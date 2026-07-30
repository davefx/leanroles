<?php
/**
 * The paths that only run when something has gone wrong.
 *
 * Error branches are where a plugin either degrades or corrupts, and they are
 * exactly the code nobody exercises by hand. Each of these forces a failure
 * that the happy-path tests never reach.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Audit\Auditor;
use LeanRoles\Audit\Benchmark;
use LeanRoles\Audit\StackProbe;
use LeanRoles\Support\Roles;
use UserTags\Catalogue;
use UserTags\Csv;
use UserTags\Query;
use UserTags\Runtime;
use UserTags\Store;
use UserTags\Taxonomy;
use LeanRoles\Tests\TestCase;

class EdgeCasesTest extends TestCase {

	// -------------------------------------------------- a missing role option

	public function test_the_audit_says_so_when_there_is_no_role_option(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => Roles::option_name() ) );
		wp_cache_delete( Roles::option_name(), 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$report = Auditor::run( array( 'benchmark' => false, 'user_counts' => false ) );

		$this->assertCount( 1, $report['findings'] );
		$this->assertSame( 'no_role_option', $report['findings'][0]['id'] );
		$this->assertStringContainsString( 'wp_roles_init', $report['findings'][0]['detail'] );
	}

	public function test_stored_roles_is_empty_when_there_is_no_option(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => Roles::option_name() ) );
		wp_cache_delete( Roles::option_name(), 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$this->assertSame( array(), Roles::stored_roles() );
		$this->assertNull( Roles::raw_option_value() );
	}

	public function test_the_benchmark_reports_unavailable_with_no_option(): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => Roles::option_name() ) );
		wp_cache_delete( Roles::option_name(), 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$this->assertFalse( Benchmark::run()['available'] );
	}

	// ------------------------------------------------------- restore refusals

	public function test_restore_refuses_a_restore_point_with_no_value(): void {
		Roles::create_backup( 'empty' );

		$backups             = Roles::backups();
		$backups[0]['value'] = null;
		update_option( Roles::BACKUP_OPTION, $backups, false );

		$error = Roles::restore_backup();

		$this->assertWPError( $error );
		$this->assertSame( 'leanroles_empty_backup', $error->get_error_code() );
	}

	public function test_restore_refuses_something_that_is_not_a_role_array(): void {
		Roles::create_backup( 'nonsense' );

		$backups              = Roles::backups();
		$backups[0]['value']  = 'this is not serialized anything';
		$backups[0]['sha256'] = hash( 'sha256', $backups[0]['value'] );
		update_option( Roles::BACKUP_OPTION, $backups, false );

		$error = Roles::restore_backup();

		$this->assertWPError( $error );
		$this->assertSame( 'leanroles_corrupt_backup', $error->get_error_code() );
		$this->assertArrayHasKey( 'administrator', Roles::stored_roles(), 'Nothing should have been written.' );
	}

	// ------------------------------------------------- taxonomy deregistered

	public function test_set_tags_re_registers_the_taxonomy_if_it_has_gone(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();

		unregister_taxonomy( Taxonomy::NAME );
		$this->assertFalse( taxonomy_exists( Taxonomy::NAME ) );

		Store::set_tags( $user_id, array( 'gold' ) );

		$this->assertTrue( taxonomy_exists( Taxonomy::NAME ) );
		$this->assertSame( array( 'gold' ), Store::get_tags( $user_id ) );
	}

	public function test_sync_mirror_re_registers_the_taxonomy_if_it_has_gone(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		Store::add( $user_id, 'gold' );

		unregister_taxonomy( Taxonomy::NAME );

		$this->assertSame( array( 'gold' ), Store::sync_mirror( $user_id ) );
	}

	public function test_register_is_idempotent(): void {
		unregister_taxonomy( Taxonomy::NAME );

		Taxonomy::register();
		Taxonomy::register();

		$this->assertTrue( taxonomy_exists( Taxonomy::NAME ) );
	}

	public function test_the_catalogue_re_registers_the_taxonomy_before_rebuilding(): void {
		$this->make_tag( 'gold' );

		unregister_taxonomy( Taxonomy::NAME );
		$this->reset_plugin_state();

		$this->assertArrayHasKey( 'gold', Catalogue::rebuild() );
	}

	public function test_cleanup_re_registers_the_taxonomy_if_it_has_gone(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		Store::add( $user_id, 'gold' );

		unregister_taxonomy( Taxonomy::NAME );

		\UserTags\Cleanup::purge_user( $user_id );

		$this->assertSame( array(), Store::get_tags( $user_id ) );
	}

	// ------------------------------------------------------- mirror hygiene

	public function test_prune_skips_a_mirror_row_that_is_not_an_array(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, Store::mirror_key(), 'not an array at all' );

		Store::prune_mirrors();

		$this->assertSame(
			'not an array at all',
			get_user_meta( $user_id, Store::mirror_key(), true ),
			'A row this shape is not ours to rewrite; leave it and let the rebuild command settle it.'
		);
	}

	public function test_runtime_tags_ignores_a_mirror_that_is_not_an_array(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, Store::mirror_key(), 'nonsense' );
		Store::flush_memo();

		$this->assertSame( array(), Store::runtime_tags( $user_id ) );
	}

	// -------------------------------------------------------- tag colour meta

	public function test_updating_a_tag_sets_the_colour_and_the_legacy_note(): void {
		$term_id = $this->make_tag( 'gold' );

		Taxonomy::update( 'gold', array( 'color' => '#123456', 'legacy_role' => 'old_role' ) );

		$this->assertSame( '#123456', get_term_meta( $term_id, Taxonomy::META_COLOR, true ) );
		$this->assertSame( 'old_role', get_term_meta( $term_id, Taxonomy::META_LEGACY, true ) );
	}

	public function test_updating_a_tag_clears_the_legacy_note(): void {
		$term_id = $this->make_tag( 'gold', array( 'legacy_role' => 'old_role' ) );

		Taxonomy::update( 'gold', array( 'legacy_role' => '' ) );

		$this->assertSame( '', (string) get_term_meta( $term_id, Taxonomy::META_LEGACY, true ) );
	}

	// -------------------------------------------------------------- CSV paths

	public function test_import_records_a_tag_that_could_not_be_created(): void {
		$user_id = self::factory()->user->create();

		// `editor` is a real role, so creating a tag for it is refused.
		$result = Csv::import_assignments(
			array(
				array( 'user_id', 'tags' ),
				array( (string) $user_id, 'editor' ),
			),
			true
		);

		$this->assertSame( array(), $result['created'] );
		$this->assertNotEmpty( $result['errors'] );
		$this->assertStringContainsString( 'already a real role', $result['errors'][0] );
	}

	public function test_import_falls_through_the_identifier_columns(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'fallback_login',
				'user_email' => 'fallback@example.org',
			)
		);

		$this->make_tag( 'gold' );

		// A wrong id, but a login that resolves.
		$result = Csv::import_assignments(
			array(
				array( 'user_id', 'user_login', 'user_email', 'tags' ),
				array( '99999999', 'fallback_login', 'nobody@example.org', 'gold' ),
			)
		);

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( array( 'gold' ), Store::get_tags( $user_id ) );
	}

	public function test_import_falls_through_to_the_email_column(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'byemail@example.org' ) );

		$this->make_tag( 'gold' );

		$result = Csv::import_assignments(
			array(
				array( 'user_id', 'user_login', 'user_email', 'tags' ),
				array( '', 'no_such_login', 'byemail@example.org', 'gold' ),
			)
		);

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( array( 'gold' ), Store::get_tags( $user_id ) );
	}

	public function test_export_skips_a_user_whose_tags_all_vanished(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		Store::add( $user_id, 'gold' );

		Taxonomy::delete( 'gold' );
		Store::flush_memo();

		// The mirror row survives the delete; the export must not emit an
		// empty tags column for it.
		$this->assertCount( 1, Csv::export_assignments(), 'Header only.' );
	}

	// ------------------------------------------------------------- the shim

	public function test_the_kill_switch_is_evaluated_once_per_request(): void {
		$calls = 0;

		$filter = static function ( $inject ) use ( &$calls ) {
			++$calls;

			return $inject;
		};

		$this->reset_plugin_state();
		add_filter( 'user_tags_inject_as_roles', $filter );

		Runtime::enabled();
		Runtime::enabled();
		Runtime::enabled();

		remove_filter( 'user_tags_inject_as_roles', $filter );

		$this->assertSame( 1, $calls, 'This is consulted on every capability read; it must be memoized.' );
	}

	public function test_the_kill_switch_stops_the_role_shim(): void {
		$this->make_tag( 'gold' );

		$filter = '__return_false';
		add_filter( 'user_tags_inject_as_roles', $filter );
		$this->reset_plugin_state();
		unset( $GLOBALS['wp_roles'] );

		$this->assertFalse( wp_roles()->is_role( 'gold' ) );

		remove_filter( 'user_tags_inject_as_roles', $filter );
		$this->reset_plugin_state();
	}

	public function test_late_registration_picks_up_a_catalogue_that_was_cold(): void {
		// The state after a cache wipe: WP_Roles was built while the catalogue
		// option was missing, so the shim never got registered.
		$this->make_tag( 'gold' );

		unset( $GLOBALS['wp_roles'] );
		delete_option( Catalogue::OPTION );
		$this->reset_plugin_state();

		wp_roles();
		$this->assertFalse( wp_roles()->is_role( 'gold' ) );

		// `init` rebuilds the catalogue, then the late pass re-registers.
		Catalogue::prime();
		$this->reset_static( Runtime::class, 'shadowed', null );
		Runtime::register_tag_roles_late();

		$this->assertTrue( wp_roles()->is_role( 'gold' ) );
	}

	public function test_late_registration_is_harmless_before_wp_roles_exists(): void {
		unset( $GLOBALS['wp_roles'] );

		Runtime::register_tag_roles_late();

		$this->assertArrayNotHasKey( 'wp_roles', $GLOBALS );
	}

	public function test_user_has_cap_leaves_an_already_present_slug_alone(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		Store::add( $user_id, 'gold' );

		$allcaps = apply_filters(
			'user_has_cap',
			array( 'gold' => 'already set by someone else' ),
			array(),
			array(),
			new \WP_User( $user_id )
		);

		$this->assertSame( 'already set by someone else', $allcaps['gold'] );
	}

	public function test_user_has_cap_ignores_a_non_user(): void {
		$this->assertSame(
			array( 'read' => true ),
			apply_filters( 'user_has_cap', array( 'read' => true ), array(), array(), null )
		);
	}

	// ---------------------------------------------------------- query inputs

	public function test_a_non_string_role_argument_is_ignored(): void {
		$this->make_tag( 'gold' );

		$query = new \WP_User_Query(
			array(
				'role'   => 12345,
				'fields' => 'ID',
			)
		);

		$this->assertIsArray( $query->get_results() );
	}

	public function test_an_object_in_the_role_argument_is_ignored(): void {
		$this->make_tag( 'gold' );

		$query = new \WP_User_Query( array( 'role' => new \stdClass(), 'fields' => 'ID' ) );

		$this->assertIsArray( $query->get_results() );
	}

	// -------------------------------------------------------------- stack

	public function test_an_unreadable_dropin_claims_nothing(): void {
		$path = tempnam( sys_get_temp_dir(), 'lr-dropin-' );
		file_put_contents( $path, '<?php new Redis();' );
		chmod( $path, 0000 );

		if ( is_readable( $path ) ) {
			// Running as root, where permissions do not bite.
			chmod( $path, 0644 );
			unlink( $path );
			$this->markTestSkipped( 'Cannot make a file unreadable as this user.' );
		}

		$report = StackProbe::run( $path );

		chmod( $path, 0644 );
		unlink( $path );

		$this->assertTrue( $report['dropin_present'] );
		$this->assertSame( array(), $report['backends'] );
		$this->assertStringContainsString( 'could not be read', implode( ' ', $report['notes'] ) );
	}

	// ---------------------------------------------------------- benchmark

	public function test_the_benchmark_stops_at_its_time_budget(): void {
		// A big structure and an absurd iteration count: the loop has to give
		// up on the clock rather than run for a minute inside a page load.
		$roles = array();

		for ( $i = 0; $i < 120; $i++ ) {
			$caps = array();

			for ( $c = 0; $c < 80; $c++ ) {
				$caps[ 'cap_' . $c ] = true;
			}

			$roles[ 'role_' . $i ] = array( 'name' => 'R' . $i, 'capabilities' => $caps );
		}

		$filter = static fn() => 10000000;

		add_filter( 'leanroles_benchmark_iterations', $filter );
		$started = microtime( true );
		$result  = Benchmark::run( serialize( $roles ) );
		$elapsed = microtime( true ) - $started;
		remove_filter( 'leanroles_benchmark_iterations', $filter );

		$this->assertLessThan( 10000000, $result['unserialize']['iterations'] );
		$this->assertLessThan( 5, $elapsed, 'The budget should have cut this short.' );
	}

	public function test_element_counting_ignores_scalars(): void {
		$result = Benchmark::run( serialize( 'just a string' ) );

		$this->assertSame( 0, $result['memory']['elements'] );
		$this->assertNull( $result['memory']['per_element'] );
	}
}

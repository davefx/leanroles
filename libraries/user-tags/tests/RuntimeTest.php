<?php
/**
 * The four injection hooks, against real WP_User objects.
 *
 * @package UserTags
 */

namespace UserTags\Tests;

use UserTags\Catalogue;
use UserTags\Runtime;
use UserTags\Store;
use UserTags\Tests\TestCase;

class RuntimeTest extends TestCase {

	private $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->make_tag( 'gold' );
		$this->make_tag( 'wholesale' );

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function caps_key(): string {
		global $wpdb;

		return $wpdb->get_blog_prefix() . 'capabilities';
	}

	/**
	 * What is actually on disk, bypassing every filter.
	 */
	private function stored_caps( int $user_id ): array {
		global $wpdb;

		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
				$user_id,
				$this->caps_key()
			)
		);

		$caps = maybe_unserialize( $raw );

		return is_array( $caps ) ? $caps : array();
	}

	// ------------------------------------------------------- role registration

	public function test_a_tag_is_registered_as_a_role_in_memory(): void {
		$this->assertTrue( wp_roles()->is_role( 'gold' ) );
	}

	public function test_the_shim_role_grants_no_capabilities(): void {
		$role = get_role( 'gold' );

		$this->assertInstanceOf( \WP_Role::class, $role );
		$this->assertSame( array(), $role->capabilities );
	}

	public function test_registering_the_shim_does_not_write_to_the_role_option(): void {
		global $wpdb;

		$before = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$wpdb->get_blog_prefix() . 'user_roles'
			)
		);

		unset( $GLOBALS['wp_roles'] );
		wp_roles()->is_role( 'gold' );

		$after = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$wpdb->get_blog_prefix() . 'user_roles'
			)
		);

		$this->assertSame( $before, $after );
		$this->assertStringNotContainsString( 'gold', (string) $after );
	}

	// ------------------------------------------------------------ read injection

	public function test_a_tag_appears_in_the_capabilities_array(): void {
		Store::add( $this->user_id, 'gold' );

		$caps = get_user_meta( $this->user_id, $this->caps_key(), true );

		$this->assertIsArray( $caps );
		$this->assertTrue( $caps['gold'] );
		$this->assertTrue( $caps['subscriber'], 'The real role must survive injection.' );
	}

	public function test_a_single_read_returns_the_array_not_its_first_element(): void {
		Store::add( $this->user_id, 'gold' );

		// get_metadata_raw() takes element zero of a short-circuit array when
		// $single is true. Returning the caps array unwrapped would hand the
		// caller a single boolean.
		$this->assertIsArray( get_user_meta( $this->user_id, $this->caps_key(), true ) );
	}

	public function test_a_non_single_read_returns_a_list_of_one_value(): void {
		Store::add( $this->user_id, 'gold' );

		$values = get_user_meta( $this->user_id, $this->caps_key(), false );

		$this->assertCount( 1, $values );
		$this->assertTrue( $values[0]['gold'] );
	}

	public function test_an_untagged_user_is_untouched(): void {
		$caps = get_user_meta( $this->user_id, $this->caps_key(), true );

		$this->assertSame( array( 'subscriber' => true ), $caps );
	}

	public function test_a_tag_appears_in_user_roles(): void {
		Store::add( $this->user_id, 'gold' );

		$user  = $this->fresh_user( $this->user_id );
		$roles = (array) $user->roles;

		$this->assertContains( 'gold', $roles );
		$this->assertContains( 'subscriber', $roles );
	}

	public function test_a_tag_answers_current_user_can(): void {
		Store::add( $this->user_id, 'gold' );

		wp_set_current_user( $this->user_id );

		$this->assertTrue( current_user_can( 'gold' ) );
		$this->assertFalse( current_user_can( 'wholesale' ) );
	}

	public function test_a_tag_grants_no_real_capability(): void {
		Store::add( $this->user_id, 'gold' );

		wp_set_current_user( $this->user_id );

		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertFalse( current_user_can( 'edit_posts' ) );
		$this->assertTrue( current_user_can( 'read' ), 'The subscriber role still works.' );
	}

	public function test_user_can_works_for_another_user(): void {
		Store::add( $this->user_id, 'wholesale' );

		$this->assertTrue( user_can( $this->user_id, 'wholesale' ) );
		$this->assertFalse( user_can( $this->user_id, 'gold' ) );
	}

	public function test_a_tag_deleted_from_the_catalogue_stops_being_injected(): void {
		Store::add( $this->user_id, 'gold' );

		// Leave the mirror alone and remove only the catalogue entry, which is
		// the state a half-finished delete leaves behind.
		$catalogue = Catalogue::all();
		unset( $catalogue['gold'] );
		update_option( Catalogue::OPTION, $catalogue, false );
		$this->reset_plugin_state();

		$caps = get_user_meta( $this->user_id, $this->caps_key(), true );

		$this->assertArrayNotHasKey( 'gold', $caps );
	}

	// -------------------------------------------------------------- shadowing

	/**
	 * Write a role straight into the stored option and rebuild WP_Roles.
	 *
	 * This is how a slug collision really arrives — an import, a migration, a
	 * plugin writing the option directly — because add_role() cannot do it once
	 * the shim is in place (see the test below).
	 *
	 * @param string $slug Role slug.
	 * @param array  $caps Capabilities.
	 */
	private function force_real_role( string $slug, array $caps ): void {
		global $wpdb;

		$key   = $wpdb->get_blog_prefix() . 'user_roles';
		$roles = get_option( $key );

		$roles[ $slug ] = array(
			'name'         => 'A real role',
			'capabilities' => $caps,
		);

		update_option( $key, $roles );

		$this->reset_plugin_state();
	}

	public function test_add_role_cannot_take_over_a_slug_the_shim_holds(): void {
		// WP_Roles::add_role() bails when the slug is already present, and the
		// shim is present from wp_roles_init onwards. First line of defence.
		$this->assertNull( add_role( 'gold', 'Gold, the real role', array( 'manage_options' => true ) ) );

		$this->assertSame( array(), get_role( 'gold' )->capabilities );
	}

	public function test_a_tag_shadowed_by_a_real_role_is_never_injected(): void {
		Store::add( $this->user_id, 'gold' );

		$this->force_real_role( 'gold', array( 'manage_options' => true ) );

		wp_set_current_user( $this->user_id );

		$caps = get_user_meta( $this->user_id, $this->caps_key(), true );

		$this->assertArrayNotHasKey(
			'gold',
			$caps,
			'Injecting a slug a real role owns would hand every tagged user that role.'
		);
		$this->assertFalse(
			current_user_can( 'manage_options' ),
			'This is the escalation the shadow list exists to prevent.'
		);
	}

	public function test_user_has_cap_also_refuses_a_shadowed_slug(): void {
		Store::add( $this->user_id, 'gold' );

		$this->force_real_role( 'gold', array( 'manage_options' => true ) );

		$allcaps = apply_filters(
			'user_has_cap',
			array( 'read' => true ),
			array( 'read' ),
			array( 'read' ),
			new \WP_User( $this->user_id )
		);

		$this->assertArrayNotHasKey( 'gold', $allcaps );
	}

	public function test_a_user_genuinely_holding_the_shadowing_role_still_gets_it(): void {
		Store::add( $this->user_id, 'gold' );

		$this->force_real_role( 'gold', array( 'manage_options' => true ) );

		$user = $this->fresh_user( $this->user_id );
		$user->add_role( 'gold' );

		wp_set_current_user( $this->user_id );
		clean_user_cache( $this->user_id );

		$this->assertTrue(
			user_can( $this->user_id, 'manage_options' ),
			'Shadowing must suppress the injection, not the real role assignment.'
		);
	}

	public function test_an_unshadowed_tag_still_works_alongside_a_shadowed_one(): void {
		Store::add( $this->user_id, array( 'gold', 'wholesale' ) );

		$this->force_real_role( 'gold', array( 'manage_options' => true ) );

		$caps = get_user_meta( $this->user_id, $this->caps_key(), true );

		$this->assertArrayNotHasKey( 'gold', $caps );
		$this->assertTrue( $caps['wholesale'] );
	}

	// ------------------------------------------------------------ write filter

	public function test_tags_are_stripped_before_being_written(): void {
		Store::add( $this->user_id, 'gold' );

		$injected = get_user_meta( $this->user_id, $this->caps_key(), true );
		$this->assertArrayHasKey( 'gold', $injected );

		// Write the injected array straight back, which is the worst case.
		update_user_meta( $this->user_id, $this->caps_key(), $injected );

		$this->assertSame( array( 'subscriber' => true ), $this->stored_caps( $this->user_id ) );
	}

	public function test_the_set_role_trap(): void {
		// WP_User::set_role() walks $this->roles — which now contains the tags —
		// and unsets each one from $this->caps before saving. If the tags were
		// genuinely stored, an administrator pressing Update would wipe them.
		Store::add( $this->user_id, array( 'gold', 'wholesale' ) );

		$user = $this->fresh_user( $this->user_id );
		$this->assertContains( 'gold', (array) $user->roles );

		$user->set_role( 'editor' );

		$stored = $this->stored_caps( $this->user_id );
		$this->assertSame( array( 'editor' => true ), $stored );

		// And the tags are still there on the next read.
		$reread = $this->fresh_user( $this->user_id );
		$this->assertContains( 'gold', (array) $reread->roles );
		$this->assertContains( 'wholesale', (array) $reread->roles );
		$this->assertContains( 'editor', (array) $reread->roles );
	}

	public function test_add_role_does_not_persist_tags(): void {
		Store::add( $this->user_id, 'gold' );

		$user = $this->fresh_user( $this->user_id );
		$user->add_role( 'author' );

		$stored = $this->stored_caps( $this->user_id );

		$this->assertArrayNotHasKey( 'gold', $stored );
		$this->assertArrayHasKey( 'author', $stored );
		$this->assertArrayHasKey( 'subscriber', $stored );
	}

	public function test_remove_role_does_not_persist_tags(): void {
		Store::add( $this->user_id, 'gold' );

		$user = $this->fresh_user( $this->user_id );
		$user->add_role( 'author' );
		$user = $this->fresh_user( $this->user_id );
		$user->remove_role( 'author' );

		$this->assertSame( array( 'subscriber' => true ), $this->stored_caps( $this->user_id ) );
	}

	public function test_the_insert_path_strips_tags_too(): void {
		$fresh = self::factory()->user->create();

		delete_user_meta( $fresh, $this->caps_key() );
		Store::add( $fresh, 'gold' );

		add_user_meta( $fresh, $this->caps_key(), array( 'subscriber' => true, 'gold' => true ), true );

		$this->assertSame( array( 'subscriber' => true ), $this->stored_caps( $fresh ) );
	}

	public function test_a_write_with_no_tags_is_passed_straight_through(): void {
		update_user_meta( $this->user_id, $this->caps_key(), array( 'editor' => true, 'author' => true ) );

		$this->assertSame(
			array( 'editor' => true, 'author' => true ),
			$this->stored_caps( $this->user_id )
		);
	}

	public function test_unrelated_meta_keys_are_never_touched(): void {
		update_user_meta( $this->user_id, 'some_plugin_setting', array( 'gold' => true ) );

		$this->assertSame(
			array( 'gold' => true ),
			get_user_meta( $this->user_id, 'some_plugin_setting', true )
		);
	}

	// -------------------------------------------------------------- kill switch

	public function test_the_shim_can_be_switched_off(): void {
		Store::add( $this->user_id, 'gold' );

		$filter = '__return_false';
		add_filter( 'user_tags_inject_as_roles', $filter );
		$this->reset_plugin_state();

		$caps = get_user_meta( $this->user_id, $this->caps_key(), true );
		$this->assertArrayNotHasKey( 'gold', $caps );

		// The assignment itself survives; only the shim is off.
		$this->assertSame( array( 'gold' ), Store::get_tags( $this->user_id ) );

		remove_filter( 'user_tags_inject_as_roles', $filter );
		$this->reset_plugin_state();
	}

	// ------------------------------------------------------------- reentrancy

	public function test_repeated_capability_reads_do_not_multiply_queries(): void {
		Store::add( $this->user_id, 'gold' );

		clean_user_cache( $this->user_id );
		Store::flush_memo();

		global $wpdb;

		get_user_meta( $this->user_id, $this->caps_key(), true );
		$after_first = $wpdb->num_queries;

		for ( $i = 0; $i < 25; $i++ ) {
			get_user_meta( $this->user_id, $this->caps_key(), true );
		}

		$this->assertSame(
			$after_first,
			$wpdb->num_queries,
			'Injection must be served from the metadata cache and the per-request memo.'
		);
	}

	public function test_injection_costs_no_extra_query_on_a_cold_cache(): void {
		Store::add( $this->user_id, 'gold' );

		clean_user_cache( $this->user_id );
		Store::flush_memo();

		global $wpdb;
		$before = $wpdb->num_queries;

		get_user_meta( $this->user_id, $this->caps_key(), true );

		$this->assertLessThanOrEqual(
			1,
			$wpdb->num_queries - $before,
			'update_meta_cache() loads every key at once, so the mirror rides along with the capabilities read.'
		);
	}

	public function test_a_thousand_capability_checks_do_not_recurse(): void {
		Store::add( $this->user_id, 'gold' );
		wp_set_current_user( $this->user_id );

		for ( $i = 0; $i < 1000; $i++ ) {
			current_user_can( 'read' );
		}

		$this->assertTrue( current_user_can( 'gold' ) );
	}

	public function test_reading_capabilities_for_a_nonexistent_user_is_harmless(): void {
		$this->assertSame( '', get_user_meta( 99999999, $this->caps_key(), true ) );
	}
}

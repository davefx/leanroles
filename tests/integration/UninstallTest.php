<?php
/**
 * uninstall.php.
 *
 * Sixty lines of raw SQL that run exactly once, on a site the developer is
 * walking away from, where nobody is watching and there is nothing to roll
 * back to. It is the least-observed and least-forgiving code in the plugin, so
 * it is executed here rather than merely parsed.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Integration;

use LeanRoles\Support\Roles;
use UserTags\Catalogue;
use UserTags\Store;
use UserTags\Taxonomy;
use LeanRoles\Tests\TestCase;

class UninstallTest extends TestCase {

	/**
	 * Run the uninstaller in this process.
	 */
	private function uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'leanroles/leanroles.php' );
		}

		require_once LEANROLES_PATH . 'uninstall.php';

		// require_once only runs the file the first time; later tests call the
		// function it defined.
		if ( function_exists( 'leanroles_uninstall_site' ) ) {
			leanroles_uninstall_site();
		}
	}

	private function count_rows( string $sql ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $sql );
	}

	private function term_row_count(): int {
		global $wpdb;

		return $this->count_rows(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
				Taxonomy::NAME
			)
		);
	}

	private function relationship_count(): int {
		global $wpdb;

		return $this->count_rows(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tt.taxonomy = %s",
				Taxonomy::NAME
			)
		);
	}

	private function mirror_count(): int {
		global $wpdb;

		return $this->count_rows(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
				Store::mirror_key()
			)
		);
	}

	public function test_it_leaves_the_shared_tag_data_alone(): void {
		$this->make_tag( 'gold' );

		foreach ( self::factory()->user->create_many( 2 ) as $id ) {
			Store::add( $id, 'gold' );
		}

		$this->uninstall();

		// Another plugin may still be using these, and no consumer can know it
		// was the last. Removing them is user_tags_uninstall()'s job.
		$this->assertGreaterThan( 0, $this->term_row_count() );
		$this->assertGreaterThan( 0, $this->relationship_count() );
		$this->assertGreaterThan( 0, $this->mirror_count() );
	}

	public function test_the_library_can_be_erased_explicitly(): void {
		$this->make_tag( 'gold' );
		$this->make_tag( 'wholesale' );

		foreach ( self::factory()->user->create_many( 3 ) as $id ) {
			Store::add( $id, array( 'gold', 'wholesale' ) );
		}

		$this->assertSame( 2, user_tags_uninstall() );

		$this->assertSame( 0, $this->term_row_count() );
		$this->assertSame( 0, $this->relationship_count() );
		$this->assertSame( 0, $this->mirror_count() );
		$this->assertFalse( get_option( Catalogue::OPTION, false ) );
		$this->assertFalse( wp_next_scheduled( 'user_tags_prune_mirrors' ) );
	}

	public function test_it_removes_everything_the_plugin_created(): void {
		$this->make_tag( 'gold' );
		$this->make_tag( 'wholesale' );

		foreach ( self::factory()->user->create_many( 3 ) as $id ) {
			Store::add( $id, array( 'gold', 'wholesale' ) );
		}

		Roles::create_backup( 'test' );
		set_transient( \LeanRoles\Audit\StructureProbe::USER_COUNT_TRANSIENT, array( 'total_users' => 1 ), HOUR_IN_SECONDS );

		$this->uninstall();

		$this->assertFalse( get_option( Roles::BACKUP_OPTION, false ) );
		$this->assertFalse( get_transient( \LeanRoles\Audit\StructureProbe::USER_COUNT_TRANSIENT ) );
	}

	public function test_erasing_the_library_removes_the_term_meta_too(): void {
		global $wpdb;

		$term_id = $this->make_tag( 'gold', array( 'color' => '#ffcc00', 'legacy_role' => 'old_role' ) );

		$before = $this->count_rows(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d", $term_id )
		);

		$this->assertGreaterThan( 0, $before );

		user_tags_uninstall();

		$this->assertSame(
			0,
			$this->count_rows( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d", $term_id ) )
		);
	}

	public function test_it_does_not_touch_the_role_option(): void {
		$this->make_tag( 'gold' );

		$before = Roles::raw_option_value();

		$this->uninstall();

		$this->assertSame(
			$before,
			Roles::raw_option_value(),
			'A plugin that rewrites {prefix}user_roles on its way out is exactly what nobody should install.'
		);
	}

	public function test_it_leaves_users_and_their_roles_alone(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		Store::add( $user_id, 'gold' );

		$this->uninstall();

		$user = $this->fresh_user( $user_id );

		$this->assertContains( 'editor', (array) $user->roles );
		$this->assertTrue( user_can( $user_id, 'edit_posts' ) );
	}

	public function test_erasing_the_library_leaves_other_taxonomies_alone(): void {
		global $wpdb;

		$this->make_tag( 'gold' );

		$category = self::factory()->category->create( array( 'name' => 'Untouched' ) );
		$post_id  = self::factory()->post->create();
		wp_set_object_terms( $post_id, array( $category ), 'category' );

		user_tags_uninstall();

		$this->assertNotNull( get_term( $category, 'category' ) );
		$this->assertSame(
			1,
			$this->count_rows(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d", $category )
			)
		);
		$this->assertNotEmpty( wp_get_object_terms( $post_id, 'category' ) );
	}

	public function test_it_leaves_other_usermeta_alone(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		Store::add( $user_id, 'gold' );
		update_user_meta( $user_id, 'some_other_plugin_key', 'keep me' );

		user_tags_uninstall();

		$this->assertSame( 'keep me', get_user_meta( $user_id, 'some_other_plugin_key', true ) );
	}

	public function test_it_leaves_the_shared_schedule_running(): void {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'user_tags_prune_mirrors' );

		$this->uninstall();

		$this->assertNotFalse(
			wp_next_scheduled( 'user_tags_prune_mirrors' ),
			'Housekeeping for shared data outlives any one consumer.'
		);
	}

	public function test_it_survives_a_site_that_has_nothing_to_remove(): void {
		$this->uninstall();
		$this->uninstall();

		$this->assertSame( 0, user_tags_uninstall() );
	}
}

<?php
/**
 * wp_delete_user() clears usermeta but not term relationships. If nothing
 * cleans them up they accumulate forever, and a later user created with a
 * recycled id would inherit them.
 *
 * @package UserTags
 */

namespace UserTags\Tests;

use UserTags\Store;
use UserTags\Taxonomy;
use UserTags\Tests\TestCase;

class CleanupTest extends TestCase {

	public function set_up(): void {
		parent::set_up();

		$this->make_tag( 'gold' );
	}

	private function relationship_count( int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tr.object_id = %d AND tt.taxonomy = %s",
				$user_id,
				Taxonomy::NAME
			)
		);
	}

	public function test_deleting_a_user_drops_their_relationships(): void {
		$user_id = self::factory()->user->create();
		Store::add( $user_id, 'gold' );

		$this->assertSame( 1, $this->relationship_count( $user_id ) );

		wp_delete_user( $user_id );

		$this->assertSame( 0, $this->relationship_count( $user_id ) );
	}

	public function test_deleting_a_user_drops_their_mirror(): void {
		$user_id = self::factory()->user->create();
		Store::add( $user_id, 'gold' );

		wp_delete_user( $user_id );

		global $wpdb;

		$rows = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
				$user_id,
				Store::mirror_key()
			)
		);

		$this->assertSame( 0, (int) $rows );
	}

	public function test_the_term_count_drops_when_a_user_is_deleted(): void {
		$ids = self::factory()->user->create_many( 3 );

		foreach ( $ids as $id ) {
			Store::add( $id, 'gold' );
		}

		$this->assertSame( 3, (int) Taxonomy::get_by_slug( 'gold' )->count );

		wp_delete_user( $ids[0] );
		clean_term_cache( array( Taxonomy::get_by_slug( 'gold' )->term_id ), Taxonomy::NAME );

		$this->assertSame( 2, (int) Taxonomy::get_by_slug( 'gold' )->count );
	}

	public function test_deleting_an_untagged_user_is_harmless(): void {
		$user_id = self::factory()->user->create();

		wp_delete_user( $user_id );

		$this->assertSame( 0, $this->relationship_count( $user_id ) );
	}

	public function test_a_deleted_id_carries_nothing_forward(): void {
		// The hazard is object_id reuse: term_relationships keys on a bare
		// integer, so anything left behind would attach itself to whoever ends
		// up with that id next.
		$user_id = self::factory()->user->create();
		Store::add( $user_id, 'gold' );

		wp_delete_user( $user_id );
		Store::flush_memo();

		$this->assertSame( array(), Store::get_tags( $user_id ) );
		$this->assertSame( array(), Store::runtime_tags( $user_id ) );
		$this->assertNotContains( $user_id, Store::users_by_tag( 'gold' ) );
	}

	public function test_purging_an_invalid_id_is_a_no_op(): void {
		\UserTags\Cleanup::purge_user( 0 );
		\UserTags\Cleanup::purge_user( -5 );

		$this->assertTrue( true );
	}
}

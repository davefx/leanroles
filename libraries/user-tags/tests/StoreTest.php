<?php
/**
 * Assignment: term relationships as truth, usermeta as mirror.
 *
 * @package UserTags
 */

namespace UserTags\Tests;

use UserTags\Store;
use UserTags\Taxonomy;
use UserTags\Tests\TestCase;

class StoreTest extends TestCase {

	private $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->make_tag( 'gold' );
		$this->make_tag( 'wholesale' );

		$this->user_id = self::factory()->user->create();
	}

	/** The mirror as stored, bypassing the memo. */
	private function raw_mirror( int $user_id ) {
		global $wpdb;

		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
				$user_id,
				Store::mirror_key()
			)
		);

		return null === $raw ? null : maybe_unserialize( $raw );
	}

	/** Slugs held according to the taxonomy. */
	private function taxonomy_slugs( int $user_id ): array {
		$terms = wp_get_object_terms( $user_id, Taxonomy::NAME, array( 'fields' => 'slugs' ) );
		$slugs = is_wp_error( $terms ) ? array() : $terms;

		sort( $slugs );

		return $slugs;
	}

	// --------------------------------------------------------------- basics

	public function test_add_and_read(): void {
		Store::add( $this->user_id, 'gold' );

		$this->assertSame( array( 'gold' ), Store::get_tags( $this->user_id ) );
	}

	public function test_the_mirror_key_is_blog_prefixed(): void {
		global $wpdb;

		$this->assertSame( $wpdb->get_blog_prefix() . 'user_tags', Store::mirror_key() );
	}

	public function test_both_stores_agree_after_a_write(): void {
		Store::add( $this->user_id, array( 'gold', 'wholesale' ) );

		$this->assertSame( array( 'gold', 'wholesale' ), $this->raw_mirror( $this->user_id ) );
		$this->assertSame( array( 'gold', 'wholesale' ), $this->taxonomy_slugs( $this->user_id ) );
	}

	public function test_set_tags_replaces_rather_than_merges(): void {
		Store::add( $this->user_id, array( 'gold', 'wholesale' ) );
		Store::set_tags( $this->user_id, array( 'wholesale' ) );

		$this->assertSame( array( 'wholesale' ), Store::get_tags( $this->user_id ) );
		$this->assertSame( array( 'wholesale' ), $this->taxonomy_slugs( $this->user_id ) );
	}

	public function test_remove(): void {
		Store::add( $this->user_id, array( 'gold', 'wholesale' ) );
		Store::remove( $this->user_id, 'gold' );

		$this->assertSame( array( 'wholesale' ), Store::get_tags( $this->user_id ) );
	}

	public function test_removing_the_last_tag_leaves_an_empty_list(): void {
		Store::add( $this->user_id, 'gold' );
		Store::remove( $this->user_id, 'gold' );

		$this->assertSame( array(), Store::get_tags( $this->user_id ) );
		$this->assertSame( array(), $this->taxonomy_slugs( $this->user_id ) );
		$this->assertSame( array(), $this->raw_mirror( $this->user_id ) );
	}

	public function test_adding_a_tag_twice_is_idempotent(): void {
		Store::add( $this->user_id, 'gold' );
		Store::add( $this->user_id, 'gold' );

		$this->assertSame( array( 'gold' ), Store::get_tags( $this->user_id ) );
		$this->assertCount( 1, $this->taxonomy_slugs( $this->user_id ) );
	}

	public function test_unknown_slugs_are_dropped(): void {
		$result = Store::set_tags( $this->user_id, array( 'gold', 'no_such_tag' ) );

		$this->assertSame( array( 'gold' ), $result );
		$this->assertSame( array( 'gold' ), Store::get_tags( $this->user_id ) );
	}

	public function test_tags_are_stored_sorted(): void {
		Store::set_tags( $this->user_id, array( 'wholesale', 'gold' ) );

		$this->assertSame( array( 'gold', 'wholesale' ), $this->raw_mirror( $this->user_id ) );
	}

	public function test_an_unknown_user_is_an_error(): void {
		$this->assertWPError( Store::set_tags( 99999999, array( 'gold' ) ) );
	}

	public function test_a_zero_user_id_reads_as_empty(): void {
		$this->assertSame( array(), Store::get_tags( 0 ) );
		$this->assertSame( array(), Store::runtime_tags( 0 ) );
	}

	// ---------------------------------------------------------------- events

	public function test_the_added_action_fires_once_per_new_tag(): void {
		$fired = array();

		add_action(
			'user_tags_added',
			static function ( $user_id, $tag ) use ( &$fired ) {
				$fired[] = "{$user_id}:{$tag}";
			},
			10,
			2
		);

		Store::add( $this->user_id, array( 'gold', 'wholesale' ) );
		Store::add( $this->user_id, 'gold' );

		$this->assertSame(
			array( "{$this->user_id}:gold", "{$this->user_id}:wholesale" ),
			$fired
		);
	}

	public function test_the_removed_action_fires(): void {
		$fired = array();

		add_action(
			'user_tags_removed',
			static function ( $user_id, $tag ) use ( &$fired ) {
				$fired[] = $tag;
			},
			10,
			2
		);

		Store::add( $this->user_id, array( 'gold', 'wholesale' ) );
		Store::remove( $this->user_id, 'gold' );

		$this->assertSame( array( 'gold' ), $fired );
	}

	// ---------------------------------------------------------------- mirror

	public function test_the_mirror_is_rebuilt_when_it_is_missing(): void {
		Store::add( $this->user_id, 'gold' );

		delete_user_meta( $this->user_id, Store::mirror_key() );
		Store::flush_memo();

		$this->assertSame( array( 'gold' ), Store::get_tags( $this->user_id ) );
		$this->assertSame( array( 'gold' ), $this->raw_mirror( $this->user_id ) );
	}

	public function test_the_taxonomy_wins_when_the_two_disagree(): void {
		Store::add( $this->user_id, 'gold' );

		// Corrupt the mirror by hand, as a bad import would.
		update_user_meta( $this->user_id, Store::mirror_key(), array( 'wholesale' ) );
		Store::flush_memo();

		$this->assertSame( array( 'gold' ), Store::sync_mirror( $this->user_id ) );
		$this->assertSame( array( 'gold' ), $this->raw_mirror( $this->user_id ) );
	}

	public function test_runtime_tags_ignores_slugs_missing_from_the_catalogue(): void {
		update_user_meta( $this->user_id, Store::mirror_key(), array( 'gold', 'vanished' ) );
		Store::flush_memo();

		$this->assertSame( array( 'gold' ), Store::runtime_tags( $this->user_id ) );
	}

	public function test_prune_mirrors_clears_dead_slugs(): void {
		update_user_meta( $this->user_id, Store::mirror_key(), array( 'gold', 'vanished' ) );
		Store::flush_memo();

		Store::prune_mirrors();

		$this->assertSame( array( 'gold' ), $this->raw_mirror( $this->user_id ) );
	}

	public function test_prune_mirrors_leaves_healthy_rows_alone(): void {
		Store::add( $this->user_id, array( 'gold', 'wholesale' ) );

		Store::prune_mirrors();

		$this->assertSame( array( 'gold', 'wholesale' ), $this->raw_mirror( $this->user_id ) );
	}

	// -------------------------------------------------------- reverse lookup

	public function test_users_by_tag(): void {
		$others = self::factory()->user->create_many( 3 );

		foreach ( $others as $id ) {
			Store::add( $id, 'gold' );
		}

		Store::add( $this->user_id, 'wholesale' );

		$found = Store::users_by_tag( 'gold' );

		sort( $others );
		sort( $found );

		$this->assertSame( $others, $found );
	}

	public function test_users_by_tag_paginates(): void {
		$ids = self::factory()->user->create_many( 5 );

		foreach ( $ids as $id ) {
			Store::add( $id, 'gold' );
		}

		$this->assertCount( 2, Store::users_by_tag( 'gold', array( 'number' => 2 ) ) );
		$this->assertCount( 3, Store::users_by_tag( 'gold', array( 'number' => 3, 'offset' => 2 ) ) );
	}

	public function test_users_by_tag_can_return_objects(): void {
		Store::add( $this->user_id, 'gold' );

		$users = Store::users_by_tag( 'gold', array( 'fields' => 'all' ) );

		$this->assertInstanceOf( \WP_User::class, $users[0] );
	}

	public function test_users_by_tag_for_an_unknown_slug(): void {
		$this->assertSame( array(), Store::users_by_tag( 'no_such_tag' ) );
	}

	// ----------------------------------------------------------- bulk assign

	public function test_assign_by_role_covers_every_holder(): void {
		$ids = self::factory()->user->create_many( 7, array( 'role' => 'author' ) );

		$result = Store::assign_by_role( 'gold', 'author', 3 );

		$this->assertSame( 7, $result['processed'] );
		$this->assertSame( max( $ids ), $result['last_id'] );

		foreach ( $ids as $id ) {
			$this->assertContains( 'gold', Store::get_tags( $id ), "User {$id} should have been tagged." );
		}
	}

	public function test_assign_by_role_reports_progress_per_batch(): void {
		self::factory()->user->create_many( 5, array( 'role' => 'author' ) );

		$batches = 0;

		Store::assign_by_role(
			'gold',
			'author',
			2,
			0,
			static function () use ( &$batches ) {
				++$batches;
			}
		);

		$this->assertGreaterThanOrEqual( 3, $batches );
	}

	public function test_assign_by_role_can_resume(): void {
		$ids = self::factory()->user->create_many( 6, array( 'role' => 'author' ) );
		sort( $ids );

		$cutoff = $ids[2];

		$result = Store::assign_by_role( 'gold', 'author', 10, $cutoff );

		$this->assertSame( 3, $result['processed'] );

		foreach ( array_slice( $ids, 0, 3 ) as $skipped ) {
			$this->assertSame( array(), Store::get_tags( $skipped ) );
		}

		foreach ( array_slice( $ids, 3 ) as $tagged ) {
			$this->assertSame( array( 'gold' ), Store::get_tags( $tagged ) );
		}
	}

	public function test_assign_by_role_releases_the_users_it_touched(): void {
		$ids = self::factory()->user->create_many( 6, array( 'role' => 'author' ) );

		Store::assign_by_role( 'gold', 'author', 2 );

		foreach ( $ids as $id ) {
			$this->assertFalse(
				wp_cache_get( $id, 'users' ),
				'A long run must not hold every user object it has ever seen.'
			);
		}
	}

	public function test_assign_by_role_emits_no_php_notice(): void {
		// The first version of the batch flush reached into WP_Object_Cache's
		// internals, which is an overloaded property before WordPress 6.1: the
		// unset() raised a notice and silently did nothing. Nothing here may
		// depend on an API newer than the declared floor.
		$errors = array();

		set_error_handler(
			static function ( $severity, $message ) use ( &$errors ) {
				$errors[] = $message;

				return true;
			}
		);

		self::factory()->user->create_many( 3, array( 'role' => 'author' ) );
		Store::assign_by_role( 'gold', 'author', 1 );

		restore_error_handler();

		$this->assertSame( array(), $errors );
	}

	public function test_assign_by_role_touches_nobody_when_the_role_is_empty(): void {
		$result = Store::assign_by_role( 'gold', 'contributor', 10 );

		$this->assertSame( 0, $result['processed'] );
	}
}

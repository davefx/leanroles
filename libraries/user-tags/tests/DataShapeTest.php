<?php
/**
 * Awkward data.
 *
 * Slugs come from people, imports and other plugins, and users accumulate more
 * tags than anyone planned for. The serialized mirror and the LIKE-based
 * queries both have opinions about what a slug looks like, so those opinions
 * are checked here rather than discovered later.
 *
 * @package UserTags
 */

namespace UserTags\Tests;

use UserTags\Catalogue;
use UserTags\Store;
use UserTags\Taxonomy;
use UserTags\Tests\TestCase;

class DataShapeTest extends TestCase {

	// ----------------------------------------------------------------- slugs

	public function test_a_slug_is_sanitized_down_to_a_key(): void {
		$term_id = Taxonomy::create( 'Gold Tier — Prémium!' );

		$this->assertIsInt( $term_id );
		$this->reset_plugin_state();

		// sanitize_key() lowercases and strips anything outside a-z0-9_-.
		$slugs = array_keys( Catalogue::rebuild() );

		$this->assertCount( 1, $slugs );
		$this->assertMatchesRegularExpression( '/^[a-z0-9_\-]+$/', $slugs[0] );
	}

	public function test_a_numeric_slug_is_not_mistaken_for_a_term_id(): void {
		// wp_set_object_terms() treats numeric strings as names on a flat
		// taxonomy, so a slug like "2024" is a genuine trap.
		$this->make_tag( '2024' );

		$user_id = self::factory()->user->create();
		Store::add( $user_id, '2024' );

		$this->assertSame( array( '2024' ), Store::get_tags( $user_id ) );
		$this->assertSame(
			array( '2024' ),
			wp_get_object_terms( $user_id, Taxonomy::NAME, array( 'fields' => 'slugs' ) )
		);
	}

	public function test_a_very_long_slug_survives_the_round_trip(): void {
		$long = str_repeat( 'a', 200 );

		$term_id = Taxonomy::create( $long );

		$this->assertIsInt( $term_id );
		$this->reset_plugin_state();
		Catalogue::rebuild();

		$stored = Taxonomy::get_by_slug( $long );

		$this->assertNotNull( $stored, 'The slug column holds 200 characters.' );

		$user_id = self::factory()->user->create();
		Store::add( $user_id, $stored->slug );

		$this->assertSame( array( $stored->slug ), Store::get_tags( $user_id ) );
	}

	public function test_slugs_that_are_prefixes_of_each_other_stay_distinct(): void {
		foreach ( array( 'gold', 'gold_plus', 'gol' ) as $slug ) {
			$this->make_tag( $slug );
		}

		$gold = self::factory()->user->create();
		$plus = self::factory()->user->create();
		$gol  = self::factory()->user->create();

		Store::add( $gold, 'gold' );
		Store::add( $plus, 'gold_plus' );
		Store::add( $gol, 'gol' );

		$this->assertSame( array( $gold ), $this->ids_for_role( 'gold' ) );
		$this->assertSame( array( $plus ), $this->ids_for_role( 'gold_plus' ) );
		$this->assertSame( array( $gol ), $this->ids_for_role( 'gol' ) );
	}

	/**
	 * User ids matching a role or tag argument.
	 *
	 * @param string $slug Role or tag slug.
	 * @return int[]
	 */
	private function ids_for_role( string $slug ): array {
		$ids = array_map( 'intval', get_users( array( 'role' => $slug, 'fields' => 'ID' ) ) );
		sort( $ids );

		return $ids;
	}

	// ------------------------------------------------------------- many tags

	public function test_a_user_can_carry_many_tags(): void {
		$slugs = array();

		for ( $i = 0; $i < 50; $i++ ) {
			$slug    = 'tag_' . $i;
			$slugs[] = $slug;
			$this->make_tag( $slug );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		Store::set_tags( $user_id, $slugs );

		sort( $slugs );

		$this->assertSame( $slugs, Store::get_tags( $user_id ) );

		$user = $this->fresh_user( $user_id );

		foreach ( $slugs as $slug ) {
			$this->assertContains( $slug, (array) $user->roles );
		}
	}

	public function test_fifty_tags_still_cost_one_meta_read(): void {
		global $wpdb;

		for ( $i = 0; $i < 50; $i++ ) {
			$this->make_tag( 'tag_' . $i );
		}

		$user_id = self::factory()->user->create();
		Store::set_tags( $user_id, array_keys( Catalogue::all() ) );

		clean_user_cache( $user_id );
		Store::flush_memo();

		$before = $wpdb->num_queries;
		get_user_meta( $user_id, $wpdb->get_blog_prefix() . 'capabilities', true );

		$this->assertLessThanOrEqual( 1, $wpdb->num_queries - $before );
	}

	public function test_a_tag_can_be_held_by_many_users(): void {
		$this->make_tag( 'gold' );

		$ids = self::factory()->user->create_many( 60 );

		foreach ( $ids as $id ) {
			Store::add( $id, 'gold' );
		}

		$this->assertSame( 60, count( Store::users_by_tag( 'gold' ) ) );
		$this->assertSame( 60, (int) Taxonomy::get_by_slug( 'gold' )->count );

		// And the paged reverse lookup covers the whole set without repeats.
		$seen = array();

		for ( $offset = 0; $offset < 60; $offset += 25 ) {
			foreach ( Store::users_by_tag( 'gold', array( 'number' => 25, 'offset' => $offset ) ) as $id ) {
				$seen[ $id ] = true;
			}
		}

		$this->assertCount( 60, $seen );
	}

	// ----------------------------------------------------------- descriptions

	public function test_a_multiline_description_survives_csv_export(): void {
		$this->make_tag(
			'gold',
			array( 'description' => "First line\nSecond, with a comma\nThird \"quoted\"" )
		);

		$rows   = \UserTags\Csv::export_catalogue();
		$parsed = \UserTags\Csv::from_string( \UserTags\Csv::to_string( $rows ) );

		$this->assertSame( $rows[1], $parsed[1] );
	}

	public function test_a_unicode_tag_name_survives_the_round_trip(): void {
		$name = 'オンライン購入者 — ★ premium';

		$this->make_tag( 'gold', array( 'name' => $name ) );

		$this->assertSame( $name, Taxonomy::get_by_slug( 'gold' )->name );
		$this->assertSame( $name, Catalogue::all()['gold']['name'] );

		$rows   = \UserTags\Csv::export_catalogue();
		$parsed = \UserTags\Csv::from_string( \UserTags\Csv::to_string( $rows ) );

		$this->assertSame( $name, $parsed[1][1] );
	}
}

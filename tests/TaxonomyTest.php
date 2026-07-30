<?php
/**
 * @package UserTags
 */

namespace UserTags\Tests;

use UserTags\Catalogue;
use UserTags\Store;
use UserTags\Taxonomy;
use UserTags\Tests\TestCase;

class TaxonomyTest extends TestCase {

	public function test_it_is_registered_against_users(): void {
		$this->assertTrue( taxonomy_exists( Taxonomy::NAME ) );

		$taxonomy = get_taxonomy( Taxonomy::NAME );

		$this->assertContains( 'user', (array) $taxonomy->object_type );
	}

	public function test_it_stays_out_of_the_generated_admin_ui(): void {
		$taxonomy = get_taxonomy( Taxonomy::NAME );

		// The generated taxonomy screens assume a post type and would not work.
		$this->assertFalse( $taxonomy->public );
		$this->assertFalse( $taxonomy->show_ui );
		$this->assertFalse( $taxonomy->query_var );
		$this->assertFalse( $taxonomy->rewrite );
	}

	public function test_it_is_flat_so_that_core_stores_no_children_option(): void {
		global $wpdb;

		$this->assertFalse(
			get_taxonomy( Taxonomy::NAME )->hierarchical,
			'A hierarchical taxonomy makes core keep a {taxonomy}_children option, written with the default autoload.'
		);

		$this->make_tag( 'gold' );
		$this->make_tag( 'wholesale' );

		// _get_term_hierarchy() is what writes it, and get_terms() is what calls
		// that. If the taxonomy ever goes hierarchical again, this appears.
		get_terms( array( 'taxonomy' => Taxonomy::NAME, 'hide_empty' => false ) );

		$this->assertNull(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s",
					Taxonomy::NAME . '_children'
				)
			)
		);
	}

	public function test_it_counts_relationships_not_posts(): void {
		$taxonomy = get_taxonomy( Taxonomy::NAME );

		$this->assertSame(
			'_update_generic_term_count',
			$taxonomy->update_count_callback,
			'The post count callbacks assume a posts row; a user tag has none.'
		);
	}

	public function test_the_count_reflects_assignments(): void {
		$this->make_tag( 'gold' );

		$ids = self::factory()->user->create_many( 3 );

		foreach ( $ids as $id ) {
			Store::add( $id, 'gold' );
		}

		$term = Taxonomy::get_by_slug( 'gold' );

		$this->assertSame( 3, (int) $term->count );
	}

	// --------------------------------------------------------------- create

	public function test_create(): void {
		$term_id = Taxonomy::create( 'gold', array( 'name' => 'Gold tier', 'description' => 'Spends a lot' ) );

		$this->assertIsInt( $term_id );

		$term = Taxonomy::get_by_slug( 'gold' );

		$this->assertSame( 'Gold tier', $term->name );
		$this->assertSame( 'Spends a lot', $term->description );
	}

	public function test_create_defaults_the_name_to_the_slug(): void {
		Taxonomy::create( 'gold' );

		$this->assertSame( 'gold', Taxonomy::get_by_slug( 'gold' )->name );
	}

	public function test_create_sanitizes_the_slug(): void {
		$this->assertIsInt( Taxonomy::create( 'Gold Tier!' ) );
		$this->assertNotNull( Taxonomy::get_by_slug( 'goldtier' ) );
	}

	public function test_create_rejects_an_empty_slug(): void {
		$error = Taxonomy::create( '!!!' );

		$this->assertWPError( $error );
		$this->assertSame( 'user_tags_invalid_slug', $error->get_error_code() );
	}

	public function test_create_rejects_a_duplicate(): void {
		Taxonomy::create( 'gold' );

		$error = Taxonomy::create( 'gold' );

		$this->assertWPError( $error );
		$this->assertSame( 'user_tags_exists', $error->get_error_code() );
	}

	public function test_create_refuses_a_slug_that_is_already_a_role(): void {
		$error = Taxonomy::create( 'editor' );

		$this->assertWPError( $error );
		$this->assertSame( 'user_tags_role_exists', $error->get_error_code() );
	}

	public function test_create_stores_colour_and_legacy_role(): void {
		$term_id = Taxonomy::create( 'gold', array( 'color' => '#ff0000', 'legacy_role' => 'old_gold_role' ) );

		$this->assertSame( '#ff0000', get_term_meta( $term_id, Taxonomy::META_COLOR, true ) );
		$this->assertSame( 'old_gold_role', get_term_meta( $term_id, Taxonomy::META_LEGACY, true ) );
	}

	public function test_create_rejects_a_malformed_colour(): void {
		$term_id = Taxonomy::create( 'gold', array( 'color' => 'not-a-colour' ) );

		$this->assertSame( '', (string) get_term_meta( $term_id, Taxonomy::META_COLOR, true ) );
	}

	public function test_create_refreshes_the_catalogue_cache(): void {
		Taxonomy::create( 'gold' );

		$this->assertTrue( Catalogue::has( 'gold' ) );
	}

	// --------------------------------------------------------------- update

	public function test_update_renames_without_touching_the_slug(): void {
		$this->make_tag( 'gold' );

		$this->assertTrue( Taxonomy::update( 'gold', array( 'name' => 'Renamed' ) ) );

		$term = Taxonomy::get_by_slug( 'gold' );

		$this->assertSame( 'Renamed', $term->name );
		$this->assertSame( 'gold', $term->slug );
	}

	public function test_update_clears_a_colour_when_given_an_empty_one(): void {
		$term_id = $this->make_tag( 'gold', array( 'color' => '#123456' ) );

		Taxonomy::update( 'gold', array( 'color' => '' ) );

		$this->assertSame( '', (string) get_term_meta( $term_id, Taxonomy::META_COLOR, true ) );
	}

	public function test_update_on_an_unknown_tag(): void {
		$error = Taxonomy::update( 'nope', array( 'name' => 'x' ) );

		$this->assertWPError( $error );
		$this->assertSame( 'user_tags_unknown_tag', $error->get_error_code() );
	}

	// --------------------------------------------------------------- delete

	public function test_delete_removes_the_term_and_its_assignments(): void {
		$this->make_tag( 'gold' );

		$user_id = self::factory()->user->create();
		Store::add( $user_id, 'gold' );

		$this->assertTrue( Taxonomy::delete( 'gold' ) );

		$this->assertNull( Taxonomy::get_by_slug( 'gold' ) );
		$this->assertFalse( Catalogue::has( 'gold' ) );
		$this->assertSame( array(), Store::runtime_tags( $user_id ) );
	}

	public function test_delete_on_an_unknown_tag(): void {
		$error = Taxonomy::delete( 'nope' );

		$this->assertWPError( $error );
		$this->assertSame( 'user_tags_unknown_tag', $error->get_error_code() );
	}

	public function test_all_terms_is_sorted_and_includes_the_empty_ones(): void {
		$this->make_tag( 'zulu' );
		$this->make_tag( 'alpha' );

		$slugs = wp_list_pluck( Taxonomy::all_terms(), 'slug' );

		$this->assertSame( array( 'alpha', 'zulu' ), $slugs );
	}

	public function test_get_by_slug_returns_null_when_missing(): void {
		$this->assertNull( Taxonomy::get_by_slug( 'nope' ) );
	}
}

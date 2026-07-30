<?php
/**
 * The catalogue cache: the one thing that has to be readable before `init`.
 *
 * @package UserTags
 */

namespace UserTags\Tests;

use UserTags\Catalogue;
use UserTags\Taxonomy;
use UserTags\Tests\TestCase;

class CatalogueTest extends TestCase {

	public function test_the_cache_option_is_never_autoloaded(): void {
		$this->make_tag( 'gold' );

		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				Catalogue::OPTION
			)
		);

		$this->assertNotEmpty( $autoload );
		$this->assertNotContains(
			$autoload,
			array( 'yes', 'on', 'auto', 'auto-on' ),
			'Putting the catalogue in autoload would recreate the disease the plugin exists to treat.'
		);
	}

	public function test_rebuild_reflects_the_taxonomy(): void {
		Taxonomy::create( 'gold', array( 'name' => 'Gold', 'color' => '#abcdef' ) );

		$this->reset_plugin_state();
		$catalogue = Catalogue::rebuild();

		$this->assertArrayHasKey( 'gold', $catalogue );
		$this->assertSame( 'Gold', $catalogue['gold']['name'] );
		$this->assertSame( '#abcdef', $catalogue['gold']['color'] );
		$this->assertIsInt( $catalogue['gold']['term_id'] );
	}

	public function test_creating_a_term_refreshes_the_cache_automatically(): void {
		wp_insert_term( 'Direct', Taxonomy::NAME, array( 'slug' => 'direct' ) );

		$this->reset_plugin_state();

		$this->assertTrue(
			Catalogue::has( 'direct' ),
			'The created_term hook should have rebuilt the cache.'
		);
	}

	public function test_editing_a_term_refreshes_the_cache(): void {
		$term_id = $this->make_tag( 'gold', array( 'name' => 'Gold' ) );

		wp_update_term( $term_id, Taxonomy::NAME, array( 'name' => 'Platinum' ) );
		$this->reset_plugin_state();

		$this->assertSame( 'Platinum', Catalogue::all()['gold']['name'] );
	}

	public function test_deleting_a_term_refreshes_the_cache(): void {
		$term_id = $this->make_tag( 'gold' );

		wp_delete_term( $term_id, Taxonomy::NAME );
		$this->reset_plugin_state();

		$this->assertFalse( Catalogue::has( 'gold' ) );
	}

	public function test_prime_builds_the_cache_when_it_is_missing(): void {
		$this->make_tag( 'gold' );

		delete_option( Catalogue::OPTION );
		$this->reset_plugin_state();

		Catalogue::prime();
		$this->reset_plugin_state();

		$this->assertTrue( Catalogue::has( 'gold' ) );
	}

	public function test_prime_is_a_no_op_once_the_cache_exists(): void {
		$this->make_tag( 'gold' );

		// A deliberately wrong cache: prime() must not overwrite it, because
		// its only job is to fill an absent one.
		update_option( Catalogue::OPTION, array( 'stale' => array( 'term_id' => 1, 'name' => 'Stale', 'color' => '' ) ), false );
		$this->reset_plugin_state();

		Catalogue::prime();
		$this->reset_plugin_state();

		$this->assertTrue( Catalogue::has( 'stale' ) );
	}

	public function test_slugs_map(): void {
		$this->make_tag( 'gold' );
		$this->make_tag( 'wholesale' );

		$this->assertSame(
			array( 'gold' => true, 'wholesale' => true ),
			Catalogue::slugs_map()
		);
	}

	public function test_partition_splits_tags_from_everything_else(): void {
		$this->make_tag( 'gold' );

		list( $tags, $others ) = Catalogue::partition( array( 'gold', 'editor', 'nonsense' ) );

		$this->assertSame( array( 'gold' ), $tags );
		$this->assertSame( array( 'editor', 'nonsense' ), $others );
	}

	public function test_partition_on_an_empty_list(): void {
		list( $tags, $others ) = Catalogue::partition( array() );

		$this->assertSame( array(), $tags );
		$this->assertSame( array(), $others );
	}

	public function test_a_corrupt_cache_reads_as_empty_rather_than_fatal(): void {
		update_option( Catalogue::OPTION, 'not an array', false );
		$this->reset_plugin_state();

		$this->assertSame( array(), Catalogue::all() );
	}

	public function test_reading_the_catalogue_from_inside_an_option_filter_does_not_recurse(): void {
		$this->make_tag( 'gold' );
		$this->reset_plugin_state();

		$depth = 0;

		// A plugin that asks a capability question while options are being
		// read. Without the guard this would recurse until the stack gave out.
		$filter = static function ( $value ) use ( &$depth ) {
			++$depth;

			if ( $depth < 5 ) {
				Catalogue::all();
			}

			return $value;
		};

		add_filter( 'option_' . Catalogue::OPTION, $filter );

		$this->assertIsArray( Catalogue::all() );

		remove_filter( 'option_' . Catalogue::OPTION, $filter );
	}
}

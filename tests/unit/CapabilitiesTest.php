<?php
/**
 * @package LeanRoles
 */

namespace LeanRoles\Tests\Unit;

use LeanRoles\Support\Capabilities;
use LeanRoles\Tests\TestCase;

class CapabilitiesTest extends TestCase {

	public function test_levels_are_zero_to_ten(): void {
		$levels = Capabilities::levels();

		$this->assertCount( 11, $levels );
		$this->assertSame( 'level_0', $levels[0] );
		$this->assertSame( 'level_10', $levels[10] );
	}

	/**
	 * @dataProvider level_names
	 */
	public function test_is_level( string $cap, bool $expected ): void {
		$this->assertSame( $expected, Capabilities::is_level( $cap ) );
	}

	public function level_names(): array {
		return array(
			'zero'            => array( 'level_0', true ),
			'ten'             => array( 'level_10', true ),
			'eleven'          => array( 'level_11', false ),
			'not a number'    => array( 'level_x', false ),
			'prefixed'        => array( 'my_level_1', false ),
			'suffixed'        => array( 'level_1_extra', false ),
			'unrelated'       => array( 'edit_posts', false ),
			'empty'           => array( '', false ),
		);
	}

	public function test_inert_is_read_plus_the_levels(): void {
		$inert = Capabilities::inert();

		$this->assertCount( 12, $inert );
		$this->assertContains( 'read', $inert );
		$this->assertContains( 'level_0', $inert );
		$this->assertNotContains( 'edit_posts', $inert );
	}

	public function test_core_capabilities_are_recognised(): void {
		$recognised = Capabilities::recognised();

		foreach ( array( 'manage_options', 'edit_posts', 'read', 'level_5', 'promote_users', 'manage_network' ) as $cap ) {
			$this->assertArrayHasKey( $cap, $recognised, "{$cap} should be recognised as a core capability." );
		}
	}

	public function test_capabilities_from_a_registered_post_type_are_recognised(): void {
		register_post_type(
			'lr_book',
			array(
				'capability_type' => 'lr_book',
				'map_meta_cap'    => true,
			)
		);

		$recognised = Capabilities::recognised();

		$this->assertArrayHasKey( 'edit_lr_books', $recognised );
		$this->assertStringContainsString( 'post type', $recognised['edit_lr_books'] );

		unregister_post_type( 'lr_book' );
	}

	public function test_capabilities_from_a_registered_taxonomy_are_recognised(): void {
		register_taxonomy(
			'lr_genre',
			'post',
			array(
				'capabilities' => array(
					'manage_terms' => 'manage_lr_genres',
					'edit_terms'   => 'edit_lr_genres',
					'delete_terms' => 'delete_lr_genres',
					'assign_terms' => 'assign_lr_genres',
				),
			)
		);

		$recognised = Capabilities::recognised();

		$this->assertArrayHasKey( 'manage_lr_genres', $recognised );
		$this->assertStringContainsString( 'taxonomy', $recognised['manage_lr_genres'] );

		unregister_taxonomy( 'lr_genre' );
	}

	public function test_the_recognised_set_is_filterable(): void {
		$filter = static function ( $recognised ) {
			$recognised['acme_premium_cap'] = 'acme';

			return $recognised;
		};

		add_filter( 'leanroles_recognised_capabilities', $filter );

		$this->assertArrayHasKey( 'acme_premium_cap', Capabilities::recognised() );

		remove_filter( 'leanroles_recognised_capabilities', $filter );

		$this->assertArrayNotHasKey( 'acme_premium_cap', Capabilities::recognised() );
	}
}

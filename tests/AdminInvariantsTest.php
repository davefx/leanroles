<?php
/**
 * Properties the screens have to hold everywhere, not in one place.
 *
 * These moved here with the screens. Coverage cannot see any of them: a line can
 * be executed and still print an unescaped tag name, or still cost a query per
 * row.
 *
 * @package UserTags
 */

namespace UserTags\Tests;

use UserTags\Admin\Profile;
use UserTags\Admin\Screen;
use UserTags\Admin\UsersList;
use UserTags\Catalogue;
use UserTags\Store;
use UserTags\Taxonomy;

class AdminInvariantsTest extends TestCase {

	/**
	 * @dataProvider hostile_names
	 *
	 * @param string $name A tag name that must not survive as markup.
	 */
	public function test_a_hostile_tag_name_is_escaped_everywhere( string $name ): void {
		global $wpdb;

		$term_id = $this->make_tag( 'gold' );

		// wp_insert_term() strips markup, so this goes in underneath it: the
		// realistic case is a row that arrived by import, migration or a direct
		// edit, and the display layer must not depend on it being clean.
		$wpdb->update( $wpdb->terms, array( 'name' => $name ), array( 'term_id' => $term_id ) );
		$wpdb->update(
			$wpdb->term_taxonomy,
			array( 'description' => $name ),
			array(
				'term_id'  => $term_id,
				'taxonomy' => Taxonomy::NAME,
			)
		);
		clean_term_cache( array( $term_id ), Taxonomy::NAME );
		Catalogue::rebuild();
		$this->reset_plugin_state();

		$user_id = self::factory()->user->create();
		Store::add( $user_id, 'gold' );

		$this->as_admin_request( array() );

		$surfaces = array(
			'users list column' => UsersList::render_column( '', 'user_tags', $user_id ),
			'users list views'  => implode( ' ', UsersList::add_views( array() ) ),
			'tags screen'       => $this->capture_output( array( Screen::class, 'render' ) ),
			'profile screen'    => $this->capture_output(
				function () use ( $user_id ) {
					Profile::render( get_userdata( $user_id ) );
				}
			),
			'bulk controls'     => $this->capture_output(
				static function () {
					UsersList::render_controls( 'top' );
				}
			),
		);

		foreach ( $surfaces as $where => $html ) {
			$this->assertStringNotContainsString( '<script', $html, "Unescaped markup reached the {$where}." );

			/*
			 * The pair below is the whole test. Looking for fragments like
			 * "onerror=" would fire on `&lt;img src=x onerror=alert(1)&gt;`,
			 * which is inert text — escaped output legitimately still contains
			 * the words. What matters is that the raw string never appears and
			 * the escaped one does.
			 */
			$this->assertStringNotContainsString( $name, $html, "The raw name reached the {$where} unescaped." );
			$this->assertStringContainsString( esc_html( $name ), $html, "The name should still be shown, escaped, on the {$where}." );
		}

		$this->clear_request();
	}

	/**
	 * Names that must never survive as markup.
	 *
	 * @return array[]
	 */
	public function hostile_names(): array {
		return array(
			'script tag'      => array( '<script>alert(1)</script>' ),
			'image onerror'   => array( '<img src=x onerror=alert(1)>' ),
			'attribute break' => array( '" onmouseover="alert(1)' ),
			'entity soup'     => array( '<a href="javascript:alert(1)">click</a>' ),
		);
	}

	public function test_a_hostile_colour_never_reaches_a_style_attribute(): void {
		$term_id = $this->make_tag( 'gold' );

		// Straight past the API, the way a bad import or a direct edit would.
		update_term_meta( $term_id, Taxonomy::META_COLOR, 'red;background-image:url(javascript:alert(1))' );
		Catalogue::rebuild();
		$this->reset_plugin_state();

		$user_id = self::factory()->user->create();
		Store::add( $user_id, 'gold' );

		$html = UsersList::render_column( '', 'user_tags', $user_id );

		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'background-image', $html );
	}

	public function test_the_users_column_costs_no_query_per_row(): void {
		global $wpdb;

		$this->make_tag( 'gold' );

		$ids = self::factory()->user->create_many( 12 );

		foreach ( $ids as $id ) {
			Store::add( $id, 'gold' );
		}

		// What the list table does before it renders a single row.
		foreach ( $ids as $id ) {
			clean_user_cache( $id );
		}

		Store::flush_memo();
		cache_users( $ids );

		$before = $wpdb->num_queries;

		foreach ( $ids as $id ) {
			UsersList::render_column( '', 'user_tags', $id );
		}

		$this->assertSame(
			$before,
			$wpdb->num_queries,
			'The mirror exists so this column rides on the metadata cache the list table already primed.'
		);
	}

	public function test_the_screens_plant_no_top_level_menu(): void {
		// A library that adds itself to somebody's sidebar because they installed
		// a plugin for something else is the mistake worth avoiding. Tags sit
		// under Users, always.
		$found = array();

		foreach ( glob( dirname( __DIR__ ) . '/src/Admin/*.php' ) as $file ) {
			foreach ( array( 'add_menu_page', 'add_management_page', 'add_options_page' ) as $needle ) {
				if ( false !== strpos( (string) file_get_contents( $file ), $needle . '(' ) ) {
					$found[] = basename( $file ) . ' calls ' . $needle . '()';
				}
			}
		}

		$this->assertSame( array(), $found, implode( "\n", $found ) );
	}
}

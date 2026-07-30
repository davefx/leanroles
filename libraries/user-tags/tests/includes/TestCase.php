<?php
/**
 * Shared base class for the library's tests.
 *
 * @package UserTags
 */

namespace UserTags\Tests;

use UserTags\Catalogue;
use UserTags\Library;
use UserTags\Runtime;
use UserTags\Store;
use UserTags\Taxonomy;

abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Reset every piece of per-request state the library memoizes.
	 *
	 * The runtime caches hard — the catalogue, a per-user tag list, the
	 * capabilities meta key. Correct in a real request, poison across tests in
	 * one process.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->reset_plugin_state();
	}

	public function tear_down(): void {
		$this->reset_plugin_state();

		parent::tear_down();
	}

	/**
	 * Clear the library's static caches.
	 */
	protected function reset_plugin_state(): void {
		Store::flush_memo();

		$this->reset_static( Catalogue::class, 'memo', null );
		$this->reset_static( Catalogue::class, 'loading', false );
		$this->reset_static( Store::class, 'keys', array() );
		$this->reset_static( Runtime::class, 'enabled', null );
		$this->reset_static( Runtime::class, 'shadowed', null );
		$this->reset_static( Runtime::class, 'cap_keys', array() );
		$this->reset_static( Runtime::class, 'reading', false );
		$this->reset_static( Runtime::class, 'writing', false );

		/*
		 * Roles added inside a test live in an option write the suite rolls
		 * back, but the WP_Roles instance built from it is a plain global and
		 * would survive into the next test. Dropping it forces a rebuild, which
		 * re-fires wp_roles_init and so re-registers the tag shims.
		 */
		unset( $GLOBALS['wp_roles'] );
	}

	/**
	 * Overwrite a private static property.
	 *
	 * @param string $class    Class name.
	 * @param string $property Property name.
	 * @param mixed  $value    New value.
	 */
	protected function reset_static( string $class, string $property, $value ): void {
		$ref = new \ReflectionProperty( $class, $property );

		// Required on PHP 7.4/8.0, a no-op since 8.1, deprecated since 8.5.
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}

		$ref->setValue( null, $value );
	}

	/**
	 * Call a private or protected static method.
	 *
	 * @param string $class  Class name.
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	protected function call_static( string $class, string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( $class, $method );

		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}

		return $ref->invokeArgs( null, $args );
	}

	/**
	 * Create a tag and make sure the catalogue cache sees it.
	 *
	 * @param string $slug Slug.
	 * @param array  $args Extra arguments.
	 * @return int Term id.
	 */
	protected function make_tag( string $slug, array $args = array() ): int {
		Taxonomy::register();

		$term_id = Taxonomy::create( $slug, wp_parse_args( $args, array( 'name' => ucfirst( $slug ) ) ) );

		$this->assertNotWPError( $term_id, "Could not create the tag {$slug}." );

		$this->reset_plugin_state();
		Catalogue::rebuild();

		return (int) $term_id;
	}

	/**
	 * Force a user object to be rebuilt from the database.
	 *
	 * @param int $user_id User id.
	 */
	protected function fresh_user( int $user_id ): \WP_User {
		clean_user_cache( $user_id );
		Store::flush_memo( $user_id );

		return new \WP_User( $user_id );
	}

	/**
	 * The directory the running copy was loaded from.
	 */
	protected function library_path(): string {
		return Library::path();
	}
}

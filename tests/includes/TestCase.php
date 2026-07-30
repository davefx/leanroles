<?php
/**
 * Shared base class.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Tests;

use LeanRoles\Support\Roles;
use UserTags\Catalogue;
use UserTags\Runtime;
use UserTags\Store;
use UserTags\Taxonomy;

abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Reset every piece of per-request state the plugin memoizes.
	 *
	 * The runtime deliberately caches hard — a catalogue read, a per-user tag
	 * list, the capabilities meta key. All of that is correct in a real request
	 * and poison across tests in one process.
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
	 * Clear the plugin's static caches.
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
		 * Roles added inside a test live in an option write that the test
		 * suite's transaction rolls back, but the WP_Roles instance built from
		 * it is a plain global and would survive into the next test. Dropping
		 * it forces a rebuild — which also re-fires wp_roles_init, and so
		 * re-registers the tag shims from whatever the catalogue now says.
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
	 * A fingerprint of everything the auditor is forbidden from changing.
	 *
	 * @return array
	 */
	protected function mutable_state_fingerprint(): array {
		global $wpdb;

		return array(
			'roles'     => Roles::raw_option_value(),
			'usermeta'  => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" ),
			'users'     => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
			'terms'     => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms}" ),
			'relations' => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships}" ),
			'options'   => $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE '\_transient%' AND option_name NOT LIKE '\_site\_transient%'"
			),
		);
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
	 * Run a handler that ends in wp_safe_redirect() and exit.
	 *
	 * The exit cannot be allowed to happen, so the redirect filter throws first
	 * — wp_redirect() runs its filters before it sends a header, so nothing has
	 * been emitted by the time this unwinds.
	 *
	 * @param callable $handler Code that is expected to redirect.
	 * @return string|null The redirect target, or null if it never redirected.
	 */
	protected function capture_redirect( callable $handler ): ?string {
		$captured = null;

		$filter = static function ( $location ) use ( &$captured ) {
			$captured = $location;

			throw new RedirectException( (string) $location );
		};

		add_filter( 'wp_redirect', $filter, 10, 1 );

		try {
			$handler();
		} catch ( RedirectException $e ) {
			// Expected.
		} finally {
			remove_filter( 'wp_redirect', $filter, 10 );
		}

		return $captured;
	}

	/**
	 * Capture whatever a renderer echoes.
	 *
	 * @param callable $handler Renderer.
	 */
	protected function capture_output( callable $handler ): string {
		ob_start();

		try {
			$handler();
		} finally {
			$output = ob_get_clean();
		}

		return (string) $output;
	}

	/**
	 * Set up a valid admin request: an administrator, a nonce, and the
	 * superglobals the handler reads.
	 *
	 * @param array  $request    Values for $_POST, $_GET and $_REQUEST.
	 * @param string $nonce      Nonce action, or '' to skip.
	 * @param string $nonce_name Field name to put the nonce in.
	 * @return int The administrator's id.
	 */
	protected function as_admin_request( array $request, string $nonce = '', string $nonce_name = '_wpnonce' ): int {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		/*
		 * On a network, WordPress withholds `edit_users` from site
		 * administrators — only Super Admins may edit another user. Handlers
		 * that check `edit_user` therefore need one, or the test would be
		 * asserting against core's rule rather than against the plugin.
		 */
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}

		wp_set_current_user( $admin_id );

		if ( '' !== $nonce ) {
			$request[ $nonce_name ] = wp_create_nonce( $nonce );
		}

		$_POST    = $request;
		$_GET     = $request;
		$_REQUEST = $request;

		return $admin_id;
	}

	/**
	 * Clear the request superglobals.
	 */
	protected function clear_request(): void {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		$_FILES   = array();
	}
}

/**
 * Thrown in place of the exit() that follows a redirect.
 */
class RedirectException extends \Exception {}

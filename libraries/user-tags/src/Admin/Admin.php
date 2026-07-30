<?php
/**
 * The optional admin interface.
 *
 * Nothing here is loaded unless a consumer asks for it:
 *
 *     add_filter( 'user_tags_enable_admin', '__return_true' );
 *
 * A filter rather than a method call, because a consumer can add it at
 * file-include time — before this library has booted — so there is no load
 * ordering to get right.
 *
 * The screens are part of this package rather than a separate one on purpose.
 * Two bundleable packages with independent version numbers produce a
 * compatibility matrix: a site could run the screens from one release against
 * the data layer of another, because two plugins bundled two different pairs.
 * One package cannot drift out of step with itself.
 *
 * @package UserTags
 */

namespace UserTags\Admin;

use UserTags\Library;

defined( 'ABSPATH' ) || exit;

final class Admin {

	/**
	 * Attach the screens, if a consumer asked for them.
	 *
	 * Called on `init`, which is late enough that every plugin has had a chance
	 * to add the filter and early enough for every hook below.
	 *
	 * @return void
	 */
	public static function maybe_boot(): void {
		if ( ! is_admin() ) {
			return;
		}

		/**
		 * Whether to register the bundled admin screens.
		 *
		 * @param bool $enable Default false.
		 */
		if ( ! apply_filters( 'user_tags_enable_admin', false ) ) {
			return;
		}

		require_once __DIR__ . '/Menu.php';
		require_once __DIR__ . '/Badge.php';
		require_once __DIR__ . '/Screen.php';
		require_once __DIR__ . '/UsersList.php';
		require_once __DIR__ . '/Profile.php';

		add_action( 'admin_menu', array( Menu::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'load-users_page_' . Menu::SLUG, array( Screen::class, 'handle_actions' ) );

		UsersList::boot();
		Profile::boot();
	}

	/**
	 * Capability required to manage tags.
	 *
	 * Defaults to `promote_users`: assigning a tag is the same kind of act as
	 * changing somebody's role, and WordPress already has an answer for who may
	 * do that.
	 */
	public static function capability(): string {
		/**
		 * Filter the capability required to manage user tags.
		 *
		 * @param string $capability Default `promote_users`.
		 */
		return (string) apply_filters( 'user_tags_capability', 'promote_users' );
	}

	/**
	 * Load the stylesheet, on the screens that use it.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( string $hook ): void {
		$screens = array( 'users_page_' . Menu::SLUG, 'users.php', 'user-edit.php', 'profile.php' );

		if ( ! in_array( $hook, $screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'user-tags-admin',
			self::asset_url( 'assets/css/admin.css' ),
			array(),
			Library::version()
		);
	}

	/**
	 * URL of a file inside the library.
	 *
	 * plugins_url() cannot be trusted here: the library sits at an arbitrary
	 * depth inside somebody else's plugin, and may be inside a mu-plugin or a
	 * theme. The path is resolved against WP_CONTENT_DIR instead, which covers
	 * every one of those.
	 *
	 * @param string $relative Path relative to the library root.
	 */
	private static function asset_url( string $relative ): string {
		$root = Library::root();

		if ( defined( 'WP_CONTENT_DIR' ) && 0 === strpos( $root, WP_CONTENT_DIR ) ) {
			return content_url( substr( $root, strlen( WP_CONTENT_DIR ) ) . '/' . $relative );
		}

		// Outside wp-content entirely; the consumer has to supply the URL.
		return (string) apply_filters( 'user_tags_asset_url', '', $relative, $root );
	}
}

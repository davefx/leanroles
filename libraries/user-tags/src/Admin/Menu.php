<?php
/**
 * Where the screen lives.
 *
 * Always under Users. Tags are a property of users, that is where an
 * administrator looks for them, and a library that plants a top-level entry in
 * somebody's menu because they installed a plugin for something else is the
 * mistake worth avoiding.
 *
 * @package UserTags
 */

namespace UserTags\Admin;

defined( 'ABSPATH' ) || exit;

final class Menu {

	public const SLUG = 'user-tags-lib';

	/**
	 * Register the submenu.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_users_page(
			__( 'User tags', 'user-tags-lib' ),
			__( 'Tags', 'user-tags-lib' ),
			Admin::capability(),
			self::SLUG,
			array( Screen::class, 'render' )
		);
	}

	/**
	 * URL of the screen.
	 *
	 * @param array $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => self::SLUG ), $args ),
			admin_url( 'users.php' )
		);
	}
}

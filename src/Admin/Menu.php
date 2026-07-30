<?php
/**
 * Admin menu and asset loading.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Admin;

defined( 'ABSPATH' ) || exit;

final class Menu {

	public const AUDIT_SLUG = 'leanroles';

	/**
	 * Hook suffixes of our own screens.
	 *
	 * @var string[]
	 */
	private static $screens = array();

	/**
	 * Attach the hooks.
	 */
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Register the menu.
	 */
	public static function register(): void {
		$audit = add_menu_page(
			__( 'LeanRoles', 'leanroles' ),
			__( 'LeanRoles', 'leanroles' ),
			'list_users',
			self::AUDIT_SLUG,
			array( AuditPage::class, 'render' ),
			'dashicons-chart-bar',
			71
		);

		add_submenu_page(
			self::AUDIT_SLUG,
			__( 'Role audit', 'leanroles' ),
			__( 'Audit', 'leanroles' ),
			'list_users',
			self::AUDIT_SLUG,
			array( AuditPage::class, 'render' )
		);

		self::$screens = array_filter( array( $audit ) );
	}

	/**
	 * Load the stylesheet on the audit screen.
	 *
	 * The tag screens bring their own; they belong to the bundled library now.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! in_array( $hook, self::$screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'leanroles-admin',
			LEANROLES_URL . 'assets/css/admin.css',
			array(),
			LEANROLES_VERSION
		);
	}

	/**
	 * URL of one of our screens.
	 *
	 * @param string $slug Page slug.
	 * @param array  $args Extra query arguments.
	 */
	public static function url( string $slug, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}
}

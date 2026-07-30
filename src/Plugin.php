<?php
/**
 * Plugin bootstrap.
 *
 * @package LeanRoles
 */

namespace LeanRoles;

use LeanRoles\Admin\Menu;
use LeanRoles\Admin\UserProfile;
use LeanRoles\Admin\UsersList;
use LeanRoles\Support\Roles;
use UserTags\Library as UserTagsLibrary;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/**
	 * Wire everything up.
	 */
	public static function boot(): void {
		/*
		 * The tag engine is not booted here. It lives in the bundled User Tags
		 * library, which arbitrates between every copy on the site and boots
		 * the newest — possibly one belonging to another plugin entirely.
		 */
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

		if ( is_admin() ) {
			Menu::boot();
			UsersList::boot();
			UserProfile::boot();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'cli_init', array( __CLASS__, 'register_cli' ) );
		}
	}

	/**
	 * Load translations.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'leanroles', false, dirname( plugin_basename( LEANROLES_FILE ) ) . '/languages' );
	}

	/**
	 * Register WP-CLI commands.
	 */
	public static function register_cli(): void {
		\WP_CLI::add_command( 'leanroles audit', Cli\AuditCommand::class );
		\WP_CLI::add_command( 'leanroles tag', Cli\TagCommand::class );
		\WP_CLI::add_command( 'leanroles role', Cli\RoleCommand::class );
		\WP_CLI::add_command( 'leanroles backup', Cli\BackupCommand::class );
	}

	/**
	 * Activation.
	 *
	 * Deliberately does not touch {prefix}user_roles. The plugin never rewrites
	 * the core option; a restore point is taken so that composing a conversion
	 * by hand always has something to roll back to.
	 */
	public static function activate(): void {
		if ( class_exists( UserTagsLibrary::class ) ) {
			UserTagsLibrary::activate();
		}

		Roles::create_backup( 'activation' );
	}

	/**
	 * Deactivation.
	 *
	 * Nothing is stored in the core option, so there is nothing to unwind. Tags
	 * simply stop being injected; because they grant no capabilities, the site is
	 * left functionally identical.
	 */
	public static function deactivate(): void {
		/*
		 * The tag housekeeping schedule is the library's, and another plugin
		 * may still be relying on it. Only what LeanRoles owns is cleared.
		 */
		delete_transient( Audit\StructureProbe::USER_COUNT_TRANSIENT );
	}
}

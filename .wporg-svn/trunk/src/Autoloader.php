<?php
/**
 * Minimal PSR-4 style autoloader. No composer in the distributed package.
 *
 * @package LeanRoles
 */

namespace LeanRoles;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	private const PREFIX = 'LeanRoles\\';

	/**
	 * Register the autoloader.
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Map LeanRoles\Foo\Bar to src/Foo/Bar.php.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$path     = LEANROLES_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}

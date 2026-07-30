<?php
/**
 * Plugin Name:       LeanRoles
 * Plugin URI:        https://example.com/leanroles
 * Description:       Measures what your role configuration costs on every request, and adds user tags — a zero-capability primitive that behaves like a role without weighing like one.
 * Version:           0.1.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            LeanRoles
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       leanroles
 * Domain Path:       /languages
 *
 * @package LeanRoles
 *
 * Copyright (C) 2026 David Marín
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program. If not, see <https://www.gnu.org/licenses/>.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'LEANROLES_VERSION' ) ) {
	return;
}

define( 'LEANROLES_VERSION', '0.1.0' );
define( 'LEANROLES_FILE', __FILE__ );
define( 'LEANROLES_PATH', plugin_dir_path( __FILE__ ) );
define( 'LEANROLES_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bail loudly rather than fatally on unsupported PHP.
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: current PHP version. */
						__( 'LeanRoles requires PHP 7.4 or newer. This site runs PHP %s, so the plugin has not been loaded.', 'leanroles' ),
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

/*
 * The bundled User Tags library. Included before the plugin's own bootstrap so
 * its capability filters are in place first, and included the same way any
 * other plugin would include it — LeanRoles is a consumer of this library, not
 * its owner at runtime. If another active plugin bundles a newer copy, that
 * copy wins and this one stays dormant.
 */
require_once LEANROLES_PATH . 'libraries/user-tags/user-tags.php';

require_once LEANROLES_PATH . 'src/Autoloader.php';
LeanRoles\Autoloader::register();

require_once LEANROLES_PATH . 'src/Api/functions.php';

/*
 * The runtime hooks are attached at file load, not on `plugins_loaded`.
 *
 * `WP_Roles` is instantiated by wp-settings.php immediately after `plugins_loaded`
 * and before `setup_theme`, and third-party code frequently builds `WP_User`
 * objects during `plugins_loaded`. Anything registered later would miss the
 * first capability read of the request.
 */
LeanRoles\Plugin::boot();

register_activation_hook( __FILE__, array( 'LeanRoles\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LeanRoles\\Plugin', 'deactivate' ) );

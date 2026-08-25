<?php

/**
 * Plugin Name:       LeanRoles
 * Plugin URI:        https://github.com/davefx/leanroles
 * Description:       Measures what your role configuration costs on every request, and adds user tags — a zero-capability primitive that behaves like a role without weighing like one.
 * Version:           0.5.3
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            David Marín
 * Author URI:        https://profiles.wordpress.org/davefx/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       leanroles
 * Domain Path:       /languages
 *
 * @package LeanRoles
 *
 * The free build carries no compiled translations: the plugin directory serves
 * those from translate.wordpress.org, and asks that they not be shipped. The
 * premium build is not hosted there and needs the ones it carries, so the
 * directory is excluded rather than deleted. Comma-separated, which is the
 * format the deploy reads.
 *
 * The free build carries no compiled translations: the plugin directory serves
 * those from translate.wordpress.org, and asks that they not be shipped. The
 * premium build is not hosted there and needs the ones it carries, so the
 * directory is excluded rather than deleted. Comma-separated, which is the
 * format the deploy reads.
 *
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
define( 'LEANROLES_VERSION', '0.5.3' );
define( 'LEANROLES_FILE', __FILE__ );
define( 'LEANROLES_PATH', plugin_dir_path( __FILE__ ) );
define( 'LEANROLES_URL', plugin_dir_url( __FILE__ ) );
/**
 * Bail loudly rather than fatally on unsupported PHP.
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action( 'admin_notices', static function () {
        printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sprintf( 
            /* translators: %s: current PHP version. */
            __( 'LeanRoles requires PHP 7.4 or newer. This site runs PHP %s, so the plugin has not been loaded.', 'leanroles' ),
            PHP_VERSION
         ) ) );
    } );
    return;
}
/*
 * Freemius, for licensing and the paid plans.
 *
 * Loaded before anything else the plugin does, because the SDK has to be in
 * place early enough to handle the activation redirect and the opt-in screen.
 * After the PHP guard above, though: bailing on an unsupported PHP version has
 * to happen first, or the notice never renders.
 *
 * The SDK lives in libraries/ rather than the vendor/freemius the dashboard
 * suggests. vendor/ here is Composer's, it is gitignored and .distignore keeps
 * it out of the archive, so an SDK inside it would simply not ship. The folder
 * name is not load-bearing: the SDK derives its paths from __FILE__.
 *
 * Required explicitly and never autoloaded, for the same reason as the User
 * Tags library below — Freemius also arbitrates between every copy on the site
 * and boots the newest, and an autoloader binding a class to a different copy
 * than the one that won is the failure that pattern exists to avoid.
 */
if ( !function_exists( 'leanroles_fs' ) ) {
    /**
     * The plugin's Freemius instance.
     *
     * Paid code is wrapped with the SDK's own suffixed helpers rather than any
     * gate of the plugin's own. The suffix is what Freemius' deployment
     * preprocessor looks for: the whole block is cut out of the free build, so the
     * paid code is absent rather than merely refused.
     *
     * Which helper to reach for, and why the choice is the expiry policy, is in
     * AGENTS.md. The names are spelled out there and deliberately not here:
     * bin/verify-free-build.sh greps the shipped build for them, and AGENTS.md
     * does not ship.
     *
     * The runtime shim is wrapped in none of them. It is free-tier code and stays
     * that way — switching it off would break a site that had already converted
     * its roles, over an unpaid invoice.
     *
     * @return Freemius
     */
    function leanroles_fs() {
        global $leanroles_fs;
        if ( !isset( $leanroles_fs ) ) {
            require_once LEANROLES_PATH . 'libraries/freemius/start.php';
            $leanroles_fs = fs_dynamic_init( array(
                'id'               => '37674',
                'slug'             => 'leanroles',
                'premium_slug'     => 'leanroles-premium',
                'type'             => 'plugin',
                'public_key'       => 'pk_92172d53958eee89365881e6b715f',
                'is_premium'       => false,
                'has_addons'       => false,
                'has_paid_plans'   => true,
                'is_org_compliant' => true,
                'menu'             => array(
                    'slug'    => 'leanroles',
                    'support' => false,
                ),
                'is_live'          => true,
            ) );
        }
        return $leanroles_fs;
    }

    leanroles_fs();
    /**
     * Fires once the Freemius SDK has been initialised.
     */
    do_action( 'leanroles_fs_loaded' );
    /**
     * Remove the plugin's data when it is deleted.
     *
     * On Freemius' `after_uninstall` rather than in an uninstall.php: WordPress
     * runs uninstall.php *instead of* the uninstall hooks a plugin registers, so
     * shipping both would silently stop Freemius ever hearing about it. Freemius
     * refuses a package containing one, which is how the sibling plugin in this
     * family found that out.
     *
     * The autoloader is required here rather than relied upon: this runs during
     * uninstall, when WordPress has included this file on its own and the rest
     * of the bootstrap has not necessarily happened.
     *
     * @return void
     */
    function leanroles_uninstall() {
        require_once LEANROLES_PATH . 'src/Autoloader.php';
        LeanRoles\Autoloader::register();
        ( new LeanRoles\Install\Uninstaller() )->uninstall();
    }

    leanroles_fs()->add_action( 'after_uninstall', 'leanroles_uninstall' );
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
register_activation_hook( __FILE__, array('LeanRoles\\Plugin', 'activate') );
register_deactivation_hook( __FILE__, array('LeanRoles\\Plugin', 'deactivate') );
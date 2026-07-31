<?php
/**
 * Plugin Name: User Tags
 * Plugin URI: https://github.com/davefx/user-tags
 * Description: A label that behaves like a role to the whole of WordPress while granting no capability and never touching the autoloaded role option. A library for plugins to bundle, and a plugin in its own right.
 * Author: David Marín
 * Version: 1.3.0
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: user-tags-lib
 * Domain Path: /languages
 * Requires at least: 5.9
 * Requires PHP: 7.4
 *
 * The entry point every bundling plugin includes.
 *
 * Activating this on its own works too, and is how the wordpress.org translation
 * platform comes to have anything to translate — the text domain has to match a
 * slug that exists there. Action Scheduler does the same thing, and it is the
 * only reason its own strings ever reach a site.
 *
 * A user tag is a label that behaves like a role to the whole of WordPress —
 * it appears in $user->roles, it answers current_user_can(), WP_User_Query
 * finds it — while granting no capability and never being written to the
 * autoloaded {prefix}user_roles option.
 *
 * WordPress offers no primitive for "this user is a wholesale customer"
 * without granting them permissions. There are only roles. That is why
 * membership plugins keep inventing them: they have no alternative. This is
 * the alternative, and it is free for anyone to bundle.
 *
 * Usage, from your plugin's main file:
 *
 *     require_once __DIR__ . '/libraries/user-tags/user-tags.php';
 *
 * That is all. Do not require anything else, and do not autoload src/ — see
 * readme.md, "Lessons taken from Action Scheduler".
 *
 * @package UserTags
 * @version 1.3.0
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

require_once __DIR__ . '/src/Versions.php';

UserTags_Versions::register( '1.3.0', __DIR__ . '/src/bootstrap.php', __FILE__ );

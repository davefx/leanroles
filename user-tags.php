<?php
/**
 * User Tags — the entry point every bundling plugin includes.
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
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/Versions.php';

UserTags_Versions::register( '1.0.0', __DIR__ . '/src/bootstrap.php', __FILE__ );

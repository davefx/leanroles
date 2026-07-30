<?php
/**
 * Uninstall.
 *
 * Removes what LeanRoles created and nothing else.
 *
 * In particular it does **not** remove user tags. Those belong to the bundled
 * User Tags library, which any number of other plugins may also be using, and
 * no single consumer can know it is the last one — the version registry only
 * ever sees the copies loaded in the current request, and a plugin that happens
 * to be deactivated today is still a plugin whose segments these are.
 *
 * Action Scheduler has the mirror image of this problem: its tables survive
 * every uninstall and nobody ever removes them. Here the data survives too, but
 * on purpose and with a documented way out — `user_tags_uninstall()`, run
 * deliberately by a site owner rather than as a side effect of removing one
 * plugin.
 *
 * The core role option is never touched. A plugin that edits
 * {prefix}user_roles on its way out is exactly the thing nobody should install.
 *
 * @package LeanRoles
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean up one site.
 */
function leanroles_uninstall_site() {
	delete_option( 'leanroles_roles_backup' );
	delete_transient( 'leanroles_user_counts' );
}

/**
 * Clean up every site on the installation.
 *
 * Wrapped in a function so the loop variables do not leak into the global scope
 * uninstall.php runs in.
 */
function leanroles_uninstall() {
	if ( ! is_multisite() ) {
		leanroles_uninstall_site();
		return;
	}

	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		leanroles_uninstall_site();
		restore_current_blog();
	}
}

leanroles_uninstall();

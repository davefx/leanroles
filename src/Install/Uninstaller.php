<?php
/**
 * What is removed when the plugin is deleted.
 *
 * Hung on Freemius' `after_uninstall` rather than living in an uninstall.php,
 * and not as a matter of taste: WordPress runs uninstall.php *instead of* the
 * uninstall hooks a plugin has registered, so shipping both would silently stop
 * Freemius ever hearing about the uninstall. Freemius refuses to accept a
 * package containing one, which is how the sibling plugin in this family found
 * that out.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Install;

defined( 'ABSPATH' ) || exit;

final class Uninstaller {

	/**
	 * Remove what LeanRoles created, and nothing else.
	 *
	 * In particular it does not remove user tags. Those belong to the bundled
	 * User Tags library, which any number of other plugins may also be using,
	 * and no single consumer can know it is the last one — the version registry
	 * only ever sees the copies loaded in the current request, and a plugin that
	 * happens to be deactivated today is still a plugin whose segments these
	 * are. `user_tags_uninstall()` is the documented, deliberate way out.
	 *
	 * The core role option is never touched. A plugin that edits
	 * {prefix}user_roles on its way out is exactly the thing nobody should
	 * install.
	 */
	public function uninstall(): void {
		if ( ! is_multisite() ) {
			$this->uninstall_site();
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
			$this->uninstall_site();
			restore_current_blog();
		}
	}

	/**
	 * Clean up one site.
	 */
	private function uninstall_site(): void {
		delete_option( 'leanroles_roles_backup' );
		delete_option( 'leanroles_conversions' );
		delete_option( 'leanroles_bulk_runs' );
		delete_option( 'leanroles_inheritance' );
		delete_option( 'leanroles_samples' );
		delete_option( 'leanroles_alerts' );
		delete_option( 'leanroles_fleet' );
		delete_option( 'leanroles_mepr_map' );
		wp_clear_scheduled_hook( 'leanroles_take_sample' );
		delete_transient( 'leanroles_user_counts' );
	}
}

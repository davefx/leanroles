<?php
/**
 * The role slugs actually stored in the database.
 *
 * Deliberately not wp_roles(): by the time anything asks, the runtime has
 * already injected every tag into WP_Roles as a zero-capability role, so
 * is_role() would answer true for tags as well. The question here is which
 * slugs a *real* role owns, and only the stored option knows that.
 *
 * A handful of duplicated lines rather than a dependency on the plugin that
 * happens to bundle this library. A library that reaches into its host is a
 * library nobody else can adopt.
 *
 * @package UserTags
 */

namespace UserTags;

defined( 'ABSPATH' ) || exit;

final class Roles {

	/**
	 * Slugs of the roles stored for a site.
	 *
	 * @param int|null $blog_id Site id, or null for the current one.
	 * @return array<string,true>
	 */
	public static function stored_slugs( ?int $blog_id = null ): array {
		global $wpdb;

		$roles = get_option( $wpdb->get_blog_prefix( $blog_id ) . 'user_roles', array() );

		return is_array( $roles ) ? array_fill_keys( array_keys( $roles ), true ) : array();
	}
}

<?php
/**
 * The `leanroles_*` API.
 *
 * Every one of these is now a thin alias over the bundled User Tags library.
 * The tag primitive is no longer LeanRoles' to own: it lives in a library any
 * plugin can bundle, precisely so that adopting it costs nothing and carries
 * nobody else's brand.
 *
 * New code should call the library directly — `user_tags_add()` and friends —
 * because those keep working whichever plugin's copy happens to be active.
 * These remain for anything written against LeanRoles' published API.
 *
 * @package LeanRoles
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'leanroles_user_has_tag' ) ) {
	/**
	 * Does this user carry this tag?
	 *
	 * @param int    $user_id User id.
	 * @param string $tag     Tag slug.
	 */
	function leanroles_user_has_tag( int $user_id, string $tag ): bool {
		return function_exists( 'user_tags_has' ) && user_tags_has( $user_id, $tag );
	}
}

if ( ! function_exists( 'leanroles_get_user_tags' ) ) {
	/**
	 * Every tag a user carries.
	 *
	 * @param int $user_id User id.
	 * @return string[] Slugs.
	 */
	function leanroles_get_user_tags( int $user_id ): array {
		return function_exists( 'user_tags_get' ) ? user_tags_get( $user_id ) : array();
	}
}

if ( ! function_exists( 'leanroles_get_users_by_tag' ) ) {
	/**
	 * Every user carrying a tag.
	 *
	 * @param string $tag  Tag slug.
	 * @param array  $args number, offset, fields.
	 * @return int[]|WP_User[]
	 */
	function leanroles_get_users_by_tag( string $tag, array $args = array() ): array {
		return function_exists( 'user_tags_users' ) ? user_tags_users( $tag, $args ) : array();
	}
}

if ( ! function_exists( 'leanroles_tag_exists' ) ) {
	/**
	 * Is this slug a tag?
	 *
	 * @param string $tag Tag slug.
	 */
	function leanroles_tag_exists( string $tag ): bool {
		return function_exists( 'user_tags_exists' ) && user_tags_exists( $tag );
	}
}

if ( ! function_exists( 'leanroles_add_tag' ) ) {
	/**
	 * Give a user one or more tags.
	 *
	 * @param int             $user_id User id.
	 * @param string|string[] $tags    Slugs.
	 */
	function leanroles_add_tag( int $user_id, $tags ): bool {
		return function_exists( 'user_tags_add' ) && user_tags_add( $user_id, $tags );
	}
}

if ( ! function_exists( 'leanroles_remove_tag' ) ) {
	/**
	 * Take one or more tags away from a user.
	 *
	 * @param int             $user_id User id.
	 * @param string|string[] $tags    Slugs.
	 */
	function leanroles_remove_tag( int $user_id, $tags ): bool {
		return function_exists( 'user_tags_remove' ) && user_tags_remove( $user_id, $tags );
	}
}

if ( ! function_exists( 'leanroles_set_user_tags' ) ) {
	/**
	 * Replace a user's tags outright.
	 *
	 * @param int      $user_id User id.
	 * @param string[] $tags    Slugs.
	 * @return string[]|WP_Error The slugs now held.
	 */
	function leanroles_set_user_tags( int $user_id, array $tags ) {
		if ( ! function_exists( 'user_tags_set' ) ) {
			return new WP_Error( 'leanroles_no_library', __( 'The User Tags library is not available.', 'leanroles' ) );
		}

		return user_tags_set( $user_id, $tags );
	}
}

if ( ! function_exists( 'leanroles_register_tag' ) ) {
	/**
	 * Create a tag.
	 *
	 * @param string $slug Slug.
	 * @param array  $args name, description, color, legacy_role.
	 * @return int|WP_Error Term id.
	 */
	function leanroles_register_tag( string $slug, array $args = array() ) {
		if ( ! function_exists( 'user_tags_register' ) ) {
			return new WP_Error( 'leanroles_no_library', __( 'The User Tags library is not available.', 'leanroles' ) );
		}

		return user_tags_register( $slug, $args );
	}
}

if ( ! function_exists( 'leanroles_get_tags' ) ) {
	/**
	 * The whole catalogue.
	 *
	 * @return array<string,array{term_id:int,name:string,color:string}>
	 */
	function leanroles_get_tags(): array {
		return function_exists( 'user_tags_all' ) ? user_tags_all() : array();
	}
}

if ( ! function_exists( 'leanroles_audit' ) ) {
	/**
	 * Run the auditor and return its report.
	 *
	 * Read-only. Safe to call on a production site. This one stays LeanRoles'
	 * own: the auditor is the plugin, not the primitive.
	 *
	 * @param array $args See LeanRoles\Audit\Auditor::run().
	 */
	function leanroles_audit( array $args = array() ): array {
		return LeanRoles\Audit\Auditor::run( $args );
	}
}

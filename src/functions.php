<?php
/**
 * The public API.
 *
 * These functions are the surface other plugins are meant to call. Everything
 * else in src/ is internal and may change between versions; these will not.
 *
 * Guard your calls, the same way you would with any bundled library:
 *
 *     if ( function_exists( 'user_tags_add' ) ) {
 *         user_tags_add( $user_id, 'wholesale' );
 *     }
 *
 * @package UserTags
 */

use UserTags\Catalogue;
use UserTags\Library;
use UserTags\Store;
use UserTags\Taxonomy;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'user_tags_is_ready' ) ) {
	/**
	 * Is the library booted and usable?
	 */
	function user_tags_is_ready(): bool {
		return Library::is_ready();
	}
}

if ( ! function_exists( 'user_tags_version' ) ) {
	/**
	 * The version of the copy currently running.
	 */
	function user_tags_version(): string {
		return Library::version();
	}
}

if ( ! function_exists( 'user_tags_supports' ) ) {
	/**
	 * Does the running version answer for a named feature?
	 *
	 * Prefer this over comparing version strings: your plugin may be running
	 * against a copy bundled by somebody else.
	 *
	 * @param string $feature Feature name.
	 */
	function user_tags_supports( string $feature ): bool {
		return Library::supports( $feature );
	}
}

if ( ! function_exists( 'user_tags_register' ) ) {
	/**
	 * Create a tag.
	 *
	 * Safe to call on every request: an existing slug comes back as a WP_Error
	 * with code `user_tags_exists` rather than being duplicated.
	 *
	 * @param string $slug Slug.
	 * @param array  $args name, description, color, legacy_role.
	 * @return int|WP_Error Term id.
	 */
	function user_tags_register( string $slug, array $args = array() ) {
		return Taxonomy::create( $slug, $args );
	}
}

if ( ! function_exists( 'user_tags_unregister' ) ) {
	/**
	 * Delete a tag and every assignment of it.
	 *
	 * @param string $slug Slug.
	 * @return true|WP_Error
	 */
	function user_tags_unregister( string $slug ) {
		return Taxonomy::delete( $slug );
	}
}

if ( ! function_exists( 'user_tags_exists' ) ) {
	/**
	 * Is this slug a tag?
	 *
	 * @param string $tag Tag slug.
	 */
	function user_tags_exists( string $tag ): bool {
		return Catalogue::has( $tag );
	}
}

if ( ! function_exists( 'user_tags_all' ) ) {
	/**
	 * The whole catalogue.
	 *
	 * @return array<string,array{term_id:int,name:string,color:string}>
	 */
	function user_tags_all(): array {
		return Catalogue::all();
	}
}

if ( ! function_exists( 'user_tags_get' ) ) {
	/**
	 * Every tag a user carries.
	 *
	 * @param int $user_id User id.
	 * @return string[] Slugs.
	 */
	function user_tags_get( int $user_id ): array {
		return Store::get_tags( $user_id );
	}
}

if ( ! function_exists( 'user_tags_has' ) ) {
	/**
	 * Does this user carry this tag?
	 *
	 * Reads the usermeta mirror, so on any screen that has already primed the
	 * user meta cache this costs nothing.
	 *
	 * @param int    $user_id User id.
	 * @param string $tag     Tag slug.
	 */
	function user_tags_has( int $user_id, string $tag ): bool {
		return in_array( $tag, Store::runtime_tags( $user_id ), true );
	}
}

if ( ! function_exists( 'user_tags_add' ) ) {
	/**
	 * Give a user one or more tags.
	 *
	 * @param int             $user_id User id.
	 * @param string|string[] $tags    Slugs.
	 */
	function user_tags_add( int $user_id, $tags ): bool {
		return ! is_wp_error( Store::add( $user_id, $tags ) );
	}
}

if ( ! function_exists( 'user_tags_remove' ) ) {
	/**
	 * Take one or more tags away from a user.
	 *
	 * @param int             $user_id User id.
	 * @param string|string[] $tags    Slugs.
	 */
	function user_tags_remove( int $user_id, $tags ): bool {
		return ! is_wp_error( Store::remove( $user_id, $tags ) );
	}
}

if ( ! function_exists( 'user_tags_set' ) ) {
	/**
	 * Replace a user's tags outright.
	 *
	 * @param int      $user_id User id.
	 * @param string[] $tags    Slugs.
	 * @return string[]|WP_Error The slugs now held.
	 */
	function user_tags_set( int $user_id, array $tags ) {
		return Store::set_tags( $user_id, $tags );
	}
}

if ( ! function_exists( 'user_tags_users' ) ) {
	/**
	 * Every user carrying a tag.
	 *
	 * Goes through term_taxonomy_id, which is indexed, rather than through a
	 * LIKE across usermeta.
	 *
	 * @param string $tag  Tag slug.
	 * @param array  $args number, offset, fields ('ids' or 'all').
	 * @return int[]|WP_User[]
	 */
	function user_tags_users( string $tag, array $args = array() ): array {
		return Store::users_by_tag( $tag, $args );
	}
}

if ( ! function_exists( 'user_tags_assign_by_role' ) ) {
	/**
	 * Tag every user holding a role, in resumable batches.
	 *
	 * @param string        $tag        Tag slug.
	 * @param string        $role       Role slug.
	 * @param int           $batch_size Users per pass.
	 * @param int           $after_id   Resume point.
	 * @param callable|null $progress   Receives (processed, last_id) per batch.
	 * @return array{processed:int,last_id:int}
	 */
	function user_tags_assign_by_role( string $tag, string $role, int $batch_size = 200, int $after_id = 0, ?callable $progress = null ): array {
		return Store::assign_by_role( $tag, $role, $batch_size, $after_id, $progress );
	}
}

if ( ! function_exists( 'user_tags_diagnostics' ) ) {
	/**
	 * Which copy is running, which copies were seen, and what they collided on.
	 *
	 * @return array
	 */
	function user_tags_diagnostics(): array {
		return Library::diagnostics();
	}
}

if ( ! function_exists( 'user_tags_uninstall' ) ) {
	/**
	 * Erase every tag and assignment on this site.
	 *
	 * Never called automatically. See UserTags\Library::uninstall().
	 *
	 * @param bool $network_wide Walk every site on a network.
	 * @return int Terms removed.
	 */
	function user_tags_uninstall( bool $network_wide = false ): int {
		return Library::uninstall( $network_wide );
	}
}

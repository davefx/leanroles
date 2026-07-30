<?php
/**
 * Deleting a user does not delete their term relationships.
 *
 * Core's wp_delete_user() clears usermeta for us, so the mirror looks after
 * itself.
 * Term relationships it leaves behind: wp_delete_object_term_relationships()
 * is only called for the taxonomies of a post's post type, and a user is not a
 * post. Left alone, the rows accumulate forever and — worse — a later user
 * created with a recycled id would inherit them.
 *
 * @package UserTags
 */

namespace UserTags;

defined( 'ABSPATH' ) || exit;

final class Cleanup {

	/**
	 * Attach the hooks.
	 */
	public static function boot(): void {
		add_action( 'deleted_user', array( __CLASS__, 'purge_user' ), 10, 1 );
		add_action( 'wpmu_delete_user', array( __CLASS__, 'purge_user' ), 10, 1 );
		add_action( 'remove_user_from_blog', array( __CLASS__, 'purge_user' ), 10, 1 );
	}

	/**
	 * Drop every tag relationship held by a user.
	 *
	 * @param int $user_id User id.
	 */
	public static function purge_user( $user_id ): void {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return;
		}

		if ( ! taxonomy_exists( Taxonomy::NAME ) ) {
			Taxonomy::register();
		}

		wp_delete_object_term_relationships( $user_id, Taxonomy::NAME );
		delete_user_meta( $user_id, Store::mirror_key() );
		Store::flush_memo( $user_id );
	}
}

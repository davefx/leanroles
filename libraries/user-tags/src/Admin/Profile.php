<?php
/**
 * Tags on the user profile screen.
 *
 * @package UserTags
 */

namespace UserTags\Admin;

use UserTags\Catalogue;
use UserTags\Store;
use UserTags\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class Profile {

	private const NONCE = 'user_tags_profile';

	/**
	 * Attach the hooks.
	 */
	public static function boot(): void {
		add_action( 'show_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save' ) );
	}

	/**
	 * Render the checkboxes.
	 *
	 * @param \WP_User $user User being edited.
	 */
	public static function render( $user ): void {
		$terms = Taxonomy::all_terms();

		if ( ! $terms ) {
			return;
		}

		$editable = current_user_can( Admin::capability() ) && current_user_can( 'edit_user', $user->ID );
		$current  = array_flip( Store::get_tags( (int) $user->ID ) );

		echo '<h2>' . esc_html__( 'User tags', 'user-tags-lib' ) . '</h2>';

		echo '<table class="form-table"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'Tags', 'user-tags-lib' ) . '</th><td>';

		if ( $editable ) {
			wp_nonce_field( self::NONCE, 'user_tags_profile_nonce', false );
			echo '<input type="hidden" name="user_tags_submitted" value="1" />';
		}

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'User tags', 'user-tags-lib' ) . '</legend>';

		foreach ( $terms as $term ) {
			printf(
				'<label class="user-tags-checkbox"><input type="checkbox" name="user_tags[]" value="%s" %s %s /> %s</label><br />',
				esc_attr( $term->slug ),
				checked( isset( $current[ $term->slug ] ), true, false ),
				disabled( $editable, false, false ),
				Badge::render( $term->name, (string) get_term_meta( $term->term_id, Taxonomy::META_COLOR, true ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		echo '</fieldset>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Tags grant no capabilities. They are stored outside the role option, so adding one costs nothing on subsequent requests.', 'user-tags-lib' )
		);

		echo '</td></tr></tbody></table>';
	}

	/**
	 * Save.
	 *
	 * @param int $user_id User id.
	 */
	public static function save( $user_id ): void {
		if ( empty( $_POST['user_tags_submitted'] ) ) {
			return;
		}

		if ( ! current_user_can( Admin::capability() ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		check_admin_referer( self::NONCE, 'user_tags_profile_nonce' );

		$submitted = isset( $_POST['user_tags'] ) ? (array) wp_unslash( $_POST['user_tags'] ) : array();
		$slugs     = array();

		foreach ( $submitted as $slug ) {
			$slug = sanitize_key( $slug );

			if ( Catalogue::has( $slug ) ) {
				$slugs[] = $slug;
			}
		}

		Store::set_tags( (int) $user_id, $slugs );
	}
}

<?php
/**
 * The tag management screen.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Admin;

use UserTags\Csv;
use UserTags\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class TagsPage {

	private const NONCE = 'leanroles_tags';

	/**
	 * Handle create, update, delete, import and export before anything renders.
	 */
	public static function handle_actions(): void {
		if ( ! isset( $_POST['leanroles_action'] ) && ! isset( $_GET['leanroles_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'promote_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage user tags.', 'leanroles' ) );
		}

		$action = isset( $_POST['leanroles_action'] )
			? sanitize_key( wp_unslash( $_POST['leanroles_action'] ) )
			: sanitize_key( wp_unslash( $_GET['leanroles_action'] ) );

		check_admin_referer( self::NONCE );

		switch ( $action ) {
			case 'create':
				self::do_create();
				break;

			case 'update':
				self::do_update();
				break;

			case 'delete':
				self::do_delete();
				break;

			case 'import':
				self::do_import();
				break;

			case 'export':
				self::do_export();
				break;
		}
	}

	/**
	 * Create a tag.
	 */
	private static function do_create(): void {
		$slug = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );

		$result = Taxonomy::create(
			$slug,
			array(
				'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
				'color'       => sanitize_text_field( wp_unslash( $_POST['color'] ?? '' ) ),
				'legacy_role' => sanitize_key( wp_unslash( $_POST['legacy_role'] ?? '' ) ),
			)
		);

		self::redirect(
			is_wp_error( $result )
				? array( 'error' => $result->get_error_message() )
				: array( 'message' => 'created' )
		);
	}

	/**
	 * Update a tag.
	 */
	private static function do_update(): void {
		$slug = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );

		$result = Taxonomy::update(
			$slug,
			array(
				'name'        => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
				'color'       => sanitize_text_field( wp_unslash( $_POST['color'] ?? '' ) ),
				'legacy_role' => sanitize_key( wp_unslash( $_POST['legacy_role'] ?? '' ) ),
			)
		);

		self::redirect(
			is_wp_error( $result )
				? array( 'error' => $result->get_error_message() )
				: array( 'message' => 'updated' )
		);
	}

	/**
	 * Delete a tag.
	 */
	private static function do_delete(): void {
		$slug   = sanitize_key( wp_unslash( $_GET['slug'] ?? $_POST['slug'] ?? '' ) );
		$result = Taxonomy::delete( $slug );

		self::redirect(
			is_wp_error( $result )
				? array( 'error' => $result->get_error_message() )
				: array( 'message' => 'deleted' )
		);
	}

	/**
	 * Import assignments from an uploaded CSV.
	 */
	private static function do_import(): void {
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			self::redirect( array( 'error' => __( 'No file was uploaded.', 'leanroles' ) ) );
		}

		$csv = file_get_contents( $_FILES['csv']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $csv ) {
			self::redirect( array( 'error' => __( 'The uploaded file could not be read.', 'leanroles' ) ) );
		}

		$result = Csv::import_assignments(
			Csv::from_string( $csv ),
			! empty( $_POST['create_tags'] ),
			! empty( $_POST['replace'] )
		);

		set_transient( 'leanroles_import_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

		self::redirect( array( 'message' => 'imported' ) );
	}

	/**
	 * Stream a CSV export.
	 */
	private static function do_export(): void {
		$what = 'catalogue' === ( $_GET['what'] ?? '' ) ? 'catalogue' : 'assignments';
		$rows = 'catalogue' === $what ? Csv::export_catalogue() : Csv::export_assignments();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=leanroles-' . $what . '-' . gmdate( 'Ymd' ) . '.csv' );

		echo Csv::to_string( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	/**
	 * Redirect back to the screen with a notice.
	 *
	 * @param array $args Query arguments.
	 */
	private static function redirect( array $args ): void {
		wp_safe_redirect( Menu::url( Menu::TAGS_SLUG, $args ) );
		exit;
	}

	/**
	 * Render.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'promote_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage user tags.', 'leanroles' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$editing = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		$error   = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap leanroles-tags">';
		echo '<h1>' . esc_html__( 'User tags', 'leanroles' ) . '</h1>';

		echo '<p class="leanroles-lede">';
		esc_html_e( 'A tag grants no capability at all, and the rest of WordPress cannot tell it from a role: it shows up in $user->roles, it answers current_user_can(), and code that filters users by role slug finds it. What it does not do is live in the option that loads on every request.', 'leanroles' );
		echo '</p>';

		self::render_notice( $message, $error );
		self::render_table();
		self::render_form( $editing );
		self::render_transfer();

		echo '</div>';
	}

	/**
	 * Admin notices.
	 *
	 * @param string $message Message key.
	 * @param string $error   Error text.
	 */
	private static function render_notice( string $message, string $error ): void {
		if ( $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
			return;
		}

		$texts = array(
			'created'  => __( 'Tag created.', 'leanroles' ),
			'updated'  => __( 'Tag updated.', 'leanroles' ),
			'deleted'  => __( 'Tag deleted.', 'leanroles' ),
			'imported' => __( 'Import finished.', 'leanroles' ),
		);

		if ( ! isset( $texts[ $message ] ) ) {
			return;
		}

		printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( $texts[ $message ] ) );

		if ( 'imported' !== $message ) {
			return;
		}

		$result = get_transient( 'leanroles_import_result_' . get_current_user_id() );

		if ( ! is_array( $result ) ) {
			return;
		}

		delete_transient( 'leanroles_import_result_' . get_current_user_id() );

		printf(
			'<div class="notice notice-info"><p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: users updated, 2: rows skipped, 3: tags created. */
					__( '%1$d user(s) updated, %2$d row(s) skipped, %3$d tag(s) created.', 'leanroles' ),
					$result['imported'],
					$result['skipped'],
					count( $result['created'] )
				)
			)
		);

		if ( $result['errors'] ) {
			echo '<ul class="leanroles-import-errors">';

			foreach ( array_slice( $result['errors'], 0, 20 ) as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}

			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * The tag list.
	 */
	private static function render_table(): void {
		$terms = Taxonomy::all_terms();

		if ( ! $terms ) {
			echo '<p>' . esc_html__( 'No tags yet.', 'leanroles' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Tag', 'leanroles' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'leanroles' ) . '</th>';
		echo '<th>' . esc_html__( 'Users', 'leanroles' ) . '</th>';
		echo '<th>' . esc_html__( 'Description', 'leanroles' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $terms as $term ) {
			$color  = (string) get_term_meta( $term->term_id, Taxonomy::META_COLOR, true );
			$legacy = (string) get_term_meta( $term->term_id, Taxonomy::META_LEGACY, true );

			echo '<tr>';
			echo '<td>' . self::badge( $term->name, $color ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><code>' . esc_html( $term->slug ) . '</code>';

			if ( $legacy ) {
				echo '<br /><span class="description">' . esc_html(
					sprintf(
						/* translators: %s: role slug. */
						__( 'stands in for the role %s', 'leanroles' ),
						$legacy
					)
				) . '</span>';
			}

			echo '</td>';

			printf(
				'<td><a href="%s">%s</a></td>',
				esc_url( add_query_arg( 'role', $term->slug, admin_url( 'users.php' ) ) ),
				esc_html( number_format_i18n( (int) $term->count ) )
			);

			echo '<td>' . esc_html( $term->description ) . '</td>';

			printf(
				'<td><a href="%s">%s</a> | <a href="%s" class="leanroles-delete" onclick="return confirm(%s);">%s</a></td>',
				esc_url( Menu::url( Menu::TAGS_SLUG, array( 'edit' => $term->slug ) ) ),
				esc_html__( 'Edit', 'leanroles' ),
				esc_url(
					wp_nonce_url(
						Menu::url(
							Menu::TAGS_SLUG,
							array(
								'leanroles_action' => 'delete',
								'slug'             => $term->slug,
							)
						),
						self::NONCE
					)
				),
				esc_attr( "'" . esc_js( __( 'Delete this tag and remove it from every user carrying it?', 'leanroles' ) ) . "'" ),
				esc_html__( 'Delete', 'leanroles' )
			);

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Create/edit form.
	 *
	 * @param string $editing Slug being edited, if any.
	 */
	private static function render_form( string $editing ): void {
		$term = '' !== $editing ? Taxonomy::get_by_slug( $editing ) : null;

		$name        = $term ? $term->name : '';
		$slug        = $term ? $term->slug : '';
		$description = $term ? $term->description : '';
		$color       = $term ? (string) get_term_meta( $term->term_id, Taxonomy::META_COLOR, true ) : '';
		$legacy      = $term ? (string) get_term_meta( $term->term_id, Taxonomy::META_LEGACY, true ) : '';

		printf(
			'<h2>%s</h2>',
			$term ? esc_html__( 'Edit tag', 'leanroles' ) : esc_html__( 'Add a tag', 'leanroles' )
		);

		echo '<form method="post" action="' . esc_url( Menu::url( Menu::TAGS_SLUG ) ) . '">';
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="leanroles_action" value="%s" />', $term ? 'update' : 'create' );

		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th scope="row"><label for="leanroles-name">%s</label></th><td><input type="text" id="leanroles-name" name="name" class="regular-text" value="%s" required /></td></tr>',
			esc_html__( 'Name', 'leanroles' ),
			esc_attr( $name )
		);

		printf(
			'<tr><th scope="row"><label for="leanroles-slug">%s</label></th><td><input type="text" id="leanroles-slug" name="slug" class="regular-text" value="%s" %s required /><p class="description">%s</p></td></tr>',
			esc_html__( 'Slug', 'leanroles' ),
			esc_attr( $slug ),
			$term ? 'readonly' : '',
			esc_html__( 'This is the identifier third-party code will see in $user->roles and in current_user_can(). If you are replacing a role, use the role\'s own slug so nothing else has to change.', 'leanroles' )
		);

		printf(
			'<tr><th scope="row"><label for="leanroles-description">%s</label></th><td><textarea id="leanroles-description" name="description" class="large-text" rows="2">%s</textarea></td></tr>',
			esc_html__( 'Description', 'leanroles' ),
			esc_textarea( $description )
		);

		printf(
			'<tr><th scope="row"><label for="leanroles-color">%s</label></th><td><input type="color" id="leanroles-color" name="color" value="%s" /></td></tr>',
			esc_html__( 'Colour', 'leanroles' ),
			esc_attr( $color ? $color : '#2271b1' )
		);

		printf(
			'<tr><th scope="row"><label for="leanroles-legacy">%s</label></th><td><input type="text" id="leanroles-legacy" name="legacy_role" class="regular-text" value="%s" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Replaces role', 'leanroles' ),
			esc_attr( $legacy ),
			esc_html__( 'A note for your own benefit. It records which role this tag was created to stand in for, and nothing else.', 'leanroles' )
		);

		echo '</tbody></table>';

		submit_button( $term ? __( 'Save tag', 'leanroles' ) : __( 'Add tag', 'leanroles' ) );

		if ( $term ) {
			printf(
				'<a class="button-link" href="%s">%s</a>',
				esc_url( Menu::url( Menu::TAGS_SLUG ) ),
				esc_html__( 'Cancel', 'leanroles' )
			);
		}

		echo '</form>';
	}

	/**
	 * Import and export.
	 */
	private static function render_transfer(): void {
		echo '<h2>' . esc_html__( 'Import and export', 'leanroles' ) . '</h2>';

		echo '<p>';
		printf(
			'<a class="button" href="%s">%s</a> <a class="button" href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					Menu::url( Menu::TAGS_SLUG, array( 'leanroles_action' => 'export' ) ),
					self::NONCE
				)
			),
			esc_html__( 'Export assignments', 'leanroles' ),
			esc_url(
				wp_nonce_url(
					Menu::url(
						Menu::TAGS_SLUG,
						array(
							'leanroles_action' => 'export',
							'what'             => 'catalogue',
						)
					),
					self::NONCE
				)
			),
			esc_html__( 'Export tag list', 'leanroles' )
		);
		echo '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( Menu::url( Menu::TAGS_SLUG ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="leanroles_action" value="import" />';
		echo '<p><input type="file" name="csv" accept=".csv,text/csv" required /></p>';

		printf(
			'<p><label><input type="checkbox" name="create_tags" value="1" /> %s</label><br /><label><input type="checkbox" name="replace" value="1" /> %s</label></p>',
			esc_html__( 'Create tags named in the file that do not exist yet', 'leanroles' ),
			esc_html__( 'Replace each user\'s tags instead of adding to them', 'leanroles' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The file needs a "tags" column holding semicolon-separated slugs, plus one of user_id, user_login or user_email.', 'leanroles' )
		);

		submit_button( __( 'Import', 'leanroles' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * A coloured tag badge.
	 *
	 * @param string $label Tag name.
	 * @param string $color Hex colour.
	 */
	public static function badge( string $label, string $color = '' ): string {
		$color = sanitize_hex_color( $color );

		return sprintf(
			'<span class="leanroles-badge" style="%s">%s</span>',
			$color ? 'background-color:' . esc_attr( $color ) . ';color:' . esc_attr( self::contrast( $color ) ) . ';' : '',
			esc_html( $label )
		);
	}

	/**
	 * Black or white, whichever stays legible on the given background.
	 *
	 * @param string $hex Hex colour.
	 */
	private static function contrast( string $hex ): string {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return '#fff';
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		// Perceived brightness, the usual weighting.
		return ( ( $r * 299 + $g * 587 + $b * 114 ) / 1000 ) > 140 ? '#1d2327' : '#fff';
	}
}

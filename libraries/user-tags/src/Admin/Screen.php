<?php
/**
 * The tag management screen.
 *
 * @package UserTags
 */

namespace UserTags\Admin;

use UserTags\Csv;
use UserTags\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class Screen {

	private const NONCE = 'user_tags_manage';

	/**
	 * Handle create, update, delete, import and export before anything renders.
	 */
	public static function handle_actions(): void {
		if ( ! isset( $_POST['user_tags_action'] ) && ! isset( $_GET['user_tags_action'] ) ) {
			return;
		}

		if ( ! current_user_can( Admin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage user tags.', 'user-tags-lib' ) );
		}

		$action = isset( $_POST['user_tags_action'] )
			? sanitize_key( wp_unslash( $_POST['user_tags_action'] ) )
			: sanitize_key( wp_unslash( $_GET['user_tags_action'] ) );

		check_admin_referer( self::NONCE );

		/*
		 * Everything below runs after check_admin_referer() above, and after the
		 * capability check. PHPCS cannot see across the call into the handlers,
		 * so each one reads $_POST as if unguarded; it is not. The disable is
		 * lifted at the end of the class.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

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
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			self::redirect( array( 'error' => __( 'No file was uploaded.', 'user-tags-lib' ) ) );
		}

		// The path is PHP's own, not the request's, and is_uploaded_file() above
		// is what validates it — sanitising a temp path would only corrupt it.
		// phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$csv = file_get_contents( $_FILES['csv']['tmp_name'] );

		if ( false === $csv ) {
			self::redirect( array( 'error' => __( 'The uploaded file could not be read.', 'user-tags-lib' ) ) );
		}

		$result = Csv::import_assignments(
			Csv::from_string( $csv ),
			! empty( $_POST['create_tags'] ),
			! empty( $_POST['replace'] )
		);

		set_transient( 'user_tags_import_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

		self::redirect( array( 'message' => 'imported' ) );
	}

	/**
	 * Stream a CSV export.
	 */
	private static function do_export(): void {
		$what = 'catalogue' === sanitize_key( wp_unslash( $_GET['what'] ?? '' ) ) ? 'catalogue' : 'assignments';
		$rows = 'catalogue' === $what ? Csv::export_catalogue() : Csv::export_assignments();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=user-tags-' . $what . '-' . gmdate( 'Ymd' ) . '.csv' );

		echo Csv::to_string( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	/**
	 * Redirect back to the screen with a notice.
	 *
	 * @param array $args Query arguments.
	 */
	private static function redirect( array $args ): void {
		wp_safe_redirect( Menu::url( $args ) );
		exit;
	}

	/**
	 * Render.
	 */
	public static function render(): void {
		if ( ! current_user_can( Admin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to manage user tags.', 'user-tags-lib' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$editing = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$message = isset( $_GET['message'] ) ? sanitize_key( wp_unslash( $_GET['message'] ) ) : '';
		$error   = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap user-tags-screen">';
		echo '<h1>' . esc_html__( 'User tags', 'user-tags-lib' ) . '</h1>';

		echo '<p class="user-tags-lede">';
		esc_html_e( 'A tag grants no capability at all, and the rest of WordPress cannot tell it from a role: it shows up in $user->roles, it answers current_user_can(), and code that filters users by role slug finds it. What it does not do is live in the option that loads on every request.', 'user-tags-lib' );
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
			'created'  => __( 'Tag created.', 'user-tags-lib' ),
			'updated'  => __( 'Tag updated.', 'user-tags-lib' ),
			'deleted'  => __( 'Tag deleted.', 'user-tags-lib' ),
			'imported' => __( 'Import finished.', 'user-tags-lib' ),
		);

		if ( ! isset( $texts[ $message ] ) ) {
			return;
		}

		printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( $texts[ $message ] ) );

		if ( 'imported' !== $message ) {
			return;
		}

		$result = get_transient( 'user_tags_import_result_' . get_current_user_id() );

		if ( ! is_array( $result ) ) {
			return;
		}

		delete_transient( 'user_tags_import_result_' . get_current_user_id() );

		printf(
			'<div class="notice notice-info"><p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: users updated, 2: rows skipped, 3: tags created. */
					__( '%1$d user(s) updated, %2$d row(s) skipped, %3$d tag(s) created.', 'user-tags-lib' ),
					$result['imported'],
					$result['skipped'],
					count( $result['created'] )
				)
			)
		);

		if ( $result['errors'] ) {
			echo '<ul class="user-tags-import-errors">';

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
			echo '<p>' . esc_html__( 'No tags yet.', 'user-tags-lib' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Tag', 'user-tags-lib' ) . '</th>';
		echo '<th>' . esc_html__( 'Slug', 'user-tags-lib' ) . '</th>';
		echo '<th>' . esc_html__( 'Users', 'user-tags-lib' ) . '</th>';
		echo '<th>' . esc_html__( 'Description', 'user-tags-lib' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $terms as $term ) {
			$color  = (string) get_term_meta( $term->term_id, Taxonomy::META_COLOR, true );
			$legacy = (string) get_term_meta( $term->term_id, Taxonomy::META_LEGACY, true );

			echo '<tr>';
			echo '<td>' . Badge::render( $term->name, $color ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><code>' . esc_html( $term->slug ) . '</code>';

			if ( $legacy ) {
				echo '<br /><span class="description">' . esc_html(
					sprintf(
						/* translators: %s: role slug. */
						__( 'stands in for the role %s', 'user-tags-lib' ),
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
				'<td><a href="%s">%s</a> | <a href="%s" class="user-tags-delete" onclick="return confirm(%s);">%s</a></td>',
				esc_url( Menu::url( array( 'edit' => $term->slug ) ) ),
				esc_html__( 'Edit', 'user-tags-lib' ),
				esc_url(
					wp_nonce_url(
						Menu::url(
							array(
								'user_tags_action' => 'delete',
								'slug'             => $term->slug,
							)
						),
						self::NONCE
					)
				),
				esc_attr( "'" . esc_js( __( 'Delete this tag and remove it from every user carrying it?', 'user-tags-lib' ) ) . "'" ),
				esc_html__( 'Delete', 'user-tags-lib' )
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
			$term ? esc_html__( 'Edit tag', 'user-tags-lib' ) : esc_html__( 'Add a tag', 'user-tags-lib' )
		);

		echo '<form method="post" action="' . esc_url( Menu::url() ) . '">';
		wp_nonce_field( self::NONCE );
		printf( '<input type="hidden" name="user_tags_action" value="%s" />', $term ? 'update' : 'create' );

		echo '<table class="form-table"><tbody>';

		printf(
			'<tr><th scope="row"><label for="user-tags-name">%s</label></th><td><input type="text" id="user-tags-name" name="name" class="regular-text" value="%s" required /></td></tr>',
			esc_html__( 'Name', 'user-tags-lib' ),
			esc_attr( $name )
		);

		printf(
			'<tr><th scope="row"><label for="user-tags-slug">%s</label></th><td><input type="text" id="user-tags-slug" name="slug" class="regular-text" value="%s" %s required /><p class="description">%s</p></td></tr>',
			esc_html__( 'Slug', 'user-tags-lib' ),
			esc_attr( $slug ),
			$term ? 'readonly' : '',
			esc_html__( 'This is the identifier third-party code will see in $user->roles and in current_user_can(). If you are replacing a role, use the role\'s own slug so nothing else has to change.', 'user-tags-lib' )
		);

		printf(
			'<tr><th scope="row"><label for="user-tags-description">%s</label></th><td><textarea id="user-tags-description" name="description" class="large-text" rows="2">%s</textarea></td></tr>',
			esc_html__( 'Description', 'user-tags-lib' ),
			esc_textarea( $description )
		);

		printf(
			'<tr><th scope="row"><label for="user-tags-color">%s</label></th><td><input type="color" id="user-tags-color" name="color" value="%s" /></td></tr>',
			esc_html__( 'Colour', 'user-tags-lib' ),
			esc_attr( $color ? $color : '#2271b1' )
		);

		printf(
			'<tr><th scope="row"><label for="user-tags-legacy">%s</label></th><td><input type="text" id="user-tags-legacy" name="legacy_role" class="regular-text" value="%s" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Replaces role', 'user-tags-lib' ),
			esc_attr( $legacy ),
			esc_html__( 'A note for your own benefit. It records which role this tag was created to stand in for, and nothing else.', 'user-tags-lib' )
		);

		echo '</tbody></table>';

		submit_button( $term ? __( 'Save tag', 'user-tags-lib' ) : __( 'Add tag', 'user-tags-lib' ) );

		if ( $term ) {
			printf(
				'<a class="button-link" href="%s">%s</a>',
				esc_url( Menu::url() ),
				esc_html__( 'Cancel', 'user-tags-lib' )
			);
		}

		echo '</form>';
	}

	/**
	 * Import and export.
	 */
	private static function render_transfer(): void {
		echo '<h2>' . esc_html__( 'Import and export', 'user-tags-lib' ) . '</h2>';

		echo '<p>';
		printf(
			'<a class="button" href="%s">%s</a> <a class="button" href="%s">%s</a>',
			esc_url(
				wp_nonce_url(
					Menu::url( array( 'user_tags_action' => 'export' ) ),
					self::NONCE
				)
			),
			esc_html__( 'Export assignments', 'user-tags-lib' ),
			esc_url(
				wp_nonce_url(
					Menu::url(
						array(
							'user_tags_action' => 'export',
							'what'             => 'catalogue',
						)
					),
					self::NONCE
				)
			),
			esc_html__( 'Export tag list', 'user-tags-lib' )
		);
		echo '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( Menu::url() ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="user_tags_action" value="import" />';
		echo '<p><input type="file" name="csv" accept=".csv,text/csv" required /></p>';

		printf(
			'<p><label><input type="checkbox" name="create_tags" value="1" /> %s</label><br /><label><input type="checkbox" name="replace" value="1" /> %s</label></p>',
			esc_html__( 'Create tags named in the file that do not exist yet', 'user-tags-lib' ),
			esc_html__( 'Replace each user\'s tags instead of adding to them', 'user-tags-lib' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The file needs a "tags" column holding semicolon-separated slugs, plus one of user_id, user_login or user_email.', 'user-tags-lib' )
		);

		submit_button( __( 'Import', 'user-tags-lib' ), 'secondary' );
		echo '</form>';
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
}

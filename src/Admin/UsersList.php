<?php
/**
 * Tags on the users list: a column, filter links, and bulk assignment.
 *
 * The column reads the usermeta mirror, which cache_users() has already primed
 * for the whole page in a single query. Reading the taxonomy here instead
 * would be one query per row.
 *
 * @package UserTags
 */

namespace UserTags\Admin;

use UserTags\Catalogue;
use UserTags\Store;
use UserTags\Taxonomy;

defined( 'ABSPATH' ) || exit;

final class UsersList {

	private const NONCE = 'user_tags_bulk';

	/**
	 * Attach the hooks.
	 */
	public static function boot(): void {
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );
		add_filter( 'views_users', array( __CLASS__, 'add_views' ) );
		add_action( 'restrict_manage_users', array( __CLASS__, 'render_controls' ) );
		add_action( 'load-users.php', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
	}

	/**
	 * Report the outcome of a bulk tag action.
	 */
	public static function bulk_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['user_tags_bulk'] ) ) {
			return;
		}

		$outcome = sanitize_key( wp_unslash( $_GET['user_tags_bulk'] ) );
		$count   = isset( $_GET['user_tags_count'] ) ? (int) $_GET['user_tags_count'] : 0;
		$slug    = isset( $_GET['user_tag'] ) ? sanitize_key( wp_unslash( $_GET['user_tag'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'nothing' === $outcome ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__( 'Pick a tag and at least one user first.', 'user-tags-lib' )
			);
			return;
		}

		$text = 'removed' === $outcome
			/* translators: 1: number of users, 2: tag slug. */
			? __( 'Removed "%2$s" from %1$d user(s).', 'user-tags-lib' )
			/* translators: 1: number of users, 2: tag slug. */
			: __( 'Tagged %1$d user(s) with "%2$s".', 'user-tags-lib' );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( $text, $count, $slug ) )
		);
	}

	/**
	 * Add the Tags column, just after Role.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( array $columns ): array {
		if ( ! Catalogue::all() ) {
			return $columns;
		}

		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'role' === $key ) {
				$out['user_tags'] = __( 'Tags', 'user-tags-lib' );
			}
		}

		if ( ! isset( $out['user_tags'] ) ) {
			$out['user_tags'] = __( 'Tags', 'user-tags-lib' );
		}

		return $out;
	}

	/**
	 * Render the column.
	 *
	 * @param string $output  Current output.
	 * @param string $column  Column key.
	 * @param int    $user_id User id.
	 * @return string
	 */
	public static function render_column( $output, $column, $user_id ) {
		if ( 'user_tags' !== $column ) {
			return $output;
		}

		$tags      = Store::runtime_tags( (int) $user_id );
		$catalogue = Catalogue::all();

		if ( ! $tags ) {
			return '<span aria-hidden="true">—</span>';
		}

		$html = '';

		foreach ( $tags as $slug ) {
			$html .= sprintf(
				'<a href="%s">%s</a> ',
				esc_url( add_query_arg( 'role', $slug, admin_url( 'users.php' ) ) ),
				Badge::render( $catalogue[ $slug ]['name'] ?? $slug, $catalogue[ $slug ]['color'] ?? '' )
			);
		}

		return $html;
	}

	/**
	 * Add a filter link per tag.
	 *
	 * Counts come from the taxonomy, which keeps them for free. count_users()
	 * would not see tags at all: it reads the capabilities meta, and tags are
	 * never written there.
	 *
	 * @param array $views Existing views.
	 * @return array
	 */
	public static function add_views( array $views ): array {
		$terms = Taxonomy::all_terms();

		if ( ! $terms ) {
			return $views;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_REQUEST['role'] ) ? sanitize_key( wp_unslash( $_REQUEST['role'] ) ) : '';

		foreach ( $terms as $term ) {
			if ( 0 === (int) $term->count ) {
				continue;
			}

			$views[ 'user_tags_' . $term->slug ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( add_query_arg( 'role', $term->slug, admin_url( 'users.php' ) ) ),
				$current === $term->slug ? ' class="current" aria-current="page"' : '',
				esc_html( $term->name ),
				esc_html( number_format_i18n( (int) $term->count ) )
			);
		}

		return $views;
	}

	/**
	 * The bulk assign controls, rendered inside the list table form so they
	 * pick up the checked users.
	 *
	 * @param string $which top or bottom.
	 */
	public static function render_controls( $which ): void {
		if ( 'top' !== $which || ! current_user_can( Admin::capability() ) ) {
			return;
		}

		$terms = Taxonomy::all_terms();

		if ( ! $terms ) {
			return;
		}

		wp_nonce_field( self::NONCE, 'user_tags_bulk_nonce', false );

		echo '<label class="screen-reader-text" for="user-tags-bulk-tag">' . esc_html__( 'Tag', 'user-tags-lib' ) . '</label>';
		echo '<select name="user_tags_bulk_tag" id="user-tags-bulk-tag">';
		echo '<option value="">' . esc_html__( 'Tag…', 'user-tags-lib' ) . '</option>';

		foreach ( $terms as $term ) {
			printf(
				'<option value="%s">%s</option>',
				esc_attr( $term->slug ),
				esc_html( $term->name )
			);
		}

		echo '</select>';

		submit_button( __( 'Add tag', 'user-tags-lib' ), '', 'user_tags_bulk_add', false );
		submit_button( __( 'Remove tag', 'user-tags-lib' ), '', 'user_tags_bulk_remove', false );
	}

	/**
	 * Apply a bulk tag action.
	 */
	public static function handle_bulk(): void {
		if ( empty( $_REQUEST['user_tags_bulk_add'] ) && empty( $_REQUEST['user_tags_bulk_remove'] ) ) {
			return;
		}

		if ( ! current_user_can( Admin::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to tag users.', 'user-tags-lib' ) );
		}

		check_admin_referer( self::NONCE, 'user_tags_bulk_nonce' );

		$slug = isset( $_REQUEST['user_tags_bulk_tag'] ) ? sanitize_key( wp_unslash( $_REQUEST['user_tags_bulk_tag'] ) ) : '';
		$ids  = isset( $_REQUEST['users'] ) ? array_map( 'intval', (array) wp_unslash( $_REQUEST['users'] ) ) : array();

		if ( '' === $slug || ! Catalogue::has( $slug ) || ! $ids ) {
			wp_safe_redirect( add_query_arg( 'user_tags_bulk', 'nothing', admin_url( 'users.php' ) ) );
			exit;
		}

		$remove = ! empty( $_REQUEST['user_tags_bulk_remove'] );
		$count  = 0;

		foreach ( $ids as $user_id ) {
			$result = $remove ? Store::remove( $user_id, $slug ) : Store::add( $user_id, $slug );

			if ( ! is_wp_error( $result ) ) {
				++$count;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'user_tags_bulk'  => $remove ? 'removed' : 'added',
					'user_tags_count' => $count,
					'user_tag'        => $slug,
				),
				admin_url( 'users.php' )
			)
		);
		exit;
	}
}

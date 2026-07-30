<?php
/**
 * CSV import and export of tag assignments and of the catalogue itself.
 *
 * @package UserTags
 */

namespace UserTags;

defined( 'ABSPATH' ) || exit;

final class Csv {

	/**
	 * Escape character for the CSV functions.
	 *
	 * Empty means RFC 4180 behaviour: quotes are doubled, backslashes are just
	 * backslashes. PHP's historic default was a backslash escape, which is not
	 * what any spreadsheet expects and which PHP 8.4 began deprecating the
	 * omission of. Passing it explicitly keeps one behaviour across 7.4 to 8.5+.
	 */
	private const ESCAPE = '';

	/**
	 * Export the catalogue.
	 *
	 * @return array[] Rows, first one the header.
	 */
	public static function export_catalogue(): array {
		$rows = array( array( 'slug', 'name', 'description', 'color', 'users' ) );

		foreach ( Taxonomy::all_terms() as $term ) {
			$rows[] = array(
				$term->slug,
				$term->name,
				$term->description,
				(string) get_term_meta( $term->term_id, Taxonomy::META_COLOR, true ),
				(string) $term->count,
			);
		}

		return $rows;
	}

	/**
	 * Export assignments, one row per tagged user.
	 *
	 * @param int $batch How many users to read at a time.
	 * @return array[] Rows, first one the header.
	 */
	public static function export_assignments( int $batch = 500 ): array {
		global $wpdb;

		$rows    = array( array( 'user_id', 'user_login', 'user_email', 'tags' ) );
		$offset  = 0;
		$mirror  = Store::mirror_key();

		do {
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY user_id ASC LIMIT %d OFFSET %d",
					$mirror,
					$batch,
					$offset
				)
			);

			if ( $user_ids ) {
				// Primes the user and usermeta caches in two queries rather than 2N.
				cache_users( $user_ids );
			}

			foreach ( $user_ids as $user_id ) {
				$tags = Store::get_tags( (int) $user_id );

				if ( ! $tags ) {
					continue;
				}

				$user = get_userdata( (int) $user_id );

				$rows[] = array(
					(string) $user_id,
					$user ? $user->user_login : '',
					$user ? $user->user_email : '',
					implode( ';', $tags ),
				);
			}

			$offset += $batch;
		} while ( count( $user_ids ) === $batch );

		return $rows;
	}

	/**
	 * Import assignments.
	 *
	 * Accepts a `user_id`, `user_login` or `user_email` column to identify the
	 * user, and a `tags` column holding semicolon- or comma-separated slugs.
	 *
	 * @param array[] $rows        Rows including the header.
	 * @param bool    $create_tags Create tags that do not yet exist.
	 * @param bool    $replace     Replace existing tags rather than adding to them.
	 * @return array{imported:int,skipped:int,created:string[],errors:string[]}
	 */
	public static function import_assignments( array $rows, bool $create_tags = false, bool $replace = false ): array {
		$result = array(
			'imported' => 0,
			'skipped'  => 0,
			'created'  => array(),
			'errors'   => array(),
		);

		$header = array_map( 'strtolower', array_map( 'trim', (array) array_shift( $rows ) ) );
		$index  = array_flip( $header );

		if ( ! isset( $index['tags'] ) ) {
			$result['errors'][] = __( 'The file has no "tags" column.', 'user-tags-lib' );
			return $result;
		}

		if ( ! isset( $index['user_id'] ) && ! isset( $index['user_login'] ) && ! isset( $index['user_email'] ) ) {
			$result['errors'][] = __( 'The file needs a user_id, user_login or user_email column.', 'user-tags-lib' );
			return $result;
		}

		foreach ( $rows as $line => $row ) {
			$user = self::resolve_user( $row, $index );

			if ( ! $user ) {
				++$result['skipped'];
				$result['errors'][] = sprintf(
					/* translators: %d: line number in the imported file. */
					__( 'Line %d: no matching user.', 'user-tags-lib' ),
					$line + 2
				);
				continue;
			}

			$slugs = array_filter( array_map( 'sanitize_key', preg_split( '/[;,]/', (string) ( $row[ $index['tags'] ] ?? '' ) ) ) );

			foreach ( $slugs as $slug ) {
				if ( Catalogue::has( $slug ) ) {
					continue;
				}

				if ( ! $create_tags ) {
					$result['errors'][] = sprintf(
						/* translators: 1: line number, 2: tag slug. */
						__( 'Line %1$d: tag "%2$s" does not exist.', 'user-tags-lib' ),
						$line + 2,
						$slug
					);
					continue;
				}

				$created = Taxonomy::create( $slug, array( 'name' => $slug ) );

				if ( is_wp_error( $created ) ) {
					$result['errors'][] = $created->get_error_message();
					continue;
				}

				$result['created'][] = $slug;
			}

			$outcome = $replace
				? Store::set_tags( $user->ID, $slugs )
				: Store::add( $user->ID, $slugs );

			if ( is_wp_error( $outcome ) ) {
				++$result['skipped'];
				$result['errors'][] = $outcome->get_error_message();
				continue;
			}

			++$result['imported'];
		}

		return $result;
	}

	/**
	 * Turn a CSV row into a user.
	 *
	 * @param array $row   Row.
	 * @param array $index Column name => position.
	 * @return \WP_User|null
	 */
	private static function resolve_user( array $row, array $index ): ?\WP_User {
		if ( isset( $index['user_id'] ) && ! empty( $row[ $index['user_id'] ] ) ) {
			$user = get_userdata( (int) $row[ $index['user_id'] ] );

			if ( $user ) {
				return $user;
			}
		}

		if ( isset( $index['user_login'] ) && ! empty( $row[ $index['user_login'] ] ) ) {
			$user = get_user_by( 'login', trim( (string) $row[ $index['user_login'] ] ) );

			if ( $user ) {
				return $user;
			}
		}

		if ( isset( $index['user_email'] ) && ! empty( $row[ $index['user_email'] ] ) ) {
			$user = get_user_by( 'email', trim( (string) $row[ $index['user_email'] ] ) );

			if ( $user ) {
				return $user;
			}
		}

		return null;
	}

	/**
	 * Render rows as a CSV string.
	 *
	 * @param array[] $rows Rows.
	 */
	public static function to_string( array $rows ): string {
		$handle = fopen( 'php://temp', 'r+' );

		foreach ( $rows as $row ) {
			fputcsv( $handle, $row, ',', '"', self::ESCAPE );
		}

		rewind( $handle );
		$csv = stream_get_contents( $handle );
		fclose( $handle );

		return (string) $csv;
	}

	/**
	 * Parse a CSV string into rows.
	 *
	 * @param string $csv Csv.
	 * @return array[]
	 */
	public static function from_string( string $csv ): array {
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $csv );
		rewind( $handle );

		$rows = array();

		// The assignment in the condition is the idiomatic way to walk a CSV
		// handle; fgetcsv() returns false only at the end of the stream.
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', self::ESCAPE ) ) ) {
			if ( array( null ) === $row ) {
				continue;
			}

			$rows[] = $row;
		}

		fclose( $handle );

		return $rows;
	}
}

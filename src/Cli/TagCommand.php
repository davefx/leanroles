<?php
/**
 * `wp leanroles tag`
 *
 * Primitives, not analysis. Create a tag, assign it in bulk, take it off again.
 * Composed with `wp leanroles role delete`, these are enough to convert a role
 * into a tag by hand — filter by role, select all, apply the tag, delete the
 * role — at your own risk and after reading the audit.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Cli;

use UserTags\Catalogue;
use UserTags\Csv;
use UserTags\Store;
use UserTags\Taxonomy;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

class TagCommand {

	/**
	 * Create a tag.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Slug for the new tag.
	 *
	 * [--name=<name>]
	 * : Display name. Defaults to the slug.
	 *
	 * [--description=<text>]
	 * : Description.
	 *
	 * [--color=<hex>]
	 * : Colour for the admin badge, as #rrggbb.
	 *
	 * [--legacy-role=<slug>]
	 * : Note that this tag stands in for a role that used to exist.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp leanroles tag create wholesale --name="Wholesale customer"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function create( $args, $assoc_args ) {
		$slug = $args[0];

		$result = Taxonomy::create(
			$slug,
			array(
				'name'        => Utils\get_flag_value( $assoc_args, 'name', $slug ),
				'description' => Utils\get_flag_value( $assoc_args, 'description', '' ),
				'color'       => Utils\get_flag_value( $assoc_args, 'color', '' ),
				'legacy_role' => Utils\get_flag_value( $assoc_args, 'legacy-role', '' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Created tag "%s" (term %d).', $slug, $result ) );
	}

	/**
	 * Delete a tag and every assignment of it.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Tag to delete.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function delete( $args, $assoc_args ) {
		$slug = $args[0];
		$term = Taxonomy::get_by_slug( $slug );

		if ( ! $term ) {
			WP_CLI::error( sprintf( 'No tag with the slug "%s".', $slug ) );
		}

		// Logged as well as asked, so that --yes runs still record what was
		// about to be destroyed.
		WP_CLI::log( sprintf( 'Tag "%s" is carried by %d user(s).', $slug, (int) $term->count ) );

		WP_CLI::confirm(
			sprintf( 'Delete "%s" and remove it from %d user(s)?', $slug, (int) $term->count ),
			$assoc_args
		);

		$result = Taxonomy::delete( $slug );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Deleted tag "%s".', $slug ) );
	}

	/**
	 * List every tag.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * [--rebuild]
	 * : Rebuild the catalogue cache from the taxonomy before listing.
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ) {
		if ( Utils\get_flag_value( $assoc_args, 'rebuild', false ) ) {
			Catalogue::rebuild();
			WP_CLI::log( 'Catalogue cache rebuilt.' );
		}

		$rows = array();

		foreach ( Taxonomy::all_terms() as $term ) {
			$rows[] = array(
				'slug'        => $term->slug,
				'name'        => $term->name,
				'users'       => (int) $term->count,
				'color'       => (string) get_term_meta( $term->term_id, Taxonomy::META_COLOR, true ),
				'legacy_role' => (string) get_term_meta( $term->term_id, Taxonomy::META_LEGACY, true ),
				'term_id'     => (int) $term->term_id,
			);
		}

		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $rows, 'slug' ) ) );
			return;
		}

		Utils\format_items( $format, $rows, 'slug,name,users,color,legacy_role,term_id' );
	}

	/**
	 * Give a tag to users.
	 *
	 * With --role, this is step two of a conversion done by hand: select by
	 * source role, apply the tag. It processes in batches, drops the user
	 * caches between passes, and reports the last id it touched so an
	 * interrupted run can be resumed rather than restarted.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Tag to assign.
	 *
	 * [--role=<role>]
	 * : Assign to every user holding this role.
	 *
	 * [--users=<ids>]
	 * : Assign to these user ids, comma separated.
	 *
	 * [--batch-size=<n>]
	 * : Users per pass.
	 * ---
	 * default: 200
	 * ---
	 *
	 * [--resume-after=<id>]
	 * : Only touch users with an id greater than this. Use the value the
	 *   previous run reported when it stopped.
	 *
	 * ## EXAMPLES
	 *
	 *     # Everyone who currently holds a role.
	 *     $ wp leanroles tag assign wholesale --role=wholesale_customer
	 *
	 *     # Pick up where an interrupted run left off.
	 *     $ wp leanroles tag assign wholesale --role=wholesale_customer --resume-after=48213
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function assign( $args, $assoc_args ) {
		$slug = $args[0];

		if ( ! Catalogue::has( $slug ) ) {
			WP_CLI::error( sprintf( 'No tag with the slug "%s". Create it first.', $slug ) );
		}

		$role  = Utils\get_flag_value( $assoc_args, 'role', '' );
		$users = Utils\get_flag_value( $assoc_args, 'users', '' );

		if ( ! $role && ! $users ) {
			WP_CLI::error( 'Give either --role or --users.' );
		}

		if ( $users ) {
			$ids   = array_filter( array_map( 'intval', explode( ',', (string) $users ) ) );
			$count = 0;

			foreach ( $ids as $id ) {
				$result = Store::add( $id, $slug );

				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf( 'User %d: %s', $id, $result->get_error_message() ) );
					continue;
				}

				++$count;
			}

			WP_CLI::success( sprintf( 'Tagged %d user(s) with "%s".', $count, $slug ) );
			return;
		}

		if ( ! wp_roles()->is_role( $role ) ) {
			WP_CLI::error( sprintf( 'No role with the slug "%s".', $role ) );
		}

		$batch = max( 1, (int) Utils\get_flag_value( $assoc_args, 'batch-size', 200 ) );
		$after = (int) Utils\get_flag_value( $assoc_args, 'resume-after', 0 );
		$last  = $after;

		WP_CLI::log( sprintf( 'Tagging users with the role "%s" in batches of %d.', $role, $batch ) );

		try {
			$result = Store::assign_by_role(
				$slug,
				$role,
				$batch,
				$after,
				static function ( $processed, $last_id ) use ( &$last ) {
					$last = $last_id;
					WP_CLI::log( sprintf( '  %d done, last user id %d.', $processed, $last_id ) );
				}
			);
		} catch ( \Throwable $e ) {
			WP_CLI::error(
				sprintf(
					"Stopped after user %d: %s\nResume with --resume-after=%d.",
					$last,
					$e->getMessage(),
					$last
				)
			);
		}

		WP_CLI::success(
			sprintf(
				'Tagged %d user(s) with "%s". Last user id %d.',
				$result['processed'],
				$slug,
				$result['last_id']
			)
		);
	}

	/**
	 * Take a tag away from users.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Tag to remove.
	 *
	 * [--users=<ids>]
	 * : User ids, comma separated.
	 *
	 * [--all]
	 * : Remove it from everyone who has it.
	 *
	 * [--yes]
	 * : Skip the confirmation for --all.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function remove( $args, $assoc_args ) {
		$slug = $args[0];

		if ( ! Catalogue::has( $slug ) ) {
			WP_CLI::error( sprintf( 'No tag with the slug "%s".', $slug ) );
		}

		if ( Utils\get_flag_value( $assoc_args, 'all', false ) ) {
			$ids = Store::users_by_tag( $slug );

			WP_CLI::confirm( sprintf( 'Remove "%s" from %d user(s)?', $slug, count( $ids ) ), $assoc_args );
		} else {
			$users = Utils\get_flag_value( $assoc_args, 'users', '' );

			if ( ! $users ) {
				WP_CLI::error( 'Give either --users or --all.' );
			}

			$ids = array_filter( array_map( 'intval', explode( ',', (string) $users ) ) );
		}

		$count = 0;

		foreach ( $ids as $id ) {
			$result = Store::remove( (int) $id, $slug );

			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf( 'User %d: %s', $id, $result->get_error_message() ) );
				continue;
			}

			++$count;
		}

		WP_CLI::success( sprintf( 'Removed "%s" from %d user(s).', $slug, $count ) );
	}

	/**
	 * List the users carrying a tag.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Tag slug.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - ids
	 *   - count
	 * ---
	 *
	 * @subcommand users
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function users( $args, $assoc_args ) {
		$slug = $args[0];

		if ( ! Catalogue::has( $slug ) ) {
			WP_CLI::error( sprintf( 'No tag with the slug "%s".', $slug ) );
		}

		$ids    = Store::users_by_tag( $slug );
		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', $ids ) );
			return;
		}

		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $ids ) );
			return;
		}

		$rows = array();

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			cache_users( $chunk );

			foreach ( $chunk as $id ) {
				$user = get_userdata( $id );

				$rows[] = array(
					'ID'         => $id,
					'user_login' => $user ? $user->user_login : '',
					'user_email' => $user ? $user->user_email : '',
					'roles'      => $user ? implode( ',', array_diff( (array) $user->roles, array( $slug ) ) ) : '',
				);
			}
		}

		Utils\format_items( $format, $rows, 'ID,user_login,user_email,roles' );
	}

	/**
	 * Export tags or assignments as CSV.
	 *
	 * ## OPTIONS
	 *
	 * [--what=<what>]
	 * : What to export.
	 * ---
	 * default: assignments
	 * options:
	 *   - assignments
	 *   - catalogue
	 * ---
	 *
	 * [--file=<path>]
	 * : Write here instead of to stdout.
	 *
	 * @subcommand export
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function export( $args, $assoc_args ) {
		$what = Utils\get_flag_value( $assoc_args, 'what', 'assignments' );
		$rows = 'catalogue' === $what ? Csv::export_catalogue() : Csv::export_assignments();
		$csv  = Csv::to_string( $rows );
		$file = Utils\get_flag_value( $assoc_args, 'file', '' );

		if ( ! $file ) {
			WP_CLI::line( rtrim( $csv, "\n" ) );
			return;
		}

		$directory = dirname( $file );

		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			WP_CLI::error( sprintf( 'Could not write to %s: the directory is not writable.', $file ) );
		}

		// Silenced deliberately: a failed write is reported as a clean CLI
		// error, not as a raw PHP warning halfway through the output.
		if ( false === @file_put_contents( $file, $csv ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
			WP_CLI::error( sprintf( 'Could not write to %s.', $file ) );
		}

		WP_CLI::success( sprintf( 'Wrote %d row(s) to %s.', count( $rows ) - 1, $file ) );
	}

	/**
	 * Import assignments from CSV.
	 *
	 * The file needs a `tags` column and one of `user_id`, `user_login` or
	 * `user_email`.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : CSV file to read.
	 *
	 * [--create-tags]
	 * : Create tags named in the file that do not exist yet.
	 *
	 * [--replace]
	 * : Replace each user's tags instead of adding to them.
	 *
	 * @subcommand import
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function import( $args, $assoc_args ) {
		$file = $args[0];

		if ( ! is_readable( $file ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
		}

		$csv = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$result = Csv::import_assignments(
			Csv::from_string( (string) $csv ),
			(bool) Utils\get_flag_value( $assoc_args, 'create-tags', false ),
			(bool) Utils\get_flag_value( $assoc_args, 'replace', false )
		);

		foreach ( array_slice( $result['errors'], 0, 20 ) as $error ) {
			WP_CLI::warning( $error );
		}

		if ( count( $result['errors'] ) > 20 ) {
			WP_CLI::warning( sprintf( '… and %d more problems.', count( $result['errors'] ) - 20 ) );
		}

		WP_CLI::success(
			sprintf(
				'%d user(s) updated, %d skipped, %d tag(s) created.',
				$result['imported'],
				$result['skipped'],
				count( $result['created'] )
			)
		);
	}

	/**
	 * Rebuild the usermeta mirror from the taxonomy.
	 *
	 * The mirror is derived cache; the taxonomy is the source of truth. If they
	 * ever disagree — a botched import, a direct database edit — this is the
	 * command that settles it.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<n>]
	 * : Users per pass.
	 * ---
	 * default: 500
	 * ---
	 *
	 * @subcommand rebuild-mirror
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function rebuild_mirror( $args, $assoc_args ) {
		global $wpdb;

		Catalogue::rebuild();

		$term_ids = wp_list_pluck( Catalogue::all(), 'term_id' );

		if ( ! $term_ids ) {
			WP_CLI::success( 'No tags exist, so there is no mirror to rebuild.' );
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

		// $placeholders is a run of %d sized from count( $term_ids ); the ids
		// themselves are passed as arguments.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT tr.object_id
				 FROM {$wpdb->term_relationships} tr
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tt.term_id IN ($placeholders)",
				array_values( $term_ids )
			)
		);

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// Users whose mirror says they have tags but who hold none any more.
		$stale = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				Store::mirror_key()
			)
		);

		$all      = array_unique( array_map( 'intval', array_merge( $user_ids, $stale ) ) );
		$batch    = max( 1, (int) Utils\get_flag_value( $assoc_args, 'batch-size', 500 ) );
		$progress = Utils\make_progress_bar( 'Rebuilding mirror', count( $all ) );

		foreach ( array_chunk( $all, $batch ) as $chunk ) {
			foreach ( $chunk as $user_id ) {
				Store::sync_mirror( $user_id );
				$progress->tick();
			}

			Store::flush_memo();
		}

		$progress->finish();

		WP_CLI::success( sprintf( 'Rebuilt the mirror for %d user(s).', count( $all ) ) );
	}
}

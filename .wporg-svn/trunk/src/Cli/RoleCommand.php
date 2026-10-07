<?php
/**
 * `wp leanroles role`
 *
 * The free tier's role primitives: edit one, delete one and move its users
 * somewhere else, and move a whole configuration between sites.
 *
 * Primitives, not analysis. Deleting says how many capabilities the role grants
 * and how many users hold it, which is courtesy: what those users will actually
 * lose depends on the capabilities each already holds through their other roles,
 * and nothing here works that out.
 *
 * Command line only, and that is the product decision rather than an omission.
 * A screen of capability checkboxes would put this in the category of role
 * editors, which the market prices at a third of what an auditor fetches.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Cli;

use LeanRoles\Support\Capabilities;
use LeanRoles\Support\Roles;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

class RoleCommand {

	/**
	 * Delete a role and move its users to another one.
	 *
	 * A restore point of the role option is taken first; `wp leanroles backup
	 * restore` puts it back. Note that restoring the option does not put the
	 * users back into the role — the reassignment is a separate change to
	 * usermeta, and undoing it is on you.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Role to delete.
	 *
	 * [--reassign=<slug>]
	 * : Role to move its users to. Omit to leave them with no role at all,
	 *   which on most sites means they can no longer log in usefully.
	 *
	 * [--dry-run]
	 * : Report what would happen and change nothing.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp leanroles role delete wholesale_customer --reassign=customer --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function delete( $args, $assoc_args ) {
		$slug     = $args[0];
		$reassign = (string) Utils\get_flag_value( $assoc_args, 'reassign', '' );
		$stored   = Roles::stored_roles();

		if ( ! isset( $stored[ $slug ] ) ) {
			WP_CLI::error( sprintf( 'No role with the slug "%s".', $slug ) );
		}

		$granted = Roles::granted_caps( $stored[ $slug ] );
		$real    = array_diff( $granted, Capabilities::inert() );
		$users   = count_users();
		$holders = (int) ( $users['avail_roles'][ $slug ] ?? 0 );

		WP_CLI::log( sprintf( 'Role:            %s (%s)', $slug, $stored[ $slug ]['name'] ?? $slug ) );
		WP_CLI::log( sprintf( 'Capabilities:    %d granted, %d of them beyond read/level_N', count( $granted ), count( $real ) ) );
		WP_CLI::log( sprintf( 'Users:           %d', $holders ) );
		WP_CLI::log( sprintf( 'Reassigning to:  %s', '' === $reassign ? '(nothing — they will be left with no role)' : $reassign ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'What those users will actually lose is not analysed here. Some of them' );
		WP_CLI::log( 'will already hold these capabilities through another role and notice' );
		WP_CLI::log( 'nothing; others will not. Check before you run this on production.' );

		if ( Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			WP_CLI::log( '' );
			WP_CLI::success( 'Dry run: nothing was changed.' );
			return;
		}

		WP_CLI::confirm( sprintf( 'Delete "%s" and move %d user(s)?', $slug, $holders ), $assoc_args );

		$moved = Roles::delete_role( $slug, $reassign );

		if ( is_wp_error( $moved ) ) {
			WP_CLI::error( $moved->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Deleted "%s" and moved %d user(s).', $slug, $moved ) );
	}

	/**
	 * Change a role's display name, or what it grants.
	 *
	 * A restore point is taken first; `wp leanroles backup restore` puts it back.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Role to change.
	 *
	 * [--name=<name>]
	 * : New display name.
	 *
	 * [--grant=<caps>]
	 * : Comma-separated capabilities to grant.
	 *
	 * [--deny=<caps>]
	 * : Comma-separated capabilities to set to false. Not the same as dropping
	 *   them: a denied capability still occupies a row in the role option, and
	 *   the audit counts it.
	 *
	 * [--drop=<caps>]
	 * : Comma-separated capabilities to remove from the role entirely.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp leanroles role update wholesale --name="Wholesale buyer"
	 *     $ wp leanroles role update wholesale --grant=read --drop=level_0,level_1
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function update( $args, $assoc_args ) {
		$slug = sanitize_key( $args[0] ?? '' );
		$name = $assoc_args['name'] ?? null;

		$caps = array();

		foreach ( self::split( $assoc_args['grant'] ?? '' ) as $cap ) {
			$caps[ $cap ] = true;
		}

		foreach ( self::split( $assoc_args['deny'] ?? '' ) as $cap ) {
			$caps[ $cap ] = false;
		}

		$result = Roles::update_role( $slug, $name, $caps, self::split( $assoc_args['drop'] ?? '' ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				'%s (%s) now grants %d and denies %d.',
				$result['name'],
				$slug,
				$result['granted'],
				$result['denied']
			)
		);
	}

	/**
	 * Write the site's role configuration to a file.
	 *
	 * Not a restore point: `wp leanroles backup` is for putting this site back
	 * as it was. This is for moving a configuration to another site.
	 *
	 * Prints to standard output; redirect it where you want it.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp leanroles role export > roles.json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function export( $args, $assoc_args ) {
		$json = wp_json_encode( Roles::export_roles(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		// See the note on `wp leanroles tag export`: the plugin writes to the
		// filesystem nowhere, and redirection is the shell's job.
		WP_CLI::line( (string) $json );
	}

	/**
	 * Apply a role configuration from a file.
	 *
	 * A restore point is taken first. Protected roles are never touched, so a
	 * file that omits `administrator` cannot be a way to delete it.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : File written by `wp leanroles role export`.
	 *
	 * [--mode=<mode>]
	 * : merge adds and overwrites the roles the file names. replace makes this
	 *   site's roles exactly the file's.
	 * ---
	 * default: merge
	 * options:
	 *   - merge
	 *   - replace
	 * ---
	 *
	 * [--dry-run]
	 * : Report what would change and change nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp leanroles role import roles.json --mode=replace --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function import( $args, $assoc_args ) {
		$file = $args[0] ?? '';

		if ( ! is_readable( $file ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$payload = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $payload ) ) {
			WP_CLI::error( 'That file is not JSON this understands.' );
		}

		$dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$result  = Roles::import_roles( $payload, $assoc_args['mode'] ?? 'merge', $dry_run );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		foreach ( array( 'added', 'changed', 'removed', 'kept' ) as $bucket ) {
			WP_CLI::line( sprintf( '%-8s %s', $bucket, $result[ $bucket ] ? implode( ', ', $result[ $bucket ] ) : '—' ) );
		}

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run. Nothing was changed.' );
			return;
		}

		WP_CLI::success(
			sprintf(
				'%d added, %d changed, %d removed.',
				count( $result['added'] ),
				count( $result['changed'] ),
				count( $result['removed'] )
			)
		);
	}

	/**
	 * Split a comma-separated option into capability names.
	 *
	 * @param string $value Raw option value.
	 * @return string[]
	 */
	private static function split( string $value ): array {
		if ( '' === trim( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_key', explode( ',', $value ) ) ) );
	}

	/**
	 * List the roles with their capability counts.
	 *
	 * ## OPTIONS
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
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ) {
		$stored = Roles::stored_roles();
		$counts = count_users();
		$inert  = Capabilities::inert();
		$rows   = array();

		foreach ( $stored as $slug => $role ) {
			$granted = Roles::granted_caps( $role );

			$rows[] = array(
				'slug'      => $slug,
				'name'      => $role['name'] ?? $slug,
				'granted'   => count( $granted ),
				'effective' => count( array_diff( $granted, $inert ) ),
				'users'     => (int) ( $counts['avail_roles'][ $slug ] ?? 0 ),
			);
		}

		usort( $rows, static fn( $a, $b ) => $b['granted'] <=> $a['granted'] );

		Utils\format_items( Utils\get_flag_value( $assoc_args, 'format', 'table' ), $rows, 'slug,name,granted,effective,users' );
	}
}

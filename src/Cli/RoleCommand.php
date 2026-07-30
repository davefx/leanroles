<?php
/**
 * `wp leanroles role`
 *
 * One primitive: delete a role, moving its users somewhere else. Before it runs
 * it says how many capabilities the role grants and how many users hold it,
 * which is courtesy rather than analysis: what those users will actually lose
 * depends on the capabilities each one already holds through their other roles,
 * and nothing here works that out.
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

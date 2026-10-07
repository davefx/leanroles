<?php
/**
 * `wp leanroles backup`
 *
 * Restore points for the core role option. Stored in a dedicated option with
 * autoload switched off, timestamped and hashed, and verified against that
 * hash before anything is put back.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Cli;

use LeanRoles\Support\Format;
use LeanRoles\Support\Roles;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

class BackupCommand {

	/**
	 * Take a restore point.
	 *
	 * ## OPTIONS
	 *
	 * [--reason=<text>]
	 * : Label for the restore point.
	 * ---
	 * default: manual
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function create( $args, $assoc_args ) {
		$entry = Roles::create_backup( (string) Utils\get_flag_value( $assoc_args, 'reason', 'manual' ) );

		if ( 0 === $entry['bytes'] ) {
			WP_CLI::warning( 'The role option does not exist on this site; the restore point is empty.' );
		}

		WP_CLI::success( sprintf( 'Restore point %s taken (%s).', $entry['id'], Format::bytes( $entry['bytes'] ) ) );
	}

	/**
	 * List the restore points.
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
		$rows = array();

		foreach ( Roles::backups() as $entry ) {
			$rows[] = array(
				'id'      => $entry['id'],
				'taken'   => gmdate( 'Y-m-d H:i:s', (int) $entry['timestamp'] ) . ' UTC',
				'reason'  => $entry['reason'],
				'bytes'   => (int) $entry['bytes'],
				'sha256'  => substr( (string) $entry['sha256'], 0, 12 ),
				'blog_id' => (int) $entry['blog_id'],
			);
		}

		if ( ! $rows ) {
			WP_CLI::log( 'No restore points.' );
			return;
		}

		Utils\format_items( Utils\get_flag_value( $assoc_args, 'format', 'table' ), array_reverse( $rows ), 'id,taken,reason,bytes,sha256,blog_id' );
	}

	/**
	 * Put a restore point back.
	 *
	 * The stored value is re-hashed and compared before it is written. A
	 * restore point that fails the check is not applied.
	 *
	 * ## OPTIONS
	 *
	 * [--to=<id>]
	 * : Restore point id. Defaults to the most recent.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function restore( $args, $assoc_args ) {
		$id = (string) Utils\get_flag_value( $assoc_args, 'to', '' );

		WP_CLI::confirm( 'Overwrite the current role option with this restore point?', $assoc_args );

		$result = Roles::restore_backup( $id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Role option restored.' );
	}
}

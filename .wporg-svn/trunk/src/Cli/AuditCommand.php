<?php
/**
 * `wp leanroles audit`
 *
 * Everything the admin screen reports is available here too, in a shape a shell
 * loop can consume. Somebody sorting this out across a portfolio of sites will
 * write a loop, not click forty times.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Cli;

use LeanRoles\Audit\Auditor;
use LeanRoles\Audit\StructureProbe;
use LeanRoles\Support\Format;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

class AuditCommand {

	/**
	 * Measure what this site's role configuration costs.
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
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Restrict the summary to these fields.
	 *
	 * [--no-benchmark]
	 * : Skip the unserialize and memory measurements.
	 *
	 * [--no-user-counts]
	 * : Skip count_users(), which is the slow part on a large site.
	 *
	 * [--roles]
	 * : List every role with its capability counts instead of the summary.
	 *
	 * [--findings]
	 * : Print the findings in full, with their caveats.
	 *
	 * [--recount]
	 * : Recount users, ignoring the twelve-hour cache.
	 *
	 * [--requests-per-sec=<n>]
	 * : Request rate for the bandwidth projection.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--server-ram-mb=<n>]
	 * : RAM available to the PHP pool, for the capacity estimate.
	 * ---
	 * default: 4096
	 * ---
	 *
	 * [--worker-rss-mb=<n>]
	 * : Average resident size of one PHP worker.
	 * ---
	 * default: 60
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # The summary.
	 *     $ wp leanroles audit
	 *
	 *     # Every site in a portfolio, aggregated however you like.
	 *     $ for s in $(cat sites.txt); do wp --url=$s leanroles audit --format=json; done
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function __invoke( $args, $assoc_args ) {
		if ( Utils\get_flag_value( $assoc_args, 'recount', false ) ) {
			$counts = StructureProbe::user_counts( true );
			WP_CLI::log( sprintf( 'Recounted %s users.', number_format_i18n( (int) $counts['total_users'] ) ) );
		}

		$report = Auditor::run(
			array(
				'benchmark'        => ! Utils\get_flag_value( $assoc_args, 'no-benchmark', false ),
				'user_counts'      => ! Utils\get_flag_value( $assoc_args, 'no-user-counts', false ),
				'requests_per_sec' => (int) Utils\get_flag_value( $assoc_args, 'requests-per-sec', 20 ),
				'server_ram_mb'    => (int) Utils\get_flag_value( $assoc_args, 'server-ram-mb', 4096 ),
				'worker_rss_mb'    => (int) Utils\get_flag_value( $assoc_args, 'worker-rss-mb', 60 ),
			)
		);

		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );

		/*
		 * A view flag wins over the format. `--roles --format=json` asks for the
		 * roles as json, not for the whole report; deciding on the format first
		 * would quietly ignore the flag the caller actually typed.
		 */
		if ( Utils\get_flag_value( $assoc_args, 'roles', false ) ) {
			$this->print_roles( $report, $assoc_args );
			return;
		}

		if ( Utils\get_flag_value( $assoc_args, 'findings', false ) ) {
			if ( 'json' === $format || 'yaml' === $format ) {
				WP_CLI::print_value( $report['findings'], array( 'format' => $format ) );
				return;
			}

			$this->print_findings( $report );
			return;
		}

		if ( 'json' === $format || 'yaml' === $format ) {
			// The whole report, for aggregating across many sites.
			WP_CLI::print_value( $report, array( 'format' => $format ) );
			return;
		}

		$this->print_summary( $report, $assoc_args );
	}

	/**
	 * The headline numbers.
	 *
	 * @param array $report     Report.
	 * @param array $assoc_args Options.
	 */
	private function print_summary( array $report, array $assoc_args ): void {
		$size      = $report['size'];
		$structure = $report['structure'];

		$rows = array(
			$this->row( 'Role option', $size['option_name'] ),
			$this->row( 'Stored size', Format::bytes( $size['role_bytes'] ) ),
			$this->row( 'Autoloaded total', Format::bytes( $size['autoload_bytes'] ) . ' across ' . number_format_i18n( $size['autoload_count'] ) . ' options' ),
			$this->row( 'Share of autoload', null === $size['role_share'] ? '—' : Format::percent( (float) $size['role_share'], 1.0, 1 ) ),
			$this->row( 'Roles', number_format_i18n( $structure['role_count'] ) ),
			$this->row( 'Capability entries', number_format_i18n( $structure['assignments'] ) ),
			$this->row( 'Distinct capabilities', number_format_i18n( $structure['distinct_caps'] ) ),
			$this->row( 'Deprecated level_N entries', number_format_i18n( $structure['level_assignments'] ) ),
			$this->row( 'Roles granting nothing', number_format_i18n( count( $structure['inert_roles'] ) ) ),
			$this->row( 'Roles with no users', $structure['user_counts'] ? number_format_i18n( count( $structure['ghost_roles'] ) ) : 'not measured' ),
			$this->row( 'Identical capability sets', $structure['pairwise_skipped'] ? 'skipped' : number_format_i18n( count( $structure['clone_groups'] ) ) . ' groups' ),
		);

		if ( ! $structure['pairwise_skipped'] && $structure['inheritance_saving'] ) {
			$rows[] = $this->row(
				'Inheritance could remove',
				sprintf(
					'%s of %s entries (conservative)',
					number_format_i18n( $structure['inheritance_saving']['entries'] ),
					number_format_i18n( $structure['inheritance_saving']['of'] )
				)
			);
		}

		if ( ! empty( $report['benchmark']['available'] ) ) {
			$bench = $report['benchmark'];

			$rows[] = $this->row( 'Unserialize, per request', Format::duration( $bench['unserialize']['per_call'] ) );
			$rows[] = $this->row( 'Resident memory', Format::bytes( (int) $bench['memory']['bytes'] ) );
			$rows[] = $this->row(
				'Memory to disk ratio',
				null === $bench['memory']['ratio'] ? '—' : number_format_i18n( $bench['memory']['ratio'], 1 ) . '×'
			);
			$rows[] = $this->row( 'Hashtable entries', number_format_i18n( (int) $bench['memory']['elements'] ) );
		}

		if ( $report['capacity'] ) {
			$cap = $report['capacity'];

			$rows[] = $this->row(
				'Extra workers if removed',
				sprintf(
					'%s (at %s MB RAM, %s MB per worker)',
					number_format_i18n( $cap['extra_workers'] ),
					number_format_i18n( $cap['server_ram_mb'] ),
					number_format_i18n( $cap['worker_rss_mb'] )
				)
			);
		}

		$rows[] = $this->row(
			'Object cache',
			$report['stack']['dropin_present']
				? ( $report['stack']['backends'] ? implode( ', ', $report['stack']['backends'] ) : 'drop-in present, backend not identified' )
				: 'no drop-in'
		);

		if ( $report['stack']['mitigations'] ) {
			$rows[] = $this->row( 'Drop-in appears to use', implode( ', ', $report['stack']['mitigations'] ) );
		}

		$fields = Utils\get_flag_value( $assoc_args, 'fields', 'metric,value' );

		Utils\format_items( Utils\get_flag_value( $assoc_args, 'format', 'table' ), $rows, $fields );

		WP_CLI::line( '' );
		WP_CLI::line( sprintf( '%d finding(s). Run with --findings for the detail.', count( $report['findings'] ) ) );
	}

	/**
	 * One row per role.
	 *
	 * @param array $report     Report.
	 * @param array $assoc_args Options.
	 */
	private function print_roles( array $report, array $assoc_args ): void {
		$counts = $report['structure']['user_counts'];
		$avail  = isset( $counts['avail_roles'] ) ? $counts['avail_roles'] : array();
		$inert  = array_flip( $report['structure']['inert_roles'] );
		$rows   = array();

		foreach ( $report['structure']['roles'] as $slug => $role ) {
			$rows[] = array(
				'slug'      => $slug,
				'name'      => $role['name'],
				'granted'   => $role['granted'],
				'denied'    => $role['denied'],
				'levels'    => $role['levels'],
				'effective' => $role['effective'],
				'users'     => $counts ? (int) ( $avail[ $slug ] ?? 0 ) : '—',
				'inert'     => isset( $inert[ $slug ] ) ? 'yes' : '',
			);
		}

		usort( $rows, static fn( $a, $b ) => $b['granted'] <=> $a['granted'] );

		Utils\format_items(
			Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			$rows,
			Utils\get_flag_value( $assoc_args, 'fields', 'slug,name,granted,denied,levels,effective,users,inert' )
		);
	}

	/**
	 * The findings, caveats and all.
	 *
	 * @param array $report Report.
	 */
	private function print_findings( array $report ): void {
		if ( ! $report['findings'] ) {
			WP_CLI::success( 'Nothing worth flagging.' );
			return;
		}

		foreach ( $report['findings'] as $finding ) {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%9' . $finding['title'] . '%n' ) );
			WP_CLI::line( wordwrap( $finding['detail'], 78 ) );

			if ( ! empty( $finding['items'] ) ) {
				$items = $finding['items'];
				$shown = array_slice( $items, 0, 25 );

				WP_CLI::line( '  ' . implode( ', ', $shown ) );

				if ( count( $items ) > count( $shown ) ) {
					WP_CLI::line( sprintf( '  … and %d more.', count( $items ) - count( $shown ) ) );
				}
			}
		}

		WP_CLI::line( '' );
	}

	/**
	 * A metric/value row.
	 *
	 * @param string $metric Label.
	 * @param mixed  $value  Value.
	 */
	private function row( string $metric, $value ): array {
		return array(
			'metric' => $metric,
			'value'  => (string) $value,
		);
	}
}

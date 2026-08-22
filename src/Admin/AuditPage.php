<?php
/**
 * The audit screen.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Admin;

use LeanRoles\Audit\Auditor;
use LeanRoles\Support\Format;

defined( 'ABSPATH' ) || exit;

final class AuditPage {

	/**
	 * Render.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to view the role audit.', 'leanroles' ) );
		}

		// Read-only screen: the inputs come in on the query string, and nothing
		// they influence is written anywhere.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$rps       = isset( $_GET['rps'] ) ? max( 0, (int) $_GET['rps'] ) : 20;
		$ram       = isset( $_GET['ram'] ) ? max( 1, (int) $_GET['ram'] ) : 4096;
		$worker    = isset( $_GET['worker'] ) ? max( 1, (int) $_GET['worker'] ) : 60;
		$benchmark = ! isset( $_GET['benchmark'] ) || '0' !== $_GET['benchmark'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$report = Auditor::run(
			array(
				'benchmark'        => $benchmark,
				'requests_per_sec' => $rps,
				'server_ram_mb'    => $ram,
				'worker_rss_mb'    => $worker,
			)
		);

		echo '<div class="wrap leanroles-audit">';
		echo '<h1>' . esc_html__( 'Role audit', 'leanroles' ) . '</h1>';

		echo '<p class="leanroles-lede">';
		esc_html_e( 'Everything on this page is read. Nothing here writes to your role configuration, your users, or anything else — you can leave this installed on a client site without asking anyone first.', 'leanroles' );
		echo '</p>';

		self::render_headline( $report );
		self::render_findings( $report );
		self::render_measurements( $report, $benchmark );
		self::render_controls( $rps, $ram, $worker, $benchmark );
		self::render_stack( $report );
		self::render_roles( $report );

		echo '</div>';
	}

	/**
	 * The three numbers that matter.
	 *
	 * @param array $report Report.
	 */
	private static function render_headline( array $report ): void {
		$size      = $report['size'];
		$structure = $report['structure'];

		$cards = array(
			array(
				'label' => __( 'Role option', 'leanroles' ),
				'value' => Format::bytes( $size['role_bytes'] ),
				'note'  => null === $size['role_share']
					? __( 'measured with LENGTH() in the database', 'leanroles' )
					: sprintf(
						/* translators: %s: percentage. */
						__( '%s of everything autoloaded', 'leanroles' ),
						Format::percent( (float) $size['role_share'], 1.0 )
					),
			),
			array(
				'label' => __( 'Roles', 'leanroles' ),
				'value' => number_format_i18n( $structure['role_count'] ),
				'note'  => sprintf(
					/* translators: 1: capability entries, 2: distinct capabilities. */
					__( '%1$s capability entries, %2$s distinct', 'leanroles' ),
					number_format_i18n( $structure['assignments'] ),
					number_format_i18n( $structure['distinct_caps'] )
				),
			),
			array(
				'label' => __( 'Autoloaded total', 'leanroles' ),
				'value' => Format::bytes( $size['autoload_bytes'] ),
				'note'  => sprintf(
					/* translators: %s: number of options. */
					__( 'across %s options, on every request', 'leanroles' ),
					number_format_i18n( $size['autoload_count'] )
				),
			),
		);

		echo '<div class="leanroles-cards">';

		foreach ( $cards as $card ) {
			echo '<div class="leanroles-card">';
			echo '<span class="leanroles-card__label">' . esc_html( $card['label'] ) . '</span>';
			echo '<span class="leanroles-card__value">' . esc_html( $card['value'] ) . '</span>';
			echo '<span class="leanroles-card__note">' . esc_html( $card['note'] ) . '</span>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Findings.
	 *
	 * @param array $report Report.
	 */
	private static function render_findings( array $report ): void {
		echo '<h2>' . esc_html__( 'Findings', 'leanroles' ) . '</h2>';

		if ( ! $report['findings'] ) {
			echo '<p>' . esc_html__( 'Nothing here is worth your attention. That is a genuine result, not a placeholder.', 'leanroles' ) . '</p>';
			return;
		}

		foreach ( $report['findings'] as $finding ) {
			printf(
				'<div class="leanroles-finding leanroles-finding--%s">',
				esc_attr( $finding['severity'] )
			);

			echo '<h3>' . esc_html( $finding['title'] ) . '</h3>';
			echo '<p>' . esc_html( $finding['detail'] ) . '</p>';

			if ( ! empty( $finding['items'] ) ) {
				$items = array_map( 'strval', $finding['items'] );
				$shown = array_slice( $items, 0, 40 );

				echo '<p class="leanroles-items">';

				foreach ( $shown as $item ) {
					echo '<code>' . esc_html( $item ) . '</code> ';
				}

				echo '</p>';

				if ( count( $items ) > count( $shown ) ) {
					printf(
						'<p class="description">%s</p>',
						esc_html(
							sprintf(
								/* translators: %s: number of remaining items. */
								__( '… and %s more.', 'leanroles' ),
								number_format_i18n( count( $items ) - count( $shown ) )
							)
						)
					);
				}
			}

			echo '</div>';
		}
	}

	/**
	 * Measured cost.
	 *
	 * @param array $report    Report.
	 * @param bool  $benchmark Whether the benchmark ran.
	 */
	private static function render_measurements( array $report, bool $benchmark ): void {
		echo '<h2>' . esc_html__( 'Measured cost', 'leanroles' ) . '</h2>';

		if ( ! $benchmark ) {
			echo '<p>' . esc_html__( 'The benchmark is switched off. Switch it back on below to time an unserialize() of your own option on this machine.', 'leanroles' ) . '</p>';
			return;
		}

		if ( empty( $report['benchmark']['available'] ) ) {
			echo '<p>' . esc_html( $report['benchmark']['reason'] ?? __( 'Nothing to measure.', 'leanroles' ) ) . '</p>';
			return;
		}

		$bench = $report['benchmark'];

		$rows = array(
			array(
				__( 'Unserialization, per request', 'leanroles' ),
				Format::duration( $bench['unserialize']['per_call'] ),
				sprintf(
					/* translators: %s: iteration count. */
					__( 'mean of %s timed calls after a warm-up pass', 'leanroles' ),
					number_format_i18n( $bench['unserialize']['iterations'] )
				),
			),
			array(
				__( 'Resident memory, per worker', 'leanroles' ),
				Format::bytes( (int) $bench['memory']['bytes'] ),
				__( 'memory_get_usage() either side of the call, with the result kept alive', 'leanroles' ),
			),
			array(
				__( 'In memory versus on disk', 'leanroles' ),
				null === $bench['memory']['ratio'] ? '—' : number_format_i18n( $bench['memory']['ratio'], 1 ) . '×',
				__( 'every hashtable entry costs far more resident than its serialized bytes', 'leanroles' ),
			),
			array(
				__( 'Hashtable entries', 'leanroles' ),
				number_format_i18n( (int) $bench['memory']['elements'] ),
				__( 'unserialize() scales with this, not with the byte count', 'leanroles' ),
			),
		);

		if ( $report['capacity'] ) {
			$cap = $report['capacity'];

			$rows[] = array(
				__( 'Concurrent workers', 'leanroles' ),
				sprintf( '%s → %s', number_format_i18n( $cap['workers_now'] ), number_format_i18n( $cap['workers_leaner'] ) ),
				sprintf(
					/* translators: 1: server RAM in MB, 2: worker size in MB. */
					__( 'estimate at %1$s MB of pool RAM and %2$s MB per worker — adjust both below', 'leanroles' ),
					number_format_i18n( $cap['server_ram_mb'] ),
					number_format_i18n( $cap['worker_rss_mb'] )
				),
			);
		}

		$band = $report['bandwidth'];

		$rows[] = array(
			__( 'Object cache bandwidth', 'leanroles' ),
			sprintf( '%s/s', Format::bytes( (int) $band['bytes_per_sec'] ) ),
			sprintf(
				/* translators: 1: requests per second, 2: gigabytes per day. */
				__( 'alloptions lives under one key, so the whole blob crosses the wire per request — %1$s req/s, %2$s GB/day', 'leanroles' ),
				number_format_i18n( $band['requests_per_sec'] ),
				number_format_i18n( $band['gb_per_day'], 1 )
			),
		);

		echo '<table class="widefat striped leanroles-table"><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<th scope="row">' . esc_html( $row[0] ) . '</th>';
			echo '<td class="leanroles-figure">' . esc_html( $row[1] ) . '</td>';
			echo '<td class="description">' . esc_html( $row[2] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p class="description">';
		esc_html_e( 'The unserialization and memory figures are measurements taken on this machine, on this PHP build, against your own option. The worker and bandwidth figures are arithmetic on top of them and are only as good as the two inputs you give them.', 'leanroles' );
		echo '</p>';
	}

	/**
	 * The adjustable inputs.
	 *
	 * @param int  $rps       Requests per second.
	 * @param int  $ram       Server RAM in MB.
	 * @param int  $worker    Worker RSS in MB.
	 * @param bool $benchmark Whether the benchmark is on.
	 */
	private static function render_controls( int $rps, int $ram, int $worker, bool $benchmark ): void {
		echo '<form method="get" class="leanroles-controls">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( Menu::AUDIT_SLUG ) );

		printf(
			'<label>%s <input type="number" name="rps" min="0" step="1" value="%s" /></label>',
			esc_html__( 'Requests per second', 'leanroles' ),
			esc_attr( (string) $rps )
		);

		printf(
			'<label>%s <input type="number" name="ram" min="1" step="1" value="%s" /></label>',
			esc_html__( 'Pool RAM (MB)', 'leanroles' ),
			esc_attr( (string) $ram )
		);

		printf(
			'<label>%s <input type="number" name="worker" min="1" step="1" value="%s" /></label>',
			esc_html__( 'Per worker (MB)', 'leanroles' ),
			esc_attr( (string) $worker )
		);

		printf(
			'<label><input type="checkbox" name="benchmark" value="1" %s /> %s</label>',
			checked( $benchmark, true, false ),
			esc_html__( 'Run the benchmark', 'leanroles' )
		);

		submit_button( __( 'Recalculate', 'leanroles' ), 'secondary', '', false );

		echo '</form>';
	}

	/**
	 * What is underneath.
	 *
	 * @param array $report Report.
	 */
	private static function render_stack( array $report ): void {
		$stack = $report['stack'];

		echo '<h2>' . esc_html__( 'Stack', 'leanroles' ) . '</h2>';
		echo '<table class="widefat striped leanroles-table"><tbody>';

		printf(
			'<tr><th scope="row">%s</th><td colspan="2">%s</td></tr>',
			esc_html__( 'Persistent object cache', 'leanroles' ),
			esc_html(
				$stack['dropin_present']
					? ( $stack['backends'] ? implode( ', ', $stack['backends'] ) : __( 'drop-in present, backend not identified', 'leanroles' ) )
					: __( 'none', 'leanroles' )
			)
		);

		if ( $stack['mitigations'] ) {
			printf(
				'<tr><th scope="row">%s</th><td colspan="2">%s</td></tr>',
				esc_html__( 'Drop-in appears to use', 'leanroles' ),
				esc_html( implode( ', ', $stack['mitigations'] ) )
			);
		}

		echo '</tbody></table>';

		foreach ( $stack['notes'] as $note ) {
			echo '<p class="description">' . esc_html( $note ) . '</p>';
		}
	}

	/**
	 * Every role, heaviest first.
	 *
	 * @param array $report Report.
	 */
	private static function render_roles( array $report ): void {
		$structure = $report['structure'];
		$counts    = $structure['user_counts'];
		$avail     = isset( $counts['avail_roles'] ) ? $counts['avail_roles'] : array();
		$inert     = array_flip( $structure['inert_roles'] );
		$rows      = $structure['roles'];

		uasort( $rows, static fn( $a, $b ) => $b['granted'] <=> $a['granted'] );

		echo '<h2>' . esc_html__( 'Roles', 'leanroles' ) . '</h2>';
		echo '<table class="widefat striped leanroles-roles"><thead><tr>';
		echo '<th>' . esc_html__( 'Role', 'leanroles' ) . '</th>';
		echo '<th>' . esc_html__( 'Granted', 'leanroles' ) . '</th>';
		echo '<th>' . esc_html__( 'Denied', 'leanroles' ) . '</th>';
		echo '<th>' . esc_html__( 'level_N', 'leanroles' ) . '</th>';
		echo '<th>' . esc_html__( 'Effective', 'leanroles' ) . '</th>';
		echo '<th>' . esc_html__( 'Users', 'leanroles' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $slug => $role ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $role['name'] ) . '</strong> <code>' . esc_html( $slug ) . '</code>';

			if ( isset( $inert[ $slug ] ) ) {
				echo ' <span class="leanroles-pill">' . esc_html__( 'grants nothing', 'leanroles' ) . '</span>';
			}

			echo '</td>';
			echo '<td>' . esc_html( number_format_i18n( $role['granted'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $role['denied'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $role['levels'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $role['effective'] ) ) . '</td>';
			echo '<td>' . esc_html( $counts ? number_format_i18n( (int) ( $avail[ $slug ] ?? 0 ) ) : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $counts && isset( $counts['counted_at'] ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: human-readable time difference. */
						__( 'User counts come from count_users() and are cached for twelve hours. Last counted %s ago.', 'leanroles' ),
						human_time_diff( (int) $counts['counted_at'] )
					)
				)
			);
		}

		if ( $structure['subset_pairs'] ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: number of pairs. */
						__( '%s pairs of roles stand in a subset relationship: one role\'s capabilities are entirely contained in another\'s. Those are the candidates for inheritance.', 'leanroles' ),
						number_format_i18n( count( $structure['subset_pairs'] ) )
					)
				)
			);
		}

		if ( $structure['pairwise_skipped'] ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: number of roles. */
						__( 'Clone and subset detection compares every role against every other, which is quadratic. With %s roles it was skipped rather than run inside a page load; the WP-CLI command will do it.', 'leanroles' ),
						number_format_i18n( $structure['role_count'] )
					)
				)
			);
		}
	}
}

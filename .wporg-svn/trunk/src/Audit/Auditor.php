<?php
/**
 * The auditor. Read-only, always.
 *
 * Nothing in this namespace writes to the role option, to usermeta, or to
 * anything else a site owner would mind. That is not a stylistic choice: it is
 * the reason the auditor can be installed on someone else's production site
 * without a conversation first.
 *
 * The only writes anywhere near it are two caches — the twelve-hour user-count
 * transient and, when the benchmark runs, nothing at all.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Audit;

defined( 'ABSPATH' ) || exit;

final class Auditor {

	/**
	 * Run the audit.
	 *
	 * @param array $args Report options: benchmark, user_counts, server_ram_mb,
	 *                    worker_rss_mb, requests_per_sec, dropin_path.
	 * @return array
	 */
	public static function run( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'benchmark'        => true,
				'user_counts'      => true,
				'server_ram_mb'    => 4096,
				'worker_rss_mb'    => 60,
				'requests_per_sec' => 20,
				'dropin_path'      => null,
			)
		);

		$size      = SizeProbe::run();
		$structure = StructureProbe::run( (bool) $args['user_counts'] );
		$stack     = StackProbe::run( $args['dropin_path'] );

		$report = array(
			'generated_at' => time(),
			'site'         => array(
				'blog_id'    => get_current_blog_id(),
				'multisite'  => is_multisite(),
				'wp_version' => get_bloginfo( 'version' ),
				'php'        => PHP_VERSION,
			),
			'size'         => $size,
			'structure'    => $structure,
			'stack'        => $stack,
			'benchmark'    => null,
			'capacity'     => null,
			'bandwidth'    => Benchmark::bandwidth( (int) $size['autoload_bytes'], (int) $args['requests_per_sec'] ),
			'findings'     => array(),
		);

		if ( $args['benchmark'] ) {
			$report['benchmark'] = Benchmark::run();

			if ( ! empty( $report['benchmark']['available'] ) ) {
				$report['capacity'] = Benchmark::capacity(
					(int) $report['benchmark']['memory']['bytes'],
					(int) $args['server_ram_mb'],
					(int) $args['worker_rss_mb']
				);
			}
		}

		$report['findings'] = self::findings( $report );

		/**
		 * Filter the finished audit report.
		 */
		return (array) apply_filters( 'leanroles_audit_report', $report );
	}

	/**
	 * Turn the raw numbers into the handful of statements worth acting on.
	 *
	 * Severity is deliberately conservative. A finding that overstates its case
	 * once costs more credibility than ten findings that were merely dull.
	 *
	 * @param array $report Report so far.
	 * @return array[]
	 */
	private static function findings( array $report ): array {
		$findings  = array();
		$structure = $report['structure'];
		$size      = $report['size'];

		if ( null === $size['role_bytes'] ) {
			$findings[] = array(
				'id'       => 'no_role_option',
				'severity' => 'info',
				'title'    => __( 'No role option was found for this site.', 'leanroles' ),
				'detail'   => __( 'Either the site has never stored one, or it is being supplied by code on the wp_roles_init hook. There is nothing here to audit.', 'leanroles' ),
			);

			return $findings;
		}

		if ( $structure['level_assignments'] > 0 ) {
			$findings[] = array(
				'id'       => 'levels',
				'severity' => $structure['level_assignments'] > 100 ? 'warning' : 'info',
				'title'    => sprintf(
					/* translators: %s: number of capability entries. */
					__( '%s capability entries are deprecated user levels.', 'leanroles' ),
					number_format_i18n( $structure['level_assignments'] )
				),
				'detail'   => __( 'level_0 through level_10 were deprecated in WordPress 3.0. Core keeps reading them for backwards compatibility, so removing them is not free of risk, but on a site whose roles were cloned from Editor they are pure accounting weight — they are copied into every clone.', 'leanroles' ),
			);
		}

		if ( $structure['inert_roles'] ) {
			$findings[] = array(
				'id'       => 'inert_roles',
				'severity' => 'warning',
				'title'    => sprintf(
					/* translators: %s: number of roles. */
					_n( '%s role grants no effective permission.', '%s roles grant no effective permission.', count( $structure['inert_roles'] ), 'leanroles' ),
					number_format_i18n( count( $structure['inert_roles'] ) )
				),
				'detail'   => __( 'Their capabilities are contained in { read, level_0 … level_10 }, which every subscriber already has. They are labels wearing a role\'s clothes, and a user tag does the same job without the weight. Nothing here converts them for you; the three primitives that are here — create a tag, assign it in bulk, delete the role with reassignment — compose into the same result by hand.', 'leanroles' ),
				'items'    => $structure['inert_roles'],
			);
		}

		if ( $structure['ghost_roles'] ) {
			$findings[] = array(
				'id'       => 'ghost_roles',
				'severity' => 'info',
				'title'    => sprintf(
					/* translators: %s: number of roles. */
					_n( '%s role has no users.', '%s roles have no users.', count( $structure['ghost_roles'] ), 'leanroles' ),
					number_format_i18n( count( $structure['ghost_roles'] ) )
				),
				'detail'   => __( 'A role with no users still costs its full weight on every request. Some are left behind by deactivated plugins; some are waiting for users who will arrive next season. The counts come from count_users() and are cached for twelve hours.', 'leanroles' ),
				'items'    => $structure['ghost_roles'],
			);
		}

		if ( $structure['clone_groups'] ) {
			$duplicated = 0;

			foreach ( $structure['clone_groups'] as $group ) {
				$duplicated += ( count( $group['roles'] ) - 1 ) * $group['size'];
			}

			$findings[] = array(
				'id'       => 'clones',
				'severity' => 'warning',
				'title'    => sprintf(
					/* translators: 1: number of groups, 2: number of duplicated entries. */
					__( '%1$s groups of roles have identical capabilities, duplicating %2$s entries.', 'leanroles' ),
					number_format_i18n( count( $structure['clone_groups'] ) ),
					number_format_i18n( $duplicated )
				),
				'detail'   => __( 'Core has no inheritance, so every one of these carries its own full copy. They may all be legitimate — a role is a name as well as a permission set — but only one copy of the capabilities needs to exist.', 'leanroles' ),
			);
		}

		if ( ! empty( $structure['inheritance_saving']['entries'] ) ) {
			$saving = $structure['inheritance_saving'];

			$findings[] = array(
				'id'       => 'inheritance',
				'severity' => 'info',
				'title'    => sprintf(
					/* translators: 1: entries that could be removed, 2: total entries. */
					__( 'Inheritance could remove at least %1$s of %2$s capability entries.', 'leanroles' ),
					number_format_i18n( $saving['entries'] ),
					number_format_i18n( $saving['of'] )
				),
				'detail'   => __( 'A conservative greedy estimate: each role is assumed to inherit from the largest role whose capabilities it fully contains. A better arrangement almost certainly exists. This is a lower bound and is stated as one.', 'leanroles' ),
			);
		}

		if ( $structure['unrecognised'] ) {
			$findings[] = array(
				'id'       => 'unrecognised',
				'severity' => 'info',
				'title'    => sprintf(
					/* translators: %s: number of capabilities. */
					_n( '%s capability could not be traced to core, a post type or a taxonomy.', '%s capabilities could not be traced to core, a post type or a taxonomy.', count( $structure['unrecognised'] ), 'leanroles' ),
					number_format_i18n( count( $structure['unrecognised'] ) )
				),
				'detail'   => __( 'Unrecognised does not mean orphaned. Custom code and premium plugins check capabilities that no scanner can see from here, and anything registered conditionally will be missing from the comparison. This is a list of things to look into, and nothing more than that.', 'leanroles' ),
				'items'    => $structure['unrecognised'],
			);
		}

		if ( $structure['pairwise_skipped'] ) {
			$findings[] = array(
				'id'       => 'pairwise_skipped',
				'severity' => 'info',
				'title'    => __( 'Clone and subset analysis was skipped.', 'leanroles' ),
				'detail'   => sprintf(
					/* translators: %s: number of roles. */
					__( 'Comparing every role against every other is quadratic, and this site has %s of them. Raise the leanroles_pairwise_limit filter to run it anyway, ideally from WP-CLI rather than inside a page load.', 'leanroles' ),
					number_format_i18n( $structure['role_count'] )
				),
			);
		}

		return $findings;
	}
}

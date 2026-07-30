<?php
/**
 * What the role configuration is made of.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Audit;

use LeanRoles\Support\Capabilities;
use LeanRoles\Support\Roles;

defined( 'ABSPATH' ) || exit;

final class StructureProbe {

	/** Cached count_users() result. Expensive on large sites. */
	public const USER_COUNT_TRANSIENT = 'leanroles_user_counts';

	/** Above this many roles the pairwise comparisons stop being free. */
	public const PAIRWISE_LIMIT = 200;

	/**
	 * Run the probe.
	 *
	 * @param bool $with_user_counts Whether to run count_users(). Off in the CLI
	 *                               unless asked for, because on a large site it
	 *                               is the slowest thing here by an order of
	 *                               magnitude.
	 * @return array
	 */
	public static function run( bool $with_user_counts = true ): array {
		$roles = Roles::stored_roles();

		$granted     = array();
		$declared    = array();
		$assignments = 0;
		$levels      = 0;

		foreach ( $roles as $slug => $role ) {
			$caps               = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
			$assignments       += count( $caps );
			$granted[ $slug ]   = Roles::granted_caps( $role );
			$declared[ $slug ]  = array_keys( $caps );

			foreach ( array_keys( $caps ) as $cap ) {
				if ( Capabilities::is_level( (string) $cap ) ) {
					++$levels;
				}
			}
		}

		$distinct = array();

		foreach ( $declared as $caps ) {
			foreach ( $caps as $cap ) {
				$distinct[ $cap ] = true;
			}
		}

		$report = array(
			'role_count'         => count( $roles ),
			'assignments'        => $assignments,
			'distinct_caps'      => count( $distinct ),
			'level_assignments'  => $levels,
			'roles'              => self::role_summaries( $roles, $granted ),
			'inert_roles'        => self::inert_roles( $granted ),
			'unrecognised'       => self::unrecognised( $distinct ),
			'pairwise_skipped'   => false,
			'clone_groups'       => array(),
			'subset_pairs'       => array(),
			'inheritance_saving' => null,
			'ghost_roles'        => array(),
			'user_counts'        => null,
		);

		$limit = (int) apply_filters( 'leanroles_pairwise_limit', self::PAIRWISE_LIMIT );

		if ( count( $roles ) > $limit ) {
			$report['pairwise_skipped'] = true;
		} else {
			$report['clone_groups']       = self::clone_groups( $granted );
			$report['subset_pairs']       = self::subset_pairs( $granted );
			$report['inheritance_saving'] = self::inheritance_saving( $granted, $report['clone_groups'] );
		}

		if ( $with_user_counts ) {
			$counts                 = self::user_counts();
			$report['user_counts']  = $counts;
			$report['ghost_roles']  = self::ghost_roles( $roles, $counts );
		}

		return $report;
	}

	/**
	 * Per-role summary rows.
	 *
	 * @param array $roles   Stored roles.
	 * @param array $granted Slug => granted capabilities.
	 * @return array[]
	 */
	private static function role_summaries( array $roles, array $granted ): array {
		$rows  = array();
		$inert = array_flip( Capabilities::inert() );

		foreach ( $roles as $slug => $role ) {
			$caps   = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
			$levels = 0;

			foreach ( array_keys( $caps ) as $cap ) {
				if ( Capabilities::is_level( (string) $cap ) ) {
					++$levels;
				}
			}

			$real = array_diff( $granted[ $slug ], array_keys( $inert ) );

			$rows[ $slug ] = array(
				'slug'      => $slug,
				'name'      => isset( $role['name'] ) ? (string) $role['name'] : $slug,
				'declared'  => count( $caps ),
				'granted'   => count( $granted[ $slug ] ),
				'denied'    => count( $caps ) - count( $granted[ $slug ] ),
				'levels'    => $levels,
				'effective' => count( $real ),
			);
		}

		return $rows;
	}

	/**
	 * Roles whose granted capabilities are contained in { read, level_0..10 }.
	 *
	 * These are the ones that were only ever a label. Diagnosis stops here:
	 * flagging them is free, converting them is not.
	 *
	 * @param array $granted Slug => granted capabilities.
	 * @return string[]
	 */
	private static function inert_roles( array $granted ): array {
		$inert  = Capabilities::inert();
		$result = array();

		foreach ( $granted as $slug => $caps ) {
			if ( ! $caps ) {
				$result[] = $slug;
				continue;
			}

			if ( ! array_diff( $caps, $inert ) ) {
				$result[] = $slug;
			}
		}

		return $result;
	}

	/**
	 * Roles with byte-identical granted capability sets.
	 *
	 * @param array $granted Slug => granted capabilities.
	 * @return array[] Groups of two or more slugs, each with the set size.
	 */
	private static function clone_groups( array $granted ): array {
		$buckets = array();

		foreach ( $granted as $slug => $caps ) {
			$buckets[ md5( implode( '|', $caps ) ) ][] = $slug;
		}

		$groups = array();

		foreach ( $buckets as $hash => $slugs ) {
			if ( count( $slugs ) < 2 ) {
				continue;
			}

			$groups[] = array(
				'roles' => $slugs,
				'size'  => count( $granted[ $slugs[0] ] ),
			);
		}

		return $groups;
	}

	/**
	 * Pairs where one role's capabilities are a strict subset of another's.
	 *
	 * @param array $granted Slug => granted capabilities.
	 * @return array[]
	 */
	private static function subset_pairs( array $granted ): array {
		$sets = array();

		foreach ( $granted as $slug => $caps ) {
			$sets[ $slug ] = array_flip( $caps );
		}

		$pairs = array();

		foreach ( $sets as $parent => $parent_caps ) {
			foreach ( $sets as $child => $child_caps ) {
				if ( $parent === $child || count( $child_caps ) >= count( $parent_caps ) || ! $child_caps ) {
					continue;
				}

				if ( ! array_diff_key( $child_caps, $parent_caps ) ) {
					$pairs[] = array(
						'parent' => $parent,
						'child'  => $child,
						'shared' => count( $child_caps ),
						'delta'  => count( $parent_caps ) - count( $child_caps ),
					);
				}
			}
		}

		return $pairs;
	}

	/**
	 * How many capability entries inheritance could remove.
	 *
	 * Greedy: for each role, the largest proper subset available among the
	 * others is assumed to become its parent. This is a lower bound and is
	 * presented as one. A real solver would find more; promising more than the
	 * greedy figure and then delivering less is how a plugin loses an audience.
	 *
	 * @param array $granted Slug => granted capabilities.
	 * @param array $clones  Clone groups.
	 * @return array{entries:int,of:int}
	 */
	private static function inheritance_saving( array $granted, array $clones ): array {
		$total = 0;

		foreach ( $granted as $caps ) {
			$total += count( $caps );
		}

		$sets   = array();
		$cloned = array();

		foreach ( $clones as $group ) {
			// Everything after the first copy in a clone group is pure duplication.
			foreach ( array_slice( $group['roles'], 1 ) as $slug ) {
				$cloned[ $slug ] = true;
			}
		}

		foreach ( $granted as $slug => $caps ) {
			$sets[ $slug ] = array_flip( $caps );
		}

		$saving = 0;

		foreach ( $sets as $slug => $caps ) {
			if ( isset( $cloned[ $slug ] ) ) {
				$saving += count( $caps );
				continue;
			}

			$best = 0;

			foreach ( $sets as $other => $other_caps ) {
				if ( $slug === $other || isset( $cloned[ $other ] ) ) {
					continue;
				}

				$size = count( $other_caps );

				if ( $size >= count( $caps ) || $size <= $best ) {
					continue;
				}

				if ( ! array_diff_key( $other_caps, $caps ) ) {
					$best = $size;
				}
			}

			$saving += $best;
		}

		return array(
			'entries' => $saving,
			'of'      => $total,
		);
	}

	/**
	 * Capabilities nothing on this installation can account for.
	 *
	 * Note carefully what this is not. "Unrecognised" is not "orphaned". A
	 * capability can be perfectly live and still appear here: custom code,
	 * premium plugins and anything registering late all sit outside what a
	 * scanner can see. This list is a place to start looking, and the interface
	 * must never present it as anything more.
	 *
	 * @param array $distinct Capability => true.
	 * @return string[]
	 */
	private static function unrecognised( array $distinct ): array {
		$recognised = Capabilities::recognised();
		$unknown    = array();

		foreach ( array_keys( $distinct ) as $cap ) {
			if ( ! isset( $recognised[ $cap ] ) ) {
				$unknown[] = (string) $cap;
			}
		}

		sort( $unknown );

		return $unknown;
	}

	/**
	 * Roles nobody holds.
	 *
	 * @param array $roles  Stored roles.
	 * @param array $counts count_users() output.
	 * @return string[]
	 */
	private static function ghost_roles( array $roles, array $counts ): array {
		$avail  = isset( $counts['avail_roles'] ) && is_array( $counts['avail_roles'] ) ? $counts['avail_roles'] : array();
		$ghosts = array();

		foreach ( array_keys( $roles ) as $slug ) {
			if ( empty( $avail[ $slug ] ) ) {
				$ghosts[] = (string) $slug;
			}
		}

		return $ghosts;
	}

	/**
	 * Count users by role, cached for twelve hours.
	 *
	 * On a site with hundreds of thousands of users this is a full table scan
	 * of usermeta, so it is never run inline without a cache behind it.
	 *
	 * @param bool $force Force.
	 */
	public static function user_counts( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::USER_COUNT_TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$counts               = count_users();
		$counts['counted_at'] = time();

		set_transient( self::USER_COUNT_TRANSIENT, $counts, 12 * HOUR_IN_SECONDS );

		return $counts;
	}
}

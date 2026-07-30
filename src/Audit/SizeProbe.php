<?php
/**
 * What the role option actually weighs, and what share of autoload it takes.
 *
 * Everything here is measured against the database, not against
 * strlen(serialize(get_option(...))). Re-serializing in PHP produces a
 * different string from the one MySQL is storing and PHP is unserializing, and
 * the difference is exactly the kind of small dishonesty that gets a
 * performance plugin disbelieved.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Audit;

use LeanRoles\Support\Roles;

defined( 'ABSPATH' ) || exit;

final class SizeProbe {

	/**
	 * Autoload values that mean "load this on every request".
	 *
	 * WordPress 6.6 replaced the yes/no pair with a small vocabulary. Older
	 * installs only ever store 'yes'; both are matched so the same query is
	 * correct across the supported range.
	 *
	 * @var string[]
	 */
	private const AUTOLOAD_ON = array( 'yes', 'on', 'auto', 'auto-on' );

	/**
	 * Run the probe.
	 *
	 * @return array
	 */
	public static function run(): array {
		global $wpdb;

		$option_name = Roles::option_name();
		$placeholders = implode( ',', array_fill( 0, count( self::AUTOLOAD_ON ), '%s' ) );

		$role_bytes = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		/*
		 * $placeholders is a run of %s built from the length of a private
		 * constant, so nothing external reaches the query string; the values
		 * themselves still go through prepare(). The sniff cannot see that far.
		 */
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$autoload = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS count, COALESCE(SUM(LENGTH(option_value)),0) AS bytes
				 FROM {$wpdb->options}
				 WHERE autoload IN ($placeholders)",
				self::AUTOLOAD_ON
			),
			ARRAY_A
		);

		$heaviest = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS bytes
				 FROM {$wpdb->options}
				 WHERE autoload IN ($placeholders)
				 ORDER BY bytes DESC
				 LIMIT 10",
				self::AUTOLOAD_ON
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$role_bytes     = null === $role_bytes ? null : (int) $role_bytes;
		$autoload_bytes = (int) ( $autoload['bytes'] ?? 0 );

		return array(
			'option_name'      => $option_name,
			'role_bytes'       => $role_bytes,
			'autoload_bytes'   => $autoload_bytes,
			'autoload_count'   => (int) ( $autoload['count'] ?? 0 ),
			'role_share'       => $autoload_bytes > 0 && null !== $role_bytes ? $role_bytes / $autoload_bytes : null,
			'heaviest'         => array_map(
				static function ( $row ) {
					return array(
						'option_name' => $row['option_name'],
						'bytes'       => (int) $row['bytes'],
					);
				},
				(array) $heaviest
			),
			'role_option_rank' => self::rank_of( $option_name, (array) $heaviest ),
		);
	}

	/**
	 * Where the role option sits in the top ten, if it is there at all.
	 *
	 * @param string $needle Option name.
	 * @param array  $rows   Heaviest options.
	 * @return int|null 1-based rank.
	 */
	private static function rank_of( string $needle, array $rows ): ?int {
		foreach ( array_values( $rows ) as $i => $row ) {
			if ( $row['option_name'] === $needle ) {
				return $i + 1;
			}
		}

		return null;
	}
}

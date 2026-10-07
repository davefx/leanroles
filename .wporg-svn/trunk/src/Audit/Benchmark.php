<?php
/**
 * Measurements, not estimates.
 *
 * The whole argument of the plugin rests on numbers taken from this machine,
 * on this PHP build, against this option. A table of figures copied from a
 * blog post is a spreadsheet; this is a measurement.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Audit;

use LeanRoles\Support\Roles;

defined( 'ABSPATH' ) || exit;

final class Benchmark {

	/** Iterations for the unserialize loop, unless the clock says otherwise. */
	private const ITERATIONS = 2000;

	/** Stop early once the loop has taken this long. */
	private const TIME_BUDGET = 0.5;

	/**
	 * Keeps the measured structure alive across the memory reading.
	 *
	 * Measuring before and after an unserialize() whose result is immediately
	 * collectable measures nothing at all.
	 *
	 * @var mixed
	 */
	private static $anchor;

	/**
	 * Run the benchmarks.
	 *
	 * @param string|null $raw Serialized option value. Read from the database when null.
	 * @return array
	 */
	public static function run( ?string $raw = null ): array {
		if ( null === $raw ) {
			$raw = Roles::raw_option_value();
		}

		if ( null === $raw || '' === $raw ) {
			return array(
				'available' => false,
				'reason'    => __( 'The role option does not exist on this site.', 'leanroles' ),
			);
		}

		return array(
			'available'    => true,
			'bytes'        => strlen( $raw ),
			'unserialize'  => self::time_unserialize( $raw ),
			'memory'       => self::measure_memory( $raw ),
			'php_version'  => PHP_VERSION,
			'memory_limit' => ini_get( 'memory_limit' ),
		);
	}

	/**
	 * Time a single unserialize() of the option.
	 *
	 * A warm-up pass runs first: the first call pays for opcode caches, string
	 * interning and whatever the allocator feels like doing, and including it
	 * would flatter the result in the direction the plugin is arguing for.
	 *
	 * @param string $raw Serialized value.
	 * @return array
	 */
	private static function time_unserialize( string $raw ): array {
		for ( $i = 0; $i < 50; $i++ ) {
			unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		}

		$iterations = (int) apply_filters( 'leanroles_benchmark_iterations', self::ITERATIONS );
		$start      = microtime( true );
		$done       = 0;

		for ( $i = 0; $i < $iterations; $i++ ) {
			unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			++$done;

			// Sample the clock rarely; microtime() is not free either.
			if ( 0 === $done % 100 && ( microtime( true ) - $start ) > self::TIME_BUDGET ) {
				break;
			}
		}

		$elapsed = microtime( true ) - $start;

		return array(
			'iterations' => $done,
			'total'      => $elapsed,
			'per_call'   => $done > 0 ? $elapsed / $done : null,
		);
	}

	/**
	 * Measure the resident footprint of the unserialized structure.
	 *
	 * Note that memory_get_usage() is called without the real_usage flag,
	 * deliberately: the question is how much PHP hands to this array, not how
	 * many pages the allocator happened to request from the kernel.
	 *
	 * @param string $raw Serialized value.
	 * @return array
	 */
	private static function measure_memory( string $raw ): array {
		self::$anchor = null;

		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		$before = memory_get_usage();

		self::$anchor = unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

		$after = memory_get_usage();

		$footprint = max( 0, $after - $before );

		// Count the elements while the structure is still alive: the ratio of
		// bytes to elements is the interesting part, because unserialize()
		// scales with element count rather than with string length.
		$elements = self::count_elements( self::$anchor );

		self::$anchor = null;

		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		return array(
			'bytes'       => $footprint,
			'serialized'  => strlen( $raw ),
			'ratio'       => strlen( $raw ) > 0 ? $footprint / strlen( $raw ) : null,
			'elements'    => $elements,
			'per_element' => $elements > 0 ? $footprint / $elements : null,
			'peak'        => memory_get_peak_usage( true ),
		);
	}

	/**
	 * Total number of hashtable entries in a nested structure.
	 *
	 * @param mixed $value Structure.
	 */
	private static function count_elements( $value ): int {
		if ( ! is_array( $value ) ) {
			return 0;
		}

		$count = count( $value );

		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				$count += self::count_elements( $item );
			}
		}

		return $count;
	}

	/**
	 * Turn a per-worker footprint into a capacity statement.
	 *
	 * Nothing here is read from the PHP-FPM pool config, because a PHP process
	 * generally cannot read it and guessing would be worse than asking. Both
	 * inputs are exposed in the interface and both default to something
	 * conservative.
	 *
	 * @param int $footprint_bytes Resident cost of the role option per worker.
	 * @param int $server_ram_mb   RAM available to the PHP pool.
	 * @param int $worker_rss_mb   Average resident size of one worker.
	 * @return array
	 */
	public static function capacity( int $footprint_bytes, int $server_ram_mb, int $worker_rss_mb ): array {
		$server_bytes = $server_ram_mb * 1024 * 1024;
		$worker_bytes = max( 1, $worker_rss_mb * 1024 * 1024 );

		$now    = (int) floor( $server_bytes / $worker_bytes );
		$leaner = (int) floor( $server_bytes / max( 1, $worker_bytes - $footprint_bytes ) );

		return array(
			'server_ram_mb'  => $server_ram_mb,
			'worker_rss_mb'  => $worker_rss_mb,
			'workers_now'    => $now,
			'workers_leaner' => $leaner,
			'extra_workers'  => max( 0, $leaner - $now ),
		);
	}

	/**
	 * Bandwidth the alloptions blob costs at a given request rate.
	 *
	 * Only meaningful with a persistent object cache: alloptions is stored
	 * under one key, so the whole blob crosses the wire on every request that
	 * misses the local cache.
	 *
	 * @param int $alloptions_bytes Size of the autoloaded set.
	 * @param int $requests_per_sec Request rate.
	 * @return array
	 */
	public static function bandwidth( int $alloptions_bytes, int $requests_per_sec ): array {
		$bytes_per_sec = $alloptions_bytes * max( 0, $requests_per_sec );

		return array(
			'requests_per_sec' => $requests_per_sec,
			'bytes_per_sec'    => $bytes_per_sec,
			// Cast: PHP hands back an int when the division comes out exact,
			// and callers should not have to care which happened.
			'gb_per_day'       => (float) ( $bytes_per_sec * 86400 / ( 1024 ** 3 ) ),
		);
	}
}

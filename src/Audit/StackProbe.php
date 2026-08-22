<?php
/**
 * What is underneath: object cache drop-in, backend, and whether it has
 * already solved the problem.
 *
 * The tone of the whole report depends on this. Warning a stack that already
 * shards or compresses alloptions about the size of alloptions is how a report
 * loses the trust of the one reader who understood it.
 *
 * Everything below is reported as an observation with its evidence attached.
 * The drop-in is inspected, never assumed: several commercial ones have
 * changed behaviour between releases.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Audit;

defined( 'ABSPATH' ) || exit;

final class StackProbe {

	/**
	 * Markers looked for in the drop-in source, and what each one suggests.
	 *
	 * @var array<string,array{pattern:string,label:string}>
	 */
	private const MARKERS = array(
		'redis'       => array(
			'pattern' => '/\b(Redis|phpredis|Predis|RedisCachePro)\b/i',
			'label'   => 'Redis',
		),
		'memcached'   => array(
			'pattern' => '/\bMemcach(e|ed)\b/i',
			'label'   => 'Memcached',
		),
		'apcu'        => array(
			'pattern' => '/\bapcu_(fetch|store)\b/i',
			'label'   => 'APCu',
		),
		'sqlite'      => array(
			'pattern' => '/\bSQLite3?\b/i',
			'label'   => 'SQLite',
		),
		'igbinary'    => array(
			'pattern' => '/igbinary_(serialize|unserialize)/i',
			'label'   => 'igbinary serializer',
		),
		'msgpack'     => array(
			'pattern' => '/msgpack_(pack|unpack)/i',
			'label'   => 'msgpack serializer',
		),
		'compression' => array(
			'pattern' => '/\b(gzcompress|gzdeflate|zstd_compress|lz4_compress|snappy_compress)\b/i',
			'label'   => 'value compression',
		),
		'split'       => array(
			'pattern' => '/(alloptions|split_alloptions|shard).{0,40}(split|shard|chunk)/i',
			'label'   => 'possible alloptions splitting',
		),
	);

	/**
	 * Run the probe.
	 *
	 * @param string|null $dropin_path Path to inspect. Defaults to the real
	 *                                 drop-in location; overridable so the
	 *                                 detection can be exercised without
	 *                                 dropping a file into a live wp-content.
	 * @return array
	 */
	public static function run( ?string $dropin_path = null ): array {
		if ( null === $dropin_path ) {
			$dropin_path = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/object-cache.php' : '';
		}

		$path   = $dropin_path;
		$exists = '' !== $path && file_exists( $path );

		$report = array(
			'external_cache' => function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : false,
			'dropin_present' => $exists,
			'dropin_path'    => $exists ? $path : null,
			'dropin_bytes'   => $exists ? (int) filesize( $path ) : null,
			'backends'       => array(),
			'mitigations'    => array(),
			'notes'          => array(),
		);

		if ( ! $exists ) {
			$report['notes'][] = __( 'No object-cache.php drop-in is installed. The autoloaded options are read from MySQL on every request that is not served from a page cache.', 'leanroles' );

			return $report;
		}

		$source = self::read_head( $path );

		if ( null === $source ) {
			$report['notes'][] = __( 'A drop-in is installed but could not be read, so nothing is claimed about what it does.', 'leanroles' );

			return $report;
		}

		foreach ( self::MARKERS as $key => $marker ) {
			if ( ! preg_match( $marker['pattern'], $source ) ) {
				continue;
			}

			if ( in_array( $key, array( 'redis', 'memcached', 'apcu', 'sqlite' ), true ) ) {
				$report['backends'][] = $marker['label'];
			} else {
				$report['mitigations'][] = $marker['label'];
			}
		}

		$report['backends']    = array_values( array_unique( $report['backends'] ) );
		$report['mitigations'] = array_values( array_unique( $report['mitigations'] ) );

		if ( $report['mitigations'] ) {
			$report['notes'][] = __( 'This drop-in appears to already do something about the cost of large cached values. The findings below still describe real memory and unserialization work in PHP, which no cache backend removes — but the bandwidth projection may not apply to this stack.', 'leanroles' );
		}

		if ( in_array( 'Redis', $report['backends'], true ) && ! $report['mitigations'] ) {
			$report['notes'][] = __( 'Redis serves one command at a time. A single large GET for alloptions delays every other client waiting on the same connection, which is why the symptom usually shows up as unrelated timeouts.', 'leanroles' );
		}

		$report['notes'][] = __( 'Drop-in behaviour is inferred from markers in its source and may be wrong, particularly for commercial drop-ins that change between releases. Treat it as a starting point, not a verdict.', 'leanroles' );

		return $report;
	}

	/**
	 * Read the head of the drop-in.
	 *
	 * Enough to identify it; not so much that a megabyte-long bundled drop-in
	 * turns the audit into a file read benchmark.
	 *
	 * Not WP_Filesystem: it reads whole files, which is the one thing this must
	 * not do, and it wants credentials the audit has no business asking for. The
	 * file is a PHP drop-in already loaded into this very process — reading its
	 * first quarter-megabyte read-only is not a filesystem write the site owner
	 * needs to authorise.
	 *
	 * @param string $path  Path.
	 * @param int    $bytes Bytes.
	 */
	private static function read_head( string $path, int $bytes = 262144 ): ?string {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $handle ) {
			return null;
		}

		$source = fread( $handle, $bytes );
		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return false === $source ? null : $source;
	}
}

<?php
/**
 * Presentation helpers shared by the admin screens and the CLI.
 *
 * @package LeanRoles
 */

namespace LeanRoles\Support;

defined( 'ABSPATH' ) || exit;

final class Format {

	/**
	 * Bytes as a human-readable string, with enough precision to be useful at
	 * the scale role options actually reach.
	 *
	 * @param int|null $bytes Bytes.
	 */
	public static function bytes( ?int $bytes ): string {
		if ( null === $bytes ) {
			return '—';
		}

		if ( $bytes < 1024 ) {
			/* translators: %s: number of bytes. */
			return sprintf( _n( '%s byte', '%s bytes', $bytes, 'leanroles' ), number_format_i18n( $bytes ) );
		}

		if ( $bytes < 1024 * 1024 ) {
			return sprintf( '%s KB', number_format_i18n( $bytes / 1024, 1 ) );
		}

		return sprintf( '%s MB', number_format_i18n( $bytes / ( 1024 * 1024 ), 2 ) );
	}

	/**
	 * A duration in seconds, rendered at whatever scale keeps it legible.
	 *
	 * @param float|null $seconds Seconds.
	 */
	public static function duration( ?float $seconds ): string {
		if ( null === $seconds ) {
			return '—';
		}

		if ( $seconds < 0.001 ) {
			return sprintf( '%s µs', number_format_i18n( $seconds * 1000000, 1 ) );
		}

		if ( $seconds < 1 ) {
			return sprintf( '%s ms', number_format_i18n( $seconds * 1000, 2 ) );
		}

		return sprintf( '%s s', number_format_i18n( $seconds, 3 ) );
	}

	/**
	 * A share of a whole, as a percentage string.
	 *
	 * @param float $part     Part.
	 * @param float $whole    Whole.
	 * @param int   $decimals Decimals.
	 */
	public static function percent( float $part, float $whole, int $decimals = 1 ): string {
		if ( $whole <= 0 ) {
			return '—';
		}

		return number_format_i18n( ( $part / $whole ) * 100, $decimals ) . '%';
	}
}

<?php
/**
 * The coloured tag badge, shared by every screen.
 *
 * @package UserTags
 */

namespace UserTags\Admin;

defined( 'ABSPATH' ) || exit;

final class Badge {

	/**
	 * A coloured tag badge.
	 *
	 * @param string $label Tag name.
	 * @param string $color Hex colour.
	 */
	public static function render( string $label, string $color = '' ): string {
		$color = sanitize_hex_color( $color );

		return sprintf(
			'<span class="user-tags-badge" style="%s">%s</span>',
			$color ? 'background-color:' . esc_attr( $color ) . ';color:' . esc_attr( self::contrast( $color ) ) . ';' : '',
			esc_html( $label )
		);
	}

	/**
	 * Black or white, whichever stays legible on the given background.
	 *
	 * @param string $hex Hex colour.
	 */
	private static function contrast( string $hex ): string {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return '#fff';
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		// Perceived brightness, the usual weighting.
		return ( ( $r * 299 + $g * 587 + $b * 114 ) / 1000 ) > 140 ? '#1d2327' : '#fff';
	}
}

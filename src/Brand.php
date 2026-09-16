<?php
/**
 * DGLP brand tokens.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

defined( 'ABSPATH' ) || exit;

/**
 * The palette, read from the live site rather than invented.
 *
 * Source: `wp option get theme_mods_blocksy-child` on the staging site. Blocksy
 * already emits all eighteen as `--theme-palette-color-N` custom properties on
 * every page, and the dashboard renders inside the Blocksy child theme, so the
 * stylesheet consumes those properties and treats the hexes here as fallbacks
 * for the two places that render outside theme context: the standalone auth
 * screens and the email templates.
 *
 * Keeping the values in PHP as well as CSS is not duplication for its own sake.
 * It lets the test suite assert that every colour pair the interface uses still
 * clears WCAG AA, so a brand tweak that breaks contrast fails the build instead
 * of shipping.
 */
final class Brand {

	/**
	 * The Blocksy palette, by slot number.
	 *
	 * @return array<int, string>
	 */
	public static function palette(): array {
		return [
			1  => '#57b0b6', // Teal. Decorative only, see below.
			2  => '#b9eaea',
			3  => '#1d6265', // Dark teal. The teal that can carry white text.
			4  => '#1e3232', // Ink.
			5  => '#fdf8f0', // Page background.
			6  => '#4ca42f', // VAL green.
			7  => '#4364ad', // Forum Central blue.
			8  => '#ffffff',
			9  => '#ffc043', // Amber accent.
			10 => '#cc0053', // Accent hover.
			11 => '#ff6633', // Accent 3.
			12 => '#e3fffe',
			13 => '#000000',
			14 => '#2d3b6b', // Deep navy.
			15 => '#5b6bae', // Partnership purple.
			16 => '#c8ebd8',
			17 => '#a8c8e8',
			18 => '#f4f6fb', // Off white.
		];
	}

	/**
	 * Semantic roles, as palette slot numbers.
	 *
	 * Deep navy carries primary actions at 10.77:1 against white. Partnership
	 * purple carries links, which is what the site already uses it for.
	 *
	 * The teal is deliberately absent from every text-bearing role. At 2.53:1
	 * on white and 2.40:1 on the page background it fails AA both ways, so it
	 * is borders, tints and rails only. DGLP will think of it as the brand
	 * colour, so this needs saying out loud rather than quietly working around.
	 *
	 * @return array<string, int>
	 */
	public static function roles(): array {
		return [
			'primary'    => 14,
			'link'       => 15,
			'ink'        => 4,
			'page'       => 5,
			'surface'    => 18,
			'decorative' => 1,
			'teal-solid' => 3,
			'danger'     => 10,
		];
	}

	/**
	 * Status chip colours, background then text.
	 *
	 * Each background is its source palette colour mixed 88% into white, and
	 * each text colour is that same colour darkened until it clears 4.5:1
	 * against the background. So every chip is derived from the DGLP palette
	 * rather than picked by eye, and `tests/test-brand.php` proves each one.
	 *
	 * Colour is never the only signal. Every chip also carries its label.
	 *
	 * @return array<string, array{bg: string, fg: string}>
	 */
	public static function status_colours(): array {
		return [
			Statuses::DRAFT    => [ 'bg' => '#e4e6e6', 'fg' => '#1e3232' ],
			Statuses::PENDING  => [ 'bg' => '#fff7e8', 'fg' => '#7a5a1c' ],
			Statuses::LIVE     => [ 'bg' => '#eaf4e6', 'fg' => '#387923' ],
			Statuses::CHANGES  => [ 'bg' => '#f9e0ea', 'fg' => '#cc0053' ],
			Statuses::EXPIRED  => [ 'bg' => '#e6e7ed', 'fg' => '#2d3b6b' ],
			Statuses::ARCHIVED => [ 'bg' => '#ebedf5', 'fg' => '#5767a7' ],
			Statuses::REJECTED => [ 'bg' => '#ffede7', 'fg' => '#b84925' ],
		];
	}

	/**
	 * Typography, from the same theme mods. Montserrat throughout.
	 *
	 * @return array<string, string>
	 */
	public static function type(): array {
		return [
			'family'    => 'Montserrat',
			'root-size' => '18px',
			'root-line' => '1.65',
			'body'      => '400',
			'heading'   => '700',
			'button'    => '500',
		];
	}

	/**
	 * Geometry, from the same theme mods.
	 *
	 * @return array<string, string>
	 */
	public static function geometry(): array {
		return [
			'radius-button' => '8px',
			'radius-card'   => '20px',
			'radius-field'  => '10px',
			'field-border'  => '2px',
			'field-height'  => '45px',
		];
	}

	/**
	 * The relative luminance of a hex colour, per WCAG 2.1.
	 */
	public static function luminance( string $hex ): float {
		$hex = ltrim( trim( $hex ), '#' );

		$channels = [
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		];

		foreach ( $channels as $i => $value ) {
			$c            = $value / 255;
			$channels[ $i ] = $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * The contrast ratio between two hex colours, from 1 to 21.
	 */
	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );

		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}
}

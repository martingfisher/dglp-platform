<?php
/**
 * Keeping the theme's page furniture out of the member area.
 *
 * The member area renders its own document but still calls wp_footer(),
 * because scripts live there. So does everything a theme or plugin hooks
 * into it: Blocksy's site footer, and any Blocksy content block whose
 * location is a hook, which arrives as `<div data-block="hook:123">`.
 * That second one is what the "sky blue band" under short pages was: a
 * Greenshift-built block, post 1258 on staging, hooked into the footer.
 *
 * Elements are removed whole, by counting opening and closing tags, so a
 * footer with a nested footer or a block with fifty nested divs comes out
 * in one piece. A non-greedy regex, which is what 0.8.4 used, stops at
 * the first closing tag it sees and leaves the rest on the page.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

defined( 'ABSPATH' ) || exit;

final class Chrome {

	/**
	 * Strip the theme's furniture from wp_footer() output.
	 */
	public static function strip( string $html ): string {
		$html = self::remove_elements( $html, '#<footer(?![\w-])[^>]*>#i', 'footer' );
		$html = self::remove_elements( $html, '#<div\b[^>]*\bdata-block="hook:[^"]*"[^>]*>#i', 'div' );

		return $html;
	}

	/**
	 * Remove every element whose opening tag matches, with all its contents.
	 *
	 * @param string $open_pattern Regex for the opening tag.
	 * @param string $tag          The tag name, for counting nesting.
	 */
	public static function remove_elements( string $html, string $open_pattern, string $tag ): string {
		$tag = strtolower( $tag );

		while ( 1 === preg_match( $open_pattern, $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$start = (int) $m[0][1];
			$end   = self::element_end( $html, $start + strlen( $m[0][0] ), $tag );

			if ( null === $end ) {
				// Unbalanced markup: drop to the end rather than leave half.
				$html = substr( $html, 0, $start );
				break;
			}

			$html = substr( $html, 0, $start ) . substr( $html, $end );
		}

		return $html;
	}

	/**
	 * The byte just past the closing tag that balances an element already
	 * opened, or null when the markup never closes it.
	 */
	private static function element_end( string $html, int $from, string $tag ): ?int {
		$depth   = 1;
		$pattern = '#<(/?)' . preg_quote( $tag, '#' ) . '(?![\w-])[^>]*>#i';

		while ( 1 === preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE, $from ) ) {
			$from = (int) $m[0][1] + strlen( $m[0][0] );

			if ( '/' === $m[1][0] ) {
				--$depth;
			} elseif ( ! str_ends_with( $m[0][0], '/>' ) ) {
				++$depth;
			}

			if ( 0 === $depth ) {
				return $from;
			}
		}

		return null;
	}
}

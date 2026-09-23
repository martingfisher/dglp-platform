<?php
/**
 * Take dead links out of HTML, keeping their text.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Pure, no WordPress calls. A decider says what to do with each link's
 * address: null to leave it, '' to unlink it (the text stays, the anchor
 * goes), or a new address to point it at instead.
 */
final class DeadLinks {

	/**
	 * @param callable(string): ?string $decide Given a trimmed, entity-decoded href, returns null (keep), '' (unlink) or a replacement.
	 * @return array{html: string, changes: array<int, array{href: string, action: string, to: string}>}
	 */
	public static function strip( string $html, callable $decide ): array {
		$changes = [];

		$out = (string) preg_replace_callback(
			'#<a\b([^>]*)>(.*?)</a>#is',
			static function ( array $m ) use ( $decide, &$changes ): string {
				$attrs = $m[1];
				$inner = $m[2];

				if ( ! preg_match( '#\bhref\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $attrs, $h ) ) {
					return $m[0];
				}

				$raw  = $h[2] ?? '';
				$raw  = '' !== $raw ? $raw : ( $h[3] ?? '' );
				$raw  = '' !== $raw ? $raw : ( $h[4] ?? '' );
				$href = trim( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

				$verdict = $decide( $href );

				if ( null === $verdict ) {
					return $m[0];
				}

				if ( '' === $verdict ) {
					$changes[] = [ 'href' => $href, 'action' => 'unlinked', 'to' => '' ];

					return $inner;
				}

				$changes[] = [ 'href' => $href, 'action' => 'rewritten', 'to' => $verdict ];

				$new_attrs = (string) preg_replace(
					'#\bhref\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i',
					'href="' . htmlspecialchars( $verdict, ENT_QUOTES, 'UTF-8' ) . '"',
					$attrs,
					1
				);

				return '<a' . $new_attrs . '>' . $inner . '</a>';
			},
			$html
		);

		return [ 'html' => $out, 'changes' => $changes ];
	}

	/**
	 * The last path segment of an address, as a slug, or ''.
	 */
	public static function slug_of( string $url ): string {
		$path = (string) ( wp_parse_url_shim( $url )['path'] ?? '' );
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return '';
		}

		$parts = explode( '/', $path );

		return strtolower( (string) end( $parts ) );
	}

	/**
	 * The host of an address, lower-case, or ''.
	 */
	public static function host_of( string $url ): string {
		return strtolower( (string) ( wp_parse_url_shim( $url )['host'] ?? '' ) );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\wp_parse_url_shim' ) ) {
	/**
	 * parse_url() with the failure case folded to an empty array, so the
	 * pure class can be unit tested without WordPress's wp_parse_url().
	 *
	 * @return array<string, string|int>
	 */
	function wp_parse_url_shim( string $url ): array {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure code, tested without WordPress.

		return is_array( $parts ) ? $parts : [];
	}
}

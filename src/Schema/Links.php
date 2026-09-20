<?php
/**
 * One rule for typed web addresses, everywhere one is typed.
 *
 * Members type "example.com". That is a web address to a person and not
 * one to a computer, so every form that takes one puts "https://" in front
 * when no scheme was given. Plain "http://" is refused everywhere, by
 * DGLP's decision of 20 September 2026: the site lists nothing served
 * without a certificate, and the team help a group secure its hosting
 * rather than link to it insecurely. Pure, no WordPress.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Schema;

final class Links {

	/**
	 * A typed address with a scheme on it. Empty stays empty; anything with
	 * a scheme already, including "javascript:", is returned as typed so the
	 * validator can refuse it rather than have it hidden behind "https://".
	 */
	public static function normalise( string $raw ): string {
		$value = trim( $raw );

		if ( '' === $value ) {
			return '';
		}

		// "//example.com" is a scheme-relative address; give it the scheme too.
		$value = ltrim( $value, '/' );

		// A scheme is letters then a colon, unless what follows the colon is a
		// port: "localhost:8080/x" and "example.com:443" are hosts, not schemes.
		if ( 1 === preg_match( '/^[a-z][a-z0-9+.\-]*:(?!\d+(?:\/|$))/i', $value ) ) {
			return $value;
		}

		return 'https://' . $value;
	}

	/** True for http or https, whatever the case. */
	public static function is_web( string $url ): bool {
		$scheme = parse_url( $url, PHP_URL_SCHEME );

		return is_string( $scheme ) && in_array( strtolower( $scheme ), [ 'http', 'https' ], true );
	}

	/** True for https only. The site lists nothing served over plain http. */
	public static function is_secure( string $url ): bool {
		$scheme = parse_url( $url, PHP_URL_SCHEME );

		return is_string( $scheme ) && 'https' === strtolower( $scheme );
	}

	/**
	 * The http links in a piece of HTML, the ones the site refuses.
	 *
	 * @return string[]
	 */
	public static function insecure_hrefs( string $html ): array {
		return array_values( array_filter( self::hrefs( $html ), static fn( string $h ): bool => 1 === preg_match( '/^http:\/\//i', $h ) ) );
	}

	/** The same address over https, or null when it is not a plain http one. */
	public static function https_twin( string $url ): ?string {
		return 1 === preg_match( '/^http:\/\//i', $url ) ? 'https://' . substr( $url, 7 ) : null;
	}

	/**
	 * Every href in a piece of HTML, in order, deduplicated.
	 *
	 * @return string[]
	 */
	public static function hrefs( string $html ): array {
		if ( 0 === preg_match_all( '/href\s*=\s*["\']([^"\']+)["\']/i', $html, $m ) ) {
			return [];
		}

		return array_values( array_unique( array_map( static fn( string $h ): string => html_entity_decode( trim( $h ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $m[1] ) ) );
	}
}

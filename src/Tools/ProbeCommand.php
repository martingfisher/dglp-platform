<?php
/**
 * WP-CLI: read the site's own rendered pages from the server.
 *
 * For the times the person debugging cannot reach the site in a browser
 * but can reach WP-CLI. Read-only: it fetches a page the way any visitor
 * would and prints the markup around a phrase.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Tools;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class ProbeCommand {

	public static function register(): void {
		WP_CLI::add_command( 'dgl probe', self::class );
	}

	/**
	 * Fetch a page of this site and print the markup around a phrase.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Path on this site, e.g. /dashboard/ or /events/.
	 *
	 * <needle>
	 * : Text to look for in the HTML.
	 *
	 * [--before=<chars>]
	 * : Characters to show before each match. Default 600.
	 *
	 * [--after=<chars>]
	 * : Characters to show after each match. Default 200.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl probe /dashboard/ "Cookies Policy"
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function page( array $args, array $assoc ): void {
		$path   = '/' . ltrim( (string) ( $args[0] ?? '/' ), '/' );
		$needle = (string) ( $args[1] ?? '' );
		$before = max( 0, (int) ( $assoc['before'] ?? 600 ) );
		$after  = max( 0, (int) ( $assoc['after'] ?? 200 ) );

		if ( '' === $needle ) {
			WP_CLI::error( 'Give a phrase to look for.' );
		}

		$url      = home_url( $path );
		$response = wp_remote_get( $url, [ 'timeout' => 20, 'redirection' => 3, 'sslverify' => false ] );

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( $response->get_error_message() );
		}

		$html = (string) wp_remote_retrieve_body( $response );
		WP_CLI::log( sprintf( 'GET %s -> HTTP %d, %d bytes', $url, (int) wp_remote_retrieve_response_code( $response ), strlen( $html ) ) );

		$at    = 0;
		$found = 0;

		while ( false !== ( $pos = strpos( $html, $needle, $at ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$found;
			$start = max( 0, $pos - $before );
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( '--- match %d at byte %d ---', $found, $pos ) );
			WP_CLI::log( substr( $html, $start, $pos - $start + strlen( $needle ) + $after ) );
			$at = $pos + strlen( $needle );

			if ( $found >= 5 ) {
				break;
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( 0 === $found ? 'Not found.' : sprintf( '%d match(es).', $found ) );
	}
}

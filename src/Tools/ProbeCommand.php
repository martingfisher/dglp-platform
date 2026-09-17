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
	 * [--post=<query>]
	 * : Send a POST instead, with this urlencoded body, e.g.
	 * "dgl[title]=Hello&dgl[body]=<p>Hi</p>". For finding out whether a
	 * firewall in front of WordPress rejects a form before it arrives.
	 *
	 * [--multipart]
	 * : Send the --post fields as multipart/form-data, the way a form with
	 * a file control posts.
	 *
	 * [--file=<kind>]
	 * : With --multipart, also attach a small generated file as
	 * dgl_file_image: "png" (a real 1x1 PNG), "text" (a .txt), or "blob:N"
	 * (N megabytes of random bytes named photo.jpg) to find a size limit.
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

		$url  = home_url( $path );
		$post = isset( $assoc['post'] ) ? (string) $assoc['post'] : null;

		if ( null === $post ) {
			$response = wp_remote_get( $url, [ 'timeout' => 20, 'redirection' => 3, 'sslverify' => false ] );
		} elseif ( isset( $assoc['multipart'] ) ) {
			[ $body, $type ] = self::multipart( $post, (string) ( $assoc['file'] ?? '' ) );
			$response        = wp_remote_post( $url, [ 'timeout' => 20, 'redirection' => 0, 'sslverify' => false, 'body' => $body, 'headers' => [ 'Content-Type' => $type ] ] );
		} else {
			$response = wp_remote_post( $url, [ 'timeout' => 20, 'redirection' => 0, 'sslverify' => false, 'body' => $post, 'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ] ] );
		}

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( $response->get_error_message() );
		}

		$html = (string) wp_remote_retrieve_body( $response );
		WP_CLI::log( sprintf( '%s %s -> HTTP %d, %d bytes%s', null === $post ? 'GET' : 'POST', $url, (int) wp_remote_retrieve_response_code( $response ), strlen( $html ), '' !== (string) wp_remote_retrieve_header( $response, 'server' ) ? ', server: ' . wp_remote_retrieve_header( $response, 'server' ) : '' ) );

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

	/**
	 * Build a multipart body from a urlencoded field string.
	 *
	 * @return array{0: string, 1: string} Body, then the Content-Type header.
	 */
	private static function multipart( string $query, string $file ): array {
		$boundary = 'dglprobe' . wp_generate_password( 12, false );
		$fields   = [];
		parse_str( $query, $fields );
		$body = '';

		$flat = static function ( array $values, string $prefix = '' ) use ( &$flat ): array {
			$out = [];
			foreach ( $values as $key => $value ) {
				$name = '' === $prefix ? (string) $key : $prefix . '[' . $key . ']';
				if ( is_array( $value ) ) {
					$out = array_merge( $out, $flat( $value, $name ) );
				} else {
					$out[ $name ] = (string) $value;
				}
			}
			return $out;
		};

		foreach ( $flat( $fields ) as $name => $value ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}

		if ( 'png' === $file ) {
			$png   = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"dgl_file_image\"; filename=\"probe.png\"\r\nContent-Type: image/png\r\n\r\n{$png}\r\n";
		} elseif ( str_starts_with( $file, 'blob:' ) ) {
			$mb    = max( 1, min( 64, (int) substr( $file, 5 ) ) );
			$blob  = random_bytes( $mb * 1024 * 1024 );
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"dgl_file_image\"; filename=\"photo.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n{$blob}\r\n";
		} elseif ( 'text' === $file ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"dgl_file_image\"; filename=\"probe.txt\"\r\nContent-Type: text/plain\r\n\r\nhello\r\n";
		} elseif ( '' === $file ) {
			// An untouched file control still posts an empty part, and that is
			// the shape a firewall might object to.
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"dgl_file_image\"; filename=\"\"\r\nContent-Type: application/octet-stream\r\n\r\n\r\n";
		}

		$body .= "--{$boundary}--\r\n";

		return [ $body, 'multipart/form-data; boundary=' . $boundary ];
	}
}

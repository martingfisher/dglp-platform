<?php
/**
 * Where the weekly funding import posts its results.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Funding;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/dgl/v1/funding/sync, bearer token only.
 *
 * The token is made by `wp dgl funding token`, shown once, and kept here only
 * as a SHA-256 hash, so a copy of the database does not hand anyone the means
 * to post funds. No token saved means the route refuses everything.
 */
final class Endpoint {

	public const NAMESPACE = 'dgl/v1';
	public const ROUTE     = '/funding/sync';

	/** The token's hash. */
	public const OPTION_TOKEN = 'dgl_funding_token_hash';

	/** A bigger body than this is not a week of funding. */
	public const MAX_BYTES = 8 * 1024 * 1024;

	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => [ self::class, 'allowed' ],
			]
		);
	}

	/** Whether the request carries the token. */
	public static function allowed( WP_REST_Request $request ): bool {
		$hash   = (string) get_option( self::OPTION_TOKEN, '' );
		$header = (string) $request->get_header( 'authorization' );

		if ( '' === $hash || ! preg_match( '/^Bearer\s+(\S+)$/i', trim( $header ), $m ) ) {
			return false;
		}

		return self::matches( $m[1], $hash );
	}

	/** Pure: does this token hash to that. Constant time. */
	public static function matches( string $token, string $hash ): bool {
		return '' !== $token && '' !== $hash && hash_equals( $hash, hash( 'sha256', $token ) );
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		if ( strlen( (string) $request->get_body() ) > self::MAX_BYTES ) {
			return new WP_REST_Response( [ 'ok' => false, 'errors' => [ 'Request too large.' ] ], 413 );
		}

		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'errors' => [ 'Expected a JSON object.' ] ], 400 );
		}

		$dry = in_array( strtolower( (string) $request->get_param( 'dry_run' ) ), [ '1', 'true', 'yes' ], true );
		$run = Import::run( $payload, $dry );

		return new WP_REST_Response( $run, $run['ok'] ? 200 : 422 );
	}

	/**
	 * A new token: saved as its hash, returned once.
	 */
	public static function new_token(): string {
		$token = bin2hex( random_bytes( 32 ) );
		update_option( self::OPTION_TOKEN, hash( 'sha256', $token ), false );

		return $token;
	}
}

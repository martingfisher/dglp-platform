<?php
/**
 * Ask SMTP2GO what became of a message, from the server.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The site sends through the SMTP2GO plugin, which keeps the account's API
 * key in an option. The same key can read the account's activity log, so
 * "did it arrive?" can be answered from the command line with the events
 * SMTP2GO recorded for an address: processed, delivered, bounced, deferred.
 * The key is read here and sent to SMTP2GO; it is never printed or logged.
 */
final class Smtp2go {

	/** The SMTP2GO plugin's own key option (stored encrypted by recent plugin versions). */
	public const OPTION_KEY    = 'smtp2go_api_key';
	public const OPTION_REGION = 'smtp2go_api_region';

	/** Our own option: a read-only SMTP2GO key with reports permission, for asking about delivery. */
	public const OPTION_REPORT_KEY = 'dgl_smtp2go_report_key';

	/** Seconds to wait for SMTP2GO. */
	public const TIMEOUT = 15;

	/**
	 * The key to ask with: our own read-only reporting key first, else the
	 * SMTP2GO plugin's own key. The plugin (1.17) stores its key as
	 * base64( iv . AES-256-CTR( key, AUTH_KEY ) ), decrypted the same way
	 * here; an older plugin stored it in the clear. A key SMTP2GO accepts
	 * reads api-<32 characters>, and nothing else is ever used.
	 */
	public static function key(): string {
		$own = trim( (string) get_option( self::OPTION_REPORT_KEY, '' ) );

		if ( self::looks_like_key( $own ) ) {
			return $own;
		}

		$plugin = self::unwrap( trim( (string) get_option( self::OPTION_KEY, '' ) ), defined( 'AUTH_KEY' ) ? (string) constant( 'AUTH_KEY' ) : '' );

		return self::looks_like_key( $plugin ) ? $plugin : '';
	}

	public static function looks_like_key( string $candidate ): bool {
		return 1 === preg_match( '/^api-[A-Za-z0-9]{32}$/', $candidate );
	}

	/**
	 * The SMTP2GO plugin's stored key in the clear. Pure given the secret.
	 */
	public static function unwrap( string $stored, string $secret ): string {
		if ( '' === $stored || str_starts_with( $stored, 'api-' ) ) {
			return $stored;
		}

		if ( '' === $secret || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( $stored, true );
		$len = (int) openssl_cipher_iv_length( 'AES-256-CTR' );

		if ( false === $raw || strlen( $raw ) <= $len ) {
			return '';
		}

		$plain = openssl_decrypt( substr( $raw, $len ), 'AES-256-CTR', $secret, 0, substr( $raw, 0, $len ) );

		return is_string( $plain ) ? $plain : '';
	}

	/** Whether there is a usable key. */
	public static function configured(): bool {
		return '' !== self::key();
	}

	/** The API base for the account's region. */
	public static function base(): string {
		$region = strtolower( trim( (string) get_option( self::OPTION_REGION, '' ) ) );

		return 'eu' === $region ? 'https://eu-api.smtp2go.com/v3/' : 'https://api.smtp2go.com/v3/';
	}

	/**
	 * The activity SMTP2GO recorded for an address, newest first.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error Each event: time, event, subject, recipient, sender, detail.
	 */
	public static function activity( string $recipient, int $limit = 10, int $hours = 48 ): array|WP_Error {
		$key = self::key();

		if ( '' === $key ) {
			return new WP_Error( 'dgl_no_smtp2go', __( 'No usable SMTP2GO key: the SMTP2GO plugin has none saved, or its key could not be read. Save a read-only reporting key under Email settings.', 'dgl-platform' ) );
		}

		$since = gmdate( 'Y-m-d\TH:i:s\Z', time() - $hours * HOUR_IN_SECONDS );

		$response = wp_remote_post(
			self::base() . 'activity/search',
			[
				'timeout' => self::TIMEOUT,
				'headers' => [ 'Content-Type' => 'application/json', 'X-Smtp2go-Api-Key' => $key ],
				'body'    => wp_json_encode(
					[
						'search_recipient' => $recipient,
						'start_date'       => $since,
						'limit'            => max( 1, min( 100, $limit ) ),
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'dgl_smtp2go_unreachable', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			/* translators: %d: an HTTP status. */
			return new WP_Error( 'dgl_smtp2go_bad_reply', sprintf( __( 'SMTP2GO answered HTTP %d with something that is not JSON.', 'dgl-platform' ), $code ) );
		}

		return self::summarise( $body, $code );
	}

	/**
	 * SMTP2GO's answer as a flat list of events, or the error it gave. Pure.
	 *
	 * @param array<string, mixed> $body
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function summarise( array $body, int $code = 200 ): array|WP_Error {
		$data = is_array( $body['data'] ?? null ) ? $body['data'] : [];

		if ( 200 !== $code || isset( $data['error'] ) || isset( $data['error_code'] ) ) {
			$why = (string) ( $data['error'] ?? $data['error_code'] ?? ( $body['error'] ?? '' ) );

			/* translators: 1: an HTTP status, 2: the service's own words. */
			return new WP_Error( 'dgl_smtp2go_refused', sprintf( __( 'SMTP2GO refused the question (HTTP %1$d): %2$s', 'dgl-platform' ), $code, '' !== $why ? $why : __( 'no reason given', 'dgl-platform' ) ) );
		}

		$events = is_array( $data['events'] ?? null ) ? $data['events'] : [];
		$out    = [];

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$out[] = [
				'time'      => (string) ( $event['time'] ?? $event['date'] ?? '' ),
				'event'     => (string) ( $event['event'] ?? $event['status'] ?? '' ),
				'subject'   => (string) ( $event['subject'] ?? '' ),
				'recipient' => (string) ( $event['recipient'] ?? ( is_array( $event['recipients'] ?? null ) ? implode( ', ', $event['recipients'] ) : '' ) ),
				'sender'    => (string) ( $event['sender'] ?? '' ),
				'detail'    => (string) ( $event['smtp_response'] ?? $event['error'] ?? $event['reason'] ?? $event['detail'] ?? '' ),
			];
		}

		return $out;
	}
}

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

	public const OPTION_KEY    = 'smtp2go_api_key';
	public const OPTION_REGION = 'smtp2go_api_region';

	/** Seconds to wait for SMTP2GO. */
	public const TIMEOUT = 15;

	/** Whether the SMTP2GO plugin has a key for us to use. */
	public static function configured(): bool {
		return '' !== trim( (string) get_option( self::OPTION_KEY, '' ) );
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
		$key = trim( (string) get_option( self::OPTION_KEY, '' ) );

		if ( '' === $key ) {
			return new WP_Error( 'dgl_no_smtp2go', __( 'The SMTP2GO plugin has no API key saved, so there is nothing to ask.', 'dgl-platform' ) );
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

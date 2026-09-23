<?php
/**
 * The join page asks, before anything is sent, whether what is being typed
 * is an organisation already on the list.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Joining;

use DGL\Meta;
use DGL\Org\Duplicates;
use DGL\Schema\Links;

defined( 'ABSPATH' ) || exit;

/**
 * A logged-out AJAX endpoint keyed to the sign-up token, so only somebody
 * part way through joining with a proven address can ask, and asked no
 * more than sixty times an hour per sign-up. The answer is the same
 * matcher the server runs on submit; this only makes the round trip rare.
 * The server check remains the guarantee.
 */
final class MatchEndpoint {

	public const ACTION = 'dgl_join_matches';

	public static function init(): void {
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ self::class, 'matches' ] );
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'matches' ] );
	}

	public static function matches(): void {
		$answer = self::answer( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the token is the credential; each field is sanitised in answer().

		if ( 200 !== $answer['status'] ) {
			wp_send_json_error( $answer['body'], $answer['status'] );
		}

		wp_send_json_success( $answer['body'] );
	}

	/**
	 * The reply, as status and body, so it can be tested without exiting.
	 *
	 * @param array<string, mixed> $post
	 * @return array{status: int, body: array<string, mixed>}
	 */
	public static function answer( array $post ): array {
		$signup = Store::find_by_token( sanitize_text_field( (string) ( $post['token'] ?? '' ) ) );

		if ( null === $signup || Signup::VERIFIED !== $signup->state || '' !== Rules::link_problem( $signup, Store::now() ) ) {
			return [ 'status' => 403, 'body' => [ 'message' => __( 'Confirm your email address first.', 'dgl-platform' ) ] ];
		}

		$limited = Guard::limited_lookup( $signup->id );

		if ( '' !== $limited ) {
			return [ 'status' => 429, 'body' => [ 'message' => $limited ] ];
		}

		$name    = sanitize_text_field( (string) ( $post['name'] ?? '' ) );
		$details = [
			'org_website'  => esc_url_raw( Links::normalise( (string) ( $post['website'] ?? '' ) ) ),
			'org_number'   => sanitize_text_field( (string) ( $post['number'] ?? '' ) ),
			'org_postcode' => sanitize_text_field( (string) ( $post['postcode'] ?? '' ) ),
		];

		if ( mb_strlen( trim( $name ) ) < 3 && '' === $details['org_website'] && '' === $details['org_number'] ) {
			return [ 'status' => 200, 'body' => [ 'matches' => [], 'hard' => false ] ];
		}

		$matches = Joining::matches_for( $signup, $name, $details );
		$out     = [];

		foreach ( $matches as $match ) {
			if ( $match['trashed'] ) {
				continue;
			}

			$out[] = [
				'id'       => $match['id'],
				'name'     => $match['name'],
				'strength' => $match['strength'],
				'pending'  => Meta::ORG_PENDING === $match['status'],
				'why'      => implode( ', ', array_unique( array_map( [ Duplicates::class, 'reason_label' ], $match['reasons'] ) ) ),
			];
		}

		return [ 'status' => 200, 'body' => [ 'matches' => $out, 'hard' => [] !== Duplicates::hard( $matches ) ] ];
	}
}

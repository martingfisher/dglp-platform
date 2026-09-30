<?php
/**
 * Asking a venue about hiring a space.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Spaces;

use DGL\Audit\Log;
use DGL\Email\Mailer;
use DGL\Email\Routing;
use DGL\Email\SpacesCopy;
use DGL\Joining\Guard;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Statuses;
use DateTimeImmutable;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * An enquiry, not a booking. The form on a venue's page sends one email
 * to the venue's contact with the enquirer as Reply-To, so the venue
 * answers from its own inbox and the site is out of the conversation.
 * Nothing is stored beyond an audit line saying an enquiry was sent.
 *
 * The page is cached in front of the site, so the form carries no
 * WordPress nonce: a nonce baked into cached HTML expires under the
 * visitor. Instead: the honeypot and signed stamp the join form uses,
 * an origin check, and a rate limit per address and per connection.
 */
final class Enquiry {

	public const CONTEXT = 'enquiry';

	/** The field that marks a POST as an enquiry. */
	public const FLAG = 'dgl_enquiry';

	/** Enquiries allowed per email address, and per connection, an hour. */
	public const PER_EMAIL = 10;
	public const PER_IP    = 30;

	public const MESSAGE_MIN = 10;
	public const MESSAGE_MAX = 2000;

	/** What a failed post left, for the page to show. */
	private static ?array $pending = null;

	public static function init(): void {
		add_action( 'template_redirect', [ self::class, 'handle' ], 8 );
	}

	/**
	 * What the venue page shows: a form, a note that enquiries are off, or
	 * nothing. Carries the values and errors of a post that failed.
	 *
	 * @return array<string, mixed>
	 */
	public static function state( WP_Post $venue ): array {
		$to = self::recipients( (int) $venue->ID );

		if ( PostTypes::VENUE !== $venue->post_type || Statuses::LIVE !== $venue->post_status || [] === $to || Visibility::is_hidden( (int) $venue->ID ) ) {
			return [ 'show' => false, 'off' => false ];
		}

		if ( ! Routing::is_enabled() ) {
			return [ 'show' => false, 'off' => true ];
		}

		$sent   = isset( $_GET['sent'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a flag on a redirect, nothing is written.
		$picked = isset( $_GET['space'] ) ? (int) $_GET['space'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return [
			'show'    => true,
			'off'     => false,
			'sent'    => $sent,
			'limited' => isset( $_GET['enquiry'] ) && 'limited' === $_GET['enquiry'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'errors'  => self::$pending['errors'] ?? [],
			'values'  => self::$pending['values'] ?? [ 'space' => $picked > 0 ? (string) $picked : '' ],
			'stamp'   => Guard::stamp( null, self::CONTEXT ),
		];
	}

	/**
	 * Where an enquiry goes: the venue's contact, else the organisation's
	 * owners. Empty means no form.
	 *
	 * @return string[]
	 */
	public static function recipients( int $venue_id ): array {
		$contact = sanitize_email( (string) get_post_meta( $venue_id, 'dgl_contact_email', true ) );

		if ( '' !== $contact && is_email( $contact ) ) {
			return [ $contact ];
		}

		$org_id = Org::for_item( $venue_id );

		return $org_id > 0 ? Org::owner_emails( $org_id ) : [];
	}

	/**
	 * The options for "Which space?": every live space, plus "Not sure".
	 *
	 * @return array<string, string> Value => label.
	 */
	public static function options( int $venue_id ): array {
		$out = [];

		foreach ( SpacesQuery::spaces( $venue_id ) as $space ) {
			$out[ (string) $space['post']->ID ] = (string) get_the_title( $space['post'] );
		}

		$out['any'] = __( 'Not sure yet', 'dgl-platform' );

		return $out;
	}

	/**
	 * Clean and check what was typed. Pure: the caller passes the options
	 * and the date, so tests can too.
	 *
	 * @param array<string, mixed>  $post    The raw POST, unslashed.
	 * @param array<string, string> $options From options().
	 * @return array{values: array<string, string>, errors: array<string, string>}
	 */
	public static function validate( array $post, array $options, DateTimeImmutable $today ): array {
		$get = static fn( string $key, int $max ): string => mb_substr( trim( sanitize_text_field( (string) ( $post[ $key ] ?? '' ) ) ), 0, $max );

		$values = [
			'space'     => $get( 'space', 20 ),
			'date'      => $get( 'date', 10 ),
			'time_from' => $get( 'time_from', 5 ),
			'time_to'   => $get( 'time_to', 5 ),
			'people'    => $get( 'people', 6 ),
			'message'   => mb_substr( trim( sanitize_textarea_field( (string) ( $post['message'] ?? '' ) ) ), 0, self::MESSAGE_MAX + 1 ),
			'name'      => $get( 'name', 120 ),
			'email'     => strtolower( $get( 'email', 200 ) ),
			'phone'     => $get( 'phone', 40 ),
		];

		$errors = [];

		if ( ! isset( $options[ $values['space'] ] ) ) {
			$errors['space'] = __( 'Pick a space, or "Not sure yet".', 'dgl-platform' );
		}

		if ( '' === $values['date'] ) {
			$errors['date'] = __( 'Tell the venue the date you have in mind.', 'dgl-platform' );
		} else {
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $values['date'], $today->getTimezone() );

			if ( ! $date instanceof DateTimeImmutable || $date->format( 'Y-m-d' ) !== $values['date'] ) {
				$errors['date'] = __( 'That date does not look right.', 'dgl-platform' );
			} elseif ( $date < $today->setTime( 0, 0, 0 ) ) {
				$errors['date'] = __( 'That date has passed.', 'dgl-platform' );
			}
		}

		foreach ( [ 'time_from', 'time_to' ] as $key ) {
			if ( '' !== $values[ $key ] && 1 !== preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $values[ $key ] ) ) {
				$errors[ $key ] = __( 'Use a time like 14:30.', 'dgl-platform' );
			}
		}

		if ( '' !== $values['people'] ) {
			if ( 1 !== preg_match( '/^\d+$/', $values['people'] ) || (int) $values['people'] < 1 || (int) $values['people'] > SpacesQuery::MAX_PEOPLE ) {
				$errors['people'] = __( 'How many people, as a number.', 'dgl-platform' );
			} else {
				$values['people'] = (string) (int) $values['people'];
			}
		}

		$length = mb_strlen( $values['message'] );

		if ( $length < self::MESSAGE_MIN ) {
			$errors['message'] = __( 'Say a little about what you are planning, so the venue can answer properly.', 'dgl-platform' );
		} elseif ( $length > self::MESSAGE_MAX ) {
			/* translators: %d: a number of characters. */
			$errors['message'] = sprintf( __( 'Keep it under %d characters.', 'dgl-platform' ), self::MESSAGE_MAX );
		}

		if ( '' === $values['name'] ) {
			$errors['name'] = __( 'Your name, so the venue knows who to reply to.', 'dgl-platform' );
		}

		if ( '' === $values['email'] || ! is_email( $values['email'] ) ) {
			$errors['email'] = __( 'An email address the venue can reply to.', 'dgl-platform' );
		}

		return [ 'values' => $values, 'errors' => $errors ];
	}

	/**
	 * A Reply-To header that cannot smuggle a second header in.
	 */
	public static function reply_to( string $name, string $email ): string {
		$name  = trim( (string) preg_replace( '/[\r\n<>"]+/', ' ', $name ) );
		$email = trim( (string) preg_replace( '/[\r\n<>\s]+/', '', $email ) );

		return 'Reply-To: ' . ( '' !== $name ? $name . ' <' . $email . '>' : $email );
	}

	/**
	 * Whether the POST came from a page on this site. The form carries no
	 * nonce (the page is cached), so the browser's own word is the check.
	 *
	 * @param array<string, mixed> $server $_SERVER.
	 */
	public static function same_origin( array $server ): bool {
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		foreach ( [ 'HTTP_ORIGIN', 'HTTP_REFERER' ] as $header ) {
			$value = (string) ( $server[ $header ] ?? '' );

			if ( '' !== $value ) {
				return strcasecmp( (string) wp_parse_url( $value, PHP_URL_HOST ), $home ) === 0;
			}
		}

		return false;
	}

	/**
	 * A POST on a venue page. Robots are told "sent" and nothing is sent.
	 */
	public static function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || ! isset( $_POST[ self::FLAG ] ) || ! is_singular( PostTypes::VENUE ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guarded below.
			return;
		}

		$venue = get_queried_object();

		if ( ! $venue instanceof WP_Post ) {
			return;
		}

		$result = self::submit( (int) $venue->ID, wp_unslash( $_POST ), $_SERVER ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guarded and sanitised inside.

		if ( 'invalid' === $result['status'] ) {
			// The page renders again with what was typed and what is wrong.
			self::$pending = [ 'errors' => $result['errors'], 'values' => $result['values'] ];
			return;
		}

		$url = (string) get_permalink( $venue );

		if ( 'limited' === $result['status'] ) {
			wp_safe_redirect( add_query_arg( 'enquiry', 'limited', $url ) . '#enquire' );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'sent', '1', $url ) . '#enquire' );
		exit;
	}

	/**
	 * The whole thing, without the redirects, so tests can call it.
	 *
	 * @param array<string, mixed> $post   The raw POST, unslashed.
	 * @param array<string, mixed> $server $_SERVER.
	 * @return array{status: string, errors?: array<string, string>, values?: array<string, string>}
	 *         status is 'sent', 'dropped' (looked automated), 'limited', 'invalid' or 'off'.
	 */
	public static function submit( int $venue_id, array $post, array $server ): array {
		$venue = get_post( $venue_id );
		$to    = self::recipients( $venue_id );

		if ( ! $venue instanceof WP_Post || PostTypes::VENUE !== $venue->post_type || Statuses::LIVE !== $venue->post_status || [] === $to || ! Routing::is_enabled() || Visibility::is_hidden( $venue_id ) ) {
			return [ 'status' => 'off' ];
		}

		$org_id = Org::for_item( $venue_id );

		if ( ! self::same_origin( $server ) || Guard::is_robot( $post, null, self::CONTEXT ) ) {
			Log::record( 'enquiry_blocked', 'item', $venue_id, $org_id, __( 'An enquiry that looked automated was dropped.', 'dgl-platform' ), [], 0 );

			return [ 'status' => 'dropped' ];
		}

		$options = self::options( $venue_id );
		$checked = self::validate( $post, $options, new DateTimeImmutable( 'today', wp_timezone() ) );

		if ( [] !== $checked['errors'] ) {
			return [ 'status' => 'invalid', 'errors' => $checked['errors'], 'values' => $checked['values'] ];
		}

		$values = $checked['values'];
		$ip     = (string) ( $server['REMOTE_ADDR'] ?? '' );

		if ( '' !== Guard::limited( $values['email'], $ip, self::CONTEXT, self::PER_EMAIL, self::PER_IP ) ) {
			return [ 'status' => 'limited' ];
		}

		$space_label = $options[ $values['space'] ];
		$space_id    = 'any' === $values['space'] ? 0 : (int) $values['space'];

		$message = SpacesCopy::enquiry(
			venue_name: (string) get_the_title( $venue ),
			space_label: $space_label,
			values: $values,
			item_url: \DGL\Dashboard\Router::url( 'item', (string) $venue_id ),
			public_url: (string) get_permalink( $venue )
		)->for_recipients( $to )->with_headers( [ self::reply_to( $values['name'], $values['email'] ) ] );

		Mailer::send( $message );

		Log::record(
			'enquiry_sent',
			'item',
			$venue_id,
			$org_id,
			sprintf(
				/* translators: 1: a space or "Not sure yet", 2: a date. */
				__( 'Enquiry about %1$s for %2$s, sent to the venue contact.', 'dgl-platform' ),
				$space_label,
				$values['date']
			),
			[ 'space' => $space_id ],
			0
		);

		return [ 'status' => 'sent' ];
	}
}

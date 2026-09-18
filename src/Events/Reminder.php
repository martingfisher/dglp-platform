<?php
/**
 * The "is this still running?" email, two weeks before a series ends.
 *
 * Runs on the hourly expiry hook between the roll-forward and the sweep.
 * Sends once per end date: extending the series sets a new end date, so
 * the next reminder is due two weeks before that one.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Events;

use DGL\Audit\Log;
use DGL\Dashboard\Router;
use DGL\Email\Mailer;
use DGL\Email\Routing;
use DGL\Email\SeriesCopy;
use DGL\Index\ItemsTable;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

final class Reminder {

	/** How far ahead of the end date the email goes. */
	public const DAYS_BEFORE = 14;

	/** A hash of the one-click token. The token itself is only ever in the email. */
	public const META_TOKEN = 'dgl_extend_token';

	/** The end date the last reminder was for, so one end date gets one email. */
	public const META_REMINDED_FOR = 'dgl_extend_reminded_for';

	/**
	 * Email the owners of every series ending within the window.
	 *
	 * @return int How many reminders went.
	 */
	public static function send_due( int $limit = 100 ): int {
		if ( ! Routing::is_enabled() ) {
			return 0;
		}

		$now  = Series::now();
		$sent = 0;

		$due = ItemsTable::expiring_between(
			$now->format( 'Y-m-d H:i:s' ),
			$now->modify( '+' . self::DAYS_BEFORE . ' days' )->format( 'Y-m-d H:i:s' ),
			$limit
		);

		foreach ( $due as $post_id ) {
			if ( self::send_for( $post_id ) ) {
				++$sent;
			}
		}

		return $sent;
	}

	/**
	 * One series: the email, if it is due and has not gone for this end date.
	 */
	public static function send_for( int $post_id ): bool {
		if ( Statuses::LIVE !== get_post_status( $post_id ) ) {
			return false;
		}

		$rule     = Series::rule_for( $post_id );
		$lifetime = \DGL\Workflow\Lifetime::until( $post_id );

		// A dated one-off comes off on its date and nobody is asked.
		if ( null === $rule && null === $lifetime ) {
			return false;
		}

		$until_at = null !== $rule ? $rule->until : $lifetime;
		$until    = $until_at->format( 'Y-m-d' );

		if ( (string) get_post_meta( $post_id, self::META_REMINDED_FOR, true ) === $until ) {
			return false;
		}

		$to = Org::owner_emails( Org::for_item( $post_id ) );

		if ( [] === $to ) {
			// Nobody to ask. Marked as done so the sweep is not asked again every hour.
			update_post_meta( $post_id, self::META_REMINDED_FOR, $until );
			return false;
		}

		$token = self::issue_token( $post_id );

		$when = (string) wp_date( (string) get_option( 'date_format', 'j F Y' ), $until_at->getTimestamp() );

		$message = null !== $rule
			? SeriesCopy::ending_soon(
				(string) get_the_title( $post_id ),
				$when,
				Router::url( 'extend', (string) $post_id, $token ),
				Router::url( 'item', (string) $post_id ),
				(string) get_bloginfo( 'name' )
			)
			: SeriesCopy::listing_ending(
				(string) get_the_title( $post_id ),
				$when,
				Router::url( 'extend', (string) $post_id, $token ),
				Router::url( 'item', (string) $post_id ),
				(string) get_bloginfo( 'name' ),
				\DGL\Workflow\Lifetime::spell_for( (string) get_post_type( $post_id ) )
			);

		$sent = Mailer::send( $message->for_recipients( $to ) );

		update_post_meta( $post_id, self::META_REMINDED_FOR, $until );

		Log::record( 'series_reminder_sent', 'item', $post_id, Org::for_item( $post_id ), '', [ 'until' => $until ], 0 );

		return $sent;
	}

	/**
	 * A fresh single-use token for the one-click link. Only its hash is stored.
	 */
	public static function issue_token( int $post_id ): string {
		$token = bin2hex( random_bytes( 16 ) );

		update_post_meta( $post_id, self::META_TOKEN, self::hash( $token ) );

		return $token;
	}

	/**
	 * Whether a token from a link is the live one for this series.
	 */
	public static function token_is_valid( int $post_id, string $token ): bool {
		$token = trim( $token );

		if ( '' === $token || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return false;
		}

		if ( ! PostTypes::is_submittable( (string) get_post_type( $post_id ) ) ) {
			return false;
		}

		$stored = (string) get_post_meta( $post_id, self::META_TOKEN, true );

		return '' !== $stored && hash_equals( $stored, self::hash( $token ) );
	}

	/** Spend the token, so the link works once. */
	public static function clear_token( int $post_id ): void {
		delete_post_meta( $post_id, self::META_TOKEN );
	}

	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, (string) wp_salt( 'auth' ) );
	}
}

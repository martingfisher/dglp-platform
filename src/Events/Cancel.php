<?php
/**
 * Cancelling an event, or one date of a series.
 *
 * A cancelled event stays on the site for a week with a Cancelled stamp, so
 * the people who saw it learn it is off, and then the ordinary sweep takes
 * it down. A cancelled date of a series stays on the calendar and in the
 * coming dates, marked, but no longer counts as the next date. Both are
 * the owning organisation's call, applied at once, no review.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Events;

use DateTimeImmutable;
use DGL\Audit\Log;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

final class Cancel {

	/** When the whole event was cancelled, wall Y-m-d H:i:s. */
	public const META_AT = 'dgl_cancelled_at';

	/** What the organisation said about it, if anything. */
	public const META_NOTE = 'dgl_cancelled_note';

	/** Single dates of a series that are cancelled, Y-m-d each. */
	public const META_DATES = 'dgl_cancelled_dates';

	/** How long a cancelled event stays listed, marked, before it comes off. */
	public const LISTED_DAYS = 7;

	/** How many coming dates the item screen offers for cancelling. */
	public const CHOICES = 8;

	/* ---- The whole event ------------------------------------------------- */

	public static function is_cancelled( int $post_id ): bool {
		return '' !== trim( (string) get_post_meta( $post_id, self::META_AT, true ) );
	}

	public static function note( int $post_id ): string {
		return trim( (string) get_post_meta( $post_id, self::META_NOTE, true ) );
	}

	public static function at( int $post_id ): ?DateTimeImmutable {
		$raw = trim( (string) get_post_meta( $post_id, self::META_AT, true ) );

		if ( '' === $raw ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $raw, wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function cancel( int $post_id, string $note, int $actor_id ) {
		if ( PostTypes::EVENT !== get_post_type( $post_id ) || Statuses::LIVE !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'dgl_not_live', __( 'Only an event on the site can be cancelled.', 'dgl-platform' ) );
		}

		if ( self::is_cancelled( $post_id ) ) {
			return new \WP_Error( 'dgl_already', __( 'It is already marked cancelled.', 'dgl-platform' ) );
		}

		$note = sanitize_textarea_field( $note );

		update_post_meta( $post_id, self::META_AT, Series::now()->format( 'Y-m-d H:i:s' ) );

		if ( '' !== $note ) {
			update_post_meta( $post_id, self::META_NOTE, $note );
		} else {
			delete_post_meta( $post_id, self::META_NOTE );
		}

		Series::stamp( $post_id, PostTypes::EVENT );
		\DGL\Index\Sync::sync( $post_id );

		Log::record(
			'cancelled',
			'item',
			$post_id,
			Org::for_item( $post_id ),
			'' !== $note ? $note : __( 'Marked cancelled. It stays on the site for a week with a Cancelled stamp, then comes off.', 'dgl-platform' ),
			[],
			$actor_id
		);

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function reinstate( int $post_id, int $actor_id ) {
		if ( ! self::is_cancelled( $post_id ) ) {
			return new \WP_Error( 'dgl_not_cancelled', __( 'It is not marked cancelled.', 'dgl-platform' ) );
		}

		delete_post_meta( $post_id, self::META_AT );
		delete_post_meta( $post_id, self::META_NOTE );

		Series::stamp( $post_id, PostTypes::EVENT );
		\DGL\Index\Sync::sync( $post_id );

		Log::record( 'reinstated', 'item', $post_id, Org::for_item( $post_id ), __( 'Back on: the cancellation was withdrawn.', 'dgl-platform' ), [], $actor_id );

		return true;
	}

	/**
	 * The expiry a cancelled event gets: a week from the cancellation, or
	 * its own if that is sooner. Unchanged for anything not cancelled.
	 */
	public static function cap_expiry( int $post_id, ?string $expires ): ?string {
		$at = self::at( $post_id );

		if ( null === $at ) {
			return $expires;
		}

		$cap = $at->modify( '+' . self::LISTED_DAYS . ' days' )->format( 'Y-m-d H:i:s' );

		return null === $expires || $cap < $expires ? $cap : $expires;
	}

	/* ---- One date of a series -------------------------------------------- */

	/** @return string[] Y-m-d, sorted. */
	public static function dates( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_DATES, true );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$out = array_values( array_unique( array_filter( array_map( 'strval', $raw ), static fn( string $d ): bool => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) ) );
		sort( $out );

		return $out;
	}

	/**
	 * The coming dates a member may cancel: the next few that will run.
	 *
	 * @return Occurrence[]
	 */
	public static function choices( int $post_id ): array {
		return Series::next_dates( $post_id, self::CHOICES );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function cancel_date( int $post_id, string $date, int $actor_id ) {
		$rule = Series::rule_for( $post_id );

		if ( null === $rule || Statuses::LIVE !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'dgl_not_series', __( 'Only a date of a repeating event on the site can be cancelled.', 'dgl-platform' ) );
		}

		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );

		if ( false === $day || ! $rule->matches( $day ) ) {
			return new \WP_Error( 'dgl_bad_date', __( 'That is not a date this event runs on.', 'dgl-platform' ) );
		}

		if ( $rule->occurrence_on( $day )->finish() < Series::now() ) {
			return new \WP_Error( 'dgl_past', __( 'That date has already been.', 'dgl-platform' ) );
		}

		$dates = self::dates( $post_id );

		if ( in_array( $day->format( 'Y-m-d' ), $dates, true ) ) {
			return new \WP_Error( 'dgl_already', __( 'That date is already cancelled.', 'dgl-platform' ) );
		}

		$dates[] = $day->format( 'Y-m-d' );
		sort( $dates );
		update_post_meta( $post_id, self::META_DATES, $dates );

		Series::stamp( $post_id, PostTypes::EVENT );
		\DGL\Index\Sync::sync( $post_id );

		Log::record(
			'date_cancelled',
			'item',
			$post_id,
			Org::for_item( $post_id ),
			/* translators: %s: a date. */
			sprintf( __( 'Cancelled on %s. Marked on the calendar; the other dates are unchanged.', 'dgl-platform' ), (string) wp_date( 'l j F Y', $day->getTimestamp() ) ),
			[ 'date' => $day->format( 'Y-m-d' ) ],
			$actor_id
		);

		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function reinstate_date( int $post_id, string $date, int $actor_id ) {
		$dates = self::dates( $post_id );

		if ( ! in_array( $date, $dates, true ) ) {
			return new \WP_Error( 'dgl_not_cancelled', __( 'That date is not cancelled.', 'dgl-platform' ) );
		}

		$dates = array_values( array_diff( $dates, [ $date ] ) );

		if ( [] === $dates ) {
			delete_post_meta( $post_id, self::META_DATES );
		} else {
			update_post_meta( $post_id, self::META_DATES, $dates );
		}

		Series::stamp( $post_id, PostTypes::EVENT );
		\DGL\Index\Sync::sync( $post_id );

		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );

		Log::record(
			'date_reinstated',
			'item',
			$post_id,
			Org::for_item( $post_id ),
			/* translators: %s: a date. */
			sprintf( __( 'Back on for %s.', 'dgl-platform' ), false === $day ? $date : (string) wp_date( 'l j F Y', $day->getTimestamp() ) ),
			[ 'date' => $date ],
			$actor_id
		);

		return true;
	}
}

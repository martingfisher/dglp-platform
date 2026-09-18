<?php
/**
 * A repeating event as WordPress sees it.
 *
 * The one place that writes an item's expiry and next-occurrence stamps, so
 * the three callers that used to carry their own copy of that logic (submit,
 * approve and restore in Transition; an applied edit in Revisions) cannot
 * drift, and the roll-forward on cron is the same code path again.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Events;

use DateTimeImmutable;
use DGL\Index\ItemsTable;
use DGL\Meta;
use DGL\PostTypes;
use DGL\Schema\FieldRegistry;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

final class Series {

	/** The stored rule as a Rule, or null for a one-off or a non-event. */
	public static function rule_for( int $post_id ): ?Rule {
		if ( PostTypes::EVENT !== get_post_type( $post_id ) ) {
			return null;
		}

		$repeat = get_post_meta( $post_id, 'dgl_repeat', true );

		if ( ! is_array( $repeat ) || [] === $repeat ) {
			return null;
		}

		$rule = Rule::from_meta(
			$repeat,
			(string) get_post_meta( $post_id, 'dgl_start_datetime', true ),
			(string) get_post_meta( $post_id, 'dgl_end_datetime', true ),
			wp_timezone()
		);

		$cancelled = Cancel::dates( $post_id );

		return null === $rule || [] === $cancelled ? $rule : $rule->with_cancelled( $cancelled );
	}

	public static function is_series( int $post_id ): bool {
		return null !== self::rule_for( $post_id );
	}

	/** Now, in the site's own timezone, which is what every stored date is in. */
	public static function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', wp_timezone() );
	}

	/**
	 * Write the expiry and next-occurrence stamps from what the item carries.
	 *
	 * A series expires at the end of its last day (the rule's "until"); its
	 * next stamp is the next occurrence that has not finished. When nothing
	 * is left, the next stamp goes and the expiry is set to now, so the very
	 * next sweep takes it off the site through the ordinary expiry path. A
	 * one-off's next stamp is its start.
	 */
	public static function stamp( int $post_id, string $post_type ): void {
		$values = [];

		foreach ( FieldRegistry::for_type( $post_type ) as $field ) {
			$values[ $field->key ] = get_post_meta( $post_id, $field->meta_key(), true );
		}

		$expires = FieldRegistry::expiry_for( $post_type, $values );
		$next    = null;

		// An undated type is listed for a spell instead. Set on the day it
		// goes live, moved by an extension, read here.
		if ( null === $expires ) {
			\DGL\Workflow\Lifetime::ensure( $post_id, $post_type );
			$expires = \DGL\Workflow\Lifetime::expiry_for( $post_id );
		}

		if ( PostTypes::EVENT === $post_type ) {
			$rule = self::rule_for( $post_id );

			if ( null !== $rule ) {
				$coming = Occurrences::next( $rule, self::now(), 1 );

				if ( [] === $coming ) {
					$expires = current_time( 'mysql' );
				} else {
					$next = $coming[0]->wall();
				}
			} else {
				$start = trim( (string) ( $values['start_datetime'] ?? '' ) );
				$next  = '' !== $start ? $start : null;
			}
		}

		// A cancelled event stays up, marked, for a short while so the people
		// who saw it learn it is off; then the ordinary sweep takes it down.
		$expires = Cancel::cap_expiry( $post_id, $expires );

		if ( null === $expires ) {
			delete_post_meta( $post_id, Meta::ITEM_EXPIRES_AT );
		} else {
			update_post_meta( $post_id, Meta::ITEM_EXPIRES_AT, $expires );
		}

		if ( null === $next ) {
			delete_post_meta( $post_id, Meta::ITEM_NEXT_AT );
		} else {
			update_post_meta( $post_id, Meta::ITEM_NEXT_AT, $next );
		}
	}

	/** How much longer one extension gives, in months, from today. */
	public const EXTEND_MONTHS = 6;

	/** The item screen offers an extension once the end is this close, in weeks. */
	public const EXTEND_WINDOW_WEEKS = 8;

	/**
	 * Whether a series is live and close enough to its end to be extended.
	 */
	public static function can_extend( int $post_id ): bool {
		$rule = self::rule_for( $post_id );

		if ( null === $rule || Statuses::LIVE !== get_post_status( $post_id ) ) {
			return false;
		}

		return $rule->until <= self::now()->modify( '+' . self::EXTEND_WINDOW_WEEKS . ' weeks' );
	}

	/**
	 * Keep a series going: the end moves to six months from today.
	 *
	 * Six months from today rather than six on from the old end, so a
	 * series extended early does not run further ahead than a new one
	 * could. Applies at once, no review; logged so the owners see it in
	 * their notifications.
	 *
	 * @param int    $actor_id Who did it. 0 for the email link, which carries no session.
	 * @return true|\WP_Error
	 */
	public static function extend( int $post_id, int $actor_id, string $how = '' ) {
		$rule = self::rule_for( $post_id );

		if ( null === $rule ) {
			return new \WP_Error( 'dgl_not_a_series', __( 'This event does not repeat, so there is nothing to extend.', 'dgl-platform' ) );
		}

		if ( Statuses::LIVE !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'dgl_not_live', __( 'This event is not on the site. Restore it from your dashboard instead.', 'dgl-platform' ) );
		}

		$until  = self::now()->setTime( 0, 0, 0 )->modify( '+' . self::EXTEND_MONTHS . ' months' );
		$repeat = (array) get_post_meta( $post_id, 'dgl_repeat', true );

		$repeat['until'] = $until->format( 'Y-m-d' );

		update_post_meta( $post_id, 'dgl_repeat', $repeat );
		delete_post_meta( $post_id, Reminder::META_TOKEN );

		self::stamp( $post_id, PostTypes::EVENT );

		\DGL\Audit\Log::record(
			'series_extended',
			'item',
			$post_id,
			\DGL\Org\Org::for_item( $post_id ),
			sprintf(
				/* translators: %s: a date. */
				__( 'Now listed until %s.', 'dgl-platform' ),
				(string) wp_date( (string) get_option( 'date_format', 'j F Y' ), $until->getTimestamp() )
			) . ( '' !== $how ? ' ' . $how : '' ),
			[ 'until' => $repeat['until'] ],
			$actor_id
		);

		return true;
	}

	/** The last listed date in words, or '' for a one-off. */
	public static function until_wording( int $post_id ): string {
		$rule = self::rule_for( $post_id );

		return null === $rule ? '' : (string) wp_date( (string) get_option( 'date_format', 'j F Y' ), $rule->until->getTimestamp() );
	}

	/**
	 * The coming dates, for the public page and the item screen.
	 *
	 * @return Occurrence[]
	 */
	public static function next_dates( int $post_id, int $count = 5, bool $with_cancelled = false ): array {
		$rule = self::rule_for( $post_id );

		return null === $rule ? [] : Occurrences::next( $rule, self::now(), $count, $with_cancelled );
	}

	/**
	 * Move every live event's next stamp forward. Runs hourly before the
	 * expiry sweep, so a series that has run out is expired in the same run.
	 *
	 * @return int How many were restamped.
	 */
	public static function roll_forward( int $limit = 200 ): int {
		$done = 0;

		foreach ( ItemsTable::events_to_roll( current_time( 'mysql' ), $limit ) as $post_id ) {
			self::stamp( $post_id, PostTypes::EVENT );
			++$done;
		}

		return $done;
	}
}

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

		return Rule::from_meta(
			$repeat,
			(string) get_post_meta( $post_id, 'dgl_start_datetime', true ),
			(string) get_post_meta( $post_id, 'dgl_end_datetime', true ),
			wp_timezone()
		);
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

	/**
	 * The coming dates, for the public page and the item screen.
	 *
	 * @return Occurrence[]
	 */
	public static function next_dates( int $post_id, int $count = 5 ): array {
		$rule = self::rule_for( $post_id );

		return null === $rule ? [] : Occurrences::next( $rule, self::now(), $count );
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

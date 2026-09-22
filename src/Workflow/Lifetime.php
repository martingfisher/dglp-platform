<?php
/**
 * How long an undated item stays on the site.
 *
 * An event or a training course has a date and comes off after it. A news
 * story has none, so without this it stayed up until somebody archived it,
 * and nobody did. It is listed for a fixed spell from the day it goes live;
 * two weeks before the end the owners are asked whether it is still current,
 * and one click keeps it for another spell. Same reminder, token and confirm
 * page as a repeating event.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Workflow;

use DateTimeImmutable;
use DGL\Audit\Log;
use DGL\Events\Reminder;
use DGL\Events\Series;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

final class Lifetime {

	/** The last listed date, Y-m-d, on the post. Moved by an extension. */
	public const META_UNTIL = 'dgl_listed_until';

	/** The item screen offers an extension once the end is this close, in weeks. */
	public const EXTEND_WINDOW_WEEKS = 8;

	/**
	 * Days on the site per type. A type not listed here expires on its dates
	 * or not at all.
	 *
	 * Empty since 0.22.0. News carried a 90-day spell from 0.10.0; Martin
	 * withdrew it on 22 September 2026 because a story that comes off after
	 * three months forfeits the long-tail search value the site is built
	 * for. A story now stays up until its organisation archives it. The
	 * machinery stays so a spell can be given back to a type in one line.
	 *
	 * @return array<string, int>
	 */
	public static function days(): array {
		return [];
	}

	/**
	 * Withdraw a spell a type used to have. Run once by the schema upgrade.
	 *
	 * Every item of a type with no spell loses its end date, its reminder
	 * marks and its expiry stamp; one the sweep had already taken off for
	 * running out of days is put back on the site, because that was the
	 * only thing that ever expired it. Idempotent: a second run finds
	 * nothing to do.
	 *
	 * @param list<string> $post_types Types to release; default news.
	 * @return array{released: int, restored: int}
	 */
	public static function release( array $post_types = [ PostTypes::NEWS ] ): array {
		$done = [ 'released' => 0, 'restored' => 0 ];

		foreach ( $post_types as $post_type ) {
			if ( null !== self::days_for( $post_type ) ) {
				continue;
			}

			// Named statuses, not 'any': 'any' skips statuses hidden from search,
			// and the expired one is, so the very items to restore would be missed.
			$ids = get_posts( [ 'post_type' => $post_type, 'post_status' => Statuses::all(), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ] );

			foreach ( $ids as $id ) {
				$id       = (int) $id;
				$had_end  = '' !== trim( (string) get_post_meta( $id, self::META_UNTIL, true ) );
				$expired  = Statuses::EXPIRED === get_post_status( $id );

				if ( ! $had_end && ! $expired ) {
					continue;
				}

				delete_post_meta( $id, self::META_UNTIL );
				delete_post_meta( $id, Reminder::META_TOKEN );
				delete_post_meta( $id, Reminder::META_REMINDED_FOR );

				if ( $expired ) {
					wp_update_post( [ 'ID' => $id, 'post_status' => Statuses::LIVE ] );
					Log::record( 'listing_restored', 'item', $id, Org::for_item( $id ), __( 'Back on the site: news no longer comes off after a fixed spell.', 'dgl-platform' ), [], 0 );
					++$done['restored'];
				}

				Series::stamp( $id, $post_type );
				++$done['released'];
			}
		}

		return $done;
	}

	public static function days_for( string $post_type ): ?int {
		return self::days()[ $post_type ] ?? null;
	}

	/** In words, for copy: "three months". */
	public static function spell_for( string $post_type ): string {
		$days = self::days_for( $post_type ) ?? 0;

		if ( $days >= 28 && 0 === $days % 30 ) {
			/* translators: %d: number of months. */
			return sprintf( _n( '%d month', '%d months', intdiv( $days, 30 ), 'dgl-platform' ), intdiv( $days, 30 ) );
		}

		/* translators: %d: number of days. */
		return sprintf( _n( '%d day', '%d days', $days, 'dgl-platform' ), $days );
	}

	/**
	 * The last listed date, or null when the item has none or is not a type
	 * with a lifetime.
	 */
	public static function until( int $post_id ): ?DateTimeImmutable {
		if ( null === self::days_for( (string) get_post_type( $post_id ) ) ) {
			return null;
		}

		$raw = trim( (string) get_post_meta( $post_id, self::META_UNTIL, true ) );

		if ( '' === $raw ) {
			return null;
		}

		try {
			return ( new DateTimeImmutable( $raw, wp_timezone() ) )->setTime( 23, 59, 59 );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Give a live item its first end date if it has none: today plus its spell.
	 * Called from the stamp, so approval sets it and nothing else has to.
	 */
	public static function ensure( int $post_id, string $post_type ): void {
		$days = self::days_for( $post_type );

		if ( null === $days || Statuses::LIVE !== get_post_status( $post_id ) ) {
			return;
		}

		if ( '' !== trim( (string) get_post_meta( $post_id, self::META_UNTIL, true ) ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_UNTIL, Series::now()->modify( '+' . $days . ' days' )->format( 'Y-m-d' ) );
	}

	/** The expiry stamp this item should carry, or null. */
	public static function expiry_for( int $post_id ): ?string {
		$until = self::until( $post_id );

		return null === $until ? null : $until->format( 'Y-m-d H:i:s' );
	}

	public static function can_extend( int $post_id ): bool {
		$until = self::until( $post_id );

		if ( null === $until || Statuses::LIVE !== get_post_status( $post_id ) ) {
			return false;
		}

		return $until <= Series::now()->modify( '+' . self::EXTEND_WINDOW_WEEKS . ' weeks' );
	}

	/**
	 * Keep it listed: the end moves to today plus the type's spell.
	 *
	 * @return true|\WP_Error
	 */
	public static function extend( int $post_id, int $actor_id, string $how = '' ) {
		$post_type = (string) get_post_type( $post_id );
		$days      = self::days_for( $post_type );

		if ( null === $days ) {
			return new \WP_Error( 'dgl_no_lifetime', __( 'This one comes off the site on its own date, so there is nothing to extend.', 'dgl-platform' ) );
		}

		if ( Statuses::LIVE !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'dgl_not_live', __( 'This is not on the site. Restore it from your dashboard instead.', 'dgl-platform' ) );
		}

		$until = Series::now()->setTime( 0, 0, 0 )->modify( '+' . $days . ' days' );

		update_post_meta( $post_id, self::META_UNTIL, $until->format( 'Y-m-d' ) );
		delete_post_meta( $post_id, Reminder::META_TOKEN );

		Series::stamp( $post_id, $post_type );

		Log::record(
			'listing_extended',
			'item',
			$post_id,
			Org::for_item( $post_id ),
			sprintf(
				/* translators: %s: a date. */
				__( 'Now listed until %s.', 'dgl-platform' ),
				(string) wp_date( (string) get_option( 'date_format', 'j F Y' ), $until->getTimestamp() )
			) . ( '' !== $how ? ' ' . $how : '' ),
			[ 'until' => $until->format( 'Y-m-d' ) ],
			$actor_id
		);

		return true;
	}

	/** The last listed date in words, or '' when there is none. */
	public static function until_wording( int $post_id ): string {
		$until = self::until( $post_id );

		return null === $until ? '' : (string) wp_date( (string) get_option( 'date_format', 'j F Y' ), $until->getTimestamp() );
	}

	/** What one extension from today would reach, in words. */
	public static function next_until_wording( string $post_type ): string {
		$days = self::days_for( $post_type ) ?? 0;

		return (string) wp_date( (string) get_option( 'date_format', 'j F Y' ), Series::now()->setTime( 0, 0, 0 )->modify( '+' . $days . ' days' )->getTimestamp() );
	}

	/**
	 * Live items from before lifetimes existed get one from the day they went
	 * live, so nothing that was already up comes down the moment this ships.
	 *
	 * @return int How many were given an end date.
	 */
	public static function backfill(): int {
		$done = 0;

		foreach ( self::days() as $post_type => $days ) {
			$ids = get_posts( [ 'post_type' => $post_type, 'post_status' => Statuses::LIVE, 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ] );

			foreach ( $ids as $id ) {
				if ( '' !== trim( (string) get_post_meta( (int) $id, self::META_UNTIL, true ) ) ) {
					continue;
				}

				$from = trim( (string) get_post_meta( (int) $id, \DGL\Meta::ITEM_APPROVED_AT, true ) );
				$from = '' !== $from ? $from : (string) get_post_field( 'post_date', (int) $id );

				try {
					$start = new DateTimeImmutable( $from, wp_timezone() );
				} catch ( \Exception $e ) {
					$start = Series::now();
				}

				// Never in the past: something live today gets at least two weeks.
				$until = max( $start->modify( '+' . $days . ' days' ), Series::now()->modify( '+14 days' ) );

				update_post_meta( (int) $id, self::META_UNTIL, $until->format( 'Y-m-d' ) );
				Series::stamp( (int) $id, $post_type );
				++$done;
			}
		}

		return $done;
	}
}

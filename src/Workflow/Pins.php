<?php
/**
 * Featuring a live item for a week or a fortnight.
 *
 * A moderator pins it; it sits first on its public list with a small
 * "Featured" stamp; the hourly hook takes the pin off when the time is up.
 * Never longer than fourteen days, so nothing is featured by neglect.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Workflow;

use DateTimeImmutable;
use DGL\Audit\Log;
use DGL\Events\Series;
use DGL\Org\Org;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

final class Pins {

	/** The wall-clock moment the pin lapses, Y-m-d H:i:s, on the post. */
	public const META_UNTIL = 'dgl_pinned_until';

	/** @return int[] */
	public static function choices(): array {
		return [ 7, 14 ];
	}

	/** The moment a pin lapses, or null when the item is not featured. */
	public static function until( int $post_id ): ?DateTimeImmutable {
		$raw = trim( (string) get_post_meta( $post_id, self::META_UNTIL, true ) );

		if ( '' === $raw ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $raw, wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/** Featured right now: live, pinned, and the pin has not lapsed. */
	public static function is_pinned( int $post_id ): bool {
		$until = self::until( $post_id );

		return null !== $until && $until > Series::now() && Statuses::LIVE === get_post_status( $post_id );
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function pin( int $post_id, int $days, int $actor_id ) {
		if ( ! in_array( $days, self::choices(), true ) ) {
			return new \WP_Error( 'dgl_bad_days', __( 'Seven or fourteen days.', 'dgl-platform' ) );
		}

		if ( Statuses::LIVE !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'dgl_not_live', __( 'Only something on the site can be featured.', 'dgl-platform' ) );
		}

		$until = Series::now()->modify( '+' . $days . ' days' );

		update_post_meta( $post_id, self::META_UNTIL, $until->format( 'Y-m-d H:i:s' ) );

		Log::record(
			'pinned',
			'item',
			$post_id,
			Org::for_item( $post_id ),
			sprintf(
				/* translators: 1: days, 2: a date. */
				__( 'Featured at the top of its list for %1$d days, until %2$s.', 'dgl-platform' ),
				$days,
				(string) wp_date( (string) get_option( 'date_format', 'j F Y' ), $until->getTimestamp() )
			),
			[ 'days' => $days, 'until' => $until->format( 'Y-m-d H:i:s' ) ],
			$actor_id
		);

		return true;
	}

	/**
	 * @param int    $actor_id 0 when the pin lapsed on its own.
	 */
	public static function unpin( int $post_id, int $actor_id, string $why = '' ): bool {
		if ( null === self::until( $post_id ) ) {
			return false;
		}

		delete_post_meta( $post_id, self::META_UNTIL );

		Log::record(
			'unpinned',
			'item',
			$post_id,
			Org::for_item( $post_id ),
			'' !== $why ? $why : __( 'No longer featured.', 'dgl-platform' ),
			[],
			$actor_id
		);

		return true;
	}

	/**
	 * Take off every pin whose time is up. Hourly.
	 *
	 * @return int How many lapsed.
	 */
	public static function lapse(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value < %s", self::META_UNTIL, Series::now()->format( 'Y-m-d H:i:s' ) ) );
		$done = 0;

		foreach ( array_map( 'intval', (array) $ids ) as $post_id ) {
			if ( self::unpin( $post_id, 0, __( 'Its time as a featured item ended.', 'dgl-platform' ) ) ) {
				++$done;
			}
		}

		return $done;
	}
}

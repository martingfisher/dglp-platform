<?php
/**
 * Building and sending the digests.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email\Digest;

use DateTimeImmutable;
use DateTimeZone;
use DGL\Email\Mailer;
use DGL\Email\Routing;
use DGL\Index\ItemsTable;
use DGL\Meta;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Taxonomies;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The thing that actually sends a digest.
 *
 * Everything it decides has been decided elsewhere and tested without a
 * database: {@see Frequency} says who is owed one, {@see Matcher} says what
 * goes in it, {@see Subscription} says whether it may lawfully be sent at all.
 * This part is querying, assembly and one call per subscriber.
 *
 * It is deliberately capable of doing nothing. A run that matches no content
 * sends no email, but the slot is still marked handled, so a quiet week
 * produces silence and the next digest still covers one week.
 */
final class Runner {

	/** How many subscribers one run will process for a given cadence. */
	public const BATCH = 200;

	/** How many items one digest will carry. */
	public const MAX_ITEMS = 25;

	/**
	 * Send every digest that is owed for a cadence.
	 *
	 * @param bool $dry_run Build everything and send nothing.
	 * @return array{considered:int, sent:int, skipped_empty:int, failed:int, items:int}
	 */
	public static function run( string $frequency, ?DateTimeImmutable $now = null, bool $dry_run = false ): array {
		$now   = $now ?? self::now();
		$stats = [
			'considered'    => 0,
			'sent'          => 0,
			'skipped_empty' => 0,
			'failed'        => 0,
			'items'         => 0,
		];

		if ( ! Frequency::is_valid( $frequency ) || ! Store::exists() ) {
			return $stats;
		}

		/*
		 * Checked once, here, rather than per subscriber. A site with sending
		 * off should do no work at all, not build two hundred digests and
		 * throw them away.
		 */
		if ( ! $dry_run && ! Routing::is_enabled() ) {
			return $stats;
		}

		foreach ( Store::due( $frequency, $now, self::BATCH ) as $subscription ) {
			++$stats['considered'];

			$slot   = Frequency::due_slot( $frequency, $subscription->last_sent_at, $subscription->consent_at, $now, wp_timezone() ) ?? $now;
			$result = self::send_one( $subscription, $now, $dry_run, $slot );

			$stats[ $result['outcome'] ] = ( $stats[ $result['outcome'] ] ?? 0 ) + 1;
			$stats['items']             += $result['items'];
		}

		return $stats;
	}

	/**
	 * Build and send one subscriber's digest for a slot.
	 *
	 * The slot is the send time being handled; the digest covers the one
	 * period before it. A manual send passes now, so it covers the period up
	 * to this minute. The slot is stamped on a real send and on a quiet
	 * period alike, never on a dry run or a failure.
	 *
	 * @return array{outcome:string, items:int}
	 */
	public static function send_one( Subscription $subscription, DateTimeImmutable $now, bool $dry_run = false, ?DateTimeImmutable $slot = null ): array {
		if ( ! $subscription->is_sendable() ) {
			return [ 'outcome' => 'skipped_empty', 'items' => 0 ];
		}

		$slot    = $slot ?? $now;
		$stamp   = $slot->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$matched = Matcher::match( $subscription, self::candidates( $subscription, $slot ) );

		if ( ! Matcher::should_send( $matched ) ) {
			if ( ! $dry_run ) {
				Store::mark_sent( $subscription->user_id, $stamp );
			}

			return [ 'outcome' => 'skipped_empty', 'items' => 0 ];
		}

		$matched = array_slice( $matched, 0, self::MAX_ITEMS );
		$items   = self::rows( $matched );

		if ( [] === $items ) {
			return [ 'outcome' => 'skipped_empty', 'items' => 0 ];
		}

		$message = Copy::digest(
			items: $items,
			frequency: $subscription->frequency,
			site_name: (string) get_bloginfo( 'name' ),
			unsubscribe_url: self::unsubscribe_url( $subscription->unsubscribe_token ),
			preferences_url: \DGL\Dashboard\Router::url( 'profile', 'email' ),
			browse_url: home_url( '/' )
		);

		if ( $dry_run ) {
			return [ 'outcome' => 'sent', 'items' => count( $items ) ];
		}

		// Mail clients show their own unsubscribe control from these, and
		// Gmail's one-click POSTs to the same link the footer carries.
		$unsubscribe = self::unsubscribe_url( $subscription->unsubscribe_token );
		$message     = $message->with_headers(
			[
				'List-Unsubscribe: <' . $unsubscribe . '>',
				'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
			]
		);

		$sent = Mailer::send( $message->for_recipients( [ $subscription->email ] ) );

		if ( ! $sent ) {
			/*
			 * The stamp is not moved on a failure. The alternative is a
			 * subscriber who never learns what happened in the window their
			 * one failed send covered.
			 */
			return [ 'outcome' => 'failed', 'items' => 0 ];
		}

		Store::mark_sent( $subscription->user_id, $stamp );

		return [ 'outcome' => 'sent', 'items' => count( $items ) ];
	}

	/**
	 * Everything published in the one period before a slot, as the plain
	 * arrays {@see Matcher} reads. The Matcher then drops anything from
	 * before the subscriber's last digest, so a manual send mid-week never
	 * repeats what Tuesday's already carried.
	 *
	 * @return array<int, array{id:int, type:string, topics:int[], org_id:int, approved_at:string}>
	 */
	public static function candidates( Subscription $subscription, ?DateTimeImmutable $slot = null ): array {
		$slot  = $slot ?? self::now();
		$since = Frequency::window_start( $subscription->frequency, $slot, wp_timezone() )
			->setTimezone( new DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );

		/*
		 * Switched-off types are filtered here rather than in the Matcher.
		 * The Matcher is pure and should not know what this release happens to
		 * have enabled, and a subscription saved before a type was switched off
		 * still lists it. Without this, turning a type off would hide it from
		 * every screen and keep posting it to everybody who had ever ticked it.
		 */
		$types = array_values( array_intersect( $subscription->types, PostTypes::enabled_keys() ) );

		if ( [] === $types ) {
			return [];
		}

		$ids = ItemsTable::published_since( $types, $since, self::MAX_ITEMS * 4 );

		if ( [] === $ids ) {
			return [];
		}

		$candidates = [];

		foreach ( $ids as $id ) {
			$candidates[] = [
				'id'          => $id,
				'type'        => (string) get_post_type( $id ),
				'topics'      => self::topics( $id ),
				'org_id'      => Org::for_item( $id ),
				'approved_at' => (string) get_post_meta( $id, Meta::ITEM_APPROVED_AT, true ),
			];
		}

		return $candidates;
	}

	/**
	 * @return int[]
	 */
	private static function topics( int $post_id ): array {
		$terms = wp_get_post_terms( $post_id, Taxonomies::TOPIC, [ 'fields' => 'ids' ] );

		return is_array( $terms ) ? array_map( 'intval', $terms ) : [];
	}

	/**
	 * The rows as the email renders them.
	 *
	 * @param int[] $ids
	 * @return array<int, array{title:string, meta:string, url:string, summary:string}>
	 */
	public static function rows( array $ids ): array {
		$definitions = PostTypes::definitions();
		$order       = array_flip( [ PostTypes::EVENT, PostTypes::NEWS, PostTypes::TRAINING, PostTypes::VOLUNTEERING, PostTypes::GRANT ] );
		$rows        = [];

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$type   = (string) $post->post_type;
			$org_id = Org::for_item( (int) $post->ID );
			$org    = $org_id > 0 ? (string) get_the_title( $org_id ) : '';
			$date   = in_array( $type, [ PostTypes::EVENT, PostTypes::TRAINING ], true ) ? \DGL\Frontend\Cards::date_parts( $post ) : null;
			$parts  = [];

			/*
			 * The line under the title, like the row on the site: for an
			 * event the weekday, time and place; for a course the weekday and
			 * where it runs; for a story the organisation and the day it
			 * was posted. The date itself is on the leaf beside it.
			 */
			if ( null !== $date ) {
				$parts[] = $date['weekday'];

				if ( '' !== $date['time'] ) {
					$parts[] = $date['time'];
				}

				$where = PostTypes::EVENT === $type
					? \DGL\Frontend\Frontend::where( $post )
					: (string) \DGL\Frontend\Frontend::value( $post, 'location' );

				if ( 'online' === (string) \DGL\Frontend\Frontend::value( $post, 'delivery' ) ) {
					$where = __( 'Online', 'dgl-platform' );
				}

				if ( '' !== $where ) {
					$parts[] = $where;
				}
			} elseif ( PostTypes::NEWS === $type ) {
				$parts[] = \DGL\Frontend\Cards::posted( $post );
			}

			if ( '' !== $org ) {
				$parts[] = $org;
			}

			$rows[] = [
				'title'   => (string) get_the_title( $post ),
				'meta'    => implode( ' · ', $parts ),
				'url'     => (string) ( get_permalink( $post ) ?: '' ),
				'summary' => self::summary( (int) $post->ID ),
				'section' => (string) ( $definitions[ $type ]['plural'] ?? '' ),
				'date'    => null === $date ? null : [ 'month' => $date['month'], 'day' => $date['day'], 'iso' => $date['iso'] ],
				'sort'    => ( $order[ $type ] ?? 9 ) . '|' . ( null === $date ? '' : $date['iso'] ),
			];
		}

		// Events first, soonest first; then news; then training, soonest first.
		usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['sort'], $b['sort'] ) );

		return $rows;
	}

	/**
	 * The one or two sentences the member wrote for listings and emails.
	 *
	 * Falls back to nothing rather than to the body. The body is rich text and
	 * an arbitrary slice of it ends mid-sentence, mid-tag, or on a heading.
	 */
	private static function summary( int $post_id ): string {
		$summary = trim( (string) get_post_meta( $post_id, 'summary', true ) );

		return '' !== $summary ? $summary : '';
	}

	public static function unsubscribe_url( string $token ): string {
		return \DGL\Dashboard\Router::url( 'unsubscribe', $token );
	}

	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
}

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
 * sends no email and does not move the last-sent stamp, so a quiet month
 * produces silence rather than an empty newsletter, and the next digest covers
 * the whole gap.
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

			$result = self::send_one( $subscription, $now, $dry_run );

			$stats[ $result['outcome'] ] = ( $stats[ $result['outcome'] ] ?? 0 ) + 1;
			$stats['items']             += $result['items'];
		}

		return $stats;
	}

	/**
	 * Build and send one subscriber's digest.
	 *
	 * @return array{outcome:string, items:int}
	 */
	public static function send_one( Subscription $subscription, DateTimeImmutable $now, bool $dry_run = false ): array {
		if ( ! $subscription->is_sendable() ) {
			return [ 'outcome' => 'skipped_empty', 'items' => 0 ];
		}

		$matched = Matcher::match( $subscription, self::candidates( $subscription ) );

		if ( ! Matcher::should_send( $matched ) ) {
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

		$sent = Mailer::send( $message->for_recipients( [ $subscription->email ] ) );

		if ( ! $sent ) {
			/*
			 * The stamp is not moved on a failure. The alternative is a
			 * subscriber who never learns what happened in the window their
			 * one failed send covered.
			 */
			return [ 'outcome' => 'failed', 'items' => 0 ];
		}

		Store::mark_sent( $subscription->user_id, $now->format( 'Y-m-d H:i:s' ) );

		return [ 'outcome' => 'sent', 'items' => count( $items ) ];
	}

	/**
	 * Everything published since this subscriber last heard from us, as the
	 * plain arrays {@see Matcher} reads.
	 *
	 * The window is deliberately generous when there is no last-sent stamp: a
	 * brand new subscriber gets the last month rather than everything ever
	 * posted, which would be a first email of several hundred items.
	 *
	 * @return array<int, array{id:int, type:string, topics:int[], org_id:int, approved_at:string}>
	 */
	public static function candidates( Subscription $subscription ): array {
		$since = $subscription->last_sent_at;

		if ( null === $since || '' === $since ) {
			$since = self::now()->modify( '-1 month' )->format( 'Y-m-d H:i:s' );
		}

		$ids = ItemsTable::published_since( $subscription->types, $since, self::MAX_ITEMS * 4 );

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
		$rows        = [];

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$org_id = Org::for_item( (int) $post->ID );
			$parts  = [];

			$type_label = (string) ( $definitions[ $post->post_type ]['singular'] ?? '' );

			if ( '' !== $type_label ) {
				$parts[] = $type_label;
			}

			if ( $org_id > 0 ) {
				$parts[] = (string) get_the_title( $org_id );
			}

			$rows[] = [
				'title'   => (string) get_the_title( $post ),
				'meta'    => implode( ' - ', $parts ),
				'url'     => (string) ( get_permalink( $post ) ?: '' ),
				'summary' => self::summary( (int) $post->ID ),
			];
		}

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

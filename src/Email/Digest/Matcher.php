<?php
/**
 * Choosing which items belong in one subscriber's digest.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email\Digest;

/**
 * Matches published items against what a subscriber asked for.
 *
 * Pure: it is handed candidate items as plain arrays rather than querying for
 * them, so the selection rules can be exercised exhaustively without a
 * database. {@see Runner} does the querying.
 *
 * The rules are deliberately conservative. A digest that includes something the
 * member did not ask for is a complaint, and at a few thousand subscribers a
 * complaint rate is a deliverability problem.
 */
final class Matcher {

	/**
	 * Which of the candidates belong in this digest.
	 *
	 * @param Subscription $subscription The subscriber's preferences.
	 * @param array<int, array{id:int, type:string, topics:int[], org_id:int, approved_at:string}> $candidates
	 * @return int[] Item IDs, in the order given.
	 */
	public static function match( Subscription $subscription, array $candidates ): array {
		if ( ! $subscription->is_sendable() ) {
			return [];
		}

		$matched = [];

		foreach ( $candidates as $item ) {
			if ( self::wants( $subscription, $item ) ) {
				$matched[] = (int) $item['id'];
			}
		}

		return $matched;
	}

	/**
	 * Whether one item belongs in this digest.
	 *
	 * @param array{id:int, type:string, topics:int[], org_id:int, approved_at:string} $item
	 */
	public static function wants( Subscription $subscription, array $item ): bool {
		// Type is an explicit opt-in. An empty list means none, never all.
		if ( ! in_array( $item['type'], $subscription->types, true ) ) {
			return false;
		}

		/*
		 * Topics are a filter rather than an opt-in: choosing no topic means
		 * "any topic", which is what someone who ignores the topic control
		 * expects. Choosing some means only those.
		 */
		if ( ! empty( $subscription->topic_ids ) ) {
			$overlap = array_intersect(
				array_map( 'intval', $subscription->topic_ids ),
				array_map( 'intval', $item['topics'] )
			);

			if ( empty( $overlap ) ) {
				return false;
			}
		}

		// Nobody needs an email about the thing they posted this morning.
		if ( ! $subscription->include_own_org
			&& $subscription->org_id > 0
			&& (int) $item['org_id'] === $subscription->org_id
		) {
			return false;
		}

		// Only things approved since their last digest.
		if ( null !== $subscription->last_sent_at && '' !== $subscription->last_sent_at ) {
			if ( (string) $item['approved_at'] <= $subscription->last_sent_at ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether this digest is worth sending.
	 *
	 * An empty digest is never sent, and the last-sent stamp is deliberately not
	 * advanced when nothing matched. A monthly subscriber with a quiet quarter
	 * then gets one digest covering the whole quarter, rather than three empty
	 * emails or a silently skipped window.
	 *
	 * @param int[] $matched
	 */
	public static function should_send( array $matched ): bool {
		return count( $matched ) > 0;
	}
}

<?php
/**
 * The member area sidebar.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

use DGL\Access\UserContext;
use DGL\Index\ItemsTable;
use DGL\PostTypes;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the sidebar from what the person is actually allowed to see.
 *
 * The review queue only appears for moderators, rather than appearing and then
 * refusing. A menu item that exists to say no is a menu item that wastes
 * somebody's time.
 */
final class Navigation {

	/**
	 * @return array<int, array{label:string, url:string, count:?int, section:string, current:bool}>
	 */
	public static function items( UserContext $user ): array {
		$first  = Router::segments()[0] ?? '';
		$second = Router::segments()[1] ?? '';
		$counts = null !== $user->org_id ? self::counts_by_type( $user->org_id ) : [];

		/*
		 * Review-team accounts are not members and submit nothing, so listing
		 * five content types at zero would be five lines of noise on every
		 * screen they use.
		 */
		$has_own_work = null !== $user->org_id;

		$items = [
			[
				'label'   => __( 'Dashboard', 'dgl-platform' ),
				'url'     => Router::url(),
				'count'   => null,
				'section' => '',
				'current' => '' === $first,
			],
		];

		if ( $has_own_work ) {
			foreach ( PostTypes::enabled() as $post_type => $def ) {
				$items[] = [
					'label'   => $def['plural'],
					'url'     => Router::url( $def['slug'] ),
					'count'   => $counts[ $post_type ] ?? 0,
					'section' => __( 'Your submissions', 'dgl-platform' ),
					'current' => $def['slug'] === $first,
				];
			}

			$items[] = [
				'label'   => __( 'Archive', 'dgl-platform' ),
				'url'     => Router::url( 'archive' ),
				'count'   => null,
				'section' => __( 'Account', 'dgl-platform' ),
				'current' => 'archive' === $first,
			];
		}

		$alerts = self::attention_count( $user );

		$items[] = [
			'label'   => __( 'Notifications', 'dgl-platform' ),
			'url'     => Router::url( 'notifications' ),
			// Zero shows no badge rather than a nought. A count is a prompt to
			// act, and "0" is a prompt to act on nothing.
			'count'   => $alerts > 0 ? $alerts : null,
			'section' => __( 'Account', 'dgl-platform' ),
			'current' => 'notifications' === $first,
		];

		$items[] = [
			'label'   => __( 'Organisation and profile', 'dgl-platform' ),
			'url'     => Router::url( 'profile' ),
			'count'   => null,
			'section' => __( 'Account', 'dgl-platform' ),
			'current' => 'profile' === $first,
		];

		if ( $has_own_work ) {
			$items[] = [
				'label'   => __( 'Help', 'dgl-platform' ),
				'url'     => Router::url( 'help' ),
				'count'   => null,
				'section' => __( 'Account', 'dgl-platform' ),
				'current' => 'help' === $first && 'team' !== $second,
			];
		}

		if ( $user->is_moderator() ) {
			$items[] = [
				'label'   => __( 'Review queue', 'dgl-platform' ),
				'url'     => Router::url( 'review' ),
				// Counted, not measured off a capped page: a badge that stops at
				// 500 stops being a number and starts being a guess.
				'count'   => ItemsTable::queue_count() + count( \DGL\Org\Profile::awaiting_review() ) + \DGL\Joining\Store::awaiting_count(),
				'section' => __( 'Review team', 'dgl-platform' ),
				'current' => 'review' === $first && ! in_array( $second, [ 'decided', 'reports', 'orgs' ], true ),
			];

			$items[] = [
				'label'   => __( 'Organisations', 'dgl-platform' ),
				'url'     => Router::url( 'review', 'orgs' ),
				'count'   => null,
				'section' => __( 'Review team', 'dgl-platform' ),
				'current' => 'review' === $first && 'orgs' === $second,
			];

			$items[] = [
				'label'   => __( 'Approved', 'dgl-platform' ),
				'url'     => Router::url( 'review', 'decided' ),
				'count'   => null,
				'section' => __( 'Review team', 'dgl-platform' ),
				'current' => 'review' === $first && 'decided' === $second,
			];

			$items[] = [
				'label'   => __( 'Reports', 'dgl-platform' ),
				'url'     => Router::url( 'review', 'reports' ),
				'count'   => null,
				'section' => __( 'Review team', 'dgl-platform' ),
				'current' => 'review' === $first && 'reports' === $second,
			];

			$items[] = [
				'label'   => __( 'Team guide', 'dgl-platform' ),
				'url'     => Router::url( 'help', 'team' ),
				'count'   => null,
				'section' => __( 'Review team', 'dgl-platform' ),
				'current' => 'help' === $first && 'team' === $second,
			];
		}

		return $items;
	}

	/**
	 * How many things are waiting on this member.
	 *
	 * Today that means submissions a moderator has sent back, which is the only
	 * state that actually requires the member to do something. It is a real
	 * number rather than a placeholder.
	 *
	 * When the notifications screen lands with a read/unread store behind it,
	 * this becomes the unread count. Showing "3 unread" now, against nothing
	 * that records reading, would be a badge nobody could ever clear.
	 */
	public static function attention_count( UserContext $user ): int {
		if ( null === $user->org_id ) {
			return 0;
		}

		$counts = ItemsTable::counts_for_org( $user->org_id );

		return (int) ( $counts[ Statuses::CHANGES ] ?? 0 );
	}

	/**
	 * How many items the organisation has of each type.
	 *
	 * @return array<string, int>
	 */
	private static function counts_by_type( int $org_id ): array {
		$counts = [];

		foreach ( PostTypes::enabled_keys() as $post_type ) {
			$counts[ $post_type ] = count( ItemsTable::for_org( $org_id, [ $post_type ], null, 500 ) );
		}

		return $counts;
	}
}

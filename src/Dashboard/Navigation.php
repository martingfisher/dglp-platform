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
			foreach ( PostTypes::definitions() as $post_type => $def ) {
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

		$items[] = [
			'label'   => __( 'Notifications', 'dgl-platform' ),
			'url'     => Router::url( 'notifications' ),
			'count'   => null,
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

		if ( $user->is_moderator() ) {
			$items[] = [
				'label'   => __( 'Review queue', 'dgl-platform' ),
				'url'     => Router::url( 'review' ),
				'count'   => count( ItemsTable::queue( null, 500 ) ),
				'section' => __( 'Review team', 'dgl-platform' ),
				'current' => 'review' === $first,
			];
		}

		return $items;
	}

	/**
	 * How many items the organisation has of each type.
	 *
	 * @return array<string, int>
	 */
	private static function counts_by_type( int $org_id ): array {
		$counts = [];

		foreach ( PostTypes::submittable() as $post_type ) {
			$counts[ $post_type ] = count( ItemsTable::for_org( $org_id, [ $post_type ], null, 500 ) );
		}

		return $counts;
	}
}

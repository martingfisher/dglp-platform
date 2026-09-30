<?php
/**
 * What happens to a venue's spaces when the venue changes state.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Spaces;

use DGL\Audit\Log;
use DGL\Index\ItemsTable;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Statuses;
use DGL\Workflow\Plan;
use DGL\Workflow\StateMachine;
use DGL\Workflow\Transition;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A space cannot outlive its venue on the site. When a venue is archived,
 * taken down or refused, the spaces under it go the same way, through the
 * same state machine and as the same person, so every move is audited.
 * Restoring the venue brings its archived spaces back for review too.
 *
 * One email, not nine: the mailer is told to stay quiet while a cascade
 * runs, because the member has already been told about the venue and the
 * spaces went with it.
 */
final class Cascade {

	/** Whether a cascade is in progress, for the mailer. */
	private static bool $active = false;

	public static function init(): void {
		// After the mailer (10) has sent the venue's own email.
		add_action( 'dgl_item_transitioned', [ self::class, 'on_transition' ], 20, 4 );
	}

	public static function is_active(): bool {
		return self::$active;
	}

	public static function on_transition( int $post_id, Plan $plan, int $actor_id, string $note = '' ): void {
		if ( self::$active ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! Link::is_venue( $post ) ) {
			return;
		}

		$rule = self::rule_for( $plan->action );

		if ( null === $rule ) {
			return;
		}

		[ $from, $action ] = $rule;

		$children = ItemsTable::children( $post_id, $from, 500 );

		if ( [] === $children ) {
			return;
		}

		self::$active = true;
		$moved        = 0;

		try {
			foreach ( $children as $child_id ) {
				$result = Transition::apply( $child_id, $action, $actor_id, $note );

				if ( true === $result ) {
					++$moved;
				}
			}
		} finally {
			self::$active = false;
		}

		if ( $moved > 0 ) {
			Log::record(
				'cascaded',
				'item',
				$post_id,
				Org::for_item( $post_id ),
				sprintf(
					/* translators: 1: a number, 2: what happened, e.g. "archived". */
					_n( '%1$d space %2$s with the venue.', '%1$d spaces %2$s with the venue.', $moved, 'dgl-platform' ),
					$moved,
					self::word( $action )
				),
				[ 'action' => $action, 'spaces' => $moved ],
				$actor_id
			);
		}
	}

	/**
	 * Which children move, and how, for a venue action. Null when the
	 * action leaves the spaces alone (a submit, an approval, a change
	 * request: those are about the venue's own words).
	 *
	 * @return array{0: string[], 1: string}|null Child statuses that move, and the action applied.
	 */
	public static function rule_for( string $action ): ?array {
		return match ( $action ) {
			StateMachine::ARCHIVE   => [ [ Statuses::DRAFT, Statuses::CHANGES, Statuses::LIVE, Statuses::EXPIRED, Statuses::REJECTED ], StateMachine::ARCHIVE ],
			StateMachine::TAKE_DOWN => [ [ Statuses::LIVE ], StateMachine::TAKE_DOWN ],
			StateMachine::REJECT    => [ [ Statuses::PENDING ], StateMachine::REJECT ],
			StateMachine::RESTORE   => [ [ Statuses::ARCHIVED ], StateMachine::RESTORE ],
			default                 => null,
		};
	}

	private static function word( string $action ): string {
		return match ( $action ) {
			StateMachine::ARCHIVE   => __( 'archived', 'dgl-platform' ),
			StateMachine::TAKE_DOWN => __( 'taken down', 'dgl-platform' ),
			StateMachine::REJECT    => __( 'refused', 'dgl-platform' ),
			StateMachine::RESTORE   => __( 'restored and sent for review', 'dgl-platform' ),
			default                 => $action,
		};
	}

	/**
	 * Move a venue's spaces to another organisation with it. Every status,
	 * because a space of one organisation under a venue of another is
	 * a thing nobody can edit.
	 *
	 * @return int How many spaces moved.
	 */
	public static function reassign_children( int $venue_id, int $org_id, int $actor_id ): int {
		$moved = 0;

		foreach ( ItemsTable::children( $venue_id, null, 500 ) as $child_id ) {
			$child = get_post( $child_id );

			if ( ! $child instanceof WP_Post || PostTypes::SPACE !== $child->post_type ) {
				continue;
			}

			update_post_meta( $child_id, \DGL\Meta::ITEM_ORG, $org_id );
			\DGL\Index\Sync::sync( $child_id );

			Log::record(
				'moved_with_venue',
				'item',
				$child_id,
				$org_id,
				sprintf(
					/* translators: %s: the venue's name. */
					__( 'Moved with its venue, %s.', 'dgl-platform' ),
					(string) get_the_title( $venue_id )
				),
				[ 'venue' => $venue_id, 'to' => $org_id ],
				$actor_id
			);

			++$moved;
		}

		return $moved;
	}
}

<?php
/**
 * What has happened to an organisation's submissions.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

use DGL\Audit\Log;
use DGL\PostTypes;
use DGL\Workflow\StateMachine;

defined( 'ABSPATH' ) || exit;

/**
 * The audit trail, read back to the member in their own words.
 *
 * Wireframe 1j calls this Notifications and describes it as "every decision the
 * team makes on your submissions". Every one of those decisions is already
 * recorded, by the state machine, in the audit log. So this is not a new store
 * to keep in step with anything: it is the trail that already exists, filtered
 * to one organisation and phrased for the person who submitted rather than for
 * whoever reviewed.
 *
 * Which is why there is no read/unread yet. Marking something read is a new
 * fact about a person, and that needs somewhere to live. Until it does, the
 * badge counts what actually needs doing, which is the number a member would
 * want anyway.
 */
final class Notifications {

	/**
	 * Actions worth telling a member about.
	 *
	 * Deliberately not everything in the trail. A member does not need to see
	 * that their index row was rebuilt, and an audit log read out in full is
	 * noise that buries the four entries that matter.
	 *
	 * @return array<string, array{title: string, tone: string}>
	 */
	public static function readable(): array {
		return [
			StateMachine::SUBMIT          => [ 'title' => __( 'Sent for review', 'dgl-platform' ), 'tone' => 'quiet' ],
			StateMachine::APPROVE         => [ 'title' => __( 'Approved and published', 'dgl-platform' ), 'tone' => 'good' ],
			StateMachine::REQUEST_CHANGES => [ 'title' => __( 'The team asked for a change', 'dgl-platform' ), 'tone' => 'attention' ],
			StateMachine::REJECT          => [ 'title' => __( 'Not approved', 'dgl-platform' ), 'tone' => 'bad' ],
			StateMachine::TAKE_DOWN       => [ 'title' => __( 'Taken off the site', 'dgl-platform' ), 'tone' => 'bad' ],
			StateMachine::REOPEN          => [ 'title' => __( 'Being looked at again', 'dgl-platform' ), 'tone' => 'quiet' ],
			StateMachine::EXPIRE          => [ 'title' => __( 'Came off the site on its date', 'dgl-platform' ), 'tone' => 'quiet' ],
			StateMachine::ARCHIVE         => [ 'title' => __( 'Archived', 'dgl-platform' ), 'tone' => 'quiet' ],
			StateMachine::RESTORE         => [ 'title' => __( 'Restored from the archive', 'dgl-platform' ), 'tone' => 'quiet' ],
			'series_extended'             => [ 'title' => __( 'Kept on the site for another six months', 'dgl-platform' ), 'tone' => 'good' ],
			'listing_extended'            => [ 'title' => __( 'Kept on the site for another three months', 'dgl-platform' ), 'tone' => 'good' ],
			'schedule_changed'            => [ 'title' => __( 'Dates and times changed', 'dgl-platform' ), 'tone' => 'quiet' ],
			'org_change_approved'         => [ 'title' => __( 'Your organisation details were accepted', 'dgl-platform' ), 'tone' => 'good' ],
			'org_change_rejected'         => [ 'title' => __( 'Your organisation details were not accepted', 'dgl-platform' ), 'tone' => 'bad' ],
			'org_status_changed'          => [ 'title' => __( 'Your organisation\'s status changed', 'dgl-platform' ), 'tone' => 'quiet' ],
			'trust_changed'               => [ 'title' => __( 'Your review settings changed', 'dgl-platform' ), 'tone' => 'quiet' ],
			'trust_revoked'               => [ 'title' => __( 'Your submissions are being reviewed again', 'dgl-platform' ), 'tone' => 'attention' ],
			'member_removed'              => [ 'title' => __( 'Somebody was removed from your organisation', 'dgl-platform' ), 'tone' => 'quiet' ],
		];
	}

	/**
	 * One organisation's history, ready for the screen.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_org( int $org_id, int $limit = 40, int $offset = 0 ): array {
		if ( $org_id <= 0 ) {
			return [];
		}

		$readable = self::readable();
		$rows     = [];

		foreach ( Log::for_org_actions( $org_id, array_keys( $readable ), $limit, $offset ) as $entry ) {
			$action = (string) ( $entry['action'] ?? '' );

			if ( ! isset( $readable[ $action ] ) ) {
				continue;
			}

			$object_id = (int) ( $entry['object_id'] ?? 0 );
			$type      = (string) ( $entry['object_type'] ?? '' );
			$is_item   = in_array( $type, [ 'item', 'revision' ], true );

			$post = $is_item && $object_id > 0 ? get_post( $object_id ) : null;

			/*
			 * A revision is an edit to something, and the thing is what the
			 * member recognises. Naming the revision would show them a title
			 * they have never seen under a heading they do not expect.
			 */
			if ( null !== $post && PostTypes::REVISION === $post->post_type ) {
				$parent = get_post( (int) $post->post_parent );
				$post   = $parent ?? $post;
			}

			$rows[] = [
				'action'  => $action,
				'title'   => $readable[ $action ]['title'],
				'tone'    => $readable[ $action ]['tone'],
				'is_edit' => null !== $post && $is_item && PostTypes::REVISION === (string) get_post_type( $object_id ),
				'subject' => null !== $post ? (string) get_the_title( $post ) : '',
				'url'     => null !== $post ? Router::url( 'item', (string) $post->ID ) : '',
				'note'    => (string) ( $entry['note'] ?? '' ),
				'when'    => (string) ( $entry['logged_at'] ?? '' ),
				'who'     => self::who( (int) ( $entry['actor_id'] ?? 0 ) ),
			];
		}

		return $rows;
	}

	/** How many readable entries the organisation has, for the pager. */
	public static function count_for_org( int $org_id ): int {
		return $org_id <= 0 ? 0 : Log::count_for_org( $org_id, array_keys( self::readable() ) );
	}

	/**
	 * Who did it, from the member's point of view.
	 *
	 * A member does not need the reviewer's name to understand what happened,
	 * and naming an individual on a refusal invites them to take it personally.
	 * Their own colleagues are named, because that is useful.
	 */
	private static function who( int $actor_id ): string {
		if ( $actor_id <= 0 ) {
			return __( 'Automatic', 'dgl-platform' );
		}

		$user = get_userdata( $actor_id );

		if ( ! $user ) {
			return __( 'The DGLP team', 'dgl-platform' );
		}

		$org = \DGL\Org\Org::for_user( $actor_id );

		return null !== $org
			? (string) $user->display_name
			: __( 'The DGLP team', 'dgl-platform' );
	}
}

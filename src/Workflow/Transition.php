<?php
/**
 * Carrying out a workflow action against WordPress.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Workflow;

use DGL\Access\Access;
use DGL\Access\Policy;
use DGL\Audit\Log;
use DGL\Index\Sync;
use DGL\Meta;
use DGL\Org\Org;
use DGL\Org\Trust;
use DGL\PostTypes;
use DGL\Schema\FieldRegistry;
use DGL\Statuses;
use WP_Error;
use WP_Post;

/**
 * The one way a submission changes state.
 *
 * Nothing else in the plugin should call `wp_update_post()` to move an item
 * between statuses. Everything funnels through here so that the permission
 * check, the legality check, the audit entry, the index update and the
 * notification always happen together. A status changed by any other route is a
 * status change with no trail behind it.
 *
 * The thinking lives in {@see Planner}; this is the part that carries it out.
 */
final class Transition {

	/** Which policy question each workflow action asks. */
	private const POLICY_FOR = [
		StateMachine::SUBMIT          => Policy::SUBMIT_ITEM,
		StateMachine::APPROVE         => Policy::MODERATE_ITEM,
		StateMachine::REQUEST_CHANGES => Policy::MODERATE_ITEM,
		StateMachine::REJECT          => Policy::MODERATE_ITEM,
		StateMachine::TAKE_DOWN       => Policy::TAKE_DOWN_ITEM,
		StateMachine::ARCHIVE         => Policy::ARCHIVE_ITEM,
		StateMachine::RESTORE         => Policy::RESTORE_ITEM,
	];

	/**
	 * Apply an action to an item.
	 *
	 * @param int    $post_id  The item.
	 * @param string $action   A {@see StateMachine} action.
	 * @param int    $actor_id Who is doing it. 0 means the system, for expiry.
	 * @param string $note     Message to the member, where the action carries one.
	 * @return true|WP_Error
	 */
	public static function apply( int $post_id, string $action, int $actor_id, string $note = '' ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! PostTypes::is_reviewable( $post->post_type ) ) {
			return new WP_Error( 'dgl_not_an_item', __( 'That is not a submission.', 'dgl-platform' ) );
		}

		// A pending edit moves through the same states as the item it replaces.
		$is_edit = PostTypes::REVISION === $post->post_type;

		$system = 0 === $actor_id;

		// Expiry is the only action the system takes on its own.
		if ( $system && StateMachine::EXPIRE !== $action ) {
			return new WP_Error( 'dgl_no_actor', __( 'That action needs somebody to be doing it.', 'dgl-platform' ) );
		}

		if ( ! $system ) {
			$permission = self::POLICY_FOR[ $action ] ?? null;

			if ( null === $permission || ! Access::can( $actor_id, $permission, $post_id ) ) {
				/*
				 * Somebody who can already see the item gets told what is
				 * actually wrong, because "you cannot submit something that is
				 * pending review" is useful and "you cannot do that" is not.
				 * Anyone else gets the flat refusal, so the error text never
				 * becomes a way to probe the status of another organisation's
				 * unpublished work.
				 */
				if ( Access::can( $actor_id, Policy::VIEW_ITEM, $post_id )
					&& ! StateMachine::can( $action, (string) $post->post_status )
				) {
					return self::illegal( $action, (string) $post->post_status );
				}

				return new WP_Error( 'dgl_not_allowed', __( 'You cannot do that to this item.', 'dgl-platform' ) );
			}
		}

		$org_id = Org::for_item( $post_id );
		$trust  = Org::trust_level( $org_id > 0 ? $org_id : null );
		$staff  = ! $system && Access::user_context( $actor_id )->is_moderator();

		$plan = Planner::plan( $action, (string) $post->post_status, $trust, $staff, $is_edit );

		if ( null === $plan ) {
			return self::illegal( $action, (string) $post->post_status );
		}

		$note = trim( $note );

		if ( $plan->requires_note && '' === $note ) {
			return new WP_Error(
				'dgl_note_required',
				__( 'Say what needs to change. The member sees this, and a decision without a reason just produces another email.', 'dgl-platform' )
			);
		}

		return self::carry_out( $post, $plan, $actor_id, $note, $org_id );
	}

	/**
	 * The error for a move that is not legal from the current status.
	 */
	private static function illegal( string $action, string $status ): WP_Error {
		return new WP_Error(
			'dgl_illegal_transition',
			sprintf(
				/* translators: 1: action, 2: current status label. */
				__( 'You cannot %1$s something that is %2$s.', 'dgl-platform' ),
				$action,
				Statuses::label( $status )
			)
		);
	}

	/**
	 * Perform the plan. Ordered so the audit entry is never written for a status
	 * change that did not happen.
	 *
	 * @return true|WP_Error
	 */
	/**
	 * True while this class is moving something.
	 *
	 * Read by {@see \DGL\Admin\Guard}, which records any status change that
	 * happens without it. Without that, a status altered directly in wp-admin
	 * leaves no trail at all, and an audit log with holes in it is worse than
	 * none: it is one you believe.
	 */
	public static bool $in_progress = false;

	private static function carry_out( WP_Post $post, Plan $plan, int $actor_id, string $note, int $org_id ) {
		$post_id = (int) $post->ID;
		$now     = current_time( 'mysql', true );

		self::$in_progress = true;

		$updated = wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => $plan->to,
			],
			true
		);

		self::$in_progress = false;

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( $plan->stamp_submitted ) {
			update_post_meta( $post_id, Meta::ITEM_SUBMITTED_AT, $now );
		}

		if ( $plan->stamp_approved ) {
			update_post_meta( $post_id, Meta::ITEM_APPROVED_AT, $now );
		}

		if ( $plan->recompute_expiry ) {
			/*
			 * A revision has no schema of its own, so its dates are read
			 * against its parent's field list. Without this the expiry stamp on
			 * an edit is always null and an approved edit loses the end date.
			 */
			$schema_type = PostTypes::REVISION === $post->post_type
				? Revisions::type_of( $post_id )
				: (string) $post->post_type;

			if ( '' !== $schema_type ) {
				self::recompute_expiry( $post_id, $schema_type );
			}
		}

		if ( $plan->revokes_trust && $org_id > 0 && Trust::MODERATED !== Trust::normalise( get_post_meta( $org_id, Meta::ORG_TRUST, true ) ) ) {
			update_post_meta( $org_id, Meta::ORG_TRUST, Trust::MODERATED );

			Log::record(
				'trust_revoked',
				'org',
				$org_id,
				$org_id,
				sprintf(
					/* translators: %s: the action that caused it. */
					__( 'Trust returned to moderated automatically after %s.', 'dgl-platform' ),
					$plan->action
				),
				[],
				$actor_id
			);
		}

		Sync::sync( $post_id );

		Log::record(
			$plan->action,
			PostTypes::REVISION === $post->post_type ? 'revision' : 'item',
			$post_id,
			$org_id,
			$note,
			[ 'status' => [ $plan->from, $plan->to ] ],
			$actor_id
		);

		/**
		 * Fires after a submission changes state.
		 *
		 * Notifications hang off this rather than being called inline, so a mail
		 * failure can never roll back a decision a moderator already made.
		 *
		 * @param int    $post_id  The item.
		 * @param Plan   $plan     What happened.
		 * @param int    $actor_id Who did it, 0 for the system.
		 * @param string $note     The message to the member, if any.
		 */
		do_action( 'dgl_item_transitioned', $post_id, $plan, $actor_id, $note );

		return true;
	}

	/**
	 * Rebuild the expiry stamp from whichever date the type expires on.
	 */
	private static function recompute_expiry( int $post_id, string $post_type ): void {
		// Series is the one writer of the expiry and next-occurrence stamps.
		\DGL\Events\Series::stamp( $post_id, $post_type );
	}

	/**
	 * Expire everything that is past its date. Driven by cron.
	 *
	 * Batched because the host caps execution at 30 seconds, and a site with a
	 * few thousand listings will eventually have a day where many fall due at
	 * once.
	 *
	 * @return int How many were expired.
	 */
	public static function run_expiry_sweep( int $limit = 100 ): int {
		// Stored expiries are the site's wall clock, so they are compared with the site's wall clock, not UTC.
		$due  = \DGL\Index\ItemsTable::due_for_expiry( current_time( 'mysql' ), $limit );
		$done = 0;

		foreach ( $due as $post_id ) {
			if ( true === self::apply( $post_id, StateMachine::EXPIRE, 0 ) ) {
				++$done;
			}
		}

		return $done;
	}
}

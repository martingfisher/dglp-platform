<?php
/**
 * Builds the plan for a workflow action.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Workflow;

use DGL\Org\Trust;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an action into its full set of consequences.
 *
 * Pure. No WordPress, no database, no clock. Given the same inputs it always
 * produces the same plan, which is what lets the whole workflow be exercised
 * in a test rather than only in a browser.
 */
final class Planner {

	/**
	 * Plan a transition, or null when the move is illegal from this status.
	 *
	 * @param string $action         A {@see StateMachine} action.
	 * @param string $from           Current status.
	 * @param int    $trust          The owning organisation's trust level.
	 * @param bool   $actor_is_staff Whether a moderator or administrator is acting.
	 * @param bool   $is_edit        Whether the thing moving is an edit to
	 *                               already-published content.
	 */
	public static function plan( string $action, string $from, int $trust = Trust::MODERATED, bool $actor_is_staff = false, bool $is_edit = false ): ?Plan {
		$to = StateMachine::next( $action, $from, $trust, $is_edit );

		if ( null === $to ) {
			return null;
		}

		return match ( $action ) {
			StateMachine::SUBMIT          => self::submit( $from, $to ),
			StateMachine::APPROVE         => self::approve( $from, $to ),
			StateMachine::REQUEST_CHANGES => self::request_changes( $from, $to ),
			StateMachine::REJECT          => self::reject( $from, $to ),
			StateMachine::TAKE_DOWN       => self::take_down( $from, $to ),
			StateMachine::EXPIRE          => self::expire( $from, $to ),
			StateMachine::ARCHIVE         => self::archive( $from, $to, $actor_is_staff ),
			StateMachine::RESTORE         => self::restore( $from, $to ),
			StateMachine::REOPEN          => self::reopen( $from, $to ),
			default                       => null,
		};
	}

	/**
	 * A trusted organisation's submission skips the queue, so the member is told
	 * it is live rather than told it is waiting, and it joins the spot-check
	 * list so the team can still read it afterwards.
	 *
	 * Either way the member gets a receipt. They have just handed something to
	 * somebody else and lost the ability to edit it, and the confirmation screen
	 * they saw is gone the moment they close the tab. Without an email there is
	 * nothing in writing that says it arrived.
	 */
	private static function submit( string $from, string $to ): Plan {
		$published = Statuses::LIVE === $to;

		return new Plan(
			action: StateMachine::SUBMIT,
			from: $from,
			to: $to,
			notify: [ Plan::NOTIFY_MEMBER, Plan::NOTIFY_MODERATORS ],
			message_key: $published ? 'published_on_trust' : 'submitted',
			stamp_submitted: true,
			stamp_approved: $published,
			recompute_expiry: true,
			spot_check: $published,
		);
	}

	private static function approve( string $from, string $to ): Plan {
		return new Plan(
			action: StateMachine::APPROVE,
			from: $from,
			to: $to,
			notify: [ Plan::NOTIFY_MEMBER ],
			message_key: 'approved',
			stamp_approved: true,
			recompute_expiry: true,
		);
	}

	/**
	 * A change request without a reason produces a support email rather than a
	 * fix, so the note is mandatory at this level and not left to the interface.
	 */
	private static function request_changes( string $from, string $to ): Plan {
		return new Plan(
			action: StateMachine::REQUEST_CHANGES,
			from: $from,
			to: $to,
			notify: [ Plan::NOTIFY_MEMBER ],
			message_key: 'changes_requested',
			requires_note: true,
		);
	}

	private static function reject( string $from, string $to ): Plan {
		return new Plan(
			action: StateMachine::REJECT,
			from: $from,
			to: $to,
			notify: [ Plan::NOTIFY_MEMBER ],
			message_key: 'rejected',
			requires_note: true,
			revokes_trust: true,
		);
	}

	/**
	 * A refused item looked at again. The member is told, because "not
	 * approved" has just stopped being the last word on it.
	 */
	private static function reopen( string $from, string $to ): Plan {
		return new Plan(
			action: StateMachine::REOPEN,
			from: $from,
			to: $to,
			notify: [ Plan::NOTIFY_MEMBER ],
			message_key: 'reopened',
		);
	}

	/**
	 * Pulling live content back into the queue. Trust goes with it: an
	 * organisation whose published work had to be removed is no longer one whose
	 * submissions should bypass review.
	 */
	private static function take_down( string $from, string $to ): Plan {
		return new Plan(
			action: StateMachine::TAKE_DOWN,
			from: $from,
			to: $to,
			notify: [ Plan::NOTIFY_MEMBER, Plan::NOTIFY_MODERATORS ],
			message_key: 'taken_down',
			requires_note: true,
			revokes_trust: true,
		);
	}

	/**
	 * Expiry is the system acting, not a person, so there is nothing to note and
	 * nobody to hold responsible. The member is told because their listing has
	 * gone and they may want to relist it.
	 */
	private static function expire( string $from, string $to ): Plan {
		return new Plan(
			action: StateMachine::EXPIRE,
			from: $from,
			to: $to,
			notify: [ Plan::NOTIFY_MEMBER ],
			message_key: 'expired',
		);
	}

	/**
	 * A member tidying their own archive needs no email telling them what they
	 * just did. A moderator archiving somebody else's work does.
	 */
	private static function archive( string $from, string $to, bool $actor_is_staff ): Plan {
		return new Plan(
			action: StateMachine::ARCHIVE,
			from: $from,
			to: $to,
			notify: $actor_is_staff ? [ Plan::NOTIFY_MEMBER ] : [],
			message_key: $actor_is_staff ? 'archived_by_team' : '',
			requires_note: $actor_is_staff,
		);
	}

	/**
	 * Restoring puts it back in the queue rather than straight back on the site,
	 * so the team reads it again before the public does.
	 */
	private static function restore( string $from, string $to ): Plan {
		return new Plan(
			action: StateMachine::RESTORE,
			from: $from,
			to: $to,
			notify: [ Plan::NOTIFY_MODERATORS ],
			message_key: 'restored',
			stamp_submitted: true,
			recompute_expiry: true,
		);
	}
}

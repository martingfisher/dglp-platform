<?php
/**
 * Transition planning tests.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Org\Trust;
use DGL\Statuses;
use DGL\Workflow\Plan;
use DGL\Workflow\Planner;
use DGL\Workflow\StateMachine;

Harness::group( 'Illegal moves produce no plan' );

Harness::assert_same( null, Planner::plan( StateMachine::APPROVE, Statuses::DRAFT ), 'approving a draft has no plan' );
Harness::assert_same( null, Planner::plan( StateMachine::SUBMIT, Statuses::PENDING ), 'resubmitting a pending item has no plan' );
Harness::assert_same( null, Planner::plan( 'invent_an_action', Statuses::DRAFT ), 'an unknown action has no plan' );

Harness::group( 'Submitting under moderation' );

$plan = Planner::plan( StateMachine::SUBMIT, Statuses::DRAFT, Trust::MODERATED );
Harness::assert_same( Statuses::PENDING, $plan->to, 'a moderated submission waits in the queue' );
Harness::assert_true( $plan->notifies( Plan::NOTIFY_MODERATORS ), 'the review team is told there is something to read' );
Harness::assert_false( $plan->notifies( Plan::NOTIFY_MEMBER ), 'the member is not emailed about their own click' );
Harness::assert_true( $plan->stamp_submitted, 'the submission time is recorded' );
Harness::assert_false( $plan->stamp_approved, 'nothing is approved yet' );
Harness::assert_true( $plan->recompute_expiry, 'the expiry date is rebuilt from the submitted dates' );
Harness::assert_false( $plan->spot_check, 'a moderated item needs no spot check, it was read' );
Harness::assert_false( $plan->publishes(), 'a moderated submission does not reach the public' );

Harness::group( 'Submitting on trust' );

$plan = Planner::plan( StateMachine::SUBMIT, Statuses::DRAFT, Trust::TRUSTED );
Harness::assert_same( Statuses::LIVE, $plan->to, 'a trusted submission publishes' );
Harness::assert_true( $plan->publishes(), 'it counts as reaching the public' );
Harness::assert_true( $plan->notifies( Plan::NOTIFY_MEMBER ), 'the member is told it is live, not that it is waiting' );
Harness::assert_true( $plan->notifies( Plan::NOTIFY_MODERATORS ), 'the team still hears about it' );
Harness::assert_true( $plan->spot_check, 'it joins the spot-check list, because nobody read it first' );
Harness::assert_true( $plan->stamp_approved, 'approval time is stamped even though no person approved it' );
Harness::assert_same( 'published_on_trust', $plan->message_key, 'the member gets the published message, not the submitted one' );

$plan = Planner::plan( StateMachine::SUBMIT, Statuses::DRAFT, Trust::TRUSTED_EDITS );
Harness::assert_same( Statuses::PENDING, $plan->to, 'trusted-for-edits does not skip review on a new item' );
Harness::assert_false( $plan->spot_check, 'and so needs no spot check' );

Harness::group( 'Moderator decisions' );

$plan = Planner::plan( StateMachine::APPROVE, Statuses::PENDING );
Harness::assert_true( $plan->publishes(), 'approval publishes' );
Harness::assert_true( $plan->notifies( Plan::NOTIFY_MEMBER ), 'the member is told' );
Harness::assert_false( $plan->requires_note, 'approving needs no explanation' );
Harness::assert_false( $plan->revokes_trust, 'approval leaves trust alone' );
Harness::assert_true( $plan->stamp_approved, 'the approval time is recorded' );

$plan = Planner::plan( StateMachine::REQUEST_CHANGES, Statuses::PENDING );
Harness::assert_true( $plan->requires_note, 'asking for changes without saying what is blocked' );
Harness::assert_false( $plan->revokes_trust, 'asking for a change is not a black mark' );
Harness::assert_true( $plan->notifies( Plan::NOTIFY_MEMBER ), 'the member is told what to fix' );

$plan = Planner::plan( StateMachine::REJECT, Statuses::PENDING );
Harness::assert_true( $plan->requires_note, 'refusing without a reason is blocked' );
Harness::assert_true( $plan->revokes_trust, 'a rejection drops the organisation back to moderated' );

Harness::group( 'Taking live content down' );

$plan = Planner::plan( StateMachine::TAKE_DOWN, Statuses::LIVE );
Harness::assert_same( Statuses::PENDING, $plan->to, 'it goes back into the queue, not into the bin' );
Harness::assert_true( $plan->requires_note, 'a takedown has to say why' );
Harness::assert_true( $plan->revokes_trust, 'an organisation whose live work was pulled loses its bypass' );
Harness::assert_true( $plan->notifies( Plan::NOTIFY_MEMBER ), 'the member is told their listing has gone' );
Harness::assert_true( $plan->notifies( Plan::NOTIFY_MODERATORS ), 'the rest of the team sees it too' );

Harness::group( 'Expiry is the system, not a person' );

$plan = Planner::plan( StateMachine::EXPIRE, Statuses::LIVE );
Harness::assert_false( $plan->requires_note, 'expiry needs no note, nobody decided it' );
Harness::assert_false( $plan->revokes_trust, 'expiring is not a failure' );
Harness::assert_true( $plan->notifies( Plan::NOTIFY_MEMBER ), 'the member is told so they can relist' );
Harness::assert_false( $plan->notifies( Plan::NOTIFY_MODERATORS ), 'the team does not need an email per expiry' );

Harness::group( 'Archiving depends on who did it' );

$by_member = Planner::plan( StateMachine::ARCHIVE, Statuses::LIVE, Trust::MODERATED, false );
Harness::assert_same( [], $by_member->notify, 'a member gets no email telling them what they just did' );
Harness::assert_false( $by_member->requires_note, 'and needs to explain themselves to nobody' );

$by_staff = Planner::plan( StateMachine::ARCHIVE, Statuses::LIVE, Trust::MODERATED, true );
Harness::assert_true( $by_staff->notifies( Plan::NOTIFY_MEMBER ), 'the team archiving somebody else s work does tell them' );
Harness::assert_true( $by_staff->requires_note, 'and has to say why' );

Harness::group( 'Restoring re-enters the queue' );

$plan = Planner::plan( StateMachine::RESTORE, Statuses::ARCHIVED, Trust::TRUSTED );
Harness::assert_same( Statuses::PENDING, $plan->to, 'even a trusted organisation goes through review on restore' );
Harness::assert_true( $plan->stamp_submitted, 'the queue sees it as newly submitted' );
Harness::assert_true( $plan->notifies( Plan::NOTIFY_MODERATORS ), 'the team is told there is something back in the queue' );

Harness::group( 'Every legal move has a plan, and the two agree' );

foreach ( StateMachine::table() as $action => $moves ) {
	foreach ( array_keys( $moves ) as $from ) {
		foreach ( Trust::all() as $trust ) {
			foreach ( [ true, false ] as $staff ) {
				$plan = Planner::plan( $action, $from, $trust, $staff );

				Harness::assert_true(
					$plan instanceof Plan,
					sprintf( '%s from %s (trust %d, staff %s) has a plan', $action, $from, $trust, $staff ? 'yes' : 'no' )
				);

				Harness::assert_same(
					StateMachine::next( $action, $from, $trust ),
					$plan->to,
					sprintf( '%s from %s (trust %d) lands where the state machine says', $action, $from, $trust )
				);

				Harness::assert_true(
					in_array( $plan->to, Statuses::all(), true ),
					sprintf( '%s from %s lands on a real status', $action, $from )
				);

				// A note is only ever demanded where the state machine says so.
				if ( $plan->requires_note && ! $staff ) {
					Harness::assert_true(
						StateMachine::requires_note( $action ),
						sprintf( '%s demands a note only when the lifecycle says it should', $action )
					);
				}
			}
		}
	}
}

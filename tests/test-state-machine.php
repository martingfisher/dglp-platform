<?php
/**
 * Workflow transition tests.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Org\Trust;
use DGL\Statuses;
use DGL\Workflow\StateMachine;

Harness::group( 'Submission path' );

Harness::assert_same( Statuses::PENDING, StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT ), 'draft submits to pending' );
Harness::assert_same( Statuses::PENDING, StateMachine::next( StateMachine::SUBMIT, Statuses::CHANGES ), 'changes requested resubmits to pending' );
Harness::assert_same( Statuses::PENDING, StateMachine::next( StateMachine::SUBMIT, Statuses::EXPIRED ), 'expired item can be resubmitted' );
Harness::assert_same( null, StateMachine::next( StateMachine::SUBMIT, Statuses::PENDING ), 'cannot submit something already pending' );
Harness::assert_same( null, StateMachine::next( StateMachine::SUBMIT, Statuses::LIVE ), 'cannot submit a live item directly, it goes through a revision' );

Harness::group( 'Moderator decisions' );

Harness::assert_same( Statuses::LIVE, StateMachine::next( StateMachine::APPROVE, Statuses::PENDING ), 'approve publishes' );
Harness::assert_same( Statuses::CHANGES, StateMachine::next( StateMachine::REQUEST_CHANGES, Statuses::PENDING ), 'request changes returns it to the member' );
Harness::assert_same( Statuses::REJECTED, StateMachine::next( StateMachine::REJECT, Statuses::PENDING ), 'reject marks it not approved' );
Harness::assert_same( null, StateMachine::next( StateMachine::APPROVE, Statuses::DRAFT ), 'cannot approve a draft that was never submitted' );
Harness::assert_same( null, StateMachine::next( StateMachine::APPROVE, Statuses::LIVE ), 'cannot approve something already live' );
Harness::assert_same( null, StateMachine::next( StateMachine::REJECT, Statuses::ARCHIVED ), 'cannot reject an archived item' );

Harness::group( 'Trust changes where a submission lands' );

Harness::assert_same(
	Statuses::PENDING,
	StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT, false ),
	'an untrusted submission goes to the queue'
);
Harness::assert_same(
	Statuses::LIVE,
	StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT, true ),
	'a trusted one publishes on submit'
);
Harness::assert_same(
	null,
	StateMachine::next( StateMachine::SUBMIT, Statuses::ARCHIVED, true ),
	'trust does not make an illegal transition legal'
);
Harness::assert_same(
	Statuses::PENDING,
	StateMachine::next( StateMachine::RESTORE, Statuses::ARCHIVED, true ),
	'trust does not auto-publish a restore'
);

Harness::group( 'The old trust levels still normalise, for the index' );

Harness::assert_same( Trust::MODERATED, Trust::normalise( 99 ), 'an out of range trust level fails closed' );
Harness::assert_same( Trust::MODERATED, Trust::normalise( 'nonsense' ), 'a non-numeric trust level fails closed' );
Harness::assert_same( Trust::MODERATED, Trust::normalise( -1 ), 'a negative trust level fails closed' );
Harness::assert_same( Trust::TRUSTED, Trust::normalise( '2' ), 'a numeric string trust level is accepted' );

Harness::group( 'Lifecycle end states' );

Harness::assert_same( Statuses::EXPIRED, StateMachine::next( StateMachine::EXPIRE, Statuses::LIVE ), 'live content expires' );
Harness::assert_same( null, StateMachine::next( StateMachine::EXPIRE, Statuses::DRAFT ), 'a draft cannot expire' );
Harness::assert_same( Statuses::ARCHIVED, StateMachine::next( StateMachine::ARCHIVE, Statuses::LIVE ), 'live content can be archived' );
Harness::assert_same( null, StateMachine::next( StateMachine::ARCHIVE, Statuses::PENDING ), 'a pending item cannot be archived out of the queue' );
Harness::assert_same( Statuses::PENDING, StateMachine::next( StateMachine::RESTORE, Statuses::ARCHIVED ), 'restoring sends it back for approval, not straight live' );
Harness::assert_same( Statuses::PENDING, StateMachine::next( StateMachine::TAKE_DOWN, Statuses::LIVE ), 'take down returns live content to the queue' );

Harness::group( 'Action metadata' );

Harness::assert_true( StateMachine::requires_note( StateMachine::REQUEST_CHANGES ), 'requesting changes needs a note' );
Harness::assert_true( StateMachine::requires_note( StateMachine::REJECT ), 'rejecting needs a note' );
Harness::assert_true( StateMachine::requires_note( StateMachine::TAKE_DOWN ), 'taking down needs a note' );
Harness::assert_false( StateMachine::requires_note( StateMachine::APPROVE ), 'approving needs no note' );
Harness::assert_true( StateMachine::revokes_trust( StateMachine::REJECT ), 'a rejection revokes trust' );
Harness::assert_true( StateMachine::revokes_trust( StateMachine::TAKE_DOWN ), 'a take down revokes trust' );
Harness::assert_false( StateMachine::revokes_trust( StateMachine::APPROVE ), 'an approval does not revoke trust' );

$from_live = StateMachine::available_from( Statuses::LIVE );
sort( $from_live );
Harness::assert_same(
	[ StateMachine::ARCHIVE, StateMachine::EXPIRE, StateMachine::TAKE_DOWN ],
	$from_live,
	'live content offers exactly archive, expire and take down'
);
Harness::assert_same( [], StateMachine::available_from( 'not_a_status' ), 'an unknown status offers no actions' );
Harness::assert_same( null, StateMachine::next( 'not_an_action', Statuses::DRAFT ), 'an unknown action is illegal' );

Harness::group( 'Trust is decided by the caller, per type and per kind of change' );

Harness::assert_same(
	Statuses::PENDING,
	StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT, false, true ),
	'an edit the caller did not trust is read first'
);
Harness::assert_same(
	Statuses::LIVE,
	StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT, true, true ),
	'a trusted edit goes straight on to the site'
);
Harness::assert_same(
	Statuses::LIVE,
	StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT, true, false ),
	'and so does a trusted new item'
);

Harness::group( 'An edit is decided like anything else once it is in the queue' );

Harness::assert_same( Statuses::LIVE, StateMachine::next( StateMachine::APPROVE, Statuses::PENDING, false, true ), 'approving an edit resolves it' );
Harness::assert_same( Statuses::CHANGES, StateMachine::next( StateMachine::REQUEST_CHANGES, Statuses::PENDING, false, true ), 'changes can be asked for' );
Harness::assert_same( Statuses::REJECTED, StateMachine::next( StateMachine::REJECT, Statuses::PENDING, false, true ), 'and it can be refused' );

Harness::group( 'Reopening a refusal' );

Harness::assert_same( Statuses::PENDING, StateMachine::next( StateMachine::REOPEN, Statuses::REJECTED ), 'a refused item reopens into the queue' );
Harness::assert_same( null, StateMachine::next( StateMachine::REOPEN, Statuses::LIVE ), 'a live item is taken down, not reopened' );
Harness::assert_same( null, StateMachine::next( StateMachine::REOPEN, Statuses::ARCHIVED ), 'an archived item is restored, not reopened' );
Harness::assert_false( StateMachine::requires_note( StateMachine::REOPEN ), 'reopening does not need a note' );
Harness::assert_false( StateMachine::revokes_trust( StateMachine::REOPEN ), 'and does not touch trust' );

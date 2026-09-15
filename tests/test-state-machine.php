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
	StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT, Trust::MODERATED ),
	'moderated organisation goes to the queue'
);
Harness::assert_same(
	Statuses::PENDING,
	StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT, Trust::TRUSTED_EDITS ),
	'trusted-for-edits organisation still queues new items'
);
Harness::assert_same(
	Statuses::LIVE,
	StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT, Trust::TRUSTED ),
	'fully trusted organisation publishes on submit'
);
Harness::assert_same(
	null,
	StateMachine::next( StateMachine::SUBMIT, Statuses::ARCHIVED, Trust::TRUSTED ),
	'trust does not make an illegal transition legal'
);
Harness::assert_same(
	Statuses::PENDING,
	StateMachine::next( StateMachine::RESTORE, Statuses::ARCHIVED, Trust::TRUSTED ),
	'trust does not auto-publish a restore'
);

Harness::group( 'Trust helpers' );

Harness::assert_false( Trust::auto_publishes_new( Trust::MODERATED ), 'moderated does not auto-publish new items' );
Harness::assert_false( Trust::auto_publishes_new( Trust::TRUSTED_EDITS ), 'trusted-for-edits does not auto-publish new items' );
Harness::assert_true( Trust::auto_publishes_new( Trust::TRUSTED ), 'trusted auto-publishes new items' );
Harness::assert_false( Trust::auto_publishes_edits( Trust::MODERATED ), 'moderated does not auto-publish edits' );
Harness::assert_true( Trust::auto_publishes_edits( Trust::TRUSTED_EDITS ), 'trusted-for-edits auto-publishes edits' );
Harness::assert_true( Trust::auto_publishes_edits( Trust::TRUSTED ), 'trusted auto-publishes edits' );
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

Harness::group( 'Trust treats an edit and a new item as different permissions' );

Harness::assert_same(
	Statuses::PENDING,
	StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT, Trust::TRUSTED_EDITS, false ),
	'a trusted-for-edits organisation still has new work read first'
);
Harness::assert_same(
	Statuses::LIVE,
	StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT, Trust::TRUSTED_EDITS, true ),
	'but its edits go straight on to the site'
);
Harness::assert_same(
	Statuses::PENDING,
	StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT, Trust::MODERATED, true ),
	'a moderated organisation has its edits read like everything else'
);
Harness::assert_same(
	Statuses::LIVE,
	StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT, Trust::TRUSTED, true ),
	'and a fully trusted one skips the queue either way'
);
Harness::assert_same(
	Statuses::LIVE,
	StateMachine::next( StateMachine::SUBMIT, Statuses::DRAFT, Trust::TRUSTED, false ),
	'both ways round'
);

Harness::group( 'An edit is decided like anything else once it is in the queue' );

Harness::assert_same( Statuses::LIVE, StateMachine::next( StateMachine::APPROVE, Statuses::PENDING, Trust::MODERATED, true ), 'approving an edit resolves it' );
Harness::assert_same( Statuses::CHANGES, StateMachine::next( StateMachine::REQUEST_CHANGES, Statuses::PENDING, Trust::MODERATED, true ), 'changes can be asked for' );
Harness::assert_same( Statuses::REJECTED, StateMachine::next( StateMachine::REJECT, Statuses::PENDING, Trust::MODERATED, true ), 'and it can be refused' );

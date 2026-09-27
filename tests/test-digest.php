<?php
/**
 * Digest cadence and matching tests.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Email\Digest\Frequency;
use DGL\Email\Digest\Matcher;
use DGL\Email\Digest\Subscription;
use DGL\PostTypes;

$at = static fn( string $s ): DateTimeImmutable => new DateTimeImmutable( $s, new DateTimeZone( 'UTC' ) );

/** A sendable subscription, overridable per test. */
$sub = static function ( array $over = [] ): Subscription {
	return new Subscription(
		user_id: $over['user_id'] ?? 1,
		email: $over['email'] ?? 'member@example.test',
		types: $over['types'] ?? [ PostTypes::EVENT ],
		topic_ids: $over['topic_ids'] ?? [],
		frequency: $over['frequency'] ?? Frequency::WEEKLY,
		last_sent_at: array_key_exists( 'last_sent_at', $over ) ? $over['last_sent_at'] : '2026-09-01 07:00:00',
		unsubscribe_token: $over['unsubscribe_token'] ?? 'tok123',
		consent_at: array_key_exists( 'consent_at', $over ) ? $over['consent_at'] : '2026-08-01 12:00:00',
		org_id: $over['org_id'] ?? 5,
		include_own_org: $over['include_own_org'] ?? false,
	);
};

$item = static fn( array $o ): array => array_merge(
	[ 'id' => 1, 'type' => PostTypes::EVENT, 'topics' => [], 'org_id' => 9, 'approved_at' => '2026-09-10 09:00:00' ],
	$o
);

Harness::group( 'Consent is required, not assumed' );

Harness::assert_false( $sub( [ 'consent_at' => null ] )->has_consent(), 'a row with no consent timestamp has no consent' );
Harness::assert_false( $sub( [ 'consent_at' => null ] )->is_sendable(), 'and cannot be sent' );
Harness::assert_same( [], Matcher::match( $sub( [ 'consent_at' => null ] ), [ $item( [] ) ] ), 'nothing matches without consent, whatever the content' );
Harness::assert_false( $sub( [ 'unsubscribe_token' => '' ] )->is_sendable(), 'no unsubscribe token means no send' );
Harness::assert_false( $sub( [ 'email' => '' ] )->is_sendable(), 'no address means no send' );
Harness::assert_false( $sub( [ 'types' => [] ] )->is_sendable(), 'asking for nothing means nothing is sent' );
Harness::assert_false( $sub( [ 'frequency' => 'fortnightly' ] )->is_sendable(), 'an unknown cadence fails closed' );
Harness::assert_true( $sub()->is_sendable(), 'a complete subscription is sendable' );

Harness::group( 'Type is an explicit opt-in' );

$s = $sub( [ 'types' => [ PostTypes::EVENT, PostTypes::VOLUNTEERING ] ] );
Harness::assert_true( Matcher::wants( $s, $item( [ 'type' => PostTypes::EVENT ] ) ), 'a requested type matches' );
Harness::assert_true( Matcher::wants( $s, $item( [ 'type' => PostTypes::VOLUNTEERING ] ) ), 'a second requested type matches' );
Harness::assert_false( Matcher::wants( $s, $item( [ 'type' => PostTypes::GRANT ] ) ), 'an unrequested type does not' );
Harness::assert_false( Matcher::wants( $sub( [ 'types' => [] ] ), $item( [] ) ), 'an empty type list means none, not all' );

Harness::group( 'Topics filter, they do not opt in' );

$any = $sub( [ 'topic_ids' => [] ] );
Harness::assert_true( Matcher::wants( $any, $item( [ 'topics' => [ 3 ] ] ) ), 'choosing no topic means any topic' );
Harness::assert_true( Matcher::wants( $any, $item( [ 'topics' => [] ] ) ), 'including items with no topic at all' );

$narrow = $sub( [ 'topic_ids' => [ 3, 7 ] ] );
Harness::assert_true( Matcher::wants( $narrow, $item( [ 'topics' => [ 7 ] ] ) ), 'a chosen topic matches' );
Harness::assert_true( Matcher::wants( $narrow, $item( [ 'topics' => [ 1, 3, 9 ] ] ) ), 'one overlap is enough' );
Harness::assert_false( Matcher::wants( $narrow, $item( [ 'topics' => [ 1, 9 ] ] ) ), 'no overlap does not match' );
Harness::assert_false( Matcher::wants( $narrow, $item( [ 'topics' => [] ] ) ), 'an untagged item does not match a narrowed subscription' );
Harness::assert_true( Matcher::wants( $narrow, $item( [ 'topics' => [ '3' ] ] ) ), 'topic ids compare as integers, not strings' );

Harness::group( 'Your own organisation' );

$own = $item( [ 'org_id' => 5 ] );
Harness::assert_false( Matcher::wants( $sub(), $own ), 'a member is not emailed their own organisation s posting' );
Harness::assert_true( Matcher::wants( $sub( [ 'include_own_org' => true ] ), $own ), 'unless they asked to see it' );
Harness::assert_true( Matcher::wants( $sub( [ 'org_id' => 0 ] ), $own ), 'a subscriber with no organisation sees everything' );

Harness::group( 'Only what is new since last time' );

$s = $sub( [ 'last_sent_at' => '2026-09-10 07:00:00' ] );
Harness::assert_true( Matcher::wants( $s, $item( [ 'approved_at' => '2026-09-10 09:00:00' ] ) ), 'approved after the last digest' );
Harness::assert_false( Matcher::wants( $s, $item( [ 'approved_at' => '2026-09-09 09:00:00' ] ) ), 'approved before it' );
Harness::assert_false( Matcher::wants( $s, $item( [ 'approved_at' => '2026-09-10 07:00:00' ] ) ), 'approved exactly on the boundary is not resent' );
Harness::assert_true( Matcher::wants( $sub( [ 'last_sent_at' => null ] ), $item( [ 'approved_at' => '2020-01-01 00:00:00' ] ) ), 'a first digest has no lower bound' );

Harness::group( 'Empty digests are not sent' );

Harness::assert_false( Matcher::should_send( [] ), 'nothing matched means nothing sent' );
Harness::assert_true( Matcher::should_send( [ 1 ] ), 'one match is worth sending' );

Harness::group( 'Matching a realistic mix' );

$mixed = [
	$item( [ 'id' => 1, 'type' => PostTypes::EVENT, 'topics' => [ 3 ] ] ),
	$item( [ 'id' => 2, 'type' => PostTypes::GRANT, 'topics' => [ 3 ] ] ),
	$item( [ 'id' => 3, 'type' => PostTypes::EVENT, 'topics' => [ 8 ] ] ),
	$item( [ 'id' => 4, 'type' => PostTypes::EVENT, 'topics' => [ 3 ], 'org_id' => 5 ] ),
	$item( [ 'id' => 5, 'type' => PostTypes::EVENT, 'topics' => [ 3 ], 'approved_at' => '2026-08-01 09:00:00' ] ),
	$item( [ 'id' => 6, 'type' => PostTypes::EVENT, 'topics' => [ 3, 8 ] ] ),
];

$result = Matcher::match( $sub( [ 'types' => [ PostTypes::EVENT ], 'topic_ids' => [ 3 ] ] ), $mixed );
Harness::assert_same( [ 1, 6 ], $result, 'wrong type, wrong topic, own org and too old are all excluded' );

Harness::group( 'Cadence: fixed slots in the site s own time' );

$tz  = new DateTimeZone( 'Europe/London' );
$fmt = static fn( DateTimeImmutable $d ): string => $d->format( 'D Y-m-d H:i T' );

Harness::assert_same( 'Tue 2026-09-29 08:00 BST', $fmt( Frequency::slot_after( Frequency::WEEKLY, $at( '2026-09-24 12:00:00' ), $tz ) ), 'the weekly slot after a Thursday is the next Tuesday at 08:00 local' );
Harness::assert_same( 'Tue 2026-10-06 08:00 BST', $fmt( Frequency::slot_after( Frequency::WEEKLY, $at( '2026-09-29 07:00:00' ), $tz ) ), 'and after Tuesday 08:00 BST itself (07:00 UTC) it is the Tuesday after' );
Harness::assert_same( 'Tue 2026-09-29 08:00 BST', $fmt( Frequency::slot_after( Frequency::WEEKLY, $at( '2026-09-29 06:59:00' ), $tz ) ), 'a minute before the slot, the slot is still to come' );
Harness::assert_same( 'Tue 2026-10-27 08:00 GMT', $fmt( Frequency::slot_after( Frequency::WEEKLY, $at( '2026-10-24 12:00:00' ), $tz ) ), 'across the clock change the slot stays at 08:00 on the wall' );
Harness::assert_same( 'Thu 2026-10-01 08:00 BST', $fmt( Frequency::slot_after( Frequency::MONTHLY, $at( '2026-09-15 12:00:00' ), $tz ) ), 'the monthly slot is the first of the month' );
Harness::assert_same( 'Fri 2026-09-25 08:00 BST', $fmt( Frequency::slot_after( Frequency::DAILY, $at( '2026-09-24 23:30:00' ), $tz ) ), 'the daily slot is tomorrow morning' );
Harness::assert_same( 'Thu 2026-09-24 08:00 BST', $fmt( Frequency::slot_after( Frequency::DAILY, $at( '2026-09-24 03:00:00' ), $tz ) ), 'or this morning, before 08:00' );

Harness::assert_same( 'Tue 2026-09-29 08:00 BST', $fmt( Frequency::slot_before( Frequency::WEEKLY, $at( '2026-10-01 10:00:00' ), $tz ) ), 'the latest slot before a Thursday is that Tuesday' );
Harness::assert_same( 'Tue 2026-09-29 08:00 BST', $fmt( Frequency::slot_before( Frequency::WEEKLY, $at( '2026-09-29 07:00:00' ), $tz ) ), 'at the slot itself, it is the slot' );
Harness::assert_same( 'Tue 2026-09-22 08:00 BST', $fmt( Frequency::window_start( Frequency::WEEKLY, Frequency::slot_after( Frequency::WEEKLY, $at( '2026-09-24 12:00:00' ), $tz ), $tz ) ), 'a weekly digest looks back exactly one week from its slot' );
Harness::assert_same( 'Tue 2026-09-01 08:00 BST', $fmt( Frequency::window_start( Frequency::MONTHLY, $at( '2026-10-01 07:00:00' ), $tz ) ), 'a monthly one looks back to the first of last month' );

Harness::group( 'Cadence: what is owed, and when' );

Harness::assert_false( Frequency::is_due( Frequency::WEEKLY, null, '2026-09-24 12:00:00', $at( '2026-09-28 12:00:00' ), $tz ), 'subscribed on Thursday, nothing is owed on Monday' );
Harness::assert_true( Frequency::is_due( Frequency::WEEKLY, null, '2026-09-24 12:00:00', $at( '2026-09-29 07:05:00' ), $tz ), 'but at 08:05 on Tuesday the first digest is owed' );
Harness::assert_same( 'Tue 2026-09-29 08:00 BST', $fmt( Frequency::due_slot( Frequency::WEEKLY, null, '2026-09-24 12:00:00', $at( '2026-09-29 07:05:00' ), $tz ) ), 'and the slot being handled is that Tuesday' );
Harness::assert_false( Frequency::is_due( Frequency::WEEKLY, '2026-09-29 07:00:00', '2026-09-24 12:00:00', $at( '2026-09-29 09:00:00' ), $tz ), 'once that slot is stamped nothing is owed until the next' );
Harness::assert_true( Frequency::is_due( Frequency::WEEKLY, '2026-09-29 07:00:00', '2026-09-24 12:00:00', $at( '2026-10-06 07:30:00' ), $tz ), 'which is the Tuesday after' );
Harness::assert_false( Frequency::is_due( Frequency::WEEKLY, null, null, $at( '2026-10-21 12:00:00' ), $tz ), 'no consent and no send means nothing is ever owed' );
Harness::assert_same( 'Tue 2026-10-20 08:00 BST', $fmt( Frequency::due_slot( Frequency::WEEKLY, '2026-09-22 07:00:00', null, $at( '2026-10-21 12:00:00' ), $tz ) ), 'after three missed weeks only the latest slot is handled, not all three' );
Harness::assert_same( 'Tue 2026-10-06 08:00 BST', $fmt( Frequency::next_due_at( Frequency::WEEKLY, '2026-09-29 07:00:00', null, $at( '2026-09-29 09:00:00' ), $tz ) ), 'the next due date reads from the last slot handled' );
Harness::assert_same( 'Tue 2026-09-29 08:00 BST', $fmt( Frequency::next_due_at( Frequency::WEEKLY, null, '2026-09-24 12:00:00', $at( '2026-09-24 13:00:00' ), $tz ) ), 'or from the subscription when nothing has been sent' );
Harness::assert_true( Frequency::is_due( Frequency::DAILY, '2026-09-10 07:00:00', null, $at( '2026-09-14 09:00:00' ), $tz ), 'a daily subscriber three days late is owed today s' );
Harness::assert_true( Frequency::is_due( Frequency::MONTHLY, '2026-08-01 07:00:00', null, $at( '2026-09-01 07:30:00' ), $tz ), 'a monthly one is owed on the first' );
Harness::assert_false( Frequency::is_due( Frequency::MONTHLY, '2026-09-01 07:00:00', null, $at( '2026-09-20 07:00:00' ), $tz ), 'and not mid month' );

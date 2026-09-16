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

Harness::group( 'Cadence: when the next one is owed' );

Harness::assert_true( Frequency::is_valid( Frequency::DAILY ), 'daily is a cadence' );
Harness::assert_false( Frequency::is_valid( 'hourly' ), 'hourly is not' );

// Daily, last sent yesterday morning: owed this morning.
Harness::assert_true(
	Frequency::is_due( Frequency::DAILY, '2026-09-13 07:00:00', $at( '2026-09-14 07:00:00' ) ),
	'a daily digest is owed the next morning'
);
Harness::assert_false(
	Frequency::is_due( Frequency::DAILY, '2026-09-14 07:00:00', $at( '2026-09-14 12:00:00' ) ),
	'and not twice in one day'
);

Harness::assert_false(
	Frequency::is_due( Frequency::WEEKLY, '2026-09-10 07:00:00', $at( '2026-09-14 07:00:00' ) ),
	'a weekly digest is not owed after four days'
);
Harness::assert_true(
	Frequency::is_due( Frequency::WEEKLY, '2026-09-07 07:00:00', $at( '2026-09-14 07:00:00' ) ),
	'but is owed after seven'
);

Harness::assert_false(
	Frequency::is_due( Frequency::MONTHLY, '2026-09-01 07:00:00', $at( '2026-09-20 07:00:00' ) ),
	'a monthly digest is not owed mid month'
);
Harness::assert_true(
	Frequency::is_due( Frequency::MONTHLY, '2026-08-01 07:00:00', $at( '2026-09-01 07:00:00' ) ),
	'but is owed a month later'
);

Harness::group( 'A missed run catches up rather than skipping' );

/*
 * If the scheduler was down for three days, the daily digest is still owed.
 * Computing from the last send rather than from a calendar rule is what makes
 * that true, and it is why a member does not silently lose a period of content.
 */
Harness::assert_true(
	Frequency::is_due( Frequency::DAILY, '2026-09-10 07:00:00', $at( '2026-09-14 09:00:00' ) ),
	'three days late is still owed, not skipped'
);
Harness::assert_true(
	Frequency::is_due( Frequency::WEEKLY, '2026-07-01 07:00:00', $at( '2026-09-14 07:00:00' ) ),
	'a long outage leaves the weekly digest owed'
);

Harness::group( 'A new subscriber is not emailed the moment they sign up' );

Harness::assert_false(
	Frequency::is_due( Frequency::DAILY, null, $at( '2026-09-14 23:30:00' ) ),
	'signing up at half eleven at night does not trigger an instant email'
);
Harness::assert_same(
	'2026-09-15 07:00:00',
	Frequency::next_due_at( Frequency::DAILY, null, $at( '2026-09-14 23:30:00' ) )->format( 'Y-m-d H:i:s' ),
	'their first digest is owed the next morning'
);
Harness::assert_same(
	'2026-09-14 07:00:00',
	Frequency::next_due_at( Frequency::DAILY, null, $at( '2026-09-14 03:00:00' ) )->format( 'Y-m-d H:i:s' ),
	'signing up before the send hour is caught by that morning s run'
);

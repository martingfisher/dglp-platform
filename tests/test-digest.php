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

Harness::group( 'A row can carry a small square picture' );

$digest_html = static fn( array $items ): string => DGL\Email\Template::render(
	new DGL\Email\Message( key: 'digest', audience: 'subscriber', subject: 'S', items: $items )
);

$row = [ 'title' => 'Tree planting in Armley Park', 'meta' => 'News', 'url' => 'https://example.test/news/trees/', 'summary' => 'Join us.', 'section' => 'News', 'date' => null ];

$plain = $digest_html( [ $row ] );
Harness::assert_false( str_contains( $plain, '<img' ), 'a row with no picture has no image tag, and no empty box' );
Harness::assert_false( str_contains( $digest_html( [ $row + [ 'image' => null ] ] ), '<img' ), 'a null picture is the same as none' );
Harness::assert_false( str_contains( $digest_html( [ $row + [ 'image' => [ 'url' => '', 'alt' => 'x' ] ] ] ), '<img' ), 'a picture with no address is dropped' );
Harness::assert_false( str_contains( $digest_html( [ $row + [ 'image' => [ 'url' => 'javascript:alert(1)', 'alt' => 'x' ] ] ] ), '<img' ), 'an address that is not http or https is dropped' );

$pic = $digest_html( [ $row + [ 'image' => [ 'url' => 'https://example.test/up/dgl-email-thumbs/7-1-192.jpg', 'alt' => 'Volunteers planting a tree "today" & <b>more</b>' ] ] ] );
$size = DGL\Email\Digest\Thumb::SHOWN;

Harness::assert_true( str_contains( $pic, 'src="https://example.test/up/dgl-email-thumbs/7-1-192.jpg"' ), 'a row with a picture shows it' );
Harness::assert_true( str_contains( $pic, 'width="' . $size . '" height="' . $size . '"' ), 'with width and height as attributes, which is all Outlook reads' );
Harness::assert_true( str_contains( $pic, 'width:' . $size . 'px;height:' . $size . 'px' ), 'and in the style, for every other client' );
Harness::assert_true( str_contains( $pic, 'alt="Volunteers planting a tree &quot;today&quot; &amp; &lt;b&gt;more&lt;/b&gt;"' ), 'the alt text is escaped, so a member cannot break out of the attribute' );
Harness::assert_true( str_contains( $pic, '<a href="https://example.test/news/trees/" style="text-decoration:none;"><img' ), 'the picture links to the same page as the title' );
Harness::assert_same( 1, substr_count( $pic, '<img' ), 'one picture per row' );
Harness::assert_true( str_contains( $digest_html( [ $row + [ 'image' => [ 'url' => 'https://example.test/a.jpg', 'alt' => '' ] ] ] ), 'alt=""' ), 'no description gives an empty alt, not a missing one' );

$no_link = $digest_html( [ [ 'url' => '' ] + $row + [ 'image' => [ 'url' => 'https://example.test/a.jpg', 'alt' => '' ] ] ] );
Harness::assert_true( str_contains( $no_link, '<img' ) && ! str_contains( $no_link, '<a href=""' ), 'a row with no address still shows its picture, unlinked' );

$mixed = $digest_html( [ $row + [ 'image' => [ 'url' => 'https://example.test/a.jpg', 'alt' => '' ] ], [ 'title' => 'No picture here' ] + $row ] );
Harness::assert_same( 1, substr_count( $mixed, '<img' ), 'in a list, only the rows that have a picture show one' );

Harness::assert_same( '7-1793181557-192.jpg', DGL\Email\Digest\Thumb::file_name( 7, 1793181557 ), 'the crop is named by attachment, edit time and size' );
Harness::assert_true( DGL\Email\Digest\Thumb::file_name( 7, 100 ) !== DGL\Email\Digest\Thumb::file_name( 7, 200 ), 'so a replaced picture gets a new file rather than the stale one' );
Harness::assert_same( 2 * DGL\Email\Digest\Thumb::SHOWN, DGL\Email\Digest\Thumb::CUT, 'cut at twice the size shown, so it stays sharp on a phone' );

$text = ( new DGL\Email\Message( key: 'digest', audience: 'subscriber', subject: 'S', items: [ $row + [ 'image' => [ 'url' => 'https://example.test/a.jpg', 'alt' => 'x' ] ] ] ) )->to_text();
Harness::assert_false( str_contains( $text, 'a.jpg' ), 'the plain-text version does not mention the picture' );

Harness::group( 'An event keeps its picture under the date, the same width' );

$event = [ 'title' => 'Community lunch', 'meta' => 'Thu', 'url' => 'https://example.test/e/1', 'summary' => 'Free.', 'section' => 'Events', 'date' => [ 'month' => 'Nov', 'day' => '5', 'iso' => '2026-11-05' ] ];
$under = DGL\Email\Digest\Thumb::SHOWN_UNDER_DATE;
$ev    = $digest_html( [ $event + [ 'image' => [ 'url' => 'https://example.test/a.jpg', 'alt' => 'The hall' ] ] ] );

Harness::assert_same( 1, substr_count( $ev, '<img' ), 'an event with a picture shows one' );
Harness::assert_true( str_contains( $ev, 'width="' . $under . '" height="' . $under . '"' ), 'at the date box width, so the two line up' );
Harness::assert_false( str_contains( $ev, 'width="' . DGL\Email\Digest\Thumb::SHOWN . '" height="' ), 'and not at the larger size used beside news' );
Harness::assert_true( strpos( $ev, 'Nov' ) < strpos( $ev, '<img' ) && strpos( $ev, '<img' ) < strpos( $ev, 'Community lunch' ), 'it comes after the date and before the title, in the left column' );
Harness::assert_false( str_contains( $ev, 'align="right"' ), 'an event has no right-hand picture cell, so the text keeps the width' );
Harness::assert_true( str_contains( $ev, '<a href="https://example.test/e/1" style="text-decoration:none;"><img' ), 'the event picture links to the event' );
Harness::assert_false( str_contains( $digest_html( [ $event + [ 'image' => null ] ] ), '<img' ), 'an event with no picture shows the date alone' );
Harness::assert_true( str_contains( $digest_html( [ $event + [ 'image' => null ] ] ), 'Nov' ), 'and still shows the date' );

$both = $digest_html( [ $event + [ 'image' => [ 'url' => 'https://example.test/a.jpg', 'alt' => '' ] ], $row + [ 'image' => [ 'url' => 'https://example.test/b.jpg', 'alt' => '' ] ] ] );
Harness::assert_same( 2, substr_count( $both, '<img' ), 'events and news can share a digest' );
Harness::assert_same( 1, substr_count( $both, 'align="right"' ), 'only the news row has a right-hand picture' );

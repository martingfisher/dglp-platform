<?php
/**
 * Events as iCalendar: the rule mapping, escaping and folding.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Events\Ics;
use DGL\Events\Rule;

$tz = new DateTimeZone( 'Europe/London' );

Harness::group( 'iCalendar: a weekly series becomes an RRULE Google and Outlook read' );

$weekly = Rule::from_meta( [ 'freq' => 'weekly', 'weekdays' => [ 2, 4 ], 'until' => '2027-03-31', 'skip' => [ '2026-12-24', '2026-12-31' ] ], '2026-09-22 13:00:00', '2026-09-22 15:00:00', $tz );
Harness::assert_same( 'FREQ=WEEKLY;BYDAY=TU,TH;UNTIL=20270331T225959Z', Ics::rrule( $weekly ), 'weekly on two days, until the end of the last day in UTC' );
Harness::assert_same( [ 'EXDATE;TZID=Europe/London:20261224T130000', 'EXDATE;TZID=Europe/London:20261231T130000' ], Ics::exdates( $weekly, 'Europe/London' ), 'one EXDATE per skipped date, at the start time' );

$fortnightly = Rule::from_meta( [ 'freq' => 'fortnightly', 'weekdays' => [ 3 ], 'until' => '2026-12-31' ], '2026-10-07 10:00:00', '', $tz );
Harness::assert_same( 'FREQ=WEEKLY;INTERVAL=2;WKST=MO;BYDAY=WE;UNTIL=20261231T235959Z', Ics::rrule( $fortnightly ), 'every other week counts Monday-based weeks, as the engine does' );

$by_day = Rule::from_meta( [ 'freq' => 'monthly', 'monthly' => 'day', 'until' => '2027-02-28' ], '2026-10-15 18:30:00', '', $tz );
Harness::assert_same( 'FREQ=MONTHLY;BYMONTHDAY=15;UNTIL=20270228T235959Z', Ics::rrule( $by_day ), 'the 15th of each month' );

$by_nth = Rule::from_meta( [ 'freq' => 'monthly', 'monthly' => 'nth', 'until' => '2027-02-28' ], '2026-10-13 18:30:00', '', $tz ); // second Tuesday
Harness::assert_same( 'FREQ=MONTHLY;BYDAY=2TU;UNTIL=20270228T235959Z', Ics::rrule( $by_nth ), 'the second Tuesday of the month' );

$by_last = Rule::from_meta( [ 'freq' => 'monthly', 'monthly' => 'last', 'until' => '2027-02-28' ], '2026-10-30 18:30:00', '', $tz ); // last Friday
Harness::assert_same( 'FREQ=MONTHLY;BYDAY=-1FR;UNTIL=20270228T235959Z', Ics::rrule( $by_last ), 'the last Friday of the month' );

Harness::group( 'iCalendar: dates, zones, escaping and folding' );

$at = new DateTimeImmutable( '2026-07-01 13:00:00', $tz );
Harness::assert_same( 'DTSTART;TZID=Europe/London:20260701T130000', Ics::dt( 'DTSTART', $at, 'Europe/London' ), 'a named zone keeps wall-clock time with a TZID' );
Harness::assert_same( 'DTSTART:20260701T120000Z', Ics::dt( 'DTSTART', $at, '' ), 'no named zone: UTC, so 13:00 BST is 12:00Z' );
Harness::assert_same( 'Europe/London', Ics::tzid( $tz ), 'an IANA zone is passed through' );
Harness::assert_same( '', Ics::tzid( new DateTimeZone( '+01:00' ) ), 'an offset-only zone is not a TZID' );

Harness::assert_same( 'Tea\, cake\; and a talk\\\\ on two\nlines', Ics::escape( "Tea, cake; and a talk\\ on two\r\nlines" ), 'commas, semicolons, backslashes and newlines are escaped' );
Harness::assert_same( 'short', Ics::fold( 'short' ), 'a short line is left alone' );

$long   = 'DESCRIPTION:' . str_repeat( 'é', 100 );
$folded = Ics::fold( $long );
$parts  = explode( "\r\n ", $folded );
Harness::assert_true( count( $parts ) > 1 && max( array_map( 'strlen', $parts ) ) <= 75, 'a long line is folded so no piece is over 75 octets' );
Harness::assert_same( $long, str_replace( "\r\n ", '', $folded ), 'and unfolds back to exactly what it was, no character split' );

$cal = Ics::build( [ [ 'BEGIN:VEVENT', 'UID:x', 'END:VEVENT' ] ], 'Test, list' );
Harness::assert_true( str_starts_with( $cal, "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" ) && str_ends_with( $cal, "END:VCALENDAR\r\n" ), 'a calendar is CRLF-terminated with the RFC framing' );
Harness::assert_true( str_contains( $cal, "X-WR-CALNAME:Test\\, list\r\n" ) && ! str_contains( $cal, 'X-PUBLISHED-TTL' ), 'the name is escaped; a one-event file is not marked as a subscription' );
Harness::assert_true( str_contains( Ics::build( [], 'Feed', true ), "X-PUBLISHED-TTL:PT12H\r\n" ), 'the feed asks clients to refresh twice a day' );

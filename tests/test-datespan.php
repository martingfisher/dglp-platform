<?php
/**
 * The date line for events and courses: one day, a span, today, running now.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\DateSpan;

$tz  = new DateTimeZone( 'Europe/London' );
$at  = static fn( string $s ): DateTimeImmutable => new DateTimeImmutable( $s, $tz );
$now = $at( '2026-10-19 13:00:00' );

Harness::group( 'DateSpan: a single day' );

$d = DateSpan::describe( $at( '2026-10-25' ), null, $now, false );
Harness::assert_same( 'Sun 25 Oct 2026', $d['line'], 'a dated course with no end says its day, with the year' );
Harness::assert_same( '', $d['to'], 'and the leaf has no extra line' );
Harness::assert_same( false, $d['multi'], 'it is not a span' );
Harness::assert_same( DateSpan::UPCOMING, $d['state'], 'it is coming up' );
Harness::assert_same( '2026-10-25', $d['iso'], 'its machine date is the day' );

$d = DateSpan::describe( $at( '2026-10-20 10:00:00' ), null, $now, true );
Harness::assert_same( 'Tue 20 Oct 2026, 10:00', $d['line'], 'a timed event says the day and the time' );
Harness::assert_same( '10:00', $d['time'], 'the leaf carries the time' );
Harness::assert_same( '2026-10-20T10:00', $d['iso'], 'and the machine date has it too' );

$d = DateSpan::describe( $at( '2026-10-20 10:00:00' ), $at( '2026-10-20 12:30:00' ), $now, true );
Harness::assert_same( 'Tue 20 Oct 2026, 10:00 to 12:30', $d['line'], 'a same-day end time makes a time range' );

$d = DateSpan::describe( $at( '2026-10-20 10:00:00' ), $at( '2026-10-20 10:00:00' ), $now, true );
Harness::assert_same( 'Tue 20 Oct 2026, 10:00', $d['line'], 'an end equal to the start adds nothing' );

$d = DateSpan::describe( $at( '2026-10-20 10:00:00' ), $at( '2026-10-20 09:00:00' ), $now, true );
Harness::assert_same( 'Tue 20 Oct 2026, 10:00', $d['line'], 'an end before the start is ignored' );

Harness::group( 'DateSpan: today' );

$d = DateSpan::describe( $at( '2026-10-19 18:30:00' ), null, $now, true );
Harness::assert_same( 'Today, 18:30', $d['line'], 'an event this evening says today and the time' );
Harness::assert_same( DateSpan::TODAY, $d['state'], 'and is marked today' );
Harness::assert_same( 'Mon 19 Oct 2026, 18:30', $d['text'], 'the plain text still carries the full date' );

$d = DateSpan::describe( $at( '2026-10-19' ), null, $now, false );
Harness::assert_same( 'Today', $d['line'], 'a dated item today with no time says only today' );

Harness::group( 'DateSpan: a span' );

$d = DateSpan::describe( $at( '2026-10-25' ), $at( '2026-10-28' ), $now, false );
Harness::assert_same( 'Sun 25 to Wed 28 Oct 2026', $d['line'], 'a span inside one month names the month once' );
Harness::assert_same( 'to 28 Oct', $d['to'], 'and the leaf gets its end' );
Harness::assert_same( true, $d['multi'], 'it is a span' );
Harness::assert_same( DateSpan::UPCOMING, $d['state'], 'which has not started' );

$d = DateSpan::describe( $at( '2026-10-29' ), $at( '2026-11-03' ), $now, false );
Harness::assert_same( 'Thu 29 Oct to Tue 3 Nov 2026', $d['line'], 'across a month change it names both months' );

$d = DateSpan::describe( $at( '2026-12-29' ), $at( '2027-01-02' ), $now, false );
Harness::assert_same( 'Tue 29 Dec 2026 to Sat 2 Jan 2027', $d['line'], 'across a year change it names both years' );

Harness::group( 'DateSpan: running now' );

$d = DateSpan::describe( $at( '2026-10-15' ), $at( '2026-10-22' ), $now, false );
Harness::assert_same( 'Running now, until Thu 22 Oct 2026', $d['line'], 'a course that started and has not ended says it is running' );
Harness::assert_same( DateSpan::RUNNING, $d['state'], 'and is marked running' );
Harness::assert_same( 'Thu 15 to Thu 22 Oct 2026', $d['text'], 'its plain text is still the whole range' );
Harness::assert_same( 'to 22 Oct', $d['to'], 'the leaf shows where it ends' );

$d = DateSpan::describe( $at( '2026-10-15' ), $at( '2026-10-19' ), $now, false );
Harness::assert_same( DateSpan::RUNNING, $d['state'], 'it still runs on its last day' );

$d = DateSpan::describe( $at( '2026-10-15' ), $at( '2026-10-18' ), $now, false );
Harness::assert_same( DateSpan::ENDED, $d['state'], 'the day after it ends it has ended' );
Harness::assert_same( 'Thu 15 to Sun 18 Oct 2026', $d['line'], 'and it says its range, not "running"' );

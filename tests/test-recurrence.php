<?php
/**
 * The repeating-event engine, in Europe/London so both clock changes are in play.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Events\Occurrences;
use DGL\Events\Rule;
use DGL\Events\Wording;

$tz  = new DateTimeZone( 'Europe/London' );
$at  = static fn( string $s ): DateTimeImmutable => new DateTimeImmutable( $s, $tz );
$fmt = static fn( array $occ ): array => array_map( static fn( $o ): string => $o->wall(), $occ );

Harness::group( 'Weekly: every Tuesday 13:00 to 15:00, September to March' );

$weekly = Rule::from_meta( [ 'freq' => 'weekly', 'weekdays' => [ 2 ], 'until' => '2027-03-31' ], '2026-09-22 13:00:00', '2026-09-22 15:00:00', $tz );
Harness::assert_true( $weekly instanceof Rule, 'a weekly rule builds' );
Harness::assert_same( [ '2026-10-06 13:00:00', '2026-10-13 13:00:00', '2026-10-20 13:00:00', '2026-10-27 13:00:00' ], $fmt( Occurrences::between( $weekly, $at( '2026-10-01' ), $at( '2026-10-31 23:59:59' ) ) ), 'four Tuesdays in October, at 13:00 each' );
Harness::assert_same( '2026-09-22 13:00:00', Occurrences::first( $weekly )?->wall(), 'the first occurrence is the start' );
Harness::assert_same( '2026-09-22 15:00:00', Occurrences::first( $weekly )?->end?->format( 'Y-m-d H:i:s' ), 'with its end two hours later' );
Harness::assert_same( 28, count( Occurrences::between( $weekly, $at( '2026-09-01' ), $at( '2027-04-30' ) ) ), '28 Tuesdays from 22 September to 30 March inclusive' );

Harness::group( 'Weekly on two days; the start\'s weekday is always in' );

$two = Rule::from_meta( [ 'freq' => 'weekly', 'weekdays' => [ 1 ], 'until' => '2026-10-31' ], '2026-10-01 10:00:00', '', $tz ); // 1 Oct 2026 is a Thursday
Harness::assert_same( [ 1, 4 ], $two->weekdays, 'Monday asked for, Thursday added because the start is a Thursday' );
Harness::assert_same( [ '2026-10-01 10:00:00', '2026-10-05 10:00:00', '2026-10-08 10:00:00' ], $fmt( Occurrences::between( $two, $at( '2026-10-01' ), $at( '2026-10-09' ) ) ), 'Thursday, Monday, Thursday' );
Harness::assert_same( null, Occurrences::first( $two )?->end, 'no end when none was given' );

Harness::group( 'Fortnightly: alternate Wednesdays, parity holds over the year end' );

$fort = Rule::from_meta( [ 'freq' => 'fortnightly', 'weekdays' => [ 3 ], 'until' => '2027-02-28' ], '2026-12-16 19:00:00', '2026-12-16 20:30:00', $tz );
Harness::assert_same( [ '2026-12-16 19:00:00', '2026-12-30 19:00:00', '2027-01-13 19:00:00', '2027-01-27 19:00:00', '2027-02-10 19:00:00', '2027-02-24 19:00:00' ], $fmt( Occurrences::between( $fort, $at( '2026-12-01' ), $at( '2027-02-28 23:59:59' ) ) ), 'every other Wednesday, 23 December and 6 January absent' );

Harness::group( 'Monthly on the 31st: months without one are skipped' );

$m31 = Rule::from_meta( [ 'freq' => 'monthly', 'monthly' => 'day', 'until' => '2027-03-31' ], '2026-10-31 11:00:00', '', $tz );
Harness::assert_same( 31, $m31->day, 'day derived from the start' );
Harness::assert_same( [ '2026-10-31 11:00:00', '2026-12-31 11:00:00', '2027-01-31 11:00:00', '2027-03-31 11:00:00' ], $fmt( Occurrences::between( $m31, $at( '2026-10-01' ), $at( '2027-03-31 23:59:59' ) ) ), 'November and February skipped' );

Harness::group( 'Monthly on the third Tuesday, and a fifth Friday only when there is one' );

$nth = Rule::from_meta( [ 'freq' => 'monthly', 'monthly' => 'nth', 'until' => '2027-01-31' ], '2026-10-20 14:00:00', '', $tz ); // third Tuesday of October 2026
Harness::assert_same( [ 2, 3 ], [ $nth->weekday, $nth->nth ], 'weekday and ordinal derived from the start' );
Harness::assert_same( [ '2026-10-20 14:00:00', '2026-11-17 14:00:00', '2026-12-15 14:00:00', '2027-01-19 14:00:00' ], $fmt( Occurrences::between( $nth, $at( '2026-10-01' ), $at( '2027-01-31 23:59:59' ) ) ), 'third Tuesday each month' );
$fifth = Rule::from_meta( [ 'freq' => 'monthly', 'monthly' => 'nth', 'until' => '2027-03-31' ], '2026-10-30 09:00:00', '', $tz ); // fifth Friday of October 2026
Harness::assert_same( 5, $fifth->nth, 'a fifth Friday' );
Harness::assert_same( [ '2026-10-30 09:00:00', '2027-01-29 09:00:00' ], $fmt( Occurrences::between( $fifth, $at( '2026-10-01' ), $at( '2027-03-31 23:59:59' ) ) ), 'only October and January have a fifth Friday in that stretch' );

Harness::group( 'Monthly on the last Friday, including February' );

$last = Rule::from_meta( [ 'freq' => 'monthly', 'monthly' => 'last', 'until' => '2027-03-31' ], '2026-10-30 12:00:00', '', $tz );
Harness::assert_same( [ '2026-10-30 12:00:00', '2026-11-27 12:00:00', '2026-12-25 12:00:00', '2027-01-29 12:00:00', '2027-02-26 12:00:00', '2027-03-26 12:00:00' ], $fmt( Occurrences::between( $last, $at( '2026-10-01' ), $at( '2027-03-31 23:59:59' ) ) ), 'last Friday of each month' );

Harness::group( 'Skip dates, and until is inclusive on its day' );

$skip = Rule::from_meta( [ 'freq' => 'weekly', 'weekdays' => [ 2 ], 'until' => '2027-01-05', 'skip' => [ '2026-12-29', '2026-12-22' ] ], '2026-12-15 13:00:00', '', $tz );
Harness::assert_same( [ '2026-12-22', '2026-12-29' ], $skip->skip, 'skip dates sorted' );
Harness::assert_same( [ '2026-12-15 13:00:00', '2027-01-05 13:00:00' ], $fmt( Occurrences::between( $skip, $at( '2026-12-01' ), $at( '2027-01-31' ) ) ), 'two Tuesdays skipped, the until day itself included' );
Harness::assert_same( [], Occurrences::between( $skip, $at( '2027-01-06' ), $at( '2027-03-01' ) ), 'nothing after until' );

Harness::group( 'Clock changes: 13:00 stays 13:00' );

$dst = Rule::from_meta( [ 'freq' => 'weekly', 'weekdays' => [ 7 ], 'until' => '2027-04-30' ], '2026-10-18 13:00:00', '2026-10-18 15:00:00', $tz );
$autumn = Occurrences::between( $dst, $at( '2026-10-18' ), $at( '2026-11-01 23:59:59' ) );
Harness::assert_same( [ '2026-10-18 13:00:00', '2026-10-25 13:00:00', '2026-11-01 13:00:00' ], $fmt( $autumn ), 'wall clock held across 25 October' );
Harness::assert_same( 7 * 86400 + 3600, $autumn[1]->start->getTimestamp() - $autumn[0]->start->getTimestamp(), 'the week into the clocks-back Sunday is an hour longer in real time' );
$spring = Occurrences::between( $dst, $at( '2027-03-21' ), $at( '2027-04-04 23:59:59' ) );
Harness::assert_same( [ '2027-03-21 13:00:00', '2027-03-28 13:00:00', '2027-04-04 13:00:00' ], $fmt( $spring ), 'wall clock held across 28 March' );
Harness::assert_same( 7 * 86400 - 3600, $spring[1]->start->getTimestamp() - $spring[0]->start->getTimestamp(), 'and an hour shorter into the clocks-forward Sunday' );
Harness::assert_same( '2026-10-25 15:00:00', $autumn[1]->end?->format( 'Y-m-d H:i:s' ), 'the end keeps its two-hour distance' );

Harness::group( 'Next: mid-series, during an occurrence, after the end, all skipped' );

Harness::assert_same( [ '2026-10-06 13:00:00' ], $fmt( Occurrences::next( $weekly, $at( '2026-10-01 09:00:00' ) ) ), 'mid-series the coming Tuesday is next' );
Harness::assert_same( [ '2026-10-06 13:00:00' ], $fmt( Occurrences::next( $weekly, $at( '2026-10-06 14:00:00' ) ) ), 'during an occurrence, the running one is next' );
Harness::assert_same( [ '2026-10-13 13:00:00' ], $fmt( Occurrences::next( $weekly, $at( '2026-10-06 15:00:01' ) ) ), 'a second after it ends, the following week is next' );
Harness::assert_same( 3, count( Occurrences::next( $weekly, $at( '2026-10-01' ), 3 ) ), 'asks for three, gets three' );
Harness::assert_true( Occurrences::has_ended( $weekly, $at( '2027-04-01' ) ), 'after until the series has ended' );
Harness::assert_false( Occurrences::has_ended( $weekly, $at( '2027-03-30 12:00:00' ) ), 'on the last Tuesday morning it has not' );
$all_skipped = Rule::from_meta( [ 'freq' => 'weekly', 'weekdays' => [ 2 ], 'until' => '2026-12-29', 'skip' => [ '2026-12-22', '2026-12-29' ] ], '2026-12-15 13:00:00', '', $tz );
Harness::assert_true( Occurrences::has_ended( $all_skipped, $at( '2026-12-16' ) ), 'every remaining date skipped means it has ended before until' );

Harness::group( 'from_meta refuses what is not a rule; to_meta round-trips' );

Harness::assert_same( null, Rule::from_meta( [], '2026-10-01 10:00:00', '', $tz ), 'empty array is a one-off' );
Harness::assert_same( null, Rule::from_meta( [ 'freq' => 'daily', 'until' => '2026-12-01' ], '2026-10-01 10:00:00', '', $tz ), 'unknown frequency' );
Harness::assert_same( null, Rule::from_meta( [ 'freq' => 'weekly', 'until' => 'soon' ], '2026-10-01 10:00:00', '', $tz ), 'bad until' );
Harness::assert_same( null, Rule::from_meta( [ 'freq' => 'weekly', 'until' => '2026-12-01' ], '', '', $tz ), 'no start' );
Harness::assert_same( [ 'freq' => 'weekly', 'until' => '2027-03-31', 'weekdays' => [ 2 ] ], $weekly->to_meta(), 'weekly round-trips' );
Harness::assert_same( [ 'freq' => 'monthly', 'until' => '2027-03-31', 'monthly' => 'last', 'day' => 30, 'weekday' => 5, 'nth' => 5 ], $last->to_meta(), 'monthly carries the derived day, weekday and ordinal' );
Harness::assert_same( '2027-09-30', $weekly->with_until( $at( '2027-09-30' ) )->until->format( 'Y-m-d' ), 'with_until moves the end' );
Harness::assert_same( '23:59:59', $weekly->until->format( 'H:i:s' ), 'until is the end of its day' );

Harness::group( 'Wording' );

Harness::assert_same( 'Every Tuesday', Wording::describe( [ 'freq' => 'weekly', 'weekdays' => [ 2 ] ] ), 'weekly, one day' );
Harness::assert_same( 'Every Monday and Thursday', Wording::describe( [ 'freq' => 'weekly', 'weekdays' => [ 1, 4 ] ] ), 'two days' );
Harness::assert_same( 'Every Monday, Wednesday and Friday', Wording::describe( [ 'freq' => 'weekly', 'weekdays' => [ 1, 3, 5 ] ] ), 'three days' );
Harness::assert_same( 'Every other Wednesday', Wording::describe( [ 'freq' => 'fortnightly', 'weekdays' => [ 3 ] ] ), 'fortnightly' );
Harness::assert_same( 'The 15th of each month', Wording::describe( [ 'freq' => 'monthly', 'monthly' => 'day', 'day' => 15 ] ), 'monthly by date' );
Harness::assert_same( 'First Tuesday of the month', Wording::describe( [ 'freq' => 'monthly', 'monthly' => 'nth', 'weekday' => 2, 'nth' => 1 ] ), 'monthly by ordinal weekday' );
Harness::assert_same( 'Last Friday of the month', Wording::describe( [ 'freq' => 'monthly', 'monthly' => 'last', 'weekday' => 5 ] ), 'monthly by last weekday' );
Harness::assert_same( [ '1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st', '22nd', '23rd', '31st' ], array_map( [ Wording::class, 'ordinal' ], [ 1, 2, 3, 4, 11, 12, 13, 21, 22, 23, 31 ] ), 'ordinal suffixes' );
Harness::assert_same( 'Every Tuesday, 13:00 to 15:00', Wording::with_times( [ 'freq' => 'weekly', 'weekdays' => [ 2 ] ], '2026-09-22 13:00:00', '2026-09-22 15:00:00' ), 'with both times' );
Harness::assert_same( 'Every Tuesday, 13:00', Wording::with_times( [ 'freq' => 'weekly', 'weekdays' => [ 2 ] ], '2026-09-22 13:00:00', '' ), 'with a start only' );
Harness::assert_same( 'Every Tuesday, 13:00 to 15:00, until 31 March 2027. Not on 23 December 2026, 30 December 2026.', Wording::long( [ 'freq' => 'weekly', 'weekdays' => [ 2 ], 'until' => '2027-03-31', 'skip' => [ '2026-12-23', '2026-12-30' ] ], '2026-09-22 13:00:00', '2026-09-22 15:00:00', static fn( string $d ): string => ( new DateTimeImmutable( $d ) )->format( 'j F Y' ) ), 'the long form' );
Harness::assert_same( 'Every Tuesday, 13:00, until 31 March 2027.', Wording::long( [ 'freq' => 'weekly', 'weekdays' => [ 2 ], 'until' => '2027-03-31' ], '2026-09-22 13:00:00', '', static fn( string $d ): string => ( new DateTimeImmutable( $d ) )->format( 'j F Y' ) ), 'no skips, no end time' );

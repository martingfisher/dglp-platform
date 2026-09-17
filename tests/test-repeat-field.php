<?php
/**
 * The repeat field: what the validator makes of the form, and what the
 * schema derives from it.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\PostTypes;
use DGL\Schema\Field;
use DGL\Schema\FieldRegistry;
use DGL\Schema\Validator;

$repeat = new Field( key: 'repeat', label: 'Repeats', type: Field::REPEAT );
$start  = new Field( key: 'start_datetime', label: 'Start', type: Field::DATETIME, required: true );
$end    = new Field( key: 'end_datetime', label: 'End', type: Field::DATETIME );
$fields = [ $start, $end, $repeat ];
$in_six = ( new DateTimeImmutable( 'today' ) )->modify( '+3 months' )->format( 'Y-m-d' );
$today  = ( new DateTimeImmutable( 'today' ) )->format( 'Y-m-d' );
$run    = static fn( array $input ): array => Validator::validate( $fields, $input );

Harness::group( 'Repeat: off, on, and what is derived from the start' );

$r = $run( [ 'start_datetime' => '2026-10-06T13:00', 'repeat' => [ 'posted' => '1', 'freq' => 'weekly', 'until' => $in_six ] ] );
Harness::assert_same( [], $r['values']['repeat'], 'a posted form without the box ticked is off, even with the parts filled (JavaScript off)' );
$r = $run( [ 'start_datetime' => '2026-10-06T13:00' ] );
Harness::assert_same( [], $r['values']['repeat'], 'absent is off' );

$r = $run( [ 'start_datetime' => '2026-10-06T13:00', 'end_datetime' => '2026-10-06T15:00', 'repeat' => [ 'on' => '1', 'freq' => 'weekly', 'weekdays' => [ '', '4' ], 'until' => $in_six ] ] );
Harness::assert_same( [], $r['errors'], 'a weekly rule validates: ' . implode( ' | ', $r['errors'] ) );
Harness::assert_same( [ 2, 4 ], $r['values']['repeat']['weekdays'], 'the start\'s Tuesday is merged in and the blank dropped' );
Harness::assert_same( $in_six, $r['values']['repeat']['until'], 'until kept' );
Harness::assert_false( isset( $r['values']['repeat']['skip'] ), 'no skip key when none given' );

$r = $run( [ 'start_datetime' => '2026-10-20T14:00', 'repeat' => [ 'on' => '1', 'freq' => 'monthly', 'monthly' => 'nth', 'until' => $in_six ] ] );
Harness::assert_same( [ 'freq' => 'monthly', 'monthly' => 'nth', 'day' => 20, 'weekday' => 2, 'nth' => 3, 'until' => $in_six ], $r['values']['repeat'], 'monthly derives day, weekday and ordinal from the start' );

$r = $run( [ 'start_datetime' => '2026-10-13T14:00', 'repeat' => [ 'on' => '1', 'freq' => 'monthly', 'monthly' => 'last', 'until' => $in_six ] ] );
Harness::assert_true( str_contains( (string) ( $r['errors']['repeat'] ?? '' ), 'not the last Tuesday' ), '"last" refused when the start is not the last such weekday' );
$r = $run( [ 'start_datetime' => '2026-10-27T14:00', 'repeat' => [ 'on' => '1', 'freq' => 'monthly', 'monthly' => 'last', 'until' => $in_six ] ] );
Harness::assert_same( 'last', $r['values']['repeat']['monthly'] ?? '', 'and accepted when it is' );

$r = $run( [ 'start_datetime' => '2026-10-06T13:00', 'repeat' => [ 'on' => '1', 'freq' => 'weekly', 'until' => $in_six ] ] );
Harness::assert_same( [ 2 ], $r['values']['repeat']['weekdays'], 'no days ticked still gives the start\'s weekday' );

// A stored rule read back for a full re-validation has no "on" but has a freq.
$r = $run( [ 'start_datetime' => '2026-10-06 13:00:00', 'repeat' => [ 'freq' => 'weekly', 'weekdays' => [ 2 ], 'until' => $in_six ] ] );
Harness::assert_same( [ 2 ], $r['values']['repeat']['weekdays'] ?? null, 'a stored rule counts as on' );

Harness::group( 'Repeat: what is refused' );

$r = $run( [ 'repeat' => [ 'on' => '1', 'freq' => 'weekly', 'until' => $in_six ] ] );
Harness::assert_true( str_contains( (string) ( $r['errors']['repeat'] ?? '' ), 'Set the start' ), 'no start: told to set it first' );
$r = $run( [ 'start_datetime' => '2026-10-06T13:00', 'repeat' => [ 'on' => '1', 'freq' => 'daily', 'until' => $in_six ] ] );
Harness::assert_same( 'Choose how often it repeats.', $r['errors']['repeat'] ?? '', 'unknown frequency' );
$r = $run( [ 'start_datetime' => '2026-10-06T13:00', 'repeat' => [ 'on' => '1', 'freq' => 'monthly', 'until' => $in_six ] ] );
Harness::assert_same( 'Choose which day of the month it repeats on.', $r['errors']['repeat'] ?? '', 'monthly without a pattern' );
$r = $run( [ 'start_datetime' => '2026-10-06T13:00', 'repeat' => [ 'on' => '1', 'freq' => 'weekly' ] ] );
Harness::assert_same( 'Say when it runs until.', $r['errors']['repeat'] ?? '', 'until required' );
$r = $run( [ 'start_datetime' => '2026-10-06T13:00', 'repeat' => [ 'on' => '1', 'freq' => 'weekly', 'until' => '2026-10-01' ] ] );
Harness::assert_same( 'Runs until has to be on or after the start date.', $r['errors']['repeat'] ?? '', 'until before the start' );
$r = $run( [ 'start_datetime' => '2026-10-06T13:00', 'repeat' => [ 'on' => '1', 'freq' => 'weekly', 'until' => ( new DateTimeImmutable( 'today' ) )->modify( '+7 months' )->format( 'Y-m-d' ) ] ] );
Harness::assert_true( str_contains( (string) ( $r['errors']['repeat'] ?? '' ), 'up to six months' ), 'more than six months ahead refused' );
$six = ( new DateTimeImmutable( 'today' ) )->modify( '+6 months' )->format( 'Y-m-d' );
$r = $run( [ 'start_datetime' => $today . 'T13:00', 'repeat' => [ 'on' => '1', 'freq' => 'weekly', 'until' => $six ] ] );
Harness::assert_same( [], $r['errors'], 'exactly six months allowed' );
$r = $run( [ 'start_datetime' => '2026-10-06T13:00', 'end_datetime' => '2026-10-07T10:00', 'repeat' => [ 'on' => '1', 'freq' => 'weekly', 'until' => $in_six ] ] );
Harness::assert_true( str_contains( (string) ( $r['errors']['repeat'] ?? '' ), 'same day' ), 'an end on another day is refused for a series' );

Harness::group( 'Repeat: dates it does not run' );

$r = $run( [ 'start_datetime' => '2026-10-06T13:00', 'repeat' => [ 'on' => '1', 'freq' => 'weekly', 'until' => $in_six, 'skip' => "2026-10-20\n13/10/2026, 2026-10-20\n" ] ] );
Harness::assert_same( [ '2026-10-13', '2026-10-20' ], $r['values']['repeat']['skip'] ?? null, 'both date forms, newlines and commas, duplicates dropped, sorted' );
$r = $run( [ 'start_datetime' => '2026-10-06T13:00', 'repeat' => [ 'on' => '1', 'freq' => 'weekly', 'until' => $in_six, 'skip' => 'next tuesday' ] ] );
Harness::assert_true( str_contains( (string) ( $r['errors']['repeat'] ?? '' ), 'is not a date' ), 'a non-date is named' );
$r = $run( [ 'start_datetime' => '2026-10-06T13:00', 'repeat' => [ 'on' => '1', 'freq' => 'weekly', 'until' => $in_six, 'skip' => '2026-09-01' ] ] );
Harness::assert_true( str_contains( (string) ( $r['errors']['repeat'] ?? '' ), 'outside the dates' ), 'a date before the start is refused' );
$many = implode( "\n", array_map( static fn( int $i ): string => ( new DateTimeImmutable( '2026-10-06' ) )->modify( '+' . $i . ' days' )->format( 'Y-m-d' ), range( 1, 11 ) ) );
$r = $run( [ 'start_datetime' => '2026-10-06T13:00', 'repeat' => [ 'on' => '1', 'freq' => 'weekly', 'until' => $in_six, 'skip' => $many ] ] );
Harness::assert_same( 'Up to ten dates it does not run.', $r['errors']['repeat'] ?? '', 'eleven refused' );

Harness::group( 'Repeat: the schema and expiry' );

Harness::assert_true( in_array( Field::REPEAT, Field::types(), true ), 'the type is registered' );
$event_keys = array_map( static fn( Field $f ): string => $f->key, FieldRegistry::for_type( PostTypes::EVENT ) );
Harness::assert_true( in_array( 'repeat', $event_keys, true ) && ! in_array( 'is_recurring', $event_keys, true ), 'events carry repeat and no longer is_recurring' );
$schedule = array_map( static fn( Field $f ): string => $f->key, array_values( array_filter( FieldRegistry::for_type( PostTypes::EVENT ), static fn( Field $f ): bool => $f->schedule ) ) );
Harness::assert_same( [ 'start_datetime', 'end_datetime', 'repeat' ], $schedule, 'exactly three schedule fields' );
Harness::assert_same( '2027-03-31 23:59:59', FieldRegistry::expiry_for( PostTypes::EVENT, [ 'start_datetime' => '2026-10-06 13:00:00', 'end_datetime' => '2026-10-06 15:00:00', 'repeat' => [ 'freq' => 'weekly', 'weekdays' => [ 2 ], 'until' => '2027-03-31' ] ] ), 'a series expires at the end of its last day' );
Harness::assert_same( '2026-10-06 15:00:00', FieldRegistry::expiry_for( PostTypes::EVENT, [ 'start_datetime' => '2026-10-06 13:00:00', 'end_datetime' => '2026-10-06 15:00:00', 'repeat' => [] ] ), 'a one-off still expires at its end' );

<?php
/**
 * The date windows the events list filters by.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Filters;

$tz = new DateTimeZone( 'Europe/London' );
$on = static fn( string $d ): DateTimeImmutable => new DateTimeImmutable( $d, $tz );
$w  = static fn( ?array $x ): ?array => null === $x ? null : [ $x['from']->format( 'Y-m-d H:i:s' ), $x['to']->format( 'Y-m-d H:i:s' ) ];

Harness::group( 'Filters: what each date window means, from a Wednesday' );

$wed = $on( '2026-09-23 15:42:00' );
Harness::assert_same( [ '2026-09-23 00:00:00', '2026-09-23 23:59:59' ], $w( Filters::window( 'today', $wed ) ), 'today is the whole of today, whatever the time now' );
Harness::assert_same( [ '2026-09-23 00:00:00', '2026-09-29 23:59:59' ], $w( Filters::window( 'week', $wed ) ), 'the next seven days end on Tuesday' );
Harness::assert_same( [ '2026-09-26 00:00:00', '2026-09-27 23:59:59' ], $w( Filters::window( 'weekend', $wed ) ), 'this weekend is the coming Saturday and Sunday' );
Harness::assert_same( [ '2026-09-23 00:00:00', '2026-09-30 23:59:59' ], $w( Filters::window( 'month', $wed ) ), 'this month runs to the 30th' );
Harness::assert_same( [ '2026-10-01 00:00:00', '2026-10-31 23:59:59' ], $w( Filters::window( 'next-month', $wed ) ), 'next month is all of October' );
Harness::assert_same( null, Filters::window( 'someday', $wed ), 'an unknown spell is nothing' );

Harness::group( 'Filters: the weekend edge cases' );

Harness::assert_same( [ '2026-09-26 00:00:00', '2026-09-27 23:59:59' ], $w( Filters::window( 'weekend', $on( '2026-09-26 09:00:00' ) ) ), 'on a Saturday the weekend is today and tomorrow' );
Harness::assert_same( [ '2026-09-27 00:00:00', '2026-09-27 23:59:59' ], $w( Filters::window( 'weekend', $on( '2026-09-27 09:00:00' ) ) ), 'on a Sunday it is today alone, not next weekend' );
Harness::assert_same( [ '2026-01-03 00:00:00', '2026-01-04 23:59:59' ], $w( Filters::window( 'weekend', $on( '2026-01-01 09:00:00' ) ) ), 'and it crosses nothing odd at the year start' );
Harness::assert_same( [ '2026-12-30 00:00:00', '2027-01-05 23:59:59' ], $w( Filters::window( 'week', $on( '2026-12-30 09:00:00' ) ) ), 'the seven days run over the year end' );
Harness::assert_same( [ '2027-01-01 00:00:00', '2027-01-31 23:59:59' ], $w( Filters::window( 'next-month', $on( '2026-12-30 09:00:00' ) ) ), 'and next month from December is January' );

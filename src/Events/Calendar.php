<?php
/**
 * The public events calendar: eight weeks, day by day.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Events;

use DateTimeImmutable;
use DGL\Index\ItemsTable;
use DGL\PostTypes;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class Calendar {

	public const QUERY_VAR = 'dgl_calendar';
	public const WEEKS     = 8;
	/** How far ahead the "from" date may be set. */
	public const MAX_AHEAD_MONTHS = 12;

	/**
	 * The window a request asks for.
	 *
	 * @param array<string, mixed> $request Usually $_GET.
	 * @return array{from: DateTimeImmutable, to: DateTimeImmutable}
	 */
	public static function args_from( array $request ): array {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$from  = $today;
		$given = isset( $request['from'] ) && is_scalar( $request['from'] ) ? trim( (string) $request['from'] ) : '';

		if ( '' !== $given ) {
			$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $given, $tz );

			if ( false !== $parsed ) {
				$latest = $today->modify( '+' . self::MAX_AHEAD_MONTHS . ' months' );
				$from   = max( $today, min( $parsed, $latest ) );
			}
		}

		return [
			'from' => $from,
			'to'   => $from->modify( '+' . self::WEEKS . ' weeks' )->modify( '-1 day' )->setTime( 23, 59, 59 ),
		];
	}

	/**
	 * Every event on every day it runs inside the window, grouped by date.
	 *
	 * @return array<string, array<int, array{post: WP_Post, start: DateTimeImmutable, end: ?DateTimeImmutable, series: bool}>>
	 */
	public static function days( DateTimeImmutable $from, DateTimeImmutable $to ): array {
		$days = [];
		$tz   = wp_timezone();

		foreach ( ItemsTable::events_between( $from->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ) ) as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post || PostTypes::EVENT !== $post->post_type || 'publish' !== $post->post_status ) {
				continue;
			}

			$rule = Series::rule_for( $post_id );

			if ( null !== $rule ) {
				foreach ( Occurrences::between( $rule, $from, $to ) as $occurrence ) {
					$days[ $occurrence->date() ][] = [ 'post' => $post, 'start' => $occurrence->start, 'end' => $occurrence->end, 'series' => true ];
				}
				continue;
			}

			$start_raw = (string) get_post_meta( $post_id, 'dgl_start_datetime', true );

			if ( '' === $start_raw ) {
				continue;
			}

			try {
				$start = new DateTimeImmutable( $start_raw, $tz );
			} catch ( \Exception $e ) {
				continue;
			}

			if ( $start < $from || $start > $to ) {
				continue;
			}

			$end_raw = (string) get_post_meta( $post_id, 'dgl_end_datetime', true );
			$end     = null;

			if ( '' !== $end_raw ) {
				try {
					$end = new DateTimeImmutable( $end_raw, $tz );
				} catch ( \Exception $e ) {
					$end = null;
				}
			}

			$days[ $start->format( 'Y-m-d' ) ][] = [ 'post' => $post, 'start' => $start, 'end' => $end, 'series' => false ];
		}

		ksort( $days );

		foreach ( $days as &$rows ) {
			usort( $rows, static fn( array $a, array $b ): int => $a['start'] <=> $b['start'] ?: strcmp( $a['post']->post_title, $b['post']->post_title ) );
		}
		unset( $rows );

		return $days;
	}

	public static function url( ?DateTimeImmutable $from = null ): string {
		$base = home_url( '/' . PostTypes::definitions()[ PostTypes::EVENT ]['slug'] . '/calendar/' );

		return null === $from ? $base : add_query_arg( 'from', $from->format( 'Y-m-d' ), $base );
	}

	/**
	 * Everything the template needs.
	 *
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>
	 */
	public static function view_data( array $request ): array {
		$args  = self::args_from( $request );
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		$from  = $args['from'];
		$to    = $args['to'];

		$months = [];
		$first  = $today->modify( 'first day of next month' );
		for ( $i = 0; $i < 6; $i++ ) {
			$month = $first->modify( '+' . $i . ' months' );
			$months[ wp_date( 'F', $month->getTimestamp() ) ] = self::url( $month );
		}

		$earlier = $from > $today ? max( $today, $from->modify( '-' . self::WEEKS . ' weeks' ) ) : null;

		return [
			'from'    => $from,
			'to'      => $to,
			'today'   => $today,
			'days'    => self::days( $from, $to ),
			'earlier' => null === $earlier ? null : ( $earlier <= $today ? self::url() : self::url( $earlier ) ),
			'later'   => $to < $today->modify( '+' . self::MAX_AHEAD_MONTHS . ' months' ) ? self::url( $to->modify( '+1 day' )->setTime( 0, 0, 0 ) ) : null,
			'months'  => $months,
		];
	}
}

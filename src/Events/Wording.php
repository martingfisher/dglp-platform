<?php
/**
 * A rule in words: "Every Tuesday", "First Tuesday of the month".
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Events;

defined( 'ABSPATH' ) || exit;

final class Wording {

	/**
	 * The pattern alone.
	 *
	 * @param array<string, mixed> $repeat The stored rule.
	 */
	public static function describe( array $repeat ): string {
		$freq = (string) ( $repeat['freq'] ?? '' );

		if ( Rule::MONTHLY === $freq ) {
			$weekday = self::weekday_name( (int) ( $repeat['weekday'] ?? 1 ) );

			return match ( (string) ( $repeat['monthly'] ?? Rule::BY_DAY ) ) {
				/* translators: %s: weekday, e.g. Tuesday. */
				Rule::BY_LAST => sprintf( __( 'Last %s of the month', 'dgl-platform' ), $weekday ),
				/* translators: 1: ordinal, e.g. Third, 2: weekday. */
				Rule::BY_NTH  => sprintf( __( '%1$s %2$s of the month', 'dgl-platform' ), self::ordinal_word( (int) ( $repeat['nth'] ?? 1 ) ), $weekday ),
				/* translators: %s: ordinal day, e.g. 15th. */
				default       => sprintf( __( 'The %s of each month', 'dgl-platform' ), self::ordinal( (int) ( $repeat['day'] ?? 1 ) ) ),
			};
		}

		$days = array_map( [ self::class, 'weekday_name' ], array_map( 'intval', (array) ( $repeat['weekdays'] ?? [] ) ) );
		$list = self::join( $days );

		if ( Rule::FORTNIGHTLY === $freq ) {
			/* translators: %s: weekday or list of weekdays. */
			return sprintf( __( 'Every other %s', 'dgl-platform' ), $list );
		}

		/* translators: %s: weekday or list of weekdays. */
		return sprintf( __( 'Every %s', 'dgl-platform' ), $list );
	}

	/**
	 * The pattern with the time of day: "Every Tuesday, 13:00 to 15:00".
	 *
	 * @param array<string, mixed> $repeat
	 */
	public static function with_times( array $repeat, string $start, string $end ): string {
		$out  = self::describe( $repeat );
		$from = self::time_of( $start );

		if ( '' === $from ) {
			return $out;
		}

		$to = self::time_of( $end );

		return '' !== $to
			/* translators: 1: pattern, 2: start time, 3: end time. */
			? sprintf( __( '%1$s, %2$s to %3$s', 'dgl-platform' ), $out, $from, $to )
			/* translators: 1: pattern, 2: start time. */
			: sprintf( __( '%1$s, %2$s', 'dgl-platform' ), $out, $from );
	}

	/**
	 * The whole story: pattern, times, end date, dates it does not run.
	 *
	 * @param array<string, mixed> $repeat
	 * @param callable             $date_fmt Formats a Y-m-d string for people; injected so this stays pure.
	 */
	public static function long( array $repeat, string $start, string $end, callable $date_fmt ): string {
		$out   = self::with_times( $repeat, $start, $end );
		$until = (string) ( $repeat['until'] ?? '' );

		if ( '' !== $until ) {
			/* translators: 1: pattern and times, 2: end date. */
			$out = sprintf( __( '%1$s, until %2$s', 'dgl-platform' ), $out, (string) $date_fmt( $until ) );
		}

		$skip = array_map( 'strval', (array) ( $repeat['skip'] ?? [] ) );

		if ( [] !== $skip ) {
			/* translators: %s: list of dates. */
			$out .= '. ' . sprintf( __( 'Not on %s', 'dgl-platform' ), implode( ', ', array_map( $date_fmt, $skip ) ) );
		}

		$cancelled = array_map( 'strval', (array) ( $repeat['cancelled'] ?? [] ) );

		if ( [] !== $cancelled ) {
			/* translators: %s: list of dates. */
			$out .= '. ' . sprintf( __( 'Cancelled on %s', 'dgl-platform' ), implode( ', ', array_map( $date_fmt, $cancelled ) ) );
		}

		return $out . '.';
	}

	public static function ordinal( int $n ): string {
		$suffix = match ( true ) {
			$n % 100 >= 11 && $n % 100 <= 13 => 'th',
			1 === $n % 10 => 'st',
			2 === $n % 10 => 'nd',
			3 === $n % 10 => 'rd',
			default => 'th',
		};

		return $n . $suffix;
	}

	/** "First" to "Fifth", for the weekday-of-the-month form. */
	public static function ordinal_word( int $n ): string {
		$words = [
			1 => __( 'First', 'dgl-platform' ),
			2 => __( 'Second', 'dgl-platform' ),
			3 => __( 'Third', 'dgl-platform' ),
			4 => __( 'Fourth', 'dgl-platform' ),
			5 => __( 'Fifth', 'dgl-platform' ),
		];

		return $words[ $n ] ?? self::ordinal( $n );
	}

	public static function weekday_name( int $iso ): string {
		$names = [
			1 => __( 'Monday', 'dgl-platform' ),
			2 => __( 'Tuesday', 'dgl-platform' ),
			3 => __( 'Wednesday', 'dgl-platform' ),
			4 => __( 'Thursday', 'dgl-platform' ),
			5 => __( 'Friday', 'dgl-platform' ),
			6 => __( 'Saturday', 'dgl-platform' ),
			7 => __( 'Sunday', 'dgl-platform' ),
		];

		return $names[ $iso ] ?? '';
	}

	/** "Monday", "Monday and Thursday", "Monday, Wednesday and Friday". */
	private static function join( array $items ): string {
		$items = array_values( array_filter( $items ) );

		if ( count( $items ) <= 1 ) {
			return (string) ( $items[0] ?? '' );
		}

		$last = array_pop( $items );

		/* translators: 1: comma-separated items, 2: the last item. */
		return sprintf( __( '%1$s and %2$s', 'dgl-platform' ), implode( ', ', $items ), $last );
	}

	private static function time_of( string $datetime ): string {
		return 1 === preg_match( '/(\d{2}:\d{2})/', $datetime, $m ) ? $m[1] : '';
	}
}

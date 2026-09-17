<?php
/**
 * How an event repeats.
 *
 * A value object built from the stored `dgl_repeat` array plus the start and
 * end the member typed. Pure: no WordPress, so it is unit-tested on its own.
 * Every date in it carries the site's timezone, and every occurrence is made
 * by putting the start's wall-clock time onto a calendar date, so 13:00 stays
 * 13:00 across the clock change.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Events;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

final readonly class Rule {

	public const WEEKLY      = 'weekly';
	public const FORTNIGHTLY = 'fortnightly';
	public const MONTHLY     = 'monthly';

	public const BY_DAY  = 'day';
	public const BY_NTH  = 'nth';
	public const BY_LAST = 'last';

	/** A series may be listed this far ahead before somebody confirms it. */
	public const MAX_MONTHS_AHEAD = 6;

	public const MAX_SKIPS = 10;

	/**
	 * @param string[] $skip Dates it does not run, Y-m-d.
	 * @param int[]    $weekdays ISO weekdays 1 (Monday) to 7, sorted.
	 */
	public function __construct(
		public DateTimeImmutable $start,
		public ?DateInterval $duration,
		public string $freq,
		public array $weekdays,
		public string $monthly,
		public int $day,
		public int $weekday,
		public int $nth,
		public DateTimeImmutable $until,
		public array $skip,
	) {}

	/**
	 * Build from what is stored. Null when the array is empty or not a rule.
	 *
	 * @param array<string, mixed> $repeat The stored `dgl_repeat` value.
	 * @param string               $start  Stored start, Y-m-d H:i:s.
	 * @param string               $end    Stored end, Y-m-d H:i:s or ''.
	 */
	public static function from_meta( array $repeat, string $start, string $end, DateTimeZone $tz ): ?self {
		$freq = (string) ( $repeat['freq'] ?? '' );

		if ( ! in_array( $freq, self::freqs(), true ) || '' === trim( $start ) ) {
			return null;
		}

		$start_at = self::parse( $start, $tz );
		$until_on = self::parse_date( (string) ( $repeat['until'] ?? '' ), $tz );

		if ( null === $start_at || null === $until_on ) {
			return null;
		}

		$end_at   = '' !== trim( $end ) ? self::parse( $end, $tz ) : null;
		$duration = null !== $end_at && $end_at > $start_at ? $start_at->diff( $end_at ) : null;

		$weekdays = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $repeat['weekdays'] ?? [] ) ), static fn( int $d ): bool => $d >= 1 && $d <= 7 ) ) );
		sort( $weekdays );

		$start_weekday = (int) $start_at->format( 'N' );

		if ( self::MONTHLY !== $freq && ! in_array( $start_weekday, $weekdays, true ) ) {
			$weekdays[] = $start_weekday;
			sort( $weekdays );
		}

		$monthly = (string) ( $repeat['monthly'] ?? self::BY_DAY );

		if ( ! in_array( $monthly, [ self::BY_DAY, self::BY_NTH, self::BY_LAST ], true ) ) {
			$monthly = self::BY_DAY;
		}

		$skip = [];
		foreach ( (array) ( $repeat['skip'] ?? [] ) as $date ) {
			$parsed = self::parse_date( (string) $date, $tz );
			if ( null !== $parsed ) {
				$skip[] = $parsed->format( 'Y-m-d' );
			}
		}
		$skip = array_values( array_unique( $skip ) );
		sort( $skip );

		$day = (int) $start_at->format( 'j' );

		return new self(
			start: $start_at,
			duration: $duration,
			freq: $freq,
			weekdays: $weekdays,
			monthly: $monthly,
			day: $day,
			weekday: $start_weekday,
			nth: intdiv( $day - 1, 7 ) + 1,
			until: $until_on->setTime( 23, 59, 59 ),
			skip: $skip,
		);
	}

	/**
	 * The storable shape.
	 *
	 * @return array<string, mixed>
	 */
	public function to_meta(): array {
		$out = [ 'freq' => $this->freq, 'until' => $this->until->format( 'Y-m-d' ) ];

		if ( self::MONTHLY === $this->freq ) {
			$out['monthly'] = $this->monthly;
			$out['day']     = $this->day;
			$out['weekday'] = $this->weekday;
			$out['nth']     = $this->nth;
		} else {
			$out['weekdays'] = $this->weekdays;
		}

		if ( [] !== $this->skip ) {
			$out['skip'] = $this->skip;
		}

		return $out;
	}

	public function with_until( DateTimeImmutable $until ): self {
		return new self( $this->start, $this->duration, $this->freq, $this->weekdays, $this->monthly, $this->day, $this->weekday, $this->nth, $until->setTime( 23, 59, 59 ), $this->skip );
	}

	public function is_skipped( DateTimeImmutable $date ): bool {
		return in_array( $date->format( 'Y-m-d' ), $this->skip, true );
	}

	/**
	 * Whether the series runs on this calendar date.
	 */
	public function matches( DateTimeImmutable $date ): bool {
		$day = $date->setTime( 0, 0, 0 );

		if ( $day < $this->start->setTime( 0, 0, 0 ) || $day > $this->until ) {
			return false;
		}

		if ( $this->is_skipped( $day ) ) {
			return false;
		}

		$n = (int) $day->format( 'N' );
		$j = (int) $day->format( 'j' );

		return match ( $this->freq ) {
			self::WEEKLY      => in_array( $n, $this->weekdays, true ),
			self::FORTNIGHTLY => in_array( $n, $this->weekdays, true ) && 0 === self::weeks_between( $this->start, $day ) % 2,
			self::MONTHLY     => match ( $this->monthly ) {
				self::BY_NTH  => $n === $this->weekday && intdiv( $j - 1, 7 ) + 1 === $this->nth,
				self::BY_LAST => $n === $this->weekday && $j + 7 > (int) $day->format( 't' ),
				default       => $j === $this->day,
			},
			default           => false,
		};
	}

	/**
	 * The occurrence on a date: the start's time of day on that date, and
	 * the end the same distance later.
	 */
	public function occurrence_on( DateTimeImmutable $date ): Occurrence {
		$start = $date->setTime( (int) $this->start->format( 'G' ), (int) $this->start->format( 'i' ), (int) $this->start->format( 's' ) );

		return new Occurrence( $start, null !== $this->duration ? $start->add( $this->duration ) : null );
	}

	/** @return string[] */
	public static function freqs(): array {
		return [ self::WEEKLY, self::FORTNIGHTLY, self::MONTHLY ];
	}

	/** Whole weeks between the Monday of one date's week and the Monday of another's. */
	private static function weeks_between( DateTimeImmutable $a, DateTimeImmutable $b ): int {
		$monday_a = $a->setTime( 0, 0, 0 )->modify( 'monday this week' );
		$monday_b = $b->setTime( 0, 0, 0 )->modify( 'monday this week' );

		return intdiv( (int) $monday_a->diff( $monday_b )->days, 7 );
	}

	private static function parse( string $value, DateTimeZone $tz ): ?DateTimeImmutable {
		$value = str_replace( 'T', ' ', trim( $value ) );

		foreach ( [ 'Y-m-d H:i:s', 'Y-m-d H:i' ] as $format ) {
			$parsed = DateTimeImmutable::createFromFormat( '!' . $format, $value, $tz );

			if ( false !== $parsed ) {
				return $parsed;
			}
		}

		return null;
	}

	private static function parse_date( string $value, DateTimeZone $tz ): ?DateTimeImmutable {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', trim( $value ), $tz );

		return false !== $parsed ? $parsed : null;
	}
}

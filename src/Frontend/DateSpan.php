<?php
/**
 * When an event or a course happens, in words a visitor can read at a glance.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

/**
 * One start, maybe an end, and today: the date line, the calendar leaf's
 * extra line and where it stands (coming up, today, running now).
 *
 * The listings used to show the start alone. A course that runs 15 to 22
 * October and is still on sale on the 19th then read as a date in the past,
 * and a posted date next to the leaf looked like a second event date. This
 * says the whole span, says "Running now" while it runs, and leaves the
 * posted date out of it.
 *
 * Pure: three dates in, plain strings out. English, as the site is.
 */
final class DateSpan {

	public const UPCOMING = 'upcoming';
	public const TODAY    = 'today';
	public const RUNNING  = 'running';
	public const ENDED    = 'ended';

	/**
	 * @param DateTimeImmutable      $start When it begins (a date, or a date and time when `$timed`).
	 * @param DateTimeImmutable|null $end   When it ends, if the member said. Ignored if before the start.
	 * @param DateTimeImmutable      $now   Now, in the site's timezone.
	 * @param bool                   $timed Whether `$start` carries a real time of day.
	 * @return array{line:string, text:string, to:string, time:string, iso:string, multi:bool, state:string}
	 *   line: the one sentence to print. text: the plain date or range.
	 *   to: the leaf's extra line for a span ("to 22 Oct"), else ''.
	 *   time: the start time of a one-day timed item, else ''.
	 *   iso: the start, for a <time datetime>.
	 */
	public static function describe( DateTimeImmutable $start, ?DateTimeImmutable $end, DateTimeImmutable $now, bool $timed ): array {
		$start_day = $start->format( 'Y-m-d' );
		$today     = $now->format( 'Y-m-d' );
		$end_day   = null !== $end ? $end->format( 'Y-m-d' ) : '';
		$multi     = '' !== $end_day && $end_day > $start_day;
		$time      = $timed && ! $multi ? $start->format( 'H:i' ) : '';

		if ( $multi ) {
			$text  = self::range( $start, $end );
			$state = $today < $start_day ? self::UPCOMING : ( $today <= $end_day ? self::RUNNING : self::ENDED );
			$line  = self::RUNNING === $state ? 'Running now, until ' . $end->format( 'D j M Y' ) : $text;
			$to    = 'to ' . $end->format( 'j M' );
		} else {
			$clock = '';

			if ( $timed ) {
				$clock = $start->format( 'H:i' );

				if ( null !== $end && $end_day === $start_day && $end > $start && $end->format( 'H:i' ) !== $clock ) {
					$clock .= ' to ' . $end->format( 'H:i' );
				}
			}

			$date  = $start->format( 'D j M Y' );
			$text  = '' === $clock ? $date : $date . ', ' . $clock;
			$state = $today < $start_day ? self::UPCOMING : ( $today === $start_day ? self::TODAY : self::ENDED );
			$line  = self::TODAY === $state ? ( '' === $clock ? 'Today' : 'Today, ' . $clock ) : $text;
			$to    = '';
		}

		return [
			'line'  => $line,
			'text'  => $text,
			'to'    => $to,
			'time'  => $time,
			'iso'   => $start->format( $timed ? 'Y-m-d\\TH:i' : 'Y-m-d' ),
			'multi' => $multi,
			'state' => $state,
		];
	}

	/** "Thu 15 to Thu 22 Oct 2026", "Thu 15 Oct to Tue 3 Nov 2026", or both years when they differ. */
	private static function range( DateTimeImmutable $start, DateTimeImmutable $end ): string {
		if ( $start->format( 'Y' ) !== $end->format( 'Y' ) ) {
			return $start->format( 'D j M Y' ) . ' to ' . $end->format( 'D j M Y' );
		}

		if ( $start->format( 'm' ) !== $end->format( 'm' ) ) {
			return $start->format( 'D j M' ) . ' to ' . $end->format( 'D j M Y' );
		}

		return $start->format( 'D j' ) . ' to ' . $end->format( 'D j M Y' );
	}
}

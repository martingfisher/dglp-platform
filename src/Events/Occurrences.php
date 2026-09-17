<?php
/**
 * Walking a rule to find its dates.
 *
 * A day-by-day walk rather than arithmetic per pattern: a series is at most
 * six months from any point it was confirmed, so the walk is a few hundred
 * cheap comparisons, and one loop for every pattern is one loop to get right.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Events;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

final class Occurrences {

	/** Guard on the walk. Two years of days, well past any series length. */
	private const MAX_STEPS = 1500;

	/**
	 * Every occurrence starting within the window, in order.
	 *
	 * @return Occurrence[]
	 */
	public static function between( Rule $rule, DateTimeImmutable $from, DateTimeImmutable $to, int $limit = 500 ): array {
		$out = [];

		foreach ( self::walk( $rule, $from ) as $occurrence ) {
			if ( $occurrence->start > $to ) {
				break;
			}

			if ( $occurrence->start >= $from ) {
				$out[] = $occurrence;

				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * The next occurrences that have not finished, including one running now.
	 *
	 * @return Occurrence[]
	 */
	public static function next( Rule $rule, DateTimeImmutable $now, int $count = 1 ): array {
		$out = [];

		foreach ( self::walk( $rule, $now->modify( '-1 day' ) ) as $occurrence ) {
			if ( $occurrence->finish() < $now ) {
				continue;
			}

			$out[] = $occurrence;

			if ( count( $out ) >= $count ) {
				break;
			}
		}

		return $out;
	}

	public static function has_ended( Rule $rule, DateTimeImmutable $now ): bool {
		return [] === self::next( $rule, $now, 1 );
	}

	public static function first( Rule $rule ): ?Occurrence {
		return self::next( $rule, $rule->start, 1 )[0] ?? null;
	}

	/**
	 * Occurrences from the later of the series start and a date, to the end.
	 *
	 * @return iterable<Occurrence>
	 */
	private static function walk( Rule $rule, DateTimeImmutable $from ): iterable {
		$day  = max( $rule->start->setTime( 0, 0, 0 ), $from->setTime( 0, 0, 0 ) );
		$last = $rule->until->setTime( 0, 0, 0 );

		for ( $steps = 0; $day <= $last && $steps < self::MAX_STEPS; ++$steps ) {
			if ( $rule->matches( $day ) ) {
				yield $rule->occurrence_on( $day );
			}

			$day = $day->modify( '+1 day' );
		}
	}
}

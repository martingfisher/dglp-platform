<?php
/**
 * How often a member wants to hear from the site.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email\Digest;

use DateInterval;
use DateTimeImmutable;

/**
 * Digest cadence, and when the next one is owed.
 *
 * Due dates are computed from the last send rather than from a calendar rule,
 * so a missed run catches up instead of silently skipping a period. If the
 * server is down on a Monday morning, the weekly digest goes out when it
 * recovers rather than waiting another seven days.
 *
 * Pure: the clock is always passed in, never read.
 */
final class Frequency {

	public const DAILY   = 'daily';
	public const WEEKLY  = 'weekly';
	public const MONTHLY = 'monthly';

	/** Digests go out in the morning, UTC. */
	public const DEFAULT_SEND_HOUR = 7;

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return [ self::DAILY, self::WEEKLY, self::MONTHLY ];
	}

	public static function is_valid( string $frequency ): bool {
		return in_array( $frequency, self::all(), true );
	}

	public static function label( string $frequency ): string {
		return match ( $frequency ) {
			self::DAILY   => __( 'Every day', 'dgl-platform' ),
			self::WEEKLY  => __( 'Once a week', 'dgl-platform' ),
			self::MONTHLY => __( 'Once a month', 'dgl-platform' ),
			default       => $frequency,
		};
	}

	/**
	 * The gap between sends.
	 */
	public static function interval( string $frequency ): DateInterval {
		return match ( $frequency ) {
			self::WEEKLY  => new DateInterval( 'P7D' ),
			self::MONTHLY => new DateInterval( 'P1M' ),
			default       => new DateInterval( 'P1D' ),
		};
	}

	/**
	 * When the next digest is owed.
	 *
	 * A subscriber who has never received one is owed a digest at the next send
	 * hour rather than the instant they subscribe, so signing up at midnight
	 * does not produce an immediate email.
	 *
	 * @param string|null $last_sent_at UTC `Y-m-d H:i:s`, or null if never sent.
	 */
	public static function next_due_at(
		string $frequency,
		?string $last_sent_at,
		DateTimeImmutable $now,
		int $send_hour = self::DEFAULT_SEND_HOUR
	): DateTimeImmutable {
		if ( null === $last_sent_at || '' === $last_sent_at ) {
			$today = $now->setTime( $send_hour, 0 );

			return $now < $today ? $today : $today->add( new DateInterval( 'P1D' ) );
		}

		$last = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $last_sent_at )
			?: $now->sub( self::interval( $frequency ) );

		return $last->add( self::interval( $frequency ) )->setTime( $send_hour, 0 );
	}

	/**
	 * Whether a digest is owed right now.
	 */
	public static function is_due(
		string $frequency,
		?string $last_sent_at,
		DateTimeImmutable $now,
		int $send_hour = self::DEFAULT_SEND_HOUR
	): bool {
		return $now >= self::next_due_at( $frequency, $last_sent_at, $now, $send_hour );
	}
}

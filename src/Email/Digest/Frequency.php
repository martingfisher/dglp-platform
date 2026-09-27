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
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Digest cadence, and when the next one is owed.
 *
 * Every cadence has fixed send slots in the site's own timezone: 08:00 every
 * day, 08:00 every Tuesday, 08:00 on the first of the month. A slot is owed
 * once the clock passes it and it has not been handled. People learn when to
 * expect the email, and a digest always covers exactly one period, however
 * quiet the last one was.
 *
 * Somebody who has never been sent one is owed the first slot after they
 * subscribed, not one worked out from the current clock, which is never
 * reached. If the scheduler was down over a slot, the latest missed slot is
 * handled when it recovers and the older ones are let go: a month of stale
 * round-ups in one morning helps nobody.
 *
 * Pure: the clock and the timezone are always passed in, never read.
 */
final class Frequency {

	public const DAILY   = 'daily';
	public const WEEKLY  = 'weekly';
	public const MONTHLY = 'monthly';

	/** Digests go out in the morning, site time. */
	public const SEND_HOUR = 8;

	/** ISO weekday of the weekly slot: 2 is Tuesday. */
	public const WEEKLY_DAY = 2;

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
	 * When it arrives, in words, for the preferences screen.
	 */
	public static function when( string $frequency ): string {
		return match ( $frequency ) {
			self::DAILY   => __( 'every morning', 'dgl-platform' ),
			self::MONTHLY => __( 'on the first of the month', 'dgl-platform' ),
			default       => __( 'on Tuesday mornings', 'dgl-platform' ),
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
	 * The first send slot strictly after a moment.
	 */
	public static function slot_after( string $frequency, DateTimeImmutable $after, DateTimeZone $tz ): DateTimeImmutable {
		$local = $after->setTimezone( $tz );
		$slot  = $local->setTime( self::SEND_HOUR, 0 );

		if ( self::MONTHLY === $frequency ) {
			$slot = $slot->setDate( (int) $local->format( 'Y' ), (int) $local->format( 'n' ), 1 );

			while ( $slot <= $after ) {
				$slot = $slot->modify( 'first day of next month' )->setTime( self::SEND_HOUR, 0 );
			}

			return $slot;
		}

		if ( $slot <= $after ) {
			$slot = $slot->modify( '+1 day' )->setTime( self::SEND_HOUR, 0 );
		}

		if ( self::WEEKLY === $frequency ) {
			while ( (int) $slot->format( 'N' ) !== self::WEEKLY_DAY ) {
				$slot = $slot->modify( '+1 day' )->setTime( self::SEND_HOUR, 0 );
			}
		}

		return $slot;
	}

	/**
	 * The most recent send slot at or before a moment.
	 */
	public static function slot_before( string $frequency, DateTimeImmutable $at, DateTimeZone $tz ): DateTimeImmutable {
		// The slot after now is strictly later, so the one before it is at or before now.
		return self::previous_slot( $frequency, self::slot_after( $frequency, $at, $tz ), $tz );
	}

	/**
	 * The slot before a slot.
	 */
	private static function previous_slot( string $frequency, DateTimeImmutable $slot, DateTimeZone $tz ): DateTimeImmutable {
		$local = $slot->setTimezone( $tz );

		if ( self::MONTHLY === $frequency ) {
			return $local->modify( 'first day of previous month' )->setTime( self::SEND_HOUR, 0 );
		}

		return $local->sub( self::interval( $frequency ) )->setTime( self::SEND_HOUR, 0 );
	}

	/**
	 * Where a digest sent at a slot starts looking: one period back.
	 */
	public static function window_start( string $frequency, DateTimeImmutable $slot, DateTimeZone $tz ): DateTimeImmutable {
		return self::previous_slot( $frequency, $slot, $tz );
	}

	/**
	 * When the next digest is owed.
	 *
	 * The slot after the last one handled; for somebody never sent one, the
	 * slot after they subscribed. Without either there is nothing to owe, and
	 * the answer is the slot after now.
	 *
	 * @param string|null $last_sent_at UTC `Y-m-d H:i:s`, or null if never sent.
	 * @param string|null $consent_at   UTC `Y-m-d H:i:s`, or null if never subscribed.
	 */
	public static function next_due_at(
		string $frequency,
		?string $last_sent_at,
		?string $consent_at,
		DateTimeImmutable $now,
		DateTimeZone $tz
	): DateTimeImmutable {
		$after = self::utc( $last_sent_at ) ?? self::utc( $consent_at ) ?? $now;

		return self::slot_after( $frequency, $after, $tz );
	}

	/**
	 * The slot to handle now, or null when nothing is owed.
	 *
	 * The latest slot at or before now, provided it comes after the last one
	 * handled and after the subscription. Older missed slots are let go.
	 */
	public static function due_slot(
		string $frequency,
		?string $last_sent_at,
		?string $consent_at,
		DateTimeImmutable $now,
		DateTimeZone $tz
	): ?DateTimeImmutable {
		$after = self::utc( $last_sent_at ) ?? self::utc( $consent_at );

		if ( null === $after ) {
			return null;
		}

		$latest = self::slot_before( $frequency, $now, $tz );

		return $latest > $after ? $latest : null;
	}

	/**
	 * Whether a digest is owed right now.
	 */
	public static function is_due(
		string $frequency,
		?string $last_sent_at,
		?string $consent_at,
		DateTimeImmutable $now,
		DateTimeZone $tz
	): bool {
		return null !== self::due_slot( $frequency, $last_sent_at, $consent_at, $now, $tz );
	}

	private static function utc( ?string $stamp ): ?DateTimeImmutable {
		if ( null === $stamp || '' === $stamp || str_starts_with( $stamp, '0000-00-00' ) ) {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $stamp, new DateTimeZone( 'UTC' ) );

		return false === $parsed ? null : $parsed;
	}
}

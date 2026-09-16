<?php
/**
 * Per-organisation trust levels.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Org;

defined( 'ABSPATH' ) || exit;

/**
 * How much of an organisation's output skips the queue.
 *
 * At a few thousand members the bottleneck in this system is human reviewers,
 * not the database. Trust is the release valve: a proven organisation stops
 * consuming moderator time, while a new one is read in full.
 *
 * Raising trust is an administrator action only, never a moderator's, and it is
 * always audit logged. A rejection or an upheld report drops the organisation
 * back to MODERATED automatically.
 *
 * Trust never bypasses account approval, organisation verification, upload
 * validation or bot checks. It only decides whether a moderator reads the item
 * before the public does.
 */
final class Trust {

	/** Default. Every submission and every edit is reviewed. */
	public const MODERATED = 0;

	/** New items reviewed. Edits to already-approved items publish immediately. */
	public const TRUSTED_EDITS = 1;

	/** Submissions and edits publish immediately, listed for a post-hoc spot check. */
	public const TRUSTED = 2;

	/**
	 * @return int[]
	 */
	public static function all(): array {
		return [ self::MODERATED, self::TRUSTED_EDITS, self::TRUSTED ];
	}

	public static function is_valid( int $level ): bool {
		return in_array( $level, self::all(), true );
	}

	/**
	 * Coerce a stored value into a valid level, failing closed.
	 */
	public static function normalise( mixed $level ): int {
		$level = is_numeric( $level ) ? (int) $level : self::MODERATED;

		return self::is_valid( $level ) ? $level : self::MODERATED;
	}

	public static function label( int $level ): string {
		return match ( self::normalise( $level ) ) {
			self::TRUSTED_EDITS => __( 'Trusted for edits', 'dgl-platform' ),
			self::TRUSTED       => __( 'Trusted', 'dgl-platform' ),
			default             => __( 'Moderated', 'dgl-platform' ),
		};
	}

	public static function description( int $level ): string {
		return match ( self::normalise( $level ) ) {
			self::TRUSTED_EDITS => __( 'New items are reviewed. Edits to approved items go live straight away.', 'dgl-platform' ),
			self::TRUSTED       => __( 'Everything goes live straight away and is listed for a spot check.', 'dgl-platform' ),
			default             => __( 'Every submission and every edit is reviewed before it appears.', 'dgl-platform' ),
		};
	}

	/** Whether a brand new submission skips the queue. */
	public static function auto_publishes_new( int $level ): bool {
		return self::normalise( $level ) >= self::TRUSTED;
	}

	/** Whether an edit to an already-approved item skips the queue. */
	public static function auto_publishes_edits( int $level ): bool {
		return self::normalise( $level ) >= self::TRUSTED_EDITS;
	}
}

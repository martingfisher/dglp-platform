<?php
/**
 * The submission lifecycle as an explicit transition table.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Workflow;

use DGL\Org\Trust;
use DGL\Statuses;

/**
 * Which status changes are legal, and what a given action produces.
 *
 * Written as a table rather than scattered `if` statements so that an illegal
 * transition is impossible to reach by accident, and so the whole lifecycle can
 * be read in one screen. Like {@see \DGL\Access\Policy} this is pure: no
 * WordPress, no database, fully testable in isolation.
 *
 * Permission to perform an action is a separate question, answered by the
 * policy. This class only answers "is that move legal from here, and where does
 * it land".
 */
final class StateMachine {

	public const SUBMIT          = 'submit';
	public const APPROVE         = 'approve';
	public const REQUEST_CHANGES = 'request_changes';
	public const REJECT          = 'reject';
	public const ARCHIVE         = 'archive';
	public const RESTORE         = 'restore';
	public const EXPIRE          = 'expire';
	public const TAKE_DOWN       = 'take_down';

	/**
	 * Legal moves, as action => [ from status => to status ].
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function table(): array {
		return [
			self::SUBMIT          => [
				Statuses::DRAFT   => Statuses::PENDING,
				Statuses::CHANGES => Statuses::PENDING,
				Statuses::EXPIRED => Statuses::PENDING,
			],
			self::APPROVE         => [
				Statuses::PENDING => Statuses::LIVE,
			],
			self::REQUEST_CHANGES => [
				Statuses::PENDING => Statuses::CHANGES,
			],
			self::REJECT          => [
				Statuses::PENDING => Statuses::REJECTED,
			],
			self::ARCHIVE         => [
				Statuses::DRAFT    => Statuses::ARCHIVED,
				Statuses::CHANGES  => Statuses::ARCHIVED,
				Statuses::LIVE     => Statuses::ARCHIVED,
				Statuses::EXPIRED  => Statuses::ARCHIVED,
				Statuses::REJECTED => Statuses::ARCHIVED,
			],
			// Restoring sends it back through review rather than straight live.
			self::RESTORE         => [
				Statuses::ARCHIVED => Statuses::PENDING,
			],
			self::EXPIRE          => [
				Statuses::LIVE => Statuses::EXPIRED,
			],
			// A moderator pulling reported content while they look at it.
			self::TAKE_DOWN       => [
				Statuses::LIVE => Statuses::PENDING,
			],
		];
	}

	/**
	 * Where an action lands, or null if the move is illegal from this status.
	 *
	 * @param string $action  One of the class constants.
	 * @param string $from     The item's current status.
	 * @param int    $trust    The owning organisation's trust level.
	 * @param bool   $is_edit  Whether this is an edit to something already
	 *                         published, rather than a new submission.
	 */
	public static function next( string $action, string $from, int $trust = Trust::MODERATED, bool $is_edit = false ): ?string {
		$to = self::table()[ $action ][ $from ] ?? null;

		if ( null === $to ) {
			return null;
		}

		/*
		 * A trusted organisation's submission goes straight live. Everything
		 * else about the move, including the audit entry, is unchanged: the
		 * item simply lands on `publish` instead of waiting in the queue.
		 *
		 * Edits and new items are two different permissions. The middle trust
		 * level exists precisely so an organisation can fix its own typos
		 * without waiting, while anything genuinely new is still read first.
		 */
		if ( self::SUBMIT === $action && Statuses::PENDING === $to ) {
			$skips_queue = $is_edit
				? Trust::auto_publishes_edits( $trust )
				: Trust::auto_publishes_new( $trust );

			if ( $skips_queue ) {
				return Statuses::LIVE;
			}
		}

		return $to;
	}

	/**
	 * Whether an action is legal from a given status.
	 */
	public static function can( string $action, string $from ): bool {
		return null !== ( self::table()[ $action ][ $from ] ?? null );
	}

	/**
	 * Every action legal from a given status.
	 *
	 * @return string[]
	 */
	public static function available_from( string $status ): array {
		$actions = [];

		foreach ( self::table() as $action => $moves ) {
			if ( isset( $moves[ $status ] ) ) {
				$actions[] = $action;
			}
		}

		return $actions;
	}

	/**
	 * Whether an action must carry a written note to the member.
	 *
	 * Requesting changes or refusing something without saying why produces a
	 * support email instead of a fix, so the note is mandatory at this level
	 * rather than left to the interface.
	 */
	public static function requires_note( string $action ): bool {
		return in_array( $action, [ self::REQUEST_CHANGES, self::REJECT, self::TAKE_DOWN ], true );
	}

	/**
	 * Whether an action drops a trusted organisation back to moderated.
	 */
	public static function revokes_trust( string $action ): bool {
		return in_array( $action, [ self::REJECT, self::TAKE_DOWN ], true );
	}
}

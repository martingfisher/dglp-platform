<?php
/**
 * The submission lifecycle as an explicit transition table.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Workflow;

use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

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
	public const REOPEN          = 'reopen';

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
			// A refusal looked at again: back in the queue, decided afresh.
			self::REOPEN          => [
				Statuses::REJECTED => Statuses::PENDING,
			],
		];
	}

	/**
	 * Where an action lands, or null if the move is illegal from this status.
	 *
	 * @param string $action       One of the class constants.
	 * @param string $from         The item's current status.
	 * @param bool   $auto_publish Whether the owning organisation is trusted
	 *                             for this kind of submission, decided by the
	 *                             caller with TrustSettings::skips_review().
	 * @param bool   $is_edit      Whether this is an edit to something already
	 *                             published, rather than a new submission.
	 *                             Kept for the record; the caller has already
	 *                             folded it into $auto_publish.
	 */
	public static function next( string $action, string $from, bool $auto_publish = false, bool $is_edit = false ): ?string {
		$to = self::table()[ $action ][ $from ] ?? null;

		if ( null === $to ) {
			return null;
		}

		/*
		 * A trusted organisation's submission goes straight live. Everything
		 * else about the move, including the audit entry, is unchanged: the
		 * item simply lands on `publish` instead of waiting in the queue.
		 *
		 * Whether it is trusted is decided per content type and per kind of
		 * change (new or edit) before this is called, so this only has to
		 * know the answer.
		 */
		if ( self::SUBMIT === $action && Statuses::PENDING === $to && $auto_publish ) {
			return Statuses::LIVE;
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

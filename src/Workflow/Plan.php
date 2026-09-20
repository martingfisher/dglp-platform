<?php
/**
 * What a workflow action actually does, beyond changing a status.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Workflow;

defined( 'ABSPATH' ) || exit;

/**
 * The full consequence of one transition, decided in one place.
 *
 * {@see StateMachine} answers "is that move legal and where does it land".
 * This answers everything that follows: what gets stamped, who hears about it,
 * whether trust survives, whether the expiry date needs recalculating.
 *
 * Keeping it as data rather than a pile of side effects inside a controller is
 * what makes the whole workflow testable. {@see Transition} is the thin part
 * that carries a plan out against WordPress.
 */
final readonly class Plan {

	/** Nobody is told. */
	public const NOTIFY_NONE = 'none';

	/** The member who submitted it, and their organisation's owner. */
	public const NOTIFY_MEMBER = 'member';

	/** The DGLP review team. */
	public const NOTIFY_MODERATORS = 'moderators';

	/**
	 * @param string   $action        The action being taken.
	 * @param string   $from          Status before.
	 * @param string   $to            Status after.
	 * @param string[] $notify        Who hears about it.
	 * @param string   $message_key   Template key for the notification.
	 * @param bool     $requires_note Whether a note to the member is mandatory.
	 * @param bool     $revokes_trust Whether the organisation drops to moderated.
	 * @param bool     $stamp_submitted Whether to record a new submission time.
	 * @param bool     $stamp_approved  Whether to record a new approval time.
	 * @param bool     $recompute_expiry Whether the expiry date needs rebuilding.
	 * @param bool     $spot_check    Whether it published without being read first.
	 */
	public function __construct(
		public string $action,
		public string $from,
		public string $to,
		public array $notify = [],
		public string $message_key = '',
		public bool $requires_note = false,
		public bool $revokes_trust = false,
		public bool $stamp_submitted = false,
		public bool $stamp_approved = false,
		public bool $recompute_expiry = false,
		public bool $spot_check = false,
	) {}

	public function notifies( string $audience ): bool {
		return in_array( $audience, $this->notify, true );
	}

	/**
	 * Whether this transition put something in front of the public.
	 */
	public function publishes(): bool {
		return \DGL\Statuses::LIVE === $this->to && \DGL\Statuses::LIVE !== $this->from;
	}
}

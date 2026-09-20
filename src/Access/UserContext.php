<?php
/**
 * Everything the access policy needs to know about the person acting.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Access;

defined( 'ABSPATH' ) || exit;

/**
 * A plain snapshot of an actor, built once per request and passed to
 * {@see Policy}. Deliberately free of WordPress calls so the policy can be
 * exercised in isolation.
 */
final readonly class UserContext {

	/** Registered, not yet approved by the DGLP team. Can draft, cannot submit. */
	public const ACCOUNT_PENDING = 'pending';

	/** Approved. Full member. */
	public const ACCOUNT_APPROVED = 'approved';

	/** Suspended by the team. Read-only on their own content. */
	public const ACCOUNT_SUSPENDED = 'suspended';

	/** Closed, by the member or the team. No access at all. */
	public const ACCOUNT_CLOSED = 'closed';

	public const ROLE_MEMBER    = 'dgl_member';
	public const ROLE_MODERATOR = 'dgl_moderator';
	public const ROLE_ADMIN     = 'administrator';

	/** Can invite and remove colleagues, and edit the organisation profile. */
	public const ORG_OWNER = 'owner';

	/** Can submit on the organisation's behalf, nothing more. */
	public const ORG_CONTRIBUTOR = 'contributor';

	/**
	 * @param int         $user_id        WordPress user ID. 0 for a logged-out visitor.
	 * @param string[]    $roles          WordPress role slugs held by the user.
	 * @param int|null    $org_id         Organisation post ID, or null if unlinked.
	 * @param string|null $org_role       ORG_OWNER, ORG_CONTRIBUTOR, or null.
	 * @param string      $account_status One of the ACCOUNT_* constants.
	 * @param bool        $org_approved   Whether the organisation itself has been verified.
	 */
	public function __construct(
		public int $user_id,
		public array $roles = [],
		public ?int $org_id = null,
		public ?string $org_role = null,
		public string $account_status = self::ACCOUNT_PENDING,
		public bool $org_approved = false,
	) {}

	/**
	 * A logged-out visitor. Denied everything the policy governs.
	 */
	public static function anonymous(): self {
		return new self( 0, [], null, null, self::ACCOUNT_CLOSED, false );
	}

	public function has_role( string $role ): bool {
		return in_array( $role, $this->roles, true );
	}

	public function is_admin(): bool {
		return $this->has_role( self::ROLE_ADMIN );
	}

	/** Admins are moderators too. */
	public function is_moderator(): bool {
		return $this->has_role( self::ROLE_MODERATOR ) || $this->is_admin();
	}

	public function is_member(): bool {
		return $this->has_role( self::ROLE_MEMBER );
	}

	public function is_org_owner(): bool {
		return self::ORG_OWNER === $this->org_role;
	}

	/**
	 * Whether the account is in a state that permits any action at all.
	 */
	public function is_active(): bool {
		return self::ACCOUNT_CLOSED !== $this->account_status && $this->user_id > 0;
	}

	/**
	 * Whether the member may make changes, as opposed to only reading.
	 *
	 * Pending accounts may write drafts. Suspended accounts may not write at all.
	 */
	public function can_write(): bool {
		return $this->is_active() && self::ACCOUNT_SUSPENDED !== $this->account_status;
	}

	/**
	 * Whether the member has cleared both gates needed to put work in front of
	 * a moderator: their own account approval and their organisation's.
	 */
	public function is_fully_approved(): bool {
		return self::ACCOUNT_APPROVED === $this->account_status
			&& ( null === $this->org_id || $this->org_approved );
	}
}

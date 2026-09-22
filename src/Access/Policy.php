<?php
/**
 * The single access gatekeeper.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Access;

use DGL\PostTypes;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Every permission decision in the plugin resolves here.
 *
 * There is exactly one of these on purpose. Organisation scoping is the highest
 * risk in the build: one missed check leaks a member organisation's unpublished
 * drafts to a different organisation. Keeping the whole decision in one pure
 * function means it can be exhaustively tested, and it gives reviewers a single
 * place to read rather than a rule spread across a dozen controllers.
 *
 * This class has no WordPress dependencies by design. {@see Access} adapts it to
 * WordPress users, posts and `map_meta_cap`.
 *
 * Note on VIEW_ITEM: it governs visibility inside the member dashboard and the
 * moderation queue. Public reading of an already-published post is WordPress's
 * job and never reaches this policy.
 */
final class Policy {

	public const VIEW_ITEM      = 'view_item';
	public const CREATE_ITEM    = 'create_item';
	public const EDIT_ITEM      = 'edit_item';
	public const SUBMIT_ITEM    = 'submit_item';
	public const ARCHIVE_ITEM   = 'archive_item';
	public const RESTORE_ITEM   = 'restore_item';
	public const DELETE_ITEM    = 'delete_item';
	public const MODERATE_ITEM  = 'moderate_item';
	public const TAKE_DOWN_ITEM = 'take_down_item';
	public const GRANT_TRUST    = 'grant_trust';
	public const MANAGE_ORG     = 'manage_org';
	public const INVITE_MEMBER  = 'invite_member';
	public const REMOVE_MEMBER  = 'remove_member';
	public const VIEW_ORG_AUDIT = 'view_org_audit';
	public const VIEW_ALL_AUDIT = 'view_all_audit';
	public const CHANGE_SCHEDULE = 'change_schedule';
	public const REOPEN_ITEM     = 'reopen_item';
	public const EXTEND_ITEM     = 'extend_item';
	public const PIN_ITEM        = 'pin_item';
	public const CANCEL_ITEM     = 'cancel_item';
	public const REASSIGN_ITEM   = 'reassign_item';

	/**
	 * Decide whether an actor may perform an action, optionally on an item.
	 *
	 * @param UserContext      $user   The actor.
	 * @param string           $action One of the class constants.
	 * @param ItemContext|null $item   The target, for item-scoped actions.
	 */
	public static function decide( UserContext $user, string $action, ?ItemContext $item = null ): bool {
		// A closed account or a logged-out visitor gets nothing the policy governs.
		if ( ! $user->is_active() ) {
			return false;
		}

		return match ( $action ) {
			self::GRANT_TRUST    => $user->is_admin(),
			self::VIEW_ALL_AUDIT => $user->is_moderator(),
			self::VIEW_ORG_AUDIT => self::can_view_org_audit( $user ),
			self::MANAGE_ORG     => self::can_manage_org( $user ),
			self::INVITE_MEMBER  => self::can_invite_member( $user ),
			self::CREATE_ITEM    => self::can_create_item( $user ),
			self::VIEW_ITEM      => self::can_view_item( $user, $item ),
			self::EDIT_ITEM      => self::can_edit_item( $user, $item ),
			self::SUBMIT_ITEM    => self::can_submit_item( $user, $item ),
			self::ARCHIVE_ITEM   => self::can_archive_item( $user, $item ),
			self::RESTORE_ITEM   => self::can_restore_item( $user, $item ),
			self::DELETE_ITEM    => self::can_delete_item( $user, $item ),
			self::MODERATE_ITEM  => self::can_moderate_item( $user, $item ),
			self::TAKE_DOWN_ITEM => self::can_take_down_item( $user, $item ),
			self::CHANGE_SCHEDULE => self::can_change_schedule( $user, $item ),
			self::REOPEN_ITEM    => self::can_reopen_item( $user, $item ),
			self::EXTEND_ITEM    => self::can_extend_item( $user, $item ),
			self::PIN_ITEM       => self::can_take_down_item( $user, $item ), // The same people, the same live items.
			self::CANCEL_ITEM    => self::can_change_schedule( $user, $item ), // Cancelling is a schedule fact, so the same gate.
			self::REASSIGN_ITEM  => null !== $item && $user->is_moderator() && $user->can_write(), // Any item, any status: the team put it under the wrong name.
			default              => false,
		};
	}

	/**
	 * Whether the actor's organisation owns the item.
	 *
	 * An item with no organisation is orphaned. Nobody but an administrator
	 * touches it, so it can be reassigned rather than silently edited.
	 */
	private static function owns( UserContext $user, ItemContext $item ): bool {
		return $item->org_id > 0
			&& null !== $user->org_id
			&& $user->org_id === $item->org_id;
	}

	private static function can_view_org_audit( UserContext $user ): bool {
		return $user->is_moderator() || ( $user->is_member() && null !== $user->org_id );
	}

	private static function can_manage_org( UserContext $user ): bool {
		if ( $user->is_admin() ) {
			return true;
		}

		return $user->is_member()
			&& $user->can_write()
			&& $user->is_org_owner()
			&& null !== $user->org_id;
	}

	/**
	 * Inviting colleagues needs a verified organisation, not just an owner.
	 * Otherwise an unapproved signup becomes a way to mail arbitrary addresses.
	 */
	/**
	 * Whether one person may take another person's access to an organisation away.
	 *
	 * Not routed through decide(): the object is a person, not an item, so it
	 * takes the target's link directly. An owner removes anybody in their own
	 * organisation except themselves, so an organisation cannot be left with
	 * nobody who can manage it by the person who could manage it. What the
	 * removed person posted stays: the organisation owns it, not the author.
	 *
	 * @param UserContext $actor      Who is asking.
	 * @param int         $target_id  The user to remove.
	 * @param int|null    $target_org The organisation that user is linked to.
	 */
	/**
	 * Whether this person can switch the organisation's directory listing.
	 *
	 * Any approved member of the organisation, owner or contributor: it is
	 * the organisation's choice and the dashboard is shared. Pending and
	 * suspended accounts cannot, and nobody can for another organisation.
	 */
	public static function can_toggle_directory( UserContext $actor, int $org_id ): bool {
		if ( $org_id <= 0 ) {
			return false;
		}

		if ( $actor->is_admin() ) {
			return true;
		}

		return $actor->org_id === $org_id && $actor->is_fully_approved();
	}

	public static function can_remove_member( UserContext $actor, int $target_id, ?int $target_org ): bool {
		if ( $target_id <= 0 || null === $target_org || $target_id === $actor->user_id ) {
			return false;
		}

		if ( $actor->is_admin() ) {
			return true;
		}

		return self::can_manage_org( $actor )
			&& $actor->is_fully_approved()
			&& $actor->org_id === $target_org;
	}

	/**
	 * Making a colleague an owner, or an owner a contributor: the same people
	 * who can remove them, for the same reasons, and never on themselves, so
	 * an organisation cannot demote its last owner by accident.
	 */
	public static function can_change_role( UserContext $actor, int $target_id, ?int $target_org ): bool {
		return self::can_remove_member( $actor, $target_id, $target_org );
	}

	private static function can_invite_member( UserContext $user ): bool {
		if ( $user->is_admin() ) {
			return true;
		}

		return self::can_manage_org( $user ) && $user->is_fully_approved();
	}

	/**
	 * Pending members may start drafts. Wireframe 1c promises exactly that:
	 * "Start a draft. You can write now and submit once approved."
	 */
	private static function can_create_item( UserContext $user ): bool {
		if ( $user->is_admin() ) {
			return true;
		}

		return $user->is_member() && $user->can_write() && null !== $user->org_id;
	}

	private static function can_view_item( UserContext $user, ?ItemContext $item ): bool {
		if ( null === $item ) {
			return false;
		}

		return $user->is_moderator() || self::owns( $user, $item );
	}

	/**
	 * Editing is blocked while an item sits with a moderator.
	 *
	 * This is the control that stops the submit-clean-then-edit-dirty attack at
	 * source: between submission and decision the content is frozen, so what a
	 * moderator approves is exactly what they read.
	 *
	 * Editing a live item is permitted and produces a pending revision rather
	 * than changing the published post. {@see \DGL\Workflow\StateMachine}.
	 */
	private static function can_edit_item( UserContext $user, ?ItemContext $item ): bool {
		if ( null === $item || ! $user->can_write() ) {
			return false;
		}

		if ( $user->is_admin() ) {
			return true;
		}

		if ( ! self::owns( $user, $item ) ) {
			return false;
		}

		return in_array(
			$item->status,
			[ Statuses::DRAFT, Statuses::CHANGES, Statuses::LIVE, Statuses::EXPIRED ],
			true
		);
	}

	/**
	 * Dates and times on a live event change without review.
	 *
	 * A schedule is a fact about the world, not copy: the coffee morning
	 * moved to Wednesdays whether or not a moderator has read about it, and
	 * a listing that says Tuesday for a week while the edit waits is wrong
	 * for a week. Words and pictures still go through review. Only the
	 * owning organisation (or an administrator), and only while the event is
	 * on the site; a draft carries its dates through the wizard as before.
	 */
	private static function can_change_schedule( UserContext $user, ?ItemContext $item ): bool {
		if ( null === $item || ! $user->can_write() ) {
			return false;
		}

		if ( PostTypes::EVENT !== $item->post_type || Statuses::LIVE !== $item->status ) {
			return false;
		}

		// The same gate as submitting: changing what is on the site is publishing.
		return $user->is_admin() || ( self::owns( $user, $item ) && $user->is_fully_approved() );
	}

	/**
	 * Putting work in front of a moderator needs both approvals: the member's
	 * account and their organisation.
	 */
	private static function can_submit_item( UserContext $user, ?ItemContext $item ): bool {
		if ( null === $item || ! $user->can_write() ) {
			return false;
		}

		if ( $user->is_admin() ) {
			return true;
		}

		if ( ! self::owns( $user, $item ) || ! $user->is_fully_approved() ) {
			return false;
		}

		return in_array(
			$item->status,
			[ Statuses::DRAFT, Statuses::CHANGES, Statuses::EXPIRED ],
			true
		);
	}

	private static function can_archive_item( UserContext $user, ?ItemContext $item ): bool {
		if ( null === $item || ! $user->can_write() ) {
			return false;
		}

		if ( $user->is_moderator() ) {
			return true;
		}

		if ( ! self::owns( $user, $item ) ) {
			return false;
		}

		// Not while it is with a moderator: the queue would lose it mid-decision.
		return in_array(
			$item->status,
			[ Statuses::DRAFT, Statuses::CHANGES, Statuses::LIVE, Statuses::EXPIRED, Statuses::REJECTED ],
			true
		);
	}

	private static function can_restore_item( UserContext $user, ?ItemContext $item ): bool {
		if ( null === $item || ! $user->can_write() ) {
			return false;
		}

		if ( Statuses::ARCHIVED !== $item->status ) {
			return false;
		}

		return $user->is_moderator() || self::owns( $user, $item );
	}

	/**
	 * Members may permanently delete only their own untouched drafts.
	 *
	 * Once something has been in front of a moderator it stays, so the audit
	 * trail holds. Removing it beyond that point is an administrator's call.
	 */
	private static function can_delete_item( UserContext $user, ?ItemContext $item ): bool {
		if ( null === $item || ! $user->can_write() ) {
			return false;
		}

		if ( $user->is_admin() ) {
			return true;
		}

		return self::owns( $user, $item ) && Statuses::DRAFT === $item->status;
	}

	/**
	 * Only pending items can be decided, and nobody approves their own work.
	 */
	private static function can_moderate_item( UserContext $user, ?ItemContext $item ): bool {
		if ( null === $item || ! $user->is_moderator() || ! $user->can_write() ) {
			return false;
		}

		if ( Statuses::PENDING !== $item->status ) {
			return false;
		}

		// Self-approval guard. An administrator can still unstick a queue.
		if ( $user->user_id === $item->author_id && ! $user->is_admin() ) {
			return false;
		}

		return true;
	}

	/**
	 * Pulling live content off the site pending review, for reported items.
	 */
	/**
	 * Keeping a live item listed longer: a repeating event or an undated
	 * listing. The same gate as changing a schedule, for the same reason.
	 */
	private static function can_extend_item( UserContext $user, ?ItemContext $item ): bool {
		if ( null === $item || ! $user->can_write() || Statuses::LIVE !== $item->status ) {
			return false;
		}

		if ( ! in_array( $item->post_type, [ PostTypes::EVENT, PostTypes::NEWS ], true ) ) {
			return false;
		}

		return $user->is_admin() || ( self::owns( $user, $item ) && $user->is_fully_approved() );
	}

	/**
	 * A refusal can be looked at again by any moderator, including the one
	 * who refused it: undoing a mistake should not need a second person.
	 */
	private static function can_reopen_item( UserContext $user, ?ItemContext $item ): bool {
		if ( null === $item || ! $user->is_moderator() || ! $user->can_write() ) {
			return false;
		}

		return Statuses::REJECTED === $item->status;
	}

	private static function can_take_down_item( UserContext $user, ?ItemContext $item ): bool {
		if ( null === $item || ! $user->is_moderator() || ! $user->can_write() ) {
			return false;
		}

		return Statuses::LIVE === $item->status;
	}
}

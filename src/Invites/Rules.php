<?php
/**
 * What an invitation may do, decided without a database.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Invites;

use DateInterval;
use DateTimeImmutable;
use DGL\Access\UserContext;

/**
 * The rules an invitation obeys.
 *
 * Pure, so every branch can be exercised without WordPress. The WordPress side
 * of invites is a thin shell over this: it fetches rows, asks here, and writes
 * back what it is told.
 */
final class Rules {

	/** Waiting to be taken up. */
	public const OPEN = 'open';

	/** Taken up. */
	public const ACCEPTED = 'accepted';

	/** Withdrawn before it was taken up. */
	public const REVOKED = 'revoked';

	/** Ran out of time. */
	public const EXPIRED = 'expired';

	/** How long an invitation stays good for. */
	public const LIFETIME_DAYS = 14;

	/**
	 * How many open invitations one organisation may hold at once.
	 *
	 * Not a security control, a blast radius. An organisation with a hundred
	 * open invitations is either a mistake or somebody using a partner account
	 * to post links into strangers' inboxes from a domain DGLP has to keep
	 * deliverable.
	 */
	public const MAX_OPEN_PER_ORG = 25;

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------ */

	/**
	 * Where an invitation stands.
	 *
	 * Expiry is derived from the dates rather than stored, so an invitation
	 * cannot be left looking open by a cron job that did not run. The clock is
	 * always passed in.
	 */
	public static function state( Invite $invite, DateTimeImmutable $now ): string {
		if ( null !== $invite->accepted_at && '' !== $invite->accepted_at ) {
			return self::ACCEPTED;
		}

		if ( null !== $invite->revoked_at && '' !== $invite->revoked_at ) {
			return self::REVOKED;
		}

		return self::has_expired( $invite->expires_at, $now ) ? self::EXPIRED : self::OPEN;
	}

	/**
	 * Whether an invitation can still be taken up.
	 */
	public static function is_open( Invite $invite, DateTimeImmutable $now ): bool {
		return self::OPEN === self::state( $invite, $now );
	}

	public static function has_expired( string $expires_at, DateTimeImmutable $now ): bool {
		$expires = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $expires_at );

		// An unreadable expiry date is treated as expired. The failure that
		// costs somebody a re-send is better than the one that never expires.
		return false === $expires || $now >= $expires;
	}

	/**
	 * When an invitation created now should run out.
	 */
	public static function expires_at( DateTimeImmutable $created, int $days = self::LIFETIME_DAYS ): string {
		return $created->add( new DateInterval( 'P' . max( 1, $days ) . 'D' ) )->format( 'Y-m-d H:i:s' );
	}

	public static function label( string $state ): string {
		return match ( $state ) {
			self::OPEN     => __( 'Waiting to be accepted', 'dgl-platform' ),
			self::ACCEPTED => __( 'Accepted', 'dgl-platform' ),
			self::REVOKED  => __( 'Withdrawn', 'dgl-platform' ),
			self::EXPIRED  => __( 'Expired', 'dgl-platform' ),
			default        => $state,
		};
	}

	/* ---------------------------------------------------------------------
	 * Who may invite whom
	 * ------------------------------------------------------------------ */

	/**
	 * The organisation roles this actor is allowed to hand out.
	 *
	 * An owner can make another owner. That is deliberate: an organisation
	 * whose only owner leaves, with nobody able to promote a replacement, is a
	 * support ticket DGLP has to resolve by hand for every charity it happens
	 * to. A contributor can invite nobody.
	 *
	 * @return string[]
	 */
	public static function grantable_roles( UserContext $actor, int $org_id ): array {
		if ( ! $actor->can_write() ) {
			return [];
		}

		if ( $actor->is_moderator() ) {
			return [ UserContext::ORG_OWNER, UserContext::ORG_CONTRIBUTOR ];
		}

		if ( ! $actor->is_org_owner() || $actor->org_id !== $org_id || $org_id <= 0 ) {
			return [];
		}

		/*
		 * An organisation that has not been verified yet cannot recruit. Until
		 * DGLP has said this is a real partner, an invitation from it is an
		 * email from DGLP's domain vouching for somebody nobody has checked.
		 */
		if ( ! $actor->org_approved ) {
			return [];
		}

		return [ UserContext::ORG_OWNER, UserContext::ORG_CONTRIBUTOR ];
	}

	/**
	 * Whether this actor may invite somebody into this organisation as this role.
	 */
	public static function may_invite( UserContext $actor, int $org_id, string $org_role ): bool {
		return in_array( $org_role, self::grantable_roles( $actor, $org_id ), true );
	}

	/**
	 * Whether this actor may withdraw an invitation.
	 *
	 * Anyone who could have sent it can take it back, not only the person who
	 * did. An owner going on holiday should not be able to strand a mistake.
	 */
	public static function may_revoke( UserContext $actor, Invite $invite ): bool {
		return [] !== self::grantable_roles( $actor, $invite->org_id );
	}

	/* ---------------------------------------------------------------------
	 * Sending one
	 * ------------------------------------------------------------------ */

	/** The invitation can be sent. */
	public const SEND_OK = 'ok';

	/** This actor cannot invite into this organisation at this level. */
	public const SEND_DENIED = 'denied';

	/** The address is not an address. */
	public const SEND_BAD_EMAIL = 'bad_email';

	/** Somebody at that address is already in this organisation. */
	public const SEND_ALREADY_MEMBER = 'already_member';

	/** That address already has an open invitation here. */
	public const SEND_ALREADY_INVITED = 'already_invited';

	/** The address belongs to an account attached to a different organisation. */
	public const SEND_OTHER_ORG = 'other_org';

	/** Too many invitations outstanding. */
	public const SEND_TOO_MANY = 'too_many';

	/**
	 * Whether an invitation can be sent, and if not, why.
	 *
	 * Every reason is distinct because every one of them needs different words
	 * on the screen. "Could not invite" tells an owner nothing about what to do
	 * next, and the thing they do next is email DGLP.
	 *
	 * @param UserContext $actor          Who is inviting.
	 * @param int         $org_id         Organisation being invited into.
	 * @param string      $org_role       Role being offered.
	 * @param string      $email          Normalised address.
	 * @param bool        $is_valid_email Whether the address parses.
	 * @param ?int        $existing_org   The org of any existing account at that
	 *                                    address, null if no account or unlinked.
	 * @param bool        $has_open       Whether an open invitation already exists.
	 * @param int         $open_count     Open invitations this organisation holds.
	 */
	public static function can_send(
		UserContext $actor,
		int $org_id,
		string $org_role,
		string $email,
		bool $is_valid_email,
		?int $existing_org,
		bool $has_open,
		int $open_count
	): string {
		if ( ! self::may_invite( $actor, $org_id, $org_role ) ) {
			return self::SEND_DENIED;
		}

		if ( '' === $email || ! $is_valid_email ) {
			return self::SEND_BAD_EMAIL;
		}

		if ( null !== $existing_org && $existing_org === $org_id ) {
			return self::SEND_ALREADY_MEMBER;
		}

		/*
		 * A person belongs to one organisation. Moving somebody between two is
		 * a decision with consequences for who can see what, so it is DGLP's to
		 * make, not something another charity's owner can trigger by typing an
		 * address.
		 */
		if ( null !== $existing_org && $existing_org > 0 ) {
			return self::SEND_OTHER_ORG;
		}

		if ( $has_open ) {
			return self::SEND_ALREADY_INVITED;
		}

		if ( $open_count >= self::MAX_OPEN_PER_ORG ) {
			return self::SEND_TOO_MANY;
		}

		return self::SEND_OK;
	}

	/**
	 * What to say when an invitation cannot be sent.
	 */
	public static function send_error( string $reason ): string {
		return match ( $reason ) {
			self::SEND_DENIED          => __( 'You cannot invite people to this organisation.', 'dgl-platform' ),
			self::SEND_BAD_EMAIL       => __( 'That does not look like an email address. Check it and try again.', 'dgl-platform' ),
			self::SEND_ALREADY_MEMBER  => __( 'They are already part of your organisation.', 'dgl-platform' ),
			self::SEND_ALREADY_INVITED => __( 'They already have an invitation waiting. You can withdraw it and send a new one.', 'dgl-platform' ),
			self::SEND_OTHER_ORG       => __( 'That address already belongs to another organisation on the site. Ask the DGLP team to move the account.', 'dgl-platform' ),
			self::SEND_TOO_MANY        => __( 'There are too many invitations waiting. Withdraw some before sending more.', 'dgl-platform' ),
			default                    => __( 'The invitation could not be sent.', 'dgl-platform' ),
		};
	}

	/* ---------------------------------------------------------------------
	 * Taking one up
	 * ------------------------------------------------------------------ */

	/** Create an account and attach it. */
	public const ACCEPT_CREATE = 'create';

	/** An account already exists and is unattached: attach it. */
	public const ACCEPT_LINK = 'link';

	/** Nothing to do, they are already in. */
	public const ACCEPT_ALREADY_MEMBER = 'already_member';

	/** The account is attached elsewhere. Refuse. */
	public const ACCEPT_OTHER_ORG = 'other_org';

	/** The invitation is not open. */
	public const ACCEPT_CLOSED = 'closed';

	/**
	 * What accepting this invitation should actually do.
	 *
	 * @param ?int $existing_user The account at the invited address, if any.
	 * @param ?int $existing_org  That account's organisation, null if unattached.
	 */
	public static function accept_outcome(
		Invite $invite,
		DateTimeImmutable $now,
		?int $existing_user,
		?int $existing_org
	): string {
		if ( ! self::is_open( $invite, $now ) ) {
			return self::ACCEPT_CLOSED;
		}

		if ( null === $existing_user || $existing_user <= 0 ) {
			return self::ACCEPT_CREATE;
		}

		if ( null !== $existing_org && $existing_org === $invite->org_id ) {
			return self::ACCEPT_ALREADY_MEMBER;
		}

		if ( null !== $existing_org && $existing_org > 0 ) {
			return self::ACCEPT_OTHER_ORG;
		}

		return self::ACCEPT_LINK;
	}

	/**
	 * What to say when an invitation cannot be taken up.
	 *
	 * Written for the person holding the link, who has no idea what any of this
	 * means and only wants to know whether to ask for another one.
	 */
	public static function accept_error( string $outcome, string $state = '' ): string {
		if ( self::ACCEPT_OTHER_ORG === $outcome ) {
			return __( 'Your account is already linked to a different organisation. The DGLP team can move it for you.', 'dgl-platform' );
		}

		return match ( $state ) {
			self::ACCEPTED => __( 'This invitation has already been used. Try signing in instead.', 'dgl-platform' ),
			self::REVOKED  => __( 'This invitation was withdrawn. Ask whoever invited you to send a new one.', 'dgl-platform' ),
			self::EXPIRED  => __( 'This invitation has expired. Ask whoever invited you to send a new one.', 'dgl-platform' ),
			default        => __( 'This invitation is no longer valid. Ask whoever invited you to send a new one.', 'dgl-platform' ),
		};
	}

	/* ---------------------------------------------------------------------
	 * Addresses
	 * ------------------------------------------------------------------ */

	/**
	 * The stored form of an address.
	 *
	 * Lower-cased for comparison. By RFC 5321 the local part is case-sensitive,
	 * but no provider in real use treats it that way, and two invitations to
	 * `Jo@` and `jo@` are two invitations to one person.
	 */
	public static function normalise_email( string $email ): string {
		return strtolower( trim( $email ) );
	}
}

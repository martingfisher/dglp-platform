<?php
/**
 * What an invitation email says.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

/**
 * Invitation wording, held apart from {@see Copy}.
 *
 * Copy is the workflow's voice: every message there answers to a message key
 * the planner emits, and the test suite asserts the two sets line up. An
 * invitation is not a workflow transition, so putting it in there would mean
 * either weakening that check or inventing a transition that never happens.
 *
 * Pure. Takes strings, returns a {@see Message}, touches nothing.
 */
final class InviteCopy {

	public const KEY_INVITED = 'invited';

	/** Invitations go to one person, never to a group. */
	public const AUDIENCE = 'invitee';

	/**
	 * The invitation itself.
	 *
	 * Written for somebody who may never have heard of the partnership and was
	 * not expecting this. It names the organisation, names the person, says
	 * what the account is for and says when the link dies. An invitation that
	 * reads like a system notification gets deleted as phishing, which is the
	 * failure nobody ever finds out about.
	 *
	 * @param string $org_name    The organisation doing the inviting.
	 * @param string $inviter     Who sent it, in words. May be empty.
	 * @param string $role_label  What they would be able to do.
	 * @param string $accept_url  The link.
	 * @param string $expires_on  When it dies, formatted for reading.
	 * @param string $site_name   The site.
	 */
	public static function invited(
		string $org_name,
		string $inviter,
		string $role_label,
		string $accept_url,
		string $expires_on,
		string $site_name
	): Message {
		$org  = '' !== trim( $org_name ) ? $org_name : __( 'a partnership member', 'dgl-platform' );
		$site = '' !== trim( $site_name ) ? $site_name : __( 'the partnership site', 'dgl-platform' );

		$opening = '' !== trim( $inviter )
			? sprintf(
				/* translators: 1: person's name, 2: organisation, 3: site name. */
				__( '%1$s has invited you to help manage %2$s on %3$s.', 'dgl-platform' ),
				$inviter,
				$org,
				$site
			)
			: sprintf(
				/* translators: 1: organisation, 2: site name. */
				__( 'You have been invited to help manage %1$s on %2$s.', 'dgl-platform' ),
				$org,
				$site
			);

		$facts = [
			__( 'Organisation', 'dgl-platform' ) => $org,
			__( 'You would be', 'dgl-platform' ) => $role_label,
		];

		if ( '' !== trim( $expires_on ) ) {
			$facts[ __( 'Link works until', 'dgl-platform' ) ] = $expires_on;
		}

		return new Message(
			key: self::KEY_INVITED,
			audience: self::AUDIENCE,
			subject: sprintf(
				/* translators: 1: organisation, 2: site name. */
				__( 'Join %1$s on %2$s', 'dgl-platform' ),
				$org,
				$site
			),
			preheader: __( 'Set a password and the account is yours.', 'dgl-platform' ),
			heading: sprintf(
				/* translators: %s: organisation. */
				__( 'An invitation from %s', 'dgl-platform' ),
				$org
			),
			paragraphs: [
				$opening,
				__( 'The account lets you post events, news, training, volunteering and grants on the organisation\'s behalf. What you post goes to the partnership team before it appears on the site.', 'dgl-platform' ),
				__( 'Accepting takes a minute: choose a password and you are in. There is nothing to pay and no other sign-up.', 'dgl-platform' ),
			],
			facts: $facts,
			cta_label: __( 'Accept the invitation', 'dgl-platform' ),
			cta_url: $accept_url,
			footnotes: [
				sprintf(
					/* translators: %s: organisation. */
					__( 'You are getting this because somebody at %s entered your email address. If that was not expected, ignore it and the invitation expires on its own. Nothing has been created in your name.', 'dgl-platform' ),
					$org
				),
			]
		);
	}

	/**
	 * Telling the organisation somebody took their invitation up.
	 *
	 * Sent to whoever invited them. Without it an owner has no way of knowing
	 * whether an invitation landed, and the way they find out is by emailing
	 * DGLP to ask.
	 */
	public static function accepted( string $who, string $org_name, string $role_label, string $members_url ): Message {
		return new Message(
			key: 'invite_accepted',
			audience: 'inviter',
			subject: sprintf(
				/* translators: %s: person's name or email. */
				__( '%s has joined your organisation', 'dgl-platform' ),
				$who
			),
			preheader: __( 'Your invitation was accepted.', 'dgl-platform' ),
			heading: __( 'Invitation accepted', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: 1: person, 2: organisation. */
					__( '%1$s has accepted your invitation and now has an account for %2$s.', 'dgl-platform' ),
					$who,
					$org_name
				),
			],
			facts: [
				__( 'They can', 'dgl-platform' ) => $role_label,
			],
			cta_label: __( 'See who has access', 'dgl-platform' ),
			cta_url: $members_url,
			footnotes: [
				__( 'You are getting this because you sent the invitation.', 'dgl-platform' ),
			]
		);
	}
}

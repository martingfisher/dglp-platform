<?php
/**
 * The emails joining sends.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

defined( 'ABSPATH' ) || exit;

final class JoinCopy {

	/** To the person: prove the address. */
	public static function verify( string $link ): Message {
		return new Message(
			key: 'join_verify',
			audience: 'joiner',
			subject: __( 'Confirm your email address for the DGLP member area', 'dgl-platform' ),
			preheader: __( 'One click and you can carry on.', 'dgl-platform' ),
			heading: __( 'Confirm it is you', 'dgl-platform' ),
			paragraphs: [
				__( 'Somebody used this address to start joining the Doing Good Leeds Partnership member area. If that was you, use the button below and you can carry on. If it was not, ignore this and nothing will happen.', 'dgl-platform' ),
			],
			cta_label: __( 'Confirm my email address', 'dgl-platform' ),
			cta_url: $link,
			footnotes: [
				__( 'The link works once and for two days.', 'dgl-platform' ),
			]
		);
	}

	/** To the organisation's owners: somebody arrived by domain. */
	public static function joined( string $who, string $email, string $org_name, string $role_label, string $members_url ): Message {
		return new Message(
			key: 'join_joined',
			audience: 'owners',
			subject: sprintf(
				/* translators: %s: person. */
				__( '%s has joined your organisation', 'dgl-platform' ),
				$who
			),
			preheader: __( 'Their email address is on your domain, so they were let in.', 'dgl-platform' ),
			heading: __( 'Somebody has joined', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: 1: person, 2: email, 3: organisation. */
					__( '%1$s (%2$s) signed up with an address on your organisation\'s email domain, so they now post for %3$s. Nobody had to approve it: the domain is the check.', 'dgl-platform' ),
					$who,
					$email,
					$org_name
				),
				__( 'If you do not recognise them, remove them from the Members page and tell the DGLP team.', 'dgl-platform' ),
			],
			facts: [
				__( 'They can', 'dgl-platform' ) => $role_label,
			],
			cta_label: __( 'See who has access', 'dgl-platform' ),
			cta_url: $members_url,
			footnotes: [
				__( 'You are getting this because you are an owner of the organisation.', 'dgl-platform' ),
			]
		);
	}

	/** To the review team: a new organisation is waiting. */
	public static function awaiting( string $org_name, string $who, string $email, string $review_url ): Message {
		return new Message(
			key: 'join_awaiting',
			audience: 'moderators',
			subject: sprintf(
				/* translators: %s: organisation. */
				__( 'New organisation to verify: %s', 'dgl-platform' ),
				$org_name
			),
			preheader: __( 'Somebody has registered an organisation that is not on the list.', 'dgl-platform' ),
			heading: __( 'A new organisation is waiting', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: 1: person, 2: email, 3: organisation. */
					__( '%1$s (%2$s) has confirmed their email address and registered %3$s. Their address does not match any organisation on the list, so this one needs checking before they can submit anything.', 'dgl-platform' ),
					$who,
					$email,
					$org_name
				),
			],
			cta_label: __( 'Check it and decide', 'dgl-platform' ),
			cta_url: $review_url,
			footnotes: [
				__( 'You are getting this because you are on the review team.', 'dgl-platform' ),
			]
		);
	}

	/** To the person: approved. */
	public static function approved( string $org_name, string $dashboard_url ): Message {
		return new Message(
			key: 'join_approved',
			audience: 'joiner',
			subject: sprintf(
				/* translators: %s: organisation. */
				__( '%s is verified. You can submit now.', 'dgl-platform' ),
				$org_name
			),
			preheader: __( 'The team have checked your organisation.', 'dgl-platform' ),
			heading: __( 'You are in', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: %s: organisation. */
					__( 'The DGLP team have verified %s. Anything you drafted while you waited can be sent for review now, and you can invite colleagues from the Members page.', 'dgl-platform' ),
					$org_name
				),
			],
			cta_label: __( 'Go to your dashboard', 'dgl-platform' ),
			cta_url: $dashboard_url
		);
	}

	/** To the person: refused, with the reason. */
	public static function refused( string $org_name, string $reason ): Message {
		return new Message(
			key: 'join_refused',
			audience: 'joiner',
			subject: sprintf(
				/* translators: %s: organisation. */
				__( 'About your registration for %s', 'dgl-platform' ),
				$org_name
			),
			preheader: __( 'The team could not verify the organisation.', 'dgl-platform' ),
			heading: __( 'Not verified', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: %s: organisation. */
					__( 'The DGLP team could not verify %s, so the member area is not open to it. They said:', 'dgl-platform' ),
					$org_name
				),
				$reason,
				__( 'If you think this is a mistake, reply to the team through the main website and they can look again.', 'dgl-platform' ),
			]
		);
	}
}

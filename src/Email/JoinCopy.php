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
			subject: __( 'Confirm your email address for the DGLP user area', 'dgl-platform' ),
			preheader: __( 'One click and you can carry on.', 'dgl-platform' ),
			heading: __( 'Confirm it is you', 'dgl-platform' ),
			paragraphs: [
				__( 'Somebody used this address to start joining the Doing Good Leeds Partnership user area. If that was you, use the button below and you can carry on. If it was not, ignore this and nothing will happen.', 'dgl-platform' ),
			],
			cta_label: __( 'Confirm my email address', 'dgl-platform' ),
			cta_url: $link,
			footnotes: [
				__( 'The link works once, and for two days. After that, start again from the join page and a new one will be sent.', 'dgl-platform' ),
			]
		);
	}

	/**
	 * To the organisation's owners: somebody arrived, by domain or because
	 * the team checked them.
	 */
	public static function joined( string $who, string $email, string $org_name, string $role_label, string $members_url, bool $by_domain = true ): Message {
		return new Message(
			key: 'join_joined',
			audience: 'owners',
			subject: sprintf(
				/* translators: %s: person. */
				__( '%s has joined your organisation', 'dgl-platform' ),
				$who
			),
			preheader: $by_domain
				? __( 'Their email address is on your domain, so they were let in.', 'dgl-platform' )
				: __( 'The DGLP team checked they are part of your organisation.', 'dgl-platform' ),
			heading: __( 'Somebody has joined', 'dgl-platform' ),
			paragraphs: [
				$by_domain
					? sprintf(
						/* translators: 1: person, 2: email, 3: organisation. */
						__( '%1$s (%2$s) signed up with an address on your organisation\'s email domain, so they now post for %3$s. Nobody had to approve it: the domain is the check.', 'dgl-platform' ),
						$who,
						$email,
						$org_name
					)
					: sprintf(
						/* translators: 1: person, 2: email, 3: organisation. */
						__( '%1$s (%2$s) asked to join %3$s and the DGLP team checked they are part of it, so they now post for it.', 'dgl-platform' ),
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
					__( '%1$s (%2$s) has confirmed their email address and registered %3$s as an organisation that is not on the list. It needs checking before they can submit anything: the review screen shows anything on the list it might be.', 'dgl-platform' ),
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

	/** To the review team: somebody wants to join an organisation on the list. */
	public static function claim_awaiting( string $org_name, string $who, string $email, string $note, string $review_url ): Message {
		return new Message(
			key: 'join_claim_awaiting',
			audience: 'moderators',
			subject: sprintf(
				/* translators: %s: organisation. */
				__( 'Somebody wants to join %s', 'dgl-platform' ),
				$org_name
			),
			preheader: __( 'Their email address is not on the organisation\'s domain, so it needs a check.', 'dgl-platform' ),
			heading: __( 'A joining request is waiting', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: 1: person, 2: email, 3: organisation. */
					__( '%1$s (%2$s) has confirmed their email address and says they are part of %3$s. Their address is not on the organisation\'s email domain, so this needs checking before they can post.', 'dgl-platform' ),
					$who,
					$email,
					$org_name
				),
			],
			facts: '' === $note ? [] : [ __( 'They said', 'dgl-platform' ) => $note ],
			cta_label: __( 'Check it and decide', 'dgl-platform' ),
			cta_url: $review_url,
			footnotes: [
				__( 'You are getting this because you are on the review team.', 'dgl-platform' ),
			]
		);
	}

	/** To the person: their claim on a listed organisation is approved. */
	public static function claim_approved( string $org_name, string $dashboard_url, bool $owner, bool $org_pending ): Message {
		$paragraphs = [
			sprintf(
				/* translators: %s: organisation. */
				__( 'The DGLP team have confirmed you are part of %s.', 'dgl-platform' ),
				$org_name
			),
			$owner
				? __( 'You are its first person here, so you run its page: you can change its details and invite colleagues from the Members page.', 'dgl-platform' )
				: __( 'You can post for it now, and anything you drafted while you waited can be sent for review.', 'dgl-platform' ),
		];

		if ( $org_pending ) {
			$paragraphs[] = __( 'The organisation itself is still waiting to be verified, so submitting waits for that. You can draft in the meantime.', 'dgl-platform' );
		}

		return new Message(
			key: 'join_claim_approved',
			audience: 'joiner',
			subject: sprintf(
				/* translators: %s: organisation. */
				__( 'You are in: %s', 'dgl-platform' ),
				$org_name
			),
			preheader: __( 'The team have checked your request.', 'dgl-platform' ),
			heading: __( 'You are in', 'dgl-platform' ),
			paragraphs: $paragraphs,
			cta_label: __( 'Go to your dashboard', 'dgl-platform' ),
			cta_url: $dashboard_url
		);
	}

	/** To the person: their claim on a listed organisation is refused, with the reason. */
	public static function claim_refused( string $org_name, string $reason ): Message {
		return new Message(
			key: 'join_claim_refused',
			audience: 'joiner',
			subject: sprintf(
				/* translators: %s: organisation. */
				__( 'About your request to join %s', 'dgl-platform' ),
				$org_name
			),
			preheader: __( 'The team could not confirm you are part of the organisation.', 'dgl-platform' ),
			heading: __( 'Not confirmed', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: %s: organisation. */
					__( 'The DGLP team could not confirm you are part of %s, so the user area is not open to you. They said:', 'dgl-platform' ),
					$org_name
				),
				$reason,
				__( 'If you think this is a mistake, reply to the team through the main website and they can look again.', 'dgl-platform' ),
			]
		);
	}

	/** To the person: the team put them in an organisation already on the list. */
	public static function attached( string $org_name, string $typed_name, string $dashboard_url, bool $owner, bool $org_pending ): Message {
		$paragraphs = [
			sprintf(
				/* translators: 1: what was typed, 2: the organisation on the list. */
				__( 'The DGLP team recognised %1$s as %2$s, which is already on the list, so you have been added to it instead of a second entry being created.', 'dgl-platform' ),
				$typed_name,
				$org_name
			),
			$owner
				? __( 'You are its first person here, so you run its page: you can change its details and invite colleagues from the Members page.', 'dgl-platform' )
				: __( 'You can post for it now, and anything you drafted while you waited can be sent for review.', 'dgl-platform' ),
		];

		if ( $org_pending ) {
			$paragraphs[] = __( 'The organisation itself is still waiting to be verified, so submitting waits for that. You can draft in the meantime.', 'dgl-platform' );
		}

		return new Message(
			key: 'join_attached',
			audience: 'joiner',
			subject: sprintf(
				/* translators: %s: organisation. */
				__( 'You have been added to %s', 'dgl-platform' ),
				$org_name
			),
			preheader: __( 'It was already on the list.', 'dgl-platform' ),
			heading: __( 'You are in', 'dgl-platform' ),
			paragraphs: $paragraphs,
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

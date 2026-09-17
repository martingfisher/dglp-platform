<?php
/**
 * Emails about an organisation's own details.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

defined( 'ABSPATH' ) || exit;

/**
 * The review team is told when an organisation asks to change its name or
 * logo. Until this existed the request sat in wp-admin until somebody
 * happened to open the Organisations list and read the column.
 */
final class OrgCopy {

	/**
	 * To the review team: something is waiting.
	 *
	 * @param string[] $labels What is being changed, in the member's words.
	 */
	public static function change_requested( string $org_name, string $who, array $labels, string $review_url ): Message {
		$what = implode( ' and ', array_map( 'strtolower', $labels ) );

		return new Message(
			key: 'org_change_requested',
			audience: 'moderators',
			subject: sprintf(
				/* translators: 1: organisation, 2: what changed, e.g. "name and logo". */
				__( '%1$s has asked to change its %2$s', 'dgl-platform' ),
				$org_name,
				$what
			),
			preheader: __( 'A change to an organisation is waiting for a decision.', 'dgl-platform' ),
			heading: __( 'An organisation change is waiting', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: 1: person, 2: organisation, 3: what changed. */
					__( '%1$s at %2$s has asked to change the organisation\'s %3$s. Their listings carry the current details until you decide.', 'dgl-platform' ),
					$who,
					$org_name,
					$what
				),
			],
			cta_label: __( 'See the change and decide', 'dgl-platform' ),
			cta_url: $review_url,
			footnotes: [
				__( 'You are getting this because you are on the review team.', 'dgl-platform' ),
			]
		);
	}

	/**
	 * To the organisation's owners: the change they asked for is live.
	 *
	 * @param string[] $labels What changed, e.g. ["Organisation name"].
	 */
	public static function change_approved( string $org_name, array $labels, string $profile_url ): Message {
		$what = implode( ' and ', array_map( 'strtolower', $labels ) );

		return new Message(
			key: 'org_change_approved',
			audience: 'member',
			subject: count( $labels ) > 1
				/* translators: %s: what changed, e.g. "name and logo". */
				? sprintf( __( 'Your new %s are live', 'dgl-platform' ), $what )
				/* translators: %s: what changed, e.g. "organisation name". */
				: sprintf( __( 'Your new %s is live', 'dgl-platform' ), $what ),
			preheader: __( 'The review team accepted the change to your organisation.', 'dgl-platform' ),
			heading: __( 'Your organisation change is live', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: 1: organisation, 2: what changed. */
					__( 'The review team accepted the change to %1$s\'s %2$s. Every listing you have posted now carries the new details.', 'dgl-platform' ),
					$org_name,
					$what
				),
			],
			cta_label: __( 'See your organisation profile', 'dgl-platform' ),
			cta_url: $profile_url,
			footnotes: [
				__( 'You are getting this because you are an owner of the organisation in the DGLP member area.', 'dgl-platform' ),
			]
		);
	}

	/**
	 * To the organisation's owners: the change was refused, and why.
	 *
	 * @param string[] $labels What was asked for.
	 */
	public static function change_refused( string $org_name, array $labels, string $note, string $profile_url ): Message {
		$what = implode( ' and ', array_map( 'strtolower', $labels ) );

		return new Message(
			key: 'org_change_rejected',
			audience: 'member',
			subject: sprintf(
				/* translators: 1: what changed, e.g. "name and logo". */
				__( 'Your %1$s change was not accepted', 'dgl-platform' ),
				$what
			),
			preheader: __( 'The review team did not accept the change to your organisation. Their reason is inside.', 'dgl-platform' ),
			heading: __( 'Your organisation change was not accepted', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: 1: organisation, 2: what changed. */
					__( 'The review team did not accept the change to %1$s\'s %2$s. Your listings keep the details they had. You can ask again from your profile once you have read the reason below.', 'dgl-platform' ),
					$org_name,
					$what
				),
			],
			note: $note,
			note_label: __( 'From the review team', 'dgl-platform' ),
			cta_label: __( 'Go to your organisation profile', 'dgl-platform' ),
			cta_url: $profile_url,
			footnotes: [
				__( 'You are getting this because you are an owner of the organisation in the DGLP member area.', 'dgl-platform' ),
			]
		);
	}
}

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
}

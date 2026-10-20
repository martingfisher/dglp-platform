<?php
/**
 * Emails about spaces to hire.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

defined( 'ABSPATH' ) || exit;

/**
 * One email so far: somebody asking a venue about a space. It goes to
 * the venue's contact with the enquirer as Reply-To, so answering it is
 * pressing Reply.
 */
final class SpacesCopy {

	public const ENQUIRY = 'space_enquiry';

	/**
	 * @param array<string, string> $values The validated enquiry: date, time_from, time_to, people, message, name, email, phone.
	 */
	public static function enquiry( string $venue_name, string $space_label, array $values, string $item_url, string $public_url ): Message {
		// The date is optional. Without one, "When" is only the times, or left out.
		$when = $values['date'];

		if ( '' !== $when ) {
			try {
				$when = (string) wp_date( (string) get_option( 'date_format', 'j F Y' ), ( new \DateTimeImmutable( $values['date'], wp_timezone() ) )->getTimestamp() );
			} catch ( \Exception ) {
				// The date was validated; the raw value stands in if formatting fails.
			}
		}

		if ( '' !== $values['time_from'] ) {
			$when .= ( '' !== $when ? ', ' : '' ) . $values['time_from'] . ( '' !== $values['time_to'] ? ' ' . __( 'to', 'dgl-platform' ) . ' ' . $values['time_to'] : '' );
		}

		$facts = array_filter(
			[
				__( 'Space', 'dgl-platform' )  => $space_label,
				__( 'When', 'dgl-platform' )   => $when,
				__( 'People', 'dgl-platform' ) => $values['people'],
				__( 'From', 'dgl-platform' )   => $values['name'],
				__( 'Email', 'dgl-platform' )  => $values['email'],
				__( 'Phone', 'dgl-platform' )  => $values['phone'],
			]
		);

		return new Message(
			key: self::ENQUIRY,
			audience: 'venue',
			subject: sprintf(
				/* translators: 1: a space or "Not sure yet", 2: the venue. */
				__( 'Enquiry about %1$s at %2$s', 'dgl-platform' ),
				$space_label,
				$venue_name
			),
			preheader: sprintf(
				/* translators: 1: a person, 2: a date. */
				__( '%1$s is asking about %2$s. Reply to this email to answer them.', 'dgl-platform' ),
				$values['name'],
				'' !== $when ? $when : $space_label
			),
			heading: __( 'Somebody is asking about your space', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: 1: a person, 2: the venue, 3: what they asked about. */
					__( '%1$s has sent an enquiry through the Doing Good Leeds Partnership site about %2$s. They asked about: %3$s.', 'dgl-platform' ),
					$values['name'],
					$venue_name,
					$space_label
				),
				__( 'Reply to this email to answer them: it goes straight to the address they gave. The partnership is not part of the conversation and takes no booking.', 'dgl-platform' ),
			],
			facts: $facts,
			note: $values['message'],
			note_label: __( 'Their message', 'dgl-platform' ),
			cta_label: __( 'See your venue in the user area', 'dgl-platform' ),
			cta_url: $item_url,
			footnotes: [
				Footer::sent_because(
					sprintf(
						/* translators: %s: "a venue listed on the site", linked to the venue's page. */
						__( 'you are the contact for %s.', 'dgl-platform' ),
						Footer::link( __( 'a venue listed on the site', 'dgl-platform' ), $public_url )
					)
				),
				// No "reply to this email": a reply goes to the person enquiring.
				__( 'The person enquiring typed their own details; we have not checked them.', 'dgl-platform' ),
			]
		);
	}
}

<?php
/**
 * What a digest says.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email\Digest;

use DGL\Email\Message;

/**
 * Digest wording. Pure: takes strings, returns a {@see Message}.
 *
 * Held apart from {@see \DGL\Email\Copy} for the same reason the invitation
 * wording is. That class answers to the workflow's message keys and the suite
 * asserts the two sets line up; a digest is not a transition.
 */
final class Copy {

	public const KEY = 'digest';

	public const AUDIENCE = 'subscriber';

	/**
	 * The digest itself.
	 *
	 * The subject leads with the count, because the decision to open is made in
	 * a list of subject lines and "5 new things" answers the only question
	 * being asked there. The unsubscribe link is a footnote on every send,
	 * never buried and never conditional.
	 *
	 * @param array<int, array{title:string, meta:string, url:string, summary:string}> $items
	 */
	public static function digest(
		array $items,
		string $frequency,
		string $site_name,
		string $unsubscribe_url,
		string $preferences_url,
		string $browse_url = ''
	): Message {
		$count = count( $items );
		$site  = '' !== trim( $site_name ) ? $site_name : __( 'the partnership', 'dgl-platform' );

		$subject = sprintf(
			/* translators: 1: number of items, 2: site name. */
			_n( '%1$d new thing from %2$s', '%1$d new things from %2$s', $count, 'dgl-platform' ),
			$count,
			$site
		);

		$cadence = match ( $frequency ) {
			Frequency::DAILY   => __( 'You asked to hear about these every day.', 'dgl-platform' ),
			Frequency::MONTHLY => __( 'You asked to hear about these once a month.', 'dgl-platform' ),
			default            => __( 'You asked to hear about these once a week.', 'dgl-platform' ),
		};

		return new Message(
			key: self::KEY,
			audience: self::AUDIENCE,
			subject: $subject,
			preheader: self::preheader( $items ),
			heading: sprintf(
				/* translators: %s: site name. */
				__( 'New from %s', 'dgl-platform' ),
				$site
			),
			paragraphs: [
				_n(
					'Here is what member organisations have posted since your last digest.',
					'Here is what member organisations have posted since your last digest.',
					$count,
					'dgl-platform'
				),
			],
			cta_label: '' !== $browse_url ? __( 'See everything', 'dgl-platform' ) : '',
			cta_url: $browse_url,
			footnotes: [
				$cadence,
				sprintf(
					/* translators: 1: preferences URL, 2: unsubscribe URL. */
					__( 'Change what you get: %1$s - Stop these emails: %2$s', 'dgl-platform' ),
					$preferences_url,
					$unsubscribe_url
				),
			],
			items: $items
		);
	}

	/**
	 * The grey line inboxes show after the subject.
	 *
	 * The first two titles, because that is what makes somebody open it. A
	 * generic line here wastes the only other piece of text an inbox shows.
	 *
	 * @param array<int, array{title:string, meta:string, url:string, summary:string}> $items
	 */
	private static function preheader( array $items ): string {
		$titles = [];

		foreach ( array_slice( $items, 0, 2 ) as $item ) {
			$title = trim( (string) ( $item['title'] ?? '' ) );

			if ( '' !== $title ) {
				$titles[] = $title;
			}
		}

		if ( [] === $titles ) {
			return '';
		}

		$line = implode( ', ', $titles );

		if ( count( $items ) > count( $titles ) ) {
			$line .= sprintf(
				/* translators: %d: how many more items there are. */
				_n( ' and %d more', ' and %d more', count( $items ) - count( $titles ), 'dgl-platform' ),
				count( $items ) - count( $titles )
			);
		}

		return $line;
	}

	/**
	 * Confirmation that somebody has been taken off the list.
	 *
	 * Sent nowhere: this is the wording for the screen, not an email. Emailing
	 * somebody who just asked to stop being emailed is the one thing they have
	 * explicitly said they do not want.
	 */
	public static function unsubscribed_message(): string {
		return __( 'Done. You will not get any more digests. Your account and your submissions are untouched.', 'dgl-platform' );
	}
}

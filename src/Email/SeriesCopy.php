<?php
/**
 * The words for a repeating event's owners.
 *
 * Pure: takes strings, returns a Message, so every line can be tested
 * without a post or a database.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

use DGL\Workflow\Plan;

defined( 'ABSPATH' ) || exit;

final class SeriesCopy {

	/**
	 * Two weeks before a series ends: is it still running?
	 *
	 * One question, one button. The button lands on a page with its own
	 * confirm, so a mail scanner that follows every link cannot extend
	 * anything by accident, and the member is not asked to sign in.
	 *
	 * @param string $title      The event.
	 * @param string $until      The last date, as words ("31 March 2027").
	 * @param string $extend_url The one-click link.
	 * @param string $item_url   The item in the dashboard.
	 * @param string $site       The site name.
	 */
	/**
	 * Two weeks before an undated listing's spell ends: is it still current?
	 *
	 * @param string $spell The spell in words, "3 months".
	 */
	public static function listing_ending( string $title, string $until, string $extend_url, string $item_url, string $site, string $spell ): Message {
		$title = '' !== trim( $title ) ? trim( $title ) : __( 'Untitled', 'dgl-platform' );

		return new Message(
			key: 'listing_ending_soon',
			audience: Plan::NOTIFY_MEMBER,
			/* translators: %s: item title. */
			subject: sprintf( __( 'Is this still current? %s', 'dgl-platform' ), $title ),
			/* translators: %s: a date. */
			preheader: sprintf( __( 'It comes off the site on %s unless you keep it.', 'dgl-platform' ), $until ),
			heading: __( 'Is this still current?', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: 1: item title, 2: site name, 3: a date. */
					__( '%1$s has been on %2$s for a while, and it comes off the site on %3$s. Nothing stays up forever, so the site does not fill with old news.', 'dgl-platform' ),
					$title,
					$site,
					$until
				),
				sprintf(
					/* translators: %s: a spell like "3 months". */
					__( 'If it is still current, one click keeps it listed for another %s. Nothing goes through review and nothing else changes.', 'dgl-platform' ),
					$spell
				),
				__( 'If it has had its day, you do not need to do anything. It comes off on the date above and stays in your dashboard.', 'dgl-platform' ),
			],
			facts: [
				__( 'Listing', 'dgl-platform' )   => $title,
				__( 'Last date', 'dgl-platform' ) => $until,
			],
			cta_label: __( 'Yes, still current: keep it listed', 'dgl-platform' ),
			cta_url: $extend_url,
			footnotes: [
				sprintf(
					/* translators: %s: a URL. */
					__( 'The link works once. To change the words instead, open it in your dashboard: %s', 'dgl-platform' ),
					$item_url
				),
			],
		);
	}

	public static function ending_soon( string $title, string $until, string $extend_url, string $item_url, string $site ): Message {
		$title = '' !== trim( $title ) ? trim( $title ) : __( 'Untitled event', 'dgl-platform' );

		return new Message(
			key: 'series_ending_soon',
			audience: Plan::NOTIFY_MEMBER,
			/* translators: %s: event title. */
			subject: sprintf( __( 'Is this still running? %s', 'dgl-platform' ), $title ),
			/* translators: %s: a date. */
			preheader: sprintf( __( 'It comes off the site on %s unless you keep it going.', 'dgl-platform' ), $until ),
			heading: __( 'Is this still running?', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: 1: event title, 2: site name, 3: a date. */
					__( '%1$s is listed on %2$s as a repeating event, and its last listed date is %3$s. After that it comes off the site on its own.', 'dgl-platform' ),
					$title,
					$site,
					$until
				),
				__( 'If it is still running, one click keeps it listed for another six months. Nothing goes through review and nothing else changes.', 'dgl-platform' ),
				__( 'If it has stopped, you do not need to do anything. It comes off the site on the date above and stays in your dashboard.', 'dgl-platform' ),
			],
			facts: [
				__( 'Event', 'dgl-platform' )     => $title,
				__( 'Last date', 'dgl-platform' ) => $until,
			],
			cta_label: __( 'Yes, still running: keep it listed', 'dgl-platform' ),
			cta_url: $extend_url,
			footnotes: [
				sprintf(
					/* translators: %s: a URL. */
					__( 'The link works once. To change the days or times instead, open the event in your dashboard: %s', 'dgl-platform' ),
					$item_url
				),
			],
		);
	}
}

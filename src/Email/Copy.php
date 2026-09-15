<?php
/**
 * What each transactional email actually says.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

use DGL\Workflow\Plan;

/**
 * Every word the workflow sends, in one file.
 *
 * Copy lives here rather than inside templates so that it can be read as a set.
 * Eleven notifications written in eleven different files end up with eleven
 * different tones, and the member who gets four of them notices.
 *
 * Three rules hold across all of them:
 *
 * - Say what happened to the thing they submitted, first line, no preamble.
 * - Say where it is now: on the site, in the queue, or off the site.
 * - Give them one thing to click. Never two.
 *
 * The same message key reads differently depending on who is getting it. A
 * take-down tells a member their content came off the site and tells the review
 * team that it is back in their queue, so audience is part of the lookup rather
 * than a conditional buried in a template.
 */
final class Copy {

	/**
	 * Compose one notification, or null when that audience is not written for.
	 *
	 * Returning null rather than a fallback is deliberate. A combination that
	 * has no copy is a gap in the workflow, and a generic "something happened"
	 * email would hide it.
	 */
	public static function compose( string $key, string $audience, Context $c ): ?Message {
		$method = 'compose_' . $key;

		if ( ! method_exists( self::class, $method ) ) {
			return null;
		}

		/** @var ?Message $message */
		$message = self::{$method}( $audience, $c );

		return $message;
	}

	/**
	 * Every message key this class can write, for the test suite to check
	 * against the workflow planner. A key the planner emits and this does not
	 * is a silent notification failure, so it gets asserted rather than hoped.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		$keys = [];

		foreach ( get_class_methods( self::class ) as $method ) {
			if ( str_starts_with( $method, 'compose_' ) ) {
				$keys[] = substr( $method, 8 );
			}
		}

		sort( $keys );

		return $keys;
	}

	/* ---------------------------------------------------------------------
	 * Submission
	 * ------------------------------------------------------------------ */

	private static function compose_submitted( string $audience, Context $c ): ?Message {
		if ( Plan::NOTIFY_MODERATORS !== $audience ) {
			return null;
		}

		return new Message(
			key: 'submitted',
			audience: $audience,
			/* translators: 1: content type, 2: item title. */
			subject: sprintf( __( 'New %1$s for review: %2$s', 'dgl-platform' ), $c->type_lower(), $c->title() ),
			preheader: sprintf(
				/* translators: %s: organisation name. */
				__( '%s is waiting on a decision.', 'dgl-platform' ),
				$c->org()
			),
			heading: $c->title(),
			paragraphs: [
				sprintf(
					/* translators: 1: organisation, 2: content type with its article, for example "an event". */
					__( '%1$s has submitted %2$s for review. Nothing is on the site until somebody approves it.', 'dgl-platform' ),
					$c->org(),
					$c->type_with_article()
				),
			],
			facts: self::facts( $c, [ 'org', 'type', 'actor' ] ),
			cta_label: __( 'Review this submission', 'dgl-platform' ),
			cta_url: $c->review_link(),
			footnotes: self::footnotes( $audience, $c ),
		);
	}

	private static function compose_published_on_trust( string $audience, Context $c ): ?Message {
		if ( Plan::NOTIFY_MEMBER === $audience ) {
			return new Message(
				key: 'published_on_trust',
				audience: $audience,
				/* translators: 1: content type, 2: item title. */
				subject: sprintf( __( 'Your %1$s is live: %2$s', 'dgl-platform' ), $c->type_lower(), $c->title() ),
				preheader: __( 'It went straight on to the site.', 'dgl-platform' ),
				heading: __( 'It is on the site', 'dgl-platform' ),
				paragraphs: [
					sprintf(
						/* translators: 1: item title, 2: organisation. */
						__( '%1$s was published straight away, because %2$s is a trusted organisation on the Partnership.', 'dgl-platform' ),
						$c->title(),
						$c->org()
					),
					__( 'The DGLP team still read trusted submissions, so they may come back to you about it.', 'dgl-platform' ),
				],
				facts: self::facts( $c, [ 'type', 'org', 'expires' ] ),
				cta_label: __( 'View it on the site', 'dgl-platform' ),
				cta_url: $c->public_link(),
				footnotes: self::footnotes( $audience, $c ),
			);
		}

		if ( Plan::NOTIFY_MODERATORS !== $audience ) {
			return null;
		}

		return new Message(
			key: 'published_on_trust',
			audience: $audience,
			/* translators: %s: item title. */
			subject: sprintf( __( 'Published on trust: %s', 'dgl-platform' ), $c->title() ),
			preheader: __( 'Live on the site without review. Read it when you can.', 'dgl-platform' ),
			heading: $c->title(),
			paragraphs: [
				sprintf(
					/* translators: 1: organisation, 2: content type. */
					__( '%1$s is a trusted organisation, so this %2$s went live without review. It is on the site now.', 'dgl-platform' ),
					$c->org(),
					$c->type_lower()
				),
				__( 'Read it when you can. You can take it down from the review screen if it needs it.', 'dgl-platform' ),
			],
			facts: self::facts( $c, [ 'org', 'type', 'actor' ] ),
			cta_label: __( 'Read it', 'dgl-platform' ),
			cta_url: $c->review_link(),
			footnotes: self::footnotes( $audience, $c ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Decisions
	 * ------------------------------------------------------------------ */

	private static function compose_approved( string $audience, Context $c ): ?Message {
		if ( Plan::NOTIFY_MEMBER !== $audience ) {
			return null;
		}

		return new Message(
			key: 'approved',
			audience: $audience,
			/* translators: %s: item title. */
			subject: sprintf( __( 'Approved: %s', 'dgl-platform' ), $c->title() ),
			preheader: __( 'It is on the site now.', 'dgl-platform' ),
			/* translators: %s: content type. */
			heading: sprintf( __( 'Your %s is live', 'dgl-platform' ), $c->type_lower() ),
			paragraphs: [
				sprintf(
					/* translators: 1: item title, 2: site name. */
					__( 'The DGLP team have approved %1$s. It is on %2$s now.', 'dgl-platform' ),
					$c->title(),
					$c->site()
				),
			],
			facts: self::facts( $c, [ 'type', 'org', 'expires' ] ),
			note: $c->note,
			note_label: __( 'Note from the review team', 'dgl-platform' ),
			cta_label: __( 'View it on the site', 'dgl-platform' ),
			cta_url: $c->public_link(),
			footnotes: self::footnotes( $audience, $c ),
		);
	}

	private static function compose_changes_requested( string $audience, Context $c ): ?Message {
		if ( Plan::NOTIFY_MEMBER !== $audience ) {
			return null;
		}

		return new Message(
			key: 'changes_requested',
			audience: $audience,
			/* translators: %s: item title. */
			subject: sprintf( __( 'Changes needed: %s', 'dgl-platform' ), $c->title() ),
			preheader: __( 'Make the changes and send it back.', 'dgl-platform' ),
			heading: __( 'The team have asked for a change', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: %s: item title. */
					__( '%s is not on the site yet. Make the changes below and send it back for review.', 'dgl-platform' ),
					$c->title()
				),
				__( 'Nothing is lost. Your submission is waiting in your dashboard exactly as you left it.', 'dgl-platform' ),
			],
			facts: self::facts( $c, [ 'type', 'org' ] ),
			note: $c->note,
			note_label: __( 'What needs to change', 'dgl-platform' ),
			cta_label: __( 'Edit and resubmit', 'dgl-platform' ),
			cta_url: $c->member_link(),
			footnotes: self::footnotes( $audience, $c ),
		);
	}

	private static function compose_rejected( string $audience, Context $c ): ?Message {
		if ( Plan::NOTIFY_MEMBER !== $audience ) {
			return null;
		}

		return new Message(
			key: 'rejected',
			audience: $audience,
			/* translators: %s: item title. */
			subject: sprintf( __( 'Not approved: %s', 'dgl-platform' ), $c->title() ),
			preheader: __( 'The reason is in the email.', 'dgl-platform' ),
			heading: __( 'This one was not approved', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: 1: item title, 2: site name. */
					__( 'The DGLP team have not approved %1$s, so it will not appear on %2$s. Their reason is below.', 'dgl-platform' ),
					$c->title(),
					$c->site()
				),
				__( 'You can still submit other content. This decision applies to this item only.', 'dgl-platform' ),
			],
			facts: self::facts( $c, [ 'type', 'org' ] ),
			note: $c->note,
			note_label: __( 'Why', 'dgl-platform' ),
			cta_label: __( 'See the submission', 'dgl-platform' ),
			cta_url: $c->member_link(),
			footnotes: self::footnotes( $audience, $c ),
		);
	}

	/* ---------------------------------------------------------------------
	 * After publication
	 * ------------------------------------------------------------------ */

	private static function compose_taken_down( string $audience, Context $c ): ?Message {
		if ( Plan::NOTIFY_MEMBER === $audience ) {
			return new Message(
				key: 'taken_down',
				audience: $audience,
				/* translators: %s: item title. */
				subject: sprintf( __( 'Taken down: %s', 'dgl-platform' ), $c->title() ),
				preheader: __( 'It has come off the site while the team look at it.', 'dgl-platform' ),
				/* translators: %s: content type. */
				heading: sprintf( __( 'Your %s has come off the site', 'dgl-platform' ), $c->type_lower() ),
				paragraphs: [
					sprintf(
						/* translators: %s: item title. */
						__( 'The DGLP team have taken %s off the site while they look at it. Their reason is below.', 'dgl-platform' ),
						$c->title()
					),
				],
				facts: self::facts( $c, [ 'type', 'org' ] ),
				note: $c->note,
				note_label: __( 'Why it was taken down', 'dgl-platform' ),
				cta_label: __( 'See the submission', 'dgl-platform' ),
				cta_url: $c->member_link(),
				footnotes: self::footnotes( $audience, $c ),
			);
		}

		if ( Plan::NOTIFY_MODERATORS !== $audience ) {
			return null;
		}

		return new Message(
			key: 'taken_down',
			audience: $audience,
			/* translators: %s: item title. */
			subject: sprintf( __( 'Taken down: %s', 'dgl-platform' ), $c->title() ),
			preheader: __( 'Off the site and back in the queue.', 'dgl-platform' ),
			heading: $c->title(),
			paragraphs: [
				sprintf(
					/* translators: 1: who did it, 2: content type. */
					__( '%1$s took this %2$s off the site. It is back in the review queue and the member has been told.', 'dgl-platform' ),
					$c->actor(),
					$c->type_lower()
				),
			],
			facts: self::facts( $c, [ 'org', 'type', 'actor' ] ),
			note: $c->note,
			note_label: __( 'Reason given', 'dgl-platform' ),
			cta_label: __( 'Open the review queue', 'dgl-platform' ),
			cta_url: $c->review_link(),
			footnotes: self::footnotes( $audience, $c ),
		);
	}

	private static function compose_expired( string $audience, Context $c ): ?Message {
		if ( Plan::NOTIFY_MEMBER !== $audience ) {
			return null;
		}

		return new Message(
			key: 'expired',
			audience: $audience,
			/* translators: %s: item title. */
			subject: sprintf( __( 'Expired: %s', 'dgl-platform' ), $c->title() ),
			preheader: __( 'It has passed its date, so it is no longer listed.', 'dgl-platform' ),
			heading: __( 'This has come off the site', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: %s: item title. */
					__( '%s has passed its date, so it is no longer listed. Nobody made this decision. It is automatic.', 'dgl-platform' ),
					$c->title()
				),
				__( 'It is still in your dashboard. If you want to run it again, copy it into a new submission with the new dates.', 'dgl-platform' ),
			],
			facts: self::facts( $c, [ 'type', 'org', 'expires' ] ),
			cta_label: __( 'See the submission', 'dgl-platform' ),
			cta_url: $c->member_link(),
			footnotes: self::footnotes( $audience, $c ),
		);
	}

	private static function compose_archived_by_team( string $audience, Context $c ): ?Message {
		if ( Plan::NOTIFY_MEMBER !== $audience ) {
			return null;
		}

		return new Message(
			key: 'archived_by_team',
			audience: $audience,
			/* translators: %s: item title. */
			subject: sprintf( __( 'Archived: %s', 'dgl-platform' ), $c->title() ),
			preheader: __( 'The DGLP team have archived it.', 'dgl-platform' ),
			heading: __( 'The team have archived this', 'dgl-platform' ),
			paragraphs: [
				sprintf(
					/* translators: 1: item title, 2: site name. */
					__( 'The DGLP team have archived %1$s, so it is no longer on %2$s. Their reason is below.', 'dgl-platform' ),
					$c->title(),
					$c->site()
				),
			],
			facts: self::facts( $c, [ 'type', 'org', 'actor' ] ),
			note: $c->note,
			note_label: __( 'Why', 'dgl-platform' ),
			cta_label: __( 'See the submission', 'dgl-platform' ),
			cta_url: $c->member_link(),
			footnotes: self::footnotes( $audience, $c ),
		);
	}

	private static function compose_restored( string $audience, Context $c ): ?Message {
		if ( Plan::NOTIFY_MODERATORS !== $audience ) {
			return null;
		}

		return new Message(
			key: 'restored',
			audience: $audience,
			/* translators: %s: item title. */
			subject: sprintf( __( 'Back for review: %s', 'dgl-platform' ), $c->title() ),
			preheader: __( 'Restored from an archive. Not on the site.', 'dgl-platform' ),
			heading: $c->title(),
			paragraphs: [
				sprintf(
					/* translators: 1: organisation, 2: content type. */
					__( '%1$s has restored this %2$s from their archive. It is in the queue and it is not on the site.', 'dgl-platform' ),
					$c->org(),
					$c->type_lower()
				),
			],
			facts: self::facts( $c, [ 'org', 'type', 'actor' ] ),
			cta_label: __( 'Review this submission', 'dgl-platform' ),
			cta_url: $c->review_link(),
			footnotes: self::footnotes( $audience, $c ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Shared parts
	 * ------------------------------------------------------------------ */

	/**
	 * The small table under the body.
	 *
	 * Built by name so that every email shows the same facts in the same order,
	 * and so a fact with nothing behind it is left out rather than printed
	 * blank. An "Expires: " with nothing after it reads as a bug.
	 *
	 * @param string[] $wanted
	 * @return array<string, string>
	 */
	private static function facts( Context $c, array $wanted ): array {
		$available = [
			'org'     => [ __( 'Organisation', 'dgl-platform' ), trim( $c->org_name ) ],
			'type'    => [ __( 'Type', 'dgl-platform' ), trim( $c->type_label ) ],
			'actor'   => [ __( 'Submitted by', 'dgl-platform' ), trim( $c->actor_name ) ],
			'expires' => [ __( 'Comes off the site', 'dgl-platform' ), trim( $c->expires_on ) ],
		];

		$facts = [];

		foreach ( $wanted as $name ) {
			if ( ! isset( $available[ $name ] ) ) {
				continue;
			}

			[ $label, $value ] = $available[ $name ];

			if ( '' === $value ) {
				continue;
			}

			$facts[ $label ] = $value;
		}

		return $facts;
	}

	/**
	 * Why this email arrived.
	 *
	 * These are not marketing, so there is no unsubscribe link and it would be
	 * misleading to imply one. Saying plainly what the email is and that it
	 * cannot be switched off is better than a link that does nothing.
	 *
	 * @return string[]
	 */
	private static function footnotes( string $audience, Context $c ): array {
		if ( Plan::NOTIFY_MODERATORS === $audience ) {
			return [
				sprintf(
					/* translators: %s: site name. */
					__( 'You are getting this because you review content for %s.', 'dgl-platform' ),
					$c->site()
				),
			];
		}

		return [
			sprintf(
				/* translators: 1: organisation, 2: site name. */
				__( 'You are getting this because %1$s has an account on %2$s.', 'dgl-platform' ),
				$c->org(),
				$c->site()
			),
			__( 'It is about your own submission, so it is not a newsletter and there is nothing to unsubscribe from. Your digest preferences are separate and you can change those in your dashboard.', 'dgl-platform' ),
		];
	}
}

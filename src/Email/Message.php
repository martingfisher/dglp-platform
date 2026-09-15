<?php
/**
 * One email, decided before anything knows how to render or send it.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

/**
 * An email as data.
 *
 * {@see Copy} decides what an email says, {@see Template} decides what it looks
 * like and {@see Mailer} sends it. Holding the message as a value object in
 * between means the wording can be tested without a mail server and the layout
 * can change without touching a word of copy.
 *
 * The plain-text alternative is generated here rather than written twice. Two
 * hand-maintained versions of the same message drift, and the one that drifts
 * is always the one nobody looks at.
 */
final readonly class Message {

	/**
	 * @param string   $key        The message key from the workflow plan.
	 * @param string   $audience   Plan::NOTIFY_MEMBER or Plan::NOTIFY_MODERATORS.
	 * @param string   $subject    Subject line.
	 * @param string   $preheader  The grey line inboxes show after the subject.
	 * @param string   $heading    First line inside the email.
	 * @param string[] $paragraphs Body copy, one string per paragraph.
	 * @param array<string, string> $facts Label to value, shown as a small table.
	 * @param string   $note       A moderator's message to the member, if any.
	 * @param string   $note_label Heading above that message.
	 * @param string   $cta_label  Button text. Empty means no button.
	 * @param string   $cta_url    Where the button goes.
	 * @param string[] $footnotes  Small print under the body.
	 * @param string[] $to         Recipient addresses. Filled in by the mailer.
	 */
	public function __construct(
		public string $key,
		public string $audience,
		public string $subject,
		public string $preheader = '',
		public string $heading = '',
		public array $paragraphs = [],
		public array $facts = [],
		public string $note = '',
		public string $note_label = '',
		public string $cta_label = '',
		public string $cta_url = '',
		public array $footnotes = [],
		public array $to = [],
	) {}

	/**
	 * The same message addressed to somebody.
	 *
	 * @param string[] $to
	 */
	public function for_recipients( array $to ): self {
		return new self(
			key: $this->key,
			audience: $this->audience,
			subject: $this->subject,
			preheader: $this->preheader,
			heading: $this->heading,
			paragraphs: $this->paragraphs,
			facts: $this->facts,
			note: $this->note,
			note_label: $this->note_label,
			cta_label: $this->cta_label,
			cta_url: $this->cta_url,
			footnotes: $this->footnotes,
			to: $to,
		);
	}

	/**
	 * The same message with a different subject, for the staging redirect.
	 */
	public function with_subject( string $subject ): self {
		$next = $this->for_recipients( $this->to );

		return new self(
			key: $next->key,
			audience: $next->audience,
			subject: $subject,
			preheader: $next->preheader,
			heading: $next->heading,
			paragraphs: $next->paragraphs,
			facts: $next->facts,
			note: $next->note,
			note_label: $next->note_label,
			cta_label: $next->cta_label,
			cta_url: $next->cta_url,
			footnotes: $next->footnotes,
			to: $next->to,
		);
	}

	public function has_cta(): bool {
		return '' !== $this->cta_label && '' !== $this->cta_url;
	}

	public function has_note(): bool {
		return '' !== trim( $this->note );
	}

	/**
	 * The plain-text alternative.
	 *
	 * Not a stripped-down version of the HTML. It carries the same words, the
	 * same facts, the same note and the same link, because a member reading in
	 * plain text is reading the whole message, not a summary of it.
	 */
	public function to_text(): string {
		$lines = [];

		if ( '' !== $this->heading ) {
			$lines[] = $this->heading;
			$lines[] = str_repeat( '=', min( 60, strlen( $this->heading ) ) );
			$lines[] = '';
		}

		foreach ( $this->paragraphs as $paragraph ) {
			$lines[] = wordwrap( $paragraph, 72 );
			$lines[] = '';
		}

		foreach ( $this->facts as $label => $value ) {
			$lines[] = $label . ': ' . $value;
		}

		if ( [] !== $this->facts ) {
			$lines[] = '';
		}

		if ( $this->has_note() ) {
			$lines[] = ( '' !== $this->note_label ? $this->note_label : 'Message from the review team' ) . ':';
			$lines[] = wordwrap( trim( $this->note ), 72 );
			$lines[] = '';
		}

		if ( $this->has_cta() ) {
			$lines[] = $this->cta_label . ': ' . $this->cta_url;
			$lines[] = '';
		}

		foreach ( $this->footnotes as $footnote ) {
			$lines[] = wordwrap( $footnote, 72 );
		}

		return rtrim( implode( "\n", $lines ) ) . "\n";
	}
}

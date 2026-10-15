<?php
/**
 * The small print under every email.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

defined( 'ABSPATH' ) || exit;

/**
 * One voice for the footer of every email: who sent it and why, what the
 * reader can change, and where to ask. Agreed with DGLP 15 October 2026.
 *
 * A footnote may carry a link as `[words](https://...)`. The HTML email
 * shows the words as the link; the plain-text part shows the words and
 * the address in brackets, so nothing is lost in a client that does not
 * render HTML. Bare addresses are still made clickable, as before.
 */
final class Footer {

	/**
	 * Why the email arrived, from the team that sent it. `$reason` carries
	 * on the sentence: "%s has an account on DGL.org.uk." and the like.
	 */
	public static function sent_because( string $reason ): string {
		/* translators: %s: the rest of the sentence, starting in lower case, e.g. "you are on the review team." */
		return sprintf( __( 'Sent by the Doing Good Leeds Partnership team because %s', 'dgl-platform' ), $reason );
	}

	/**
	 * Where to ask. Only for email whose replies reach the review team: the
	 * reply address is the team inbox. Not for email to the team itself, nor
	 * for an enquiry, whose replies go to the person enquiring.
	 */
	public static function questions(): string {
		return __( 'Questions? Reply to this email and the review team will get back to you.', 'dgl-platform' );
	}

	/**
	 * Link words to an address, in the footnote syntax. With no address the
	 * words stand alone rather than as a link to nowhere. Pure.
	 */
	public static function link( string $words, string $url ): string {
		$url = trim( $url );

		return '' === $url ? $words : '[' . str_replace( [ '[', ']' ], '', $words ) . '](' . $url . ')';
	}

	/** Links and bare addresses, in one pass so a link's address is never linked twice. */
	private const PATTERN = '#\[([^\]\n]+)\]\((https?://[^\s)]+)\)|(https?://[^\s<>"\']+)#i';

	/**
	 * A footnote for the plain-text part: "[words](url)" becomes
	 * "words (url)". Pure.
	 */
	public static function plain( string $footnote ): string {
		return (string) preg_replace( '#\[([^\]\n]+)\]\((https?://[^\s)]+)\)#i', '$1 ($2)', $footnote );
	}

	/**
	 * A footnote as HTML: escaped first, as a whole, then links and bare
	 * addresses made clickable. Nothing in the text reaches an href
	 * unescaped. Pure but for esc_html and esc_url.
	 */
	public static function html( string $footnote, string $colour ): string {
		return (string) preg_replace_callback(
			self::PATTERN,
			static function ( array $m ) use ( $colour ): string {
				$style = ' style="color:' . $colour . ';text-decoration:underline;"';

				if ( '' !== ( $m[1] ?? '' ) ) {
					return '<a href="' . esc_url( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ) ) . '"' . $style . '>' . $m[1] . '</a>';
				}

				// A trailing full stop belongs to the sentence, not the address.
				$url  = rtrim( $m[3], '.,;:' );
				$tail = substr( $m[3], strlen( $url ) );

				return '<a href="' . esc_url( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) ) . '"' . $style . '>' . $url . '</a>' . $tail;
			},
			esc_html( $footnote )
		);
	}

	/**
	 * The privacy line, or '' when there is no notice to link to. Pure.
	 */
	public static function privacy_line( string $url ): string {
		if ( '' === trim( $url ) ) {
			return '';
		}

		/* translators: %s: "privacy notice", linked. */
		return sprintf( __( 'How we use your information: %s.', 'dgl-platform' ), self::link( __( 'privacy notice', 'dgl-platform' ), $url ) );
	}

	/**
	 * What every email gets on its way out, whatever wrote it: the privacy
	 * line last in the footer, and the team inbox as the reply address so
	 * "reply to this email" reaches the review team even if the From address
	 * changes. An email that names its own reply address (an enquiry
	 * replies to the person enquiring) keeps it.
	 */
	public static function finish( Message $message, string $privacy_url, string $team ): Message {
		$privacy = self::privacy_line( $privacy_url );

		if ( '' !== $privacy && ! in_array( $privacy, $message->footnotes, true ) ) {
			$message = $message->with_footnote( $privacy );
		}

		foreach ( $message->headers as $header ) {
			if ( 0 === stripos( ltrim( (string) $header ), 'reply-to:' ) ) {
				return $message;
			}
		}

		return '' === trim( $team ) ? $message : $message->with_headers( [ 'Reply-To: ' . trim( $team ) ] );
	}
}

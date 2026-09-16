<?php
/**
 * Turning a message into something an email client will render.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

use DGL\Brand;

defined( 'ABSPATH' ) || exit;

/**
 * The HTML shell.
 *
 * Email is not the web. Outlook renders through Word, Gmail strips `<style>`
 * blocks on forwarded mail, and every client disagrees about margins. So this
 * is tables and inline styles on purpose, not from habit: it is the only
 * approach that renders the same in all of them.
 *
 * The rules being followed, so nobody "tidies" them away later:
 *
 * - Tables for layout. Divs and flexbox collapse in Outlook.
 * - Every style inline. Gmail drops `<style>` when a message is forwarded.
 * - 600px maximum, which is the width every client agrees on.
 * - No web fonts. Montserrat is named first and falls back to the system
 *   stack, because most clients will not load the font and a missing font
 *   should degrade to Arial rather than to Times.
 * - No background images, no SVG, no external CSS.
 * - The button is a padded table cell with a link in it, not a styled anchor,
 *   because Outlook ignores padding on inline elements.
 *
 * Colours come from {@see Brand} rather than being typed in here, so the email
 * and the dashboard cannot drift apart. The hexes are used directly rather than
 * the CSS custom properties the dashboard uses: email has no theme context and
 * `var()` is not supported.
 */
final class Template {

	/** The width every email client agrees on. */
	private const WIDTH = 600;

	/**
	 * Named colours, resolved once from the brand palette.
	 *
	 * @return array<string, string>
	 */
	private static function colours(): array {
		$palette = Brand::palette();
		$roles   = Brand::roles();

		return [
			'primary' => $palette[ $roles['primary'] ],
			'link'    => $palette[ $roles['link'] ],
			'ink'     => $palette[ $roles['ink'] ],
			'page'    => $palette[ $roles['page'] ],
			'surface' => $palette[ $roles['surface'] ],
			'rule'    => $palette[ $roles['decorative'] ],
			'muted'   => '#5a6a6a',
			'white'   => '#ffffff',
		];
	}

	private const FONT = "Montserrat, 'Helvetica Neue', Helvetica, Arial, sans-serif";

	/**
	 * Render a message as a full HTML document.
	 *
	 * @param string|null $logo_url Absolute URL of a raster logo, or null. Never
	 *                              an SVG: {@see \DGL\Logo::email_url()} enforces
	 *                              that, because Gmail drops SVG and the header
	 *                              would render empty.
	 */
	public static function render( Message $message, ?string $logo_url = null ): string {
		$c = self::colours();

		$out  = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n";
		$out .= '<html xmlns="http://www.w3.org/1999/xhtml" lang="en-GB"><head>';
		$out .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />';
		$out .= '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		$out .= '<meta name="color-scheme" content="light" />';
		$out .= '<meta name="supported-color-schemes" content="light" />';
		$out .= '<title>' . esc_html( $message->subject ) . '</title>';
		$out .= '</head>';
		$out .= '<body style="margin:0;padding:0;background-color:' . $c['page'] . ';">';

		$out .= self::preheader( $message->preheader );

		$out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' . $c['page'] . ';">';
		$out .= '<tr><td align="center" style="padding:24px 12px;">';
		$out .= '<table role="presentation" width="' . self::WIDTH . '" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:' . self::WIDTH . 'px;background-color:' . $c['white'] . ';border-radius:12px;overflow:hidden;">';

		$out .= self::header( $logo_url, $c );
		$out .= self::body( $message, $c );
		$out .= self::footer( $message, $c );

		$out .= '</table>';
		$out .= '</td></tr></table>';
		$out .= '</body></html>';

		return $out;
	}

	/**
	 * The grey line inboxes show after the subject.
	 *
	 * Without one, the client picks the first text it finds, which is whatever
	 * the header happens to contain. The run of non-breaking spaces afterwards
	 * stops it pulling the opening sentence in as well.
	 */
	private static function preheader( string $text ): string {
		if ( '' === trim( $text ) ) {
			return '';
		}

		return '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">'
			. esc_html( $text )
			. str_repeat( '&#847;&zwnj;&nbsp;', 30 )
			. '</div>';
	}

	/**
	 * @param array<string, string> $c
	 */
	private static function header( ?string $logo_url, array $c ): string {
		$out = '<tr><td style="background-color:' . $c['primary'] . ';padding:24px 32px;">';

		if ( is_string( $logo_url ) && '' !== $logo_url ) {
			/*
			 * Height is set in the style as well as the attribute because
			 * Outlook reads the attribute and everything else reads the style.
			 * Width is left to the image so a landscape lockup is not squashed.
			 */
			$out .= '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '"'
				. ' height="40" style="height:40px;width:auto;display:block;border:0;outline:none;text-decoration:none;" />';
		} else {
			$out .= '<span style="font-family:' . self::FONT . ';font-size:20px;font-weight:700;color:' . $c['white'] . ';">'
				. esc_html( get_bloginfo( 'name' ) ) . '</span>';
		}

		return $out . '</td></tr>';
	}

	/**
	 * @param array<string, string> $c
	 */
	private static function body( Message $message, array $c ): string {
		$out = '<tr><td style="padding:32px;font-family:' . self::FONT . ';font-size:16px;line-height:1.6;color:' . $c['ink'] . ';">';

		if ( '' !== $message->heading ) {
			$out .= '<h1 style="margin:0 0 16px;font-family:' . self::FONT . ';font-size:24px;line-height:1.3;font-weight:700;color:' . $c['primary'] . ';">'
				. esc_html( $message->heading ) . '</h1>';
		}

		foreach ( $message->paragraphs as $paragraph ) {
			$out .= '<p style="margin:0 0 16px;">' . esc_html( $paragraph ) . '</p>';
		}

		$out .= self::items( $message, $c );
		$out .= self::facts( $message, $c );
		$out .= self::note( $message, $c );
		$out .= self::button( $message, $c );

		return $out . '</td></tr>';
	}

	/**
	 * The list in a digest.
	 *
	 * A table of rows rather than a <ul>, because Outlook renders through Word
	 * and Word's list handling is its own field of study. Each row is a linked
	 * title, a line of context and a summary; the whole row is not a link,
	 * because a link that wraps a paragraph reads as a wall of underline in
	 * clients that do not honour the styling.
	 *
	 * @param array<string, string> $c
	 */
	private static function items( Message $message, array $c ): string {
		if ( [] === $message->items ) {
			return '';
		}

		$out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">';

		foreach ( $message->items as $index => $item ) {
			$title   = (string) ( $item['title'] ?? '' );
			$meta    = (string) ( $item['meta'] ?? '' );
			$url     = (string) ( $item['url'] ?? '' );
			$summary = (string) ( $item['summary'] ?? '' );

			if ( '' === $title ) {
				continue;
			}

			// A rule between rows, never above the first one.
			$border = $index > 0 ? 'border-top:1px solid ' . $c['rule'] . ';' : '';

			$out .= '<tr><td style="padding:16px 0;' . $border . '">';

			$heading = '<span style="font-family:' . self::FONT . ';font-size:17px;font-weight:700;line-height:1.35;color:' . $c['primary'] . ';">'
				. esc_html( $title ) . '</span>';

			$out .= '' !== $url
				? '<a href="' . esc_url( $url ) . '" style="text-decoration:none;color:' . $c['primary'] . ';">' . $heading . '</a>'
				: $heading;

			if ( '' !== $meta ) {
				$out .= '<div style="margin:4px 0 0;font-family:' . self::FONT . ';font-size:13px;color:' . $c['muted'] . ';">'
					. esc_html( $meta ) . '</div>';
			}

			if ( '' !== $summary ) {
				$out .= '<div style="margin:8px 0 0;font-family:' . self::FONT . ';font-size:15px;line-height:1.55;color:' . $c['ink'] . ';">'
					. esc_html( $summary ) . '</div>';
			}

			$out .= '</td></tr>';
		}

		return $out . '</table>';
	}

	/**
	 * @param array<string, string> $c
	 */
	private static function facts( Message $message, array $c ): string {
		if ( [] === $message->facts ) {
			return '';
		}

		$out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="margin:0 0 24px;background-color:' . $c['surface'] . ';border-radius:8px;">';

		foreach ( $message->facts as $label => $value ) {
			$out .= '<tr>';
			$out .= '<td style="padding:10px 16px;font-family:' . self::FONT . ';font-size:14px;color:' . $c['muted'] . ';white-space:nowrap;vertical-align:top;">'
				. esc_html( (string) $label ) . '</td>';
			$out .= '<td style="padding:10px 16px;font-family:' . self::FONT . ';font-size:14px;font-weight:600;color:' . $c['ink'] . ';vertical-align:top;">'
				. esc_html( $value ) . '</td>';
			$out .= '</tr>';
		}

		return $out . '</table>';
	}

	/**
	 * A moderator's message to the member.
	 *
	 * Quoted in a tinted block with a rule down the left so it reads as
	 * somebody's words rather than more system text. It is the part of the
	 * email that actually matters to the person reading it.
	 *
	 * @param array<string, string> $c
	 */
	private static function note( Message $message, array $c ): string {
		if ( ! $message->has_note() ) {
			return '';
		}

		$out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">';
		$out .= '<tr><td style="padding:16px 20px;background-color:' . $c['surface'] . ';border-left:4px solid ' . $c['rule'] . ';border-radius:0 8px 8px 0;">';

		if ( '' !== $message->note_label ) {
			$out .= '<p style="margin:0 0 8px;font-family:' . self::FONT . ';font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:' . $c['muted'] . ';">'
				. esc_html( $message->note_label ) . '</p>';
		}

		/*
		 * The note is member-facing free text written by a moderator. It is
		 * escaped and its line breaks turned into `<br />` afterwards, so a
		 * paragraph break survives but nothing in the text can become markup.
		 */
		$note = nl2br( esc_html( trim( $message->note ) ), false );

		$out .= '<p style="margin:0;font-family:' . self::FONT . ';font-size:16px;line-height:1.6;color:' . $c['ink'] . ';">' . $note . '</p>';

		return $out . '</td></tr></table>';
	}

	/**
	 * @param array<string, string> $c
	 */
	private static function button( Message $message, array $c ): string {
		if ( ! $message->has_cta() ) {
			return '';
		}

		$url = esc_url( $message->cta_url );

		$out = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;">';
		$out .= '<tr><td align="center" bgcolor="' . $c['primary'] . '" style="background-color:' . $c['primary'] . ';border-radius:8px;">';
		$out .= '<a href="' . $url . '" style="display:inline-block;padding:14px 28px;font-family:' . self::FONT . ';font-size:15px;font-weight:500;color:' . $c['white'] . ';text-decoration:none;border-radius:8px;">'
			. esc_html( $message->cta_label ) . '</a>';
		$out .= '</td></tr></table>';

		/*
		 * The same link in plain text underneath. Some corporate clients strip
		 * the button entirely, and a member who cannot see it still needs a way
		 * through.
		 */
		$out .= '<p style="margin:0;font-family:' . self::FONT . ';font-size:13px;line-height:1.5;color:' . $c['muted'] . ';word-break:break-all;">'
			. esc_html__( 'If the button does not work, copy this into your browser:', 'dgl-platform' ) . '<br />'
			. '<a href="' . $url . '" style="color:' . $c['link'] . ';">' . esc_html( $message->cta_url ) . '</a></p>';

		return $out;
	}

	/**
	 * @param array<string, string> $c
	 */
	private static function footer( Message $message, array $c ): string {
		if ( [] === $message->footnotes ) {
			return '';
		}

		$out = '<tr><td style="padding:24px 32px;background-color:' . $c['surface'] . ';">';

		foreach ( $message->footnotes as $footnote ) {
			$out .= '<p style="margin:0 0 8px;font-family:' . self::FONT . ';font-size:13px;line-height:1.5;color:' . $c['muted'] . ';">'
				. self::linkify( $footnote, $c ) . '</p>';
		}

		return $out . '</td></tr>';
	}

	/**
	 * Make bare URLs in the small print clickable.
	 *
	 * The footer is where the unsubscribe link lives, and it was going out as
	 * escaped text. Some clients auto-link a bare URL and some do not, so for
	 * some readers the only way to stop the emails was to select the address,
	 * copy it and paste it into a browser. Almost nobody does that. They press
	 * the spam button instead, and a spam complaint costs the sending domain
	 * far more than an unsubscribe does.
	 *
	 * Escaping happens first, on the whole string, and the pattern then matches
	 * only what is left. Nothing user-supplied can reach the href unescaped.
	 *
	 * @param array<string, string> $c
	 */
	private static function linkify( string $text, array $c ): string {
		$escaped = esc_html( $text );

		return (string) preg_replace_callback(
			'#https?://[^\s<>"\']+#i',
			static function ( array $m ) use ( $c ): string {
				// A trailing full stop belongs to the sentence, not the URL.
				$url  = rtrim( $m[0], '.,;:' );
				$tail = substr( $m[0], strlen( $url ) );

				return '<a href="' . esc_url( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) ) . '"'
					. ' style="color:' . $c['primary'] . ';text-decoration:underline;">' . $url . '</a>' . $tail;
			},
			$escaped
		);
	}
}

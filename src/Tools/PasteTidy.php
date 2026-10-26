<?php
/**
 * Take the styling a paste from Word, Google Docs or the old theme left in
 * a story's words, so the site's own styles show.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Pure, no WordPress calls. A paste brings its own fonts, sizes, colours and
 * spacing with it, as inline styles and wrapper spans, and they override the
 * site's. This keeps the meaning (bold, italic, alignment, links, lists,
 * headings) and drops the rest:
 *
 * - spans and font tags are unwrapped; a span that made its words bold or
 *   italic becomes strong or em;
 * - b and i become strong and em;
 * - on every other tag, style keeps only text-align, and Word's classes,
 *   data- attributes, aria-level and lang go;
 * - Word's o:p tags and empty spacer paragraphs (only &nbsp;) go.
 *
 * Comments (block markup) are never touched, and nor are tags that carry
 * media or embeds (img, iframe, video, audio, source, svg).
 */
final class PasteTidy {

	/** Class names a paste leaves behind. */
	private const PASTE_CLASS = '~^(?:Mso\w*|TextRun|NormalTextRun|EOP|SCXW\d+|BCX\d+|OutlineElement|Paragraph|ParagraphLanguage\w*|ListContainerWrapper|ListMarkerWrappingSpan|ContextualSpellingAndGrammarError\w*|SpellingError\w*|Ltr|Rtl|Apple-\w+|s\d+|p\d+|li\d+|ql-\w+)$~';

	/** Tags whose attributes are left exactly as they are. */
	private const KEEP_AS_IS = [ 'img', 'iframe', 'video', 'audio', 'source', 'svg', 'path', 'picture', 'embed', 'object', 'script', 'style', 'input', 'select', 'textarea', 'form', 'button' ];

	/** Tags text-align is kept on. */
	private const ALIGNABLE = [ 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th', 'li', 'div', 'blockquote' ];

	/**
	 * @return array{html: string, changes: array<string, int>}
	 */
	public static function tidy( string $html ): array {
		$changes = [ 'spans' => 0, 'styles' => 0, 'attributes' => 0, 'bold_italic' => 0, 'spacers' => 0 ];
		$parts   = preg_split( '~(<!--.*?-->|<[^>]+>)~s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( false === $parts ) {
			return [ 'html' => $html, 'changes' => $changes ];
		}

		$out   = '';
		$stack = [];

		foreach ( $parts as $i => $part ) {
			if ( 0 === $i % 2 || str_starts_with( $part, '<!--' ) ) {
				$out .= $part;
				continue;
			}

			if ( ! preg_match( '~^<(/?)([a-z][a-z0-9:]*)\b(.*?)(/?)>$~is', $part, $t ) ) {
				$out .= $part;
				continue;
			}

			$closing = '/' === $t[1];
			$name    = strtolower( $t[2] );
			$attrs   = $t[3];

			if ( 'o:p' === $name ) {
				++$changes['spans'];
				continue;
			}

			if ( in_array( $name, [ 'span', 'font', 'b', 'i' ], true ) ) {
				if ( $closing ) {
					// Close what the opening became; a stray closer is dropped.
					$out .= null === ( $top = array_pop( $stack ) ) ? '' : $top;
					continue;
				}

				[ $open, $close ] = self::inline( $name, $attrs, $changes );
				$out             .= $open;
				$stack[]          = $close;
				continue;
			}

			if ( $closing || in_array( $name, self::KEEP_AS_IS, true ) ) {
				$out .= $part;
				continue;
			}

			$out .= '<' . $t[2] . self::attributes( $name, $attrs, $changes ) . ( '' !== $t[4] ? ' /' : '' ) . '>';
		}

		$tidied = (string) preg_replace_callback(
			'~<p\b[^>]*>(?:\s|&nbsp;|&#160;|\x{00A0})*</p>~iu',
			static function () use ( &$changes ): string {
				++$changes['spacers'];
				return '';
			},
			$out
		);

		// A line of nothing but a non-breaking space between paragraphs of classic content.
		$tidied = (string) preg_replace_callback(
			'~(^|\n)[ \t]*(?:&nbsp;|&#160;|\x{00A0})[ \t]*(?=\n|$)~u',
			static function ( array $m ) use ( &$changes ): string {
				++$changes['spacers'];
				return $m[1];
			},
			$tidied
		);

		$tidied = rtrim( $tidied );

		return [ 'html' => $tidied, 'changes' => $changes ];
	}

	/**
	 * What an inline wrapper becomes: an opening and its closing. b and i
	 * become strong and em; a span or font keeps only the bold or italic its
	 * style gave, as strong or em, and is otherwise unwrapped. A span that
	 * still carries a class of the site's own (not a paste's) stays.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function inline( string $name, string $attrs, array &$changes ): array {
		$keep = self::site_classes( self::attr( $attrs, 'class' ) );
		$id   = self::attr( $attrs, 'id' );

		if ( 'b' === $name || 'i' === $name ) {
			// An icon (<i class="fa ...">) is not italic text: left as it is.
			if ( '' !== $keep ) {
				return [ '<' . $name . $attrs . '>', '</' . $name . '>' ];
			}

			++$changes['bold_italic'];
			return 'b' === $name ? [ '<strong>', '</strong>' ] : [ '<em>', '</em>' ];
		}

		$style  = self::attr( $attrs, 'style' );
		$decls  = self::declarations( $style );
		$weight = strtolower( (string) ( $decls['font-weight'] ?? '' ) );
		$bold   = in_array( $weight, [ 'bold', 'bolder', '600', '700', '800', '900' ], true );
		$italic = 'italic' === strtolower( (string) ( $decls['font-style'] ?? '' ) );

		$open  = '';
		$close = '';

		// A span of the site's own class, or one a link jumps to, stays.
		if ( '' !== $keep || '' !== $id ) {
			$open  = '<span' . ( '' !== $id ? ' id="' . htmlspecialchars( $id, ENT_QUOTES, 'UTF-8' ) . '"' : '' ) . ( '' !== $keep ? ' class="' . htmlspecialchars( $keep, ENT_QUOTES, 'UTF-8' ) . '"' : '' ) . '>';
			$close = '</span>';

			if ( '' !== $style || $keep !== trim( self::attr( $attrs, 'class' ) ) ) {
				++$changes['styles'];
			}
		} else {
			++$changes['spans'];
		}

		if ( $bold ) {
			$open  .= '<strong>';
			$close  = '</strong>' . $close;
			++$changes['bold_italic'];
		}

		if ( $italic ) {
			$open  .= '<em>';
			$close  = '</em>' . $close;
			++$changes['bold_italic'];
		}

		return [ $open, $close ];
	}

	/** The attributes a block-level or other tag keeps. */
	private static function attributes( string $name, string $attrs, array &$changes ): string {
		if ( ! preg_match_all( '~([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?~i', $attrs, $m, PREG_SET_ORDER ) ) {
			// Only whitespace: none. Kept, it would grow by a space each pass.
			return '' === trim( $attrs ) ? '' : $attrs;
		}

		$kept = '';

		foreach ( $m as $a ) {
			$key   = strtolower( $a[1] );
			$value = $a[2] ?? '';
			$raw   = '' === $value ? '' : trim( $value, '"\'' );

			if ( str_starts_with( $key, 'data-' ) || in_array( $key, [ 'aria-level', 'lang', 'xml:lang', 'paraid', 'paraeid' ], true ) ) {
				++$changes['attributes'];
				continue;
			}

			if ( 'style' === $key ) {
				$decls = self::declarations( $raw );
				$align = strtolower( (string) ( $decls['text-align'] ?? '' ) );

				++$changes['styles'];

				if ( in_array( $name, self::ALIGNABLE, true ) && in_array( $align, [ 'center', 'right', 'justify' ], true ) ) {
					$kept .= ' style="text-align: ' . $align . ';"';
				}
				continue;
			}

			if ( 'class' === $key ) {
				$classes = self::site_classes( $raw );

				if ( $classes !== trim( (string) preg_replace( '~\s+~', ' ', $raw ) ) ) {
					++$changes['attributes'];
				}

				if ( '' !== $classes ) {
					$kept .= ' class="' . $classes . '"';
				}
				continue;
			}

			$kept .= ' ' . $a[0];
		}

		return $kept;
	}

	/** A class list without the classes a paste leaves. */
	private static function site_classes( string $classes ): string {
		$keep = array_filter(
			preg_split( '~\s+~', trim( $classes ) ) ?: [],
			static fn( string $c ): bool => '' !== $c && 1 !== preg_match( self::PASTE_CLASS, $c )
		);

		return implode( ' ', $keep );
	}

	/** An attribute's value, decoded, or ''. */
	private static function attr( string $attrs, string $name ): string {
		if ( ! preg_match( '~\b' . preg_quote( $name, '~' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\')~i', $attrs, $m ) ) {
			return '';
		}

		return html_entity_decode( '' !== ( $m[2] ?? '' ) ? $m[2] : ( $m[3] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * CSS declarations as property => value.
	 *
	 * @return array<string, string>
	 */
	private static function declarations( string $style ): array {
		$out = [];

		foreach ( explode( ';', $style ) as $decl ) {
			$bits = explode( ':', $decl, 2 );

			if ( 2 === count( $bits ) && '' !== trim( $bits[0] ) ) {
				$out[ strtolower( trim( $bits[0] ) ) ] = trim( str_ireplace( '!important', '', $bits[1] ) );
			}
		}

		return $out;
	}

	/** Whether content looks like a paste needing a tidy. */
	public static function needs_tidy( string $html ): bool {
		return 1 === preg_match( '~<(?:span|font|b|i|o:p)\b|\sstyle\s*=|\sdata-(?:contrast|ccp)|class="[^"]*(?:Mso|TextRun|SCXW)|<p\b[^>]*>(?:\s|&nbsp;)*</p>|(?:^|\n)[ \t]*&nbsp;[ \t]*(?:\n|$)~i', $html );
	}
}

<?php
/**
 * What a member's rich text may contain.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

defined( 'ABSPATH' ) || exit;

/**
 * The allowlist for the body of a listing.
 *
 * The editor offers bold, italic, two kinds of list and a link, and nothing
 * else. Storage used to accept `wp_kses_post`, which is what a WordPress
 * author may write: headings, tables, images, iframes, and a `style` attribute
 * on almost anything. A body pasted out of Word through the code view arrived
 * with all of it, and the public page rendered somebody's document styling
 * inside the site's.
 *
 * So storage accepts what the toolbar can produce and no more. The rule is the
 * same whichever way the markup arrives: the visual editor, a pasted
 * document, a hand-written POST.
 */
final class Content {

	/**
	 * Elements and attributes a body may keep.
	 *
	 * No `class`, `id`, `style` or `data-*` on anything: those are how pasted
	 * documents carry their formatting in, and none of them is something the
	 * toolbar can add.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		return [
			'p'      => [],
			'br'     => [],
			'strong' => [],
			'b'      => [],
			'em'     => [],
			'i'      => [],
			'ul'     => [],
			'ol'     => [],
			'li'     => [],
			'a'      => [
				'href'   => true,
				'title'  => true,
				'rel'    => true,
				'target' => true,
			],
		];
	}

	/**
	 * The same list in TinyMCE's `valid_elements` grammar, so the editor
	 * refuses in the browser what storage would refuse on the server.
	 */
	public static function editor_valid_elements(): string {
		$out = [];

		foreach ( self::allowed_html() as $tag => $attrs ) {
			$out[] = [] === $attrs ? $tag : $tag . '[' . implode( '|', array_keys( $attrs ) ) . ']';
		}

		return implode( ',', $out );
	}

	/**
	 * Reduce markup to the allowlist.
	 *
	 * `<script>` and `<style>` go with their contents. Stripping only the tags
	 * would leave a page of `mso-` rules as visible text, which is what a Word
	 * paste is mostly made of.
	 */
	public static function clean( string $html ): string {
		$html = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $html );

		$html = wp_kses( $html, self::allowed_html(), [ 'http', 'https', 'mailto' ] );

		// A pasted document leaves paragraphs of nothing but a non-breaking space.
		$html = (string) preg_replace( '@<p>(\s|&nbsp;|\x{00a0})*</p>@u', '', $html );

		return trim( $html );
	}
}

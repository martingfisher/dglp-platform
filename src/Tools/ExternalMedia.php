<?php
/**
 * Find the files a piece of content takes from other sites, and point them
 * at copies here.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Pure, no WordPress calls. Content here came from older sites, and its
 * pictures and documents still load from them. Those sites are going away,
 * so every such file has to live in this site's own media library.
 *
 * A reference is "embed" when the page shows the file (an img src or srcset,
 * a background, a block's picture setting) and "link" when it only points
 * at it (an href to a PDF, say). Block settings write addresses with escaped
 * slashes (https:\/\/host\/...), so both forms are found and rewritten.
 */
final class ExternalMedia {

	/** File types worth keeping a copy of. */
	public const EXTENSIONS = 'jpe?g|png|gif|webp|avif|svg|bmp|tiff?|ico|pdf|docx?|xlsx?|pptx?|odt|ods|odp|csv|rtf|zip|mp3|m4a|wav|mp4|mov|webm|m4v';

	/** Picture types: the ones that can be a news item's picture. */
	public const PICTURES = 'jpe?g|png|gif|webp|avif';

	/** Attributes and block settings that point at a file rather than show it. */
	private const LINK_KEYS = [ 'href', 'link', 'linkurl', 'url_link', 'download' ];

	/**
	 * Every file address in the content that is not on one of our own hosts.
	 * One row per address, in the order first seen; an address used both ways
	 * counts as an embed.
	 *
	 * @param string[] $own_hosts Lower-case hosts that are this site.
	 * @return array<int, array{url: string, host: string, kind: string}>
	 */
	public static function find( string $html, array $own_hosts ): array {
		$plain = str_replace( '\\/', '/', $html );

		if ( ! preg_match_all( '~https?://([a-z0-9.-]+)(/[^\s"\'<>()\\\\,|]*)~i', $plain, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return [];
		}

		$own  = array_map( 'strtolower', $own_hosts );
		$rows = [];

		foreach ( $m as $hit ) {
			$url  = rtrim( $hit[0][0], '.;:!?' );
			$host = strtolower( $hit[1][0] );

			if ( in_array( $host, $own, true ) || ! self::is_file( $url ) ) {
				continue;
			}

			$kind = self::kind( substr( $plain, max( 0, $hit[0][1] - 300 ), min( 300, $hit[0][1] ) ) );

			if ( isset( $rows[ $url ] ) ) {
				if ( 'embed' === $kind ) {
					$rows[ $url ]['kind'] = 'embed';
				}
				continue;
			}

			$rows[ $url ] = [ 'url' => $url, 'host' => $host, 'kind' => $kind ];
		}

		return array_values( $rows );
	}

	/** An address's path, entities decoded, or ''. parse_url() so it runs without WordPress. */
	private static function path( string $url ): string {
		$parts = parse_url( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure code, tested without WordPress.

		return is_array( $parts ) ? (string) ( $parts['path'] ?? '' ) : '';
	}

	/** Whether an address is a file: an uploads path, or a known file type. */
	public static function is_file( string $url ): bool {
		$path = (string) ( self::path( $url ) );

		return str_contains( $path, '/wp-content/uploads/' ) || 1 === preg_match( '~\.(?:' . self::EXTENSIONS . ')$~i', $path );
	}

	/** Whether an address is a picture a news item could use. */
	public static function is_picture( string $url ): bool {
		$path = (string) ( self::path( $url ) );

		return 1 === preg_match( '~\.(?:' . self::PICTURES . ')$~i', $path );
	}

	/**
	 * How the text just before an address uses it: the nearest attribute or
	 * block setting decides. href and link settings point; the rest show.
	 */
	public static function kind( string $before ): string {
		$key = '';

		if ( preg_match( '~([a-z_:-]+)\s*=\s*["\']?[^"\'<>=]*$~i', $before, $a ) ) {
			$key = $a[1];
		}

		if ( preg_match( '~"([a-z_]+)"\s*:\s*"[^"]*$~i', $before, $j, PREG_OFFSET_CAPTURE ) ) {
			// The block setting is nearer than any attribute.
			$attr_at = '' === $key ? -1 : (int) strrpos( $before, $key );

			if ( $j[0][1] > $attr_at ) {
				$key = $j[1][0];
			}
		}

		return in_array( strtolower( $key ), self::LINK_KEYS, true ) ? 'link' : 'embed';
	}

	/**
	 * The full-size file behind a WordPress size: "photo-300x219.jpg" is a
	 * copy of "photo.jpg". Other addresses come back as they are.
	 */
	public static function full_size( string $url ): string {
		return (string) preg_replace( '~-\d{2,5}x\d{2,5}(\.[a-z0-9]{2,5})((?:\?[^#]*)?)$~i', '$1$2', $url );
	}

	/**
	 * Swap each address for its copy here, in both the plain and the
	 * escaped-slash form. Longer addresses go first, so one address that
	 * starts another is never half replaced.
	 *
	 * @param array<string, string> $map Old address to new address.
	 */
	public static function rewrite( string $html, array $map ): string {
		$pairs = [];

		foreach ( $map as $from => $to ) {
			if ( '' === $from || '' === $to || $from === $to ) {
				continue;
			}

			$pairs[ $from ]                           = $to;
			$pairs[ str_replace( '/', '\\/', $from ) ] = str_replace( '/', '\\/', $to );
		}

		return [] === $pairs ? $html : strtr( $html, $pairs );
	}

	/**
	 * Every img in the content: its address, its alt text (null when the tag
	 * has none at all) and the attachment id its wp-image class names (on the
	 * site the tag was written on), 0 if none.
	 *
	 * @return array<int, array{src: string, alt: ?string, id: int}>
	 */
	public static function images( string $html ): array {
		if ( ! preg_match_all( '~<img\b[^>]*>~i', $html, $m ) ) {
			return [];
		}

		$out = [];

		foreach ( $m[0] as $tag ) {
			if ( ! preg_match( '~\bsrc\s*=\s*(["\'])(.*?)\1~i', $tag, $s ) ) {
				continue;
			}

			$alt = null;

			if ( preg_match( '~\balt\s*=\s*(["\'])(.*?)\1~is', $tag, $a ) ) {
				$alt = trim( html_entity_decode( $a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}

			$id = preg_match( '~\bwp-image-(\d+)\b~', $tag, $i ) ? (int) $i[1] : 0;

			$out[] = [ 'src' => $s[2], 'alt' => $alt, 'id' => $id ];
		}

		return $out;
	}

	/**
	 * After a rewrite: each img that now shows a copy here is tagged with its
	 * attachment (wp-image-<id>), and loses its srcset and sizes, which listed
	 * the old site's sizes. WordPress writes them again from the library.
	 * Where the tag's alt text is empty or missing and the library has one,
	 * the tag takes it: WordPress does not fill alt text in at display.
	 *
	 * @param array<string, int>    $ids  New address to attachment id.
	 * @param array<string, string> $alts New address to the attachment's alt text.
	 */
	public static function retag( string $html, array $ids, array $alts = [] ): string {
		if ( [] === $ids && [] === $alts ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'~<img\b[^>]*>~i',
			static function ( array $m ) use ( $ids, $alts ): string {
				$tag = $m[0];

				if ( ! preg_match( '~\bsrc\s*=\s*(["\'])(.*?)\1~i', $tag, $s ) ) {
					return $tag;
				}

				$src = html_entity_decode( $s[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$id  = $ids[ $s[2] ] ?? $ids[ $src ] ?? 0;
				$alt = trim( (string) ( $alts[ $s[2] ] ?? $alts[ $src ] ?? '' ) );

				if ( $id > 0 ) {
					$tag = (string) preg_replace( '~\s(?:srcset|sizes)\s*=\s*(["\']).*?\1~is', '', $tag );

					if ( preg_match( '~\bclass\s*=\s*(["\'])(.*?)\1~i', $tag, $c ) ) {
						$classes = trim( (string) preg_replace( '~\s+~', ' ', (string) preg_replace( '~\bwp-image-\d+\b~', '', $c[2] ) ) );
						$classes = trim( $classes . ' wp-image-' . $id );
						$tag     = str_replace( $c[0], 'class=' . $c[1] . $classes . $c[1], $tag );
					} else {
						$tag = (string) preg_replace( '~^<img\b~i', '<img class="wp-image-' . $id . '"', $tag );
					}
				}

				if ( '' !== $alt ) {
					$escaped = htmlspecialchars( $alt, ENT_QUOTES, 'UTF-8' );

					if ( preg_match( '~\balt\s*=\s*(["\'])(.*?)\1~is', $tag, $a ) ) {
						if ( '' === trim( $a[2] ) ) {
							$tag = str_replace( $a[0], 'alt="' . $escaped . '"', $tag );
						}
					} else {
						$tag = (string) preg_replace( '~^<img\b~i', '<img alt="' . $escaped . '"', $tag );
					}
				}

				return $tag;
			},
			$html
		);
	}

	/**
	 * Take out every img that shows a given attachment: tagged wp-image-<id>,
	 * or with an address whose path (any WordPress size of it) is one of the
	 * attachment's own. Whatever only held it goes too: a link round it, a
	 * paragraph left with nothing else, its figure (caption and all), its
	 * [caption] shortcode and an image block left empty. For a story whose
	 * main picture also sits in its words, so it showed twice.
	 *
	 * @param string[] $paths The attachment's file paths, e.g. /wp-content/uploads/2026/10/photo.jpg.
	 * @return array{html: string, removed: int}
	 */
	public static function drop_image( string $html, int $id, array $paths ): array {
		$mark    = "\x00dgl-dropped\x00";
		$removed = 0;
		$paths   = array_map( 'strtolower', $paths );

		$out = (string) preg_replace_callback(
			'~(<a\b[^>]*>\s*)?(<img\b[^>]*>)(\s*</a>)?~i',
			static function ( array $m ) use ( $id, $paths, $mark, &$removed ): string {
				$tag     = $m[2];
				$tagged  = $id > 0 && 1 === preg_match( '~\bwp-image-' . $id . '\b~', $tag );
				$by_path = false;

				if ( preg_match( '~\bsrc\s*=\s*(["\'])(.*?)\1~i', $tag, $s ) ) {
					$path    = strtolower( self::path( self::full_size( $s[2] ) ) );
					$by_path = '' !== $path && in_array( $path, $paths, true );
				}

				if ( ! $tagged && ! $by_path ) {
					return $m[0];
				}

				++$removed;
				$open  = $m[1] ?? '';
				$close = $m[3] ?? '';

				// A link that held only this picture goes with it; one that also
				// held other things keeps its own ends.
				if ( '' !== $open && '' !== $close ) {
					return $mark;
				}

				return $open . $mark . $close;
			},
			$html
		);

		if ( 0 === $removed ) {
			return [ 'html' => $html, 'removed' => 0 ];
		}

		$q = preg_quote( $mark, '~' );

		// The containers that held only the picture.
		$out = (string) preg_replace( '~\[caption\b[^\]]*\][^\[]*?' . $q . '.*?\[/caption\]~is', $mark, $out );
		$out = (string) preg_replace( '~<figure\b[^>]*>(?:(?!</figure>).)*?' . $q . '.*?</figure>~is', $mark, $out );
		$out = (string) preg_replace( '~<p\b[^>]*>(?:\s|&nbsp;|<br\s*/?>)*' . $q . '(?:\s|&nbsp;|<br\s*/?>)*</p>~i', $mark, $out );
		$out = (string) preg_replace( '~<!--\s*wp:image\b[^>]*?-->\s*' . $q . '\s*<!--\s*/wp:image\s*-->~i', '', $out );
		// On its own line it leaves one line ending; mid-line, nothing, since
		// a stray line break in classic content shows as a <br>.
		$out = (string) preg_replace( '~\n' . $q . '\n~', "\n", $out );
		$out = str_replace( $mark, '', $out );

		return [ 'html' => trim( (string) preg_replace( "~\n{3,}~", "\n\n", $out ) ), 'removed' => $removed ];
	}

	/**
	 * The first picture the content shows from another site, or ''. For a
	 * news item that has none of its own.
	 *
	 * @param string[] $own_hosts
	 */
	public static function first_picture( string $html, array $own_hosts ): string {
		foreach ( self::find( $html, $own_hosts ) as $row ) {
			if ( 'embed' === $row['kind'] && self::is_picture( $row['url'] ) ) {
				return $row['url'];
			}
		}

		return '';
	}
}

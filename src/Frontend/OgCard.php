<?php
/**
 * A social sharing image made on the server when nobody uploaded one.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * 1200 by 630, navy, the title in Montserrat, who posted it and the site's
 * name along the bottom. Made once with GD on first request, saved under
 * uploads/dgl-og, and served as a plain file after that. A change to the
 * words changes the file name, so a stale card is never served and the old
 * one is removed.
 *
 * Without GD, or without the fonts, url() returns '' and the caller falls
 * back to whatever else it has. Nothing here is on the page's critical path:
 * a card is only made when a page's head is built.
 */
final class OgCard {

	public const WIDTH  = 1200;
	public const HEIGHT = 630;

	/** Bump when the design changes, so every card is remade. */
	private const VERSION = 1;

	private const NAVY  = [ 0x2d, 0x3b, 0x6b ];
	private const WHITE = [ 0xff, 0xff, 0xff ];
	private const PALE  = [ 0xc9, 0xd1, 0xe8 ];
	private const RULE  = [ 0x4a, 0x5a, 0x8f ];
	private const PAD   = 72;

	/**
	 * The card's URL, making it if need be. '' when it cannot be made.
	 *
	 * @param string $key    A stable name for what the card is for, e.g. "item-123".
	 * @param string $title  The big words.
	 * @param string $line   Who posted it, along the bottom. May be ''.
	 * @param string $kicker What it is, above the title, e.g. "Event". May be ''.
	 */
	public static function url( string $key, string $title, string $line = '', string $kicker = '' ): string {
		if ( ! self::available() ) {
			return '';
		}

		$key   = preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $key ) ) ?: 'card';
		$title = self::plain( $title );
		$line  = self::plain( $line );
		$site  = self::plain( (string) get_bloginfo( 'name' ) );
		$hash  = substr( md5( self::VERSION . '|' . $title . '|' . $line . '|' . $kicker . '|' . $site ), 0, 10 );
		$dir   = wp_upload_dir();

		if ( ! empty( $dir['error'] ) ) {
			return '';
		}

		$folder = rtrim( (string) $dir['basedir'], '/' ) . '/dgl-og';
		$file   = $folder . '/' . $key . '-' . $hash . '.png';
		$url    = rtrim( (string) $dir['baseurl'], '/' ) . '/dgl-og/' . $key . '-' . $hash . '.png';

		if ( is_readable( $file ) ) {
			return $url;
		}

		if ( ! wp_mkdir_p( $folder ) || ! self::render( $file, $title, $line, self::plain( $kicker ), $site ) ) {
			return '';
		}

		// The words changed, so the old card for this key is stale.
		foreach ( glob( $folder . '/' . $key . '-*.png' ) ?: [] as $old ) {
			if ( $old !== $file ) {
				wp_delete_file( $old );
			}
		}

		return $url;
	}

	/**
	 * Remove every card made for a key, when what it was for has gone.
	 */
	public static function forget( string $key ): void {
		$key = preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $key ) ) ?: 'card';
		$dir = wp_upload_dir();

		if ( ! empty( $dir['error'] ) ) {
			return;
		}

		foreach ( glob( rtrim( (string) $dir['basedir'], '/' ) . '/dgl-og/' . $key . '-*.png' ) ?: [] as $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Whether cards can be made here at all.
	 */
	public static function available(): bool {
		return function_exists( 'imagecreatetruecolor' )
			&& function_exists( 'imagettftext' )
			&& is_readable( self::font( 'Bold' ) )
			&& is_readable( self::font( 'SemiBold' ) );
	}

	/**
	 * Break words into lines that fit. Pure: the measurer is passed in, so
	 * the rule is unit tested without a font.
	 *
	 * A word wider than the line goes on a line of its own and is cut with
	 * an ellipsis rather than overflowing. Past the last allowed line the
	 * rest is dropped and the last line ends in an ellipsis.
	 *
	 * @param callable(string): int $width_of Pixel width of a string.
	 * @return string[]
	 */
	public static function wrap( string $text, int $max_width, callable $width_of, int $max_lines ): array {
		$words = preg_split( '/\s+/', trim( $text ) ) ?: [];
		$lines = [];
		$line  = '';

		foreach ( $words as $word ) {
			if ( '' === $word ) {
				continue;
			}

			$try = '' === $line ? $word : $line . ' ' . $word;

			if ( $width_of( $try ) <= $max_width ) {
				$line = $try;
				continue;
			}

			if ( '' !== $line ) {
				$lines[] = $line;
				$line    = '';
			}

			// The word alone is too wide: cut it.
			$line = $width_of( $word ) <= $max_width ? $word : self::cut( $word, $max_width, $width_of );
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		if ( count( $lines ) > $max_lines ) {
			$lines = array_slice( $lines, 0, $max_lines );
			$last  = $lines[ $max_lines - 1 ];
			$lines[ $max_lines - 1 ] = self::cut( $last . ' ' . $words[ count( $words ) - 1 ], $max_width, $width_of );
		}

		return $lines;
	}

	private static function cut( string $text, int $max_width, callable $width_of ): string {
		$text = rtrim( $text );

		while ( '' !== $text && $width_of( $text . '…' ) > $max_width ) {
			$text = rtrim( mb_substr( $text, 0, -1 ) );
		}

		return $text . '…';
	}

	private static function plain( string $text ): string {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	private static function font( string $weight ): string {
		return plugin_dir_path( \DGL\PLUGIN_FILE ) . 'assets/fonts/Montserrat-' . $weight . '.ttf';
	}

	private static function render( string $file, string $title, string $line, string $kicker, string $site ): bool {
		$img = imagecreatetruecolor( self::WIDTH, self::HEIGHT );

		if ( false === $img ) {
			return false;
		}

		$navy  = imagecolorallocate( $img, ...self::NAVY );
		$white = imagecolorallocate( $img, ...self::WHITE );
		$pale  = imagecolorallocate( $img, ...self::PALE );
		$rule  = imagecolorallocate( $img, ...self::RULE );
		$bold  = self::font( 'Bold' );
		$semi  = self::font( 'SemiBold' );
		$inner = self::WIDTH - 2 * self::PAD;

		imagefilledrectangle( $img, 0, 0, self::WIDTH, self::HEIGHT, $navy );

		$width_at = static function ( float $size, string $font ) use ( $inner ): callable {
			return static function ( string $s ) use ( $size, $font ): int {
				$box = imagettfbbox( $size, 0, $font, $s );

				return false === $box ? PHP_INT_MAX : (int) ( $box[2] - $box[0] );
			};
		};

		$y = 108;

		if ( '' !== $kicker ) {
			imagettftext( $img, 24, 0, self::PAD, $y, $pale, $semi, mb_strtoupper( $kicker ) );
			$y += 60;
		}

		// The biggest size that fits in three lines, else four smaller ones.
		$lines = [];
		$size  = 64;

		foreach ( [ [ 64, 3 ], [ 54, 4 ], [ 44, 4 ] ] as [ $try, $max ] ) {
			$size  = $try;
			$lines = self::wrap( $title, $inner, $width_at( $size, $bold ), $max );

			if ( count( $lines ) <= $max && ! str_ends_with( end( $lines ) ?: '', '…' ) ) {
				break;
			}
		}

		$y += (int) ( $size * 0.9 );

		foreach ( $lines as $text ) {
			imagettftext( $img, $size, 0, self::PAD, $y, $white, $bold, $text );
			$y += (int) ( $size * 1.22 );
		}

		// The foot: a rule, who posted it, the site's name.
		$foot = self::HEIGHT - 70;
		imagefilledrectangle( $img, self::PAD, $foot - 46, self::WIDTH - self::PAD, $foot - 43, $rule );

		$site_box   = imagettfbbox( 22, 0, $semi, $site );
		$site_width = false === $site_box ? 0 : (int) ( $site_box[2] - $site_box[0] );

		if ( '' !== $line ) {
			$room = $inner - $site_width - 40;
			$who  = self::wrap( $line, $room, $width_at( 28, $semi ), 1 )[0] ?? '';
			imagettftext( $img, 28, 0, self::PAD, $foot, $white, $semi, $who );
			imagettftext( $img, 22, 0, self::WIDTH - self::PAD - $site_width, $foot, $pale, $semi, $site );
		} else {
			imagettftext( $img, 28, 0, self::PAD, $foot, $white, $semi, $site );
		}

		$ok = imagepng( $img, $file, 6 );
		imagedestroy( $img );

		return $ok && is_readable( $file );
	}
}

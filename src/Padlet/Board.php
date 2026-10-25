<?php
/**
 * The health and wellbeing board from Padlet, fetched overnight.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Padlet;

use DOMDocument;
use DOMElement;

defined( 'ABSPATH' ) || exit;

/**
 * Leeds Health and Care Academy keeps a Padlet board of health and
 * wellbeing support for people working in health and care in Leeds. The
 * board's own embed is an iframe that works badly on this site, so the
 * board is read from the two RSS feeds Padlet publishes instead and shown
 * as this site's own cards.
 *
 * Two feeds, because neither is whole. The export feed gives each post's
 * outbound link (as its enclosure) and a stable numeric id; the full feed
 * gives the whole description and the attached picture or video. They are
 * joined by title and time posted, which matched every titled post on 25
 * October 2026. Neither feed says which column of the board a post is in,
 * so the grouping is this site's: seeded from the board's columns as they
 * were on 25 October and changed under Settings, Wellbeing board. A post
 * that arrives later lands in "More support" until it is given a group.
 *
 * Left out, and listed on the settings screen so nobody wonders where they
 * went:
 * - posts that link to Amazon (book recommendations with shopping tracking
 *   on the link: on this site they read as adverts);
 * - posts with no title (the board's column introductions, and two links
 *   posted without a title).
 *
 * A post's picture is copied to this site's uploads, as the jobs feed does
 * with logos: Padlet signs some upload links with a token that expires, and
 * a visitor's browser should not be calling Padlet to see our page. Most
 * posts have no picture, and the cards are designed for that.
 *
 * Once a night, never on a page render, and the last good board is kept if
 * Padlet is down. Everything that comes back is untrusted: text is stripped
 * to plain text and capped, a link is kept only when it is an ordinary web
 * address, and a picture only when it really is an image.
 */
final class Board {

	/** The board, and its two feeds. */
	public const BOARD_URL  = 'https://padlet.com/lhca1/3sjincg4tfx4hxlm';
	public const EXPORT_URL = 'https://padlet.com/padlets/3sjincg4tfx4hxlm/exports/feed.xml';
	public const FEED_URL   = 'https://padlet.com/padlets/3sjincg4tfx4hxlm/feed.xml';

	/** The daily run, and the one-off first run on a fresh install. */
	public const HOOK      = 'dgl_padlet_refresh';
	public const HOOK_SOON = 'dgl_padlet_first';

	/** The local hour the daily run is due: an hour after the jobs. */
	public const HOUR = 3;

	/** The last good board, how the last attempt went, and the grouping. */
	public const OPTION        = 'dgl_padlet';
	public const OPTION_LAST   = 'dgl_padlet_last';
	public const OPTION_GROUPS = 'dgl_padlet_groups';

	/** Support pages change slowly; a board this old is still shown, older is not. */
	public const STALE_AFTER = 30 * DAY_IN_SECONDS;

	/** The most posts kept. */
	public const MAX_ITEMS = 300;

	/** Seconds to wait for Padlet. */
	public const TIMEOUT = 20;

	/** Where the copied pictures live under uploads, and how wide they are kept. */
	public const PIC_DIR   = 'dgl-padlet';
	public const PIC_WIDTH = 720;

	/** The group a post is in until someone gives it one. */
	public const DEFAULT_GROUP = 'more';

	/** Why a post is left out. */
	public const LEFT_SHOP     = 'shop';
	public const LEFT_UNTITLED = 'untitled';

	/** A picture file's name: a hash of where it came from, and its type. */
	private const PIC_NAME = '/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/';

	/** What a picture may be. */
	private const PIC_TYPES = [
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	];

	/**
	 * The board's columns on 25 October 2026, in its order, with each
	 * column's own introduction. Post id => group, below.
	 *
	 * @return array<int, array{id:string, name:string, intro:string}>
	 */
	public static function seed_groups(): array {
		return [
			[ 'id' => 'mental', 'name' => __( 'Mental health', 'dgl-platform' ), 'intro' => __( 'Mental health covers emotional, psychological and social wellbeing. These services and resources support and improve it.', 'dgl-platform' ) ],
			[ 'id' => 'physical', 'name' => __( 'Physical wellbeing', 'dgl-platform' ), 'intro' => __( 'Staying well day to day, from sleep to exercise and nutrition.', 'dgl-platform' ) ],
			[ 'id' => 'money', 'name' => __( 'Money and cost of living', 'dgl-platform' ), 'intro' => __( 'Help to feel more secure and in control of your finances, now and in the future.', 'dgl-platform' ) ],
			[ 'id' => 'men', 'name' => __( "Men's health", 'dgl-platform' ), 'intro' => __( 'Advice for men, including support groups and podcasts.', 'dgl-platform' ) ],
			[ 'id' => 'women', 'name' => __( "Women's health", 'dgl-platform' ), 'intro' => __( "Support for women's health and mental wellbeing, including support groups, podcasts and other resources.", 'dgl-platform' ) ],
			[ 'id' => 'wider', 'name' => __( 'Wider support', 'dgl-platform' ), 'intro' => __( 'From food banks to vaccinations and volunteering.', 'dgl-platform' ) ],
			[ 'id' => 'training', 'name' => __( 'Training and workshops', 'dgl-platform' ), 'intro' => __( 'Training and workshops from Leeds Health and Care Academy.', 'dgl-platform' ) ],
		];
	}

	/** @return array<string, string> Post id => group id. */
	public static function seed_assign(): array {
		$groups = [
			'mental'   => [ '2315237830', '2315243333', '2315245738', '2315247261', '2315254023', '2315261397', '2703908140', '2703912438', '2703939791' ],
			'physical' => [ '2315262800', '2315412118', '2315417361', '2315479140', '2327359200', '2412102377', '2997525110' ],
			'money'    => [ '2315258802', '2323919514', '2327355107', '2335604203', '2335636192', '2344834392', '2781466180', '2783139351', '2783140942', '2783142290', '2879019585', '2879020325' ],
			'men'      => [ '2315418564', '2315420308', '2315422415', '2703953918' ],
			'women'    => [ '2315255421', '2315424987', '2349032442', '2703921356', '2703925101', '2703942623', '2703945212', '2703948966', '2703950531', '2703951983' ],
			'wider'    => [ '2315264150', '2315414269', '2315428518', '2374963534' ],
			'training' => [ '2315265591', '2315271153', '2327422084', '2464651332', '3355800008', '3355802355', '3355804068' ],
		];

		$assign = [];

		foreach ( $groups as $group => $keys ) {
			foreach ( $keys as $key ) {
				$assign[ $key ] = $group;
			}
		}

		return $assign;
	}

	public static function init(): void {
		add_action( self::HOOK, [ self::class, 'refresh' ], 10, 0 );
		add_action( self::HOOK_SOON, [ self::class, 'refresh' ], 10, 0 );
	}

	/**
	 * Keep the nightly run scheduled, and on a site that has never fetched,
	 * fetch in a minute rather than waiting for tonight.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( \DGL\Jobs\ValJobs::next_run( time(), wp_timezone(), self::HOUR ), 'daily', self::HOOK );
		}

		if ( false === get_option( self::OPTION_LAST, false ) && ! wp_next_scheduled( self::HOOK_SOON ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK_SOON );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOOK_SOON );
	}

	/**
	 * Fetch both feeds and keep the board if it is good. Returns how it
	 * went, which is also what the Health screen and `wp dgl padlet status`
	 * read.
	 *
	 * @return array{at:int, ok:bool, count:int, left_out:int, error:string}
	 */
	public static function refresh(): array {
		$export = self::get( self::EXPORT_URL );
		$full   = self::get( self::FEED_URL );
		$error  = '';
		$board  = null;

		if ( ! is_string( $export ) ) {
			$error = $export->get_error_message();
		} else {
			$posts = self::parse_export( $export );
			$extra = is_string( $full ) ? self::parse_full( $full ) : [];

			if ( null === $posts ) {
				$error = __( 'Padlet answered, but not with the board\'s feed.', 'dgl-platform' );
			} else {
				$board          = self::merge( $posts, $extra ?? [] );
				$board['items'] = self::keep_pictures( $board['items'] );

				update_option( self::OPTION, $board + [ 'fetched' => time() ], false );
			}
		}

		$last = [
			'at'       => time(),
			'ok'       => null !== $board,
			'count'    => null === $board ? 0 : count( $board['items'] ),
			'left_out' => null === $board ? 0 : count( $board['left_out'] ),
			'error'    => $error,
		];

		update_option( self::OPTION_LAST, $last, false );

		return $last;
	}

	/**
	 * One GET, as a body or an error. Both addresses are constants here.
	 */
	private static function get( string $url ): string|\WP_Error {
		$response = wp_remote_get(
			$url,
			[
				'timeout'             => self::TIMEOUT,
				'redirection'         => 3,
				'limit_response_size' => 4 * MB_IN_BYTES,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			/* translators: %d: an HTTP status code such as 403. */
			return new \WP_Error( 'dgl_padlet_http', sprintf( __( 'Padlet answered with HTTP %d.', 'dgl-platform' ), $code ) );
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/* ---- Reading the feeds (pure) ----------------------------------------- */

	/**
	 * The export feed's posts, oldest first, or null when it is not an RSS
	 * feed. Each is the post's id, title, description, outbound link, its
	 * address on Padlet and when it was posted. Pure.
	 *
	 * @return array<int, array{key:string, title:string, text:string, link:string, post:string, at:int}>|null
	 */
	public static function parse_export( string $xml ): ?array {
		$items = self::feed_items( $xml );

		if ( null === $items ) {
			return null;
		}

		$posts = [];

		foreach ( $items as $item ) {
			$post = self::safe_url( self::child( $item, 'link' ) );

			if ( ! preg_match( '~/wish/(\d+)$~', $post, $id ) ) {
				continue;
			}

			$enclosure = self::child_element( $item, 'enclosure' );

			$posts[] = [
				'key'   => $id[1],
				'title' => self::text( self::child( $item, 'title' ), 160 ),
				'text'  => self::text( self::child( $item, 'description' ), 400 ),
				'link'  => null === $enclosure ? '' : self::safe_url( $enclosure->getAttribute( 'url' ) ),
				'post'  => $post,
				'at'    => self::time( self::child( $item, 'pubDate' ) ),
			];

			if ( count( $posts ) >= self::MAX_ITEMS ) {
				break;
			}
		}

		return $posts;
	}

	/**
	 * The full feed's posts, by title and time posted: the description as
	 * HTML (still untrusted, read for its text and first link) and the
	 * attachment's address and type. Pure.
	 *
	 * @return array<string, array{html:string, media:string, type:string}>|null
	 */
	public static function parse_full( string $xml ): ?array {
		$items = self::feed_items( $xml );

		if ( null === $items ) {
			return null;
		}

		$posts = [];

		foreach ( $items as $item ) {
			$enclosure = self::child_element( $item, 'enclosure' );
			$key       = self::join_key( self::text( self::child( $item, 'title' ), 160 ), self::time( self::child( $item, 'pubDate' ) ) );

			$posts[ $key ] = [
				'html'  => self::child( $item, 'description' ),
				'media' => null === $enclosure ? '' : self::safe_url( $enclosure->getAttribute( 'url' ) ),
				'type'  => null === $enclosure ? '' : strtolower( trim( $enclosure->getAttribute( 'type' ) ) ),
			];
		}

		return $posts;
	}

	/**
	 * The board: the posts worth showing, and the ones left out with why.
	 * Pure.
	 *
	 * @param array<int, array{key:string, title:string, text:string, link:string, post:string, at:int}> $posts
	 * @param array<string, array{html:string, media:string, type:string}>                             $extra
	 * @return array{items: array<int, array<string, mixed>>, left_out: array<int, array<string, string>>}
	 */
	public static function merge( array $posts, array $extra ): array {
		$items    = [];
		$left_out = [];
		$seen     = [];
		$by_title = [];

		// A post edited after it was posted carries a different time in each
		// feed; its title alone finds it, when no other post shares it.
		foreach ( array_keys( $extra ) as $key ) {
			$title              = substr( (string) $key, 0, (int) strrpos( (string) $key, '|' ) );
			$by_title[ $title ] = isset( $by_title[ $title ] ) ? false : $key;
		}

		foreach ( $posts as $post ) {
			$exact   = self::join_key( $post['title'], $post['at'] );
			$loose   = $by_title[ mb_strtolower( $post['title'] ) ] ?? false;
			$full    = $extra[ $exact ] ?? ( '' !== $post['title'] && false !== $loose ? $extra[ $loose ] : null );
			$link    = $post['link'];
			$picture = '';

			// An uploaded picture is the post's attachment, not where it leads:
			// the picture is shown and the link goes where the words point.
			if ( self::is_picture_url( $link ) ) {
				$picture = $link;
				$link    = '';
			}

			if ( null !== $full && str_starts_with( $full['type'], 'image/' ) ) {
				$picture = $full['media'];
			}

			if ( '' === $link && null !== $full ) {
				$link = self::first_link( $full['html'] );
			}

			if ( '' === $link && null !== $full && str_starts_with( $full['type'], 'video/' ) ) {
				$link = $full['media'];
			}

			$link = '' === $link ? $post['post'] : $link;

			if ( '' === $post['title'] ) {
				$left_out[] = [ 'key' => $post['key'], 'title' => '', 'link' => $link, 'why' => self::LEFT_UNTITLED ];
				continue;
			}

			if ( self::is_shop( $link ) || ( null !== $full && self::is_shop( self::first_link( $full['html'] ) ) ) ) {
				$left_out[] = [ 'key' => $post['key'], 'title' => $post['title'], 'link' => $link, 'why' => self::LEFT_SHOP ];
				continue;
			}

			$text = null !== $full ? self::description( $full['html'] ) : '';
			$text = '' === $text ? self::without_urls( $post['text'] ) : $text;

			// The same title to the same place twice: one card, the fuller words.
			$same = strtolower( $post['title'] ) . '|' . $link;

			if ( isset( $seen[ $same ] ) ) {
				if ( mb_strlen( $text ) > mb_strlen( (string) $items[ $seen[ $same ] ]['text'] ) ) {
					$items[ $seen[ $same ] ]['text'] = $text;
				}
				continue;
			}

			$seen[ $same ] = count( $items );
			$items[]       = [
				'key'     => $post['key'],
				'title'   => $post['title'],
				'text'    => $text,
				'link'    => $link,
				'host'    => self::host_label( $link ),
				'kind'    => self::kind( $link, null === $full ? '' : $full['type'] ),
				'picture' => self::safe_https( $picture ),
				'at'      => $post['at'],
			];
		}

		return [ 'items' => $items, 'left_out' => $left_out ];
	}

	/**
	 * A post's words as plain text: the description with its links' bare
	 * addresses taken out (Padlet prints the attachment's address as the
	 * first line) and embedded players dropped. Pure.
	 */
	public static function description( string $html ): string {
		$html = (string) preg_replace( '~<(iframe|script|style)\b[^>]*>.*?</\1>~is', ' ', $html );
		// A link whose words are its own address says nothing: gone, and
		// every other tag is a space, so words either side never run together.
		$html = (string) preg_replace( '~<a\b[^>]*>\s*https?://[^<]*</a>~i', ' ', $html );
		$html = (string) preg_replace( '~<[^>]*>~', ' ', $html );

		return self::without_urls( self::text( $html, 400 ) );
	}

	/** Text with any web address in it taken out, tidied. Pure. */
	public static function without_urls( string $text ): string {
		$text = (string) preg_replace( '~\bhttps?://\S+~i', ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * The first ordinary link in a description, or ''. Pure.
	 */
	public static function first_link( string $html ): string {
		if ( ! preg_match_all( '~<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $links ) ) {
			return '';
		}

		foreach ( $links[2] as $href ) {
			$url = self::safe_url( $href );

			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Whether a link is a shop's: Amazon on any of its domains, or its
	 * short links. Pure.
	 */
	public static function is_shop( string $url ): bool {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		return '' !== $host && ( 1 === preg_match( '/(^|\.)(amazon|amzn)\.[a-z.]{2,12}$/', $host ) || 'a.co' === $host );
	}

	/** Whether an address is a picture file rather than a page. Pure. */
	public static function is_picture_url( string $url ): bool {
		$ext = strtolower( pathinfo( (string) parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		return '' !== $url && isset( self::PIC_TYPES[ 'jpeg' === $ext ? 'jpg' : $ext ] );
	}

	/**
	 * What kind of thing the link opens, when it is not just a website:
	 * a podcast, a video or a document. Pure.
	 */
	public static function kind( string $url, string $type = '' ): string {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		$ext  = strtolower( pathinfo( (string) parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

		if ( 'open.spotify.com' === $host || str_ends_with( $host, 'podcasts.apple.com' ) ) {
			return __( 'Podcast', 'dgl-platform' );
		}

		if ( str_starts_with( $type, 'video/' ) || in_array( $ext, [ 'mp4', 'webm', 'mov' ], true ) || 1 === preg_match( '/(^|\.)(ted\.com|youtube\.com|youtu\.be|vimeo\.com)$/', $host ) ) {
			return __( 'Video', 'dgl-platform' );
		}

		if ( 'pdf' === $ext ) {
			return __( 'PDF', 'dgl-platform' );
		}

		if ( 'drive.google.com' === $host || 'docs.google.com' === $host ) {
			return __( 'Document', 'dgl-platform' );
		}

		return '';
	}

	/** The site a link goes to, as a reader would say it: "nhs.uk". Pure. */
	public static function host_label( string $url ): string {
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * An ordinary web address, or ''. Padlet posts point anywhere, so any
	 * host will do, but only over http or https, with no login or port in
	 * it and nothing that could break out of an attribute. Pure.
	 */
	public static function safe_url( string $url ): string {
		$url   = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$parts = parse_url( $url );

		if ( ! is_array( $parts ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) {
			return '';
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || '' === (string) ( $parts['host'] ?? '' ) ) {
			return '';
		}

		return preg_match( '/[\s"\'<>`]/', $url ) ? '' : $url;
	}

	/** A safe address that is also https, or ''. Pictures are fetched only over https. Pure. */
	public static function safe_https( string $url ): string {
		$url = self::safe_url( $url );

		return str_starts_with( strtolower( $url ), 'https://' ) ? $url : '';
	}

	/**
	 * Plain text, on one line, no longer than $max characters. Pure.
	 */
	public static function text( string $raw, int $max ): string {
		$text = html_entity_decode( strip_tags( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );

		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
	}

	/** How the two feeds' copies of a post find each other. Pure. */
	private static function join_key( string $title, int $at ): string {
		return mb_strtolower( $title ) . '|' . $at;
	}

	/** A feed date as a timestamp, or 0. Pure. */
	private static function time( string $date ): int {
		$at = strtotime( trim( $date ) );

		return false === $at ? 0 : $at;
	}

	/**
	 * The items of an RSS feed, or null when it is not one. No network, no
	 * entity expansion.
	 *
	 * @return DOMElement[]|null
	 */
	private static function feed_items( string $xml ): ?array {
		if ( '' === trim( $xml ) ) {
			return null;
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$ok       = $doc->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $ok || 'rss' !== $doc->documentElement?->localName ) {
			return null;
		}

		$items = [];

		foreach ( $doc->getElementsByTagName( 'item' ) as $item ) {
			$items[] = $item;
		}

		return $items;
	}

	private static function child_element( DOMElement $item, string $name ): ?DOMElement {
		foreach ( $item->childNodes as $child ) {
			if ( $child instanceof DOMElement && $name === $child->localName ) {
				return $child;
			}
		}

		return null;
	}

	private static function child( DOMElement $item, string $name ): string {
		return (string) self::child_element( $item, $name )?->textContent;
	}

	/* ---- Pictures --------------------------------------------------------- */

	/**
	 * Copy each post's picture into uploads, unless it is there already, and
	 * delete the copies no post uses any more.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	private static function keep_pictures( array $items ): array {
		$dir  = self::pic_dir();
		$kept = [];

		foreach ( $items as $i => $item ) {
			$src  = (string) ( $item['picture'] ?? '' );
			$name = self::pic_name( $src );
			$ok   = false;

			if ( '' !== $name && '' !== $dir && wp_mkdir_p( $dir ) ) {
				$ok = $kept[ $name ] ?? ( file_exists( $dir . '/' . $name ) || self::copy_picture( $src, $dir . '/' . $name ) );

				$kept[ $name ] = $ok;
			}

			$items[ $i ]['picture_file'] = $ok ? $name : '';
		}

		if ( '' !== $dir ) {
			foreach ( (array) glob( $dir . '/*' ) as $path ) {
				$file = basename( (string) $path );

				if ( preg_match( self::PIC_NAME, $file ) && empty( $kept[ $file ] ) ) {
					wp_delete_file( (string) $path );
				}
			}
		}

		return $items;
	}

	/** Fetch one picture, check it is the image its name says, and keep it no wider than PIC_WIDTH. */
	private static function copy_picture( string $url, string $path ): bool {
		$body = self::get( $url );

		if ( ! is_string( $body ) || '' === $body ) {
			return false;
		}

		$info = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a non-image is an expected answer, not a warning.
		$ext  = pathinfo( $path, PATHINFO_EXTENSION );

		if ( ! is_array( $info ) || ( self::PIC_TYPES[ $ext ] ?? '' ) !== ( $info['mime'] ?? '' ) ) {
			return false;
		}

		if ( false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own uploads folder, written by cron.
			return false;
		}

		$editor = wp_get_image_editor( $path );

		if ( ! is_wp_error( $editor ) && ! is_wp_error( $editor->resize( self::PIC_WIDTH, null, false ) ) ) {
			$editor->save( $path );
		}

		return true;
	}

	/**
	 * The file a picture is kept as: a hash of its address without the
	 * signature (a new token tomorrow is the same picture) and its type, or
	 * '' for anything that is not an image address we keep. Pure.
	 */
	public static function pic_name( string $url ): string {
		if ( '' === self::safe_https( $url ) ) {
			return '';
		}

		$ext = strtolower( pathinfo( (string) parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$ext = 'jpeg' === $ext ? 'jpg' : $ext;
		$at  = strtok( $url, '?#' );

		return isset( self::PIC_TYPES[ $ext ] ) ? md5( (string) $at ) . '.' . $ext : '';
	}

	private static function pic_dir(): string {
		$uploads = wp_upload_dir( null, false );

		return empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ? $uploads['basedir'] . '/' . self::PIC_DIR : '';
	}

	/**
	 * A post's copied picture as an address on this site, or ''.
	 *
	 * @param array<string, mixed> $item
	 */
	public static function picture_url( array $item ): string {
		$name = (string) ( $item['picture_file'] ?? '' );
		$dir  = self::pic_dir();

		if ( ! preg_match( self::PIC_NAME, $name ) || '' === $dir || ! file_exists( $dir . '/' . $name ) ) {
			return '';
		}

		return wp_upload_dir( null, false )['baseurl'] . '/' . self::PIC_DIR . '/' . $name;
	}

	/* ---- What is stored ---------------------------------------------------- */

	/**
	 * The stored posts, or none when the board is stale.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function items( ?int $now = null ): array {
		$store = get_option( self::OPTION, [] );

		return self::fresh( is_array( $store ) ? $store : [], $now ?? time() );
	}

	/**
	 * The posts in a stored board, unless it is too old to trust. Pure.
	 *
	 * @param array<string, mixed> $store
	 * @return array<int, array<string, mixed>>
	 */
	public static function fresh( array $store, int $now ): array {
		$fetched = (int) ( $store['fetched'] ?? 0 );

		if ( $fetched <= 0 || $now - $fetched > self::STALE_AFTER || ! is_array( $store['items'] ?? null ) ) {
			return [];
		}

		return array_values( array_filter( $store['items'], 'is_array' ) );
	}

	/**
	 * The posts left out of the stored board, with why.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function left_out(): array {
		$store = get_option( self::OPTION, [] );

		return is_array( $store ) && is_array( $store['left_out'] ?? null ) ? array_values( array_filter( $store['left_out'], 'is_array' ) ) : [];
	}

	/** When the stored board was fetched, or 0. */
	public static function fetched(): int {
		$store = get_option( self::OPTION, [] );

		return is_array( $store ) ? (int) ( $store['fetched'] ?? 0 ) : 0;
	}

	/**
	 * How the last attempt went, or null before the first.
	 *
	 * @return array{at:int, ok:bool, count:int, left_out:int, error:string}|null
	 */
	public static function last(): ?array {
		$last = get_option( self::OPTION_LAST, false );

		if ( ! is_array( $last ) ) {
			return null;
		}

		return [
			'at'       => (int) ( $last['at'] ?? 0 ),
			'ok'       => (bool) ( $last['ok'] ?? false ),
			'count'    => (int) ( $last['count'] ?? 0 ),
			'left_out' => (int) ( $last['left_out'] ?? 0 ),
			'error'    => (string) ( $last['error'] ?? '' ),
		];
	}

	/* ---- Groups ------------------------------------------------------------- */

	/**
	 * The groups and which post is in which: what was saved, or the seed.
	 *
	 * @return array{groups: array<int, array{id:string, name:string, intro:string}>, assign: array<string, string>}
	 */
	public static function grouping(): array {
		$saved = get_option( self::OPTION_GROUPS, null );

		if ( ! is_array( $saved ) || ! is_array( $saved['groups'] ?? null ) ) {
			return [ 'groups' => self::seed_groups(), 'assign' => self::seed_assign() ];
		}

		return self::clean_grouping( $saved );
	}

	/**
	 * A grouping made safe to store and to draw: each group has an id made
	 * of letters, numbers and dashes, a plain name and a plain introduction;
	 * no two share an id, a group with no name is dropped, and a post is
	 * only ever assigned to a group that exists. Pure.
	 *
	 * @param array<string, mixed> $raw
	 * @return array{groups: array<int, array{id:string, name:string, intro:string}>, assign: array<string, string>}
	 */
	public static function clean_grouping( array $raw ): array {
		$groups = [];
		$ids    = [];

		foreach ( (array) ( $raw['groups'] ?? [] ) as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$name = self::text( (string) ( $group['name'] ?? '' ), 80 );
			$id   = strtolower( (string) preg_replace( '/[^a-z0-9-]+/i', '-', (string) ( $group['id'] ?? '' ) ) );
			$id   = trim( '' === trim( $id, '-' ) ? strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $name ) ) : $id, '-' );

			if ( '' === $name || '' === $id || self::DEFAULT_GROUP === $id || isset( $ids[ $id ] ) ) {
				continue;
			}

			$ids[ $id ] = true;
			$groups[]   = [ 'id' => $id, 'name' => $name, 'intro' => self::text( (string) ( $group['intro'] ?? '' ), 300 ) ];
		}

		$assign = [];

		foreach ( (array) ( $raw['assign'] ?? [] ) as $key => $group ) {
			// "More support" by choice is kept, so the post is not shown as new.
			if ( preg_match( '/^\d{1,20}$/', (string) $key ) && ( isset( $ids[ (string) $group ] ) || self::DEFAULT_GROUP === $group ) ) {
				$assign[ (string) $key ] = (string) $group;
			}
		}

		return [ 'groups' => $groups, 'assign' => $assign ];
	}

	/**
	 * The posts sorted into their groups, in the groups' order, each group's
	 * posts by title, and the posts nobody has placed in "More support" at
	 * the end. A group with no posts is left out. Pure.
	 *
	 * @param array<int, array<string, mixed>>                                                                   $items
	 * @param array{groups: array<int, array{id:string, name:string, intro:string}>, assign: array<string, string>} $grouping
	 * @return array<int, array{id:string, name:string, intro:string, items: array<int, array<string, mixed>>}>
	 */
	public static function grouped( array $items, array $grouping ): array {
		$groups = [];

		foreach ( $grouping['groups'] as $group ) {
			$groups[ $group['id'] ] = $group + [ 'items' => [] ];
		}

		$groups[ self::DEFAULT_GROUP ] = [
			'id'    => self::DEFAULT_GROUP,
			'name'  => __( 'More support', 'dgl-platform' ),
			'intro' => '',
			'items' => [],
		];

		foreach ( $items as $item ) {
			$group = $grouping['assign'][ (string) ( $item['key'] ?? '' ) ] ?? self::DEFAULT_GROUP;

			$groups[ isset( $groups[ $group ] ) ? $group : self::DEFAULT_GROUP ]['items'][] = $item;
		}

		foreach ( $groups as $id => $group ) {
			usort( $group['items'], static fn( array $a, array $b ): int => strnatcasecmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) ) );

			$groups[ $id ] = $group;
		}

		return array_values( array_filter( $groups, static fn( array $group ): bool => [] !== $group['items'] ) );
	}

	/** The verdicts the Health screen draws. */
	public const GOOD = 'good';
	public const WARN = 'warn';
	public const BAD  = 'bad';

	/**
	 * How worried to be: as for the jobs, but the board is allowed to be a
	 * few days old before it is amber. Pure.
	 *
	 * @param array{at:int, ok:bool, count:int, left_out:int, error:string}|null $last
	 */
	public static function verdict( ?array $last, int $fetched, int $now ): string {
		if ( $fetched <= 0 || $now - $fetched > self::STALE_AFTER ) {
			return null === $last ? self::WARN : self::BAD;
		}

		if ( null !== $last && ! $last['ok'] ) {
			return self::WARN;
		}

		return $now - $fetched > 3 * DAY_IN_SECONDS ? self::WARN : self::GOOD;
	}
}

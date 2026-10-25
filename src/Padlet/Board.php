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
 * board is read and shown as this site's own cards instead.
 *
 * Two sources. First the board's own data: the same public answer the
 * board page loads in a visitor's browser, with every post's column, its
 * place in the column, its link and Padlet's thumbnail for it. Padlet does
 * not document it, so if it stops answering, or answers with something
 * that is not a board, the two RSS feeds Padlet does publish are read
 * instead. They carry no columns and almost no pictures, so then each post
 * keeps the column and picture it had at the last good read, a post new
 * since then shows under "More support", and the Health screen is amber.
 *
 * The groups are the board's own columns, in its order, each with the
 * introduction its header post carries. Under Settings, Wellbeing board the
 * team can rename a column, change its introduction or move one post to
 * another column on this site; nothing else needs keeping up.
 *
 * Left out, and listed on the settings screen so nobody wonders where they
 * went: posts that link to Amazon (book recommendations with shopping
 * tracking on the link, which on this site read as adverts), and posts with
 * neither a title nor a linked page to take one from (the column header
 * posts, whose words become the column's introduction).
 *
 * Pictures are copied to this site's uploads, as the jobs feed does with
 * logos: a visitor's browser should not be calling Padlet to see our page,
 * and some upload links carry a token that expires. The nightly run spends
 * at most PICTURE_BUDGET seconds on new pictures and carries the rest over.
 *
 * Once a night, never on a page render, and the last good board is kept if
 * Padlet is down. Everything that comes back is untrusted: text is stripped
 * to plain text and capped, a link is kept only when it is an ordinary web
 * address, and a picture only when it really is an image.
 */
final class Board {

	/** The board, its data, and its two feeds. */
	public const BOARD_URL    = 'https://padlet.com/lhca1/3sjincg4tfx4hxlm';
	public const WISHES_URL   = 'https://padlet.com/api/10/wishes?wall_hashid=board_Y0KryDdYbjVlyPBb&page_start=';
	public const SECTIONS_URL = 'https://padlet.com/api/10/wall_sections?wall_id=%d';
	public const EXPORT_URL   = 'https://padlet.com/padlets/3sjincg4tfx4hxlm/exports/feed.xml';
	public const FEED_URL     = 'https://padlet.com/padlets/3sjincg4tfx4hxlm/feed.xml';

	/** Where a board came from. */
	public const FROM_BOARD = 'board';
	public const FROM_FEEDS = 'feeds';

	/** The daily run, and the one-off first run on a fresh install. */
	public const HOOK      = 'dgl_padlet_refresh';
	public const HOOK_SOON = 'dgl_padlet_first';

	/** The local hour the daily run is due: an hour after the jobs. */
	public const HOUR = 3;

	/** The last good board, how the last attempt went, and the team's changes to the columns. */
	public const OPTION        = 'dgl_padlet';
	public const OPTION_LAST   = 'dgl_padlet_last';
	public const OPTION_GROUPS = 'dgl_padlet_groups';

	/** Support pages change slowly; a board this old is still shown, older is not. */
	public const STALE_AFTER = 30 * DAY_IN_SECONDS;

	/** The most posts kept, and the most pages of the board's data read. */
	public const MAX_ITEMS = 300;
	public const MAX_PAGES = 10;

	/** Seconds to wait for Padlet. */
	public const TIMEOUT = 20;

	/** Where the copied pictures live under uploads, and how wide they are kept. */
	public const PIC_DIR   = 'dgl-padlet';
	public const PIC_WIDTH = 720;

	/** Seconds the nightly run may spend on new pictures; what is left carries over to the next night. */
	public const PICTURE_BUDGET = 15;

	/** Seconds to wait for one picture. */
	public const PAGE_TIMEOUT = 8;

	/** The group for a post with no column. */
	public const DEFAULT_GROUP = 'more';

	/** Why a post is left out. */
	public const LEFT_SHOP     = 'shop';
	public const LEFT_UNTITLED = 'untitled';
	public const LEFT_BROKEN   = 'broken';

	/**
	 * What each card's link answered when it was last checked, by link, and
	 * how often a link that answers is checked again. A link that has just
	 * answered "gone" once is checked again on the next run, to confirm.
	 */
	public const OPTION_LINKS = 'dgl_padlet_links';
	public const LINK_EVERY   = 7 * DAY_IN_SECONDS;

	/** A link's answer when its site's name no longer resolves (or is not on the open internet). */
	public const NO_HOST = -1;

	/** A link's answer when there was none at all (a timeout, a dropped connection). */
	public const NO_ANSWER = 0;

	/** A picture file's name: a hash of where it came from, and its type. */
	private const PIC_NAME = '/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/';

	/** What a picture may be. */
	private const PIC_TYPES = [
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	];

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
	 * Read the board and keep it if it is good. Returns how it went, which
	 * is also what the Health screen and `wp dgl padlet status` read.
	 *
	 * @return array{at:int, ok:bool, count:int, left_out:int, error:string, source:string}
	 */
	public static function refresh( int $budget = self::PICTURE_BUDGET ): array {
		$previous = get_option( self::OPTION, [] );
		$previous = is_array( $previous ) ? $previous : [];
		$board    = self::from_board( $previous );
		$source   = self::FROM_BOARD;
		$error    = '';

		if ( is_wp_error( $board ) ) {
			$error  = $board->get_error_message();
			$board  = self::from_feeds( $previous );
			$source = self::FROM_FEEDS;
		}

		if ( is_wp_error( $board ) ) {
			$error = $board->get_error_message();
			$board = null;
		} else {
			$items    = $board['items'];
			$deadline = time() + max( 0, $budget );
			$links    = get_option( self::OPTION_LINKS, [] );
			$links    = is_array( $links ) ? $links : [];
			$store    = static function ( array $with, array $links ) use ( $board, $source ): array {
				[ $shown, $gone ] = self::without_gone( $with, $links );

				return [ 'items' => $shown, 'left_out' => array_merge( $board['left_out'], $gone ) ] + $board + [ 'source' => $source, 'fetched' => time() ];
			};

			// The board first, with the pictures already on this site and the
			// links already known to be gone left out, so a run cut short
			// still leaves a whole board; then the new pictures and the links
			// due a check, as many as the time allows.
			update_option( self::OPTION, $store( self::keep_pictures( $items, 0, false ), $links ), false );

			$items = self::keep_pictures( $items, $deadline, true );
			$links = self::check_links( $items, $links, $deadline );

			update_option( self::OPTION_LINKS, $links, false );
			update_option( self::OPTION, $store( $items, $links ), false );

			$board = get_option( self::OPTION, $board );
		}

		$last = [
			'at'       => time(),
			'ok'       => null !== $board,
			'count'    => null === $board ? 0 : count( $board['items'] ),
			'left_out' => null === $board ? 0 : count( $board['left_out'] ),
			'error'    => $error,
			'source'   => null === $board ? '' : $source,
		];

		update_option( self::OPTION_LAST, $last, false );

		return $last;
	}

	/**
	 * The board from its own data, or why not.
	 *
	 * @param array<string, mixed> $previous The last good board.
	 * @return array{items: array<int, array<string, mixed>>, left_out: array<int, array<string, string>>, sections: array<int, array{id:string, name:string, intro:string}>}|\WP_Error
	 */
	private static function from_board( array $previous ): array|\WP_Error {
		$rows = [];
		$next = '';

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$body = self::get( self::WISHES_URL . rawurlencode( $next ) );

			if ( ! is_string( $body ) ) {
				return $body;
			}

			$data = self::json_rows( $body );

			if ( null === $data ) {
				return new \WP_Error( 'dgl_padlet_board', __( 'Padlet answered, but not with the board\'s posts.', 'dgl-platform' ) );
			}

			$rows = array_merge( $rows, $data );
			$meta = json_decode( $body, true )['meta'] ?? [];
			$next = is_array( $meta ) && is_scalar( $meta['next'] ?? null ) ? (string) $meta['next'] : '';

			if ( '' === $next ) {
				break;
			}
		}

		if ( [] === $rows ) {
			return new \WP_Error( 'dgl_padlet_board', __( 'Padlet answered with no posts at all.', 'dgl-platform' ) );
		}

		$wall    = (int) ( $rows[0]['attributes']['wall_id'] ?? 0 );
		$answer  = $wall > 0 ? self::get( sprintf( self::SECTIONS_URL, $wall ) ) : '';
		$columns = is_string( $answer ) ? self::json_rows( $answer ) : null;
		$board   = self::parse_board( $rows, $columns ?? [] );

		// Columns that did not come back tonight: the ones from the last good read.
		if ( null === $columns && is_array( $previous['sections'] ?? null ) ) {
			$board['sections'] = $previous['sections'];
		}

		return $board;
	}

	/**
	 * The board from the two RSS feeds, each post keeping the column and
	 * picture it had at the last good read, or why not.
	 *
	 * @param array<string, mixed> $previous The last good board.
	 * @return array{items: array<int, array<string, mixed>>, left_out: array<int, array<string, string>>, sections: array<int, array{id:string, name:string, intro:string}>}|\WP_Error
	 */
	private static function from_feeds( array $previous ): array|\WP_Error {
		$export = self::get( self::EXPORT_URL );

		if ( ! is_string( $export ) ) {
			return $export;
		}

		$posts = self::parse_export( $export );

		if ( null === $posts ) {
			return new \WP_Error( 'dgl_padlet_feed', __( 'Padlet answered, but not with the board\'s feed.', 'dgl-platform' ) );
		}

		$full  = self::get( self::FEED_URL );
		$board = self::merge( $posts, is_string( $full ) ? ( self::parse_full( $full ) ?? [] ) : [] );
		$known = [];

		foreach ( is_array( $previous['items'] ?? null ) ? $previous['items'] : [] as $item ) {
			if ( is_array( $item ) && isset( $item['key'] ) ) {
				$known[ (string) $item['key'] ] = $item;
			}
		}

		foreach ( $board['items'] as $i => $item ) {
			$was = $known[ $item['key'] ] ?? [];

			$board['items'][ $i ]['section'] = (string) ( $was['section'] ?? '' );
			$board['items'][ $i ]['order']   = (int) ( $was['order'] ?? $item['at'] );

			if ( '' !== (string) ( $was['picture'] ?? '' ) ) {
				$board['items'][ $i ]['picture'] = (string) $was['picture'];
			}
		}

		$board['sections'] = is_array( $previous['sections'] ?? null ) ? $previous['sections'] : [];

		return $board;
	}

	/**
	 * One GET, as a body or an error. Padlet's own addresses are constants
	 * here and are fetched plainly; a picture a post points at is untrusted,
	 * so it goes through WordPress's safe fetch, which refuses addresses on
	 * a private network.
	 */
	private static function get( string $url, bool $untrusted = false ): string|\WP_Error {
		$args = [
			'timeout'             => $untrusted ? self::PAGE_TIMEOUT : self::TIMEOUT,
			'redirection'         => $untrusted ? 4 : 3,
			'limit_response_size' => 4 * MB_IN_BYTES,
			'headers'             => [ 'Accept' => 'application/json, application/rss+xml, application/xml;q=0.9, image/*;q=0.8, */*;q=0.5' ],
		];

		$response = $untrusted ? wp_safe_remote_get( $url, $args ) : wp_remote_get( $url, $args );

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

	/* ---- Reading the board's own data (pure) -------------------------------- */

	/**
	 * The rows of a JSON:API answer (`{"data": [...]}`), or null when it is
	 * not one. Pure.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function json_rows( string $json ): ?array {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || ! is_array( $data['data'] ?? null ) ) {
			return null;
		}

		return array_values( array_filter( $data['data'], static fn( $row ): bool => is_array( $row ) && is_array( $row['attributes'] ?? null ) ) );
	}

	/**
	 * The board from its own data: the posts worth showing, in their
	 * columns; the posts left out, with why; and the columns, in the
	 * board's order, each with the introduction its header post carries.
	 * Pure.
	 *
	 * A post with no title takes its linked page's title. One with neither
	 * is a column header: its words become the column's introduction, and
	 * the post itself is left out. A post whose attachment is a picture is
	 * shown with it and leads where its words point.
	 *
	 * @param array<int, array<string, mixed>> $wishes   The posts' rows.
	 * @param array<int, array<string, mixed>> $sections The columns' rows.
	 * @return array{items: array<int, array<string, mixed>>, left_out: array<int, array<string, string>>, sections: array<int, array{id:string, name:string, intro:string}>}
	 */
	public static function parse_board( array $wishes, array $sections ): array {
		$columns = [];

		foreach ( $sections as $row ) {
			$a  = (array) ( $row['attributes'] ?? [] );
			$id = (string) ( $a['id'] ?? $row['id'] ?? '' );

			if ( ctype_digit( $id ) ) {
				$columns[] = [ 'id' => 's' . $id, 'name' => self::text( (string) ( $a['title'] ?? '' ), 80 ), 'order' => (int) ( $a['sort_index'] ?? 0 ) ];
			}
		}

		usort( $columns, static fn( array $x, array $y ): int => $x['order'] <=> $y['order'] );

		$items    = [];
		$left_out = [];
		$intros   = [];
		$seen     = [];

		foreach ( array_slice( $wishes, 0, self::MAX_ITEMS ) as $row ) {
			$a   = (array) ( $row['attributes'] ?? [] );
			$key = (string) ( $a['id'] ?? $row['id'] ?? '' );

			if ( ! ctype_digit( $key ) || false === ( $a['published'] ?? true ) || 'everyone' !== ( $a['visibility'] ?? 'everyone' ) || ! empty( $a['is_content_hidden'] ) ) {
				continue;
			}

			$link_data  = is_array( $a['attachment_link'] ?? null ) ? $a['attachment_link'] : [];
			$attachment = self::safe_url( (string) ( $a['attachment'] ?? '' ) );
			$type       = strtolower( (string) ( $link_data['content_type'] ?? '' ) );
			$category   = strtolower( (string) ( $link_data['content_category'] ?? '' ) );
			$is_picture = 'photo' === $category || str_starts_with( $type, 'image/' ) || self::is_picture_url( $attachment );
			$body       = (string) ( $a['body'] ?? '' );
			$section    = ctype_digit( (string) ( $a['wall_section_id'] ?? '' ) ) ? 's' . $a['wall_section_id'] : '';
			$post       = self::safe_url( (string) ( $a['permalink'] ?? '' ) );
			$preview    = is_array( $link_data['preview_image'] ?? null ) ? self::safe_url( (string) ( $link_data['preview_image']['url'] ?? '' ) ) : '';
			$link       = $is_picture ? self::first_link( $body ) : $attachment;
			$link       = '' === $link ? $post : $link;
			$title      = self::text( (string) ( $a['subject'] ?? '' ), 160 );
			$text       = self::description( $body );

			if ( '' === $title && ! $is_picture && '' !== $attachment ) {
				$title = self::text( (string) ( $link_data['title'] ?? '' ), 160 );
			}

			if ( '' === $title ) {
				if ( '' !== $section && '' !== $text && ! isset( $intros[ $section ] ) ) {
					$intros[ $section ] = self::text( $text, 300 );
				}

				$left_out[] = [ 'key' => $key, 'title' => '', 'link' => $link, 'why' => self::LEFT_UNTITLED ];
				continue;
			}

			if ( self::is_shop( $link ) || self::is_shop( $attachment ) || self::is_shop( self::first_link( $body ) ) ) {
				$left_out[] = [ 'key' => $key, 'title' => $title, 'link' => $link, 'why' => self::LEFT_SHOP ];
				continue;
			}

			// No words of its own: the linked page's own summary of itself.
			if ( '' === $text && ! $is_picture ) {
				$text = self::text( (string) ( $link_data['description'] ?? '' ), 220 );
			}

			$same = strtolower( $title ) . '|' . $link;

			if ( isset( $seen[ $same ] ) ) {
				continue;
			}

			$seen[ $same ] = true;
			$created       = strtotime( (string) ( $a['created_at'] ?? '' ) );
			$items[]       = [
				'key'     => $key,
				'title'   => $title,
				'text'    => $text,
				'link'    => $link,
				'host'    => self::host_label( $link ),
				'kind'    => 'video' === $category ? __( 'Video', 'dgl-platform' ) : self::kind( $link, $type ),
				'picture' => '' !== $preview ? $preview : ( $is_picture ? $attachment : '' ),
				'section' => $section,
				'order'   => (int) ( $a['sort_index'] ?? 0 ),
				'at'      => false === $created ? 0 : $created,
			];
		}

		return [
			'items'    => $items,
			'left_out' => $left_out,
			'sections' => array_map(
				static fn( array $c ): array => [ 'id' => $c['id'], 'name' => $c['name'], 'intro' => $intros[ $c['id'] ] ?? '' ],
				$columns
			),
		];
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
				'picture' => self::safe_url( $picture ),
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

	/* ---- Links that have gone ----------------------------------------------- */

	/**
	 * Whether a link is gone, from its last answers, oldest first. Gone is
	 * only ever a clear answer, twice running: "not found" (404), "gone"
	 * (410), or a site whose name no longer resolves. Anything else, a
	 * refusal (401, 403), "too many requests" (429), a server error, a
	 * timeout, leaves the card showing: many sites refuse a server they
	 * would serve to a browser, and a bad night is not a dead page. Pure.
	 *
	 * @param int[] $answers
	 */
	public static function link_verdict( array $answers ): string {
		$gone = static fn( int $code ): bool => in_array( $code, [ 404, 410, self::NO_HOST ], true );
		$last = array_slice( array_map( 'intval', array_values( $answers ) ), -2 );

		if ( 2 === count( $last ) && $gone( $last[0] ) && $gone( $last[1] ) ) {
			return 'gone';
		}

		$latest = end( $last );

		return false !== $latest && $latest >= 200 && $latest < 400 ? 'ok' : 'unknown';
	}

	/**
	 * The cards still worth showing, and the ones whose link is gone, as
	 * left-out rows. Pure.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, mixed>             $links
	 * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, string>>}
	 */
	public static function without_gone( array $items, array $links ): array {
		$shown = [];
		$gone  = [];

		foreach ( $items as $item ) {
			$known = $links[ md5( (string) ( $item['link'] ?? '' ) ) ] ?? null;

			if ( is_array( $known ) && 'gone' === self::link_verdict( (array) ( $known['answers'] ?? [] ) ) ) {
				$gone[] = [ 'key' => (string) $item['key'], 'title' => (string) $item['title'], 'link' => (string) $item['link'], 'why' => self::LEFT_BROKEN ];
				continue;
			}

			$shown[] = $item;
		}

		return [ $shown, $gone ];
	}

	/**
	 * Whether a link is due a check: never checked, last checked a week or
	 * more ago, or just answered "gone" once and needs confirming. Pure.
	 *
	 * @param array<string, mixed>|null $known
	 */
	public static function link_due( ?array $known, int $now ): bool {
		if ( null === $known ) {
			return true;
		}

		$answers = array_map( 'intval', (array) ( $known['answers'] ?? [] ) );
		$latest  = end( $answers );
		$gone    = false !== $latest && in_array( $latest, [ 404, 410, self::NO_HOST ], true );

		return ( $gone && 'gone' !== self::link_verdict( $answers ) ) || $now - (int) ( $known['at'] ?? 0 ) >= self::LINK_EVERY;
	}

	/**
	 * Check the links that are due, oldest check first, while there is time
	 * before $deadline, and forget the links no card uses any more.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @param array<string, mixed>             $links
	 * @return array<string, array{link:string, answers:int[], at:int}>
	 */
	private static function check_links( array $items, array $links, int $deadline ): array {
		$kept = [];

		foreach ( $items as $item ) {
			$link = (string) ( $item['link'] ?? '' );

			if ( '' !== $link ) {
				$key          = md5( $link );
				$known        = is_array( $links[ $key ] ?? null ) ? $links[ $key ] : null;
				$kept[ $key ] = $known ?? [ 'link' => $link, 'answers' => [], 'at' => 0 ];
			}
		}

		uasort( $kept, static fn( array $a, array $b ): int => (int) $a['at'] <=> (int) $b['at'] );

		foreach ( $kept as $key => $known ) {
			if ( time() >= $deadline ) {
				break;
			}

			if ( ! self::link_due( [] === $known['answers'] ? null : $known, time() ) ) {
				continue;
			}

			$answers      = array_slice( array_merge( array_map( 'intval', (array) $known['answers'] ), [ self::answer( (string) $known['link'] ) ] ), -3 );
			$kept[ $key ] = [ 'link' => (string) $known['link'], 'answers' => $answers, 'at' => time() ];
		}

		return $kept;
	}

	/**
	 * What one link answers: its HTTP status after redirects, NO_HOST when
	 * its site's name does not resolve, NO_ANSWER when nothing came back.
	 * Fetched through WordPress's safe fetch, which refuses private
	 * addresses and only reads the first part of the page.
	 */
	private static function answer( string $url ): int {
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => self::PAGE_TIMEOUT,
				'redirection'         => 5,
				'limit_response_size' => 64 * KB_IN_BYTES,
			]
		);

		if ( is_wp_error( $response ) ) {
			$message = strtolower( $response->get_error_message() );

			// The safe fetch says "a valid URL was not provided" when the name does not resolve.
			return str_contains( $message, 'resolve host' ) || str_contains( $message, 'valid url was not provided' ) ? self::NO_HOST : self::NO_ANSWER;
		}

		return (int) wp_remote_retrieve_response_code( $response );
	}

	/* ---- Pictures --------------------------------------------------------- */

	/**
	 * Copy each post's picture into uploads, unless it is there already, and
	 * (with $prune) delete the copies no post uses any more. A picture is
	 * only fetched while there is time left before $deadline.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	private static function keep_pictures( array $items, int $deadline, bool $prune ): array {
		$dir  = self::pic_dir();
		$kept = [];

		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			return array_map( static fn( array $item ): array => [ 'picture_file' => '' ] + $item, $items );
		}

		foreach ( $items as $i => $item ) {
			$base = self::pic_base( (string) ( $item['picture'] ?? '' ) );
			$name = '';

			if ( '' !== $base ) {
				$name = $kept[ $base ] ?? self::find_picture( $dir, $base );

				if ( '' === $name && ! isset( $kept[ $base ] ) && time() < $deadline ) {
					$name = self::copy_picture( (string) $item['picture'], $dir, $base );
				}

				$kept[ $base ] = $name;
			}

			$items[ $i ]['picture_file'] = $name;
		}

		if ( $prune ) {
			foreach ( (array) glob( $dir . '/*' ) as $path ) {
				$file = basename( (string) $path );

				if ( preg_match( self::PIC_NAME, $file ) && ! in_array( $file, $kept, true ) ) {
					wp_delete_file( (string) $path );
				}
			}
		}

		return $items;
	}

	/** The copy of a picture already in the folder, or ''. */
	private static function find_picture( string $dir, string $base ): string {
		foreach ( array_keys( self::PIC_TYPES ) as $ext ) {
			if ( file_exists( $dir . '/' . $base . '.' . $ext ) ) {
				return $base . '.' . $ext;
			}
		}

		return '';
	}

	/**
	 * Fetch one picture, check it really is one of the image types we keep
	 * (by its content, not its name: a preview picture's address often has
	 * no extension), and keep it no wider than PIC_WIDTH. Returns the file's
	 * name, or '' when it is not kept.
	 */
	private static function copy_picture( string $url, string $dir, string $base ): string {
		$body = self::get( $url, true );

		if ( ! is_string( $body ) || '' === $body ) {
			return '';
		}

		$info = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a non-image is an expected answer, not a warning.
		$ext  = is_array( $info ) ? array_search( (string) ( $info['mime'] ?? '' ), self::PIC_TYPES, true ) : false;

		if ( false === $ext ) {
			return '';
		}

		$path = $dir . '/' . $base . '.' . $ext;

		if ( false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own uploads folder, written by cron.
			return '';
		}

		$editor = wp_get_image_editor( $path );

		if ( ! is_wp_error( $editor ) && ! is_wp_error( $editor->resize( self::PIC_WIDTH, null, false ) ) ) {
			$editor->save( $path );
		}

		return $base . '.' . $ext;
	}

	/**
	 * What a picture is kept under: a hash of its address, or '' when it is
	 * not one we fetch. Padlet signs its uploads with a token that changes,
	 * so for Padlet the token is left out: a new token tomorrow is the same
	 * picture, not a new copy. Pure.
	 */
	public static function pic_base( string $url ): string {
		if ( '' === self::safe_url( $url ) ) {
			return '';
		}

		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

		return md5( str_ends_with( $host, 'padletusercontent.com' ) ? (string) strtok( $url, '?#' ) : $url );
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
	 * @return array{at:int, ok:bool, count:int, left_out:int, error:string, source:string}|null
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
			'source'   => (string) ( $last['source'] ?? '' ),
		];
	}

	/**
	 * The board's columns as last read, in its order.
	 *
	 * @return array<int, array{id:string, name:string, intro:string}>
	 */
	public static function sections(): array {
		$store = get_option( self::OPTION, [] );
		$list  = is_array( $store ) && is_array( $store['sections'] ?? null ) ? $store['sections'] : [];

		return array_values(
			array_filter(
				array_map(
					static fn( $s ): ?array => is_array( $s ) && is_string( $s['id'] ?? null ) ? [ 'id' => $s['id'], 'name' => (string) ( $s['name'] ?? '' ), 'intro' => (string) ( $s['intro'] ?? '' ) ] : null,
					$list
				)
			)
		);
	}

	/** Where the stored board came from: the board's own data or the feeds. */
	public static function source(): string {
		$store = get_option( self::OPTION, [] );

		return is_array( $store ) ? (string) ( $store['source'] ?? '' ) : '';
	}

	/* ---- The team's changes to the columns ---------------------------------- */

	/**
	 * What the team changed under Settings, Wellbeing board: a column's
	 * heading, a column's introduction, a post moved to another column.
	 *
	 * @return array{names: array<string, string>, intros: array<string, string>, assign: array<string, string>}
	 */
	public static function overrides(): array {
		$saved = get_option( self::OPTION_GROUPS, [] );

		return self::clean_overrides( is_array( $saved ) ? $saved : [] );
	}

	/**
	 * Changes made safe to store and to draw: a heading or introduction is
	 * plain text for a column id; a post is moved only by its numeric id,
	 * and only to a column id or to More support. Whether the column still
	 * exists is decided when the board is drawn, so a change is not lost
	 * because a night's read was short. Pure.
	 *
	 * @param array<string, mixed> $raw
	 * @return array{names: array<string, string>, intros: array<string, string>, assign: array<string, string>}
	 */
	public static function clean_overrides( array $raw ): array {
		$column = static fn( $id ): bool => is_string( $id ) && 1 === preg_match( '/^s\d{1,20}$/', $id );
		$out    = [ 'names' => [], 'intros' => [], 'assign' => [] ];

		foreach ( [ 'names' => 80, 'intros' => 300 ] as $field => $max ) {
			foreach ( (array) ( $raw[ $field ] ?? [] ) as $id => $value ) {
				$value = self::text( (string) $value, $max );

				if ( $column( (string) $id ) && '' !== $value ) {
					$out[ $field ][ (string) $id ] = $value;
				}
			}
		}

		foreach ( (array) ( $raw['assign'] ?? [] ) as $key => $group ) {
			if ( preg_match( '/^\d{1,20}$/', (string) $key ) && ( $column( $group ) || self::DEFAULT_GROUP === $group ) ) {
				$out['assign'][ (string) $key ] = (string) $group;
			}
		}

		return $out;
	}

	/**
	 * The posts in their columns, in the board's order of columns and, in
	 * each, the board's own order (newest at the top, as on Padlet), with
	 * the team's changes applied and the posts with no column in More
	 * support at the end. A column with no posts is left out. Pure.
	 *
	 * @param array<int, array<string, mixed>>                                                                         $items
	 * @param array<int, array{id:string, name:string, intro:string}>                                                   $sections
	 * @param array{names: array<string, string>, intros: array<string, string>, assign: array<string, string>} $overrides
	 * @return array<int, array{id:string, name:string, intro:string, items: array<int, array<string, mixed>>}>
	 */
	public static function grouped( array $items, array $sections, array $overrides = [ 'names' => [], 'intros' => [], 'assign' => [] ] ): array {
		$groups = [];

		foreach ( $sections as $section ) {
			$id = (string) $section['id'];

			$groups[ $id ] = [
				'id'    => $id,
				'name'  => $overrides['names'][ $id ] ?? ( '' !== $section['name'] ? $section['name'] : __( 'More support', 'dgl-platform' ) ),
				'intro' => $overrides['intros'][ $id ] ?? $section['intro'],
				'items' => [],
			];
		}

		$groups[ self::DEFAULT_GROUP ] = [
			'id'    => self::DEFAULT_GROUP,
			'name'  => __( 'More support', 'dgl-platform' ),
			'intro' => '',
			'items' => [],
		];

		foreach ( $items as $item ) {
			$group = $overrides['assign'][ (string) ( $item['key'] ?? '' ) ] ?? (string) ( $item['section'] ?? '' );

			$groups[ isset( $groups[ $group ] ) ? $group : self::DEFAULT_GROUP ]['items'][] = $item;
		}

		foreach ( $groups as $id => $group ) {
			usort(
				$group['items'],
				static fn( array $a, array $b ): int => [ (int) ( $b['order'] ?? 0 ), (string) ( $a['title'] ?? '' ) ] <=> [ (int) ( $a['order'] ?? 0 ), (string) ( $b['title'] ?? '' ) ]
			);

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
	 * few days old before it is amber, and it is amber too while it is read
	 * from the feeds because the board's own data did not answer. Pure.
	 *
	 * @param array{at:int, ok:bool, count:int, left_out:int, error:string, source:string}|null $last
	 */
	public static function verdict( ?array $last, int $fetched, int $now ): string {
		if ( $fetched <= 0 || $now - $fetched > self::STALE_AFTER ) {
			return null === $last ? self::WARN : self::BAD;
		}

		if ( null !== $last && ( ! $last['ok'] || self::FROM_FEEDS === ( $last['source'] ?? '' ) ) ) {
			return self::WARN;
		}

		return $now - $fetched > 3 * DAY_IN_SECONDS ? self::WARN : self::GOOD;
	}
}

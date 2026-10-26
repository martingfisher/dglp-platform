<?php
/**
 * Bring the files content takes from other sites into this site's library.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Tools;

use DGL\Audit\Log;
use DGL\Org\Org;
use DGL\PostTypes;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The stories, pages and blocks here came from older sites (doinggoodleeds.org.uk,
 * Forum Central), and the pictures and documents in their words still load
 * from those sites. Those sites are being switched off. This copies each such
 * file into the media library once, remembers where it came from, and points
 * every reference at the copy.
 *
 * What is copied: every file the page shows (embed), from any site, and every
 * file the page links to on one of the old sites. A link to a document on
 * somebody else's site (an NHS report, say) is an ordinary link and is only
 * reported.
 */
final class MediaLocaliser {

	/** Old address (full size) to attachment id, for every file copied. */
	public const OPTION_MAP = 'dgl_media_map';

	/** On a copied attachment: the address it was copied from. */
	public const META_SOURCE = 'dgl_media_source';

	/** The sites this content moved from. Links to files on them are copied too. */
	public const OLD_HOSTS = [
		'doinggoodleeds.org.uk',
		'www.doinggoodleeds.org.uk',
		'cdn-doinggood.b-cdn.net',
		'dgl.wordifysites.com',
		'forumcentral.org.uk',
		'www.forumcentral.org.uk',
		'forumcentral.wordifysites.com',
	];

	/** A dead old host, and the live one that holds the same files at the same paths. */
	public const MIRRORS = [
		'forumcentral.wordifysites.com' => 'forumcentral.org.uk',
	];

	/**
	 * The post types whose words are walked: everything with content except
	 * revisions, the library itself, and the font and global style settings,
	 * whose only outside addresses are the font picker's preview images on
	 * WordPress.org: never shown to visitors, and stored as settings, not words.
	 */
	public static function types(): array {
		return array_values( array_diff( get_post_types( [], 'names' ), [ 'revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'wp_global_styles' ] ) );
	}

	/** Hosts that are this site: the home address, the uploads address and its CDN. */
	public static function own_hosts(): array {
		$hosts = [
			strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			strtolower( (string) wp_parse_url( (string) ( wp_get_upload_dir()['baseurl'] ?? '' ), PHP_URL_HOST ) ),
			'cdn-partnership-doinggoodleeds-org-uk-ysfb.b-cdn.net',
		];

		/** Filters the hosts treated as this site. */
		return array_values( array_unique( array_filter( (array) apply_filters( 'dgl_media_own_hosts', $hosts ) ) ) );
	}

	/** Whether a reference should be copied here, or only reported. */
	public static function wanted( array $row ): bool {
		return 'embed' === $row['kind'] || in_array( $row['host'], self::OLD_HOSTS, true );
	}

	/**
	 * Every post with references, for the audit and the copy.
	 *
	 * @return \Generator<int, array{post: WP_Post, refs: array<int, array{url:string, host:string, kind:string}>}>
	 */
	public static function walk( int $only = 0 ): \Generator {
		$own      = self::own_hosts();
		$statuses = array_values( array_diff( array_keys( get_post_stati() ), [ 'trash', 'auto-draft', 'inherit' ] ) );
		$page     = 1;

		do {
			$batch = get_posts(
				[
					'post_type'        => self::types(),
					'post_status'      => $statuses,
					'posts_per_page'   => 200,
					'paged'            => $page,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'include'          => $only > 0 ? [ $only ] : [],
					'suppress_filters' => true,
				]
			);

			foreach ( $batch as $post ) {
				$refs = ExternalMedia::find( (string) $post->post_content, $own );

				if ( [] !== $refs ) {
					yield [ 'post' => $post, 'refs' => $refs ];
				}
			}

			++$page;
		} while ( 200 === count( $batch ) && 0 === $only );
	}

	/**
	 * The attachment for a file at an address, copying it in if need be.
	 * Already copied: the same id. Already in this site's uploads at the same
	 * path (the site began as a copy of the old one): that file. Otherwise
	 * downloaded, full size first, then the size asked for, then a mirror.
	 *
	 * @return array{id: int, url: string, how: string}|WP_Error
	 */
	public static function attachment_for( string $url, int $parent, bool $apply, array $hint = [] ): array|WP_Error {
		$address = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$full    = ExternalMedia::full_size( $address );
		$map     = (array) get_option( self::OPTION_MAP, [] );
		$known   = (int) ( $map[ $full ] ?? 0 );

		if ( $known > 0 && 'attachment' === get_post_type( $known ) ) {
			self::fill_alt( $known, $hint, $apply );

			return [ 'id' => $known, 'url' => (string) wp_get_attachment_url( $known ), 'how' => 'copied before' ];
		}

		$local = self::already_here( $full );

		if ( null !== $local ) {
			if ( $local['id'] > 0 ) {
				self::fill_alt( $local['id'], $hint, $apply );
			}

			return $local;
		}

		$tries = array_values( array_unique( array_filter( [ $full, $address, self::mirror( $full ), self::mirror( $address ) ] ) ) );

		if ( ! $apply ) {
			return [ 'id' => 0, 'url' => '', 'how' => 'would copy' ];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$last = new WP_Error( 'dgl_media_none', 'Nothing to fetch.' );
		$alt  = self::alt_from( $full, $hint );
		// Given at creation, so the alt-text service on upload has nothing to
		// spend a credit on when the old site already described the picture.
		$data = '' !== $alt ? [ 'meta_input' => [ '_wp_attachment_image_alt' => $alt ] ] : [];

		foreach ( $tries as $try ) {
			$tmp = download_url( $try, 30 );

			if ( is_wp_error( $tmp ) ) {
				$last = $tmp;
				continue;
			}

			$name = rawurldecode( wp_basename( (string) wp_parse_url( $try, PHP_URL_PATH ) ) );
			$id   = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $parent, null, $data );

			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}

			if ( is_wp_error( $id ) ) {
				$last = $id;
				continue;
			}

			update_post_meta( (int) $id, self::META_SOURCE, $full );
			$map          = (array) get_option( self::OPTION_MAP, [] );
			$map[ $full ] = (int) $id;
			update_option( self::OPTION_MAP, $map, false );

			return [ 'id' => (int) $id, 'url' => (string) wp_get_attachment_url( (int) $id ), 'how' => $try === $full ? 'copied' : 'copied from ' . $try ];
		}

		return $last;
	}

	/**
	 * Alt text for a file: what the tag said, else what the old site's media
	 * library holds for the attachment the tag named, else ''.
	 *
	 * @param array{alt?: ?string, id?: int} $hint
	 */
	public static function alt_from( string $full, array $hint ): string {
		$alt = trim( (string) ( $hint['alt'] ?? '' ) );

		if ( '' !== $alt ) {
			return $alt;
		}

		$old_id = (int) ( $hint['id'] ?? 0 );
		$host   = strtolower( (string) wp_parse_url( $full, PHP_URL_HOST ) );

		if ( $old_id <= 0 || ! in_array( $host, self::OLD_HOSTS, true ) ) {
			return '';
		}

		$origin   = self::MIRRORS[ $host ] ?? ( 'cdn-doinggood.b-cdn.net' === $host ? 'doinggoodleeds.org.uk' : $host );
		$response = wp_safe_remote_get( 'https://' . $origin . '/wp-json/wp/v2/media/' . $old_id . '?_fields=alt_text,source_url', [ 'timeout' => 15 ] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$media = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $media ) ) {
			return '';
		}

		// The id is the old site's; only trust it when it is the same file.
		$same = wp_basename( ExternalMedia::full_size( (string) ( $media['source_url'] ?? '' ) ) ) === wp_basename( $full );

		return $same ? trim( wp_strip_all_tags( (string) ( $media['alt_text'] ?? '' ) ) ) : '';
	}

	/** Give an attachment with no alt text the one known for it. Never overwrites. */
	private static function fill_alt( int $id, array $hint, bool $apply ): void {
		if ( ! $apply || '' !== trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
			return;
		}

		$alt = trim( (string) ( $hint['alt'] ?? '' ) );

		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
	}

	/** The same file at the same path in this site's own uploads, if it is there. */
	private static function already_here( string $full ): ?array {
		$path = (string) wp_parse_url( $full, PHP_URL_PATH );
		$at   = strpos( $path, '/wp-content/uploads/' );

		if ( false === $at ) {
			return null;
		}

		$rel  = rawurldecode( substr( $path, $at + strlen( '/wp-content/uploads/' ) ) );
		$dirs = wp_get_upload_dir();

		if ( '' === $rel || ! file_exists( trailingslashit( (string) $dirs['basedir'] ) . $rel ) ) {
			return null;
		}

		$url = trailingslashit( (string) $dirs['baseurl'] ) . $rel;
		$id  = attachment_url_to_postid( $url );

		return [ 'id' => (int) $id, 'url' => $id > 0 ? (string) wp_get_attachment_url( $id ) : $url, 'how' => 'already here' ];
	}

	/** The same path on the live host that took over from a dead one, or ''. */
	private static function mirror( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return isset( self::MIRRORS[ $host ] ) ? (string) preg_replace( '~//' . preg_quote( $host, '~' ) . '/~i', '//' . self::MIRRORS[ $host ] . '/', $url, 1 ) : '';
	}

	/**
	 * Copy one post's files and point its words at the copies. With $feature,
	 * a news item with no picture of its own takes the first picture it shows.
	 *
	 * @return array{changed: bool, files: array<int, array{url:string, host:string, kind:string, how:string, to:string}>, featured: int, error: string}
	 */
	public static function localise_post( WP_Post $post, array $refs, bool $apply, bool $feature = false, int $actor = 0 ): array {
		$map   = [];
		$ids   = [];
		$alts  = [];
		$files = [];
		$hints = [];

		foreach ( ExternalMedia::images( (string) $post->post_content ) as $img ) {
			$key = ExternalMedia::full_size( html_entity_decode( $img['src'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

			if ( ! isset( $hints[ $key ] ) || ( '' === (string) $hints[ $key ]['alt'] && '' !== (string) $img['alt'] ) ) {
				$hints[ $key ] = [ 'alt' => (string) $img['alt'], 'id' => $img['id'] ];
			}
		}

		foreach ( $refs as $row ) {
			if ( ! self::wanted( $row ) ) {
				$files[] = $row + [ 'how' => 'left: a link to another site', 'to' => '' ];
				continue;
			}

			$key = ExternalMedia::full_size( html_entity_decode( $row['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$got = self::attachment_for( $row['url'], (int) $post->ID, $apply, $hints[ $key ] ?? [] );

			if ( is_wp_error( $got ) ) {
				$files[] = $row + [ 'how' => 'FAILED: ' . $got->get_error_message(), 'to' => '' ];
				continue;
			}

			$files[] = $row + [ 'how' => $got['how'], 'to' => $got['url'] ];

			if ( '' !== $got['url'] ) {
				$map[ $row['url'] ] = $got['url'];

				if ( $got['id'] > 0 ) {
					$ids[ $got['url'] ]  = $got['id'];
					$alts[ $got['url'] ] = (string) get_post_meta( $got['id'], '_wp_attachment_image_alt', true );
				}
			}
		}

		$out = [ 'changed' => false, 'files' => $files, 'featured' => 0, 'error' => '', 'no_alt' => count( array_filter( $ids, static fn( int $id ): bool => '' === trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) && wp_attachment_is_image( $id ) ) ) ];

		if ( $feature && PostTypes::NEWS === $post->post_type && (int) get_post_meta( $post->ID, 'dgl_image', true ) <= 0 ) {
			$first = ExternalMedia::first_picture( (string) $post->post_content, self::own_hosts() );

			if ( '' !== $first && isset( $map[ $first ], $ids[ $map[ $first ] ] ) ) {
				$out['featured'] = $ids[ $map[ $first ] ];
			} elseif ( '' !== $first && ! $apply ) {
				$out['featured'] = -1;
			}
		}

		if ( ! $apply || [] === $map ) {
			return $out;
		}

		$html = ExternalMedia::retag( ExternalMedia::rewrite( (string) $post->post_content, $map ), $ids, $alts );

		if ( $html !== (string) $post->post_content ) {
			// WP-CLI runs without a user, so the content filters would strip
			// the markup blocks need (inline SVG, styles, iframes).
			kses_remove_filters();
			$saved = wp_update_post( [ 'ID' => (int) $post->ID, 'post_content' => wp_slash( $html ) ], true );
			kses_init_filters();

			if ( is_wp_error( $saved ) ) {
				$out['error'] = $saved->get_error_message();

				return $out;
			}

			clean_post_cache( (int) $post->ID );

			if ( (string) get_post_field( 'post_content', (int) $post->ID, 'raw' ) !== $html ) {
				$out['error'] = 'Saved, but the stored words differ from what was sent.';

				return $out;
			}

			$out['changed'] = true;
		}

		if ( $out['featured'] > 0 ) {
			set_post_thumbnail( (int) $post->ID, $out['featured'] );
			update_post_meta( (int) $post->ID, 'dgl_image', $out['featured'] );
		}

		$changes = [];

		foreach ( array_values( $map ) as $i => $to ) {
			$changes[ 'file_' . $i ] = [ (string) array_keys( $map )[ $i ], $to ];
		}

		$submittable = PostTypes::is_submittable( (string) $post->post_type );

		Log::record(
			'media_localised',
			$submittable ? 'item' : 'page',
			(int) $post->ID,
			$submittable ? Org::for_item( (int) $post->ID ) : 0,
			sprintf( '%d file(s) from other sites copied here.%s', count( $map ), $out['featured'] > 0 ? ' Picture set from the words.' : '' ),
			$changes,
			$actor
		);

		return $out;
	}
	/**
	 * Fill the empty alt text on the library's own pictures in one post's
	 * words, from the attachment's alt text. Never overwrites a written one.
	 *
	 * @return array{filled: int, missing: int[], error: string}
	 */
	public static function fill_alts( WP_Post $post, bool $apply, int $actor = 0 ): array {
		$alts    = [];
		$missing = [];

		foreach ( ExternalMedia::images( (string) $post->post_content ) as $img ) {
			if ( null !== $img['alt'] && '' !== $img['alt'] ) {
				continue;
			}

			$id = $img['id'] > 0 && 'attachment' === get_post_type( $img['id'] ) ? $img['id'] : attachment_url_to_postid( html_entity_decode( $img['src'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

			if ( $id <= 0 ) {
				continue;
			}

			$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );

			if ( '' === $alt ) {
				$missing[] = $id;
				continue;
			}

			$alts[ $img['src'] ] = $alt;
		}

		$out = [ 'filled' => count( $alts ), 'missing' => array_values( array_unique( $missing ) ), 'error' => '' ];

		if ( ! $apply || [] === $alts ) {
			return $out;
		}

		$html = ExternalMedia::retag( (string) $post->post_content, [], $alts );

		if ( $html === (string) $post->post_content ) {
			$out['filled'] = 0;

			return $out;
		}

		kses_remove_filters();
		$saved = wp_update_post( [ 'ID' => (int) $post->ID, 'post_content' => wp_slash( $html ) ], true );
		kses_init_filters();

		if ( is_wp_error( $saved ) ) {
			$out['error'] = $saved->get_error_message();

			return $out;
		}

		clean_post_cache( (int) $post->ID );

		$submittable = PostTypes::is_submittable( (string) $post->post_type );

		Log::record( 'media_alt_filled', $submittable ? 'item' : 'page', (int) $post->ID, $submittable ? Org::for_item( (int) $post->ID ) : 0, sprintf( '%d picture(s) given their alt text.', count( $alts ) ), [], $actor );

		return $out;
	}

	/**
	 * Every post whose words hold an img: for the alt-text pass.
	 *
	 * @return \Generator<int, WP_Post>
	 */
	public static function with_images(): \Generator {
		$statuses = array_values( array_diff( array_keys( get_post_stati() ), [ 'trash', 'auto-draft', 'inherit' ] ) );
		$page     = 1;

		do {
			$batch = get_posts( [ 'post_type' => self::types(), 'post_status' => $statuses, 'posts_per_page' => 200, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ] );

			foreach ( $batch as $post ) {
				if ( false !== stripos( (string) $post->post_content, '<img' ) ) {
					yield $post;
				}
			}

			++$page;
		} while ( 200 === count( $batch ) );
	}
}

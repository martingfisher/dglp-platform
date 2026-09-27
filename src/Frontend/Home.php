<?php
/**
 * The sample home page: what the partnership does, and what is new.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use DGL\Meta;
use DGL\Org\Directory;
use DGL\PostTypes;
use DGL\Statuses;
use DGL\Workflow\Pins;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Built from the agreed wireframe and served at /samplehome/ so the
 * partnership can see the real content in it before it replaces the site's
 * front page. Every list on it reads live data: the next events, the newest
 * stories, the next courses, the directory. The two blocks the plugin has
 * no data for yet, funding and testimonials, are drawn as labelled
 * placeholders rather than invented.
 *
 * Copy that a team member will want to change is filterable, and the hero
 * photo and the testimonials are options, so none of it needs a release.
 */
final class Home {

	/** The path under the site root. */
	public const BASE = 'samplehome';

	/** The query var the rewrite rule sets. */
	public const QUERY_VAR = 'dgl_home';

	/** Option: attachment ID of the hero photo. */
	public const OPTION_HERO = 'dgl_home_hero';

	/** Option: the testimonials, a list of quote, name, role rows. */
	public const OPTION_QUOTES = 'dgl_home_testimonials';

	/** How many of each thing the page shows. */
	public const EVENTS   = 6;
	public const NEWS     = 5;
	public const TRAINING = 3;
	public const GRANTS   = 3;
	public const TOPICS   = 6;

	public static function add_rules(): void {
		add_rewrite_rule( '^' . self::BASE . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public static function is_request(): bool {
		return '1' === (string) get_query_var( self::QUERY_VAR, '' );
	}

	public static function url(): string {
		return home_url( '/' . self::BASE . '/' );
	}

	/**
	 * What the sample page's own blocks print: the hero, the partners, the
	 * testimonials and the links. The live sections come from HomeBlocks.
	 *
	 * @return array<string, mixed>
	 */
	public static function view_data(): array {
		return [
			'hero_image'  => self::hero_image(),
			'partners'    => self::partners(),
			'grants_on'   => PostTypes::is_enabled( PostTypes::GRANT ),
			'quotes'      => self::testimonials(),
			'join_url'    => \DGL\Dashboard\Router::url( 'join' ),
			'dir_url'     => home_url( '/' . Directory::BASE . '/' ),
			'urls'        => [
				PostTypes::EVENT    => Frontend::archive_url( PostTypes::EVENT ),
				PostTypes::NEWS     => Frontend::archive_url( PostTypes::NEWS ),
				PostTypes::TRAINING => Frontend::archive_url( PostTypes::TRAINING ),
				PostTypes::GRANT    => Frontend::archive_url( PostTypes::GRANT ),
			],
		];
	}

	/**
	 * The hero photo, as an <img>, or '' when none is set. Set with
	 * `wp option update dgl_home_hero <attachment id>`.
	 */
	public static function hero_image(): string {
		$id = (int) get_option( self::OPTION_HERO, 0 );

		if ( $id <= 0 ) {
			return '';
		}

		return (string) wp_get_attachment_image( $id, 'large', false, [ 'loading' => 'eager', 'fetchpriority' => 'high', 'class' => 'dgl-home__heroimg' ] );
	}

	/**
	 * The organisations that run the partnership: name and link.
	 *
	 * @return array<int, array{name: string, url: string}>
	 */
	public static function partners(): array {
		$default = [
			[ 'name' => 'Forum Central', 'url' => 'https://forumcentral.org.uk/' ],
			[ 'name' => 'Voluntary Action Leeds', 'url' => 'https://doinggoodleeds.org.uk/' ],
			[ 'name' => 'Leeds Older People\'s Forum', 'url' => 'https://www.opforum.org.uk/' ],
		];

		/**
		 * The partner organisations named on the home page.
		 *
		 * @param array<int, array{name: string, url: string}> $partners
		 */
		return (array) apply_filters( 'dgl_home_partners', $default );
	}

	/**
	 * The testimonials: rows of quote, name, role. Empty until the partnership
	 * supplies them, so the template draws placeholders. Set with
	 * `wp option update dgl_home_testimonials --format=json '[{"quote":"…","name":"…","role":"…"}]'`.
	 *
	 * @return array<int, array{quote: string, name: string, role: string}>
	 */
	public static function testimonials(): array {
		$raw = get_option( self::OPTION_QUOTES, [] );

		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}

		$out = [];

		foreach ( is_array( $raw ) ? $raw : [] as $row ) {
			if ( ! is_array( $row ) || '' === trim( (string) ( $row['quote'] ?? '' ) ) ) {
				continue;
			}

			$out[] = [
				'quote' => trim( (string) $row['quote'] ),
				'name'  => trim( (string) ( $row['name'] ?? '' ) ),
				'role'  => trim( (string) ( $row['role'] ?? '' ) ),
			];

			if ( count( $out ) >= 3 ) {
				break;
			}
		}

		/**
		 * The testimonials on the home page.
		 *
		 * @param array<int, array{quote: string, name: string, role: string}> $quotes
		 */
		return (array) apply_filters( 'dgl_home_testimonials', $out );
	}

	/**
	 * The next few live items of a dated type, soonest first, featured ones
	 * first of all. Events sort by their next occurrence, so a weekly group
	 * sits where its next date belongs; anything else by its own date.
	 *
	 * @return WP_Post[]
	 */
	public static function upcoming( string $post_type, int $limit ): array {
		$sort_field = Frontend::sort_key( $post_type );
		$field      = null === $sort_field ? null : \DGL\Schema\FieldRegistry::find( $post_type, $sort_field );
		$date_key   = PostTypes::EVENT === $post_type ? Meta::ITEM_NEXT_AT : ( null === $field ? '' : $field->meta_key() );

		$args = [
			'post_type'        => $post_type,
			'post_status'      => Statuses::LIVE,
			'posts_per_page'   => $limit,
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'dgl_pin_first'    => true,
		];

		if ( '' !== $date_key ) {
			// Undated rows still appear, first: see Frontend::order_archive().
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a small, bounded list.
				'relation'    => 'OR',
				'dgl_when'    => [ 'key' => $date_key, 'compare' => 'EXISTS' ],
				'dgl_undated' => [ 'key' => $date_key, 'compare' => 'NOT EXISTS' ],
			];
			$args['orderby']    = [ 'dgl_when' => 'ASC', 'date' => 'DESC' ];
		} else {
			$args['orderby'] = [ 'date' => 'DESC' ];
		}

		return self::posts( $args );
	}

	/**
	 * The newest live items of an undated type, featured ones first.
	 *
	 * @return WP_Post[]
	 */
	public static function newest( string $post_type, int $limit ): array {
		return self::posts(
			[
				'post_type'        => $post_type,
				'post_status'      => Statuses::LIVE,
				'posts_per_page'   => $limit,
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'orderby'          => [ 'date' => 'DESC' ],
				'dgl_pin_first'    => true,
			]
		);
	}

	/**
	 * @param array<string, mixed> $args
	 * @return WP_Post[]
	 */
	private static function posts( array $args ): array {
		if ( ! PostTypes::is_enabled( (string) $args['post_type'] ) ) {
			return [];
		}

		$query = new WP_Query( $args );

		return array_values( array_filter( $query->posts, static fn( $p ): bool => $p instanceof WP_Post ) );
	}

	/** Whether an item is featured right now, for the template's badge. */
	public static function is_featured( WP_Post $post ): bool {
		return Pins::is_pinned( (int) $post->ID );
	}
}

<?php
/**
 * The topic feed: a row of news, events or training for one topic.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use DGL\Dashboard\View;
use DGL\PostTypes;
use DGL\Taxonomies;
use DGL\Topics\Topics;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * One block, two choices: what kind of content (news, events, training) and
 * which topic. Dropped on a network's page and set to that network's topic,
 * it shows that network's own news, events or training, four across, picture
 * on top, words underneath. The same block goes on any page, as many times
 * as the page wants:
 *
 *   [dgl_feed type="news" topic="children-and-young-people"]
 *   [dgl_feed type="event" topic="older-people" count="8" heading="Coming up"]
 *
 * The block editor offers the same two choices as dropdowns (see FeedBlock).
 *
 * It is plain server-rendered HTML, so a crawler reads every title, date and
 * link without running a script, and each block also prints its own
 * `ItemList` so the list is stated as data as well as shown as markup.
 *
 * When a topic has nothing yet, the block shows the newest of that type
 * instead, headed as the latest rather than as the topic, so nobody is told
 * an unrelated story belongs to the network.
 */
final class Feed {

	/** The shortcode tag. */
	public const TAG = 'dgl_feed';

	/** What the `type` choice means. Choice => post type. */
	public const TYPES = [
		'news'     => PostTypes::NEWS,
		'event'    => PostTypes::EVENT,
		'training' => PostTypes::TRAINING,
	];

	public const DEFAULT_TYPE  = 'news';
	public const DEFAULT_COUNT = 4;
	public const MAX_COUNT     = 12;

	/** Option holding the picture used when an item has none, per type. */
	public const IMAGE_OPTION = 'dgl_feed_image_%s';

	/**
	 * Which of the 28 topics each network's page uses, from DGLP's sheet of
	 * pages that take a feed. Page name => topic slug. A page that has no
	 * single clear topic is left out and decided by the team.
	 *
	 * @return array<string, string>
	 */
	public static function page_topics(): array {
		return [
			'Young Lives Leeds'                   => 'children-and-young-people',
			'Cultural Diverse Hub'                => 'cultural-diverse-communities',
			'Funding Support and Network'         => 'grants-and-funding',
			'Health and Care Leaders Network'     => 'health-and-social-care',
			'Learning Disability'                 => 'learning-disability',
			'Local Care Partnerships'             => 'local-place-based',
			'Mental Health'                       => 'mental-health',
			'Age Friendly Leeds'                  => 'older-people',
			'Physical and Sensory Impairments'    => 'physical-and-sensory-impairments',
			'Representatives'                     => 'reps',
			'Support parent page'                 => 'support-for-vcse-organisations',
			'Volunteering parent page'            => 'volunteering',
		];
	}

	/* ---- The attributes: pure ------------------------------------------- */

	/**
	 * Clean what the shortcode or the block gave us.
	 *
	 * Anything unknown falls back to a default rather than to an error: a
	 * typo in a shortcode should show a sensible row, not break the page.
	 *
	 * @param array<string, mixed> $atts
	 * @return array{type:string, topic:string, count:int, heading:?string, link:bool}
	 */
	public static function normalise( array $atts ): array {
		$atts = array_change_key_case( $atts, CASE_LOWER );

		$type = strtolower( trim( (string) ( $atts['type'] ?? '' ) ) );
		$type = rtrim( $type, 's' ) === 'event' ? 'event' : $type;
		$type = isset( self::TYPES[ $type ] ) ? $type : self::DEFAULT_TYPE;

		$topic = strtolower( trim( (string) ( $atts['topic'] ?? '' ) ) );
		$topic = 1 === preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $topic ) ? $topic : '';

		$count = isset( $atts['count'] ) && is_numeric( $atts['count'] ) ? (int) $atts['count'] : self::DEFAULT_COUNT;
		$count = max( 1, min( self::MAX_COUNT, $count ) );

		// null: use the type's own heading. '' : no heading at all.
		$heading = null;

		if ( array_key_exists( 'heading', $atts ) ) {
			$heading = trim( strip_tags( (string) $atts['heading'] ) );
		}

		$link = ! in_array( strtolower( trim( (string) ( $atts['link'] ?? 'yes' ) ) ), [ 'no', '0', 'false', 'off' ], true );

		return [ 'type' => $type, 'topic' => $topic, 'count' => $count, 'heading' => $heading, 'link' => $link ];
	}

	/**
	 * The `ItemList` for a block, as schema.org data. Pure.
	 *
	 * @param string                                  $id    The block's own id on the page.
	 * @param string                                  $name  What the list is.
	 * @param string                                  $page  The page's URL.
	 * @param array<int, array{0:string, 1:string}>   $items [url, name] pairs.
	 * @return array<string, mixed>
	 */
	public static function item_list( string $id, string $name, string $page, array $items ): array {
		$list = [];

		foreach ( array_values( $items ) as $i => [ $url, $title ] ) {
			$list[] = [ '@type' => 'ListItem', 'position' => $i + 1, 'url' => $url, 'name' => $title ];
		}

		return [
			'@type'           => 'ItemList',
			'@id'             => $page . '#' . $id,
			'name'            => $name,
			'itemListElement' => $list,
		];
	}

	/* ---- Choices -------------------------------------------------------- */

	/**
	 * The topics to offer in the editor: every term the site has, so one a
	 * moderator added by hand is there too. Slug => name.
	 *
	 * @return array<string, string>
	 */
	public static function topic_choices(): array {
		$terms = get_terms( [ 'taxonomy' => Taxonomies::TOPIC, 'hide_empty' => false, 'orderby' => 'name' ] );

		if ( ! is_array( $terms ) || [] === $terms ) {
			return Topics::all();
		}

		$out = [];

		foreach ( $terms as $term ) {
			$out[ (string) $term->slug ] = html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' );
		}

		return $out;
	}

	/** @return array<string, string> Choice => label, for the editor. */
	public static function type_choices(): array {
		return [
			'news'     => __( 'News', 'dgl-platform' ),
			'event'    => __( 'Events', 'dgl-platform' ),
			'training' => __( 'Training', 'dgl-platform' ),
		];
	}

	/* ---- Rendering ------------------------------------------------------ */

	/**
	 * The block's markup, or '' when that type is switched off.
	 *
	 * @param array<string, mixed> $atts
	 */
	public static function render( array $atts ): string {
		$a         = self::normalise( $atts );
		$post_type = self::TYPES[ $a['type'] ];

		if ( ! PostTypes::is_enabled( $post_type ) ) {
			return '';
		}

		$items      = self::items( $post_type, $a['topic'], $a['count'] );
		$backfilled = false;

		// Nothing under the topic yet: the newest of the type, said as such.
		if ( [] === $items && '' !== $a['topic'] ) {
			$items      = self::items( $post_type, '', $a['count'] );
			$backfilled = true;
		}

		$topic_name = '' !== $a['topic'] && ! $backfilled ? self::topic_name( $a['topic'] ) : '';
		$heading    = $a['heading'] ?? self::default_heading( $a['type'] );
		$archive    = Frontend::archive_url( $post_type );
		$all_url    = '' !== $archive && $a['link'] ? ( '' !== $a['topic'] && ! $backfilled ? add_query_arg( Filters::PARAM_TOPIC, $a['topic'], $archive ) : $archive ) : '';

		// Every block on a page needs an id of its own: a page carries several.
		$id = wp_unique_id( 'dgl-feed-' );

		return View::render(
			'public/home/feed',
			[
				'items'     => $items,
				'type'      => $a['type'],
				'heading'   => $heading,
				'all_url'   => $all_url,
				'all_label' => self::all_label( $a['type'], $topic_name ),
				'id'        => $id,
				'schema'    => self::schema_for( $id, '' !== $heading ? $heading : self::default_heading( $a['type'] ), $items ),
			]
		);
	}

	/**
	 * The items: the topic's, or any topic's when none is given. Events and
	 * training come soonest first, news newest first, featured ones leading.
	 *
	 * @return WP_Post[]
	 */
	public static function items( string $post_type, string $topic, int $limit ): array {
		$slug = '' === $topic ? null : $topic;

		return PostTypes::NEWS === $post_type
			? Home::newest( $post_type, $limit, $slug )
			: Home::upcoming( $post_type, $limit, $slug );
	}

	/** The picture: the item's own, else the type's chosen stand-in, else the plain tile. */
	public static function picture( WP_Post $post, string $type ): string {
		if ( Cards::has_picture( $post ) ) {
			return Cards::picture( $post, 'medium_large' );
		}

		$stand_in = (int) get_option( sprintf( self::IMAGE_OPTION, $type ), 0 );

		if ( $stand_in > 0 ) {
			$img = wp_get_attachment_image( $stand_in, 'medium_large', false, [ 'loading' => 'lazy', 'alt' => '', 'class' => 'dgl-card__img dgl-card__img--stand-in' ] );

			if ( '' !== $img ) {
				return $img;
			}
		}

		return Cards::picture( $post, 'medium_large' );
	}

	/**
	 * The date line and its machine-readable form. Events and training say
	 * when they happen; news says when it was posted.
	 *
	 * @return array{text:string, iso:string}
	 */
	public static function date_line( WP_Post $post ): array {
		if ( PostTypes::NEWS !== (string) $post->post_type ) {
			$parts = Cards::date_parts( $post );

			if ( null !== $parts ) {
				return [ 'text' => Cards::when( $post ), 'iso' => $parts['iso'] ];
			}
		}

		return [ 'text' => Cards::posted( $post ), 'iso' => (string) get_the_date( 'Y-m-d', $post ) ];
	}

	private static function topic_name( string $slug ): string {
		$term = get_term_by( 'slug', $slug, Taxonomies::TOPIC );

		return $term instanceof \WP_Term ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : '';
	}

	private static function default_heading( string $type ): string {
		return match ( $type ) {
			'event'    => __( "What's on", 'dgl-platform' ),
			'training' => __( 'Training and learning', 'dgl-platform' ),
			default    => __( 'Latest news', 'dgl-platform' ),
		};
	}

	private static function all_label( string $type, string $topic_name ): string {
		$what = match ( $type ) {
			'event'    => __( 'events', 'dgl-platform' ),
			'training' => __( 'training', 'dgl-platform' ),
			default    => __( 'news', 'dgl-platform' ),
		};

		return '' === $topic_name
			/* translators: %s: "news", "events" or "training". */
			? sprintf( __( 'All %s', 'dgl-platform' ), $what )
			/* translators: 1: "news", "events" or "training", 2: a topic name. */
			: sprintf( __( 'All %1$s on %2$s', 'dgl-platform' ), $what, $topic_name );
	}

	/**
	 * @param WP_Post[] $items
	 */
	private static function schema_for( string $id, string $name, array $items ): string {
		if ( [] === $items ) {
			return '';
		}

		$pairs = array_map(
			static fn( WP_Post $p ): array => [ (string) get_permalink( $p ), html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ) ],
			$items
		);

		$page = (string) ( get_permalink( (int) get_queried_object_id() ) ?: home_url( '/' ) );

		return Seo::schema_tag( [ 'list' => self::item_list( $id, $name, $page, $pairs ) ] );
	}
}

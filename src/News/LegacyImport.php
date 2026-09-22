<?php
/**
 * Bring the old site's posts into the platform as news items.
 *
 * The old site kept its stories as ordinary WordPress posts, filed under core
 * categories, with a featured image and an author. The platform keeps news as
 * `dgl_news` items that belong to an organisation, carry topics, and are
 * indexed for the lists and the review queue. This class turns one into the
 * other in place: the post keeps its id, its address slug, its picture, its
 * dates and its words; it gains an organisation, topics and the item meta the
 * lists read. Nothing is copied, so nothing can be copied twice.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\News;

use DGL\Audit\Log;
use DGL\Events\Series;
use DGL\Index\Sync;
use DGL\Meta;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Statuses;
use DGL\Taxonomies;
use DGL\Topics\Topics;
use DGL\Workflow\StateMachine;
use DGL\Workflow\Transition;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class LegacyImport {

	/** Set to '1' on an item that was a post on the old site. */
	public const META_FROM = 'dgl_legacy_post';

	/** The old categories, as slugs, comma separated: the record of what was mapped. */
	public const META_CATEGORIES = 'dgl_legacy_categories';

	/** The address the story had on the old site. */
	public const META_URL = 'dgl_legacy_url';

	/** Summary length the schema allows. */
	private const SUMMARY_MAX = 300;

	/**
	 * Published posts of the old kind, oldest first, so ids and dates keep
	 * their order in the audit trail.
	 *
	 * @return int[]
	 */
	public static function candidates( int $limit = 0 ): array {
		$ids = get_posts(
			[
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'orderby'          => 'date',
				'order'            => 'ASC',
				'numberposts'      => $limit > 0 ? $limit : -1,
				'suppress_filters' => true,
			]
		);

		return array_map( 'intval', (array) $ids );
	}

	/** Old category slugs on a post, in the order WordPress keeps them. */
	public static function categories( int $post_id ): array {
		$slugs = wp_get_object_terms( $post_id, 'category', [ 'fields' => 'slugs' ] );

		return is_array( $slugs ) ? array_values( array_map( 'strval', $slugs ) ) : [];
	}

	/**
	 * The topics a post gets: its categories through the recorded mapping.
	 * Categories on the retired list, and any not on either list, give
	 * nothing. Duplicates fold, so two old categories that both became
	 * Featured give one topic.
	 *
	 * @return string[] Topic slugs.
	 */
	public static function topics_for( array $categories ): array {
		$map = Topics::legacy();
		$out = [];

		foreach ( $categories as $slug ) {
			if ( isset( $map[ $slug ] ) && ! in_array( $map[ $slug ], $out, true ) ) {
				$out[] = $map[ $slug ];
			}
		}

		return $out;
	}

	/**
	 * Who owns the story: the first old category with an owner in the table,
	 * else the default. The table is category slug to organisation id.
	 */
	public static function owner_for( array $categories, int $default_org, array $owners ): int {
		foreach ( $categories as $slug ) {
			if ( isset( $owners[ $slug ] ) && (int) $owners[ $slug ] > 0 ) {
				return (int) $owners[ $slug ];
			}
		}

		return $default_org;
	}

	/**
	 * The listing summary: the excerpt the editor wrote, else the story's
	 * first words, within the length the schema allows.
	 */
	public static function summary_for( WP_Post $post ): string {
		$text = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );

		if ( '' === $text ) {
			$text = (string) wp_trim_words( trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) ), 40, '…' );
		}

		if ( mb_strlen( $text ) > self::SUMMARY_MAX ) {
			$text = rtrim( mb_substr( $text, 0, self::SUMMARY_MAX - 1 ) ) . '…';
		}

		return $text;
	}

	/**
	 * What converting a post would do, without doing it.
	 *
	 * @return array{id:int,title:string,categories:string[],topics:string[],org:int,summary:string,image:int,old_url:string}|WP_Error
	 */
	public static function plan( int $post_id, int $default_org, array $owners = [] ): array|WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return new WP_Error( 'dgl_not_legacy', 'Not a post of the old kind.' );
		}

		if ( 'publish' !== $post->post_status ) {
			return new WP_Error( 'dgl_not_published', 'Only published posts are brought over.' );
		}

		$categories = self::categories( $post_id );

		return [
			'id'         => $post_id,
			'title'      => (string) $post->post_title,
			'categories' => $categories,
			'topics'     => self::topics_for( $categories ),
			'org'        => self::owner_for( $categories, $default_org, $owners ),
			'summary'    => self::summary_for( $post ),
			'image'      => (int) get_post_thumbnail_id( $post_id ),
			'old_url'    => (string) get_permalink( $post_id ),
		];
	}

	/**
	 * Convert one post. In place: same id, same slug, same dates, same words.
	 *
	 * @return array|WP_Error The plan that was applied.
	 */
	public static function convert( int $post_id, int $default_org, array $owners = [], int $actor_id = 0 ): array|WP_Error {
		$plan = self::plan( $post_id, $default_org, $owners );

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( $plan['org'] <= 0 || ! Org::exists( $plan['org'] ) ) {
			return new WP_Error( 'dgl_no_org', 'The owning organisation does not exist.' );
		}

		$post = get_post( $post_id );

		// The words the lists and the item page read, under the schema's keys.
		update_post_meta( $post_id, 'dgl_summary', $plan['summary'] );

		if ( $plan['image'] > 0 ) {
			update_post_meta( $post_id, 'dgl_image', $plan['image'] );
		}

		update_post_meta( $post_id, Meta::ITEM_ORG, $plan['org'] );
		update_post_meta( $post_id, Meta::ITEM_SUBMITTED_AT, (string) $post->post_date_gmt );
		update_post_meta( $post_id, Meta::ITEM_APPROVED_AT, (string) $post->post_date_gmt );
		update_post_meta( $post_id, self::META_FROM, '1' );
		update_post_meta( $post_id, self::META_CATEGORIES, implode( ',', $plan['categories'] ) );
		update_post_meta( $post_id, self::META_URL, $plan['old_url'] );

		$term_ids = [];

		foreach ( $plan['topics'] as $slug ) {
			$term = get_term_by( 'slug', $slug, Taxonomies::TOPIC );

			if ( $term instanceof \WP_Term ) {
				$term_ids[] = (int) $term->term_id;
			}
		}

		$updated = wp_update_post(
			[
				'ID'        => $post_id,
				'post_type' => PostTypes::NEWS,
			],
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		wp_set_object_terms( $post_id, $term_ids, Taxonomies::TOPIC, false );

		// The old categories were the mapping's input; the record of them is in meta now.
		wp_delete_object_term_relationships( $post_id, 'category' );

		Series::stamp( $post_id, PostTypes::NEWS );
		Sync::sync( $post_id );

		Log::record(
			'imported',
			'item',
			$post_id,
			$plan['org'],
			sprintf(
				/* translators: %s: the old address. */
				__( 'Brought over from the old site, where it was %s.', 'dgl-platform' ),
				$plan['old_url']
			),
			[
				'categories' => $plan['categories'],
				'topics'     => $plan['topics'],
				'old_url'    => $plan['old_url'],
			],
			$actor_id > 0 ? $actor_id : null
		);

		return $plan;
	}

	/** Whether an item came over from the old site. */
	public static function is_legacy( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, self::META_FROM, true );
	}

	/** Whether a converted item is live. Used by the redirect. */
	public static function live_by_slug( string $slug ): ?int {
		$found = get_posts(
			[
				'post_type'        => PostTypes::NEWS,
				'post_status'      => Statuses::LIVE,
				'name'             => $slug,
				'fields'           => 'ids',
				'numberposts'      => 1,
				'meta_key'         => self::META_FROM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
			]
		);

		return [] === $found ? null : (int) $found[0];
	}

	/**
	 * Converted stories whose old categories included any of the given slugs.
	 *
	 * @param string[] $categories Old category slugs.
	 * @return int[]
	 */
	public static function converted_in( array $categories, string $status = Statuses::LIVE ): array {
		$ids = get_posts(
			[
				'post_type'        => PostTypes::NEWS,
				'post_status'      => $status,
				'fields'           => 'ids',
				'numberposts'      => -1,
				'orderby'          => 'date',
				'order'            => 'ASC',
				'meta_key'         => self::META_FROM, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
			]
		);

		$wanted = array_values( array_filter( array_map( 'sanitize_title', $categories ) ) );
		$out    = [];

		foreach ( array_map( 'intval', (array) $ids ) as $post_id ) {
			$had = array_filter( explode( ',', (string) get_post_meta( $post_id, self::META_CATEGORIES, true ) ) );

			if ( [] !== array_intersect( $had, $wanted ) ) {
				$out[] = $post_id;
			}
		}

		return $out;
	}

	/**
	 * Archive the converted stories that were filed under the given old
	 * categories, through the ordinary transition so the audit trail, the
	 * index and the dashboards all follow. The actor has to be somebody the
	 * policy lets archive: a moderator or an administrator.
	 *
	 * @return array{archived:int[],failed:array<int,string>}
	 */
	public static function archive_in( array $categories, int $actor_id, string $note ): array {
		$result = [ 'archived' => [], 'failed' => [] ];

		foreach ( self::converted_in( $categories ) as $post_id ) {
			$done = Transition::apply( $post_id, StateMachine::ARCHIVE, $actor_id, $note );

			if ( is_wp_error( $done ) ) {
				$result['failed'][ $post_id ] = $done->get_error_message();
			} else {
				$result['archived'][] = $post_id;
			}
		}

		return $result;
	}

	/**
	 * Live news items with no topic, id => old category slugs (empty for a
	 * story that was never a post on the old site). The list the review team
	 * works through.
	 *
	 * @return array<int,string[]>
	 */
	public static function topicless(): array {
		$ids = get_posts(
			[
				'post_type'        => PostTypes::NEWS,
				'post_status'      => Statuses::LIVE,
				'fields'           => 'ids',
				'numberposts'      => -1,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
				'tax_query'        => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[ 'taxonomy' => Taxonomies::TOPIC, 'operator' => 'NOT EXISTS' ],
				],
			]
		);

		$out = [];

		foreach ( array_map( 'intval', (array) $ids ) as $post_id ) {
			$out[ $post_id ] = array_values( array_filter( explode( ',', (string) get_post_meta( $post_id, self::META_CATEGORIES, true ) ) ) );
		}

		return $out;
	}

	/**
	 * Live converted stories whose old categories were all within the given
	 * slugs (and not empty). The rule that tells VAL's stories apart: VAL's
	 * site files under news and blog only, so a story with nothing else came
	 * from there.
	 *
	 * @param string[] $only Old category slugs.
	 * @return int[]
	 */
	public static function converted_only( array $only ): array {
		$allowed = array_values( array_filter( array_map( 'sanitize_title', $only ) ) );
		$out     = [];

		foreach ( self::converted_in( $allowed ) as $post_id ) {
			$had = array_values( array_filter( explode( ',', (string) get_post_meta( $post_id, self::META_CATEGORIES, true ) ) ) );

			if ( [] !== $had && [] === array_diff( $had, $allowed ) ) {
				$out[] = $post_id;
			}
		}

		return $out;
	}
}

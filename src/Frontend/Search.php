<?php
/**
 * Site search across the things the partnership publishes.
 *
 * The theme's header search only ever looked through posts. This answers a
 * `?s=` request with four groups, each in its own section, so a search for
 * "grant" gives the organisations that mention it, then the news, then the
 * events, then the training, and nothing is mixed into one undifferentiated
 * pile. The results page is `templates/public/search.php`.
 *
 * Words are matched in the title, the body and the summary (or, for an
 * organisation, the name and the description), whole phrase, case blind. Only
 * live items and listed, approved organisations come back.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use DGL\Meta;
use DGL\Org\DirectoryQuery;
use DGL\PostTypes;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

final class Search {

	/** How many of each group the overview shows before "See all". */
	public const PER_GROUP = 6;

	/** The most a single-group page shows. */
	public const MAX_GROUP = 100;

	/** Longest term we look for. */
	public const MAX_TERM = 100;

	public const PARAM_TYPE = 'type';

	/** Group key => post type, in the order they appear. Organisations first: people search for names. */
	public static function groups(): array {
		return [
			'organisations' => PostTypes::ORG,
			'news'          => PostTypes::NEWS,
			'events'        => PostTypes::EVENT,
			'training'      => PostTypes::TRAINING,
		];
	}

	public static function label( string $group ): string {
		return match ( $group ) {
			'organisations' => __( 'Organisations', 'dgl-platform' ),
			'news'          => __( 'News', 'dgl-platform' ),
			'events'        => __( 'Events', 'dgl-platform' ),
			'training'      => __( 'Training', 'dgl-platform' ),
			default         => $group,
		};
	}

	/** The term as typed, trimmed, capped, tags gone. Empty when there is nothing to look for. */
	public static function term_from( array $request ): string {
		$raw = isset( $request['s'] ) && is_scalar( $request['s'] ) ? (string) $request['s'] : '';
		$raw = trim( wp_strip_all_tags( $raw ) );
		$raw = (string) preg_replace( '/\s+/u', ' ', $raw );

		return mb_substr( $raw, 0, self::MAX_TERM );
	}

	/** A group key when the request narrows to one, else ''. */
	public static function type_from( array $request ): string {
		$type = isset( $request[ self::PARAM_TYPE ] ) && is_scalar( $request[ self::PARAM_TYPE ] ) ? sanitize_key( (string) $request[ self::PARAM_TYPE ] ) : '';

		return isset( self::groups()[ $type ] ) ? $type : '';
	}

	/** The address of a search, optionally narrowed to one group. */
	public static function url( string $term, string $group = '' ): string {
		$args = [ 's' => $term ];

		if ( '' !== $group ) {
			$args[ self::PARAM_TYPE ] = $group;
		}

		return add_query_arg( array_map( 'rawurlencode', $args ), home_url( '/' ) );
	}

	/**
	 * Every group's matches for a term.
	 *
	 * @return array<string,array{label:string,ids:int[],total:int,more:string}> Keyed by group, in order; a group with nothing is left out.
	 */
	public static function results( string $term, string $only = '' ): array {
		if ( '' === trim( $term ) ) {
			return [];
		}

		$out = [];

		foreach ( self::groups() as $group => $post_type ) {
			if ( '' !== $only && $only !== $group ) {
				continue;
			}

			$limit = '' !== $only ? self::MAX_GROUP : self::PER_GROUP;

			if ( PostTypes::ORG === $post_type ) {
				$found = DirectoryQuery::run( [ 'q' => $term, 'page' => 1, 'filters' => [] ] );
				$ids   = array_slice( $found['ids'], 0, $limit );
				$total = (int) $found['total'];
				$more  = add_query_arg( 'q', rawurlencode( $term ), home_url( '/' . \DGL\Org\Directory::BASE . '/' ) );
			} else {
				$all   = self::item_ids( $term, $post_type );
				$ids   = array_slice( $all, 0, $limit );
				$total = count( $all );
				$more  = self::url( $term, $group );
			}

			if ( 0 === $total ) {
				continue;
			}

			$out[ $group ] = [
				'label' => self::label( $group ),
				'ids'   => $ids,
				'total' => $total,
				'more'  => $more,
			];
		}

		return $out;
	}

	/**
	 * Live items of one type whose title, body or summary holds the phrase.
	 * Events come soonest first; everything else newest first.
	 *
	 * @return int[]
	 */
	public static function item_ids( string $term, string $post_type ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_date, nx.meta_value AS next_at
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} sm ON sm.post_id = p.ID AND sm.meta_key = 'dgl_summary'
				 LEFT JOIN {$wpdb->postmeta} nx ON nx.post_id = p.ID AND nx.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status = %s
				   AND ( p.post_title LIKE %s OR p.post_content LIKE %s OR sm.meta_value LIKE %s )
				 GROUP BY p.ID",
				Meta::ITEM_NEXT_AT,
				$post_type,
				Statuses::LIVE,
				$like,
				$like,
				$like
			),
			ARRAY_A
		);

		$rows = is_array( $rows ) ? $rows : [];

		if ( PostTypes::EVENT === $post_type ) {
			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $a['next_at'] ?? '9' ), (string) ( $b['next_at'] ?? '9' ) ) );
		} else {
			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) $b['post_date'], (string) $a['post_date'] ) );
		}

		return array_map( static fn( array $r ): int => (int) $r['ID'], $rows );
	}

	/** Everything the template needs for one request. */
	public static function view_data( array $request ): array {
		$term = self::term_from( $request );
		$only = self::type_from( $request );
		$hits = self::results( $term, $only );

		return [
			'term'   => $term,
			'only'   => $only,
			'groups' => $hits,
			'total'  => array_sum( array_map( static fn( array $g ): int => $g['total'], $hits ) ),
		];
	}
}

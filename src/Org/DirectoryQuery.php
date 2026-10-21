<?php
/**
 * Finding organisations for the public directory.
 *
 * One SQL query rather than WP_Query, because the search has to cover the
 * description, which is post meta, and the filters have to look inside
 * lists stored as serialised arrays. WP_Query can do neither in one pass.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Org;

use DGL\Meta;
use DGL\PostTypes;

defined( 'ABSPATH' ) || exit;

final class DirectoryQuery {

	public const PER_PAGE = 24;

	/**
	 * Which filters the page offers, keyed by URL parameter.
	 *
	 * A `list` filter looks inside a several-of-a-list field and takes any
	 * number of values (`?service[]=a&service[]=b`): an organisation matches
	 * if it has any of them. Ward is one value. Filters combine with AND.
	 *
	 * Area of work (`dgl_org_specialism`) was a filter until 0.50.0; DGLP
	 * asked for it to go. The field stays on the record and the pages.
	 *
	 * @return array<string, array{label: string, meta: string, options: array<string, string>, list: bool}>
	 */
	public static function filters(): array {
		return [
			'ward'    => [ 'label' => __( 'Ward', 'dgl-platform' ), 'meta' => 'dgl_org_ward', 'options' => Options::wards(), 'list' => false ],
			'service' => [ 'label' => __( 'Service', 'dgl-platform' ), 'meta' => 'dgl_org_services', 'options' => Options::services(), 'list' => true ],
			'for'     => [ 'label' => __( 'Who they work with', 'dgl-platform' ), 'meta' => 'dgl_org_service_users', 'options' => Options::service_users(), 'list' => true ],
		];
	}

	/**
	 * Clean the request into the arguments the query takes.
	 *
	 * A list filter arrives as an array (`service[]=`) or, from an older link
	 * or a home-page chip, as one value; both are kept as a list of known
	 * keys in option order, so the same choice always makes the same URL.
	 *
	 * @param array<string, mixed> $request Usually $_GET.
	 * @return array{q: string, page: int, filters: array<string, string|string[]>}
	 */
	public static function args_from( array $request ): array {
		$filters = [];

		foreach ( self::filters() as $param => $filter ) {
			if ( $filter['list'] ) {
				$wanted = array_filter( array_map( 'strval', array_filter( (array) ( $request[ $param ] ?? [] ), 'is_scalar' ) ), 'strlen' );
				$values = array_keys( array_intersect_key( $filter['options'], array_flip( $wanted ) ) );

				if ( [] !== $values ) {
					$filters[ $param ] = array_map( 'strval', $values );
				}

				continue;
			}

			$value = isset( $request[ $param ] ) && is_scalar( $request[ $param ] ) ? (string) $request[ $param ] : '';

			if ( '' !== $value && isset( $filter['options'][ $value ] ) ) {
				$filters[ $param ] = $value;
			}
		}

		$q = isset( $request['q'] ) && is_scalar( $request['q'] ) ? trim( (string) $request['q'] ) : '';

		return [
			'q'       => mb_substr( $q, 0, 100 ),
			'page'    => max( 1, (int) ( $request['pg'] ?? 1 ) ),
			'filters' => $filters,
		];
	}

	/**
	 * Listed organisations matching the search and filters.
	 *
	 * Listed means: verified, the organisation switched itself on, and the
	 * review team have not hidden it. All three are checked here, not on the
	 * page, so no template can show one by accident.
	 *
	 * @param array{q: string, page: int, filters: array<string, string|string[]>} $args
	 * @return array{ids: int[], total: int, pages: int}
	 */
	public static function run( array $args ): array {
		global $wpdb;

		/*
		 * Two placeholder lists, because the joins print before the WHERE
		 * and prepare() fills placeholders in the order they appear. One
		 * list, appended as the code ran, put the search's LIKE strings
		 * where a filter's meta key belonged as soon as both were used:
		 * "older" plus a ward matched nothing.
		 */
		$join_params  = [ Meta::ORG_IN_DIRECTORY, Meta::ORG_STATUS, Meta::ORG_APPROVED, Meta::ORG_DIRECTORY_HIDDEN ];
		$where_params = [];
		$where        = [ 'hidden.post_id IS NULL' ];
		$joins        = [
			"INNER JOIN {$wpdb->postmeta} listed ON listed.post_id = p.ID AND listed.meta_key = %s AND listed.meta_value = '1'",
			"INNER JOIN {$wpdb->postmeta} status ON status.post_id = p.ID AND status.meta_key = %s AND status.meta_value = %s",
			// The review team's veto: a row here keeps the organisation out whatever it chose.
			"LEFT JOIN {$wpdb->postmeta} hidden ON hidden.post_id = p.ID AND hidden.meta_key = %s AND hidden.meta_value = '1'",
		];

		$q = trim( $args['q'] ?? '' );

		if ( '' !== $q ) {
			$joins[]        = "LEFT JOIN {$wpdb->postmeta} descr ON descr.post_id = p.ID AND descr.meta_key = 'dgl_org_description'";
			$like           = '%' . $wpdb->esc_like( $q ) . '%';
			$where[]        = '( p.post_title LIKE %s OR descr.meta_value LIKE %s )';
			$where_params[] = $like;
			$where_params[] = $like;
		}

		$n = 0;
		foreach ( $args['filters'] ?? [] as $param => $value ) {
			$filter = self::filters()[ $param ] ?? null;

			if ( null === $filter ) {
				continue;
			}

			$values = $filter['list']
				? array_values( array_filter( array_map( 'strval', (array) $value ), 'strlen' ) )
				: [ (string) $value ];

			if ( [] === $values || '' === $values[0] ) {
				continue;
			}

			++$n;
			$alias         = 'f' . $n;
			$joins[]       = "INNER JOIN {$wpdb->postmeta} {$alias} ON {$alias}.post_id = p.ID AND {$alias}.meta_key = %s";
			$join_params[] = $filter['meta'];

			if ( $filter['list'] ) {
				/*
				 * A serialised array holds each key as s:N:"key"; the quotes make
				 * the match exact, so people_of_faith never matches
				 * people_of_faith_buddhist, and esc_like keeps underscores literal.
				 * Several values are any-of: one OR group on the one join.
				 */
				$likes = [];

				foreach ( $values as $key ) {
					$likes[]        = "{$alias}.meta_value LIKE %s";
					$where_params[] = '%' . $wpdb->esc_like( '"' . $key . '"' ) . '%';
				}

				$where[] = '( ' . implode( ' OR ', $likes ) . ' )';
			} else {
				$where[]        = "{$alias}.meta_value = %s";
				$where_params[] = $values[0];
			}
		}

		$where[]        = 'p.post_type = %s';
		$where_params[] = PostTypes::ORG;
		$where[]        = "p.post_status = 'publish'";

		$sql    = "SELECT SQL_CALC_FOUND_ROWS p.ID FROM {$wpdb->posts} p " . implode( ' ', $joins ) . ' WHERE ' . implode( ' AND ', $where ) . ' GROUP BY p.ID ORDER BY p.post_title ASC LIMIT %d OFFSET %d';
		$params = array_merge( $join_params, $where_params );

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$params[] = self::PER_PAGE;
		$params[] = ( $page - 1 ) * self::PER_PAGE;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
		$total = self::found_rows( $sql, $params );

		return [
			'ids'   => $ids,
			'total' => $total,
			'pages' => (int) ceil( $total / self::PER_PAGE ),
		];
	}

	/**
	 * How many match without the page limit. Counted with a second query
	 * rather than FOUND_ROWS(), which SQLite (the test database) lacks.
	 *
	 * @param array<int, mixed> $params
	 */
	private static function found_rows( string $sql, array $params ): int {
		global $wpdb;

		$count_sql = preg_replace( '/^SELECT SQL_CALC_FOUND_ROWS p\.ID/', 'SELECT COUNT(DISTINCT p.ID)', $sql );
		$count_sql = preg_replace( '/ GROUP BY p\.ID ORDER BY p\.post_title ASC LIMIT %d OFFSET %d$/', '', (string) $count_sql );
		$params    = array_slice( $params, 0, -2 );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( (string) $count_sql, $params ) );
	}

	/**
	 * The query string for a set of arguments, in the shape the page's own
	 * form submits: `q`, then each filter in its definition order with a
	 * list as `service[]=a&service[]=b`, then `pg` when past page one.
	 * Built by hand because add_query_arg() writes a list as `service[0]=`.
	 * Empty when nothing is set.
	 *
	 * @param array{q?: string, page?: int, filters?: array<string, string|string[]>} $args
	 */
	public static function query_string( array $args ): string {
		$parts = [];
		$q     = trim( (string) ( $args['q'] ?? '' ) );

		if ( '' !== $q ) {
			$parts[] = 'q=' . rawurlencode( $q );
		}

		foreach ( self::filters() as $param => $filter ) {
			$value = $args['filters'][ $param ] ?? null;

			if ( null === $value || '' === $value || [] === $value ) {
				continue;
			}

			foreach ( $filter['list'] ? (array) $value : [ (string) $value ] as $one ) {
				$parts[] = rawurlencode( $param ) . ( $filter['list'] ? '%5B%5D=' : '=' ) . rawurlencode( (string) $one );
			}
		}

		if ( (int) ( $args['page'] ?? 1 ) > 1 ) {
			$parts[] = 'pg=' . (int) $args['page'];
		}

		return implode( '&', $parts );
	}

	/**
	 * The same arguments with one filter value taken out: the link behind a
	 * "remove this filter" chip. A list loses that one value, a single-value
	 * filter is cleared, and the page goes back to one.
	 *
	 * @param array{q?: string, page?: int, filters?: array<string, string|string[]>} $args
	 * @return array{q: string, page: int, filters: array<string, string|string[]>}
	 */
	public static function without( array $args, string $param, string $value = '' ): array {
		$filters = (array) ( $args['filters'] ?? [] );
		$current = $filters[ $param ] ?? null;

		if ( is_array( $current ) ) {
			$kept = array_values( array_filter( $current, static fn( $v ): bool => (string) $v !== $value ) );

			if ( [] === $kept ) {
				unset( $filters[ $param ] );
			} else {
				$filters[ $param ] = $kept;
			}
		} else {
			unset( $filters[ $param ] );
		}

		return [ 'q' => (string) ( $args['q'] ?? '' ), 'page' => 1, 'filters' => $filters ];
	}

	/**
	 * The directory page for a set of arguments.
	 *
	 * @param array{q?: string, page?: int, filters?: array<string, string|string[]>} $args
	 */
	public static function search_url( array $args ): string {
		$query = self::query_string( $args );

		return home_url( '/' . Directory::BASE . '/' ) . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * The listed organisation at this slug or ID, or null.
	 */
	public static function find( string $slug ): ?\WP_Post {
		$post = null;

		if ( ctype_digit( $slug ) ) {
			$post = get_post( (int) $slug );
		} else {
			$found = get_posts(
				[
					'post_type'      => PostTypes::ORG,
					'post_status'    => 'publish',
					'name'           => sanitize_title( $slug ),
					'posts_per_page' => 1,
					'no_found_rows'  => true,
				]
			);
			$post  = $found[0] ?? null;
		}

		if ( ! $post instanceof \WP_Post || PostTypes::ORG !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		return Directory::is_listed( (int) $post->ID ) ? $post : null;
	}

	/**
	 * The public address of an organisation's directory page.
	 */
	public static function url( int $org_id ): string {
		$post = get_post( $org_id );
		$slug = $post instanceof \WP_Post && '' !== $post->post_name ? $post->post_name : (string) $org_id;

		return home_url( '/' . Directory::BASE . '/' . $slug . '/' );
	}
}

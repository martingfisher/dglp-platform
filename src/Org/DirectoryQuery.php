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
	 * @return array<string, array{label: string, meta: string, options: array<string, string>, list: bool}>
	 */
	public static function filters(): array {
		return [
			'ward'    => [ 'label' => __( 'Ward', 'dgl-platform' ), 'meta' => 'dgl_org_ward', 'options' => Options::wards(), 'list' => false ],
			'area'    => [ 'label' => __( 'Area of work', 'dgl-platform' ), 'meta' => 'dgl_org_specialism', 'options' => Options::specialism(), 'list' => true ],
			'service' => [ 'label' => __( 'Service', 'dgl-platform' ), 'meta' => 'dgl_org_services', 'options' => Options::services(), 'list' => true ],
			'for'     => [ 'label' => __( 'Who they work with', 'dgl-platform' ), 'meta' => 'dgl_org_service_users', 'options' => Options::service_users(), 'list' => true ],
		];
	}

	/**
	 * Clean the request into the arguments the query takes.
	 *
	 * @param array<string, mixed> $request Usually $_GET.
	 * @return array{q: string, page: int, filters: array<string, string>}
	 */
	public static function args_from( array $request ): array {
		$filters = [];

		foreach ( self::filters() as $param => $filter ) {
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
	 * Listed means: verified, and the organisation switched itself on. Both
	 * are checked here, not on the page, so no template can show one by
	 * accident.
	 *
	 * @param array{q: string, page: int, filters: array<string, string>} $args
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
		$join_params  = [ Meta::ORG_IN_DIRECTORY, Meta::ORG_STATUS, Meta::ORG_APPROVED ];
		$where_params = [];
		$where        = [];
		$joins        = [
			"INNER JOIN {$wpdb->postmeta} listed ON listed.post_id = p.ID AND listed.meta_key = %s AND listed.meta_value = '1'",
			"INNER JOIN {$wpdb->postmeta} status ON status.post_id = p.ID AND status.meta_key = %s AND status.meta_value = %s",
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

			++$n;
			$alias         = 'f' . $n;
			$joins[]       = "INNER JOIN {$wpdb->postmeta} {$alias} ON {$alias}.post_id = p.ID AND {$alias}.meta_key = %s";
			$join_params[] = $filter['meta'];

			if ( $filter['list'] ) {
				// A serialised array holds each key as s:N:"key"; the quotes make the match exact.
				$where[]        = "{$alias}.meta_value LIKE %s";
				$where_params[] = '%' . $wpdb->esc_like( '"' . $value . '"' ) . '%';
			} else {
				$where[]        = "{$alias}.meta_value = %s";
				$where_params[] = $value;
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

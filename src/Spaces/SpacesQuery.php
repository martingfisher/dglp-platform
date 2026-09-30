<?php
/**
 * Finding a space: live venues with the live spaces that match.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Spaces;

use DGL\Index\ItemsTable;
use DGL\Meta;
use DGL\PostTypes;
use DGL\Schema\Types\Space;
use DGL\Schema\Types\Venue;
use DGL\Statuses;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * One SQL query, the way the directory does it, because the filters look
 * inside the spaces (capacity, type, price) while the results are venues.
 * A venue is in the results when at least one of its live spaces matches
 * every space filter and the venue itself matches every venue filter.
 *
 * Priced venues come first: a rate is worth more to somebody choosing a
 * room than a name, and it nudges venues to add one. Within that, A to Z.
 *
 * Numeric compares use `meta_value + 0` rather than CAST, because the
 * test database is SQLite and the two disagree on the syntax.
 */
final class SpacesQuery {

	public const PER_PAGE = 20;

	/** The most people a search can ask for. */
	public const MAX_PEOPLE = 5000;

	/** Price bands, by hourly rate. */
	public const BANDS = [
		'free'       => 'Free',
		'up_to_15'   => 'Up to £15 an hour',
		'15_30'      => '£15 to £30 an hour',
		'over_30'    => 'Over £30 an hour',
		'on_request' => 'Price on request',
	];

	/**
	 * Clean the request into the arguments the query takes.
	 *
	 * @param array<string, mixed> $request Usually $_GET.
	 * @return array{q: string, ward: string, people: int, type: string, access: string[], price: string, page: int}
	 */
	public static function args_from( array $request ): array {
		$q      = isset( $request['q'] ) && is_scalar( $request['q'] ) ? trim( (string) $request['q'] ) : '';
		$ward   = isset( $request['ward'] ) && is_scalar( $request['ward'] ) ? (string) $request['ward'] : '';
		$type   = isset( $request['type'] ) && is_scalar( $request['type'] ) ? (string) $request['type'] : '';
		$price  = isset( $request['price'] ) && is_scalar( $request['price'] ) ? (string) $request['price'] : '';
		$people = isset( $request['people'] ) && is_scalar( $request['people'] ) ? (int) $request['people'] : 0;
		$access = isset( $request['access'] ) ? (array) $request['access'] : [];

		return [
			'q'      => mb_substr( $q, 0, 100 ),
			'ward'   => isset( Venue::wards()[ $ward ] ) ? $ward : '',
			'people' => max( 0, min( self::MAX_PEOPLE, $people ) ),
			'type'   => isset( Space::TYPES[ $type ] ) ? $type : '',
			'access' => array_values( array_unique( array_filter( array_map( 'strval', $access ), static fn( string $k ): bool => isset( Venue::ACCESS[ $k ] ) ) ) ),
			'price'  => isset( self::BANDS[ $price ] ) ? $price : '',
			'page'   => max( 1, (int) ( $request['pg'] ?? 1 ) ),
		];
	}

	/**
	 * Whether any filter is set, so the page can say "showing everything".
	 *
	 * @param array<string, mixed> $args
	 */
	public static function is_active( array $args ): bool {
		return '' !== $args['q'] || '' !== $args['ward'] || $args['people'] > 0 || '' !== $args['type'] || [] !== $args['access'] || '' !== $args['price'];
	}

	/**
	 * The band a rate falls in. Hourly bands only: a day or session rate is
	 * "priced" for ranking and matches "Any price", but is not made into
	 * an hourly figure the venue never quoted.
	 */
	public static function price_band( ?float $rate, string $unit ): string {
		if ( null === $rate ) {
			return 'on_request';
		}

		if ( $rate <= 0 ) {
			return 'free';
		}

		if ( 'hour' !== $unit ) {
			return 'other';
		}

		if ( $rate <= 15 ) {
			return 'up_to_15';
		}

		return $rate <= 30 ? '15_30' : 'over_30';
	}

	/**
	 * Whether a space takes this many people in any layout.
	 *
	 * @param array<string, int|string|null> $caps The four capacity values, any order.
	 */
	public static function matches_people( array $caps, int $people ): bool {
		if ( $people <= 0 ) {
			return true;
		}

		foreach ( $caps as $cap ) {
			if ( (int) $cap >= $people ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether one space's stored values meet the space filters. The SQL does
	 * this coarsely to pick venues; this is the exact version, used to say
	 * which of a venue's rooms matched.
	 *
	 * @param array<string, mixed> $meta space_type, cap_*, rate, rate_unit.
	 * @param array<string, mixed> $args From args_from().
	 */
	public static function space_matches( array $meta, array $args ): bool {
		if ( '' !== $args['type'] && (string) ( $meta['space_type'] ?? '' ) !== $args['type'] ) {
			return false;
		}

		if ( ! self::matches_people( array_intersect_key( $meta, Space::LAYOUTS ), (int) $args['people'] ) ) {
			return false;
		}

		if ( '' !== $args['price'] ) {
			$rate = '' === (string) ( $meta['rate'] ?? '' ) ? null : (float) $meta['rate'];
			$band = self::price_band( $rate, (string) ( $meta['rate_unit'] ?? '' ) );

			if ( $band !== $args['price'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The venues that match, one page of them.
	 *
	 * @param array<string, mixed> $args From args_from().
	 * @return array{ids: int[], total: int, pages: int}
	 */
	public static function run( array $args ): array {
		[ $sql, $params ] = self::build( $args, true );

		global $wpdb;

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$params[] = self::PER_PAGE;
		$params[] = ( $page - 1 ) * self::PER_PAGE;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );

		[ $count_sql, $count_params ] = self::build( $args, false );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( [] === $count_params ? $count_sql : $wpdb->prepare( $count_sql, $count_params ) );

		return [
			'ids'   => $ids,
			'total' => $total,
			'pages' => (int) ceil( $total / self::PER_PAGE ),
		];
	}

	/**
	 * Every matching venue's map pin: id, title, address, lat, lng. Only
	 * venues the geocoder has placed; capped so a map never gets a
	 * thousand markers.
	 *
	 * @param array<string, mixed> $args
	 * @return array<int, array{id:int, title:string, url:string, lat:float, lng:float}>
	 */
	public static function pins( array $args, int $cap = 500 ): array {
		[ $sql, $params ] = self::build( $args, true );

		global $wpdb;

		$params[] = $cap;
		$params[] = 0;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
		$out = [];

		if ( [] !== $ids ) {
			update_postmeta_cache( $ids );
		}

		foreach ( $ids as $id ) {
			$lat = (string) get_post_meta( $id, Meta::VENUE_LAT, true );
			$lng = (string) get_post_meta( $id, Meta::VENUE_LNG, true );

			if ( '' === $lat || '' === $lng ) {
				continue;
			}

			$out[] = [
				'id'    => $id,
				'title' => (string) get_the_title( $id ),
				'url'   => (string) get_permalink( $id ),
				'lat'   => (float) $lat,
				'lng'   => (float) $lng,
			];
		}

		return $out;
	}

	/**
	 * The SQL and its parameters. The join and where placeholders are kept
	 * in the order they print, which is the bug the directory query found.
	 *
	 * @param array<string, mixed> $args
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private static function build( array $args, bool $paged ): array {
		global $wpdb;

		$items = ItemsTable::name();

		$joins  = [
			"INNER JOIN {$items} vi ON vi.post_id = p.ID AND vi.post_type = %s AND vi.status = %s",
		];
		$params = [ PostTypes::VENUE, Statuses::LIVE ];
		$where  = [];

		$q = trim( (string) ( $args['q'] ?? '' ) );

		if ( '' !== $q ) {
			$joins[] = "LEFT JOIN {$wpdb->postmeta} sm ON sm.post_id = p.ID AND sm.meta_key = 'dgl_summary'";
		}

		if ( '' !== (string) ( $args['ward'] ?? '' ) ) {
			$joins[] = "INNER JOIN {$wpdb->postmeta} wd ON wd.post_id = p.ID AND wd.meta_key = 'dgl_ward'";
		}

		if ( [] !== (array) ( $args['access'] ?? [] ) ) {
			$joins[] = "INNER JOIN {$wpdb->postmeta} ac ON ac.post_id = p.ID AND ac.meta_key = 'dgl_access'";
		}

		$where[]  = 'p.post_type = %s';
		$params[] = PostTypes::VENUE;
		$where[]  = 'p.post_status = %s';
		$params[] = Statuses::LIVE;

		// At least one live space that meets the space filters.
		$space_joins  = [];
		$space_where  = [ 'si.parent_id = p.ID', 'si.post_type = %s', 'si.status = %s' ];
		$space_params = [ PostTypes::SPACE, Statuses::LIVE ];

		if ( '' !== (string) ( $args['type'] ?? '' ) ) {
			$space_joins[]  = "INNER JOIN {$wpdb->postmeta} st ON st.post_id = si.post_id AND st.meta_key = 'dgl_space_type'";
			$space_where[]  = 'st.meta_value = %s';
			$space_params[] = (string) $args['type'];
		}

		if ( (int) ( $args['people'] ?? 0 ) > 0 ) {
			$keys           = "'" . implode( "','", array_map( static fn( string $k ): string => 'dgl_' . $k, array_keys( Space::LAYOUTS ) ) ) . "'";
			$space_joins[]  = "INNER JOIN {$wpdb->postmeta} cp ON cp.post_id = si.post_id AND cp.meta_key IN ({$keys})";
			$space_where[]  = 'cp.meta_value + 0 >= %d';
			$space_params[] = (int) $args['people'];
		}

		$price = (string) ( $args['price'] ?? '' );

		if ( '' !== $price ) {
			$space_joins[] = "LEFT JOIN {$wpdb->postmeta} rt ON rt.post_id = si.post_id AND rt.meta_key = 'dgl_rate'";
			$space_joins[] = "LEFT JOIN {$wpdb->postmeta} ru ON ru.post_id = si.post_id AND ru.meta_key = 'dgl_rate_unit'";

			switch ( $price ) {
				case 'free':
					$space_where[] = 'rt.meta_value IS NOT NULL AND rt.meta_value + 0 <= 0';
					break;
				case 'on_request':
					$space_where[] = 'rt.meta_value IS NULL';
					break;
				case 'up_to_15':
					$space_where[] = "ru.meta_value = 'hour' AND rt.meta_value + 0 > 0 AND rt.meta_value + 0 <= 15";
					break;
				case '15_30':
					$space_where[] = "ru.meta_value = 'hour' AND rt.meta_value + 0 > 15 AND rt.meta_value + 0 <= 30";
					break;
				default:
					$space_where[] = "ru.meta_value = 'hour' AND rt.meta_value + 0 > 30";
			}
		}

		$where[] = "EXISTS (SELECT 1 FROM {$items} si " . implode( ' ', $space_joins ) . ' WHERE ' . implode( ' AND ', $space_where ) . ')';
		$params  = array_merge( $params, $space_params );

		if ( '' !== $q ) {
			$like     = '%' . $wpdb->esc_like( $q ) . '%';
			$where[]  = '( p.post_title LIKE %s OR sm.meta_value LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== (string) ( $args['ward'] ?? '' ) ) {
			$where[]  = 'wd.meta_value = %s';
			$params[] = (string) $args['ward'];
		}

		foreach ( (array) ( $args['access'] ?? [] ) as $key ) {
			// A serialised array holds each key as s:N:"key"; the quotes make the match exact.
			$where[]  = 'ac.meta_value LIKE %s';
			$params[] = '%' . $wpdb->esc_like( '"' . $key . '"' ) . '%';
		}

		$where_sql = implode( ' AND ', $where );
		$join_sql  = implode( ' ', $joins );

		if ( ! $paged ) {
			return [ "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$join_sql} WHERE {$where_sql}", $params ];
		}

		$priced = "(SELECT COUNT(*) FROM {$items} sp INNER JOIN {$wpdb->postmeta} r ON r.post_id = sp.post_id AND r.meta_key = 'dgl_rate' AND r.meta_value + 0 > 0 WHERE sp.parent_id = p.ID AND sp.post_type = '" . PostTypes::SPACE . "' AND sp.status = '" . Statuses::LIVE . "') > 0";

		$sql = "SELECT p.ID FROM {$wpdb->posts} p {$join_sql} WHERE {$where_sql} GROUP BY p.ID ORDER BY {$priced} DESC, p.post_title ASC LIMIT %d OFFSET %d";

		return [ $sql, $params ];
	}

	/* ---- Hydration --------------------------------------------------------- */

	/**
	 * A venue's live spaces with their stored values, ready to print.
	 *
	 * @return array<int, array{post: WP_Post, meta: array<string, mixed>}>
	 */
	public static function spaces( int $venue_id ): array {
		$ids = Link::spaces_of( $venue_id );

		if ( [] === $ids ) {
			return [];
		}

		update_postmeta_cache( $ids );

		$out = [];

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( $post instanceof WP_Post ) {
				$out[] = [ 'post' => $post, 'meta' => self::space_meta( $id ) ];
			}
		}

		// Whole building first, then the biggest room.
		usort(
			$out,
			static function ( array $a, array $b ): int {
				$wa = 'whole_building' === (string) ( $a['meta']['space_type'] ?? '' ) ? 0 : 1;
				$wb = 'whole_building' === (string) ( $b['meta']['space_type'] ?? '' ) ? 0 : 1;

				if ( $wa !== $wb ) {
					return $wa <=> $wb;
				}

				return self::largest( $b['meta'] ) <=> self::largest( $a['meta'] );
			}
		);

		return $out;
	}

	/**
	 * The values a space card and the filters read.
	 *
	 * @return array<string, mixed>
	 */
	public static function space_meta( int $space_id ): array {
		$meta = [];

		foreach ( [ 'space_type', 'size_m2', 'space_facilities', 'rate', 'rate_unit', 'rate_note', 'summary', 'image' ] as $key ) {
			$meta[ $key ] = get_post_meta( $space_id, 'dgl_' . $key, true );
		}

		foreach ( array_keys( Space::LAYOUTS ) as $key ) {
			$meta[ $key ] = get_post_meta( $space_id, 'dgl_' . $key, true );
		}

		return $meta;
	}

	/**
	 * The most people a space takes in any layout.
	 *
	 * @param array<string, mixed> $meta
	 */
	public static function largest( array $meta ): int {
		$max = 0;

		foreach ( array_keys( Space::LAYOUTS ) as $key ) {
			$max = max( $max, (int) ( $meta[ $key ] ?? 0 ) );
		}

		return $max;
	}

	/**
	 * What a venue offers at a glance: how many spaces, the most people,
	 * the lowest rate. Pure, so the template and the tests agree.
	 *
	 * @param array<int, array<string, mixed>> $spaces_meta One meta array per live space.
	 * @return array{count:int, max_people:int, from:?float, from_unit:string, on_request:bool}
	 */
	public static function quick_facts( array $spaces_meta ): array {
		$count      = count( $spaces_meta );
		$max_people = 0;
		$from       = null;
		$from_unit  = '';
		$on_request = false;

		foreach ( $spaces_meta as $meta ) {
			$max_people = max( $max_people, self::largest( $meta ) );

			if ( '' === (string) ( $meta['rate'] ?? '' ) ) {
				$on_request = true;
				continue;
			}

			$rate = (float) $meta['rate'];
			$unit = (string) ( $meta['rate_unit'] ?? '' );

			// Hourly rates compare with each other; a day rate only stands in when nothing is hourly.
			if ( null === $from || ( 'hour' === $unit && 'hour' !== $from_unit ) || ( $unit === $from_unit && $rate < $from ) ) {
				$from      = $rate;
				$from_unit = $unit;
			}
		}

		return [
			'count'      => $count,
			'max_people' => $max_people,
			'from'       => $from,
			'from_unit'  => $from_unit,
			'on_request' => $on_request && null === $from,
		];
	}

	/**
	 * A rate in words: "£35 an hour", "Free", or "Price on request".
	 *
	 * @param array<string, mixed> $meta
	 */
	public static function rate_words( array $meta ): string {
		if ( '' === (string) ( $meta['rate'] ?? '' ) ) {
			return __( 'Price on request', 'dgl-platform' );
		}

		$rate = (float) $meta['rate'];

		if ( $rate <= 0 ) {
			return __( 'Free', 'dgl-platform' );
		}

		$unit = Venue::labelled( Space::UNITS )[ (string) ( $meta['rate_unit'] ?? '' ) ] ?? '';

		/* translators: 1: an amount like £35, 2: "an hour", "a session" or "a day". */
		return trim( sprintf( __( '%1$s %2$s', 'dgl-platform' ), self::money( $rate ), $unit ) );
	}

	/**
	 * Pounds, with pence only when there are some.
	 */
	public static function money( float $amount ): string {
		return '£' . ( floor( $amount ) === $amount ? number_format( $amount, 0 ) : number_format( $amount, 2 ) );
	}
}

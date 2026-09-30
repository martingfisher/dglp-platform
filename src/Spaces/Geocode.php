<?php
/**
 * Where a venue's postcode is on the map.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Spaces;

use DGL\Meta;
use DGL\PostTypes;
use DGL\Workflow\Plan;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A postcode is looked up once, on postcodes.io (free, no key, Open
 * Government Licence), when a venue goes live or an approved edit lands,
 * and the answer is kept on the venue as latitude and longitude. Never
 * on a page render: a visitor is never made to wait on somebody else's
 * server. The lookup is cached per postcode in a transient, so a second
 * venue at the same postcode costs nothing and a failure is not retried
 * on every save.
 */
final class Geocode {

	public const ENDPOINT = 'https://api.postcodes.io/postcodes/';

	/** How long a good answer is kept, and a failure. */
	public const KEEP_HIT  = 30 * DAY_IN_SECONDS;
	public const KEEP_MISS = HOUR_IN_SECONDS;

	/** Seconds to wait for the lookup before giving up. */
	public const TIMEOUT = 4;

	public static function init(): void {
		add_action( 'dgl_item_transitioned', [ self::class, 'on_transition' ], 15, 4 );
		add_action( 'dgl_revision_applied', [ self::class, 'on_revision' ], 10, 2 );
	}

	public static function on_transition( int $post_id, Plan $plan, int $actor_id, string $note = '' ): void {
		if ( $plan->publishes() && Link::is_venue( get_post( $post_id ) ) ) {
			self::stamp( $post_id );
		}
	}

	public static function on_revision( int $parent_id, int $revision_id ): void {
		if ( Link::is_venue( get_post( $parent_id ) ) ) {
			self::stamp( $parent_id );
		}
	}

	/**
	 * Write the venue's coordinates from its postcode, or clear them when
	 * the postcode is blank or unknown. Returns what was written, or null.
	 *
	 * @return array{lat: float, lng: float}|null
	 */
	public static function stamp( int $venue_id ): ?array {
		$postcode = (string) get_post_meta( $venue_id, 'dgl_postcode', true );
		$found    = '' === trim( $postcode ) ? null : self::lookup( $postcode );

		if ( null === $found ) {
			delete_post_meta( $venue_id, Meta::VENUE_LAT );
			delete_post_meta( $venue_id, Meta::VENUE_LNG );

			return null;
		}

		update_post_meta( $venue_id, Meta::VENUE_LAT, (string) $found['lat'] );
		update_post_meta( $venue_id, Meta::VENUE_LNG, (string) $found['lng'] );

		return $found;
	}

	/**
	 * The venue's stored coordinates, or null when it has none.
	 *
	 * @return array{lat: float, lng: float}|null
	 */
	public static function coords( int $venue_id ): ?array {
		$lat = (string) get_post_meta( $venue_id, Meta::VENUE_LAT, true );
		$lng = (string) get_post_meta( $venue_id, Meta::VENUE_LNG, true );

		return '' === $lat || '' === $lng ? null : [ 'lat' => (float) $lat, 'lng' => (float) $lng ];
	}

	/**
	 * Where a postcode is, from the cache or from postcodes.io.
	 *
	 * @return array{lat: float, lng: float}|null
	 */
	public static function lookup( string $postcode ): ?array {
		$compact = strtoupper( (string) preg_replace( '/\s+/', '', $postcode ) );

		if ( '' === $compact ) {
			return null;
		}

		$key    = 'dgl_geo_' . md5( $compact );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return [ 'lat' => (float) $cached['lat'], 'lng' => (float) $cached['lng'] ];
		}

		if ( 'none' === $cached ) {
			return null;
		}

		$response = wp_remote_get( self::ENDPOINT . rawurlencode( $compact ), [ 'timeout' => self::TIMEOUT ] );

		if ( is_wp_error( $response ) ) {
			set_transient( $key, 'none', self::KEEP_MISS );

			return null;
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$found = 200 === $code ? self::parse( (string) wp_remote_retrieve_body( $response ) ) : null;

		if ( null === $found ) {
			// A postcode the service does not know stays unknown for a month; a server fault is retried in an hour.
			set_transient( $key, 'none', 404 === $code ? self::KEEP_HIT : self::KEEP_MISS );

			return null;
		}

		set_transient( $key, $found, self::KEEP_HIT );

		return $found;
	}

	/**
	 * The coordinates in a postcodes.io answer, or null. Pure.
	 *
	 * @return array{lat: float, lng: float}|null
	 */
	public static function parse( string $body ): ?array {
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || 200 !== (int) ( $data['status'] ?? 0 ) || ! is_array( $data['result'] ?? null ) ) {
			return null;
		}

		$lat = $data['result']['latitude'] ?? null;
		$lng = $data['result']['longitude'] ?? null;

		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}

		return [ 'lat' => round( (float) $lat, 6 ), 'lng' => round( (float) $lng, 6 ) ];
	}

	/**
	 * Venues that are live and have no pin, for the backfill.
	 *
	 * @return int[]
	 */
	public static function unplaced( bool $all = false ): array {
		$ids = get_posts(
			[
				'post_type'        => PostTypes::VENUE,
				'post_status'      => $all ? 'any' : \DGL\Statuses::LIVE,
				'fields'           => 'ids',
				'numberposts'      => -1,
				'suppress_filters' => true,
			]
		);

		return array_values(
			array_filter(
				array_map( 'intval', (array) $ids ),
				static fn( int $id ): bool => $all || null === self::coords( $id )
			)
		);
	}
}

<?php
/**
 * What the two public pages print: Find a space, and a venue.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Spaces;

use DGL\Meta;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class Pages {

	/**
	 * The Find a space page.
	 *
	 * @param array<string, mixed> $request Usually $_GET.
	 * @return array<string, mixed>
	 */
	public static function find_data( array $request ): array {
		$args = SpacesQuery::args_from( $request );

		return [
			'args'   => $args,
			'result' => SpacesQuery::run( $args ),
			'pins'   => MapAssets::available() ? SpacesQuery::pins( $args ) : [],
		];
	}

	/**
	 * A venue page.
	 *
	 * @return array<string, mixed>
	 */
	public static function venue_data( ?WP_Post $post ): array {
		if ( ! $post instanceof WP_Post ) {
			return [ 'post' => null ];
		}

		$lat = (string) get_post_meta( (int) $post->ID, Meta::VENUE_LAT, true );
		$lng = (string) get_post_meta( (int) $post->ID, Meta::VENUE_LNG, true );

		return [
			'post'    => $post,
			'enquiry' => Enquiry::state( $post ),
			'map'     => MapAssets::available() && '' !== $lat && '' !== $lng
				? [ 'lat' => (float) $lat, 'lng' => (float) $lng, 'title' => (string) get_the_title( $post ) ]
				: null,
		];
	}
}

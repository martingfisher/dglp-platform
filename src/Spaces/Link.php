<?php
/**
 * The link between a space and its venue.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Spaces;

use DGL\Index\ItemsTable;
use DGL\Meta;
use DGL\PostTypes;
use DGL\Statuses;
use DGL\Workflow\Revisions;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A space belongs to one venue for life. The link is set when the space is
 * started, mirrored into the index as `parent_id`, and read from here by
 * everything that needs to walk it either way: the venue screen listing
 * its spaces, a space naming its venue, the public page, the cascade.
 */
final class Link {

	public static function is_venue( ?WP_Post $post ): bool {
		return $post instanceof WP_Post && PostTypes::VENUE === $post->post_type;
	}

	public static function is_space( ?WP_Post $post ): bool {
		return $post instanceof WP_Post && PostTypes::SPACE === $post->post_type;
	}

	/**
	 * The venue a space belongs to, or 0. A pending edit to a space answers
	 * for the space it would replace.
	 */
	public static function venue_of( int $post_id ): int {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		if ( PostTypes::REVISION === $post->post_type ) {
			$post_id = Revisions::target( $post_id );
		}

		return (int) get_post_meta( $post_id, Meta::SPACE_VENUE, true );
	}

	/**
	 * The venue post a space belongs to, or null.
	 */
	public static function venue_post( int $post_id ): ?WP_Post {
		$venue_id = self::venue_of( $post_id );
		$venue    = $venue_id > 0 ? get_post( $venue_id ) : null;

		return self::is_venue( $venue ) ? $venue : null;
	}

	/**
	 * A venue's spaces, from the index. Live ones by default.
	 *
	 * @param string[]|null $statuses
	 * @return int[]
	 */
	public static function spaces_of( int $venue_id, ?array $statuses = [ Statuses::LIVE ] ): array {
		return ItemsTable::children( $venue_id, $statuses );
	}

	/**
	 * The venues an organisation can add a space to: anything of theirs
	 * that is not rejected, so a member can build the rooms while the
	 * building itself is still with the review team.
	 *
	 * @return array<int, WP_Post>
	 */
	public static function venues_for_org( int $org_id ): array {
		$out = [];

		foreach ( ItemsTable::for_org( $org_id, [ PostTypes::VENUE ], [ Statuses::DRAFT, Statuses::PENDING, Statuses::CHANGES, Statuses::LIVE, Statuses::EXPIRED ], 100 ) as $id ) {
			$post = get_post( $id );

			if ( $post instanceof WP_Post ) {
				$out[ (int) $post->ID ] = $post;
			}
		}

		uasort( $out, static fn( WP_Post $a, WP_Post $b ): int => strcasecmp( $a->post_title, $b->post_title ) );

		return $out;
	}

	/**
	 * Whether a member's organisation may start a space under this venue.
	 */
	public static function can_add_space( int $venue_id, int $org_id ): bool {
		$venue = get_post( $venue_id );

		return self::is_venue( $venue )
			&& (int) get_post_meta( $venue_id, Meta::ITEM_ORG, true ) === $org_id
			&& ! in_array( (string) $venue->post_status, [ Statuses::ARCHIVED, Statuses::REJECTED, 'trash' ], true );
	}

	/**
	 * Where the public sees a thing. A space has no page: it is a card on
	 * its venue's page. Empty when nothing is live to link to.
	 */
	public static function public_url( WP_Post $post ): string {
		if ( self::is_space( $post ) ) {
			$venue = self::venue_post( (int) $post->ID );

			if ( null === $venue || Statuses::LIVE !== $venue->post_status || Statuses::LIVE !== $post->post_status ) {
				return '';
			}

			$url = get_permalink( $venue );

			return is_string( $url ) ? $url . '#space-' . (int) $post->ID : '';
		}

		if ( Statuses::LIVE !== $post->post_status ) {
			return '';
		}

		$url = get_permalink( $post );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * The dashboard address for adding a space to a venue.
	 */
	public static function add_space_url( int $venue_id ): string {
		return add_query_arg( 'venue', $venue_id, \DGL\Dashboard\Router::url( 'new', PostTypes::definitions()[ PostTypes::SPACE ]['slug'] ) );
	}
}

<?php
/**
 * A member switches a live venue or space off the site and back.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Spaces;

use DGL\Access\Access;
use DGL\Access\Policy;
use DGL\Access\UserContext;
use DGL\Audit\Log;
use DGL\Meta;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Statuses;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A venue shut for a refurbishment, a hall out of use for a term: the
 * organisation hides it and shows it again itself, at once, with no
 * review either way, because nothing about the listing changes. Hidden
 * means off Find a space, off the search, off the organisation's page,
 * off the map, and its own page answers 404; its details, photos and
 * spaces stay exactly as they were. The archive stays for taking a
 * listing down for good; that road back runs through the review team.
 */
final class Visibility {

	public static function init(): void {
		add_action( 'template_redirect', [ self::class, 'not_found_when_hidden' ], 9 );
	}

	public static function is_hidden( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, Meta::ITEM_HIDDEN, true );
	}

	/**
	 * Whether this is a live venue or space, the only things with a switch.
	 */
	public static function has_switch( ?WP_Post $post ): bool {
		return $post instanceof WP_Post
			&& Statuses::LIVE === $post->post_status
			&& in_array( PostTypes::kind_of( (string) $post->post_type ), [ PostTypes::KIND_VENUE, PostTypes::KIND_SPACE ], true );
	}

	/**
	 * Anyone who could archive it can hide it: the organisation's members
	 * and the review team.
	 */
	public static function can_toggle( UserContext $user, ?WP_Post $post ): bool {
		return self::has_switch( $post ) && Access::can( $user->user_id, Policy::ARCHIVE_ITEM, (int) $post->ID );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function hide( int $post_id, UserContext $user ): true|WP_Error {
		return self::set( $post_id, $user, true );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function show( int $post_id, UserContext $user ): true|WP_Error {
		return self::set( $post_id, $user, false );
	}

	/**
	 * @return true|WP_Error
	 */
	private static function set( int $post_id, UserContext $user, bool $hidden ): true|WP_Error {
		$post = get_post( $post_id );

		if ( ! self::can_toggle( $user, $post ) ) {
			return new WP_Error( 'dgl_no_switch', __( 'This one cannot be hidden or shown.', 'dgl-platform' ) );
		}

		if ( $hidden === self::is_hidden( $post_id ) ) {
			return true;
		}

		if ( $hidden ) {
			update_post_meta( $post_id, Meta::ITEM_HIDDEN, '1' );
		} else {
			delete_post_meta( $post_id, Meta::ITEM_HIDDEN );
		}

		// A save with nothing changed, so the page caches that watch saves
		// drop the venue page and the lists it was on.
		wp_update_post( [ 'ID' => $post_id ] );

		Log::record(
			$hidden ? 'hidden' : 'shown',
			'item',
			$post_id,
			Org::for_item( $post_id ),
			$hidden
				? __( 'Hidden from the site by the organisation. Its details are kept; it comes back the moment it is shown again.', 'dgl-platform' )
				: __( 'Shown on the site again by the organisation.', 'dgl-platform' ),
			[],
			$user->user_id
		);

		return true;
	}

	/**
	 * When it was hidden, from the audit trail, or '' when it is not.
	 */
	public static function hidden_since( int $post_id ): string {
		if ( ! self::is_hidden( $post_id ) ) {
			return '';
		}

		foreach ( Log::for_object( 'item', $post_id ) as $row ) {
			if ( 'hidden' === (string) ( $row['action'] ?? '' ) ) {
				return (string) ( $row['logged_at'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * A hidden venue's page is not there, to a visitor.
	 */
	public static function not_found_when_hidden(): void {
		if ( is_admin() || ! is_singular( PostTypes::VENUE ) ) {
			return;
		}

		$post = get_queried_object();

		if ( $post instanceof WP_Post && self::is_hidden( (int) $post->ID ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * The SQL that keeps hidden things out of a raw query: a NOT EXISTS on
	 * the flag, for a posts table alias or a column holding a post id.
	 */
	public static function sql_shown( string $post_id_expr ): string {
		global $wpdb;

		return sprintf(
			"NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} hv WHERE hv.post_id = %s AND hv.meta_key = '%s' AND hv.meta_value = '1')",
			$post_id_expr,
			Meta::ITEM_HIDDEN
		);
	}
}

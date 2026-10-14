<?php
/**
 * Old page addresses keep working after a page is renamed.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress remembers a post's old slug and sends its old address on to
 * the new one, but deliberately not a page's: `wp_check_for_changed_slugs`
 * skips hierarchical types, and `wp_old_slug_redirect` only looks at the
 * `name` query var, which a page address never sets. So a renamed page
 * simply 404s at its old address, and every link to it out in the world
 * breaks.
 *
 * This does the same for pages, with the same meta key: a published page
 * renamed while published gains an `_wp_old_slug` row, and a request that
 * 404s on that slug is sent on, permanently, to the page as it is now. An
 * old slug claimed by two pages is ambiguous and goes nowhere.
 *
 * A rename done where plugins are not loaded (the host's WP-CLI console)
 * records nothing on its own; add the row by hand:
 *
 *   wp post meta add <page id> _wp_old_slug <old-slug>
 */
final class PageRedirects {

	public const META = '_wp_old_slug';

	public static function init(): void {
		// Before redirect_canonical (10), which would otherwise guess.
		add_action( 'template_redirect', [ self::class, 'redirect' ], 5 );
		add_action( 'post_updated', [ self::class, 'remember' ], 10, 3 );
	}

	/**
	 * Send a 404 on a page's old slug to the page.
	 */
	public static function redirect(): void {
		if ( ! is_404() ) {
			return;
		}

		$path   = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reduced to one sanitised slug below.
		$target = self::target( self::slug_from_path( $path ) );

		if ( '' !== $target ) {
			wp_safe_redirect( $target, 301, 'DGLP' );
			exit;
		}
	}

	/**
	 * The last segment of a path, as a slug, or ''. Pure.
	 */
	public static function slug_from_path( string $path ): string {
		$segments = array_values( array_filter( explode( '/', $path ), static fn( string $s ): bool => '' !== $s ) );

		if ( [] === $segments ) {
			return '';
		}

		return sanitize_title( rawurldecode( (string) end( $segments ) ) );
	}

	/**
	 * Where a page's old slug now lives, or '' when no one page claims it.
	 */
	public static function target( string $slug ): string {
		if ( '' === $slug ) {
			return '';
		}

		$ids = get_posts(
			[
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'meta_key'         => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery -- only on a 404.
				'meta_value'       => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery -- only on a 404.
				'fields'           => 'ids',
				'numberposts'      => 2,
				'suppress_filters' => true,
			]
		);

		return 1 === count( $ids ) ? (string) get_permalink( (int) $ids[0] ) : '';
	}

	/**
	 * Keep a published page's previous slug when it is renamed, as WordPress
	 * does for posts. The new slug is never kept as an old one.
	 */
	public static function remember( int $post_id, WP_Post $after, WP_Post $before ): void {
		if ( 'page' !== $after->post_type || 'publish' !== $after->post_status || 'publish' !== $before->post_status ) {
			return;
		}

		if ( '' === $before->post_name || $before->post_name === $after->post_name ) {
			return;
		}

		if ( ! in_array( $before->post_name, (array) get_post_meta( $post_id, self::META ), true ) ) {
			add_post_meta( $post_id, self::META, $before->post_name );
		}

		delete_post_meta( $post_id, self::META, $after->post_name );
	}
}

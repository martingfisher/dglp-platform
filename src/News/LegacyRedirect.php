<?php
/**
 * Old addresses keep working.
 *
 * The old site's permalinks were /category/story-name/. A converted story
 * lives at /news/story-name/. WordPress's own 404 guess usually finds it by
 * name, but that guess is filterable and some plugins switch it off, so this
 * is explicit: a 404 whose last path segment names a converted, live story is
 * sent to the story with a permanent redirect.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\News;

defined( 'ABSPATH' ) || exit;

final class LegacyRedirect {

	public static function init(): void {
		add_action( 'template_redirect', [ self::class, 'maybe' ], 1 );
	}

	/** The slug an old address points at, or '' when there is none. */
	public static function slug_from_path( string $path ): string {
		$parts = array_values( array_filter( explode( '/', trim( (string) wp_parse_url( $path, PHP_URL_PATH ), '/' ) ) ) );

		if ( [] === $parts ) {
			return '';
		}

		$last = sanitize_title( (string) end( $parts ) );

		return $last;
	}

	public static function maybe(): void {
		if ( ! is_404() ) {
			return;
		}

		$slug = self::slug_from_path( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised inside.

		if ( '' === $slug ) {
			return;
		}

		// A converted story at its new address, else the kept copy of an
		// archived duplicate whose address this was.
		$post_id = LegacyImport::live_by_slug( $slug ) ?? LegacyImport::duplicate_target_by_slug( $slug );

		if ( null === $post_id ) {
			return;
		}

		$target = (string) get_permalink( $post_id );

		if ( '' === $target ) {
			return;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}
}

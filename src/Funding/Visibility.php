<?php
/**
 * One switch for whether funding is shown to the public.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Funding;

use DGL\PostTypes;
use DGL\Taxonomies;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Funding was built and filled before DGLP had signed it off, so it can be
 * held back from the public and switched on at a moment's notice:
 *
 *     wp dgl funding public off    (or: wp option update dgl_funding_public 0)
 *     wp dgl funding public on
 *
 * While it is off:
 * - /grants/ and every fund's page answer 404 to the public;
 * - the home page and support page funding blocks, and any funding feed,
 *   draw nothing;
 * - funds are left out of topic pages, search, organisation pages, the
 *   sitemaps and the digest, and the digest choices do not offer them.
 *
 * The weekly import keeps running, so the list is current the moment it goes
 * on. Signed-in site administrators still see all of it, marked as a
 * preview, so it can be checked before the switch; those pages are
 * noindex.
 */
final class Visibility {

	public const OPTION = 'dgl_funding_public';

	public static function init(): void {
		add_action( 'template_redirect', [ self::class, 'gate' ], 1 );
		add_action( 'pre_get_posts', [ self::class, 'leave_out' ] );
		add_filter( 'wp_robots', [ self::class, 'robots' ] );
		add_filter( 'wp_sitemaps_post_types', [ self::class, 'core_sitemap' ] );
		add_filter( 'rank_math/sitemap/exclude_post_type', [ self::class, 'rank_math_sitemap' ], 10, 2 );
		add_filter( 'render_block', [ self::class, 'held_block' ], 10, 2 );
	}

	/** A block given this class in the editor is held back with funding. */
	public const LAUNCH_CLASS = 'dgl-funding-launch';

	/**
	 * Any block carrying LAUNCH_CLASS (Advanced > Additional CSS class in the
	 * editor) draws nothing until funding is switched on, except for
	 * administrators. So the parts of a page that go live with funding (the
	 * topic feeds on the funding support page, say) go live with it.
	 *
	 * @param array<string, mixed> $block
	 */
	public static function held_block( string $html, array $block ): string {
		$class = (string) ( $block['attrs']['className'] ?? '' );

		if ( '' === $class || ! in_array( self::LAUNCH_CLASS, preg_split( '/\s+/', $class ) ?: [], true ) ) {
			return $html;
		}

		return self::shown() ? $html : '';
	}

	/** Whether the public sees funding. On unless switched off. */
	public static function is_on(): bool {
		return '0' !== (string) get_option( self::OPTION, '1' );
	}

	/** Whether this visitor may see it anyway: site administrators, as a preview (Martin, 13 November 2026). */
	public static function can_preview(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/** Whether funding is shown to whoever is looking. */
	public static function shown(): bool {
		return self::is_on() || self::can_preview();
	}

	/**
	 * Whether this type is held back from whoever is looking. Only funding
	 * ever is. With $strict, a preview does not count: for what goes out to
	 * other people (digests, sitemaps).
	 */
	public static function hides( string $post_type, bool $strict = false ): bool {
		return PostTypes::GRANT === $post_type && ! ( $strict ? self::is_on() : self::shown() );
	}

	/**
	 * These types, without funding when it is held back.
	 *
	 * @param string[] $types
	 * @return string[]
	 */
	public static function filter_types( array $types, bool $strict = false ): array {
		return array_values( array_filter( $types, static fn( string $t ): bool => ! self::hides( $t, $strict ) ) );
	}

	/**
	 * The line an administrator sees on anything funding while it is held
	 * back, so a preview is never mistaken for the live site.
	 */
	public static function preview_note(): string {
		if ( self::is_on() || ! self::can_preview() ) {
			return '';
		}

		return '<p class="dgl-pub__passed dgl-funding-preview" role="note"><strong>' . esc_html__( 'Preview for administrators.', 'dgl-platform' ) . '</strong> '
			. esc_html__( 'Funding is hidden from the public until it is switched on (wp dgl funding public on).', 'dgl-platform' ) . '</p>';
	}

	/** The list and the funds' own pages: not found, to the public. */
	public static function gate(): void {
		if ( self::shown() ) {
			return;
		}

		if ( is_post_type_archive( PostTypes::GRANT ) || is_singular( PostTypes::GRANT ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/** Topic pages, search and the like: no funds while it is held back. */
	public static function leave_out( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || self::shown() ) {
			return;
		}

		if ( ! $query->is_tax( Taxonomies::TOPIC ) && ! $query->is_search() && ! $query->is_author() && ! $query->is_date() ) {
			return;
		}

		$asked = $query->get( 'post_type' );
		$types = '' === $asked || 'any' === $asked || [] === $asked
			? array_values( get_post_types( [ 'public' => true ] ) )
			: (array) $asked;

		$query->set( 'post_type', self::filter_types( array_map( 'strval', $types ) ) );
	}

	/**
	 * @param array<string, mixed> $robots
	 * @return array<string, mixed>
	 */
	public static function robots( array $robots ): array {
		if ( ! self::is_on() && ( is_post_type_archive( PostTypes::GRANT ) || is_singular( PostTypes::GRANT ) ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}

	/**
	 * @param array<string, mixed> $types
	 * @return array<string, mixed>
	 */
	public static function core_sitemap( array $types ): array {
		if ( ! self::is_on() ) {
			unset( $types[ PostTypes::GRANT ] );
		}

		return $types;
	}

	/** @param mixed $exclude */
	public static function rank_math_sitemap( $exclude, string $type ): bool {
		return PostTypes::GRANT === $type && ! self::is_on() ? true : (bool) $exclude;
	}
}

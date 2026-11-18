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
 * on. Only the people listed in SCREEN_USERS_OPTION (Martin) still see it,
 * marked as a preview, so it can be checked before the switch; those pages
 * are noindex. Everyone else, other administrators included, sees no
 * funding anywhere: wp-admin, settings, the review area or the site
 * (Martin, 16 November 2026).
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
		// Everywhere else funding shows, held back from all but the people listed (Martin, 16 November 2026).
		add_filter( 'register_post_type_args', [ self::class, 'admin_args' ], 10, 2 );
		add_filter( 'map_meta_cap', [ self::class, 'deny_caps' ], 20, 4 );
		add_filter( 'dgl_items_hidden_types', [ self::class, 'items_hidden' ] );
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

	/**
	 * Whether this visitor may see it anyway, as a preview. Was every site
	 * administrator (13 November 2026); since 16 November only the people
	 * listed in SCREEN_USERS_OPTION (Martin).
	 */
	public static function can_preview(): bool {
		return is_user_logged_in() && self::screen_open_to( get_current_user_id(), false, self::allowed() );
	}

	/**
	 * The people who still see the review team's Grants and Funding screen
	 * while funding is switched off: user ids, kept in this option. Martin
	 * asked (16 November 2026) for the screen to be hidden from everyone but
	 * him until DGLP approve funding; set with
	 * `wp option update dgl_funding_screen_users '[1]' --format=json`.
	 */
	public const SCREEN_USERS_OPTION = 'dgl_funding_screen_users';

	/**
	 * Whether a user sees the Grants and Funding screen. Once funding is on,
	 * everyone who can review does. While it is off, only the users listed.
	 * Pure.
	 *
	 * @param int[] $allowed
	 */
	public static function screen_open_to( int $user_id, bool $is_on, array $allowed ): bool {
		if ( $is_on ) {
			return true;
		}

		return $user_id > 0 && in_array( $user_id, array_map( 'intval', $allowed ), true );
	}

	/** @return array<int|string> The people listed. */
	private static function allowed(): array {
		$allowed = get_option( self::SCREEN_USERS_OPTION, [] );

		return is_array( $allowed ) ? $allowed : [];
	}

	/** Whether this user sees funding anywhere: the review screen, wp-admin, settings, the queue. */
	public static function screen_visible( int $user_id ): bool {
		return self::screen_open_to( $user_id, self::is_on(), self::allowed() );
	}

	/** Whether funding is held back from whoever is signed in here (or nobody is). */
	public static function hidden_here(): bool {
		return ! self::screen_visible( get_current_user_id() );
	}

	/**
	 * The funding post type's admin settings for someone it is held back
	 * from: no wp-admin list or edit screen (core refuses edit.php and
	 * post.php for a type without show_ui), no menu, no "New" in the admin
	 * bar, no REST route. Pure.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function held_admin_args( array $args, bool $hidden ): array {
		if ( ! $hidden ) {
			return $args;
		}

		foreach ( [ 'show_ui', 'show_in_menu', 'show_in_admin_bar', 'show_in_nav_menus', 'show_in_rest' ] as $key ) {
			$args[ $key ] = false;
		}

		return $args;
	}

	/**
	 * Hooked on register_post_type_args.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function admin_args( array $args, string $post_type ): array {
		return PostTypes::GRANT === $post_type ? self::held_admin_args( $args, self::hidden_here() ) : $args;
	}

	/** The capabilities on a single post that are refused on funding for anyone it is held back from. */
	private const HELD_CAPS = [ 'edit_post', 'delete_post', 'read_post', 'publish_post', 'edit_dgl_item', 'delete_dgl_item', 'read_dgl_item' ];

	/**
	 * Whether a capability on a post is refused because it is funding held
	 * back from this user. Never for nobody signed in (the import and cron
	 * run as no one and never ask). Pure.
	 *
	 * @param array<int|string> $allowed
	 */
	public static function blocks_cap( string $cap, string $post_type, int $user_id, bool $is_on, array $allowed ): bool {
		return PostTypes::GRANT === $post_type
			&& $user_id > 0
			&& in_array( $cap, self::HELD_CAPS, true )
			&& ! self::screen_open_to( $user_id, $is_on, $allowed );
	}

	/**
	 * Hooked on map_meta_cap after the plugin's own policy, so an
	 * administrator not listed cannot edit, delete or read a fund through
	 * wp-admin, the REST API or admin-ajax while funding is off.
	 *
	 * @param string[] $caps
	 * @param mixed[]  $args
	 * @return string[]
	 */
	public static function deny_caps( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, self::HELD_CAPS, true ) || ! isset( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( (int) $args[0] );

		if ( null === $post || ! self::blocks_cap( $cap, (string) $post->post_type, $user_id, self::is_on(), self::allowed() ) ) {
			return $caps;
		}

		return [ 'do_not_allow' ];
	}

	/**
	 * PostTypes::enabled(), without funding for anyone it is held back from.
	 * For the review team's reports and their downloads.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function enabled_here(): array {
		$types = PostTypes::enabled();

		if ( self::hidden_here() ) {
			unset( $types[ PostTypes::GRANT ] );
		}

		return $types;
	}

	/**
	 * Hooked on dgl_items_hidden_types: the review queue, its count, the
	 * Approved list and organisation lists leave funding out for anyone it
	 * is held back from.
	 *
	 * @param string[] $types
	 * @return string[]
	 */
	public static function items_hidden( array $types ): array {
		return self::hidden_here() ? array_merge( $types, [ PostTypes::GRANT ] ) : $types;
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

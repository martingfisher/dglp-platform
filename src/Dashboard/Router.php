<?php
/**
 * Front-end routing for the member area.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * One rewrite rule, one query var, and a path parsed in PHP.
 *
 * A rule per screen would mean a rewrite flush every time a screen is added,
 * and rewrite flushes are the classic way a WordPress site quietly 404s after a
 * deploy. One catch-all rule means the routing table never changes again.
 */
final class Router {

	public const QUERY_VAR = 'dgl_route';

	/** Default base. Filterable so the slug is not baked into the code. */
	public const DEFAULT_BASE = 'dashboard';

	public static function init(): void {
		add_action( 'init', [ self::class, 'add_rules' ] );
		add_filter( 'query_vars', [ self::class, 'add_query_var' ] );
		add_action( 'template_redirect', [ self::class, 'dispatch' ] );
	}

	/**
	 * The URL base the member area lives under.
	 */
	public static function base(): string {
		/**
		 * Filters the member area's URL base.
		 *
		 * @param string $base Defaults to "dashboard".
		 */
		return (string) apply_filters( 'dgl_dashboard_base', self::DEFAULT_BASE );
	}

	public static function add_rules(): void {
		add_rewrite_rule(
			'^' . self::base() . '/?(.*)$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public static function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Whether the current request is for the member area.
	 */
	public static function is_dashboard(): bool {
		return null !== get_query_var( self::QUERY_VAR, null )
			&& '' !== get_query_var( self::QUERY_VAR, '' )
			|| self::is_dashboard_root();
	}

	private static function is_dashboard_root(): bool {
		global $wp;

		return isset( $wp->request ) && rtrim( (string) $wp->request, '/' ) === self::base();
	}

	/**
	 * The path inside the member area, as segments.
	 *
	 * @return string[]
	 */
	public static function segments(): array {
		$path = (string) get_query_var( self::QUERY_VAR, '' );
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return [];
		}

		return array_values( array_filter( explode( '/', $path ), static fn( string $s ): bool => '' !== $s ) );
	}

	/**
	 * Build a URL inside the member area.
	 */
	public static function url( string ...$segments ): string {
		$path = self::base();

		foreach ( $segments as $segment ) {
			$path .= '/' . rawurlencode( trim( $segment, '/' ) );
		}

		return home_url( user_trailingslashit( $path ) );
	}

	/**
	 * Hand the request to the controller.
	 */
	public static function dispatch(): void {
		if ( ! self::is_dashboard() ) {
			return;
		}

		// The member area is never a search result and never cached as a page.
		nocache_headers();

		status_header( 200 );

		Controller::handle( self::segments() );
		exit;
	}
}

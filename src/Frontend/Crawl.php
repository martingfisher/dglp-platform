<?php
/**
 * What search engines may crawl and index outside the posts WordPress knows.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use DGL\Dashboard\Router;
use DGL\Events\Calendar;
use DGL\Org\Directory;
use DGL\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Two gaps an SEO plugin cannot see.
 *
 * The member area is a set of virtual routes. Sign-in, join, invitation and
 * unsubscribe screens are public by design, and nothing marked them
 * `noindex`, so the day "Discourage search engines" is unticked they become
 * indexable. They now say noindex in the page, in a header and in robots.txt.
 *
 * The directory, its organisation pages, the calendar and the listing pages
 * are also virtual or archive routes, which neither core's sitemap nor Rank
 * Math lists. The plugin serves its own sitemap for them at
 * /dgl-sitemap.xml and names it in robots.txt, so it works whichever SEO
 * plugin is on. Submit it once in Search Console.
 */
final class Crawl {

	public const QUERY_VAR = 'dgl_sitemap';

	public const PATH = 'dgl-sitemap.xml';

	/** The protocol's limit for one file. */
	public const MAX_URLS = 50000;

	public static function init(): void {
		add_filter( 'wp_robots', [ self::class, 'robots' ], 20 );
		add_action( 'send_headers', [ self::class, 'header' ] );
		add_filter( 'robots_txt', [ self::class, 'robots_txt' ], 20 );
		add_action( 'init', [ self::class, 'add_rules' ] );
		add_filter( 'query_vars', [ self::class, 'add_query_var' ] );
		add_action( 'template_redirect', [ self::class, 'serve' ], 1 );
	}

	/* ---- The member area stays out ------------------------------------- */

	/**
	 * @param array<string, mixed> $robots
	 * @return array<string, mixed>
	 */
	public static function robots( array $robots ): array {
		if ( ! is_admin() && Router::is_dashboard() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
			unset( $robots['index'], $robots['follow'], $robots['max-image-preview'] );
		}

		return $robots;
	}

	/** The same instruction as a header, for anything that is not HTML. */
	public static function header(): void {
		if ( ! is_admin() && Router::is_dashboard() && ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
	}

	/**
	 * Lines to add to the virtual robots.txt: keep crawlers out of the member
	 * area and say where the sitemap is. Pure.
	 */
	public static function robots_txt_extra( string $output, string $dashboard_base, string $sitemap_url ): string {
		$extra = '';
		$rule  = 'Disallow: /' . trim( $dashboard_base, '/' ) . '/';

		if ( ! str_contains( $output, $rule ) ) {
			$extra .= $rule . "\n";
		}

		$line = 'Sitemap: ' . $sitemap_url;

		if ( ! str_contains( $output, $line ) ) {
			$extra .= $line . "\n";
		}

		if ( '' === $extra ) {
			return $output;
		}

		/*
		 * A Disallow only means something under a User-agent group. WordPress
		 * writes `User-agent: *` first; when it is missing, say so ourselves.
		 */
		$group = str_contains( strtolower( $output ), 'user-agent:' ) ? '' : "User-agent: *\n";

		return rtrim( $output ) . "\n\n" . $group . $extra;
	}

	public static function robots_txt( mixed $output ): mixed {
		if ( ! is_string( $output ) || '0' === (string) get_option( 'blog_public' ) ) {
			return $output;
		}

		return self::robots_txt_extra( $output, Router::base(), home_url( '/' . self::PATH ) );
	}

	/* ---- The sitemap ----------------------------------------------------- */

	public static function add_rules(): void {
		add_rewrite_rule( '^' . preg_quote( self::PATH, '~' ) . '$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public static function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	public static function serve(): void {
		if ( '1' !== (string) get_query_var( self::QUERY_VAR, '' ) ) {
			return;
		}

		global $wp_query;

		$wp_query->is_404 = false;
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );

		echo self::xml( self::entries() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in xml().
		exit;
	}

	/**
	 * Every address the plugin owns that is worth indexing.
	 *
	 * @return array<int, array{loc:string, lastmod:string}>
	 */
	public static function entries(): array {
		$urls = [
			[ Frontend::archive_url( PostTypes::VENUE ), '' ],
			[ home_url( '/' . Directory::BASE . '/' ), '' ],
			[ Calendar::url(), '' ],
		];

		foreach ( PostTypes::enabled_keys() as $type ) {
			$urls[] = [ Frontend::archive_url( $type ), '' ];
		}

		$orgs = get_posts(
			[
				'post_type'      => PostTypes::ORG,
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_URLS,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);

		foreach ( $orgs as $id ) {
			if ( Directory::is_listed( (int) $id ) ) {
				$urls[] = [ \DGL\Org\DirectoryQuery::url( (int) $id ), (string) get_post_modified_time( 'c', true, (int) $id ) ];
			}
		}

		return self::normalise( $urls );
	}

	/**
	 * Drop empty and repeated addresses and cap the list. Pure.
	 *
	 * @param array<int, array{0:string, 1:string}> $urls [address, last modified] pairs.
	 * @return array<int, array{loc:string, lastmod:string}>
	 */
	public static function normalise( array $urls ): array {
		$seen = [];
		$out  = [];

		foreach ( $urls as [ $loc, $lastmod ] ) {
			if ( '' === $loc || isset( $seen[ $loc ] ) ) {
				continue;
			}

			$seen[ $loc ] = true;
			$out[]        = [ 'loc' => $loc, 'lastmod' => $lastmod ];

			if ( count( $out ) >= self::MAX_URLS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * The sitemap document. Pure.
	 *
	 * @param array<int, array{loc:string, lastmod:string}> $entries
	 */
	public static function xml( array $entries ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $entries as $entry ) {
			$xml .= "\t<url><loc>" . htmlspecialchars( $entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</loc>';

			if ( '' !== $entry['lastmod'] ) {
				$xml .= '<lastmod>' . htmlspecialchars( $entry['lastmod'], ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</lastmod>';
			}

			$xml .= "</url>\n";
		}

		return $xml . "</urlset>\n";
	}
}

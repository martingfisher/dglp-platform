<?php
/**
 * The home page sections as shortcodes, for a page built in the theme.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use DGL\Dashboard\Assets;
use DGL\Dashboard\View;
use DGL\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * The live parts of the home page are the only parts that need code: the
 * next events, the newest stories, the next courses, the funding, the
 * directory search and the join call to action. Each is a shortcode, so
 * the team builds the front page in the block editor with their own hero,
 * copy, photos and testimonials, and drops these in wherever they belong:
 *
 *   [dgl_home_events count="6" heading="What's on" link="yes"]
 *   [dgl_home_news count="5" heading="Latest news"]
 *   [dgl_home_training count="3"]
 *   [dgl_home_funding]
 *   [dgl_home_directory areas="6"]
 *   [dgl_home_roundup heading="Get the weekly round-up" text="…"]
 *
 * `heading=""` drops the heading so the page can supply its own;
 * `link="no"` drops the "All events" link. The stylesheet loads only on a
 * page that uses one of these.
 */
final class HomeBlocks {

	/** The shortcode tags, by section. */
	public const TAGS = [
		'events'    => 'dgl_home_events',
		'news'      => 'dgl_home_news',
		'training'  => 'dgl_home_training',
		'funding'   => 'dgl_home_funding',
		'directory' => 'dgl_home_directory',
		'roundup'   => 'dgl_home_roundup',
	];

	/** The most rows one list will show, whatever `count` says. */
	public const MAX_COUNT = 24;

	public static function init(): void {
		foreach ( self::TAGS as $section => $tag ) {
			add_shortcode( $tag, static fn( $atts ): string => self::render( $section, is_array( $atts ) ? $atts : [] ) );
		}
	}

	/**
	 * One section, wrapped so the styles apply outside the plugin's own pages.
	 *
	 * @param array<string, mixed> $atts
	 */
	public static function render( string $section, array $atts ): string {
		$html = self::section( $section, $atts );

		if ( '' === $html ) {
			return '';
		}

		self::assets();

		return '<div class="dgl-home-block dgl-home-block--' . esc_attr( $section ) . '">' . $html . '</div>';
	}

	/**
	 * The section's own markup, unwrapped. The sample page composes these.
	 *
	 * @param array<string, mixed> $atts
	 */
	public static function section( string $section, array $atts = [] ): string {
		$id = 'dgl-home-' . $section;

		switch ( $section ) {
			case 'events':
				return self::rows( PostTypes::EVENT, $atts, Home::EVENTS, __( "What's on", 'dgl-platform' ), __( 'All events', 'dgl-platform' ), $id );

			case 'training':
				return self::rows( PostTypes::TRAINING, $atts, Home::TRAINING, __( 'Training and learning', 'dgl-platform' ), __( 'All training', 'dgl-platform' ), $id );

			case 'funding':
				return self::rows( PostTypes::GRANT, $atts, Home::GRANTS, __( 'Funding and grants', 'dgl-platform' ), __( 'All funding', 'dgl-platform' ), $id );

			case 'news':
				$a = self::atts( $atts, [ 'count' => Home::NEWS, 'heading' => __( 'Latest news', 'dgl-platform' ), 'link' => 'yes' ] );

				return View::render(
					'public/home/news',
					[
						'items'     => Home::newest( PostTypes::NEWS, $a['count'] ),
						'heading'   => $a['heading'],
						'all_url'   => $a['link'] ? Frontend::archive_url( PostTypes::NEWS ) : '',
						'all_label' => __( 'All news', 'dgl-platform' ),
						'id'        => $id,
					]
				);

			case 'directory':
				$a = self::atts( $atts, [ 'heading' => __( 'Find an organisation', 'dgl-platform' ), 'areas' => Home::TOPICS ] );

				return View::render(
					'public/home/directory',
					[
						'heading'   => $a['heading'],
						'org_total' => (int) \DGL\Org\DirectoryQuery::run( [ 'q' => '', 'page' => 1, 'filters' => [] ] )['total'],
						'dir_url'   => home_url( '/' . \DGL\Org\Directory::BASE . '/' ),
						'areas'     => array_slice( \DGL\Org\Options::specialism(), 0, max( 0, min( self::MAX_COUNT, (int) $a['areas'] ) ), true ),
						'id'        => $id,
					]
				);

			case 'roundup':
				$a = self::atts(
					$atts,
					[
						'heading' => __( 'Get the weekly round-up', 'dgl-platform' ),
						'text'    => __( 'Join the partnership and every Tuesday morning we send you what is new: news, events, training and funding from across Leeds. Pick what you want, or stop it any time from the email.', 'dgl-platform' ),
					]
				);

				return View::render(
					'public/home/roundup',
					[
						'heading'    => $a['heading'],
						'text'       => $a['text'],
						'join_url'   => \DGL\Dashboard\Router::url( 'join' ),
						'signin_url' => \DGL\Dashboard\Router::url(),
						'id'         => $id,
					]
				);
		}

		return '';
	}

	/**
	 * A dated list: events, training or funding.
	 *
	 * @param array<string, mixed> $atts
	 */
	private static function rows( string $post_type, array $atts, int $default_count, string $default_heading, string $all_label, string $id ): string {
		$a  = self::atts( $atts, [ 'count' => $default_count, 'heading' => $default_heading, 'link' => 'yes' ] );
		$on = PostTypes::is_enabled( $post_type );

		return View::render(
			'public/home/rows',
			[
				'items'       => $on ? Home::upcoming( $post_type, $a['count'] ) : [],
				'heading'     => $a['heading'],
				'all_url'     => $on && $a['link'] ? Frontend::archive_url( $post_type ) : '',
				'all_label'   => $all_label,
				'id'          => $id,
				'placeholder' => $on ? '' : sprintf(
					/* translators: %s: the section's plural name, e.g. "Grants". */
					__( 'This will list live %s, soonest closing first, once that section is switched on.', 'dgl-platform' ),
					strtolower( Frontend::type_label( $post_type, true ) )
				),
			]
		);
	}

	/**
	 * Clean the attributes: count capped, link a yes/no, text plain.
	 *
	 * @param array<string, mixed> $atts
	 * @param array<string, mixed> $defaults
	 * @return array<string, mixed>
	 */
	private static function atts( array $atts, array $defaults ): array {
		$a = shortcode_atts( $defaults, array_change_key_case( $atts, CASE_LOWER ) );

		if ( isset( $a['count'] ) ) {
			$a['count'] = max( 1, min( self::MAX_COUNT, (int) $a['count'] ) );
		}

		if ( isset( $a['link'] ) ) {
			$a['link'] = ! in_array( strtolower( trim( (string) $a['link'] ) ), [ 'no', '0', 'false', 'off' ], true );
		}

		foreach ( [ 'heading', 'text' ] as $key ) {
			if ( isset( $a[ $key ] ) ) {
				$a[ $key ] = trim( wp_strip_all_tags( (string) $a[ $key ] ) );
			}
		}

		return $a;
	}

	/** The public stylesheet, once, for a page that is not otherwise ours. */
	private static function assets(): void {
		if ( wp_style_is( 'dgl-public', 'enqueued' ) ) {
			return;
		}

		Assets::style( 'assets/public.css', 'dgl-public' );
	}
}

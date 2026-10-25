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
 *   [dgl_home_directory chips="6"]   (or chips="age_older_people,circ_carers" to name them; areas= still accepted)
 *   [dgl_home_roundup heading="Get the weekly round-up" text="…"]
 *   [dgl_val_jobs layout="carousel" count="12"]
 *   [dgl_val_jobs layout="feed" count="6"]
 *   [dgl_val_jobs layout="feed" count="100"]   (the jobs page: every vacancy)
 *   [dgl_feed type="news" topic="children-and-young-people"]   (see Feed)
 *   [dgl_padlet]   (the health and wellbeing board; group="mental" for one group)
 *
 * `heading=""` drops the heading so the page can supply its own;
 * `link="no"` drops the "All events" link. The stylesheet loads only on a
 * page that uses one of these.
 *
 * The jobs block is the one that is not ours: it lists the vacancies
 * Voluntary Action Leeds advertises, fetched overnight by ValJobs, and
 * every job links to its advert on VAL's site.
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
		'jobs'      => 'dgl_val_jobs',
		'feed'      => Feed::TAG,
		'padlet'    => 'dgl_padlet',
	];

	/** The most rows one list will show, whatever `count` says. */
	public const MAX_COUNT = 24;

	/**
	 * The "Browse by who they work with" chips the directory block shows
	 * when it is only told how many: the questions visitors most often
	 * arrive with, in this order.
	 */
	public const DIRECTORY_CHIPS = [
		'age_older_people',
		'age_children_under_18',
		'age_young_people_16_25',
		'circ_carers',
		'people_from_culturally_diverse_communities',
		'circ_refugees_asylum_seekers_and_migrants',
		'dementia',
		'long_term_chronic_health_conditions',
	];

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
				return self::rows( PostTypes::EVENT, $atts, Home::EVENTS, __( "What's on", 'dgl-platform' ), __( 'View all events', 'dgl-platform' ), $id );

			case 'training':
				return self::rows( PostTypes::TRAINING, $atts, Home::TRAINING, __( 'Training and learning', 'dgl-platform' ), __( 'View all training', 'dgl-platform' ), $id );

			case 'funding':
				return self::rows( PostTypes::GRANT, $atts, Home::GRANTS, __( 'Funding and grants', 'dgl-platform' ), __( 'View all funding', 'dgl-platform' ), $id );

			case 'news':
				$a = self::atts( $atts, [ 'count' => Home::NEWS, 'heading' => __( 'Latest news', 'dgl-platform' ), 'link' => 'yes' ] );

				return View::render(
					'public/home/news',
					[
						'items'     => Home::newest( PostTypes::NEWS, $a['count'] ),
						'heading'   => $a['heading'],
						'all_url'   => $a['link'] ? Frontend::archive_url( PostTypes::NEWS ) : '',
						'all_label' => __( 'View all news', 'dgl-platform' ),
						'id'        => $id,
					]
				);

			case 'directory':
				// `areas` was the attribute until 0.50.0, when the chips moved from areas of work to who they work with.
				if ( isset( $atts['areas'] ) && ! isset( $atts['chips'] ) ) {
					$atts['chips'] = $atts['areas'];
				}

				$a = self::atts( $atts, [ 'heading' => __( 'Find an organisation', 'dgl-platform' ), 'chips' => Home::TOPICS ] );

				return View::render(
					'public/home/directory',
					[
						'heading'   => $a['heading'],
						'org_total' => (int) \DGL\Org\DirectoryQuery::run( [ 'q' => '', 'page' => 1, 'filters' => [] ] )['total'],
						'dir_url'   => home_url( '/' . \DGL\Org\Directory::BASE . '/' ),
						'chips'     => self::directory_chips( (string) $a['chips'] ),
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

			case 'jobs':
				return self::jobs( $atts, $id );

			case 'feed':
				// Feed gives each block its own id: a page carries several.
				return Feed::render( $atts );

			case 'padlet':
				return self::padlet( $atts, $id );
		}

		return '';
	}

	/**
	 * The health and wellbeing board from Padlet, in its groups. `group`
	 * names one group by its id (Settings, Wellbeing board) to show only
	 * that one; `link="no"` drops the link to the board itself.
	 *
	 * @param array<string, mixed> $atts
	 */
	private static function padlet( array $atts, string $id ): string {
		$a      = self::atts( $atts, [ 'heading' => __( 'Health and wellbeing support', 'dgl-platform' ), 'link' => 'yes', 'group' => '' ] );
		$only   = strtolower( trim( (string) $a['group'] ) );
		$groups = \DGL\Padlet\Board::grouped( \DGL\Padlet\Board::items(), \DGL\Padlet\Board::grouping() );

		if ( '' !== $only ) {
			$groups = array_values( array_filter( $groups, static fn( array $group ): bool => $only === $group['id'] ) );
		}

		return View::render(
			'public/home/padlet',
			[
				'groups'  => $groups,
				'heading' => $a['heading'],
				'all_url' => $a['link'] ? \DGL\Padlet\Board::BOARD_URL : '',
				'fetched' => \DGL\Padlet\Board::fetched(),
				'id'      => $id . ( '' !== $only ? '-' . sanitize_key( $only ) : '' ),
			]
		);
	}

	/** The jobs block's two layouts. */
	public const JOB_LAYOUTS = [ 'carousel', 'feed' ];

	/**
	 * VAL's jobs, as a carousel or a feed.
	 *
	 * @param array<string, mixed> $atts
	 */
	private static function jobs( array $atts, string $id ): string {
		$asked  = strtolower( trim( (string) ( array_change_key_case( $atts, CASE_LOWER )['layout'] ?? '' ) ) );
		$layout = in_array( $asked, self::JOB_LAYOUTS, true ) ? $asked : 'carousel';
		$a      = self::atts(
			$atts,
			[
				'count'   => 'feed' === $layout ? 6 : 12,
				'heading' => __( 'Jobs in the sector', 'dgl-platform' ),
				'link'    => 'yes',
			]
		);
		// The jobs page lists every vacancy, so this block is not held to
		// the home page's cap: as many as VAL lists, up to what is kept.
		$asked_count = array_change_key_case( $atts, CASE_LOWER )['count'] ?? null;
		$count       = null === $asked_count ? $a['count'] : max( 1, min( \DGL\Jobs\ValJobs::MAX_JOBS, (int) $asked_count ) );

		if ( 'carousel' === $layout ) {
			Assets::script( 'assets/jobs-carousel.js', 'dgl-jobs-carousel' );
		}

		return View::render(
			'public/home/jobs',
			[
				'jobs'      => array_slice( \DGL\Jobs\ValJobs::jobs(), 0, $count ),
				'layout'    => $layout,
				'heading'   => $a['heading'],
				'all_url'   => $a['link'] ? \DGL\Jobs\ValJobs::JOBS_URL : '',
				'all_label' => __( 'All jobs on the VAL website', 'dgl-platform' ),
				'fetched'   => \DGL\Jobs\ValJobs::fetched(),
				'id'        => $id . '-' . $layout,
			]
		);
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

	/**
	 * The chips under the directory search box: key => short label, from
	 * "who they work with". A number takes the first so many of
	 * DIRECTORY_CHIPS; a comma list names the keys, in that order. The label
	 * is the item without its group ("Older People", not "Age Groups: Older
	 * People"), because the chip is a button, not a read-out.
	 *
	 * @return array<string, string>
	 */
	private static function directory_chips( string $spec ): array {
		$options = \DGL\Org\Options::service_users();
		$spec    = trim( $spec );

		if ( '' === $spec || ctype_digit( $spec ) ) {
			$keys = array_slice( self::DIRECTORY_CHIPS, 0, max( 0, min( self::MAX_COUNT, '' === $spec ? Home::TOPICS : (int) $spec ) ) );
		} else {
			$keys = array_slice( array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $spec ) ), static fn( string $k ): bool => isset( $options[ $k ] ) ) ) ), 0, self::MAX_COUNT );
		}

		$short = [];

		foreach ( \DGL\Org\Options::grouped( $options ) as $items ) {
			$short += $items;
		}

		$chips = [];

		foreach ( $keys as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$chips[ $key ] = (string) ( $short[ $key ] ?? $options[ $key ] );
			}
		}

		return $chips;
	}
}

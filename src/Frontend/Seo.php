<?php
/**
 * The head of every public page the plugin owns: title, description,
 * canonical, social tags and schema.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use DGL\Events\Cancel;
use DGL\Events\Series;
use DGL\Org\Directory;
use DGL\Org\DirectoryQuery;
use DGL\Org\Org;
use DGL\Org\Profile;
use DGL\PostTypes;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * An SEO plugin knows a post has a title and a picture. It does not know an
 * event has a date and a venue, that a directory entry is an organisation
 * with an address, or that the calendar exists at all. So the plugin
 * describes its own pages.
 *
 * If Rank Math is printing the head, the same values are handed to it
 * through its filters and nothing is printed twice. If it is not, the tags
 * are printed here. Either way the schema is the plugin's.
 *
 * The social image is never missing: the item's own picture, else the
 * organisation's logo, else a card made for it (see OgCard).
 */
final class Seo {

	/** @var array<string, mixed>|null|false What the current page is; false once looked up and found not ours. */
	private static array|null|false $page = null;

	public static function init(): void {
		add_action( 'wp_head', [ self::class, 'head' ], 2 );

		// A deleted item or organisation takes its card with it.
		add_action(
			'deleted_post',
			static function ( int $post_id, ?\WP_Post $post = null ): void {
				if ( $post instanceof \WP_Post && PostTypes::ORG === $post->post_type ) {
					OgCard::forget( 'org-' . $post_id );
				} elseif ( $post instanceof \WP_Post && PostTypes::is_submittable( (string) $post->post_type ) ) {
					OgCard::forget( 'item-' . $post_id );
				}
			},
			10,
			2
		);

		// Rank Math, when it is printing: feed it rather than fight it.
		add_filter( 'rank_math/frontend/title', [ self::class, 'rank_math_title' ] );
		add_filter( 'rank_math/frontend/description', [ self::class, 'rank_math_description' ] );
		add_filter( 'rank_math/frontend/canonical', [ self::class, 'rank_math_canonical' ] );
		add_filter( 'rank_math/frontend/robots', [ self::class, 'rank_math_robots' ] );
		add_filter( 'rank_math/opengraph/facebook/image', [ self::class, 'rank_math_image' ] );
		add_filter( 'rank_math/opengraph/twitter/image', [ self::class, 'rank_math_image' ] );
		add_filter( 'rank_math/opengraph/facebook/og_type', [ self::class, 'rank_math_og_type' ] );
		add_filter( 'rank_math/json_ld', [ self::class, 'rank_math_json_ld' ], 99 );
	}

	/* ---- Printing ------------------------------------------------------ */

	public static function head(): void {
		$page = self::page();

		if ( null === $page ) {
			return;
		}

		echo "\n<!-- DGLP: page description -->\n";

		// Rank Math printed the tags already, fed by the filters below; only the schema is ours to add.
		if ( did_action( 'rank_math/head' ) ) {
			self::print_schema( $page['schema'] );
			echo "<!-- /DGLP -->\n";
			return;
		}

		$tag = static function ( string $attr, string $name, string $content ): void {
			if ( '' !== $content ) {
				printf( '<meta %s="%s" content="%s">' . "\n", esc_attr( $attr ), esc_attr( $name ), esc_attr( $content ) );
			}
		};

		$tag( 'name', 'description', $page['description'] );

		// Core prints noindex for the whole site when search engines are discouraged; never print two.
		if ( 'index' !== $page['robots'] && (int) get_option( 'blog_public' ) === 1 ) {
			$tag( 'name', 'robots', $page['robots'] );
		}

		if ( '' !== $page['url'] ) {
			printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $page['url'] ) );
		}

		$tag( 'property', 'og:locale', 'en_GB' );
		$tag( 'property', 'og:type', $page['og_type'] );
		$tag( 'property', 'og:title', $page['title'] );
		$tag( 'property', 'og:description', $page['description'] );
		$tag( 'property', 'og:url', $page['url'] );
		$tag( 'property', 'og:site_name', (string) get_bloginfo( 'name' ) );

		if ( '' !== $page['image']['url'] ) {
			$tag( 'property', 'og:image', $page['image']['url'] );

			if ( $page['image']['width'] > 0 ) {
				$tag( 'property', 'og:image:width', (string) $page['image']['width'] );
				$tag( 'property', 'og:image:height', (string) $page['image']['height'] );
			}

			$tag( 'property', 'og:image:alt', $page['image']['alt'] );
		}

		if ( 'article' === $page['og_type'] ) {
			$tag( 'property', 'article:published_time', $page['published'] );
			$tag( 'property', 'article:modified_time', $page['modified'] );
		}

		$tag( 'name', 'twitter:card', '' !== $page['image']['url'] ? 'summary_large_image' : 'summary' );
		$tag( 'name', 'twitter:title', $page['title'] );
		$tag( 'name', 'twitter:description', $page['description'] );

		if ( '' !== $page['image']['url'] ) {
			$tag( 'name', 'twitter:image', $page['image']['url'] );
		}

		self::print_schema( $page['schema'] );
		echo "<!-- /DGLP -->\n";
	}

	/**
	 * @param array<int, array<string, mixed>> $entities
	 */
	private static function print_schema( array $entities ): void {
		if ( [] === $entities ) {
			return;
		}

		$graph = [
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $entities ),
		];

		echo '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}

	/* ---- Rank Math bridge (not exercised here; Rank Math is not installed locally) ---- */

	public static function rank_math_title( mixed $title ): mixed {
		$page = self::page();

		return null === $page ? $title : $page['title'];
	}

	public static function rank_math_description( mixed $description ): mixed {
		$page = self::page();

		return null === $page ? $description : $page['description'];
	}

	public static function rank_math_canonical( mixed $url ): mixed {
		$page = self::page();

		return null === $page || '' === $page['url'] ? $url : $page['url'];
	}

	public static function rank_math_robots( mixed $robots ): mixed {
		$page = self::page();

		if ( null === $page || ! is_array( $robots ) ) {
			return $robots;
		}

		return 'index' === $page['robots'] ? $robots : [ 'index' => 'noindex', 'follow' => 'follow' ];
	}

	public static function rank_math_image( mixed $image ): mixed {
		$page = self::page();

		return null === $page || '' === $page['image']['url'] ? $image : $page['image']['url'];
	}

	public static function rank_math_og_type( mixed $type ): mixed {
		$page = self::page();

		return null === $page ? $type : $page['og_type'];
	}

	/**
	 * Rank Math builds its own graph; ours replaces the parts it guessed.
	 *
	 * @param mixed $data Rank Math's entities, keyed by name.
	 */
	public static function rank_math_json_ld( mixed $data ): mixed {
		$page = self::page();

		if ( null === $page || ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $data as $key => $entity ) {
			$type = is_array( $entity ) ? (string) ( $entity['@type'] ?? '' ) : '';

			if ( in_array( $type, [ 'Article', 'NewsArticle', 'BlogPosting', 'BreadcrumbList', 'WebPage', 'CollectionPage', 'Event', 'Organization' ], true ) && 'publisher' !== $key ) {
				unset( $data[ $key ] );
			}
		}

		foreach ( $page['schema'] as $key => $entity ) {
			$data[ 'dgl_' . $key ] = $entity;
		}

		return $data;
	}

	/* ---- What the page is ---------------------------------------------- */

	/**
	 * Everything the head needs for the current request, or null when the
	 * page is not one of ours.
	 *
	 * @return array{kind:string, title:string, description:string, url:string, og_type:string, robots:string, image:array{url:string, width:int, height:int, alt:string}, published:string, modified:string, schema:array<string, array<string, mixed>>}|null
	 */
	public static function page(): ?array {
		if ( null !== self::$page ) {
			return false === self::$page ? null : self::$page;
		}

		$page = self::describe();

		self::$page = null === $page ? false : $page;

		return $page;
	}

	/** For tests: forget the current page. */
	public static function reset(): void {
		self::$page = null;
	}

	private static function describe(): ?array {
		if ( ! Frontend::is_ours() ) {
			return null;
		}

		if ( Frontend::calendar_request() ) {
			return self::for_calendar();
		}

		$directory = Frontend::directory_request();

		if ( null !== $directory ) {
			$org = Frontend::directory_org();

			if ( '1' !== $directory && null === $org ) {
				return null; // A 404 describes itself.
			}

			return null === $org ? self::for_directory() : self::for_org( $org );
		}

		$type = Frontend::single_type();

		if ( null !== $type ) {
			$post = get_queried_object();

			return $post instanceof WP_Post ? self::for_post( $post ) : null;
		}

		$type = Frontend::archive_type();

		if ( null !== $type ) {
			return self::for_archive( $type );
		}

		return null;
	}

	/* ---- Each kind of page ----------------------------------------------- */

	/**
	 * @return array<string, mixed>
	 */
	public static function for_post( WP_Post $post ): array {
		$type   = (string) $post->post_type;
		$org    = Frontend::organisation( $post );
		$title  = self::plain( (string) get_the_title( $post ) );
		$url    = (string) get_permalink( $post );
		$desc   = self::plain( Cards::summary( $post, 30 ) );
		$image  = self::image_for_post( $post );
		$is_doc = PostTypes::NEWS === $type;

		$schema = [
			'main'        => self::schema_for_post( $post, $image, $desc ),
			'breadcrumbs' => self::breadcrumbs(
				[
					[ Frontend::type_label( $type, true ), Frontend::archive_url( $type ) ],
					[ $title, $url ],
				]
			),
		];

		return [
			'kind'        => 'single',
			'title'       => self::plain( wp_get_document_title() ),
			'description' => $desc,
			'url'         => $url,
			'og_type'     => $is_doc ? 'article' : 'website',
			'robots'      => 'index',
			'image'       => $image,
			'published'   => (string) get_post_time( 'c', true, $post ),
			'modified'    => (string) get_post_modified_time( 'c', true, $post ),
			'schema'      => array_filter( $schema ),
			'org'         => $org,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function for_archive( string $type ): array {
		global $wp_query;

		$label  = Frontend::type_label( $type, true );
		$paged  = max( 1, (int) get_query_var( 'paged' ) );
		$base   = Frontend::archive_url( $type );
		$url    = $paged > 1 ? (string) get_pagenum_link( $paged, false ) : $base;
		$filter = [] !== array_filter( Filters::args_from( wp_unslash( $_GET ), $type ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read only, sanitised by Filters.
		$items  = [];

		foreach ( (array) ( $wp_query->posts ?? [] ) as $i => $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = [ (string) get_permalink( $post ), self::plain( (string) get_the_title( $post ) ) ];
			}
		}

		$desc = sprintf(
			/* translators: %s: content type, plural, lower case. */
			__( '%s posted by organisations in the Doing Good Leeds Partnership: charities, community groups and social enterprises across Leeds.', 'dgl-platform' ),
			$label
		);

		return [
			'kind'        => 'archive',
			'title'       => self::plain( wp_get_document_title() ),
			'description' => $desc,
			'url'         => $url,
			'og_type'     => 'website',
			'robots'      => $filter ? 'noindex,follow' : 'index',
			'image'       => self::card( 'list-' . $type, $label, '', '' ),
			'published'   => '',
			'modified'    => '',
			'schema'      => [
				'main'        => self::collection( $label, $url, $desc, $items ),
				'breadcrumbs' => self::breadcrumbs( [ [ $label, $base ] ] ),
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function for_directory(): array {
		$base = home_url( '/' . Directory::BASE . '/' );
		$args = DirectoryQuery::args_from( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read only, sanitised inside.
		$run  = DirectoryQuery::run( $args );
		$name = __( 'Member organisations', 'dgl-platform' );
		$desc = __( 'Charities, community groups and social enterprises in the Doing Good Leeds Partnership: who they are, where they are and what they do. Search by name or by what they do.', 'dgl-platform' );
		$busy = '' !== $args['q'] || [] !== array_filter( $args['filters'] );
		$url  = $args['page'] > 1 ? add_query_arg( 'pg', $args['page'], $base ) : $base;
		$items = [];

		foreach ( $run['ids'] as $id ) {
			$items[] = [ self::org_url( (int) $id ), self::plain( (string) get_the_title( (int) $id ) ) ];
		}

		return [
			'kind'        => 'directory',
			'title'       => self::plain( wp_get_document_title() ),
			'description' => $desc,
			'url'         => $url,
			'og_type'     => 'website',
			'robots'      => $busy ? 'noindex,follow' : 'index',
			'image'       => self::card( 'directory', $name, '', '' ),
			'published'   => '',
			'modified'    => '',
			'schema'      => [
				'main'        => self::collection( $name, $url, $desc, $items ),
				'breadcrumbs' => self::breadcrumbs( [ [ $name, $base ] ] ),
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function for_org( WP_Post $org ): array {
		$id     = (int) $org->ID;
		$values = Profile::values( $id );
		$name   = self::plain( (string) get_the_title( $org ) );
		$url    = self::org_url( $id );
		$desc   = self::plain( (string) wp_trim_words( wp_strip_all_tags( (string) ( $values['org_description'] ?? '' ) ), 30, '…' ) );
		$image  = self::logo_of( $id ) ?? self::card( 'org-' . $id, $name, self::plain( (string) ( $values['org_city'] ?? '' ) ), __( 'Member organisation', 'dgl-platform' ) );

		$entity = [
			'@type'       => 'Organization',
			'@id'         => $url . '#organization',
			'name'        => $name,
			'url'         => $url,
			'description' => $desc,
		];

		if ( '' !== (string) ( $values['org_website'] ?? '' ) ) {
			$entity['sameAs'] = [ (string) $values['org_website'] ];
		}

		if ( '' !== (string) ( $values['org_email'] ?? '' ) ) {
			$entity['email'] = (string) $values['org_email'];
		}

		if ( '' !== (string) ( $values['org_phone'] ?? '' ) ) {
			$entity['telephone'] = (string) $values['org_phone'];
		}

		if ( '' !== $image['url'] ) {
			$entity['logo'] = $image['url'];
		}

		$address = self::postal_address( trim( (string) ( $values['org_address_1'] ?? '' ) . ' ' . (string) ( $values['org_address_2'] ?? '' ) ), (string) ( $values['org_city'] ?? '' ), (string) ( $values['org_postcode'] ?? '' ) );

		if ( null !== $address ) {
			$entity['address'] = $address;
		}

		return [
			'kind'        => 'org',
			'title'       => self::plain( wp_get_document_title() ),
			'description' => '' !== $desc ? $desc : sprintf(
				/* translators: %s: organisation. */
				__( '%s, a member of the Doing Good Leeds Partnership.', 'dgl-platform' ),
				$name
			),
			'url'         => $url,
			'og_type'     => 'website',
			'robots'      => 'index',
			'image'       => $image,
			'published'   => '',
			'modified'    => '',
			'schema'      => [
				'main'        => $entity,
				'breadcrumbs' => self::breadcrumbs(
					[
						[ __( 'Member organisations', 'dgl-platform' ), home_url( '/' . Directory::BASE . '/' ) ],
						[ $name, $url ],
					]
				),
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function for_calendar(): array {
		$name = __( 'Events calendar', 'dgl-platform' );
		$url  = \DGL\Events\Calendar::url();
		$desc = __( 'Events run by organisations in the Doing Good Leeds Partnership, day by day.', 'dgl-platform' );

		return [
			'kind'        => 'calendar',
			'title'       => self::plain( wp_get_document_title() ),
			'description' => $desc,
			'url'         => $url,
			'og_type'     => 'website',
			'robots'      => isset( $_GET['from'] ) ? 'noindex,follow' : 'index', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'image'       => self::card( 'calendar', $name, '', '' ),
			'published'   => '',
			'modified'    => '',
			'schema'      => [
				'main'        => self::collection( $name, $url, $desc, [] ),
				'breadcrumbs' => self::breadcrumbs(
					[
						[ Frontend::type_label( PostTypes::EVENT, true ), Frontend::archive_url( PostTypes::EVENT ) ],
						[ $name, $url ],
					]
				),
			],
		];
	}

	/* ---- Schema for one item --------------------------------------------- */

	/**
	 * The structured data for a news story, an event or a training listing.
	 *
	 * @param array{url:string, width:int, height:int, alt:string} $image
	 * @return array<string, mixed>
	 */
	public static function schema_for_post( WP_Post $post, array $image, string $description ): array {
		$type = (string) $post->post_type;
		$org  = Frontend::organisation( $post );
		$url  = (string) get_permalink( $post );
		$name = self::plain( (string) get_the_title( $post ) );

		$organiser = [
			'@type' => 'Organization',
			'name'  => '' !== $org['name'] ? $org['name'] : (string) get_bloginfo( 'name' ),
		];

		if ( $org['id'] > 0 && Directory::is_listed( $org['id'] ) ) {
			$organiser['url'] = self::org_url( $org['id'] );
		}

		$common = [
			'name'        => $name,
			'description' => $description,
			'url'         => $url,
		];

		if ( '' !== $image['url'] ) {
			$common['image'] = [ $image['url'] ];
		}

		if ( PostTypes::NEWS === $type ) {
			return $common + [
				'@type'            => 'NewsArticle',
				'headline'         => $name,
				'datePublished'    => (string) get_post_time( 'c', true, $post ),
				'dateModified'     => (string) get_post_modified_time( 'c', true, $post ),
				'author'           => $organiser,
				'publisher'        => self::site(),
				'mainEntityOfPage' => $url,
			];
		}

		if ( PostTypes::EVENT === $type ) {
			return $common + self::event_parts( $post, $organiser );
		}

		if ( PostTypes::TRAINING === $type ) {
			return $common + self::training_parts( $post, $organiser );
		}

		return $common + [ '@type' => 'WebPage', 'publisher' => self::site() ];
	}

	/**
	 * @param array<string, mixed> $organiser
	 * @return array<string, mixed>
	 */
	private static function event_parts( WP_Post $post, array $organiser ): array {
		$format = (string) Frontend::value( $post, 'format' );
		$parts  = [
			'@type'               => 'Event',
			'eventStatus'         => Cancel::is_cancelled( (int) $post->ID ) ? 'https://schema.org/EventCancelled' : 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => match ( $format ) {
				'online' => 'https://schema.org/OnlineEventAttendanceMode',
				'hybrid' => 'https://schema.org/MixedEventAttendanceMode',
				default  => 'https://schema.org/OfflineEventAttendanceMode',
			},
			'organizer'           => $organiser,
		];

		// A series is described by its next date; a one-off by its own.
		$next = Series::is_series( (int) $post->ID ) ? Series::next_dates( (int) $post->ID, 1 ) : [];

		if ( [] !== $next ) {
			$parts['startDate'] = $next[0]->start->format( 'c' );
			$parts['endDate']   = $next[0]->finish()->format( 'c' );
		} else {
			$start = self::iso( (string) Frontend::value( $post, 'start_datetime' ) );
			$end   = self::iso( (string) Frontend::value( $post, 'end_datetime' ) );

			if ( '' !== $start ) {
				$parts['startDate'] = $start;
			}

			if ( '' !== $end ) {
				$parts['endDate'] = $end;
			}
		}

		$locations = [];

		if ( in_array( $format, [ 'in_person', 'hybrid', '' ], true ) ) {
			$place = self::place( (string) Frontend::value( $post, 'venue_name' ), (string) Frontend::value( $post, 'address' ), (string) Frontend::value( $post, 'postcode' ) );

			if ( null !== $place ) {
				$locations[] = $place;
			}
		}

		if ( in_array( $format, [ 'online', 'hybrid' ], true ) ) {
			$online = (string) Frontend::value( $post, 'online_url' );
			$locations[] = [ '@type' => 'VirtualLocation', 'url' => '' !== $online ? $online : (string) get_permalink( $post ) ];
		}

		if ( [] !== $locations ) {
			$parts['location'] = 1 === count( $locations ) ? $locations[0] : $locations;
		}

		$offer = self::offer( (string) Frontend::value( $post, 'cost' ), Frontend::booking_url( $post ), (string) get_permalink( $post ) );

		if ( null !== $offer ) {
			$parts['offers'] = $offer;
		}

		return $parts;
	}

	/**
	 * @param array<string, mixed> $organiser
	 * @return array<string, mixed>
	 */
	private static function training_parts( WP_Post $post, array $organiser ): array {
		$provider = (string) Frontend::value( $post, 'provider' );
		$by       = '' !== $provider ? [ '@type' => 'Organization', 'name' => self::plain( $provider ) ] : $organiser;
		$start    = (string) Frontend::value( $post, 'start_date' );
		$delivery = (string) Frontend::value( $post, 'delivery' );
		$offer    = self::offer( (string) Frontend::value( $post, 'cost' ), Frontend::booking_url( $post ), (string) get_permalink( $post ) );

		// Dated training is an event you attend; undated is a course you can take.
		if ( '' === $start ) {
			$parts = [ '@type' => 'Course', 'provider' => $by ];

			if ( null !== $offer ) {
				$parts['offers'] = $offer;
			}

			return $parts;
		}

		$parts = [
			'@type'               => 'EducationEvent',
			'startDate'           => $start,
			'organizer'           => $by,
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => match ( $delivery ) {
				'online'  => 'https://schema.org/OnlineEventAttendanceMode',
				'blended' => 'https://schema.org/MixedEventAttendanceMode',
				default   => 'https://schema.org/OfflineEventAttendanceMode',
			},
		];

		$end = (string) Frontend::value( $post, 'end_date' );

		if ( '' !== $end ) {
			$parts['endDate'] = $end;
		}

		if ( 'online' === $delivery ) {
			$parts['location'] = [ '@type' => 'VirtualLocation', 'url' => (string) get_permalink( $post ) ];
		} else {
			$place = self::place( (string) Frontend::value( $post, 'location' ), '', (string) Frontend::value( $post, 'postcode' ) );

			if ( null !== $place ) {
				$parts['location'] = $place;
			}
		}

		if ( null !== $offer ) {
			$parts['offers'] = $offer;
		}

		return $parts;
	}

	/* ---- Pieces ---------------------------------------------------------- */

	/**
	 * @return array<string, mixed>
	 */
	private static function site(): array {
		return [
			'@type' => 'Organization',
			'@id'   => home_url( '/#organization' ),
			'name'  => (string) get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		];
	}

	/**
	 * @param array<int, array{0:string, 1:string}> $items [url, name] pairs.
	 * @return array<string, mixed>
	 */
	private static function collection( string $name, string $url, string $description, array $items ): array {
		$entity = [
			'@type'       => 'CollectionPage',
			'name'        => $name,
			'url'         => $url,
			'description' => $description,
			'isPartOf'    => [ '@type' => 'WebSite', 'name' => (string) get_bloginfo( 'name' ), 'url' => home_url( '/' ) ],
		];

		if ( [] !== $items ) {
			$list = [];

			foreach ( $items as $i => [ $item_url, $item_name ] ) {
				$list[] = [ '@type' => 'ListItem', 'position' => $i + 1, 'url' => $item_url, 'name' => $item_name ];
			}

			$entity['mainEntity'] = [ '@type' => 'ItemList', 'itemListElement' => $list ];
		}

		return $entity;
	}

	/**
	 * Home, then each [name, url] given.
	 *
	 * @param array<int, array{0:string, 1:string}> $trail
	 * @return array<string, mixed>
	 */
	private static function breadcrumbs( array $trail ): array {
		$list = [ [ '@type' => 'ListItem', 'position' => 1, 'name' => __( 'Home', 'dgl-platform' ), 'item' => home_url( '/' ) ] ];

		foreach ( $trail as $i => [ $name, $url ] ) {
			$item = [ '@type' => 'ListItem', 'position' => $i + 2, 'name' => $name ];

			if ( '' !== $url ) {
				$item['item'] = $url;
			}

			$list[] = $item;
		}

		return [ '@type' => 'BreadcrumbList', 'itemListElement' => $list ];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function place( string $name, string $street, string $postcode ): ?array {
		$name    = self::plain( $name );
		$address = self::postal_address( $street, '', $postcode );

		if ( '' === $name && null === $address ) {
			return null;
		}

		$place = [ '@type' => 'Place', 'name' => '' !== $name ? $name : __( 'Leeds', 'dgl-platform' ) ];

		if ( null !== $address ) {
			$place['address'] = $address;
		}

		return $place;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function postal_address( string $street, string $locality, string $postcode ): ?array {
		$street   = self::plain( $street );
		$postcode = strtoupper( self::plain( $postcode ) );
		$locality = self::plain( $locality );

		if ( '' === $street && '' === $postcode ) {
			return null;
		}

		$address = [ '@type' => 'PostalAddress', 'addressLocality' => '' !== $locality ? $locality : __( 'Leeds', 'dgl-platform' ), 'addressCountry' => 'GB' ];

		if ( '' !== $street ) {
			$address['streetAddress'] = $street;
		}

		if ( '' !== $postcode ) {
			$address['postalCode'] = $postcode;
		}

		return $address;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function offer( string $cost, string $booking_url, string $page_url ): ?array {
		if ( '' === $cost ) {
			return null;
		}

		$offer = [
			'@type'         => 'Offer',
			'url'           => '' !== $booking_url ? $booking_url : $page_url,
			'priceCurrency' => 'GBP',
			'availability'  => 'https://schema.org/InStock',
		];

		if ( 'free' === $cost ) {
			$offer['price'] = '0';
		}

		return $offer;
	}

	/** A wall-clock stamp in the site's timezone as ISO 8601 with offset, or ''. */
	private static function iso( string $wall ): string {
		if ( '' === trim( $wall ) ) {
			return '';
		}

		try {
			return ( new \DateTimeImmutable( $wall, wp_timezone() ) )->format( 'c' );
		} catch ( \Exception ) {
			return '';
		}
	}

	private static function org_url( int $org_id ): string {
		return home_url( '/' . Directory::BASE . '/' . get_post_field( 'post_name', $org_id ) . '/' );
	}

	private static function plain( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/* ---- The picture ------------------------------------------------------ */

	/**
	 * The item's own picture, else its organisation's logo, else a card.
	 *
	 * @return array{url:string, width:int, height:int, alt:string}
	 */
	public static function image_for_post( WP_Post $post ): array {
		$own = self::attachment( (int) Frontend::value( $post, 'image' ), (string) Frontend::value( $post, 'image_alt' ) );

		if ( null !== $own ) {
			return $own;
		}

		$org = Frontend::organisation( $post );

		if ( $org['id'] > 0 ) {
			$logo = self::logo_of( $org['id'] );

			if ( null !== $logo ) {
				return $logo;
			}
		}

		return self::card( 'item-' . $post->ID, (string) get_the_title( $post ), $org['name'], Frontend::type_label( (string) $post->post_type ) );
	}

	/**
	 * @return array{url:string, width:int, height:int, alt:string}|null
	 */
	private static function logo_of( int $org_id ): ?array {
		$field = \DGL\Org\Schema::find( 'org_logo' );

		if ( null === $field ) {
			return null;
		}

		return self::attachment( (int) get_post_meta( $org_id, $field->meta_key(), true ), (string) get_the_title( $org_id ) );
	}

	/**
	 * @return array{url:string, width:int, height:int, alt:string}|null
	 */
	private static function attachment( int $id, string $alt ): ?array {
		if ( $id <= 0 ) {
			return null;
		}

		$src = wp_get_attachment_image_src( $id, 'full' );

		if ( ! is_array( $src ) || '' === (string) $src[0] ) {
			return null;
		}

		$stored_alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );

		return [
			'url'    => (string) $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'alt'    => self::plain( '' !== $stored_alt ? $stored_alt : $alt ),
		];
	}

	/**
	 * A made card, else the site icon, else nothing.
	 *
	 * @return array{url:string, width:int, height:int, alt:string}
	 */
	private static function card( string $key, string $title, string $line, string $kicker ): array {
		$url = OgCard::url( $key, $title, $line, $kicker );

		if ( '' !== $url ) {
			return [ 'url' => $url, 'width' => OgCard::WIDTH, 'height' => OgCard::HEIGHT, 'alt' => self::plain( $title ) ];
		}

		$icon = (string) get_site_icon_url( 512 );

		return [ 'url' => $icon, 'width' => '' !== $icon ? 512 : 0, 'height' => '' !== $icon ? 512 : 0, 'alt' => (string) get_bloginfo( 'name' ) ];
	}
}

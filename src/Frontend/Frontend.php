<?php
/**
 * The public face of a submission.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Frontend;

use DGL\Dashboard\Assets;
use DGL\Dashboard\View;
use DGL\Events\Calendar;
use DGL\Events\Series;
use DGL\Events\Wording;
use DGL\Org\Directory;
use DGL\Org\DirectoryQuery;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Schema\FieldRegistry;
use DGL\Statuses;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * What the public sees when a submission goes live.
 *
 * Until now, nothing. The post types are public and have archives, so
 * WordPress rendered an approved event with the theme's default template:
 * title and body, and none of the fields the member actually filled in. The
 * date, the venue, the cost and the booking link were collected, moderated,
 * published and then shown to nobody.
 *
 * These templates sit inside the theme's own header and footer, because a
 * public page belongs to the site. Only the content between them is ours.
 */
final class Frontend {

	/** Items per page on a public listing. */
	public const PER_PAGE = 20;

	public static function init(): void {
		add_action( 'init', [ self::class, 'add_rules' ] );
		add_filter( 'query_vars', [ self::class, 'add_query_var' ] );
		add_filter( 'pre_get_document_title', [ self::class, 'directory_title' ] );
		add_action( 'template_redirect', [ \DGL\Events\Ics::class, 'serve' ], 5 );
		add_action( 'template_redirect', [ self::class, 'directory_status' ] );
		add_filter( 'blocksy:breadcrumbs:items-array', [ self::class, 'breadcrumbs' ] );
		add_filter( 'template_include', [ self::class, 'template' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'assets' ] );
		add_action( 'pre_get_posts', [ self::class, 'order_archive' ] );
		add_filter( 'posts_clauses', [ self::class, 'pin_first' ], 10, 2 );
	}

	/**
	 * Whether this request is one of ours.
	 *
	 * Switched-off types are excluded. They are not public, so WordPress
	 * should never route one here, but a type that is re-enabled later must
	 * work without anybody remembering to change this.
	 */
	public static function is_ours(): bool {
		return self::single_type() !== null || self::archive_type() !== null || self::directory_request() !== null || self::calendar_request();
	}

	/* ---- The organisation directory ------------------------------------ */

	public static function add_rules(): void {
		// Before the post type's own rules, or 'calendar' is read as an event slug.
		add_rewrite_rule( '^' . PostTypes::definitions()[ PostTypes::EVENT ]['slug'] . '/calendar/?$', 'index.php?' . Calendar::QUERY_VAR . '=1', 'top' );
		\DGL\Events\Ics::add_rules();
		add_rewrite_rule( '^' . Directory::BASE . '/?$', 'index.php?' . Directory::QUERY_VAR . '=1', 'top' );
		add_rewrite_rule( '^' . Directory::BASE . '/([^/]+)/?$', 'index.php?' . Directory::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public static function add_query_var( array $vars ): array {
		$vars[] = Directory::QUERY_VAR;
		$vars[] = Calendar::QUERY_VAR;
		$vars[] = \DGL\Events\Ics::QUERY_VAR;

		return $vars;
	}

	/**
	 * '1' for the index, a slug for one organisation, null when this is not
	 * a directory request.
	 */
	public static function directory_request(): ?string {
		$value = get_query_var( Directory::QUERY_VAR, null );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	public static function calendar_request(): bool {
		return '1' === (string) get_query_var( Calendar::QUERY_VAR, '' );
	}

	/**
	 * The organisation a directory request is for, or null on the index or
	 * when the slug matches nothing listed.
	 */
	public static function directory_org(): ?WP_Post {
		$request = self::directory_request();

		return null === $request || '1' === $request ? null : DirectoryQuery::find( $request );
	}

	/**
	 * A slug that matches nothing listed is a 404, sent before the theme's
	 * header has put a byte on the wire. WordPress's own query sees only a
	 * custom query var here and would call it a 200.
	 */
	public static function directory_status(): void {
		$request = self::directory_request();

		global $wp_query;

		if ( self::calendar_request() ) {
			$wp_query->is_404 = false;
			// Not the blog either: the theme's breadcrumb read "News" off is_home.
			$wp_query->is_home = false;
			status_header( 200 );
			return;
		}

		if ( null === $request ) {
			return;
		}

		if ( '1' === $request ) {
			// The index is not a 404 either, whatever the main query thinks.
			$wp_query->is_404 = false;
			$wp_query->is_home = false;
			status_header( 200 );
			return;
		}

		if ( null === self::directory_org() ) {
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		} else {
			$wp_query->is_404 = false;
			status_header( 200 );
		}
	}

	/**
	 * The theme's breadcrumb for the pages WordPress has no name for.
	 *
	 * Blocksy builds its trail from the main query's flags. Ours are custom
	 * query vars, which parse as the blog index, so the trail read
	 * "Home > News" over the directory and the calendar.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array<int, array<string, mixed>>
	 */
	public static function breadcrumbs( array $items ): array {
		$request = self::directory_request();
		$home    = [] !== $items ? [ $items[0] ] : [ [ 'name' => __( 'Home', 'dgl-platform' ), 'url' => home_url( '/' ) ] ];

		if ( self::calendar_request() ) {
			return array_merge(
				$home,
				[
					[ 'name' => PostTypes::definitions()[ PostTypes::EVENT ]['plural'] ?? __( 'Events', 'dgl-platform' ), 'url' => self::archive_url( PostTypes::EVENT ) ],
					[ 'name' => __( 'Calendar', 'dgl-platform' ), 'url' => '' ],
				]
			);
		}

		if ( null === $request ) {
			return $items;
		}

		$trail = array_merge( $home, [ [ 'name' => __( 'Directory', 'dgl-platform' ), 'url' => home_url( '/' . Directory::BASE . '/' ) ] ] );
		$org   = '1' === $request ? null : self::directory_org();

		if ( null !== $org ) {
			$trail[] = [ 'name' => (string) get_the_title( $org ), 'url' => '' ];
		}

		return $trail;
	}

	public static function directory_title( string $title ): string {
		if ( self::calendar_request() ) {
			return __( 'Events calendar', 'dgl-platform' ) . ' | ' . get_bloginfo( 'name' );
		}

		$request = self::directory_request();

		if ( null === $request ) {
			return $title;
		}

		$org  = self::directory_org();
		$name = $org instanceof WP_Post ? get_the_title( $org ) : __( 'Member organisations', 'dgl-platform' );

		if ( '1' !== $request && null === $org ) {
			$name = __( 'Not found', 'dgl-platform' );
		}

		return $name . ' | ' . get_bloginfo( 'name' );
	}

	public static function single_type(): ?string {
		if ( ! is_singular() ) {
			return null;
		}

		$type = (string) get_post_type();

		return PostTypes::is_submittable( $type ) && PostTypes::is_enabled( $type ) ? $type : null;
	}

	public static function archive_type(): ?string {
		foreach ( PostTypes::enabled_keys() as $type ) {
			if ( is_post_type_archive( $type ) ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * Swap in our template, keeping the theme's.
	 *
	 * A theme can override either file by dropping a copy under
	 * `dgl-platform/` in the theme, which is what {@see View::locate()} already
	 * does for the member area.
	 */
	public static function template( string $template ): string {
		if ( ! self::is_ours() ) {
			return $template;
		}

		$ours = plugin_dir_path( \DGL\PLUGIN_FILE ) . 'templates/public/wrapper.php';

		return is_readable( $ours ) ? $ours : $template;
	}

	public static function assets(): void {
		if ( ! self::is_ours() ) {
			return;
		}

		// Goes through the same helper as the member area, so the shared
		// design tokens are always loaded first.
		Assets::style( 'assets/public.css', 'dgl-public' );
		Assets::autoload();
	}

	/**
	 * Listings are ordered by when the thing happens, not when it was posted.
	 *
	 * An events page in reverse-chronological post order puts next March above
	 * this Saturday, which is the single most common way a community listing
	 * becomes useless. Types with no date of their own keep newest-first.
	 */
	public static function order_archive( WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$type = null;

		foreach ( PostTypes::enabled_keys() as $candidate ) {
			if ( $query->is_post_type_archive( $candidate ) ) {
				$type = $candidate;
				break;
			}
		}

		if ( null === $type ) {
			return;
		}

		// The page size is the plugin's, not the Reading setting's, so the
		// autoload has a known page to fetch and the setting cannot make it 1.
		// A query that asked for its own size (a test, a feed) keeps it.
		if ( '' === (string) $query->get( 'posts_per_page', '' ) ) {
			$query->set( 'posts_per_page', self::PER_PAGE );
		}

		$sort_field = self::sort_key( $type );
		$field      = null === $sort_field ? null : \DGL\Schema\FieldRegistry::find( $type, $sort_field );

		if ( null === $field ) {
			return;
		}

		// The stored key, not the field key: 'dgl_start_datetime', not
		// 'start_datetime'. The listing had been sorting on a key that no
		// row has ever carried.
		$date_key = $field->meta_key();

		// Events sort by their next occurrence, so a weekly group sits where
		// its next date belongs rather than where its first one was.
		if ( PostTypes::EVENT === $type ) {
			$date_key = \DGL\Meta::ITEM_NEXT_AT;
		}

		/*
		 * Items with no date still have to appear. Setting `meta_key` makes
		 * WordPress inner-join postmeta on that key, whatever the meta_query
		 * says, so every row without the key vanished: the client's demo
		 * event, saved without a start date, made the public listing say
		 * "no events" while the member area said "live on site". Ordering by
		 * a named clause needs no `meta_key`; a row with no date sorts as
		 * null, first, rather than not at all.
		 */
		$query->set(
			'meta_query',
			[
				'relation'    => 'OR',
				'dgl_when'    => [ 'key' => $date_key, 'compare' => 'EXISTS' ],
				'dgl_undated' => [ 'key' => $date_key, 'compare' => 'NOT EXISTS' ],
			]
		);
		$query->set( 'orderby', [ 'dgl_when' => 'ASC', 'date' => 'DESC' ] );

		// A featured item sits first. That is done in SQL by pin_first(), not
		// by another meta_query clause: adding one to the OR above changed how
		// WordPress joined the date and broke the date order for everything.
		$query->set( 'dgl_pin_first', true );

		// ?topic= and ?when= narrow the list. Read here, on the main query,
		// so the numbered pages and the autoload carry them without help.
		Filters::apply( $query, Filters::args_from( wp_unslash( $_GET ), $type ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public filter; nothing is written.
	}

	/**
	 * Put the featured items at the top of a public list.
	 *
	 * Left-joins the pin and orders by "pinned and not yet lapsed" before the
	 * date order the query already asked for. The comparison is against the
	 * wall clock now, so a pin whose time is up drops back the moment it
	 * lapses, not an hour later when the sweep deletes it.
	 *
	 * @param array<string, string> $clauses
	 * @return array<string, string>
	 */
	public static function pin_first( array $clauses, WP_Query $query ): array {
		if ( ! $query->get( 'dgl_pin_first' ) ) {
			return $clauses;
		}

		global $wpdb;

		$clauses['join']   .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} dgl_pin ON ( dgl_pin.post_id = {$wpdb->posts}.ID AND dgl_pin.meta_key = %s )",
			\DGL\Workflow\Pins::META_UNTIL
		);
		$featured           = $wpdb->prepare( '( dgl_pin.meta_value > %s ) DESC', \DGL\Events\Series::now()->format( 'Y-m-d H:i:s' ) );
		$clauses['orderby'] = '' === trim( (string) $clauses['orderby'] ) ? $featured : $featured . ', ' . $clauses['orderby'];

		return $clauses;
	}

	/**
	 * The meta key a type's listing should sort by, if it has one.
	 */
	public static function sort_key( string $post_type ): ?string {
		foreach ( FieldRegistry::for_type( $post_type ) as $field ) {
			if ( in_array( $field->key, [ 'start_datetime', 'start_date', 'deadline' ], true ) ) {
				return $field->key;
			}
		}

		return null;
	}

	/* ---------------------------------------------------------------------
	 * What the templates render
	 * ------------------------------------------------------------------ */

	/**
	 * The facts for one item, ready to print.
	 *
	 * Empty fields are dropped rather than shown as "Not given". That marker
	 * belongs on a member's own screen, where it is a prompt to finish the job.
	 * On a public page it is the site telling a visitor that an organisation
	 * did not bother.
	 *
	 * @return array<int, array{label:string, value:string, key:string}>
	 */
	/**
	 * A field's stored value for a public page.
	 *
	 * The wizard stores every field under its meta key, 'dgl_' plus the field
	 * key. The public side read the bare key, so a real event showed no date,
	 * no venue and no summary, and only ever looked right on items somebody
	 * had created by hand with the wrong keys, including the demo on staging
	 * and every fixture in the tests.
	 */
	public static function value( WP_Post $post, string $key ): mixed {
		$field = FieldRegistry::find( (string) $post->post_type, $key );

		return get_post_meta( (int) $post->ID, null === $field ? 'dgl_' . $key : $field->meta_key(), true );
	}

	public static function facts( WP_Post $post ): array {
		$out = [];

		$schedule = self::schedule( $post );

		$all_values = [];
		foreach ( FieldRegistry::for_type( (string) $post->post_type ) as $f ) {
			$all_values[ $f->key ] = self::value( $post, $f->key );
		}

		foreach ( FieldRegistry::public_fields( (string) $post->post_type ) as $field ) {
			if ( ! $field->applies( $all_values ) ) {
				continue;
			}

			// The headline is the page title and the summary is the standfirst.
			// Repeating them in a fact table is noise.
			if ( in_array( $field->key, [ 'title', 'summary', 'body', 'image' ], true ) ) {
				continue;
			}

			// A series says when in the schedule block above the card; its raw start and end would repeat it.
			if ( null !== $schedule && in_array( $field->key, [ 'start_datetime', 'end_datetime' ], true ) ) {
				continue;
			}

			$value = get_post_meta( (int) $post->ID, $field->meta_key(), true );

			if ( self::is_blank( $value ) ) {
				continue;
			}

			$out[] = [
				'key'   => $field->key,
				'label' => $field->label,
				'value' => View::field_value( $field, $value ),
			];
		}

		return $out;
	}

	private static function is_blank( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return [] === $value;
		}

		return '' === trim( (string) $value );
	}

	/**
	 * A one-line summary of when and where, for a listing row.
	 *
	 * This is the line that decides whether somebody clicks, so it is built
	 * from the two things they are deciding on rather than from whichever
	 * fields happen to come first.
	 */
	/**
	 * Where it happens, in a word or a name: the venue, "Online", or both.
	 */
	public static function where( WP_Post $post ): string {
		$venue  = (string) self::value( $post, 'venue_name' );
		$format = (string) self::value( $post, 'format' );

		if ( 'online' === $format ) {
			return __( 'Online', 'dgl-platform' );
		}

		if ( 'hybrid' === $format && '' !== $venue ) {
			/* translators: %s: venue name. */
			return sprintf( __( '%s and online', 'dgl-platform' ), $venue );
		}

		return $venue;
	}

	public static function meta_line( WP_Post $post ): string {
		$parts = [];

		if ( \DGL\Events\Cancel::is_cancelled( (int) $post->ID ) ) {
			$parts[] = __( 'Cancelled', 'dgl-platform' );
		}

		$rule = Series::rule_for( (int) $post->ID );

		if ( null !== $rule ) {
			$parts[] = Wording::with_times( $rule->to_meta(), $rule->start->format( 'Y-m-d H:i:s' ), '' );
			$coming  = Series::next_dates( (int) $post->ID, 1 );

			if ( [] !== $coming ) {
				/* translators: %s: a date like "Tue 23 Sep". */
				$parts[] = sprintf( __( 'Next: %s', 'dgl-platform' ), wp_date( 'D j M', $coming[0]->start->getTimestamp() ) );
			}

			$venue = self::where( $post );

			if ( '' !== $venue ) {
				$parts[] = $venue;
			}

			return implode( ' · ', $parts );
		}

		$when = (string) self::value( $post, 'start_datetime' );

		if ( '' === $when ) {
			$when = (string) self::value( $post, 'start_date' );
		}

		if ( '' === $when ) {
			$when = (string) self::value( $post, 'deadline' );
		}

		if ( '' !== $when ) {
			$parts[] = View::wall_date( $when, str_contains( $when, ':' ) );
		}

		$venue = self::where( $post );

		if ( '' !== $venue ) {
			$parts[] = $venue;
		}

		return implode( ' · ', $parts );
	}

	/**
	 * The organisation that published it, and a link if it has a page.
	 *
	 * @return array{name:string, id:int}
	 */
	public static function organisation( WP_Post $post ): array {
		$org_id = Org::for_item( (int) $post->ID );

		return [
			'id'   => $org_id,
			'name' => $org_id > 0 ? (string) get_the_title( $org_id ) : '',
		];
	}

	/**
	 * The booking link, if the member gave one.
	 */
	public static function booking_url( WP_Post $post ): string {
		foreach ( [ 'booking_url', 'apply_url', 'link' ] as $key ) {
			$url = (string) self::value( $post, $key );

			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Whether this item has passed its date.
	 *
	 * The expiry sweep moves an item out of `publish` on its own schedule, so
	 * a page can only be reached in this state between the date passing and the
	 * sweep running. Saying so is better than quietly showing a stale listing.
	 */
	/**
	 * A repeating event's schedule in words, with its next dates.
	 *
	 * @return array{wording: string, next: \DGL\Events\Occurrence[], ended: bool, until: string}|null
	 */
	public static function schedule( WP_Post $post ): ?array {
		$rule = Series::rule_for( (int) $post->ID );

		if ( null === $rule ) {
			return null;
		}

		// Shown with the cancelled ones marked; "ended" counts only dates that will run.
		$next = Series::next_dates( (int) $post->ID, 5, true );

		return [
			'wording' => Wording::long( $rule->to_meta() + [ 'cancelled' => $rule->cancelled ], $rule->start->format( 'Y-m-d H:i:s' ), null !== $rule->duration ? $rule->start->add( $rule->duration )->format( 'Y-m-d H:i:s' ) : '', static fn( string $d ): string => wp_date( 'j F Y', ( new \DateTimeImmutable( $d, wp_timezone() ) )->getTimestamp() ) ),
			'next'    => $next,
			'ended'   => [] === Series::next_dates( (int) $post->ID, 1 ),
			'until'   => $rule->until->format( 'Y-m-d' ),
		];
	}

	public static function has_passed( WP_Post $post ): bool {
		$expires = (string) get_post_meta( (int) $post->ID, \DGL\Meta::ITEM_EXPIRES_AT, true );

		if ( '' === $expires ) {
			return false;
		}

		// Wall clock against wall clock: the stored stamp is in the site's own timezone.
		return $expires < current_time( 'mysql' );
	}

	public static function type_label( string $post_type, bool $plural = false ): string {
		$def = PostTypes::definitions()[ $post_type ] ?? null;

		return null === $def ? '' : (string) ( $plural ? $def['plural'] : $def['singular'] );
	}

	public static function archive_url( string $post_type ): string {
		$url = get_post_type_archive_link( $post_type );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * Whether the current item is live, as opposed to reachable by a preview.
	 */
	public static function is_live( WP_Post $post ): bool {
		return Statuses::LIVE === $post->post_status;
	}
}

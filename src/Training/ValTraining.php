<?php
/**
 * Voluntary Action Leeds' training courses, pulled in as Training items.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Training;

use DGL\Audit\Log;
use DGL\Events\Series;
use DGL\Index\Sync;
use DGL\Meta;
use DGL\News\LegacyImport;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Statuses;
use DGL\Taxonomies;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * VAL lists its courses at val.org.uk/training. There is no feed and its
 * REST API is closed to outsiders, so the page itself is read, as it is
 * served to any visitor: one card per course, with the venue and a row for
 * each date (day, time, price, places left). Each course has its own page
 * with the description and "who should attend".
 *
 * Unlike the jobs block, a course becomes a real Training item owned by
 * Voluntary Action Leeds. It then turns up in the Training list, its filters
 * and the digests like anything a member adds, and leaves by the ordinary
 * expiry.
 *
 * One item per course. Its start date is the next session. VAL is the
 * source for dates, price, venue and booking link, so those are refreshed
 * every night. The words (summary, description, who it is for, topics) are
 * written once, when the item is made, so an edit by the review team is
 * never overwritten.
 *
 * Everything that comes back is untrusted: text is stripped to plain text
 * and capped, the description is rebuilt from an allowlist of tags, and a
 * link is kept only when it points at VAL's own site over https.
 *
 * A course already on the platform from VAL, added by hand, is adopted
 * rather than copied: same organisation, same title.
 *
 * Once a night, never on a page render. A page that cannot be read, or
 * that has no courses on it, changes nothing: a failed night must never
 * empty the list.
 */
final class ValTraining {

	public const LISTING_URL = 'https://val.org.uk/training/';

	/** The only hosts a course's link may point at. */
	public const HOSTS = [ 'val.org.uk', 'www.val.org.uk' ];

	public const HOOK = 'dgl_val_training_refresh';

	/** The local hour the nightly run is due. */
	public const HOUR = 3;

	/** How the last run went. */
	public const OPTION_LAST = 'dgl_val_training_last';

	/** The organisation that owns the courses: an id, else found by name. */
	public const OPTION_ORG = 'dgl_val_training_org';

	public const ORG_NAME = 'Voluntary Action Leeds';

	/** The course page an item came from. Marks it as VAL's. */
	public const META_URL = 'dgl_val_training_url';

	public const TIMEOUT = 20;

	/** New items made in one run, so a first run on a surprise page stays small. */
	public const MAX_NEW = 60;

	/** A page with fewer courses than this is not trusted to say a course has gone. */
	public const MIN_TO_WITHDRAW = 5;

	private const MONTHS = [
		'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
		'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
	];

	/**
	 * Words in a title and the topics they point at. Every course also gets
	 * Professional Development.
	 */
	private const TOPIC_WORDS = [
		'grant'                => 'grants-and-funding',
		'funder'               => 'grants-and-funding',
		'funding'              => 'grants-and-funding',
		'trustee'              => 'leadership',
		'conflicts of interest' => 'leadership',
		'register as a charity' => 'support-for-vcse-organisations',
		'equality'             => 'equality-diversity-and-inclusion',
		'data protection'      => 'data-and-digital',
		'gdpr'                 => 'data-and-digital',
		'supervision'          => 'workforce',
		'safeguarding'         => 'workforce',
		'first aid'            => 'workforce',
		'fire'                 => 'workforce',
		'food'                 => 'workforce',
		'lone working'         => 'workforce',
		'boundaries'           => 'workforce',
	];

	public static function init(): void {
		add_action( self::HOOK, [ self::class, 'nightly' ], 10, 0 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( \DGL\Jobs\ValJobs::next_run( time(), wp_timezone(), self::HOUR ), 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** An email goes out on the first bad night, then not again until a good one. */
	public const OPTION_ALERTED = 'dgl_val_training_alerted';

	/** Seconds between two course pages fetched in one run. */
	public const PAUSE = 1;

	/**
	 * The nightly run. The result is kept for `wp dgl training status`, and
	 * a bad night is emailed to the site's administrator address.
	 */
	public static function nightly(): void {
		$run = self::run();

		if ( self::should_alert( (bool) $run['ok'], (int) $run['failed'], false !== get_option( self::OPTION_ALERTED, false ) ) ) {
			if ( self::alert( $run ) ) {
				update_option( self::OPTION_ALERTED, time(), false );
			}
		} elseif ( $run['ok'] && 0 === (int) $run['failed'] ) {
			delete_option( self::OPTION_ALERTED );
		}
	}

	/**
	 * Whether a run needs an email: it failed outright, or some courses
	 * could not be read, and nobody has been told about this spell yet.
	 */
	public static function should_alert( bool $ok, int $failed, bool $already_told ): bool {
		return ( ! $ok || $failed > 0 ) && ! $already_told;
	}

	/**
	 * Say so, by email, to the site administrator. Plain about what happened
	 * and what it means: the courses already on the site are untouched.
	 *
	 * @param array<string, mixed> $run
	 */
	private static function alert( array $run ): bool {
		$to = sanitize_email( (string) get_option( 'admin_email', '' ) );

		if ( '' === $to ) {
			return false;
		}

		$what = ! $run['ok']
			? (string) $run['error']
			: sprintf(
				/* translators: %d: how many courses. */
				_n( '%d course page could not be read.', '%d course pages could not be read.', (int) $run['failed'], 'dgl-platform' ),
				(int) $run['failed']
			);

		return \DGL\Email\Mailer::send(
			( new \DGL\Email\Message(
				key: 'val_training_failed',
				audience: 'team',
				subject: __( 'The VAL training update did not work last night', 'dgl-platform' ),
				preheader: __( 'The courses already on the site are untouched.', 'dgl-platform' ),
				heading: __( 'The VAL training update did not work', 'dgl-platform' ),
				paragraphs: [
					__( 'The nightly read of the training page on val.org.uk failed, so new courses and changes to dates and prices from VAL did not come through.', 'dgl-platform' ),
					__( 'Nothing was removed. The courses already on the site stay listed until their own dates pass. You will not get another email about this until it has worked once and then failed again.', 'dgl-platform' ),
					__( 'It usually clears by itself. If it fails again tomorrow, VAL may have changed their page or blocked the request: ask whoever looks after the site to check.', 'dgl-platform' ),
				],
				facts: [
					__( 'What went wrong', 'dgl-platform' )  => '' !== $what ? $what : __( 'Unknown', 'dgl-platform' ),
					__( 'Courses found on VAL\'s page', 'dgl-platform' ) => (string) (int) $run['courses'],
					__( 'When', 'dgl-platform' )             => wp_date( 'j M Y H:i' ),
				],
			) )->for_recipients( [ $to ] )
		);
	}

	// ------------------------------------------------------------------
	// Reading the page. Pure: no WordPress, so the tests run without it.
	// ------------------------------------------------------------------

	/**
	 * Every course on the listing page, or null when the page is not VAL's
	 * course list at all.
	 *
	 * @return array<int, array{title:string, url:string, location:string, sessions:array<int, array{when:string, date:string, price:string, full:bool}>}>|null
	 */
	public static function parse_listing( string $html ): ?array {
		$xpath = self::xpath( $html );

		if ( null === $xpath ) {
			return null;
		}

		$cards = $xpath->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' pld-tr-card ')]" );

		if ( false === $cards || 0 === $cards->length ) {
			return null;
		}

		$courses = [];

		foreach ( $cards as $card ) {
			$title = self::text( self::first( $xpath, ".//*[contains(@class,'pld-tr-title')]", $card ), 200 );
			$link  = $xpath->query( ".//a[contains(@class,'pld-tr-cover')]/@href", $card )->item( 0 );
			$url   = null === $link ? '' : self::safe_url( (string) $link->nodeValue );

			if ( '' === $title || '' === $url || isset( $courses[ $url ] ) ) {
				continue;
			}

			$sessions = [];

			foreach ( $xpath->query( ".//li[contains(@class,'pld-tr-date')]", $card ) as $row ) {
				$when = self::text( self::first( $xpath, ".//*[contains(@class,'pld-tr-date-when')]", $row ), 120 );
				$date = self::date_of( $when );

				if ( null === $date ) {
					continue;
				}

				$meta       = self::text( self::first( $xpath, ".//*[contains(@class,'pld-tr-date-meta')]", $row ), 120 );
				$sessions[] = [
					'when'  => $when,
					'date'  => $date,
					'price' => $meta,
					'full'  => 1 === preg_match( '/\bfull\b/i', $meta ),
				];
			}

			if ( [] === $sessions ) {
				continue;
			}

			usort( $sessions, static fn( array $a, array $b ): int => strcmp( $a['date'], $b['date'] ) );

			$courses[ $url ] = [
				'title'    => $title,
				'url'      => $url,
				'location' => self::text( self::first( $xpath, ".//*[contains(@class,'pld-tr-loc')]", $card ), 200 ),
				'sessions' => $sessions,
			];
		}

		return [] === $courses ? null : array_values( $courses );
	}

	/**
	 * One course page: the description rebuilt from allowed tags, the
	 * opening line for the summary, and the "who should attend" words.
	 *
	 * @return array{body:string, summary:string, who:string, location:string}|null Null when the page has no description.
	 */
	public static function parse_course( string $html ): ?array {
		$xpath = self::xpath( $html );

		if ( null === $xpath ) {
			return null;
		}

		$body = self::first( $xpath, "//*[contains(@class,'pld-tr-details-body')]", null );

		if ( ! $body instanceof DOMElement ) {
			return null;
		}

		$clean = trim( self::clean( $body ) );

		if ( '' === $clean ) {
			return null;
		}

		$who   = '';
		$paras = [];

		foreach ( $xpath->query( './/p', $body ) as $p ) {
			$paras[] = self::text( $p, 1000 );
		}

		foreach ( $paras as $i => $para ) {
			if ( '' === $who && 1 === preg_match( '/^who (should attend|is (it|this course) for)\??$/i', $para ) ) {
				$who = $paras[ $i + 1 ] ?? '';
			}
		}

		$summary = self::summary_from( array_merge( $paras, self::text_runs( $body ) ) );

		// The listing leaves the venue off some courses that have one, so the course page is the one to trust.
		$location = trim( (string) preg_replace( '/^\s*Location:\s*/i', '', self::text( self::first( $xpath, "//*[contains(@class,'pld-tr-details-loc')]", null ), 200 ) ) );

		return [
			'body'     => $clean,
			'summary'  => $summary,
			'who'      => self::shorten( $who, 600 ),
			'location' => $location,
		];
	}

	/**
	 * The first block of words long enough to be a summary: not a heading
	 * ("Learning Outcomes"), a one-line tag-line or the start of a list.
	 * Paragraphs come first, then any loose text lines. Pure.
	 *
	 * @param string[] $blocks
	 */
	public static function summary_from( array $blocks ): string {
		foreach ( $blocks as $block ) {
			$block = trim( (string) preg_replace( '/\s+/u', ' ', $block ) );

			if ( mb_strlen( $block ) < 60 || 1 === preg_match( '/^(course aims?|learning outcomes?|location:|who (should|is))/i', $block ) ) {
				continue;
			}

			return self::shorten( $block, 300 );
		}

		return '';
	}

	/**
	 * The calendar day in "Mon 16 Nov 2026, 9:30am – 12:30pm", as Y-m-d.
	 * Month names are read by hand: no locale can change them.
	 */
	public static function date_of( string $when ): ?string {
		if ( 1 !== preg_match( '/\b(\d{1,2})\s+([A-Za-z]{3})[a-z]*\s+(\d{4})\b/', $when, $m ) ) {
			return null;
		}

		$month = self::MONTHS[ strtolower( $m[2] ) ] ?? 0;

		if ( 0 === $month || ! checkdate( $month, (int) $m[1], (int) $m[3] ) ) {
			return null;
		}

		return sprintf( '%04d-%02d-%02d', (int) $m[3], $month, (int) $m[1] );
	}

	/**
	 * The sessions still to come, soonest first. A session today counts.
	 *
	 * @param array<int, array{date:string}> $sessions
	 * @return array<int, array{date:string}>
	 */
	public static function upcoming( array $sessions, string $today ): array {
		$out = array_values( array_filter( $sessions, static fn( array $s ): bool => $s['date'] >= $today ) );

		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['date'], $b['date'] ) );

		return $out;
	}

	/**
	 * The cost field and its detail, from the row's price text.
	 *
	 * @return array{cost:string, detail:string}
	 */
	public static function cost_of( string $price ): array {
		if ( 1 === preg_match( '/£\s*(\d[\d,]*(?:\.\d{1,2})?)/u', $price, $m ) && (float) str_replace( ',', '', $m[1] ) > 0 ) {
			return [ 'cost' => 'paid', 'detail' => '£' . $m[1] ];
		}

		if ( 1 === preg_match( '/donation/i', $price ) ) {
			return [ 'cost' => 'donation', 'detail' => '' ];
		}

		return [ 'cost' => 'free', 'detail' => '' ];
	}

	/**
	 * Online when the venue says so. A course with no venue at all is a
	 * webinar: VAL gives every in-person course an address.
	 */
	public static function delivery_of( string $location ): string {
		$location = trim( $location );

		if ( '' === $location || 1 === preg_match( '/\b(online|zoom|teams|webinar|virtual)\b/i', $location ) ) {
			return 'online';
		}

		return 'in_person';
	}

	/** A UK postcode in a venue line, else ''. */
	public static function postcode_of( string $location ): string {
		if ( 1 === preg_match( '/\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b/', strtoupper( $location ), $m ) ) {
			return $m[1] . ' ' . $m[2];
		}

		return '';
	}

	/**
	 * Topic slugs for a title: Professional Development, plus whatever its
	 * words point at. Pure.
	 *
	 * @return string[]
	 */
	public static function topics_for( string $title ): array {
		$lower  = strtolower( $title );
		$topics = [ 'professional-development' ];

		foreach ( self::TOPIC_WORDS as $word => $slug ) {
			if ( str_contains( $lower, $word ) && ! in_array( $slug, $topics, true ) ) {
				$topics[] = $slug;
			}
		}

		return $topics;
	}

	/**
	 * The fields VAL is the source for, as they are stored. Pure.
	 *
	 * @param array{title:string, url:string, location:string, sessions:array<int, array{date:string, price:string}>} $course
	 * @return array<string, string>|null Null when no session is left to come.
	 */
	public static function schedule_fields( array $course, string $today ): ?array {
		$coming = self::upcoming( $course['sessions'], $today );

		if ( [] === $coming ) {
			return null;
		}

		$cost = self::cost_of( $coming[0]['price'] );

		return [
			'dgl_start_date'  => $coming[0]['date'],
			'dgl_end_date'    => '',
			'dgl_delivery'    => self::delivery_of( $course['location'] ),
			'dgl_location'    => 1 === preg_match( '/^\s*(online|zoom|teams|virtual|webinar)\s*$/i', $course['location'] ) ? '' : $course['location'],
			'dgl_postcode'    => self::postcode_of( $course['location'] ),
			'dgl_cost'        => $cost['cost'],
			'dgl_cost_detail' => $cost['detail'],
			'dgl_booking_url' => $course['url'],
		];
	}

	/** A link kept only when it is https and on VAL's own host. */
	public static function safe_url( string $url ): string {
		$url   = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$parts = parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return '';
		}

		if ( ! in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), self::HOSTS, true ) ) {
			return '';
		}

		return $url;
	}

	/** Plain text from a node, tags gone, spaces collapsed, capped. */
	public static function text( ?DOMNode $node, int $max ): string {
		if ( null === $node ) {
			return '';
		}

		$text = trim( (string) preg_replace( '/\s+/u', ' ', $node->textContent ) );

		return self::shorten( $text, $max );
	}

	/** Cut at a word, with an ellipsis, when too long. */
	public static function shorten( string $text, int $max ): string {
		$text = trim( $text );

		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$cut = mb_substr( $text, 0, $max - 1 );
		$sp  = mb_strrpos( $cut, ' ' );

		return rtrim( false === $sp || $sp < $max / 2 ? $cut : mb_substr( $cut, 0, $sp ), " ,;:.-\u{2013}" ) . '…';
	}

	/**
	 * A description as clean markup: paragraphs, lists, bold, italics,
	 * sub-headings and https links. Everything else is unwrapped to its text.
	 */
	public static function clean( DOMNode $node ): string {
		$out = '';

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$out .= htmlspecialchars( (string) $child->nodeValue, ENT_QUOTES, 'UTF-8' );
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$name = strtolower( $child->nodeName );

			if ( in_array( $name, [ 'script', 'style', 'iframe', 'form', 'button', 'img', 'svg' ], true ) ) {
				continue;
			}

			$inner = self::clean( $child );

			if ( in_array( $name, [ 'p', 'ul', 'ol', 'li', 'strong', 'em', 'h2', 'h3', 'h4' ], true ) ) {
				$out .= '' === trim( strip_tags( $inner ) ) ? '' : "<{$name}>" . ( 'ul' === $name || 'ol' === $name ? "\n" . $inner : trim( $inner ) ) . "</{$name}>\n";
			} elseif ( 'b' === $name || 'i' === $name ) {
				$tag  = 'b' === $name ? 'strong' : 'em';
				$out .= "<{$tag}>{$inner}</{$tag}>";
			} elseif ( 'br' === $name ) {
				$out .= '<br>';
			} elseif ( 'a' === $name ) {
				$href = self::safe_url( $child->getAttribute( 'href' ) );
				$out .= '' !== $href ? '<a href="' . htmlspecialchars( $href, ENT_QUOTES, 'UTF-8' ) . '">' . $inner . '</a>' : $inner;
			} else {
				$out .= $inner;
			}
		}

		return $out;
	}

	/**
	 * Every run of text under a node, one per text node, so words sitting
	 * straight in a div are found as well as those in paragraphs.
	 *
	 * @return string[]
	 */
	private static function text_runs( DOMNode $node ): array {
		$runs = [];

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$runs[] = trim( (string) $child->nodeValue );
			} elseif ( $child instanceof DOMElement && ! in_array( strtolower( $child->nodeName ), [ 'script', 'style' ], true ) ) {
				$runs = array_merge( $runs, self::text_runs( $child ) );
			}
		}

		return $runs;
	}

	private static function xpath( string $html ): ?DOMXPath {
		if ( '' === trim( $html ) ) {
			return null;
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$loaded   = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $loaded ? new DOMXPath( $doc ) : null;
	}

	private static function first( DOMXPath $xpath, string $query, ?DOMNode $context ): ?DOMNode {
		$found = null === $context ? $xpath->query( $query ) : $xpath->query( $query, $context );

		return false === $found ? null : $found->item( 0 );
	}

	// ------------------------------------------------------------------
	// Writing items. Needs WordPress.
	// ------------------------------------------------------------------

	/**
	 * The organisation that owns the courses: the saved id, else the one
	 * organisation called exactly Voluntary Action Leeds.
	 *
	 * @return int|WP_Error
	 */
	public static function org_id() {
		$saved = (int) get_option( self::OPTION_ORG, 0 );

		if ( $saved > 0 && Org::exists( $saved ) ) {
			return $saved;
		}

		$found = [];

		foreach ( Org::search( self::ORG_NAME, 50 )['ids'] as $id ) {
			if ( 0 === strcasecmp( trim( (string) get_the_title( $id ) ), self::ORG_NAME ) ) {
				$found[] = (int) $id;
			}
		}

		if ( 1 !== count( $found ) ) {
			return new WP_Error( 'dgl_val_org', sprintf( 'Expected one organisation called %s, found %d. Pass --org=<id>.', self::ORG_NAME, count( $found ) ) );
		}

		return $found[0];
	}

	/**
	 * Read the page and bring the items in line with it.
	 *
	 * @return array{ok:bool, error:string, at:int, courses:int, added:int, adopted:int, updated:int, revived:int, withdrawn:int, unchanged:int, failed:int, no_who:int, titles:string[], errors:string[]}
	 */
	public static function run( bool $dry_run = false, int $org_id = 0, int $actor_id = 0 ): array {
		$out = [
			'ok'        => false,
			'error'     => '',
			'at'        => time(),
			'courses'   => 0,
			'added'     => 0,
			'adopted'   => 0,
			'updated'   => 0,
			'revived'   => 0,
			'withdrawn' => 0,
			'unchanged' => 0,
			'failed'    => 0,
			'no_who'    => 0,
			'titles'    => [],
			'errors'    => [],
		];

		if ( $org_id <= 0 ) {
			$found = self::org_id();

			if ( is_wp_error( $found ) ) {
				$out['error'] = $found->get_error_message();

				return self::remember( $out, $dry_run );
			}

			$org_id = $found;
		}

		$body    = self::get( self::LISTING_URL );
		$courses = is_string( $body ) ? self::parse_listing( $body ) : null;

		if ( ! is_string( $body ) ) {
			$out['error'] = $body->get_error_message();

			return self::remember( $out, $dry_run );
		}

		if ( null === $courses ) {
			$out['error'] = __( 'VAL answered, but not with a list of courses. Nothing was changed.', 'dgl-platform' );

			return self::remember( $out, $dry_run );
		}

		$out['ok']      = true;
		$out['courses'] = count( $courses );
		$today          = wp_date( 'Y-m-d' );
		$known          = self::known( $org_id );
		$seen           = [];
		$made           = 0;

		$pages = [];
		$read  = static function ( string $url ) use ( &$pages ): ?array {
			if ( ! array_key_exists( $url, $pages ) ) {
				// Gentle on VAL's server: a pause before every course page after the first.
				if ( [] !== $pages ) {
					sleep( self::PAUSE );
				}

				$page          = self::get( $url );
				$pages[ $url ] = is_string( $page ) ? self::parse_course( $page ) : null;
			}

			return $pages[ $url ];
		};

		foreach ( $courses as $course ) {
			// Only a course with no venue on the listing costs a second fetch.
			if ( '' === $course['location'] && null !== self::schedule_fields( $course, $today ) ) {
				$course['location'] = (string) ( $read( $course['url'] )['location'] ?? '' );
			}

			$fields = self::schedule_fields( $course, $today );

			if ( null === $fields ) {
				continue;
			}

			$post_id = self::find( $course, $known );
			$seen[]  = $course['url'];

			if ( $post_id > 0 ) {
				$result = self::refresh_item( $post_id, $course, $fields, $dry_run, $known );

				if ( 'adopted' === $result ) {
					++$out['adopted'];
				} elseif ( 'revived' === $result ) {
					++$out['revived'];
				} elseif ( 'updated' === $result ) {
					++$out['updated'];
				} else {
					++$out['unchanged'];
				}

				continue;
			}

			if ( $made >= self::MAX_NEW ) {
				continue;
			}

			$details = $read( $course['url'] );

			if ( null === $details ) {
				++$out['failed'];
				$out['errors'][] = sprintf( '%s: the course page could not be read.', $course['title'] );
				continue;
			}

			if ( '' === $details['who'] ) {
				++$out['no_who'];
			}

			++$made;

			if ( $dry_run ) {
				++$out['added'];
				$out['titles'][] = sprintf( '%s (next %s, %s)', $course['title'], $fields['dgl_start_date'], 'paid' === $fields['dgl_cost'] ? $fields['dgl_cost_detail'] : $fields['dgl_cost'] );
				continue;
			}

			$new = self::store( $course, $fields, $details, $org_id, $actor_id );

			if ( is_wp_error( $new ) ) {
				++$out['failed'];
				$out['errors'][] = sprintf( '%s: %s', $course['title'], $new->get_error_message() );
				continue;
			}

			++$out['added'];
			$out['titles'][] = $course['title'];
		}

		if ( count( $courses ) >= self::MIN_TO_WITHDRAW ) {
			$out['withdrawn'] = self::withdraw_gone( $known, $seen, $dry_run );
		}

		return self::remember( $out, $dry_run );
	}

	/**
	 * Every Training item of the organisation, as id => [url, title key, status].
	 *
	 * @return array<int, array{url:string, key:string, status:string}>
	 */
	private static function known( int $org_id ): array {
		$ids = get_posts(
			[
				'post_type'      => PostTypes::TRAINING,
				'post_status'    => Statuses::all(),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => Meta::ITEM_ORG,
				'meta_value'     => $org_id,
			]
		);

		$known = [];

		foreach ( $ids as $id ) {
			$known[ (int) $id ] = [
				'url'    => (string) get_post_meta( (int) $id, self::META_URL, true ),
				'key'    => LegacyImport::title_key( (string) get_the_title( (int) $id ) ),
				'status' => (string) get_post_status( (int) $id ),
			];
		}

		return $known;
	}

	/**
	 * The item that already holds a course: by VAL's page address first,
	 * then an item of the organisation with the same title.
	 *
	 * @param array<int, array{url:string, key:string, status:string}> $known
	 */
	private static function find( array $course, array $known ): int {
		foreach ( $known as $id => $row ) {
			if ( '' !== $row['url'] && $row['url'] === $course['url'] ) {
				return $id;
			}
		}

		$key = LegacyImport::title_key( $course['title'] );

		foreach ( $known as $id => $row ) {
			if ( '' === $row['url'] && $row['key'] === $key ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Bring the fields VAL is the source for up to date on an item that exists.
	 *
	 * @param array<string, string> $fields
	 * @param array<int, array{url:string, key:string, status:string}> $known
	 * @return string adopted|revived|updated|unchanged
	 */
	private static function refresh_item( int $post_id, array $course, array $fields, bool $dry_run, array &$known ): string {
		$changed = false;
		$adopted = '' === (string) get_post_meta( $post_id, self::META_URL, true );

		foreach ( $fields as $key => $value ) {
			if ( (string) get_post_meta( $post_id, $key, true ) !== $value ) {
				$changed = true;

				if ( ! $dry_run ) {
					if ( '' === $value ) {
						delete_post_meta( $post_id, $key );
					} else {
						update_post_meta( $post_id, $key, $value );
					}
				}
			}
		}

		$revive = in_array( get_post_status( $post_id ), [ Statuses::EXPIRED ], true );

		if ( $dry_run ) {
			return $adopted ? 'adopted' : ( $revive ? 'revived' : ( $changed ? 'updated' : 'unchanged' ) );
		}

		if ( $adopted ) {
			update_post_meta( $post_id, self::META_URL, $course['url'] );
			$known[ $post_id ]['url'] = $course['url'];
		}

		if ( $revive ) {
			wp_update_post( [ 'ID' => $post_id, 'post_status' => Statuses::LIVE ] );
		}

		if ( $changed || $adopted || $revive ) {
			Series::stamp( $post_id, PostTypes::TRAINING );
			Sync::sync( $post_id );
		}

		return $adopted ? 'adopted' : ( $revive ? 'revived' : ( $changed ? 'updated' : 'unchanged' ) );
	}

	/**
	 * Make one item. Live at once: VAL is a partner and the page is public.
	 *
	 * @param array<string, string> $fields
	 * @param array{body:string, summary:string, who:string, location:string} $details
	 * @return int|WP_Error
	 */
	private static function store( array $course, array $fields, array $details, int $org_id, int $actor_id ) {
		$slug    = sanitize_title( basename( rtrim( (string) wp_parse_url( $course['url'], PHP_URL_PATH ), '/' ) ) );
		$post_id = wp_insert_post(
			[
				'post_type'    => PostTypes::TRAINING,
				'post_status'  => Statuses::LIVE,
				'post_title'   => $course['title'],
				'post_name'    => $slug,
				'post_content' => $details['body'],
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;
		$now     = current_time( 'mysql', true );
		$words   = [
			'dgl_provider'      => self::ORG_NAME,
			'dgl_summary'       => '' !== $details['summary'] ? $details['summary'] : $course['title'],
			'dgl_who_for'       => '' !== $details['who'] ? $details['who'] : ( '' !== $details['summary'] ? $details['summary'] : $course['title'] ),
			'dgl_contact_name'  => self::ORG_NAME,
			'dgl_contact_email' => 'info@val.org.uk',
			'dgl_contact_phone' => '0113 297 7920',
			'dgl_website'       => self::LISTING_URL,
		];

		if ( str_contains( $course['location'], 'Lupton' ) ) {
			$words['dgl_accessibility'] = 'Stringer House in Hunslet is fully accessible, with free parking and good public transport links.';
		}

		foreach ( $words + $fields as $key => $value ) {
			if ( '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		update_post_meta( $post_id, Meta::ITEM_ORG, $org_id );
		update_post_meta( $post_id, Meta::ITEM_SUBMITTED_AT, $now );
		update_post_meta( $post_id, Meta::ITEM_APPROVED_AT, $now );
		update_post_meta( $post_id, self::META_URL, $course['url'] );

		$term_ids = [];

		foreach ( self::topics_for( $course['title'] ) as $topic ) {
			$term = get_term_by( 'slug', $topic, Taxonomies::TOPIC );

			if ( $term instanceof \WP_Term ) {
				$term_ids[] = (int) $term->term_id;
			}
		}

		wp_set_object_terms( $post_id, $term_ids, Taxonomies::TOPIC, false );

		Series::stamp( $post_id, PostTypes::TRAINING );
		Sync::sync( $post_id );

		Log::record(
			'imported',
			'item',
			$post_id,
			$org_id,
			sprintf(
				/* translators: %s: the course's address on VAL's site. */
				__( 'Pulled in from VAL\'s training page: %s', 'dgl-platform' ),
				$course['url']
			),
			[ 'source' => $course['url'] ],
			$actor_id > 0 ? $actor_id : null
		);

		return $post_id;
	}

	/**
	 * A live item that came from VAL and is no longer on its page is taken
	 * off by the ordinary expiry sweep: its expiry is set to now.
	 *
	 * @param array<int, array{url:string, key:string, status:string}> $known
	 * @param string[] $seen Course addresses still on the page.
	 * @return int How many.
	 */
	private static function withdraw_gone( array $known, array $seen, bool $dry_run ): int {
		$count = 0;

		foreach ( $known as $id => $row ) {
			if ( '' === $row['url'] || in_array( $row['url'], $seen, true ) || Statuses::LIVE !== $row['status'] ) {
				continue;
			}

			++$count;

			if ( ! $dry_run ) {
				update_post_meta( $id, Meta::ITEM_EXPIRES_AT, current_time( 'mysql' ) );
				Sync::sync( $id );
			}
		}

		return $count;
	}

	/**
	 * One GET, as a body or an error. WordPress's own user agent is let
	 * through VAL's firewall (as for the jobs feed); the address is a
	 * constant or one read from VAL's own page and checked by safe_url().
	 */
	private static function get( string $url ): string|WP_Error {
		$response = wp_remote_get(
			$url,
			[
				'timeout'             => self::TIMEOUT,
				'redirection'         => 3,
				'limit_response_size' => 4 * MB_IN_BYTES,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			/* translators: %d: an HTTP status code such as 403. */
			return new WP_Error( 'dgl_val_http', sprintf( __( 'VAL answered with HTTP %d.', 'dgl-platform' ), $code ) );
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Keep how the run went, unless it was a dry run.
	 *
	 * @param array<string, mixed> $out
	 * @return array<string, mixed>
	 */
	private static function remember( array $out, bool $dry_run ): array {
		if ( ! $dry_run ) {
			update_option( self::OPTION_LAST, array_diff_key( $out, [ 'titles' => 1 ] ), false );
		}

		return $out;
	}

	/** How the last real run went, or null if there has not been one. */
	public static function last(): ?array {
		$last = get_option( self::OPTION_LAST, null );

		return is_array( $last ) ? $last : null;
	}
}

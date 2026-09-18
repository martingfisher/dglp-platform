<?php
/**
 * Events as iCalendar: one file per event, and a feed of every live event.
 *
 * `/events/<slug>.ics` downloads one event, with an RRULE and EXDATEs for a
 * series so it lands in Google, Outlook or Apple Calendar on every date it
 * runs. `/events/calendar.ics` is the same for everything live, for people
 * who subscribe by address. The lines are built by pure functions so the
 * folding, escaping and rule mapping are unit-tested without WordPress.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Events;

use DateTimeImmutable;
use DateTimeZone;
use DGL\Frontend\Frontend;
use DGL\Index\ItemsTable;
use DGL\PostTypes;
use DGL\Statuses;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class Ics {

	public const QUERY_VAR = 'dgl_ics';

	/** The query var value that means the feed rather than one event. */
	public const FEED = 'calendar';

	/** How far ahead the feed looks for a next date. */
	public const FEED_MONTHS = 12;

	/* ---------------------------------------------------------------------
	 * Routing
	 * ------------------------------------------------------------------ */

	public static function add_rules(): void {
		$slug = PostTypes::definitions()[ PostTypes::EVENT ]['slug'];

		// Both before the post type's own rule, or "x.ics" is read as an event slug.
		add_rewrite_rule( '^' . $slug . '/' . self::FEED . '\.ics$', 'index.php?' . self::QUERY_VAR . '=' . self::FEED, 'top' );
		add_rewrite_rule( '^' . $slug . '/([^/]+)\.ics$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/** The download address for one event, or '' when it has nothing to put in a calendar. */
	public static function url_for( WP_Post $post ): string {
		if ( PostTypes::EVENT !== $post->post_type || '' === (string) get_post_meta( (int) $post->ID, 'dgl_start_datetime', true ) ) {
			return '';
		}

		return self::base() . rawurlencode( (string) $post->post_name ) . '.ics';
	}

	public static function feed_url(): string {
		return self::base() . self::FEED . '.ics';
	}

	private static function base(): string {
		return home_url( '/' . PostTypes::definitions()[ PostTypes::EVENT ]['slug'] . '/' );
	}

	/**
	 * Send the file and stop, or hand a bad slug to the theme's 404.
	 */
	public static function serve(): void {
		$what = trim( (string) get_query_var( self::QUERY_VAR, '' ) );

		if ( '' === $what ) {
			return;
		}

		$response = self::respond( $what );

		if ( null === $response ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: ' . ( self::FEED === $what ? 'inline' : 'attachment' ) . '; filename="' . $response['filename'] . '"' );
		header( 'X-Robots-Tag: noindex' );
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar, escaped by Ics::escape().
		exit;
	}

	/**
	 * What a request gets, without sending it. Null is a 404.
	 *
	 * @return array{filename: string, body: string}|null
	 */
	public static function respond( string $what ): ?array {
		if ( ! PostTypes::is_enabled( PostTypes::EVENT ) ) {
			return null;
		}

		if ( self::FEED === $what ) {
			return [ 'filename' => self::FEED . '.ics', 'body' => self::feed() ];
		}

		$post = get_page_by_path( $what, OBJECT, PostTypes::EVENT );

		if ( ! $post instanceof WP_Post || Statuses::LIVE !== $post->post_status || '' === self::url_for( $post ) ) {
			return null;
		}

		return [
			'filename' => sanitize_file_name( (string) $post->post_name ) . '.ics',
			'body'     => self::build( [ self::vevent( $post ) ], html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
		];
	}

	/* ---------------------------------------------------------------------
	 * Building, from WordPress
	 * ------------------------------------------------------------------ */

	/** Every live event with a date, as one calendar. */
	public static function feed(): string {
		$now    = Series::now();
		$blocks = [];

		foreach ( ItemsTable::events_between( $now->format( 'Y-m-d H:i:s' ), $now->modify( '+' . self::FEED_MONTHS . ' months' )->format( 'Y-m-d H:i:s' ) ) as $post_id ) {
			$post = get_post( $post_id );

			if ( $post instanceof WP_Post && Statuses::LIVE === $post->post_status && '' !== self::url_for( $post ) ) {
				$blocks[] = self::vevent( $post );
			}
		}

		/* translators: %s: site name. */
		return self::build( $blocks, sprintf( __( '%s events', 'dgl-platform' ), (string) get_bloginfo( 'name' ) ), true );
	}

	/**
	 * One event's VEVENT lines, unfolded.
	 *
	 * @return string[]
	 */
	public static function vevent( WP_Post $post ): array {
		$id   = (int) $post->ID;
		$tz   = wp_timezone();
		$tzid = self::tzid( $tz );
		$rule = Series::rule_for( $id );

		$start = self::parse( (string) get_post_meta( $id, 'dgl_start_datetime', true ), $tz );
		$end   = self::parse( (string) get_post_meta( $id, 'dgl_end_datetime', true ), $tz );

		if ( null !== $rule ) {
			$first = Occurrences::first( $rule );
			if ( null !== $first ) {
				$start = $first->start;
				$end   = $first->end;
			}
		}

		if ( null === $start ) {
			return [];
		}

		if ( null !== $end && $end <= $start ) {
			$end = null;
		}

		$modified = (string) $post->post_modified_gmt;
		$stamp    = self::parse( '0000-00-00 00:00:00' === $modified || '' === $modified ? (string) $post->post_date_gmt : $modified, new DateTimeZone( 'UTC' ) ) ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$summary  = trim( (string) Frontend::value( $post, 'summary' ) );
		$link     = (string) get_permalink( $post );
		$describe = '' === $summary ? $link : $summary . "\n\n" . $link;
		$online   = trim( (string) Frontend::value( $post, 'online_url' ) );
		$format   = (string) Frontend::value( $post, 'format' );

		if ( '' !== $online && in_array( $format, [ 'online', 'hybrid' ], true ) ) {
			/* translators: %s: a web address. */
			$describe .= "\n" . sprintf( __( 'Join online: %s', 'dgl-platform' ), $online );
		}

		$lines = [
			'BEGIN:VEVENT',
			'UID:dgl-' . $id . '@' . self::host(),
			'DTSTAMP:' . $stamp->format( 'Ymd\THis\Z' ),
			'LAST-MODIFIED:' . $stamp->format( 'Ymd\THis\Z' ),
			self::dt( 'DTSTART', $start, $tzid ),
		];

		if ( null !== $end ) {
			$lines[] = self::dt( 'DTEND', $end, $tzid );
		}

		$lines[] = 'SUMMARY:' . self::escape( html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$lines[] = 'DESCRIPTION:' . self::escape( $describe );

		$where = self::location( $post );
		if ( '' !== $where ) {
			$lines[] = 'LOCATION:' . self::escape( $where );
		}

		$lines[] = 'URL:' . $link;
		$lines[] = 'STATUS:' . ( Cancel::is_cancelled( $id ) ? 'CANCELLED' : 'CONFIRMED' );

		if ( null !== $rule ) {
			$lines[] = 'RRULE:' . self::rrule( $rule );
			foreach ( self::exdates( $rule, $tzid ) as $exdate ) {
				$lines[] = $exdate;
			}
		}

		$lines[] = 'END:VEVENT';

		return $lines;
	}

	/** Venue, address and postcode in one line, or "Online". */
	public static function location( WP_Post $post ): string {
		$format = (string) Frontend::value( $post, 'format' );
		$parts  = [];

		foreach ( [ 'venue_name', 'address', 'postcode' ] as $key ) {
			$value = trim( (string) Frontend::value( $post, $key ) );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}

		if ( 'online' === $format ) {
			return __( 'Online', 'dgl-platform' );
		}

		$where = implode( ', ', $parts );

		if ( 'hybrid' === $format ) {
			/* translators: %s: a place. */
			return '' === $where ? __( 'Online', 'dgl-platform' ) : sprintf( __( '%s, and online', 'dgl-platform' ), $where );
		}

		return $where;
	}

	/* ---------------------------------------------------------------------
	 * Pure pieces, unit-tested
	 * ------------------------------------------------------------------ */

	/**
	 * A whole calendar from VEVENT blocks, folded and CRLF-terminated.
	 *
	 * @param array<int, string[]> $blocks
	 */
	public static function build( array $blocks, string $name, bool $subscribable = false ): string {
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//DGLP Platform//Events//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::escape( $name ),
		];

		if ( $subscribable ) {
			$lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT12H';
			$lines[] = 'X-PUBLISHED-TTL:PT12H';
		}

		foreach ( $blocks as $block ) {
			foreach ( $block as $line ) {
				$lines[] = $line;
			}
		}

		$lines[] = 'END:VCALENDAR';

		return implode( '', array_map( static fn( string $l ): string => self::fold( $l ) . "\r\n", $lines ) );
	}

	/** The RFC 5545 rule for a series. UNTIL is in UTC, as the RFC wants when DTSTART carries a TZID. */
	public static function rrule( Rule $rule ): string {
		$until = 'UNTIL=' . $rule->until->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
		$days  = static fn( array $iso ): string => implode( ',', array_map( [ self::class, 'byday' ], $iso ) );

		return match ( $rule->freq ) {
			Rule::WEEKLY      => 'FREQ=WEEKLY;BYDAY=' . $days( $rule->weekdays ) . ';' . $until,
			Rule::FORTNIGHTLY => 'FREQ=WEEKLY;INTERVAL=2;WKST=MO;BYDAY=' . $days( $rule->weekdays ) . ';' . $until,
			default           => 'FREQ=MONTHLY;' . match ( $rule->monthly ) {
				Rule::BY_NTH  => 'BYDAY=' . $rule->nth . self::byday( $rule->weekday ),
				Rule::BY_LAST => 'BYDAY=-1' . self::byday( $rule->weekday ),
				default       => 'BYMONTHDAY=' . $rule->day,
			} . ';' . $until,
		};
	}

	/**
	 * One EXDATE line per skipped or cancelled date, at the series' start time.
	 *
	 * @return string[]
	 */
	public static function exdates( Rule $rule, string $tzid ): array {
		$out = [];

		foreach ( array_unique( array_merge( $rule->skip, $rule->cancelled ) ) as $date ) {
			$day = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $rule->start->getTimezone() );
			if ( false === $day ) {
				continue;
			}
			$out[] = self::dt( 'EXDATE', $rule->occurrence_on( $day )->start, $tzid );
		}

		return $out;
	}

	/** ISO weekday 1 to 7 as the RFC's two letters. */
	public static function byday( int $iso ): string {
		return [ 1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU' ][ $iso ] ?? 'MO';
	}

	/**
	 * A date-time property. With a named zone it is local time and a TZID;
	 * with an offset-only site zone it is UTC, which every client reads.
	 */
	public static function dt( string $name, DateTimeImmutable $at, string $tzid ): string {
		if ( '' === $tzid ) {
			return $name . ':' . $at->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
		}

		return $name . ';TZID=' . $tzid . ':' . $at->format( 'Ymd\THis' );
	}

	/** The zone's IANA name, or '' when the site only has a UTC offset. */
	public static function tzid( DateTimeZone $tz ): string {
		$name = $tz->getName();

		return str_contains( $name, '/' ) || 'UTC' === $name ? $name : '';
	}

	/** Backslash, semicolon, comma and newlines, as the RFC escapes text. */
	public static function escape( string $text ): string {
		$text = str_replace( "\r\n", "\n", $text );

		return str_replace( [ '\\', ';', ',', "\n" ], [ '\\\\', '\;', '\,', '\n' ], $text );
	}

	/** Lines longer than 75 octets continue on the next line after a space, never splitting a UTF-8 character. */
	public static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}

		$out   = '';
		$chunk = '';
		$width = 75;

		foreach ( mb_str_split( $line, 1, 'UTF-8' ) as $char ) {
			if ( strlen( $chunk ) + strlen( $char ) > $width ) {
				$out  .= $chunk . "\r\n ";
				$chunk = '';
				$width = 74;
			}
			$chunk .= $char;
		}

		return $out . $chunk;
	}

	private static function parse( string $wall, DateTimeZone $tz ): ?DateTimeImmutable {
		if ( '' === trim( $wall ) ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $wall, $tz );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	private static function host(): string {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return '' === $host ? 'dglp.local' : $host;
	}
}

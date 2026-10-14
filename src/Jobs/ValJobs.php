<?php
/**
 * Jobs from Voluntary Action Leeds, fetched overnight.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;

defined( 'ABSPATH' ) || exit;

/**
 * VAL lists third-sector vacancies at val.org.uk/jobs, on WP Job Manager.
 * That plugin publishes every live vacancy as an RSS feed, so we read the
 * feed rather than the page: a redesign of VAL's theme cannot break it.
 * The feed has no logos, so the listing endpoint the jobs page itself
 * loads from is read too, for the employer's logo only. A logo that does
 * not come back is an initial, never a failure.
 *
 * Logos are copied to this site's uploads, shrunk to 128px, rather than
 * shown from VAL's. Their firewall refused some logo requests from an
 * ordinary browser in testing (14 October 2026), and a visitor's browser
 * should not be calling another organisation's server just to see our
 * page. A logo already copied is not fetched again; one no job uses any
 * more is deleted.
 *
 * Once a night, never on a page render: a visitor is never made to wait
 * on somebody else's server. The last good list is kept, so a night VAL
 * is down or answers with an error page shows yesterday's jobs rather
 * than none. A list that has not refreshed for a week is withdrawn, so
 * the block cannot quietly advertise closed jobs for months.
 *
 * Everything that comes back is untrusted: text is stripped to plain
 * text and capped, a link or logo is kept only when it points at VAL's
 * own site over https, and a logo is kept only when it really is an
 * image of the type its name says.
 *
 * VAL's host sits behind a firewall that refuses requests pretending to
 * be a browser. WordPress's own user agent is let through (checked 14
 * October 2026), so it is left as it is.
 */
final class ValJobs {

	public const FEED_URL     = 'https://val.org.uk/?feed=job_feed&posts_per_page=100';
	public const LISTINGS_URL = 'https://val.org.uk/jm-ajax/get_listings/?per_page=100';
	public const JOBS_URL     = 'https://val.org.uk/jobs/';

	/** The only hosts a job's link or logo may point at. */
	public const HOSTS = [ 'val.org.uk', 'www.val.org.uk' ];

	/** The daily run, and the one-off first run on a fresh install. */
	public const HOOK      = 'dgl_val_jobs_refresh';
	public const HOOK_SOON = 'dgl_val_jobs_first';

	/** The local hour the daily run is due. */
	public const HOUR = 2;

	/** The last good list, and how the last attempt went. */
	public const OPTION      = 'dgl_val_jobs';
	public const OPTION_LAST = 'dgl_val_jobs_last';

	/** A list older than this is not shown. */
	public const STALE_AFTER = 7 * DAY_IN_SECONDS;

	/** The most jobs kept. */
	public const MAX_JOBS = 100;

	/** Seconds to wait for VAL before giving up until tomorrow. */
	public const TIMEOUT = 15;

	/** Where the copied logos live under uploads, and how big they are kept. */
	public const LOGO_DIR  = 'dgl-val-logos';
	public const LOGO_SIZE = 128;

	/** A logo file's name: a hash of where it came from, and its type. */
	private const LOGO_NAME = '/^[a-f0-9]{32}\.(jpg|png|gif|webp)$/';

	/** What a logo may be. */
	private const LOGO_TYPES = [
		'jpg'  => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	];

	public static function init(): void {
		add_action( self::HOOK, [ self::class, 'refresh' ], 10, 0 );
		add_action( self::HOOK_SOON, [ self::class, 'refresh' ], 10, 0 );
	}

	/**
	 * Keep the nightly run scheduled, and on a site that has never fetched,
	 * fetch in a minute rather than waiting for tonight.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( self::next_run( time(), wp_timezone() ), 'daily', self::HOOK );
		}

		if ( false === get_option( self::OPTION_LAST, false ) && ! wp_next_scheduled( self::HOOK_SOON ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK_SOON );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOOK_SOON );
	}

	/**
	 * The next HOUR o'clock in the site's timezone, as a timestamp. Pure.
	 *
	 * A 'daily' interval is a fixed 24 hours, so across a clock change the
	 * run moves by an hour, between one and three in the morning. Still
	 * overnight, which is all that matters.
	 */
	public static function next_run( int $now, DateTimeZone $tz, int $hour = self::HOUR ): int {
		$today = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( $hour, 0 );

		return $today->getTimestamp() > $now ? $today->getTimestamp() : $today->modify( '+1 day' )->getTimestamp();
	}

	/**
	 * Fetch, and keep the list if it is good. Returns how it went, which is
	 * also what the Health screen and `wp dgl jobs status` read.
	 *
	 * @return array{at:int, ok:bool, count:int, error:string}
	 */
	public static function refresh(): array {
		$body  = self::get( self::FEED_URL );
		$jobs  = is_string( $body ) ? self::parse_feed( $body ) : null;
		$error = is_string( $body ) ? '' : $body->get_error_message();

		if ( is_string( $body ) && null === $jobs ) {
			$error = __( 'VAL answered, but not with a job feed.', 'dgl-platform' );
		}

		if ( null !== $jobs ) {
			$listings = self::get( self::LISTINGS_URL );
			$jobs     = self::keep_logos( self::with_logos( $jobs, is_string( $listings ) ? self::parse_logos( $listings ) : [] ) );

			update_option( self::OPTION, [ 'jobs' => $jobs, 'fetched' => time() ], false );
		}

		$last = [
			'at'    => time(),
			'ok'    => null !== $jobs,
			'count' => null === $jobs ? 0 : count( $jobs ),
			'error' => $error,
		];

		update_option( self::OPTION_LAST, $last, false );

		return $last;
	}

	/**
	 * One GET, as a body or an error.
	 *
	 * wp_remote_get, not the "safe" variant: both addresses are constants
	 * in this file, not anything a user typed, and the safe variant
	 * refuses a host PHP cannot resolve itself, which on a server that
	 * reaches out through a proxy is every host.
	 */
	private static function get( string $url ): string|\WP_Error {
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
			return new \WP_Error( 'dgl_val_http', sprintf( __( 'VAL answered with HTTP %d.', 'dgl-platform' ), $code ) );
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Copy each job's logo into uploads, unless it is there already, and
	 * delete the copies no job uses any more. A job whose logo cannot be
	 * fetched, is not an image or cannot be written gets no logo file.
	 *
	 * @param array<int, array<string, string>> $jobs
	 * @return array<int, array<string, string>>
	 */
	private static function keep_logos( array $jobs ): array {
		$dir  = self::logo_dir();
		$kept = [];

		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			return array_map( static fn( array $job ): array => [ 'logo_file' => '' ] + $job, $jobs );
		}

		foreach ( $jobs as $i => $job ) {
			$name = self::logo_name( (string) ( $job['logo'] ?? '' ) );

			if ( '' !== $name && ! isset( $kept[ $name ] ) ) {
				$path = $dir . '/' . $name;
				$full = self::full_size( $job['logo'] );

				// The original first, so a wide logo is whole; VAL's square thumbnail if that fails.
				$kept[ $name ] = file_exists( $path ) || ( $full !== $job['logo'] && self::copy_logo( $full, $path ) ) || self::copy_logo( $job['logo'], $path );
			}

			$jobs[ $i ]['logo_file'] = '' !== $name && $kept[ $name ] ? $name : '';
		}

		foreach ( (array) glob( $dir . '/*' ) as $path ) {
			$file = basename( (string) $path );

			if ( preg_match( self::LOGO_NAME, $file ) && empty( $kept[ $file ] ) ) {
				wp_delete_file( (string) $path );
			}
		}

		return $jobs;
	}

	/**
	 * Fetch one logo, check it is the image it claims to be, and keep it no
	 * bigger than LOGO_SIZE square.
	 */
	private static function copy_logo( string $url, string $path ): bool {
		$body = self::get( $url );

		if ( ! is_string( $body ) || '' === $body ) {
			return false;
		}

		$info = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a non-image is an expected answer, not a warning.
		$ext  = pathinfo( $path, PATHINFO_EXTENSION );

		if ( ! is_array( $info ) || ( self::LOGO_TYPES[ $ext ] ?? '' ) !== ( $info['mime'] ?? '' ) ) {
			return false;
		}

		if ( false === file_put_contents( $path, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own uploads folder, written by cron.
			return false;
		}

		$editor = wp_get_image_editor( $path );

		// Smaller than the box already, or no image library: the original is kept as it is.
		if ( ! is_wp_error( $editor ) && ! is_wp_error( $editor->resize( self::LOGO_SIZE, self::LOGO_SIZE, false ) ) ) {
			$editor->save( $path );
		}

		return true;
	}

	/**
	 * The uploaded original behind a WordPress thumbnail: "logo-150x150.png"
	 * is "logo.png". VAL's thumbnails are square crops, which cut a wide
	 * logo in half; the original, shrunk to fit, shows all of it. Pure.
	 */
	public static function full_size( string $url ): string {
		return (string) preg_replace( '/-\d+x\d+(\.[a-z]+)$/i', '$1', $url );
	}

	/**
	 * The file a logo is kept as: a hash of its address and its type, or ''
	 * when the address does not end in an image type we keep. Pure.
	 */
	public static function logo_name( string $url ): string {
		if ( '' === self::safe_url( $url ) ) {
			return '';
		}

		$ext = strtolower( pathinfo( (string) parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$ext = 'jpeg' === $ext ? 'jpg' : $ext;

		return isset( self::LOGO_TYPES[ $ext ] ) ? md5( $url ) . '.' . $ext : '';
	}

	/** The folder the logos are copied to, or '' when uploads is unavailable. */
	private static function logo_dir(): string {
		$uploads = wp_upload_dir( null, false );

		return empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ? $uploads['basedir'] . '/' . self::LOGO_DIR : '';
	}

	/**
	 * A job's copied logo as an address on this site, or '' when it has none.
	 *
	 * @param array<string, string> $job
	 */
	public static function logo_url( array $job ): string {
		$name = (string) ( $job['logo_file'] ?? '' );
		$dir  = self::logo_dir();

		if ( ! preg_match( self::LOGO_NAME, $name ) || '' === $dir || ! file_exists( $dir . '/' . $name ) ) {
			return '';
		}

		return wp_upload_dir( null, false )['baseurl'] . '/' . self::LOGO_DIR . '/' . $name;
	}

	/**
	 * The stored jobs, newest first, or none when the list is stale.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function jobs( ?int $now = null ): array {
		$store = get_option( self::OPTION, [] );

		return self::fresh( is_array( $store ) ? $store : [], $now ?? time() );
	}

	/**
	 * The jobs in a stored list, unless it is too old to trust. Pure.
	 *
	 * @param array<string, mixed> $store
	 * @return array<int, array<string, string>>
	 */
	public static function fresh( array $store, int $now ): array {
		$fetched = (int) ( $store['fetched'] ?? 0 );

		if ( $fetched <= 0 || $now - $fetched > self::STALE_AFTER || ! is_array( $store['jobs'] ?? null ) ) {
			return [];
		}

		return array_values( array_filter( $store['jobs'], 'is_array' ) );
	}

	/** When the stored list was fetched, or 0. */
	public static function fetched(): int {
		$store = get_option( self::OPTION, [] );

		return is_array( $store ) ? (int) ( $store['fetched'] ?? 0 ) : 0;
	}

	/**
	 * How the last attempt went, or null before the first.
	 *
	 * @return array{at:int, ok:bool, count:int, error:string}|null
	 */
	public static function last(): ?array {
		$last = get_option( self::OPTION_LAST, false );

		if ( ! is_array( $last ) ) {
			return null;
		}

		return [
			'at'    => (int) ( $last['at'] ?? 0 ),
			'ok'    => (bool) ( $last['ok'] ?? false ),
			'count' => (int) ( $last['count'] ?? 0 ),
			'error' => (string) ( $last['error'] ?? '' ),
		];
	}

	/**
	 * The jobs in a WP Job Manager feed, or null when it is not one. A
	 * feed with no items is an empty list, not a failure: VAL may simply
	 * have no vacancies. Pure.
	 *
	 * @return array<int, array<string, string>>|null
	 */
	public static function parse_feed( string $xml ): ?array {
		$doc = self::xml( $xml );

		if ( null === $doc || 'rss' !== $doc->documentElement?->localName ) {
			return null;
		}

		$channel = $doc->getElementsByTagName( 'channel' )->item( 0 );

		if ( ! $channel instanceof DOMElement ) {
			return null;
		}

		$jobs = [];

		foreach ( $channel->getElementsByTagName( 'item' ) as $item ) {
			$job = self::job( $item );

			if ( null !== $job && ! isset( $jobs[ $job['url'] ] ) ) {
				$jobs[ $job['url'] ] = $job;
			}

			if ( count( $jobs ) >= self::MAX_JOBS ) {
				break;
			}
		}

		return array_values( $jobs );
	}

	/**
	 * One feed item as a job, or null when it has no title or no safe link.
	 *
	 * The vacancy's own fields are `job_listing:` elements. They are read
	 * by local name, because their namespace is VAL's site address and
	 * would change with it.
	 *
	 * @return array<string, string>|null
	 */
	private static function job( DOMElement $item ): ?array {
		$fields = [];

		foreach ( $item->childNodes as $child ) {
			if ( $child instanceof DOMElement && ! isset( $fields[ $child->localName ] ) ) {
				$fields[ $child->localName ] = $child->textContent;
			}
		}

		$title = self::text( $fields['title'] ?? '', 200 );
		$url   = self::safe_url( trim( $fields['link'] ?? '' ) );

		if ( '' === $title || '' === $url ) {
			return null;
		}

		$posted = strtotime( trim( $fields['pubDate'] ?? '' ) );

		return [
			'title'    => $title,
			'url'      => $url,
			'company'  => self::text( $fields['company'] ?? '', 120 ),
			'location' => self::text( $fields['location'] ?? '', 120 ),
			'type'     => self::text( $fields['job_type'] ?? '', 40 ),
			'salary'   => self::salary( self::text( $fields['salary'] ?? '', 120 ) ),
			'posted'   => false === $posted ? '' : gmdate( 'Y-m-d', $posted ),
			'logo'     => '',
		];
	}

	/**
	 * Each job's logo, by its link, from the listing endpoint's answer. The
	 * plugin's grey placeholder is left out, so a job without a logo gets
	 * an initial instead. Pure.
	 *
	 * @return array<string, string>
	 */
	public static function parse_logos( string $json ): array {
		$data = json_decode( $json, true );
		$html = is_array( $data ) ? ( $data['html'] ?? '' ) : '';

		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return [];
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><ul>' . $html . '</ul>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$logos = [];
		$xpath = new DOMXPath( $doc );

		foreach ( $xpath->query( '//li[contains(concat(" ", normalize-space(@class), " "), " job_listing ")]' ) ?: [] as $li ) {
			$link = $xpath->query( './/a[@href]', $li )->item( 0 );
			$img  = $xpath->query( './/img[contains(concat(" ", normalize-space(@class), " "), " company_logo ")]', $li )->item( 0 );

			if ( ! $link instanceof DOMElement || ! $img instanceof DOMElement ) {
				continue;
			}

			$url  = self::safe_url( $link->getAttribute( 'href' ) );
			$logo = self::safe_url( $img->getAttribute( 'src' ) );

			if ( '' !== $url && '' !== $logo && ! str_contains( $logo, '/wp-job-manager/assets/' ) ) {
				$logos[ $url ] = $logo;
			}
		}

		return $logos;
	}

	/**
	 * The jobs with their logos filled in where there is one. Pure.
	 *
	 * @param array<int, array<string, string>> $jobs
	 * @param array<string, string>             $logos
	 * @return array<int, array<string, string>>
	 */
	public static function with_logos( array $jobs, array $logos ): array {
		foreach ( $jobs as $i => $job ) {
			$jobs[ $i ]['logo'] = $logos[ $job['url'] ] ?? '';
		}

		return $jobs;
	}

	/**
	 * A bare number as money: "23953" reads as "£23,953". Anything with
	 * words in it is left exactly as the employer wrote it. Pure.
	 */
	public static function salary( string $salary ): string {
		$plain = str_replace( ',', '', $salary );

		if ( '' === $plain || ! preg_match( '/^\d+(\.\d{1,2})?$/', $plain ) ) {
			return $salary;
		}

		$pence = str_contains( $plain, '.' ) && '00' !== str_pad( explode( '.', $plain )[1], 2, '0' );

		return '£' . number_format( (float) $plain, $pence ? 2 : 0 );
	}

	/**
	 * Where, hours and pay, on one line. Pure.
	 *
	 * @param array<string, string> $job
	 */
	public static function meta( array $job ): string {
		return implode( ' · ', array_filter( [ (string) ( $job['location'] ?? '' ), (string) ( $job['type'] ?? '' ), (string) ( $job['salary'] ?? '' ) ], static fn( string $part ): bool => '' !== $part ) );
	}

	/**
	 * The letter drawn when an employer has no logo. Pure.
	 *
	 * @param array<string, string> $job
	 */
	public static function initial( array $job ): string {
		$name = (string) preg_replace( '/^the\s+/iu', '', trim( (string) ( $job['company'] ?? '' ) ) );
		$name = '' === $name ? trim( (string) ( $job['title'] ?? '' ) ) : $name;

		preg_match( '/[\p{L}\p{N}]/u', $name, $first );

		return mb_strtoupper( $first[0] ?? '?' );
	}

	/**
	 * A link or image on VAL's own site over https, or ''. Pure.
	 */
	public static function safe_url( string $url ): string {
		$url   = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$parts = parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return '';
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
			return '';
		}

		if ( ! in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), self::HOSTS, true ) ) {
			return '';
		}

		return preg_match( '/[\s"\'<>]/', $url ) ? '' : $url;
	}

	/**
	 * Plain text from something that may carry tags or entities, on one
	 * line and no longer than $max characters. Pure.
	 */
	public static function text( string $raw, int $max ): string {
		$text = html_entity_decode( strip_tags( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
	}

	/**
	 * A feed as a document, or null. No network, no entity expansion.
	 */
	private static function xml( string $xml ): ?DOMDocument {
		if ( '' === trim( $xml ) ) {
			return null;
		}

		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$ok       = $doc->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $ok ? $doc : null;
	}

	/** The verdicts the Health screen draws. */
	public const GOOD = 'good';
	public const WARN = 'warn';
	public const BAD  = 'bad';

	/**
	 * How worried to be: good after a recent good fetch, amber when the
	 * last night failed but the list is still current, red once the list
	 * has gone stale or has never been fetched at all. Pure.
	 *
	 * @param array{at:int, ok:bool, count:int, error:string}|null $last
	 */
	public static function verdict( ?array $last, int $fetched, int $now ): string {
		if ( $fetched <= 0 || $now - $fetched > self::STALE_AFTER ) {
			return null === $last ? self::WARN : self::BAD;
		}

		if ( null !== $last && ! $last['ok'] ) {
			return self::WARN;
		}

		return $now - $fetched > 2 * DAY_IN_SECONDS ? self::WARN : self::GOOD;
	}
}

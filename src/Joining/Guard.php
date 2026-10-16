<?php
/**
 * Keeping robots off the join form.
 *
 * Three cheap checks before an email is ever sent: a honeypot field that
 * people never see and robots fill, a stamp that says when the form was
 * drawn so a submit two seconds later is not a person, and a rate limit
 * per address and per network address so one script cannot make the site
 * send hundreds of links. A robot is told "sent" and nothing is sent; a
 * person over the limit is told to wait. Turnstile can sit in front of
 * this later; it does not replace it.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Joining;

defined( 'ABSPATH' ) || exit;

final class Guard {

	/**
	 * The honeypot's field name. Plausible to a robot, never shown to a
	 * person, and nothing a browser's autofill recognises: "website" or
	 * "url" in the name invites Chrome to fill it from a saved address.
	 */
	public const HONEYPOT = 'dgl_extra_field';

	/** The stamp field: when the form was drawn, signed. */
	public const STAMP = 'dgl_form_stamp';

	/** A person needs at least this long to read the page and type an address. */
	public const MIN_SECONDS = 3;

	/** A stamp older than this is a stale tab, not a robot; it is let through. */
	public const MAX_SECONDS = 86400;

	/** Starts allowed per email address in the window. */
	public const PER_EMAIL = 3;

	/** Starts allowed per network address in the window. */
	public const PER_IP = 10;

	/** The window, in seconds. */
	public const WINDOW = 3600;

	/** Lookups against the list one sign-up may make in the window. */
	public const PER_SIGNUP_LOOKUPS = 60;

	/**
	 * The stamp to put in the form: the time, signed so it cannot be forged.
	 */
	public static function stamp( ?int $now = null, string $context = 'join' ): string {
		$now = $now ?? time();

		return $now . '.' . self::sign( (string) $now, $context );
	}

	/**
	 * Whether the submission came from a robot, by the honeypot or the clock.
	 *
	 * @param array<string, mixed> $post The raw POST.
	 */
	public static function is_robot( array $post, ?int $now = null, string $context = 'join' ): bool {
		$now = $now ?? time();

		if ( '' !== trim( (string) ( $post[ self::HONEYPOT ] ?? '' ) ) ) {
			return true;
		}

		$stamp = (string) ( $post[ self::STAMP ] ?? '' );

		if ( '' === $stamp ) {
			// No stamp at all: the form was not ours to begin with.
			return true;
		}

		[ $at, $sig ] = array_pad( explode( '.', $stamp, 2 ), 2, '' );

		if ( ! ctype_digit( $at ) || ! hash_equals( self::sign( $at, $context ), $sig ) ) {
			return true;
		}

		$age = $now - (int) $at;

		return $age >= 0 && $age < self::MIN_SECONDS;
	}

	/**
	 * Whether this address or network is over the limit. Counts the attempt.
	 *
	 * @return string '' when fine, else the message for the person.
	 */
	public static function limited( string $email, string $ip, string $context = 'join', ?int $per_email = null, ?int $per_ip = null ): string {
		$email     = strtolower( trim( $email ) );
		$per_email = $per_email ?? self::PER_EMAIL;
		$per_ip    = $per_ip ?? self::PER_IP;

		if ( '' !== $email && self::bump( 'email_' . md5( $email ), $context ) > $per_email ) {
			return 'join' === $context
				? __( 'Enough links have been sent to that address for now. Check your inbox and junk folder, or try again in an hour.', 'dgl-platform' )
				: __( 'Enough has been sent from that address for now. Try again in an hour.', 'dgl-platform' );
		}

		if ( '' !== $ip && self::bump( 'ip_' . md5( $ip ), $context ) > $per_ip ) {
			return __( 'Too many attempts from your connection. Try again in an hour.', 'dgl-platform' );
		}

		return '';
	}

	/**
	 * Whether one sign-up has asked the list too often. Counts the ask.
	 *
	 * @return string '' when fine, else the message for the person.
	 */
	public static function limited_lookup( int $signup_id ): string {
		if ( self::bump( 'lookup_' . $signup_id ) > self::PER_SIGNUP_LOOKUPS ) {
			return __( 'Too many checks for now. Carry on and the list is checked when you press Continue.', 'dgl-platform' );
		}

		return '';
	}

	/** Forget the counts, for tests and for the team. */
	public static function reset( string $email = '', string $ip = '', string $context = 'join' ): void {
		if ( '' !== $email ) {
			delete_transient( self::key( 'email_' . md5( strtolower( trim( $email ) ) ), $context ) );
		}
		if ( '' !== $ip ) {
			delete_transient( self::key( 'ip_' . md5( $ip ), $context ) );
		}
	}

	/** The caller's network address, or '' when unknown. */
	public static function ip(): string {
		return self::client_ip( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated as an IP below.
	}

	/**
	 * The visitor's own address from a request's server values.
	 *
	 * Behind Cloudflare every request arrives from one of its addresses, and
	 * counting those would put every visitor in one bucket: one script could
	 * lock the forms for everybody. Cloudflare's header is believed only when
	 * the request really came from Cloudflare, so nobody can set it to dodge
	 * the limit. Anything that is not an IP address counts as unknown. Pure.
	 *
	 * @param array<string, mixed> $server
	 */
	public static function client_ip( array $server ): string {
		$remote = self::valid_ip( (string) ( $server['REMOTE_ADDR'] ?? '' ) );

		if ( '' !== $remote && self::is_cloudflare( $remote ) ) {
			$visitor = self::valid_ip( (string) ( $server['HTTP_CF_CONNECTING_IP'] ?? '' ) );

			if ( '' !== $visitor ) {
				return $visitor;
			}
		}

		return $remote;
	}

	/** Cloudflare's published ranges, from https://www.cloudflare.com/ips/ (October 2026). */
	private const CLOUDFLARE = [
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
		'108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
		'162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
		'2a06:98c0::/29', '2c0f:f248::/32',
	];

	public static function is_cloudflare( string $ip ): bool {
		foreach ( self::CLOUDFLARE as $range ) {
			if ( self::in_range( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	private static function valid_ip( string $ip ): string {
		$ip = trim( $ip );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private static function in_range( string $ip, string $cidr ): bool {
		[ $net, $bits ] = explode( '/', $cidr );
		$ip_bin         = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- false is the answer we want.
		$net_bin        = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}

		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );

		if ( substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
			return false;
		}

		$rest = $bits % 8;

		if ( 0 === $rest ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;

		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $net_bin[ $bytes ] ) & $mask );
	}

	/**
	 * Whether a named thing (a venue, say) is over its own limit. Counts the attempt.
	 */
	public static function over( string $what, string $context, int $max ): bool {
		return self::bump( sanitize_key( $what ), $context ) > $max;
	}

	/**
	 * Count one attempt in a fixed window from the first one. The window does
	 * not restart on every attempt: if it did, a steady trickle of requests
	 * would keep somebody locked out for good.
	 */
	private static function bump( string $what, string $context = 'join' ): int {
		$key  = self::key( $what, $context );
		$now  = time();
		$seen = get_transient( $key );

		if ( ! is_array( $seen ) || (int) ( $seen['until'] ?? 0 ) <= $now ) {
			$seen = [ 'count' => 0, 'until' => $now + self::WINDOW ];
		}

		$seen['count'] = (int) $seen['count'] + 1;

		set_transient( $key, $seen, max( 1, (int) $seen['until'] - $now ) );

		return $seen['count'];
	}

	/**
	 * Each form keeps its own counts and its own signature, so an enquiry
	 * never spends a sign-up's allowance and a stamp from one form is not
	 * good on another.
	 */
	private static function key( string $what, string $context = 'join' ): string {
		return 'dgl_' . sanitize_key( $context ) . '_' . $what;
	}

	private static function sign( string $at, string $context = 'join' ): string {
		return substr( hash_hmac( 'sha256', 'dgl-' . sanitize_key( $context ) . '-stamp|' . $at, wp_salt( 'nonce' ) ), 0, 20 );
	}
}

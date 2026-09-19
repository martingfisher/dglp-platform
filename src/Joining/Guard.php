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

	/** The honeypot's field name: plausible to a robot, never shown to a person. */
	public const HONEYPOT = 'dgl_website_url';

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

	/**
	 * The stamp to put in the form: the time, signed so it cannot be forged.
	 */
	public static function stamp( ?int $now = null ): string {
		$now = $now ?? time();

		return $now . '.' . self::sign( (string) $now );
	}

	/**
	 * Whether the submission came from a robot, by the honeypot or the clock.
	 *
	 * @param array<string, mixed> $post The raw POST.
	 */
	public static function is_robot( array $post, ?int $now = null ): bool {
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

		if ( ! ctype_digit( $at ) || ! hash_equals( self::sign( $at ), $sig ) ) {
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
	public static function limited( string $email, string $ip ): string {
		$email = strtolower( trim( $email ) );

		if ( '' !== $email && self::bump( 'email_' . md5( $email ) ) > self::PER_EMAIL ) {
			return __( 'Enough links have been sent to that address for now. Check your inbox and junk folder, or try again in an hour.', 'dgl-platform' );
		}

		if ( '' !== $ip && self::bump( 'ip_' . md5( $ip ) ) > self::PER_IP ) {
			return __( 'Too many attempts from your connection. Try again in an hour.', 'dgl-platform' );
		}

		return '';
	}

	/** Forget the counts, for tests and for the team. */
	public static function reset( string $email = '', string $ip = '' ): void {
		if ( '' !== $email ) {
			delete_transient( self::key( 'email_' . md5( strtolower( trim( $email ) ) ) ) );
		}
		if ( '' !== $ip ) {
			delete_transient( self::key( 'ip_' . md5( $ip ) ) );
		}
	}

	/** The caller's network address, or '' when unknown. */
	public static function ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	private static function bump( string $what ): int {
		$key   = self::key( $what );
		$count = (int) get_transient( $key ) + 1;

		set_transient( $key, $count, self::WINDOW );

		return $count;
	}

	private static function key( string $what ): string {
		return 'dgl_join_' . $what;
	}

	private static function sign( string $at ): string {
		return substr( hash_hmac( 'sha256', 'dgl-join-stamp|' . $at, wp_salt( 'nonce' ) ), 0, 20 );
	}
}

<?php
/**
 * Keeping a failed sign-in on the member area's own screen.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * A wrong password should not eject a member into WordPress.
 *
 * The member area's sign-in form is `wp_login_form()`, which posts to
 * wp-login.php. On success WordPress redirects where it was told. On failure
 * it draws its own screen: the WordPress logo, "The username Martin Fisher is
 * not registered on this site", a language picker. The first real member hit
 * that within a minute of signing up, having typed their name where their
 * email address goes, and reported the dashboard as "dumping" them somewhere.
 *
 * So a failure that started on our screen is sent back to our screen, with a
 * reason a person can act on.
 */
final class SignIn {

	/** Query flag the sign-in screen reads. */
	public const FLAG = 'dgl_signin';

	public static function init(): void {
		/*
		 * `authenticate` rather than `wp_login_failed`. The latter never fires
		 * for an empty field: WordPress returns the error before it gets that
		 * far, and the member is ejected all the same. Priority 100 so every
		 * core and plugin check has already run and the result is final.
		 */
		add_filter( 'authenticate', [ self::class, 'on_result' ], 100, 3 );
	}

	/**
	 * @param WP_User|WP_Error|null $result   What authentication decided.
	 * @param string                $username What was typed.
	 * @param string                $password What was typed.
	 * @return WP_User|WP_Error|null
	 */
	public static function on_result( $result, $username, $password ) {
		/*
		 * No special case for two empty fields. There was one, on the theory
		 * that an unsubmitted form calls authenticate with nothing; it does
		 * not, authenticate runs on a post, and the referer check above already
		 * excludes anything that did not start on our screen. All the guard
		 * did was send the one member who pressed the button with nothing in
		 * the boxes to WordPress's page. Found by trying it.
		 */
		if ( ! $result instanceof WP_Error || ! self::came_from_member_area() ) {
			return $result;
		}

		wp_safe_redirect(
			add_query_arg(
				[
					self::FLAG   => self::reason( $result ),
					'redirect_to' => rawurlencode( self::wanted() ),
				],
				Router::url()
			)
		);
		exit;
	}

	/**
	 * Which of our messages fits the error.
	 *
	 * WordPress distinguishes an unknown username from a wrong password in
	 * its own wording. That distinction is not repeated here: telling a
	 * stranger "that address has no account" confirms which addresses do.
	 */
	private static function reason( WP_Error $error ): string {
		$code = (string) $error->get_error_code();

		if ( in_array( $code, [ 'empty_username', 'empty_password' ], true ) ) {
			return 'empty';
		}

		return 'failed';
	}

	/**
	 * The message for a flag, or an empty string for no flag.
	 */
	public static function message( string $flag ): string {
		return match ( $flag ) {
			'empty'  => __( 'Type your email address and your password.', 'dgl-platform' ),
			'failed' => __( 'That email address and password did not match. You sign in with your email address, not your name. If you have forgotten the password, use the link below.', 'dgl-platform' ),
			default  => '',
		};
	}

	/**
	 * Only forms that started on the member area.
	 *
	 * Anybody signing in through wp-admin's own page keeps wp-admin's own
	 * behaviour. The check is the referer, which a browser sends on a form
	 * post and which is the only thing that says where the form was.
	 */
	private static function came_from_member_area(): bool {
		$referer = (string) wp_get_referer();

		if ( '' === $referer ) {
			return false;
		}

		$path = (string) wp_parse_url( $referer, PHP_URL_PATH );

		return str_starts_with( trim( $path, '/' ), Router::base() );
	}

	/**
	 * Where they were trying to go, carried through the round trip.
	 */
	private static function wanted(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- a destination, read only.
		$to = isset( $_POST['redirect_to'] ) ? (string) wp_unslash( $_POST['redirect_to'] ) : '';

		return '' !== $to ? $to : Router::url();
	}
}

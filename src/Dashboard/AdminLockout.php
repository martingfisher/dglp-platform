<?php
/**
 * Keeping members out of wp-admin.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

use DGL\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * A member's account is for the member area, not for WordPress.
 *
 * Members are given a role so the capability system can scope what they touch,
 * and a WordPress role comes with a WordPress admin attached to it. Left alone,
 * a member signs in and gets the black admin bar across the top of every page
 * and a working link into the dashboard behind it. Most will never click it.
 * The ones who do find a WordPress install they have no business in, cannot
 * navigate, and can still use to change their own account.
 *
 * So this is two things: the bar is hidden, and wp-admin sends them back where
 * they came from. Neither is a security boundary on its own — the capability
 * system is what actually stops a member editing another organisation's work,
 * and that is tested separately. This stops them being shown a door they were
 * never meant to see.
 *
 * Moderators keep wp-admin. Administrators are untouched.
 */
final class AdminLockout {

	public static function init(): void {
		add_filter( 'show_admin_bar', [ self::class, 'hide_admin_bar' ] );
		add_action( 'admin_init', [ self::class, 'redirect_members' ] );

		/*
		 * WordPress decides where to send somebody after they sign in, and for
		 * an account without `edit_posts` it decides on `profile.php`. So every
		 * member signing in landed in the WordPress admin, on the one screen
		 * this class allows through, and was shown the whole of wp-admin. The
		 * member area they were supposed to be using was not even linked from
		 * it. Found in a browser; nothing in the test suite could see it.
		 */
		add_filter( 'login_redirect', [ self::class, 'after_login' ], 10, 3 );

		/*
		 * Some wp-admin screens refuse a member before `admin_init` runs, so
		 * the redirect above never gets its turn and they are shown
		 * WordPress's own 403 instead. This fires immediately before that
		 * page is rendered.
		 */
		add_action( 'admin_page_access_denied', [ self::class, 'redirect_members' ] );
	}

	/**
	 * Where a member goes after signing in.
	 *
	 * Only when WordPress was going to choose for them. Somebody who followed a
	 * link into a particular screen and had to sign in on the way keeps their
	 * destination, which is how `redirect_to` is supposed to work.
	 *
	 * @param string           $to        Where WordPress intends to send them.
	 * @param string           $requested What the request asked for.
	 * @param \WP_User|\WP_Error $user    The user, or a failure.
	 */
	public static function after_login( $to, $requested, $user ) {
		if ( ! $user instanceof \WP_User || ! self::is_member_only( (int) $user->ID ) ) {
			return $to;
		}

		$requested = trim( (string) $requested );

		// A real destination the member asked for, rather than WordPress's
		// default guess. Leave it alone.
		if ( '' !== $requested
			&& ! str_contains( $requested, 'wp-admin/profile.php' )
			&& rtrim( $requested, '/' ) !== rtrim( admin_url(), '/' )
		) {
			return $to;
		}

		return Router::url();
	}

	/**
	 * Whether this account is a member and nothing more.
	 *
	 * Asked by capability rather than by role name. An administrator who has
	 * also been given the member role for testing should keep their admin, and
	 * a moderator has review work to do in wp-admin later.
	 */
	public static function is_member_only( ?int $user_id = null ): bool {
		$user = null === $user_id ? wp_get_current_user() : get_userdata( $user_id );

		if ( ! $user || 0 === (int) $user->ID ) {
			return false;
		}

		if ( ! in_array( Roles::MEMBER, (array) $user->roles, true ) ) {
			return false;
		}

		return ! user_can( $user, 'manage_options' ) && ! user_can( $user, Roles::CAP_MODERATE );
	}

	/**
	 * @param bool $show Whether WordPress intends to show the bar.
	 */
	public static function hide_admin_bar( $show ) {
		return self::is_member_only() ? false : $show;
	}

	/**
	 * Send a member who lands in wp-admin back to their own dashboard.
	 */
	public static function redirect_members(): void {
		if ( ! self::is_member_only() || self::is_allowed_request() ) {
			return;
		}

		wp_safe_redirect( Router::url() );
		exit;
	}

	/**
	 * Requests that must reach wp-admin even for a member.
	 *
	 * `admin_init` fires on more than screens. It also fires on the endpoints
	 * that forms and uploads post to, and redirecting those does not show the
	 * member a friendly page - it silently breaks the thing they were doing and
	 * returns a redirect to code that expected a response.
	 *
	 * `profile.php` used to be on this list, on the grounds that WordPress sends
	 * a password reset there to finish. It does not: a reset completes on
	 * `wp-login.php` and never touches wp-admin. What the exception actually did
	 * was hold open the one door every member walked through, because
	 * `wp-login.php` sends an account without `edit_posts` to `profile.php` by
	 * default. The member area has its own sign-in and security screen.
	 */
	private static function is_allowed_request(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}

		$page = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';

		return in_array(
			$page,
			[
				'admin-post.php',
				'admin-ajax.php',
				// Media uploads, including the ones the editor makes on paste.
				'async-upload.php',
			],
			true
		);
	}
}

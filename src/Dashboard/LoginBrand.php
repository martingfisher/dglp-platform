<?php
/**
 * wp-login.php dressed as the site.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

use DGL\Logo;

defined( 'ABSPATH' ) || exit;

/**
 * "Forgotten your password?" leaves the member area for wp-login.php, which is
 * WordPress's own page: its logo, its colours, a language picker. The reset
 * email's link lands on another one. Rebuilding the reset flow inside the member
 * area is a project of its own and touches password handling, so this does the
 * small thing instead: the same screens, in the site's logo, colours and type.
 *
 * Nothing here changes what the screens do. It is styling, a logo and two
 * labels, on every wp-login.php screen (sign in, forgotten password, check your
 * email, choose a new password).
 */
final class LoginBrand {

	public static function init(): void {
		add_action( 'login_head', [ self::class, 'styles' ] ); // After WordPress prints its own login stylesheet, so ours wins at equal weight.
		add_filter( 'login_headerurl', static fn(): string => home_url( '/' ) );
		add_filter( 'login_headertext', static fn(): string => (string) get_bloginfo( 'name' ) );
	}

	/**
	 * Inline, because wp-login.php loads none of the theme's or the plugin's
	 * stylesheets and one small block is not worth a request.
	 */
	public static function styles(): void {
		echo '<style id="dgl-login">' . self::css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from constants and escaped URLs in css().
	}

	/**
	 * The stylesheet. Colours are the member area's own fallbacks (tokens.css),
	 * because the theme's palette variables do not exist on this page.
	 */
	public static function css(): string {
		$page = '#fdf8f0';
		$ink  = '#1e3232';
		$navy = '#2d3b6b';
		$font = \DGL\PLUGIN_URL . 'assets/fonts/Montserrat-SemiBold.ttf';
		$logo = Logo::dashboard_id();
		$src  = $logo > 0 ? (string) wp_get_attachment_image_url( $logo, 'medium' ) : '';

		$css  = '@font-face{font-family:"Montserrat";font-weight:600;font-style:normal;font-display:swap;src:url("' . esc_url( $font ) . '") format("truetype")}';
		$css .= 'body.login{--wp-admin-theme-color:' . $navy . ';--wp-admin-theme-color-darker-10:#1f2a4c;--wp-admin-theme-color-darker-20:#1a2340;background:' . $page . ';color:' . $ink . ';font-family:"Montserrat",system-ui,sans-serif}';
		$css .= '.login h1 a{' . ( '' !== $src ? 'background-image:url("' . esc_url( $src ) . '");' : '' ) . 'background-size:contain;background-position:center;background-repeat:no-repeat;width:100%;max-width:220px;height:110px;margin:0 auto 12px}';
		$css .= '.login form{background:#fff;border:1px solid rgba(30,50,50,.14);border-radius:12px;box-shadow:none;padding:24px}';
		$css .= '.login label{color:' . $ink . ';font-weight:600}';
		$css .= '.login form .input,.login input[type=text],.login input[type=password]{border:1px solid rgba(30,50,50,.68);border-radius:8px;box-shadow:none;color:' . $ink . '}';
		$css .= '.login form .input:focus,.login input[type=text]:focus,.login input[type=password]:focus{border-color:' . $navy . ';box-shadow:0 0 0 2px ' . $navy . '}';
		$css .= '.login .message,.login .notice,.login .success{border-left-color:' . $navy . ';background:#fff;border-radius:0 8px 8px 0}';
		$css .= '.login #login_error{border-left-color:#a1003d;background:#fff;border-radius:0 8px 8px 0}';
		$css .= '.login .button-primary,.wp-core-ui .button-primary{background:' . $navy . ';border-color:' . $navy . ';border-radius:8px;box-shadow:none;text-shadow:none;font-weight:600}';
		$css .= '.login .button-primary:hover,.login .button-primary:focus,.wp-core-ui .button-primary:hover,.wp-core-ui .button-primary:focus{background:#1f2a4c;border-color:#1f2a4c;color:#fff}';
		$css .= '.login #nav a,.login #backtoblog a,.login .privacy-policy-page-link a{color:' . $navy . '}';
		$css .= '.login #nav a:hover,.login #backtoblog a:hover{color:' . $ink . '}';
		$css .= '.login .language-switcher{display:none}';

		return $css;
	}
}

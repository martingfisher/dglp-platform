<?php
/**
 * Resolving the DGLP logo for each surface.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

defined( 'ABSPATH' ) || exit;

/**
 * The logo is never bundled with this plugin.
 *
 * It is resolved from the media library at render time, so replacing it is a
 * media library job rather than a deploy. That also means no reconstructed or
 * approximated brand mark ever ships: the file DGLP uploaded is the file that
 * renders.
 *
 * The two surfaces want different files. The dashboard wants the SVG, which
 * stays crisp at any size. Email wants a raster, because SVG support in email
 * clients is poor and inconsistent — Gmail strips it outright, so an SVG logo
 * in an email is an invisible logo. That rule is enforced here rather than left
 * to whoever configures the setting.
 *
 * On the staging site at the time of writing, the landscape lockup exists as
 * attachment 8268 (SVG) with a raster twin at 8270 (PNG). Those IDs are not
 * hardcoded, because production will differ.
 */
final class Logo {

	/** Attachment ID of the logo shown in the dashboard. */
	public const OPTION_DASHBOARD = 'dgl_logo_dashboard';

	/** Attachment ID of the logo used in email templates. */
	public const OPTION_EMAIL = 'dgl_logo_email';

	/**
	 * Image types that render reliably across email clients.
	 *
	 * Deliberately narrow. SVG is excluded because Gmail and several Outlook
	 * builds drop it; WebP is excluded because older Outlook does not render it.
	 *
	 * @return string[]
	 */
	public static function email_safe_mimes(): array {
		return [ 'image/png', 'image/jpeg', 'image/gif' ];
	}

	/**
	 * Whether an image type can be trusted in an email.
	 */
	public static function is_email_safe_mime( string $mime ): bool {
		return in_array( strtolower( trim( $mime ) ), self::email_safe_mimes(), true );
	}

	/**
	 * The attachment to show in the dashboard.
	 *
	 * Falls back to the theme's own logo, which is already configured, so the
	 * dashboard looks right before anybody visits the plugin's settings screen.
	 */
	public static function dashboard_id(): int {
		$id = (int) get_option( self::OPTION_DASHBOARD, 0 );

		if ( $id > 0 && self::is_attachment( $id ) ) {
			return $id;
		}

		return self::theme_logo_id();
	}

	/**
	 * The attachment to use in email, guaranteed to be a safe raster or zero.
	 *
	 * Resolution walks the configured email logo, then the dashboard logo, then
	 * the theme logo, taking the first that is a raster. An SVG anywhere in that
	 * chain is skipped rather than used, because it would render as nothing.
	 */
	public static function email_id(): int {
		$candidates = [
			(int) get_option( self::OPTION_EMAIL, 0 ),
			(int) get_option( self::OPTION_DASHBOARD, 0 ),
			self::theme_logo_id(),
		];

		foreach ( $candidates as $id ) {
			if ( $id > 0 && self::is_attachment( $id ) && self::is_email_safe_mime( (string) get_post_mime_type( $id ) ) ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * The dashboard logo as markup, or the site name when none is set.
	 *
	 * @param string $size Registered image size.
	 * @param array<string, string> $attr Extra attributes.
	 */
	public static function dashboard_html( string $size = 'full', array $attr = [] ): string {
		$id = self::dashboard_id();

		if ( $id <= 0 ) {
			return '<span class="dgl-logo dgl-logo--text">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
		}

		$attr = array_merge(
			[
				'class' => 'dgl-logo',
				'alt'   => get_bloginfo( 'name' ),
			],
			$attr
		);

		return (string) wp_get_attachment_image( $id, $size, false, $attr );
	}

	/**
	 * An absolute URL for the email logo, or null when there is no safe raster.
	 *
	 * Callers must handle null by falling back to text. An email that silently
	 * renders a broken image looks worse than one with no logo at all.
	 */
	public static function email_url(): ?string {
		$id = self::email_id();

		if ( $id <= 0 ) {
			return null;
		}

		$url = wp_get_attachment_image_url( $id, 'full' );

		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * The logo the active theme is already using.
	 */
	private static function theme_logo_id(): int {
		$id = (int) get_theme_mod( 'custom_logo', 0 );

		return $id > 0 && self::is_attachment( $id ) ? $id : 0;
	}

	private static function is_attachment( int $id ): bool {
		return 'attachment' === get_post_type( $id );
	}
}

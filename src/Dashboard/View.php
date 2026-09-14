<?php
/**
 * Template loading and output escaping for the member area.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a template with a data array, and nothing else.
 *
 * Templates receive `$data` and are expected to escape at the point of output.
 * There is no template engine here on purpose: a WordPress developer picking
 * this up should find plain PHP templates doing obvious things.
 *
 * A child theme can override any template by putting a file of the same name
 * under `dgl-platform/` in the theme, which is the convention WordPress plugin
 * users already expect.
 */
final class View {

	/**
	 * Render a template to a string.
	 *
	 * @param string              $template Path under templates/, without .php.
	 * @param array<string,mixed> $data     Passed to the template as $data.
	 */
	public static function render( string $template, array $data = [] ): string {
		$file = self::locate( $template );

		if ( null === $file ) {
			return '';
		}

		$level = ob_get_level();

		ob_start();

		try {
			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- path resolved by locate().
			include $file;

			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			/*
			 * Discard the partial output. Without this the half-rendered
			 * fragment is flushed at shutdown and the member sees an unstyled
			 * page fragment with no navigation and no indication anything went
			 * wrong, which is worse than an honest error.
			 */
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'DGLP Platform: template %s failed: %s', $template, $e->getMessage() ) );

			return self::failure( $template );
		}
	}

	/**
	 * Render straight to output.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function output( string $template, array $data = [] ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- templates escape at point of use.
		echo self::render( $template, $data );
	}

	/**
	 * What a member sees when a template dies.
	 *
	 * Deliberately says something went wrong rather than rendering nothing. An
	 * empty region looks like an empty account, and a member whose work appears
	 * to have vanished raises a support ticket.
	 */
	private static function failure( string $template ): string {
		$notice = '<p class="dgl-empty">'
			. esc_html__( 'Something went wrong showing this. Nothing has been lost. Please try again, and tell the team if it keeps happening.', 'dgl-platform' )
			. '</p>';

		if ( current_user_can( 'manage_options' ) ) {
			$notice .= '<p class="dgl-empty"><code>' . esc_html( $template ) . '</code></p>';
		}

		return $notice;
	}

	/**
	 * Find a template, preferring a child theme override.
	 *
	 * The name is sanitised rather than trusted: a template name that reached
	 * this from a URL segment must not be able to walk out of the directory.
	 */
	private static function locate( string $template ): ?string {
		$template = trim( $template, '/' );

		if ( '' === $template || str_contains( $template, '..' ) ) {
			return null;
		}

		if ( ! preg_match( '#^[a-z0-9/_-]+$#', $template ) ) {
			return null;
		}

		$relative = 'dgl-platform/' . $template . '.php';
		$theme    = locate_template( [ $relative ] );

		if ( '' !== $theme ) {
			return $theme;
		}

		$plugin = \DGL\PLUGIN_DIR . 'templates/' . $template . '.php';

		return is_readable( $plugin ) ? $plugin : null;
	}

	/**
	 * A status chip, label and all.
	 *
	 * Colour is never the only signal: the chip always carries its text, so it
	 * still reads for someone who cannot distinguish the backgrounds.
	 */
	public static function chip( string $status ): string {
		$modifier = match ( $status ) {
			\DGL\Statuses::DRAFT    => 'draft',
			\DGL\Statuses::PENDING  => 'pending',
			\DGL\Statuses::LIVE     => 'live',
			\DGL\Statuses::CHANGES  => 'changes',
			\DGL\Statuses::EXPIRED  => 'expired',
			\DGL\Statuses::ARCHIVED => 'archived',
			\DGL\Statuses::REJECTED => 'rejected',
			default                 => 'draft',
		};

		return sprintf(
			'<span class="dgl-chip dgl-chip--%s">%s</span>',
			esc_attr( $modifier ),
			esc_html( \DGL\Statuses::label( $status ) )
		);
	}

	/**
	 * A human date, in the site's timezone and UK format.
	 */
	public static function date( mixed $utc, bool $with_time = false ): string {
		// WordPress date helpers return string|false, so a false reaches here
		// whenever a post has no usable modified date.
		if ( ! is_string( $utc ) || '' === $utc ) {
			return '';
		}

		$timestamp = strtotime( $utc . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		return wp_date( $with_time ? 'j M Y, H:i' : 'j M Y', $timestamp ) ?: '';
	}
}

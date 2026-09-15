<?php
/**
 * Stylesheet loading for the member area.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the dashboard stylesheet, and only on dashboard requests.
 *
 * Version is the file's modification time rather than the plugin version, so a
 * CSS change busts the cache without needing a release. That matters more than
 * usual here: the site sits behind a CDN with a 24 hour TTL.
 */
final class Assets {

	public const HANDLE = 'dgl-dashboard';

	public static function enqueue( bool $with_editor = false ): void {
		self::style( 'assets/dashboard.css', self::HANDLE );
		self::script( 'assets/dashboard.js', self::HANDLE );

		if ( $with_editor ) {
			/*
			 * Front-end wp_editor() only works if the editor's own assets are
			 * queued before wp_head runs. This is called from the controller
			 * ahead of get_header(), which is the window where that is still
			 * possible.
			 */
			wp_enqueue_editor();
		}
	}

	private static function style( string $relative, string $handle ): void {
		$path = \DGL\PLUGIN_DIR . $relative;

		wp_enqueue_style(
			$handle,
			\DGL\PLUGIN_URL . $relative,
			[],
			is_readable( $path ) ? (string) filemtime( $path ) : \DGL\VERSION
		);
	}

	private static function script( string $relative, string $handle ): void {
		$path = \DGL\PLUGIN_DIR . $relative;

		if ( ! is_readable( $path ) ) {
			return;
		}

		wp_enqueue_script(
			$handle,
			\DGL\PLUGIN_URL . $relative,
			[],
			(string) filemtime( $path ),
			true
		);
	}
}

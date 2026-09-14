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

	public static function enqueue(): void {
		$relative = 'assets/dashboard.css';
		$path     = \DGL\PLUGIN_DIR . $relative;

		wp_enqueue_style(
			self::HANDLE,
			\DGL\PLUGIN_URL . $relative,
			[],
			is_readable( $path ) ? (string) filemtime( $path ) : \DGL\VERSION
		);
	}
}

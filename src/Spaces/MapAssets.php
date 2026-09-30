<?php
/**
 * The map on the spaces pages: Leaflet with OpenStreetMap tiles.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Spaces;

use DGL\Dashboard\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Leaflet 1.9.4 ships inside the plugin (assets/vendor/leaflet, BSD
 * licence alongside), so the two pages that draw a map depend on nobody
 * else's server for their script and need no integrity hash. The tiles
 * come from OpenStreetMap, with the attribution their policy asks for.
 * Nothing loads anywhere else.
 */
final class MapAssets {

	public const VERSION = '1.9.4';

	public static function available(): bool {
		return is_readable( \DGL\PLUGIN_DIR . 'assets/vendor/leaflet/leaflet.js' );
	}

	public static function enqueue(): void {
		if ( ! self::available() ) {
			return;
		}

		wp_enqueue_style( 'leaflet', \DGL\PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css', [ 'dgl-spaces' ], self::VERSION );
		wp_enqueue_script( 'leaflet', \DGL\PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js', [], self::VERSION, true );

		$path = \DGL\PLUGIN_DIR . 'assets/spaces-map.js';

		wp_enqueue_script( 'dgl-spaces-map', \DGL\PLUGIN_URL . 'assets/spaces-map.js', [ 'leaflet' ], is_readable( $path ) ? (string) filemtime( $path ) : \DGL\VERSION, true );

		wp_add_inline_script(
			'dgl-spaces-map',
			'window.dglMap = ' . wp_json_encode(
				[
					'tiles'       => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
					'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
					'images'      => \DGL\PLUGIN_URL . 'assets/vendor/leaflet/images/',
					'empty'       => __( 'None of these venues has a pin yet.', 'dgl-platform' ),
				]
			) . ';',
			'before'
		);
	}
}

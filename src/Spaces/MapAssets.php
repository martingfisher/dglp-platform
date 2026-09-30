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
 * Leaflet comes from cdnjs with a subresource integrity hash, on the two
 * pages that draw a map and nowhere else. The tiles are OpenStreetMap's,
 * with their attribution, which their policy asks for and which is fair.
 * Stage 4 of the build turns this on; until then the pages have no map.
 */
final class MapAssets {

	public const VERSION = '1.9.4';

	public const JS  = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js';
	public const CSS = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css';

	/** Subresource integrity hashes, from cdnjs, for the files above. */
	public const JS_SRI  = '';
	public const CSS_SRI = '';

	/**
	 * Whether the map is built yet. Off until stage 4 fills in the hashes.
	 */
	public static function available(): bool {
		return '' !== self::JS_SRI && '' !== self::CSS_SRI;
	}

	public static function enqueue(): void {
		if ( ! self::available() ) {
			return;
		}

		wp_enqueue_style( 'leaflet', self::CSS, [], self::VERSION );
		wp_enqueue_script( 'leaflet', self::JS, [], self::VERSION, true );
		Assets::script( 'assets/spaces-map.js', 'dgl-spaces-map' );
		wp_script_add_data( 'dgl-spaces-map', 'group', 1 );

		add_filter( 'style_loader_tag', [ self::class, 'style_tag' ], 10, 2 );
		add_filter( 'script_loader_tag', [ self::class, 'script_tag' ], 10, 2 );
	}

	public static function style_tag( string $tag, string $handle ): string {
		if ( 'leaflet' !== $handle ) {
			return $tag;
		}

		return str_replace( ' href=', ' integrity="' . esc_attr( self::CSS_SRI ) . '" crossorigin="anonymous" href=', $tag );
	}

	public static function script_tag( string $tag, string $handle ): string {
		if ( 'leaflet' !== $handle ) {
			return $tag;
		}

		// data-cfasync stops Cloudflare's Rocket Loader reordering it ahead of the page.
		return str_replace( ' src=', ' integrity="' . esc_attr( self::JS_SRI ) . '" crossorigin="anonymous" data-cfasync="false" src=', $tag );
	}
}

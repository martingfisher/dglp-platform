<?php
/**
 * Bootstrap.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

use DGL\Access\Access;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 *
 * Registration order matters: post types, then statuses, then taxonomies, so
 * that statuses and terms attach to types that already exist.
 */
final class Plugin {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'init', [ self::class, 'register_content' ], 5 );
		add_action( 'init', [ self::class, 'load_textdomain' ] );
		add_action( 'admin_init', [ Install::class, 'maybe_migrate' ] );

		Access::init();
	}

	/**
	 * Register post types, statuses and taxonomies.
	 */
	public static function register_content(): void {
		PostTypes::register();
		Statuses::register();
		Taxonomies::register();
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'dgl-platform', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}
}

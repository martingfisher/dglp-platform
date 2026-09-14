<?php
/**
 * Bootstrap.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

use DGL\Access\Access;
use DGL\Dashboard\Router;
use DGL\Index\Sync;
use DGL\Workflow\Transition;
use DGL\Schema\FieldRegistry;

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
		add_action( 'init', [ FieldRegistry::class, 'register_meta' ], 6 );
		add_action( 'init', [ self::class, 'load_textdomain' ] );
		add_action( 'admin_init', [ Install::class, 'maybe_migrate' ] );

		Access::init();
		Sync::init();
		Router::init();

		add_action( self::EXPIRY_HOOK, [ Transition::class, 'run_expiry_sweep' ] );
	}

	/**
	 * Register post types, statuses and taxonomies.
	 */
	public static function register_content(): void {
		PostTypes::register();
		Statuses::register();
		Taxonomies::register();
	}

	/**
	 * Cron hook name for the expiry sweep.
	 */
	public const EXPIRY_HOOK = 'dgl_run_expiry_sweep';

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'dgl-platform', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}
}

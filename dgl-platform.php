<?php
/**
 * Plugin Name:       DGLP Platform
 * Plugin URI:        https://partnership.doinggoodleeds.org.uk/
 * Description:       Member organisation accounts, content submission and a moderation workflow for the Doing Good Leeds Partnership site.
 * Version:           0.10.3
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Results You Can Measure
 * Author URI:        https://resultsyoucanmeasure.com
 * Text Domain:       dgl-platform
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

defined( 'ABSPATH' ) || exit;

const VERSION    = '0.10.3';
const DB_VERSION = 6;

define( 'DGL\\PLUGIN_FILE', __FILE__ );
define( 'DGL\\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DGL\\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Composer autoload if present, otherwise a PSR-4 fallback so the plugin still
 * boots on a host where `composer install` has not been run.
 */
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			if ( ! str_starts_with( $class, 'DGL\\' ) ) {
				return;
			}
			$relative = str_replace( '\\', '/', substr( $class, 4 ) );
			$path     = __DIR__ . '/src/' . $relative . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

register_activation_hook( __FILE__, [ Install::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Install::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ Plugin::class, 'boot' ], 5 );

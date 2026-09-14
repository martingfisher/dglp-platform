<?php
/**
 * Activation, deactivation and schema migration.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

use DGL\Audit\Table as AuditTable;
use DGL\Index\ItemsTable;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that has to happen once, rather than on every request.
 */
final class Install {

	public const DB_VERSION_OPTION = 'dgl_platform_db_version';

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		self::migrate();
		Roles::install();
		self::schedule_expiry();

		// Post types and taxonomies must exist before their rewrite rules mean anything.
		PostTypes::register();
		Taxonomies::register();
		flush_rewrite_rules();
	}

	/**
	 * Runs on deactivation. Deliberately keeps the tables and the roles: turning
	 * the plugin off for ten minutes should not strand every member account or
	 * throw away the audit trail.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Plugin::EXPIRY_HOOK );
		flush_rewrite_rules();
	}

	/**
	 * Run the expiry sweep hourly.
	 *
	 * Hourly rather than daily because an event that finished at 15:00 looking
	 * live until midnight is a listing that sends somebody to a closed door.
	 * The sweep is batched and indexed, so an hourly run that finds nothing
	 * costs almost nothing.
	 *
	 * This needs a real system cron behind it. On WordPress's own pseudo-cron a
	 * quiet site simply will not fire it.
	 */
	public static function schedule_expiry(): void {
		if ( ! wp_next_scheduled( Plugin::EXPIRY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Plugin::EXPIRY_HOOK );
		}
	}

	/**
	 * Bring the schema up to date. Idempotent, and cheap when already current.
	 */
	public static function migrate(): void {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $installed === DB_VERSION ) {
			return;
		}

		ItemsTable::create();
		AuditTable::create();

		update_option( self::DB_VERSION_OPTION, DB_VERSION, false );
	}

	/**
	 * Check on every load, so a deploy that bumps the schema does not need a
	 * manual reactivation.
	 */
	public static function maybe_migrate(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) !== DB_VERSION ) {
			self::migrate();
		}
	}
}

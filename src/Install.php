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
		flush_rewrite_rules();
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

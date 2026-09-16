<?php
/**
 * Audit trail storage.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * The audit table.
 *
 * This covers this plugin's own events only. It is not a replacement for a
 * general-purpose activity log: it will not record core updates, plugin changes
 * or anything happening outside the submission workflow. That reduction in
 * coverage is deliberate and should be stated to DGLP rather than glossed over.
 */
final class Table {

	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . 'dgl_audit';
	}

	/**
	 * Formatted for `dbDelta()`.
	 */
	public static function schema(): string {
		global $wpdb;

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	logged_at datetime NOT NULL default '1970-01-01 00:00:00',
	actor_id bigint(20) unsigned NOT NULL default 0,
	actor_ip_hash char(64) default NULL,
	object_type varchar(20) NOT NULL default '',
	object_id bigint(20) unsigned NOT NULL default 0,
	org_id bigint(20) unsigned NOT NULL default 0,
	action varchar(40) NOT NULL default '',
	note text,
	changes longtext,
	PRIMARY KEY  (id),
	KEY org_time (org_id,logged_at),
	KEY object (object_type,object_id,logged_at),
	KEY actor (actor_id,logged_at),
	KEY action_time (action,logged_at)
) {$collate};";
	}

	public static function create(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema() );
	}
}

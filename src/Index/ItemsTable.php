<?php
/**
 * The read-optimised projection of every submission.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Index;

use DGL\PostTypes;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * A narrow, heavily indexed mirror of the columns the hot screens actually read.
 *
 * Scoping submissions to an organisation through post meta would put a
 * `wp_postmeta` join on the member dashboard, the moderation queue, the expiry
 * sweep and every digest build. At the scale this system is being built for
 * that is the thing that would make it feel slow.
 *
 * So the hot reads come from here and hydrate full posts by ID afterwards. The
 * table is a cache, never the source of truth: `wp dgl reindex` rebuilds it
 * from `wp_posts` and post meta at any time.
 */
final class ItemsTable {

	/**
	 * Fully qualified table name.
	 */
	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . 'dgl_items';
	}

	/**
	 * The CREATE TABLE statement, formatted the way `dbDelta()` demands:
	 * two spaces after PRIMARY KEY, one field per line, lowercase types.
	 */
	public static function schema(): string {
		global $wpdb;

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
	post_id bigint(20) unsigned NOT NULL,
	post_type varchar(20) NOT NULL default '',
	org_id bigint(20) unsigned NOT NULL default 0,
	author_id bigint(20) unsigned NOT NULL default 0,
	status varchar(20) NOT NULL default '',
	trust_level tinyint(3) unsigned NOT NULL default 0,
	has_pending_revision tinyint(1) unsigned NOT NULL default 0,
	submitted_at datetime default NULL,
	approved_at datetime default NULL,
	updated_at datetime NOT NULL default '1970-01-01 00:00:00',
	expires_at datetime default NULL,
	next_at datetime default NULL,
	PRIMARY KEY  (post_id),
	KEY org_status (org_id,status,updated_at),
	KEY queue (status,submitted_at),
	KEY digest (post_type,status,approved_at),
	KEY expiry (expires_at,status),
	KEY author (author_id,updated_at),
	KEY next_up (post_type,status,next_at)
) {$collate};";
	}

	/**
	 * Create or migrate the table. Safe to call repeatedly.
	 */
	public static function create(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema() );
	}

	/**
	 * Whether the table exists. Used by the health check and by tests.
	 */
	public static function exists(): bool {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be parameterised.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Insert or update one item's row.
	 *
	 * @param array{
	 *     post_id:int, post_type:string, org_id:int, author_id:int, status:string,
	 *     trust_level?:int, has_pending_revision?:bool,
	 *     submitted_at?:?string, approved_at?:?string, updated_at?:string, expires_at?:?string
	 * } $row Row values.
	 */
	public static function upsert( array $row ): bool {
		global $wpdb;

		$data = [
			'post_id'              => (int) $row['post_id'],
			'post_type'            => (string) $row['post_type'],
			'org_id'               => (int) $row['org_id'],
			'author_id'            => (int) $row['author_id'],
			'status'               => (string) $row['status'],
			'trust_level'          => (int) ( $row['trust_level'] ?? 0 ),
			'has_pending_revision' => ! empty( $row['has_pending_revision'] ) ? 1 : 0,
			'submitted_at'         => $row['submitted_at'] ?? null,
			'approved_at'          => $row['approved_at'] ?? null,
			'updated_at'           => $row['updated_at'] ?? current_time( 'mysql', true ),
			'expires_at'           => $row['expires_at'] ?? null,
			'next_at'              => $row['next_at'] ?? null,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->replace( self::name(), $data );
	}

	/**
	 * Remove an item's row, for instance when the post is deleted.
	 */
	public static function delete( int $post_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->delete( self::name(), [ 'post_id' => $post_id ], [ '%d' ] );
	}

	/**
	 * SQL excluding pending edits.
	 *
	 * Edits live in this table so the moderation queue can be one indexed
	 * query. Everywhere else, an edit is not a thing in its own right: it must
	 * not appear in a member's list, must not be counted on their dashboard
	 * tiles and must never be picked up by the expiry sweep. A revision that
	 * reached the expiry sweep would be "expired" while the item it belonged to
	 * carried on, which is a state nothing else in the system understands.
	 *
	 * Written as a literal rather than a placeholder because it is a constant
	 * this class owns, and `prepare()` complains when a query has no arguments.
	 */
	private static function content_only(): string {
		return " AND post_type <> '" . PostTypes::REVISION . "'";
	}

	/**
	 * One organisation's items, newest activity first. Serves the member
	 * dashboard and the category list screens.
	 *
	 * Uses the `org_status` index.
	 *
	 * @param int           $org_id    Organisation post ID.
	 * @param string[]|null $types     Restrict to these post types, or null for all.
	 * @param string[]|null $statuses  Restrict to these statuses, or null for all.
	 * @return int[] Post IDs.
	 */
	public static function for_org( int $org_id, ?array $types = null, ?array $statuses = null, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$sql    = 'SELECT post_id FROM ' . self::name() . ' WHERE org_id = %d' . self::content_only();
		$params = [ $org_id ];

		if ( ! empty( $statuses ) ) {
			$sql      .= ' AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params    = array_merge( $params, $statuses );
		}

		if ( ! empty( $types ) ) {
			$sql   .= ' AND post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$params = array_merge( $params, $types );
		}

		$sql     .= ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
	}

	/**
	 * The moderation queue, oldest submission first, as wireframe 1k shows it.
	 *
	 * Uses the `queue` index.
	 *
	 * @return int[] Post IDs.
	 */
	public static function queue( ?array $types = null, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$sql    = 'SELECT post_id FROM ' . self::name() . ' WHERE status = %s';
		$params = [ Statuses::PENDING ];

		if ( ! empty( $types ) ) {
			$sql   .= ' AND post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$params = array_merge( $params, $types );
		}

		$sql     .= ' ORDER BY submitted_at ASC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
	}

	/**
	 * How many submissions are waiting, for the queue's pager and its badge.
	 *
	 * Counted rather than inferred from a page of results. A queue that silently
	 * stops at its first page is a queue where the oldest waiting submission is
	 * the one nobody can reach, and this is the number that stops that.
	 *
	 * @param string[]|null $types
	 */
	public static function queue_count( ?array $types = null ): int {
		global $wpdb;

		$sql    = 'SELECT COUNT(*) FROM ' . self::name() . ' WHERE status = %s';
		$params = [ Statuses::PENDING ];

		if ( ! empty( $types ) ) {
			$sql   .= ' AND post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$params = array_merge( $params, $types );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Live items whose end date or deadline has passed. Drives the expiry sweep.
	 *
	 * Uses the `expiry` index.
	 *
	 * @return int[] Post IDs.
	 */
	/**
	 * Live events that could have a date inside a window: next date before
	 * the window ends and expiry after it starts. A superset, expanded in
	 * PHP by the calendar; one-offs carry their start as next_at so they are
	 * found the same way. Wall-clock strings, like the columns.
	 *
	 * @return int[]
	 */
	public static function events_between( string $from_wall, string $to_wall, int $limit = 500 ): array {
		global $wpdb;

		$sql = 'SELECT post_id FROM ' . self::name()
			. ' WHERE post_type = %s AND status = %s AND next_at IS NOT NULL AND next_at <= %s'
			. ' AND (expires_at IS NULL OR expires_at >= %s)'
			. self::content_only()
			. ' ORDER BY next_at ASC LIMIT %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, PostTypes::EVENT, Statuses::LIVE, $to_wall, $from_wall, $limit ) ) );
	}

	/**
	 * Live events whose next stamp is missing or has passed: the roll-forward's
	 * work list. A one-off that has started is in it too; restamping one is a
	 * meta read and an equal write, cheap noise.
	 *
	 * @return int[]
	 */
	public static function events_to_roll( string $now_wall, int $limit = 200 ): array {
		global $wpdb;

		$sql = 'SELECT post_id FROM ' . self::name()
			. ' WHERE post_type = %s AND status = %s AND (next_at IS NULL OR next_at < %s)'
			. self::content_only()
			. ' ORDER BY next_at ASC LIMIT %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, PostTypes::EVENT, Statuses::LIVE, $now_wall, $limit ) ) );
	}

	/**
	 * Live events expiring inside a window, for the "still running?" reminder.
	 *
	 * @return int[]
	 */
	public static function expiring_between( string $from_wall, string $to_wall, int $limit = 100 ): array {
		global $wpdb;

		$sql = 'SELECT post_id FROM ' . self::name()
			. ' WHERE post_type = %s AND status = %s AND expires_at IS NOT NULL AND expires_at BETWEEN %s AND %s'
			. self::content_only()
			. ' ORDER BY expires_at ASC LIMIT %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, PostTypes::EVENT, Statuses::LIVE, $from_wall, $to_wall, $limit ) ) );
	}

	public static function due_for_expiry( string $now_utc, int $limit = 100 ): array {
		global $wpdb;

		$sql = 'SELECT post_id FROM ' . self::name()
			. ' WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s'
			. self::content_only()
			. ' ORDER BY expires_at ASC LIMIT %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, Statuses::LIVE, $now_utc, $limit ) ) );
	}

	/**
	 * Items published since a timestamp, for building a digest.
	 *
	 * Uses the `digest` index. Topic filtering is applied afterwards through the
	 * taxonomy, which WordPress already indexes.
	 *
	 * @param string[] $types  Post types the subscriber asked for.
	 * @param string   $since  UTC datetime of their last digest.
	 * @return int[] Post IDs.
	 */
	public static function published_since( array $types, string $since, int $limit = 200 ): array {
		global $wpdb;

		if ( empty( $types ) ) {
			return [];
		}

		$sql = 'SELECT post_id FROM ' . self::name()
			. ' WHERE status = %s'
			. ' AND post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')'
			. self::content_only()
			. ' AND approved_at IS NOT NULL AND approved_at > %s'
			. ' ORDER BY approved_at DESC LIMIT %d';

		$params = array_merge( [ Statuses::LIVE ], $types, [ $since, $limit ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) );
	}

	/**
	 * Count an organisation's items by status, for the dashboard tiles.
	 *
	 * @return array<string, int> Status => count.
	 */
	public static function counts_for_org( int $org_id, ?string $post_type = null ): array {
		global $wpdb;

		$sql    = 'SELECT status, COUNT(*) AS total FROM ' . self::name() . ' WHERE org_id = %d' . self::content_only();
		$params = [ $org_id ];

		if ( null !== $post_type ) {
			$sql     .= ' AND post_type = %s';
			$params[] = $post_type;
		}

		$sql .= ' GROUP BY status';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$counts = array_fill_keys( Statuses::all(), 0 );

		foreach ( $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}
}

<?php
/**
 * Writing and reading the audit trail.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * One call site for every audited event.
 *
 * The state machine writes here on every transition, so the trail cannot drift
 * out of step with what actually happened to a submission.
 */
final class Log {

	/**
	 * Field-level diffs are capped so a large body of text cannot bloat the
	 * table. Anything longer is truncated with a marker rather than dropped.
	 */
	private const MAX_CHANGES_BYTES = 16384;

	/**
	 * Record an event.
	 *
	 * @param string               $action      Short verb, for example `approve` or `grant_trust`.
	 * @param string               $object_type `item`, `org`, `user` or `revision`.
	 * @param int                  $object_id   The thing acted on.
	 * @param int                  $org_id      Owning organisation, for scoping member-visible history.
	 * @param string               $note        Free text shown to the member, where the action carries one.
	 * @param array<string, mixed> $changes     Field-level before and after.
	 * @param int|null             $actor_id    Defaults to the current user.
	 */
	public static function record(
		string $action,
		string $object_type,
		int $object_id,
		int $org_id = 0,
		string $note = '',
		array $changes = [],
		?int $actor_id = null
	): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->insert(
			Table::name(),
			[
				'logged_at'     => current_time( 'mysql', true ),
				'actor_id'      => $actor_id ?? get_current_user_id(),
				'actor_ip_hash' => self::ip_hash(),
				'object_type'   => $object_type,
				'object_id'     => $object_id,
				'org_id'        => $org_id,
				'action'        => $action,
				'note'          => $note,
				'changes'       => self::encode_changes( $changes ),
			],
			[ '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
		);
	}

	/**
	 * One organisation's history, newest first. Uses the `org_time` index.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_org( int $org_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$sql = 'SELECT * FROM ' . Table::name()
			. ' WHERE org_id = %d ORDER BY logged_at DESC, id DESC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $org_id, $limit, $offset ), ARRAY_A );
	}

	/**
	 * One item's history, oldest first, as the status timeline in wireframe 1g.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_object( string $object_type, int $object_id ): array {
		global $wpdb;

		$sql = 'SELECT * FROM ' . Table::name()
			. ' WHERE object_type = %s AND object_id = %d ORDER BY logged_at ASC, id ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $object_type, $object_id ), ARRAY_A );
	}

	/**
	 * Delete entries older than a retention window, in batches.
	 *
	 * @return int Rows removed.
	 */
	public static function purge_older_than( string $before_utc, int $limit = 500 ): int {
		global $wpdb;

		$sql = 'DELETE FROM ' . Table::name() . ' WHERE logged_at < %s LIMIT %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( $wpdb->prepare( $sql, $before_utc, $limit ) );
	}

	/**
	 * A pseudonymised record of where the action came from.
	 *
	 * Salted with the site's auth salt so the table alone cannot be walked back
	 * to an address, which keeps it proportionate under UK GDPR.
	 */
	private static function ip_hash(): ?string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		if ( '' === $ip ) {
			return null;
		}

		return hash( 'sha256', $ip . wp_salt( 'auth' ) );
	}

	/**
	 * JSON encode the diff, capped.
	 */
	private static function encode_changes( array $changes ): ?string {
		if ( empty( $changes ) ) {
			return null;
		}

		$json = wp_json_encode( $changes );

		if ( ! is_string( $json ) ) {
			return null;
		}

		if ( strlen( $json ) > self::MAX_CHANGES_BYTES ) {
			$json = wp_json_encode(
				[
					'_truncated' => true,
					'_bytes'     => strlen( $json ),
					'_fields'    => array_keys( $changes ),
				]
			);
		}

		return is_string( $json ) ? $json : null;
	}
}

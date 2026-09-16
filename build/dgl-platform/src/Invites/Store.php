<?php
/**
 * Where invitations live.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Invites;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * The invitations table, and the only code that reads or writes it.
 *
 * A table rather than post meta on the organisation. Invitations are looked up
 * by token on a page anybody can reach, and a meta lookup by value across every
 * organisation is a full scan of `wp_postmeta` on an unauthenticated route.
 * A unique index on the token hash makes that one row read.
 */
final class Store {

	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . 'dgl_invites';
	}

	/**
	 * Formatted the way `dbDelta()` demands: two spaces after PRIMARY KEY, one
	 * field per line, lowercase types.
	 *
	 * `token_hash` is unique, which is the constraint that makes a replayed or
	 * guessed token a failed lookup rather than a race. `email_org` is not
	 * unique: an address can be invited, have that withdrawn, and be invited
	 * again, and the history of that is worth keeping.
	 */
	public static function schema(): string {
		global $wpdb;

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	email varchar(190) NOT NULL default '',
	org_id bigint(20) unsigned NOT NULL default 0,
	org_role varchar(20) NOT NULL default '',
	invited_by bigint(20) unsigned NOT NULL default 0,
	token_hash char(64) NOT NULL default '',
	created_at datetime NOT NULL default '1970-01-01 00:00:00',
	expires_at datetime NOT NULL default '1970-01-01 00:00:00',
	accepted_at datetime default NULL,
	revoked_at datetime default NULL,
	accepted_by bigint(20) unsigned NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY token (token_hash),
	KEY email_org (email,org_id),
	KEY org_open (org_id,accepted_at,revoked_at,expires_at)
) {$collate};";
	}

	public static function create(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema() );
	}

	public static function exists(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be parameterised.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::name() ) );
	}

	/* ---------------------------------------------------------------------
	 * Tokens
	 * ------------------------------------------------------------------ */

	/**
	 * A fresh token, returned in the clear exactly once.
	 *
	 * The caller puts this in the email and then has no way to get it back,
	 * which is the point: a "resend" has to mint a new one, so a token cannot
	 * be recovered from the system by anybody, including DGLP.
	 */
	public static function new_token(): string {
		return bin2hex( random_bytes( 24 ) );
	}

	/**
	 * The stored form of a token.
	 *
	 * SHA-256 rather than a password hash. This is a 192-bit random string, not
	 * a human-chosen secret, so there is nothing to brute force and a slow hash
	 * would only make the lookup slow. It has to be deterministic to be indexed.
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	public static function find_by_token( string $token ): ?Invite {
		global $wpdb;

		$token = trim( $token );

		if ( '' === $token ) {
			return null;
		}

		$table = self::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1", self::hash( $token ) ),
			ARRAY_A
		);

		return is_array( $row ) ? Invite::from_row( $row ) : null;
	}

	public static function find( int $id ): ?Invite {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? Invite::from_row( $row ) : null;
	}

	/**
	 * Every invitation for an organisation, newest first.
	 *
	 * @return Invite[]
	 */
	public static function for_org( int $org_id, int $limit = 100 ): array {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE org_id = %d ORDER BY created_at DESC LIMIT %d", $org_id, max( 1, $limit ) ),
			ARRAY_A
		);

		return array_map( [ Invite::class, 'from_row' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Invitations an organisation is still waiting on.
	 *
	 * Expiry is in the WHERE clause rather than filtered afterwards, so the
	 * count used for the ceiling and the list shown on screen cannot disagree.
	 *
	 * @return Invite[]
	 */
	public static function open_for_org( int $org_id ): array {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE org_id = %d
				AND accepted_at IS NULL
				AND revoked_at IS NULL
				AND expires_at > %s
				ORDER BY created_at DESC",
				$org_id,
				self::now()
			),
			ARRAY_A
		);

		return array_map( [ Invite::class, 'from_row' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Whether this address already has an invitation waiting here.
	 */
	public static function has_open_for( string $email, int $org_id ): bool {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE email = %s AND org_id = %d
				AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > %s
				LIMIT 1",
				Rules::normalise_email( $email ),
				$org_id,
				self::now()
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Store a new invitation.
	 *
	 * @return int The row ID, or 0 if it could not be written.
	 */
	public static function insert( Invite $invite ): int {
		global $wpdb;

		$ok = $wpdb->insert(
			self::name(),
			[
				'email'       => $invite->email,
				'org_id'      => $invite->org_id,
				'org_role'    => $invite->org_role,
				'invited_by'  => $invite->invited_by,
				'token_hash'  => $invite->token_hash,
				'created_at'  => $invite->created_at,
				'expires_at'  => $invite->expires_at,
				'accepted_at' => $invite->accepted_at,
				'revoked_at'  => $invite->revoked_at,
				'accepted_by' => $invite->accepted_by,
			],
			[ '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ]
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Mark an invitation as taken up.
	 *
	 * The `accepted_at IS NULL` in the WHERE clause is the whole point: two
	 * requests arriving with the same token, which is exactly what a double
	 * click on a link in an email produces, and only one of them updates a row.
	 * The other gets zero and knows to stop rather than creating a second
	 * account for the same person.
	 *
	 * @return bool Whether this caller is the one that claimed it.
	 */
	public static function claim( int $id, int $user_id, string $at ): bool {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET accepted_at = %s, accepted_by = %d
				WHERE id = %d AND accepted_at IS NULL AND revoked_at IS NULL",
				$at,
				$user_id,
				$id
			)
		);

		return is_int( $rows ) && $rows > 0;
	}

	/**
	 * Withdraw an invitation that has not been taken up.
	 *
	 * @return bool Whether anything was withdrawn.
	 */
	public static function revoke( int $id, string $at ): bool {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET revoked_at = %s WHERE id = %d AND accepted_at IS NULL AND revoked_at IS NULL",
				$at,
				$id
			)
		);

		return is_int( $rows ) && $rows > 0;
	}

	/**
	 * Throw away invitations nobody took up, long after they died.
	 *
	 * Accepted ones are kept: they are the record of how somebody got access,
	 * which is the question asked when an account turns out not to belong.
	 *
	 * @return int Rows removed.
	 */
	public static function purge_stale( int $older_than_days = 90 ): int {
		global $wpdb;

		$table  = self::name();
		$cutoff = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
			->modify( '-' . max( 1, $older_than_days ) . ' days' )
			->format( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE accepted_at IS NULL AND expires_at < %s", $cutoff )
		);

		return is_int( $rows ) ? $rows : 0;
	}

	public static function now(): string {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
	}
}

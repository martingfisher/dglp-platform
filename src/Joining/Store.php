<?php
/**
 * Sign-ups: an address that has asked to join, and how far it has got.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Joining;

defined( 'ABSPATH' ) || exit;

/**
 * The table behind joining. Same shape as the invitations store: a hashed
 * single-use token, and no WordPress user until the address is proven.
 */
final class Store {

	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . 'dgl_signups';
	}

	public static function schema(): string {
		global $wpdb;

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	email varchar(190) NOT NULL default '',
	domain varchar(190) NOT NULL default '',
	token_hash char(64) NOT NULL default '',
	state varchar(20) NOT NULL default 'unverified',
	org_id bigint(20) unsigned NOT NULL default 0,
	user_id bigint(20) unsigned NOT NULL default 0,
	new_org_name varchar(200) NOT NULL default '',
	new_org_details text NOT NULL,
	reason text NOT NULL,
	created_at datetime NOT NULL default '1970-01-01 00:00:00',
	expires_at datetime NOT NULL default '1970-01-01 00:00:00',
	verified_at datetime default NULL,
	completed_at datetime default NULL,
	decided_at datetime default NULL,
	decided_by bigint(20) unsigned NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY token (token_hash),
	KEY email (email),
	KEY state_org (state,org_id),
	KEY user (user_id)
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

	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function new_token(): string {
		return bin2hex( random_bytes( 24 ) );
	}

	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	public static function find( int $id ): ?Signup {
		global $wpdb;

		$table = self::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? Signup::from_row( $row ) : null;
	}

	public static function find_by_token( string $token ): ?Signup {
		global $wpdb;

		$token = trim( $token );

		if ( '' === $token ) {
			return null;
		}

		$table = self::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", self::hash( $token ) ), ARRAY_A );

		return is_array( $row ) ? Signup::from_row( $row ) : null;
	}

	/**
	 * Sign-ups waiting on the team, oldest first.
	 *
	 * @return Signup[]
	 */
	public static function awaiting(): array {
		global $wpdb;

		$table = self::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE state = %s ORDER BY completed_at ASC, id ASC LIMIT 200", Signup::AWAITING ), ARRAY_A );

		return array_map( [ Signup::class, 'from_row' ], is_array( $rows ) ? $rows : [] );
	}

	public static function awaiting_count(): int {
		global $wpdb;

		$table = self::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state = %s", Signup::AWAITING ) );
	}

	/**
	 * Start a sign-up for an address. Any earlier unfinished one for the same
	 * address is superseded, so the only working link is the newest.
	 *
	 * @return array{id:int, token:string}
	 */
	public static function start( string $email, string $domain, string $expires_at ): array {
		global $wpdb;

		$table = self::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = %s WHERE email = %s AND state IN (%s, %s)", Signup::SUPERSEDED, $email, Signup::UNVERIFIED, Signup::VERIFIED ) );

		$token = self::new_token();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			[
				'email'          => $email,
				'domain'         => $domain,
				'token_hash'     => self::hash( $token ),
				'state'          => Signup::UNVERIFIED,
				'new_org_details' => '',
				'reason'         => '',
				'created_at'     => self::now(),
				'expires_at'     => $expires_at,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return [ 'id' => (int) $wpdb->insert_id, 'token' => $token ];
	}

	/**
	 * Every signup for one address, newest first. For the team's diagnosis.
	 *
	 * @return Signup[]
	 */
	public static function for_email( string $email ): array {
		global $wpdb;

		$table = self::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id DESC LIMIT 20", strtolower( trim( $email ) ) ), ARRAY_A );

		return array_map( static fn( array $row ): Signup => Signup::from_row( $row ), $rows );
	}

	/**
	 * @param array<string, mixed> $fields Columns to set.
	 */
	public static function update( int $id, array $fields ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( self::name(), $fields, [ 'id' => $id ] );
	}

	/**
	 * Mark the address proven, once. A second click on the same link does
	 * nothing, which is what stops a forwarded email being a second account.
	 */
	public static function verify( int $id ): bool {
		global $wpdb;

		$table = self::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = %s, verified_at = %s WHERE id = %d AND state = %s", Signup::VERIFIED, self::now(), $id, Signup::UNVERIFIED ) );

		return 1 === (int) $rows;
	}
}

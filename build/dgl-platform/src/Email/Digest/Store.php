<?php
/**
 * Where digest preferences live.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email\Digest;

use DateTimeImmutable;
use DateTimeZone;
use DGL\Org\Org;

defined( 'ABSPATH' ) || exit;

/**
 * The digest subscription table, and the only code that reads or writes it.
 *
 * It holds preferences and nothing else. The address and the organisation are
 * read from the account at send time rather than copied in here, because both
 * change and a copy that drifts means posting somebody's digest to an address
 * they have already left, or filtering their own organisation's items out by an
 * organisation they are no longer in.
 *
 * A row existing is not consent. `consent_at` is, and {@see Subscription}
 * refuses to send without it.
 */
final class Store {

	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . 'dgl_digest_subs';
	}

	/**
	 * Formatted the way `dbDelta()` demands.
	 *
	 * Keyed on the user: one person, one set of preferences. `due` is the
	 * index the runner sweeps, and it is deliberately (frequency, last_sent_at)
	 * so a daily run does not read a monthly subscriber's row at all.
	 */
	public static function schema(): string {
		global $wpdb;

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
	user_id bigint(20) unsigned NOT NULL,
	types text NOT NULL,
	topic_ids text NOT NULL,
	frequency varchar(20) NOT NULL default 'weekly',
	include_own_org tinyint(1) unsigned NOT NULL default 0,
	unsubscribe_token char(48) NOT NULL default '',
	consent_at datetime default NULL,
	consent_source varchar(40) NOT NULL default '',
	last_sent_at datetime default NULL,
	updated_at datetime NOT NULL default '1970-01-01 00:00:00',
	PRIMARY KEY  (user_id),
	UNIQUE KEY unsub (unsubscribe_token),
	KEY due (frequency,last_sent_at)
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
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * One person's preferences, or null if they have never set any.
	 *
	 * Null is not the same as unsubscribed. Somebody who has never been asked
	 * has no preferences, and the difference matters: the first is a question
	 * to put to them, the second is an answer to respect.
	 */
	public static function for_user( int $user_id ): ?Subscription {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	public static function for_token( string $token ): ?Subscription {
		global $wpdb;

		$token = trim( $token );

		if ( '' === $token ) {
			return null;
		}

		$table = self::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE unsubscribe_token = %s", $token ), ARRAY_A );

		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Subscribers whose digest is owed, for one cadence.
	 *
	 * Due-ness is decided by {@see Frequency}, which is pure and tested, rather
	 * than in SQL. The query narrows to one cadence so the runner is not
	 * hydrating every subscriber on the site to discard most of them.
	 *
	 * @return Subscription[]
	 */
	public static function due( string $frequency, DateTimeImmutable $now, int $limit = 500 ): array {
		global $wpdb;

		$table = self::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE frequency = %s AND consent_at IS NOT NULL
				ORDER BY last_sent_at IS NULL DESC, last_sent_at ASC
				LIMIT %d",
				$frequency,
				max( 1, $limit )
			),
			ARRAY_A
		);

		$due = [];

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$subscription = self::hydrate( $row );

			if ( null === $subscription ) {
				continue;
			}

			if ( Frequency::is_due( $subscription->frequency, $subscription->last_sent_at, $now ) ) {
				$due[] = $subscription;
			}
		}

		return $due;
	}

	/**
	 * Turn a row into a subscription, filling in what lives on the account.
	 *
	 * Returns null when the account has gone. A row whose user no longer exists
	 * is an address nobody can be responsible for.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function hydrate( array $row ): ?Subscription {
		$user_id = (int) ( $row['user_id'] ?? 0 );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		$consent = (string) ( $row['consent_at'] ?? '' );

		return new Subscription(
			user_id: $user_id,
			email: (string) $user->user_email,
			types: self::decode( (string) ( $row['types'] ?? '' ) ),
			topic_ids: array_map( 'intval', self::decode( (string) ( $row['topic_ids'] ?? '' ) ) ),
			frequency: (string) ( $row['frequency'] ?? Frequency::WEEKLY ),
			last_sent_at: self::nullable( $row['last_sent_at'] ?? null ),
			unsubscribe_token: (string) ( $row['unsubscribe_token'] ?? '' ),
			consent_at: '' !== $consent && ! str_starts_with( $consent, '0000-00-00' ) ? $consent : null,
			org_id: (int) ( Org::for_user( $user_id ) ?? 0 ),
			include_own_org: (bool) ( $row['include_own_org'] ?? false ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Save somebody's preferences.
	 *
	 * Consent is stamped the first time they ask for something and cleared the
	 * moment they ask for nothing. Under PECR the record has to say when they
	 * agreed and how, so both are written, and neither is inferred later from
	 * the row existing.
	 *
	 * @param string[] $types
	 * @param int[]    $topic_ids
	 */
	public static function save(
		int $user_id,
		array $types,
		array $topic_ids,
		string $frequency,
		bool $include_own_org,
		string $consent_source = 'dashboard'
	): bool {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return false;
		}

		$existing = self::for_user( $user_id );
		$wants    = [] !== $types;

		if ( $wants ) {
			// Keep the original timestamp. Re-recording consent every time
			// somebody changes a checkbox would erase the date they actually
			// agreed, which is the only part of the record that matters.
			$consent = $existing?->consent_at ?? self::now();
			$source  = $existing?->has_consent() ? '' : $consent_source;
		} else {
			$consent = null;
			$source  = '';
		}

		$data = [
			'types'             => self::encode( array_values( $types ) ),
			'topic_ids'         => self::encode( array_map( 'strval', $topic_ids ) ),
			'frequency'         => Frequency::is_valid( $frequency ) ? $frequency : Frequency::WEEKLY,
			'include_own_org'   => $include_own_org ? 1 : 0,
			'unsubscribe_token' => '' !== (string) ( $existing?->unsubscribe_token ?? '' ) ? $existing->unsubscribe_token : self::new_token(),
			'consent_at'        => $consent,
			'updated_at'        => self::now(),
		];

		if ( '' !== $source ) {
			$data['consent_source'] = $source;
		}

		if ( null === $existing ) {
			$data['user_id'] = $user_id;

			return false !== $wpdb->insert( self::name(), $data );
		}

		return false !== $wpdb->update( self::name(), $data, [ 'user_id' => $user_id ] );
	}

	/**
	 * Stop sending, keeping the record of what they had asked for.
	 *
	 * Consent is cleared rather than the row deleted. Deleting it would lose
	 * the fact that this person opted out, and the next import or bulk change
	 * would happily start writing to them again.
	 */
	public static function unsubscribe( int $user_id ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			self::name(),
			[
				'types'      => self::encode( [] ),
				'consent_at' => null,
				'updated_at' => self::now(),
			],
			[ 'user_id' => $user_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Record that a digest went out.
	 *
	 * Only called when something was actually sent. A run that matched nothing
	 * deliberately leaves the stamp alone, so a monthly subscriber with a quiet
	 * quarter gets one digest covering the quarter rather than three empty ones
	 * or a silently skipped window.
	 */
	public static function mark_sent( int $user_id, string $at ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			self::name(),
			[ 'last_sent_at' => $at ],
			[ 'user_id' => $user_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/* ---------------------------------------------------------------------
	 * Odds and ends
	 * ------------------------------------------------------------------ */

	/**
	 * The unsubscribe token.
	 *
	 * Stored rather than derived, so it can be looked up by a single indexed
	 * read from a link somebody clicks without being signed in. It is a
	 * low-value secret - the worst it does is stop somebody's newsletter - but
	 * it is per-subscriber and unguessable, so one person's link cannot
	 * unsubscribe another.
	 */
	public static function new_token(): string {
		return bin2hex( random_bytes( 24 ) );
	}

	/**
	 * @param string[] $values
	 */
	private static function encode( array $values ): string {
		return implode( ',', array_filter( array_map( 'strval', $values ), static fn( string $v ): bool => '' !== $v ) );
	}

	/**
	 * @return string[]
	 */
	private static function decode( string $stored ): array {
		if ( '' === trim( $stored ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $stored ) ), static fn( string $v ): bool => '' !== $v ) );
	}

	private static function nullable( mixed $value ): ?string {
		$value = (string) ( $value ?? '' );

		return ( '' === $value || str_starts_with( $value, '0000-00-00' ) ) ? null : $value;
	}

	public static function now(): string {
		return ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
	}
}

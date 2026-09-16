<?php
/**
 * `wp dgl digest` - running and inspecting digests from the command line.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email\Digest;

use DateTimeImmutable;
use DateTimeZone;
use DGL\Email\Routing;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Digest commands.
 *
 * Exists because a digest that only ever runs on an hourly cron is a digest
 * nobody can test. `--dry-run` builds every message and sends none, which is
 * the only safe way to find out what a real run would do on a site with live
 * member addresses in its user table.
 */
final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl digest', self::class );
	}

	/**
	 * What this site would do with digests.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl digest status
	 *
	 * @when after_wp_load
	 */
	public function status(): void {
		if ( ! Store::exists() ) {
			WP_CLI::error( 'The digest table does not exist. Reactivate the plugin, or load any page to let it migrate.' );
		}

		global $wpdb;

		$table = Store::name();
		$now   = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$rows  = [];

		foreach ( Frequency::all() as $frequency ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE frequency = %s AND consent_at IS NOT NULL", $frequency )
			);

			$rows[] = [
				'cadence'    => Frequency::label( $frequency ),
				'subscribed' => $total,
				'due now'    => count( Store::due( $frequency, $now ) ),
			];
		}

		WP_CLI::log( 'Sending: ' . ( Routing::is_enabled() ? 'on' : 'OFF, nothing will be sent' ) );

		$redirect = Routing::configured_redirect();

		if ( '' !== $redirect ) {
			WP_CLI::log( 'Redirect: ALL MAIL GOES TO ' . $redirect );
		}

		$next = wp_next_scheduled( \DGL\Plugin::DIGEST_HOOK );
		WP_CLI::log( 'Next scheduled run: ' . ( $next ? gmdate( 'Y-m-d H:i:s', (int) $next ) . ' UTC' : 'NOT SCHEDULED' ) );
		WP_CLI::log( '' );

		WP_CLI\Utils\format_items( 'table', $rows, [ 'cadence', 'subscribed', 'due now' ] );
	}

	/**
	 * Send the digests that are owed.
	 *
	 * ## OPTIONS
	 *
	 * [--frequency=<frequency>]
	 * : Just one cadence. daily, weekly or monthly. Default: all three.
	 *
	 * [--dry-run]
	 * : Build everything, send nothing. Use this first on any site with real
	 * member addresses in it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl digest run --dry-run
	 *     wp dgl digest run --frequency=weekly
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function run( array $args, array $assoc ): void {
		$dry = isset( $assoc['dry-run'] );

		$cadences = isset( $assoc['frequency'] )
			? [ (string) $assoc['frequency'] ]
			: Frequency::all();

		foreach ( $cadences as $frequency ) {
			if ( ! Frequency::is_valid( $frequency ) ) {
				WP_CLI::error( sprintf( '"%s" is not a cadence. Use daily, weekly or monthly.', $frequency ) );
			}
		}

		if ( ! $dry && ! Routing::is_enabled() ) {
			WP_CLI::error( 'Sending is off, so a real run would do nothing. Turn it on, or use --dry-run.' );
		}

		$totals = [];

		foreach ( $cadences as $frequency ) {
			$stats = Runner::run( $frequency, null, $dry );

			$totals[] = [
				'cadence'    => $frequency,
				'considered' => $stats['considered'],
				'sent'       => $stats['sent'],
				'nothing to say' => $stats['skipped_empty'],
				'failed'     => $stats['failed'],
				'items'      => $stats['items'],
			];
		}

		WP_CLI\Utils\format_items( 'table', $totals, [ 'cadence', 'considered', 'sent', 'nothing to say', 'failed', 'items' ] );

		if ( $dry ) {
			WP_CLI::success( 'Dry run. Nothing was sent and no last-sent date was moved.' );
			return;
		}

		$failed = array_sum( array_column( $totals, 'failed' ) );

		if ( $failed > 0 ) {
			WP_CLI::warning( sprintf( '%d digest(s) were not accepted for delivery. Check the mail log.', $failed ) );
			return;
		}

		WP_CLI::success( 'Handed to WordPress for delivery. That is not proof anything arrived.' );
	}

	/**
	 * Send one person their digest now, whatever the schedule says.
	 *
	 * A new subscriber is not owed a digest until the next send hour, which is
	 * correct and also means there is no way to answer "does this actually
	 * arrive" without waiting until morning. This skips the cadence check and
	 * nothing else: consent is still required and an empty digest is still not
	 * sent.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login or email.
	 *
	 * [--dry-run]
	 * : Build it and send nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl digest send jo@charity.org --dry-run
	 *     wp dgl digest send jo@charity.org
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function send( array $args, array $assoc ): void {
		$user = get_user_by( 'id', (int) $args[0] )
			?: get_user_by( 'login', (string) $args[0] )
			?: get_user_by( 'email', (string) $args[0] );

		if ( ! $user instanceof \WP_User ) {
			WP_CLI::error( sprintf( 'No account matches "%s".', (string) $args[0] ) );
		}

		$subscription = Store::for_user( (int) $user->ID );

		if ( null === $subscription ) {
			WP_CLI::error( 'That account has never set digest preferences.' );
		}

		if ( ! $subscription->has_consent() ) {
			WP_CLI::error( 'That account has no recorded consent, so nothing may be sent to it. Use `wp dgl digest subscribe` first.' );
		}

		$dry = isset( $assoc['dry-run'] );

		if ( ! $dry && ! Routing::is_enabled() ) {
			WP_CLI::error( 'Sending is off. Turn it on, or use --dry-run.' );
		}

		$result = Runner::send_one(
			$subscription,
			new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ),
			$dry
		);

		if ( 'skipped_empty' === $result['outcome'] ) {
			WP_CLI::success( 'Nothing matches, so nothing was sent and the last-sent date did not move.' );
			return;
		}

		if ( 'failed' === $result['outcome'] ) {
			WP_CLI::error( 'WordPress refused the message. The last-sent date was not moved, so this can be retried.' );
		}

		if ( $dry ) {
			WP_CLI::success( sprintf( '%d item(s) would have gone to %s. Nothing was sent.', $result['items'], $subscription->email ) );
			return;
		}

		WP_CLI::success( sprintf(
			'%d item(s) handed to WordPress for %s. That is not proof it arrived, so check the inbox.',
			$result['items'],
			$subscription->email
		) );
	}

	/**
	 * Put somebody on the digest, or take them off.
	 *
	 * For the support call: a member rings up and asks to be added, or asks to
	 * stop and cannot find the link. Doing it by hand otherwise means a direct
	 * database write, and a consent record written by hand is not a consent
	 * record.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login or email.
	 *
	 * [--types=<types>]
	 * : Comma-separated content types, or "all". Default: all.
	 *
	 * [--frequency=<frequency>]
	 * : daily, weekly or monthly. Default: weekly.
	 *
	 * [--own-org]
	 * : Include their own organisation's items. Off by default.
	 *
	 * [--off]
	 * : Take them off instead. Keeps the row, clears consent.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl digest subscribe jo@charity.org --frequency=weekly
	 *     wp dgl digest subscribe jo@charity.org --types=dgl_event,dgl_grant
	 *     wp dgl digest subscribe jo@charity.org --off
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function subscribe( array $args, array $assoc ): void {
		$user = get_user_by( 'id', (int) $args[0] )
			?: get_user_by( 'login', (string) $args[0] )
			?: get_user_by( 'email', (string) $args[0] );

		if ( ! $user instanceof \WP_User ) {
			WP_CLI::error( sprintf( 'No account matches "%s".', (string) $args[0] ) );
		}

		if ( isset( $assoc['off'] ) ) {
			Store::unsubscribe( (int) $user->ID );
			WP_CLI::success( sprintf( '%s will not get digests. The record of their opt-out is kept.', $user->user_email ) );
			return;
		}

		$requested = (string) ( $assoc['types'] ?? 'all' );

		$types = 'all' === $requested
			? array_values( \DGL\PostTypes::enabled_keys() )
			: array_values( array_filter(
				array_map( 'trim', explode( ',', $requested ) ),
				static fn( string $t ): bool => in_array( $t, \DGL\PostTypes::enabled_keys(), true )
			) );

		if ( [] === $types ) {
			WP_CLI::error( sprintf( 'None of "%s" is a content type. Known types: %s', $requested, implode( ', ', \DGL\PostTypes::enabled_keys() ) ) );
		}

		$frequency = (string) ( $assoc['frequency'] ?? Frequency::WEEKLY );

		if ( ! Frequency::is_valid( $frequency ) ) {
			WP_CLI::error( sprintf( '"%s" is not a cadence. Use daily, weekly or monthly.', $frequency ) );
		}

		$saved = Store::save(
			(int) $user->ID,
			$types,
			[],
			$frequency,
			isset( $assoc['own-org'] ),
			'wp-cli'
		);

		if ( ! $saved ) {
			WP_CLI::error( 'Could not save those preferences.' );
		}

		$sub = Store::for_user( (int) $user->ID );

		WP_CLI::success( sprintf(
			'%s is subscribed: %s, %s. Consent recorded %s via wp-cli.',
			$user->user_email,
			implode( ', ', $types ),
			Frequency::label( $frequency ),
			(string) ( $sub?->consent_at ?? 'now' )
		) );
	}

	/**
	 * Show what one subscriber's next digest would contain.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login or email.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl digest preview jo@charity.org
	 *
	 * @when after_wp_load
	 *
	 * @param string[] $args
	 */
	public function preview( array $args ): void {
		$user = get_user_by( 'id', (int) $args[0] )
			?: get_user_by( 'login', (string) $args[0] )
			?: get_user_by( 'email', (string) $args[0] );

		if ( ! $user instanceof \WP_User ) {
			WP_CLI::error( sprintf( 'No account matches "%s".', (string) $args[0] ) );
		}

		$subscription = Store::for_user( (int) $user->ID );

		if ( null === $subscription ) {
			WP_CLI::error( 'That account has never set digest preferences. That is not the same as having unsubscribed.' );
		}

		WP_CLI::log( 'Address:   ' . $subscription->email );
		WP_CLI::log( 'Wants:     ' . ( [] === $subscription->types ? '(nothing)' : implode( ', ', $subscription->types ) ) );
		WP_CLI::log( 'Cadence:   ' . Frequency::label( $subscription->frequency ) );
		WP_CLI::log( 'Consent:   ' . ( $subscription->has_consent() ? (string) $subscription->consent_at : 'NONE, so nothing will be sent' ) );
		WP_CLI::log( 'Last sent: ' . ( $subscription->last_sent_at ?? 'never' ) );
		WP_CLI::log( 'Sendable:  ' . ( $subscription->is_sendable() ? 'yes' : 'no' ) );
		WP_CLI::log( '' );

		$matched = Matcher::match( $subscription, Runner::candidates( $subscription ) );
		$rows    = Runner::rows( array_slice( $matched, 0, Runner::MAX_ITEMS ) );

		if ( [] === $rows ) {
			WP_CLI::success( 'Nothing matches. No digest would be sent, and the last-sent date would not move.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'title', 'meta' ] );
		WP_CLI::success( sprintf( '%d item(s) would be sent.', count( $rows ) ) );
	}
}

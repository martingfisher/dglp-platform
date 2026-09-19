<?php
/**
 * `wp dgl join status <email>`: what happened to one address that tried
 * to join. For "I never got the email".
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Joining;

use DGL\Audit\Table as AuditTable;
use DGL\Email\Routing;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl join', self::class );
	}

	/**
	 * Everything the site knows about one address's attempts to join.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : The address.
	 *
	 * @when after_wp_load
	 */
	public function status( array $args ): void {
		global $wpdb;

		$email = strtolower( trim( (string) ( $args[0] ?? '' ) ) );

		if ( ! is_email( $email ) ) {
			WP_CLI::error( 'Give an email address.' );
		}

		$user = get_user_by( 'email', $email );
		$rows = [];

		$rows[] = [ 'what' => 'Account', 'value' => $user instanceof \WP_User ? 'yes, user ' . $user->ID . ' (' . implode( ', ', (array) $user->roles ) . ')' : 'none: joining would send a link' ];

		$redirect = trim( (string) get_option( Routing::OPTION_REDIRECT, '' ) );
		$rows[]   = [ 'what' => 'Mail goes to', 'value' => '' !== $redirect ? 'REDIRECTED to ' . $redirect . ' (staging setting), not to the address' : 'the address itself' ];
		$rows[]   = [ 'what' => 'Sending', 'value' => (string) get_option( Routing::OPTION_ENABLED, '1' ) ? 'on' : 'OFF' ];

		$per_email = (int) get_transient( 'dgl_join_email_' . md5( $email ) );
		$rows[]    = [ 'what' => 'Starts this hour', 'value' => $per_email . ' of ' . Guard::PER_EMAIL . ( $per_email > Guard::PER_EMAIL ? ' (over the limit: told to wait)' : '' ) ];

		foreach ( Store::for_email( $email ) as $signup ) {
			$rows[] = [
				'what'  => 'Signup ' . $signup->id,
				'value' => $signup->state . ', link sent ' . $signup->created_at . ' UTC, expires ' . $signup->expires_at
					. ( null !== $signup->verified_at ? ', link used ' . $signup->verified_at : ', link not used' )
					. ( null !== $signup->completed_at ? ', completed ' . $signup->completed_at : '' ),
			];
		}

		$audit = AuditTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$log = (array) $wpdb->get_results( $wpdb->prepare( "SELECT logged_at, action FROM {$audit} WHERE object_type = 'signup' AND note = %s ORDER BY logged_at DESC LIMIT 20", $email ), ARRAY_A );
		foreach ( $log as $entry ) {
			$rows[] = [ 'what' => 'Audit ' . $entry['logged_at'] . ' UTC', 'value' => (string) $entry['action'] ];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$blocked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit} WHERE action = 'join_blocked' AND logged_at > '" . gmdate( 'Y-m-d H:i:s', time() - 86400 ) . "'" );
		$rows[]  = [ 'what' => 'Robot-looking submits dropped, last 24h (all addresses)', 'value' => (string) $blocked ];

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'what', 'value' ] );
	}
}

<?php
/**
 * `wp dgl series` - looking at a repeating event the way the cron does.
 *
 * Nothing here changes what the site does. It prints every check the
 * roll-forward, the reminder and the extend button make, so a series that
 * is not behaving can be read rather than guessed at on a host where
 * `wp eval` is blocked.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Events;

use DGL\Email\Routing;
use DGL\Index\ItemsTable;
use DGL\Meta;
use DGL\Org\Org;
use DGL\PostTypes;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl series', self::class );
	}

	/**
	 * Every fact about one event that the series code reads.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The event's post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl series status 8439
	 *
	 * @when after_wp_load
	 */
	public function status( array $args ): void {
		$post_id = (int) ( $args[0] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post || PostTypes::EVENT !== $post->post_type ) {
			WP_CLI::error( 'Not an event.' );
		}

		global $wpdb;

		$rule  = Series::rule_for( $post_id );
		$now   = Series::now();
		$index = $wpdb->get_row( $wpdb->prepare( 'SELECT status, expires_at, next_at FROM ' . ItemsTable::name() . ' WHERE post_id = %d', $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$to    = $now->modify( '+' . Reminder::DAYS_BEFORE . ' days' );
		$due   = ItemsTable::expiring_between( $now->format( 'Y-m-d H:i:s' ), $to->format( 'Y-m-d H:i:s' ), 500 );
		$org   = Org::for_item( $post_id );

		$rows = [
			[ 'check', 'Title', (string) $post->post_title ],
			[ 'check', 'Status', (string) $post->post_status ],
			[ 'check', 'Site now', $now->format( 'Y-m-d H:i:s' ) . ' (' . wp_timezone_string() . ')' ],
			[ 'check', 'Start / end', get_post_meta( $post_id, 'dgl_start_datetime', true ) . ' / ' . get_post_meta( $post_id, 'dgl_end_datetime', true ) ],
			[ 'check', 'Rule meta', wp_json_encode( get_post_meta( $post_id, 'dgl_repeat', true ) ) ],
			[ 'check', 'Is a series', null === $rule ? 'no' : 'yes: ' . Wording::with_times( $rule->to_meta(), $rule->start->format( 'Y-m-d H:i:s' ), '' ) . ', until ' . $rule->until->format( 'Y-m-d' ) ],
			[ 'check', 'Expires meta', (string) get_post_meta( $post_id, Meta::ITEM_EXPIRES_AT, true ) ],
			[ 'check', 'Next meta', (string) get_post_meta( $post_id, Meta::ITEM_NEXT_AT, true ) ],
			[ 'check', 'Index row', is_array( $index ) ? wp_json_encode( $index ) : 'MISSING' ],
			[ 'check', 'Next 5 dates', implode( ', ', array_map( static fn( Occurrence $o ): string => $o->wall(), Series::next_dates( $post_id ) ) ) ],
			[ 'check', 'Can extend now', Series::can_extend( $post_id ) ? 'yes' : 'no' ],
			[ 'check', 'Mail sending', Routing::is_enabled() ? 'on' : 'OFF' ],
			[ 'check', 'Reminder window', $now->format( 'Y-m-d H:i' ) . ' to ' . $to->format( 'Y-m-d H:i' ) . ': ' . ( in_array( $post_id, $due, true ) ? 'this event is in it' : 'not in it (' . count( $due ) . ' event(s) are)' ) ],
			[ 'check', 'Reminded for', (string) get_post_meta( $post_id, Reminder::META_REMINDED_FOR, true ) ],
			[ 'check', 'Extend token', '' !== (string) get_post_meta( $post_id, Reminder::META_TOKEN, true ) ? 'set' : 'none' ],
			[ 'check', 'Organisation', $org > 0 ? $org . ' ' . get_the_title( $org ) : 'none' ],
			[ 'check', 'Owner emails', implode( ', ', Org::owner_emails( $org ) ) ],
		];

		WP_CLI\Utils\format_items( 'table', array_map( static fn( array $r ): array => [ 'what' => $r[1], 'value' => $r[2] ], $rows ), [ 'what', 'value' ] );
	}

	/**
	 * Send the "still running?" reminder for one series, exactly as cron would.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The event's post ID.
	 *
	 * [--again]
	 * : Send even if a reminder already went for this end date.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl series remind 8439
	 *
	 * @when after_wp_load
	 */
	public function remind( array $args, array $assoc ): void {
		$post_id = (int) ( $args[0] ?? 0 );

		if ( isset( $assoc['again'] ) ) {
			delete_post_meta( $post_id, Reminder::META_REMINDED_FOR );
		}

		$sent = Reminder::send_for( $post_id );

		if ( $sent ) {
			WP_CLI::success( 'Reminder sent to: ' . implode( ', ', Org::owner_emails( Org::for_item( $post_id ) ) ) . ' (redirected if the mail redirect is on).' );
			return;
		}

		WP_CLI::warning( 'Nothing sent. Run `wp dgl series status ' . $post_id . '` to see which check stopped it.' );
	}

	/**
	 * Restamp every live event's next date and expiry, as the hourly cron does.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl series roll
	 *
	 * @when after_wp_load
	 */
	public function roll(): void {
		WP_CLI::success( sprintf( '%d event(s) restamped.', Series::roll_forward() ) );
	}
}

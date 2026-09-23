<?php
/**
 * The month in numbers, for the review team.
 *
 * What was decided, how quickly, what came in, what is on the site now.
 * Read from the audit trail and the listings index, so it counts what
 * actually happened rather than what a screen remembers. Nothing here
 * writes.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Reports;

use DateTimeImmutable;
use DateTimeZone;
use DGL\Audit\Table as AuditTable;
use DGL\Index\ItemsTable;
use DGL\Meta;
use DGL\PostTypes;
use DGL\Roles;
use DGL\Statuses;
use DGL\Workflow\StateMachine;

defined( 'ABSPATH' ) || exit;

final class Monthly {

	/** How many months back the picker offers. */
	public const MONTHS = 12;

	/**
	 * The months on offer, newest first.
	 *
	 * @return array<string, string> Y-m => label.
	 */
	public static function months( ?DateTimeImmutable $today = null ): array {
		$today = $today ?? new DateTimeImmutable( 'today', wp_timezone() );
		$first = $today->modify( 'first day of this month' )->setTime( 0, 0, 0 );
		$out   = [];

		for ( $i = 0; $i < self::MONTHS; $i++ ) {
			$month                          = $first->modify( '-' . $i . ' months' );
			$out[ $month->format( 'Y-m' ) ] = (string) wp_date( 'F Y', $month->getTimestamp() );
		}

		return $out;
	}

	/** A Y-m from a request, or this month when it is missing or not on offer. */
	public static function month_from( array $request ): string {
		$given  = isset( $request['month'] ) && is_scalar( $request['month'] ) ? (string) $request['month'] : '';
		$months = self::months();

		return isset( $months[ $given ] ) ? $given : (string) array_key_first( $months );
	}

	/**
	 * The month's bounds in the site zone, and the same in UTC for the audit trail.
	 *
	 * @return array{from: DateTimeImmutable, to: DateTimeImmutable, from_utc: string, to_utc: string, from_wall: string, to_wall: string}
	 */
	public static function window( string $ym ): array {
		$tz   = wp_timezone();
		$from = DateTimeImmutable::createFromFormat( '!Y-m-d', $ym . '-01', $tz ) ?: new DateTimeImmutable( 'first day of this month', $tz );
		$from = $from->setTime( 0, 0, 0 );
		$to   = $from->modify( 'last day of this month' )->setTime( 23, 59, 59 );
		$utc  = new DateTimeZone( 'UTC' );

		return [
			'from'      => $from,
			'to'        => $to,
			'from_utc'  => $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'to_utc'    => $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'from_wall' => $from->format( 'Y-m-d H:i:s' ),
			'to_wall'   => $to->format( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Everything the screen and the command show for one month.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_month( string $ym ): array {
		$w = self::window( $ym );

		return [
			'month'         => $ym,
			'label'         => self::months()[ $ym ] ?? $ym,
			'from'          => $w['from'],
			'to'            => $w['to'],
			'decisions'     => self::decisions( $w['from_utc'], $w['to_utc'] ),
			'approved_by'   => self::approved_by_type( $w['from_utc'], $w['to_utc'] ),
			'speed'         => self::speed( $w['from_wall'], $w['to_wall'] ),
			'organisations' => self::organisations( $w['from_utc'], $w['to_utc'] ),
			'members'       => self::members( $w['from_utc'], $w['to_utc'] ),
			'now'           => self::now(),
		];
	}

	/**
	 * How many of each decision in the month, on items and edits.
	 *
	 * @return array<string, int> Action => count, every action present, zero or not.
	 */
	public static function decisions( string $from_utc, string $to_utc ): array {
		$actions = [
			StateMachine::SUBMIT,
			StateMachine::APPROVE,
			StateMachine::REQUEST_CHANGES,
			StateMachine::REJECT,
			StateMachine::TAKE_DOWN,
			StateMachine::REOPEN,
			StateMachine::EXPIRE,
			StateMachine::ARCHIVE,
			StateMachine::RESTORE,
			'pinned',
			'cancelled',
			'date_cancelled',
			'series_extended',
			'listing_extended',
			'schedule_changed',
		];

		$found = self::count_actions( $from_utc, $to_utc, [ 'item', 'revision' ] );
		$out   = [];

		foreach ( $actions as $action ) {
			$out[ $action ] = (int) ( $found[ $action ] ?? 0 );
		}

		return $out;
	}

	/**
	 * Approvals split by what was approved: each type, plus edits.
	 *
	 * @return array<string, int> Label => count.
	 */
	public static function approved_by_type( string $from_utc, string $to_utc ): array {
		global $wpdb;

		$audit = AuditTable::name();
		$posts = $wpdb->posts;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.object_type, p.post_type, COUNT(*) AS n FROM {$audit} a LEFT JOIN {$posts} p ON p.ID = a.object_id"
				. ' WHERE a.action = %s AND a.object_type IN (%s, %s) AND a.logged_at BETWEEN %s AND %s GROUP BY a.object_type, p.post_type',
				StateMachine::APPROVE,
				'item',
				'revision',
				$from_utc,
				$to_utc
			),
			ARRAY_A
		);

		$out = [];
		foreach ( PostTypes::enabled() as $type => $def ) {
			$out[ $type ] = 0;
		}
		$out['edits'] = 0;

		foreach ( (array) $rows as $row ) {
			if ( 'revision' === (string) $row['object_type'] || PostTypes::REVISION === (string) $row['post_type'] ) {
				$out['edits'] += (int) $row['n'];
			} elseif ( isset( $out[ (string) $row['post_type'] ] ) ) {
				$out[ (string) $row['post_type'] ] += (int) $row['n'];
			}
		}

		return $out;
	}

	/**
	 * How long approvals took, for items approved in the month: from the
	 * submission to the decision, in hours. Median and slowest.
	 *
	 * @return array{count: int, median_hours: float|null, longest_hours: float|null}
	 */
	public static function speed( string $from_wall, string $to_wall ): array {
		global $wpdb;

		$items = ItemsTable::name();

		// The difference is taken in PHP, not SQL: the test site runs on SQLite,
		// which has no TIMESTAMPDIFF, and a portable report is worth two reads.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$pairs = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT submitted_at, approved_at FROM {$items} WHERE approved_at BETWEEN %s AND %s AND submitted_at IS NOT NULL AND approved_at >= submitted_at",
				$from_wall,
				$to_wall
			),
			ARRAY_A
		);

		$hours = [];
		foreach ( $pairs as $pair ) {
			$in  = strtotime( (string) $pair['submitted_at'] . ' UTC' );
			$out = strtotime( (string) $pair['approved_at'] . ' UTC' );
			if ( false !== $in && false !== $out && $out >= $in ) {
				$hours[] = ( $out - $in ) / 3600;
			}
		}

		if ( [] === $hours ) {
			return [ 'count' => 0, 'median_hours' => null, 'longest_hours' => null ];
		}

		sort( $hours );
		$n      = count( $hours );
		$median = 0 === $n % 2 ? ( $hours[ $n / 2 - 1 ] + $hours[ $n / 2 ] ) / 2 : $hours[ intdiv( $n, 2 ) ];

		return [ 'count' => $n, 'median_hours' => round( $median, 1 ), 'longest_hours' => round( (float) max( $hours ), 1 ) ];
	}

	/**
	 * Organisations: verified, refused, changes accepted or refused.
	 *
	 * @return array<string, int>
	 */
	public static function organisations( string $from_utc, string $to_utc ): array {
		$found = self::count_actions( $from_utc, $to_utc, [ 'org', 'signup' ] );

		return [
			'verified'         => (int) ( $found['join_approved'] ?? 0 ),
			'refused'          => (int) ( $found['join_refused'] ?? 0 ),
			'changes_accepted' => (int) ( $found['org_change_approved'] ?? 0 ),
			'changes_refused'  => (int) ( $found['org_change_rejected'] ?? 0 ),
			'registered'       => (int) ( $found['join_registered_org'] ?? 0 ),
			'claimed'          => (int) ( $found['join_claimed'] ?? 0 ),
			'claims_approved'  => (int) ( $found['join_claim_approved'] ?? 0 ),
			'claims_refused'   => (int) ( $found['join_claim_refused'] ?? 0 ),
			'attached'         => (int) ( $found['join_attached'] ?? 0 ),
		];
	}

	/**
	 * People: joined by domain match, by invitation, removed.
	 *
	 * @return array<string, int>
	 */
	public static function members( string $from_utc, string $to_utc ): array {
		$found = self::count_actions( $from_utc, $to_utc, [ 'signup', 'invite', 'user', 'org' ] );

		return [
			'joined'   => (int) ( $found['join_completed'] ?? 0 ),
			'invited'  => (int) ( $found['invite_accepted'] ?? 0 ),
			'removed'  => (int) ( $found['member_removed'] ?? 0 ),
		];
	}

	/**
	 * The site today: live listings by type, waiting, organisations, members.
	 *
	 * @return array<string, mixed>
	 */
	public static function now(): array {
		global $wpdb;

		$items = ItemsTable::name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, COUNT(*) AS n FROM {$items} WHERE status = %s GROUP BY post_type", Statuses::LIVE ), ARRAY_A );

		$live = [];
		foreach ( PostTypes::enabled() as $type => $def ) {
			$live[ $type ] = 0;
		}
		foreach ( (array) $rows as $row ) {
			if ( isset( $live[ (string) $row['post_type'] ] ) ) {
				$live[ (string) $row['post_type'] ] = (int) $row['n'];
			}
		}

		$orgs = new \WP_Query(
			[
				'post_type'      => PostTypes::ORG,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'meta_query'     => [ [ 'key' => Meta::ORG_STATUS, 'value' => Meta::ORG_APPROVED ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);

		$members = count_users()['avail_roles'][ Roles::MEMBER ] ?? 0;

		return [
			'live'          => $live,
			'waiting'       => ItemsTable::queue_count(),
			'organisations' => (int) $orgs->found_posts,
			'members'       => (int) $members,
		];
	}

	/**
	 * @param string[] $object_types
	 * @return array<string, int> Action => count.
	 */
	private static function count_actions( string $from_utc, string $to_utc, array $object_types ): array {
		global $wpdb;

		$audit  = AuditTable::name();
		$marks  = implode( ', ', array_fill( 0, count( $object_types ), '%s' ) );
		$params = array_merge( $object_types, [ $from_utc, $to_utc ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT action, COUNT(*) AS n FROM {$audit} WHERE object_type IN ({$marks}) AND logged_at BETWEEN %s AND %s GROUP BY action", ...$params ), // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['action'] ] = (int) $row['n'];
		}

		return $out;
	}
}

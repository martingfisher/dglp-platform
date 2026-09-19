<?php
/**
 * `wp dgl report` and `wp dgl export`: the month's numbers and the CSV
 * files from the command line, for a check on a server nobody can log
 * into from where they are.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Reports;

use DGL\PostTypes;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl report', [ self::class, 'report' ] );
		WP_CLI::add_command( 'dgl export', [ self::class, 'export' ] );
	}

	/**
	 * The month in numbers.
	 *
	 * ## OPTIONS
	 *
	 * [--month=<Y-m>]
	 * : The month, e.g. 2026-09. Defaults to this month.
	 *
	 * @when after_wp_load
	 */
	public static function report( array $args, array $assoc ): void {
		$ym     = Monthly::month_from( $assoc );
		$report = Monthly::for_month( $ym );
		$rows   = [];

		foreach ( $report['decisions'] as $action => $n ) {
			$rows[] = [ 'group' => 'Decisions', 'what' => Csv::decision_label( $action ), 'value' => $n ];
		}
		foreach ( $report['approved_by'] as $type => $n ) {
			$rows[] = [ 'group' => 'Approved', 'what' => 'edits' === $type ? 'Edits' : (string) ( PostTypes::definitions()[ $type ]['plural'] ?? $type ), 'value' => $n ];
		}
		$rows[] = [ 'group' => 'Speed', 'what' => 'Approvals timed', 'value' => $report['speed']['count'] ];
		$rows[] = [ 'group' => 'Speed', 'what' => 'Median hours to approve', 'value' => $report['speed']['median_hours'] ?? '-' ];
		$rows[] = [ 'group' => 'Speed', 'what' => 'Longest hours to approve', 'value' => $report['speed']['longest_hours'] ?? '-' ];
		foreach ( $report['organisations'] as $what => $n ) {
			$rows[] = [ 'group' => 'Organisations', 'what' => str_replace( '_', ' ', $what ), 'value' => $n ];
		}
		foreach ( $report['members'] as $what => $n ) {
			$rows[] = [ 'group' => 'Members', 'what' => $what, 'value' => $n ];
		}
		foreach ( $report['now']['live'] as $type => $n ) {
			$rows[] = [ 'group' => 'On the site now', 'what' => (string) ( PostTypes::definitions()[ $type ]['plural'] ?? $type ), 'value' => $n ];
		}
		$rows[] = [ 'group' => 'On the site now', 'what' => 'Waiting for a decision', 'value' => $report['now']['waiting'] ];
		$rows[] = [ 'group' => 'On the site now', 'what' => 'Verified organisations', 'value' => $report['now']['organisations'] ];
		$rows[] = [ 'group' => 'On the site now', 'what' => 'Member accounts', 'value' => $report['now']['members'] ];

		WP_CLI::line( $report['label'] . ' (' . $report['from']->format( 'Y-m-d' ) . ' to ' . $report['to']->format( 'Y-m-d' ) . ')' );
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'group', 'what', 'value' ] );
	}

	/**
	 * Write a CSV export.
	 *
	 * ## OPTIONS
	 *
	 * <what>
	 * : A content type slug (events, news, training...) or "decisions".
	 *
	 * [--month=<Y-m>]
	 * : For decisions: the month. Defaults to this month.
	 *
	 * [--file=<path>]
	 * : Where to write. Defaults to standard output.
	 *
	 * @when after_wp_load
	 */
	public static function export( array $args, array $assoc ): void {
		$what = (string) ( $args[0] ?? '' );

		if ( Csv::DECISIONS === $what ) {
			$rows = Csv::decisions( Monthly::month_from( $assoc ) );
		} else {
			$type = null;
			foreach ( PostTypes::enabled() as $key => $def ) {
				if ( $what === $key || $what === (string) $def['slug'] ) {
					$type = $key;
				}
			}

			if ( null === $type ) {
				WP_CLI::error( 'Not a content type: ' . $what . '. Use one of ' . implode( ', ', array_column( PostTypes::enabled(), 'slug' ) ) . ', or decisions.' );
			}

			$rows = Csv::listings( $type );
		}

		$csv  = Csv::write( $rows );
		$file = (string) ( $assoc['file'] ?? '' );

		if ( '' === $file ) {
			WP_CLI::line( rtrim( $csv ) );
			return;
		}

		if ( false === file_put_contents( $file, $csv ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			WP_CLI::error( 'Could not write ' . $file );
		}

		WP_CLI::success( ( count( $rows ) - 1 ) . ' rows written to ' . $file );
	}
}

<?php
/**
 * `wp dgl training`: pull Voluntary Action Leeds' courses in.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Training;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * ## EXAMPLES
 *
 *     wp dgl training pull --dry-run
 *     wp dgl training pull --actor=1
 *     wp dgl training status
 */
final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl training', self::class );
	}

	/**
	 * Read VAL's training page and bring the Training items in line with it,
	 * exactly as the nightly run does.
	 *
	 * ## OPTIONS
	 *
	 * [--org=<id>]
	 * : The organisation that owns the courses. Default: the one called
	 *   Voluntary Action Leeds.
	 *
	 * [--actor=<id>]
	 * : The user the audit rows name.
	 *
	 * [--dry-run]
	 * : Say what would happen and change nothing.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function pull( array $args, array $assoc ): void {
		$dry = isset( $assoc['dry-run'] );
		$run = ValTraining::run( $dry, (int) ( $assoc['org'] ?? 0 ), (int) ( $assoc['actor'] ?? 0 ) );

		if ( ! $run['ok'] ) {
			WP_CLI::error( sprintf( 'Nothing changed. %s', $run['error'] ) );
		}

		WP_CLI::log( sprintf( '%s%d course(s) on the page.', $dry ? 'DRY RUN. ' : '', $run['courses'] ) );
		WP_CLI::log( sprintf( 'New: %d. Adopted (already here by hand): %d. Dates or price updated: %d. Brought back: %d. Withdrawn: %d. Unchanged: %d. Failed: %d.', $run['added'], $run['adopted'], $run['updated'], $run['revived'], $run['withdrawn'], $run['unchanged'], $run['failed'] ) );
		WP_CLI::log( sprintf( 'New courses with no "who should attend" on VAL\'s page (their summary is used): %d.', $run['no_who'] ) );

		foreach ( $run['titles'] as $title ) {
			WP_CLI::log( '  + ' . $title );
		}

		foreach ( $run['errors'] as $error ) {
			WP_CLI::warning( $error );
		}

		WP_CLI::success( $dry ? 'Dry run finished. Nothing was written.' : 'Done.' );
	}

	/**
	 * Say how the last run went and when the next is due.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function status( array $args, array $assoc ): void {
		$last = ValTraining::last();
		$next = wp_next_scheduled( ValTraining::HOOK );

		if ( null === $last ) {
			WP_CLI::log( 'Last run: never.' );
		} else {
			WP_CLI::log(
				sprintf(
					'Last run: %s, %s. %d course(s) on the page; %d new, %d updated, %d withdrawn.%s',
					wp_date( 'j M Y H:i', (int) $last['at'] ),
					$last['ok'] ? 'good' : 'FAILED',
					(int) $last['courses'],
					(int) $last['added'],
					(int) $last['updated'],
					(int) $last['withdrawn'],
					'' !== (string) $last['error'] ? ' ' . $last['error'] : ''
				)
			);
		}

		WP_CLI::log( sprintf( 'Next nightly run: %s.', $next ? wp_date( 'j M Y H:i', $next ) : 'not scheduled' ) );
	}
}

<?php
/**
 * WP-CLI: the jobs from Voluntary Action Leeds.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Jobs;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Fetch VAL's jobs now, or see how the last fetch went.
 *
 * ## EXAMPLES
 *
 *     wp dgl jobs refresh
 *     wp dgl jobs status
 */
final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl jobs', self::class );
	}

	/**
	 * Fetch the jobs from VAL now, exactly as the nightly run does.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function refresh( array $args, array $assoc ): void {
		$last = ValJobs::refresh();

		if ( ! $last['ok'] ) {
			WP_CLI::error( sprintf( 'Fetch failed: %s The last good list is kept.', $last['error'] ) );
		}

		$logos = count( array_filter( ValJobs::jobs(), static fn( array $job ): bool => '' !== ValJobs::logo_url( $job ) ) );

		WP_CLI::success( sprintf( '%d job(s) fetched from VAL, %d with a logo copied to this site.', $last['count'], $logos ) );
	}

	/**
	 * Say when the jobs were last fetched, how the last attempt went and
	 * when the next is due.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function status( array $args, array $assoc ): void {
		$fetched = ValJobs::fetched();
		$last    = ValJobs::last();
		$next    = wp_next_scheduled( ValJobs::HOOK );

		WP_CLI::log( sprintf( 'Last good list: %s, %d job(s) shown.', $fetched > 0 ? wp_date( 'j M Y H:i', $fetched ) : 'never', count( ValJobs::jobs() ) ) );
		WP_CLI::log(
			null === $last
				? 'Last attempt: none yet.'
				: sprintf( 'Last attempt: %s, %s.', wp_date( 'j M Y H:i', $last['at'] ), $last['ok'] ? $last['count'] . ' job(s)' : 'failed: ' . $last['error'] )
		);
		WP_CLI::log( sprintf( 'Next nightly run: %s.', $next ? wp_date( 'j M Y H:i', (int) $next ) : 'not scheduled' ) );
	}
}

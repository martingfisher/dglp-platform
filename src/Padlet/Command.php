<?php
/**
 * WP-CLI: the health and wellbeing board from Padlet.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Padlet;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Fetch the board now, or see how the last fetch went.
 *
 * ## EXAMPLES
 *
 *     wp dgl padlet refresh
 *     wp dgl padlet status
 */
final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl padlet', self::class );
	}

	/**
	 * Fetch the board from Padlet now, exactly as the nightly run does.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function refresh( array $args, array $assoc ): void {
		$last = Board::refresh();

		if ( ! $last['ok'] ) {
			WP_CLI::error( sprintf( 'Fetch failed: %s The last good board is kept.', $last['error'] ) );
		}

		$pictures = count( array_filter( Board::items(), static fn( array $item ): bool => '' !== Board::picture_url( $item ) ) );

		WP_CLI::success( sprintf( '%d post(s) shown, %d left out, %d with a picture copied to this site.', $last['count'], $last['left_out'], $pictures ) );
	}

	/**
	 * Say when the board was last fetched, what is left out and why, and
	 * when the next fetch is due.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function status( array $args, array $assoc ): void {
		$fetched  = Board::fetched();
		$last     = Board::last();
		$next     = wp_next_scheduled( Board::HOOK );
		$grouping = Board::grouping();

		WP_CLI::log( sprintf( 'Last good board: %s, %d post(s) shown.', $fetched > 0 ? wp_date( 'j M Y H:i', $fetched ) : 'never', count( Board::items() ) ) );
		WP_CLI::log(
			null === $last
				? 'Last attempt: none yet.'
				: sprintf( 'Last attempt: %s, %s.', wp_date( 'j M Y H:i', $last['at'] ), $last['ok'] ? $last['count'] . ' shown, ' . $last['left_out'] . ' left out' : 'failed: ' . $last['error'] )
		);

		foreach ( Board::grouped( Board::items(), $grouping ) as $group ) {
			WP_CLI::log( sprintf( '  %s: %d', $group['name'], count( $group['items'] ) ) );
		}

		foreach ( Board::left_out() as $post ) {
			WP_CLI::log( sprintf( '  Left out (%s): %s %s', $post['why'] ?? '', '' !== ( $post['title'] ?? '' ) ? $post['title'] : '(no title)', $post['link'] ?? '' ) );
		}

		WP_CLI::log( sprintf( 'Next nightly run: %s.', $next ? wp_date( 'j M Y H:i', (int) $next ) : 'not scheduled' ) );
	}
}

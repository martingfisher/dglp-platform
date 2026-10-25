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
		// From the command line there is time to fetch every new picture.
		$last = Board::refresh( 600 );

		if ( ! $last['ok'] ) {
			WP_CLI::error( sprintf( 'Fetch failed: %s The last good board is kept.', $last['error'] ) );
		}

		$pictures = count( array_filter( Board::items(), static fn( array $item ): bool => '' !== Board::picture_url( $item ) ) );

		WP_CLI::success( sprintf( '%d post(s) shown, %d left out, %d with a picture copied to this site. Read from %s.', $last['count'], $last['left_out'], $pictures, Board::FROM_FEEDS === $last['source'] ? 'the RSS feeds, because the board\'s own data did not answer: ' . $last['error'] : 'the board\'s own data' ) );
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

		WP_CLI::log( sprintf( 'Last good board: %s, %d post(s) shown.', $fetched > 0 ? wp_date( 'j M Y H:i', $fetched ) : 'never', count( Board::items() ) ) );
		WP_CLI::log(
			null === $last
				? 'Last attempt: none yet.'
				: sprintf( 'Last attempt: %s, %s.', wp_date( 'j M Y H:i', $last['at'] ), $last['ok'] ? $last['count'] . ' shown, ' . $last['left_out'] . ' left out' : 'failed: ' . $last['error'] )
		);

		WP_CLI::log( sprintf( 'Read from: %s.', Board::FROM_FEEDS === Board::source() ? 'the RSS feeds (the board\'s own data did not answer)' : 'the board\'s own data' ) );

		foreach ( Board::grouped( Board::items(), Board::sections(), Board::overrides() ) as $group ) {
			WP_CLI::log( sprintf( '  %s (%s): %d', $group['name'], $group['id'], count( $group['items'] ) ) );
		}

		foreach ( Board::left_out() as $post ) {
			WP_CLI::log( sprintf( '  Left out (%s): %s %s', $post['why'] ?? '', '' !== ( $post['title'] ?? '' ) ? $post['title'] : '(no title)', $post['link'] ?? '' ) );
		}

		WP_CLI::log( sprintf( 'Next nightly run: %s.', $next ? wp_date( 'j M Y H:i', (int) $next ) : 'not scheduled' ) );
	}
}

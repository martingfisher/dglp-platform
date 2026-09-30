<?php
/**
 * WP-CLI: spaces to hire.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Spaces;

use DGL\Meta;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Put venues on the map from the command line.
 *
 * ## EXAMPLES
 *
 *     wp dgl spaces geocode
 *     wp dgl spaces geocode --all --dry-run
 */
final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl spaces', self::class );
	}

	/**
	 * Look up the postcode of every live venue without a pin and store
	 * its coordinates.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Every venue in every status, refreshing pins it already has.
	 *
	 * [--dry-run]
	 * : Say which venues would be looked up and stop.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function geocode( array $args, array $assoc ): void {
		$all = isset( $assoc['all'] );
		$ids = Geocode::unplaced( $all );

		if ( [] === $ids ) {
			WP_CLI::success( $all ? 'No venues to look up.' : 'Every live venue already has a pin.' );
			return;
		}

		WP_CLI::log( sprintf( '%d venue(s) to look up.', count( $ids ) ) );

		if ( isset( $assoc['dry-run'] ) ) {
			foreach ( $ids as $id ) {
				WP_CLI::log( sprintf( '  #%d %s (%s)', $id, get_the_title( $id ), (string) get_post_meta( $id, 'dgl_postcode', true ) ) );
			}
			return;
		}

		$placed = 0;
		$failed = 0;

		foreach ( $ids as $id ) {
			$found = Geocode::stamp( $id );

			if ( null === $found ) {
				++$failed;
				WP_CLI::warning( sprintf( '#%d %s: no result for "%s".', $id, get_the_title( $id ), (string) get_post_meta( $id, 'dgl_postcode', true ) ) );
				continue;
			}

			++$placed;
			WP_CLI::log( sprintf( '  #%d %s: %s, %s', $id, get_the_title( $id ), $found['lat'], $found['lng'] ) );
		}

		WP_CLI::success( sprintf( '%d placed, %d without a result.', $placed, $failed ) );
	}

	/**
	 * Every venue and where it is pinned.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function pins( array $args, array $assoc ): void {
		foreach ( Geocode::unplaced( true ) as $id ) {
			$coords = Geocode::coords( $id );
			WP_CLI::log( sprintf( '#%d %s [%s]: %s', $id, get_the_title( $id ), get_post_status( $id ), null === $coords ? 'no pin' : $coords['lat'] . ', ' . $coords['lng'] ) );
		}
	}
}

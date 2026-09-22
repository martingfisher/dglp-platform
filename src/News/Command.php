<?php
/**
 * `wp dgl news`: bring the old site's posts over.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\News;

use DGL\Org\Org;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl news', self::class );
	}

	/**
	 * Convert the old site's published posts into news items, in place.
	 *
	 * ## OPTIONS
	 *
	 * --org=<id>
	 * : The organisation that owns every story not claimed by --owner.
	 *
	 * [--owner=<pairs>]
	 * : Old category slug to organisation id, comma separated. A story's first
	 *   category with an owner here wins. Example: forumcentral:12,val:13
	 *
	 * [--limit=<n>]
	 * : Stop after this many, oldest first.
	 *
	 * [--dry-run]
	 * : Report what would happen and change nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl news import --org=12 --dry-run
	 *     wp dgl news import --org=12 --owner=forumcentral:12,val:13
	 *
	 * @subcommand import
	 */
	public function import( array $args, array $assoc ): void {
		$default_org = (int) ( $assoc['org'] ?? 0 );
		$dry_run     = isset( $assoc['dry-run'] );
		$limit       = (int) ( $assoc['limit'] ?? 0 );
		$owners      = self::owners( (string) ( $assoc['owner'] ?? '' ) );

		if ( $default_org <= 0 || ! Org::exists( $default_org ) ) {
			WP_CLI::error( 'Give --org=<id>: an organisation that exists.' );
		}

		foreach ( $owners as $slug => $org_id ) {
			if ( ! Org::exists( $org_id ) ) {
				WP_CLI::error( sprintf( 'Owner for %s: organisation %d does not exist.', $slug, $org_id ) );
			}
		}

		$ids = LegacyImport::candidates( $limit );

		if ( [] === $ids ) {
			WP_CLI::success( 'No published posts of the old kind. Nothing to do.' );
			return;
		}

		$done     = 0;
		$failed   = 0;
		$by_topic = [];
		$by_org   = [];
		$no_topic = 0;
		$no_image = 0;

		foreach ( $ids as $post_id ) {
			$plan = $dry_run
				? LegacyImport::plan( $post_id, $default_org, $owners )
				: LegacyImport::convert( $post_id, $default_org, $owners, get_current_user_id() );

			if ( is_wp_error( $plan ) ) {
				++$failed;
				WP_CLI::warning( sprintf( '#%d: %s', $post_id, $plan->get_error_message() ) );
				continue;
			}

			++$done;

			if ( [] === $plan['topics'] ) {
				++$no_topic;
			}

			if ( $plan['image'] <= 0 ) {
				++$no_image;
			}

			foreach ( $plan['topics'] as $slug ) {
				$by_topic[ $slug ] = ( $by_topic[ $slug ] ?? 0 ) + 1;
			}

			$by_org[ $plan['org'] ] = ( $by_org[ $plan['org'] ] ?? 0 ) + 1;
		}

		arsort( $by_topic );

		WP_CLI::line( ( $dry_run ? 'Would convert ' : 'Converted ' ) . $done . ' post(s); ' . $failed . ' skipped.' );
		WP_CLI::line( 'Without a topic: ' . $no_topic . '. Without a picture: ' . $no_image . '.' );

		foreach ( $by_org as $org_id => $count ) {
			WP_CLI::line( sprintf( 'Owner %s (#%d): %d', get_the_title( $org_id ), $org_id, $count ) );
		}

		foreach ( $by_topic as $slug => $count ) {
			WP_CLI::line( sprintf( '  %-36s %d', $slug, $count ) );
		}

		if ( ! $dry_run ) {
			WP_CLI::success( 'Done. Old addresses redirect to the new ones.' );
		}
	}

	/** "slug:id,slug:id" to [slug => id]. */
	public static function owners( string $raw ): array {
		$out = [];

		foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) as $pair ) {
			$bits = explode( ':', $pair, 2 );

			if ( 2 === count( $bits ) && '' !== trim( $bits[0] ) && (int) $bits[1] > 0 ) {
				$out[ sanitize_title( $bits[0] ) ] = (int) $bits[1];
			}
		}

		return $out;
	}
}

<?php
/**
 * `wp dgl reindex` and `wp dgl index` - inspecting and rebuilding the index.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Index;

use DGL\PostTypes;
use DGL\Statuses;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Index commands.
 *
 * The items table is a cache of `wp_posts` and its meta, and two docblocks in
 * this plugin have been telling readers since it was written that
 * `wp dgl reindex` rebuilds it. It did not exist. `Sync::rebuild_all()` was
 * there and nothing called it, so a cache that drifted had no recovery path
 * and a claim in the source was simply untrue.
 *
 * Drift is not hypothetical: anything that writes a post without going through
 * WordPress's own hooks leaves the index behind, and so does content that
 * predates the plugin.
 */
final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl reindex', [ self::class, 'reindex' ] );
		WP_CLI::add_command( 'dgl index', self::class );
	}

	/**
	 * Rebuild the items index from the posts table.
	 *
	 * Safe to run at any time. The index is a cache, so the worst a rebuild
	 * can do is take a moment.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl reindex
	 *
	 * @when after_wp_load
	 */
	public static function reindex(): void {
		if ( ! ItemsTable::exists() ) {
			WP_CLI::error( 'The items table does not exist. Reactivate the plugin, or load any page to let it migrate.' );
		}

		$before = self::count();

		// Events carry a next-occurrence stamp the index mirrors; write it
		// first so a deploy does not wait an hour for the roll-forward.
		$stamped = 0;
		foreach ( PostTypes::submittable() as $type ) {
			foreach ( get_posts( [ 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ] ) as $item_id ) {
				\DGL\Events\Series::stamp( (int) $item_id, $type );
				++$stamped;
			}
		}
		WP_CLI::log( sprintf( 'Items stamped: %d', $stamped ) );

		$done   = Sync::rebuild_all();
		$after  = self::count();

		WP_CLI::log( sprintf( 'Rows before: %d', $before ) );
		WP_CLI::log( sprintf( 'Rows after:  %d', $after ) );

		WP_CLI::success( sprintf( '%d item(s) reindexed.', $done ) );
	}

	/**
	 * What is in the index, and what should be.
	 *
	 * The two numbers disagreeing is the whole point: it is how you find out
	 * the cache has drifted, which is otherwise invisible until a member says
	 * their event is missing from a listing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl index status
	 *
	 * @when after_wp_load
	 */
	public function status(): void {
		if ( ! ItemsTable::exists() ) {
			WP_CLI::error( 'The items table does not exist.' );
		}

		global $wpdb;

		$rows      = [];
		$total_idx = 0;
		$total_pst = 0;

		foreach ( PostTypes::submittable() as $type ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$indexed = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . ItemsTable::name() . ' WHERE post_type = %s', $type )
			);

			$statuses = implode( "','", array_map( 'esc_sql', Statuses::all() ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$posts = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('{$statuses}')",
					$type
				)
			);

			$total_idx += $indexed;
			$total_pst += $posts;

			$rows[] = [
				'type'     => $type,
				'in index' => $indexed,
				'in posts' => $posts,
				'drifted'  => $indexed === $posts ? '' : 'YES',
			];
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'type', 'in index', 'in posts', 'drifted' ] );

		if ( $total_idx !== $total_pst ) {
			WP_CLI::warning( sprintf( 'The index is out of step: %d rows against %d posts. Run `wp dgl reindex`.', $total_idx, $total_pst ) );
			return;
		}

		WP_CLI::success( sprintf( 'In step: %d item(s).', $total_idx ) );
	}

	private static function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ItemsTable::name() );
	}
}

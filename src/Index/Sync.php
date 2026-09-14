<?php
/**
 * Keeping the items index in step with the posts it mirrors.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Index;

use DGL\Meta;
use DGL\Org\Org;
use DGL\PostTypes;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The index is a cache, and a cache that drifts is worse than no cache.
 *
 * Every write path funnels through {@see self::sync()}, which rebuilds a row
 * from the post rather than patching it. Rebuilding is slightly more work and
 * removes a whole class of bug: there is no partial update that can leave a row
 * half right.
 *
 * `wp dgl reindex` rebuilds the lot from `wp_posts`, so the index can always be
 * thrown away and recreated.
 */
final class Sync {

	/** Guards against re-entering sync from inside a post update. */
	private static bool $syncing = false;

	public static function init(): void {
		add_action( 'save_post', [ self::class, 'on_save' ], 20, 2 );
		add_action( 'deleted_post', [ self::class, 'on_delete' ], 10, 1 );
		add_action( 'trashed_post', [ self::class, 'on_delete' ], 10, 1 );
		add_action( 'untrashed_post', [ self::class, 'on_save_id' ], 20, 1 );

		/*
		 * Meta is almost always written after wp_insert_post returns, so the row
		 * written during save_post has no organisation on it yet and the item is
		 * invisible to the organisation that owns it. Re-syncing when a mirrored
		 * key changes closes that window without callers having to remember.
		 */
		add_action( 'added_post_meta', [ self::class, 'on_meta' ], 10, 3 );
		add_action( 'updated_post_meta', [ self::class, 'on_meta' ], 10, 3 );
		add_action( 'deleted_post_meta', [ self::class, 'on_meta' ], 10, 3 );
	}

	/**
	 * Keys the index mirrors. A change to any of them makes the row stale.
	 *
	 * @return string[]
	 */
	public static function mirrored_keys(): array {
		return [
			Meta::ITEM_ORG,
			Meta::ITEM_SUBMITTED_AT,
			Meta::ITEM_APPROVED_AT,
			Meta::ITEM_EXPIRES_AT,
		];
	}

	/**
	 * @param int|array<int> $meta_id  Ignored.
	 * @param int            $post_id  The post the meta belongs to.
	 * @param string         $meta_key Which key changed.
	 */
	public static function on_meta( int|array $meta_id, int $post_id, string $meta_key ): void {
		if ( self::$syncing || ! in_array( $meta_key, self::mirrored_keys(), true ) ) {
			return;
		}

		self::sync( $post_id );
	}

	/**
	 * @param int     $post_id Post being saved.
	 * @param WP_Post $post    The post object.
	 */
	public static function on_save( int $post_id, WP_Post $post ): void {
		if ( self::$syncing || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! PostTypes::is_submittable( $post->post_type ) ) {
			return;
		}

		self::sync( $post_id );
	}

	public static function on_save_id( int $post_id ): void {
		$post = get_post( $post_id );

		if ( $post instanceof WP_Post ) {
			self::on_save( $post_id, $post );
		}
	}

	public static function on_delete( int $post_id ): void {
		ItemsTable::delete( $post_id );
	}

	/**
	 * Rebuild one item's index row from the post and its meta.
	 */
	public static function sync( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! PostTypes::is_submittable( $post->post_type ) ) {
			return false;
		}

		self::$syncing = true;

		$org_id = Org::for_item( $post_id );

		$result = ItemsTable::upsert(
			[
				'post_id'              => $post_id,
				'post_type'            => $post->post_type,
				'org_id'               => $org_id,
				'author_id'            => (int) $post->post_author,
				'status'               => $post->post_status,
				'trust_level'          => Org::trust_level( $org_id > 0 ? $org_id : null ),
				'has_pending_revision' => self::has_pending_revision( $post_id ),
				'submitted_at'         => self::meta_or_null( $post_id, Meta::ITEM_SUBMITTED_AT ),
				'approved_at'          => self::meta_or_null( $post_id, Meta::ITEM_APPROVED_AT ),
				'expires_at'           => self::meta_or_null( $post_id, Meta::ITEM_EXPIRES_AT ),
				'updated_at'           => get_post_modified_time( 'Y-m-d H:i:s', true, $post ) ?: current_time( 'mysql', true ),
			]
		);

		self::$syncing = false;

		return $result;
	}

	/**
	 * Whether an unapproved edit is waiting against this item.
	 */
	public static function has_pending_revision( int $post_id ): bool {
		$found = get_posts(
			[
				'post_type'      => PostTypes::REVISION,
				'post_parent'    => $post_id,
				'post_status'    => \DGL\Statuses::PENDING,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		return ! empty( $found );
	}

	/**
	 * Rebuild the whole index. Returns how many rows were written.
	 */
	public static function rebuild_all( int $batch = 200 ): int {
		$done   = 0;
		$paged  = 1;

		do {
			$ids = get_posts(
				[
					'post_type'      => PostTypes::submittable(),
					'post_status'    => 'any',
					'posts_per_page' => $batch,
					'paged'          => $paged,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				]
			);

			foreach ( $ids as $id ) {
				if ( self::sync( (int) $id ) ) {
					++$done;
				}
			}

			++$paged;
		} while ( count( $ids ) === $batch );

		return $done;
	}

	/**
	 * A meta value, or null when it was never set. The index stores real nulls
	 * rather than empty strings so date comparisons behave.
	 */
	private static function meta_or_null( int $post_id, string $key ): ?string {
		$value = get_post_meta( $post_id, $key, true );

		return is_string( $value ) && '' !== $value ? $value : null;
	}
}

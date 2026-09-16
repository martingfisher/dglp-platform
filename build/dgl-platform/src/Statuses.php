<?php
/**
 * The submission lifecycle, as WordPress post statuses.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

defined( 'ABSPATH' ) || exit;

/**
 * Seven states. Two are WordPress core (`draft`, `publish`); the rest are ours.
 *
 * Only `publish` is publicly queryable. Everything else is visible solely
 * through the member dashboard and the moderation queue, both of which scope
 * their own queries through {@see Access\Policy}.
 */
final class Statuses {

	/** Member is still writing. Nobody else can see it. */
	public const DRAFT = 'draft';

	/** Submitted and waiting for a moderator decision. */
	public const PENDING = 'dgl_pending';

	/** Approved and live on the site. */
	public const LIVE = 'publish';

	/** Moderator asked for a change. Back with the member, with a note. */
	public const CHANGES = 'dgl_changes';

	/** Passed its end date or deadline. Came off the listings automatically. */
	public const EXPIRED = 'dgl_expired';

	/** Taken out of circulation deliberately, by the member or the team. */
	public const ARCHIVED = 'dgl_archived';

	/** Refused. Never went live. */
	public const REJECTED = 'dgl_rejected';

	/**
	 * Every status in the lifecycle, in roughly the order a member meets them.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return [
			self::DRAFT,
			self::PENDING,
			self::LIVE,
			self::CHANGES,
			self::EXPIRED,
			self::ARCHIVED,
			self::REJECTED,
		];
	}

	/**
	 * The statuses this plugin registers. Core owns `draft` and `publish`.
	 *
	 * @return string[]
	 */
	public static function custom(): array {
		return [
			self::PENDING,
			self::CHANGES,
			self::EXPIRED,
			self::ARCHIVED,
			self::REJECTED,
		];
	}

	/**
	 * Statuses that count as finished with, for the Archive screen.
	 *
	 * @return string[]
	 */
	public static function archival(): array {
		return [ self::EXPIRED, self::ARCHIVED, self::REJECTED ];
	}

	/**
	 * Human label for a status, as shown on the member dashboard.
	 */
	public static function label( string $status ): string {
		$labels = [
			self::DRAFT    => __( 'Draft', 'dgl-platform' ),
			self::PENDING  => __( 'Pending review', 'dgl-platform' ),
			self::LIVE     => __( 'Live on site', 'dgl-platform' ),
			self::CHANGES  => __( 'Changes requested', 'dgl-platform' ),
			self::EXPIRED  => __( 'Expired', 'dgl-platform' ),
			self::ARCHIVED => __( 'Archived', 'dgl-platform' ),
			self::REJECTED => __( 'Not approved', 'dgl-platform' ),
		];

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Register the five custom statuses. Hooked on `init`.
	 */
	public static function register(): void {
		foreach ( self::custom() as $status ) {
			register_post_status(
				$status,
				[
					'label'                     => self::label( $status ),
					'public'                    => false,
					'internal'                  => false,
					'protected'                 => true,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of items with this status. */
					'label_count'               => _n_noop(
						self::label( $status ) . ' <span class="count">(%s)</span>',
						self::label( $status ) . ' <span class="count">(%s)</span>',
						'dgl-platform'
					),
				]
			);
		}
	}
}

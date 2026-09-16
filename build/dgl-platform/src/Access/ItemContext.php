<?php
/**
 * Everything the access policy needs to know about the thing being acted on.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Access;

/**
 * A plain snapshot of a submission. Built from the `dgl_items` index rather
 * than a full post object, because the policy only needs four fields and the
 * index already has them.
 */
final readonly class ItemContext {

	/**
	 * @param int    $post_id   The item's post ID.
	 * @param string $post_type One of {@see \DGL\PostTypes::submittable()}, or the revision type.
	 * @param int    $org_id    Owning organisation. 0 means orphaned, which is always denied.
	 * @param int    $author_id The member who created it.
	 * @param string $status    One of {@see \DGL\Statuses::all()}.
	 */
	public function __construct(
		public int $post_id,
		public string $post_type,
		public int $org_id,
		public int $author_id,
		public string $status,
	) {}
}

<?php
/**
 * The WordPress adapter for the access policy.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Access;

use DGL\Meta;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Roles;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Turns WordPress users and posts into the plain contexts {@see Policy} reads,
 * and wires the policy into WordPress's own capability system so both routes
 * reach the same decision.
 *
 * Controllers should call {@see self::current_user_can()}. Nothing in the
 * plugin should ever compare organisation IDs by hand.
 */
final class Access {

	/** @var array<int, UserContext> Per-request cache. */
	private static array $user_cache = [];

	/**
	 * Meta capability => policy action.
	 *
	 * Core's `map_meta_cap()` resolves a post type's own capability names
	 * internally and then fires the filter with the ORIGINAL request, so what
	 * arrives here is `edit_post`, never `edit_dgl_item`. Both spellings are
	 * mapped: the core ones because they are what WordPress actually asks, and
	 * the post-type ones so a direct `current_user_can( 'edit_dgl_item', $id )`
	 * reaches the same decision rather than quietly bypassing it.
	 */
	private const META_CAP_MAP = [
		'edit_post'       => Policy::EDIT_ITEM,
		'delete_post'     => Policy::DELETE_ITEM,
		'read_post'       => Policy::VIEW_ITEM,
		'edit_dgl_item'   => Policy::EDIT_ITEM,
		'delete_dgl_item' => Policy::DELETE_ITEM,
		'read_dgl_item'   => Policy::VIEW_ITEM,
	];

	/**
	 * Hook the policy into WordPress capabilities.
	 */
	public static function init(): void {
		add_filter( 'map_meta_cap', [ self::class, 'map_meta_cap' ], 10, 4 );
	}

	/**
	 * Can this user perform this action, optionally on this item?
	 *
	 * @param int                 $user_id The actor.
	 * @param string              $action  A {@see Policy} constant.
	 * @param int|WP_Post|null    $object  The target item, if the action needs one.
	 */
	public static function can( int $user_id, string $action, int|WP_Post|null $object = null ): bool {
		return Policy::decide(
			self::user_context( $user_id ),
			$action,
			null === $object ? null : self::item_context( $object )
		);
	}

	/**
	 * The same question for whoever is logged in.
	 */
	public static function current_user_can( string $action, int|WP_Post|null $object = null ): bool {
		return self::can( get_current_user_id(), $action, $object );
	}

	/**
	 * Build the actor snapshot. Cached for the request.
	 */
	public static function user_context( int $user_id ): UserContext {
		if ( isset( self::$user_cache[ $user_id ] ) ) {
			return self::$user_cache[ $user_id ];
		}

		if ( $user_id <= 0 ) {
			return self::$user_cache[ $user_id ] = UserContext::anonymous();
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return self::$user_cache[ $user_id ] = UserContext::anonymous();
		}

		$org_id = Org::for_user( $user_id );
		$status = (string) get_user_meta( $user_id, Meta::USER_ACCOUNT_STATUS, true );

		/*
		 * Staff accounts are not members and have no account approval of their
		 * own to clear, so they default to approved rather than pending.
		 */
		if ( '' === $status ) {
			$is_staff = array_intersect(
				[ Roles::MODERATOR, UserContext::ROLE_ADMIN ],
				(array) $user->roles
			);

			$status = $is_staff ? UserContext::ACCOUNT_APPROVED : UserContext::ACCOUNT_PENDING;
		}

		return self::$user_cache[ $user_id ] = new UserContext(
			user_id: $user_id,
			roles: array_values( (array) $user->roles ),
			org_id: $org_id,
			org_role: Org::role_for_user( $user_id ),
			account_status: $status,
			org_approved: Org::is_approved( $org_id ),
		);
	}

	/**
	 * Build the item snapshot from the post, which is the source of truth. The
	 * index table is a cache and is deliberately not trusted for permissions.
	 */
	public static function item_context( int|WP_Post $post ): ?ItemContext {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		if ( ! PostTypes::is_submittable( $post->post_type ) && PostTypes::REVISION !== $post->post_type ) {
			return null;
		}

		/*
		 * A pending edit inherits its parent's organisation. Permission to touch
		 * the revision is permission to touch the thing it would replace.
		 */
		$org_source = PostTypes::REVISION === $post->post_type && $post->post_parent > 0
			? (int) $post->post_parent
			: (int) $post->ID;

		return new ItemContext(
			post_id: (int) $post->ID,
			post_type: (string) $post->post_type,
			org_id: Org::for_item( $org_source ),
			author_id: (int) $post->post_author,
			status: (string) $post->post_status,
		);
	}

	/**
	 * Route WordPress's meta capabilities through the policy.
	 *
	 * Two things happen here. Denials become `do_not_allow`, which nothing can
	 * override. Approvals collapse to the single primitive `edit_dgl_items`
	 * rather than WordPress's author-based mapping, because on this site the
	 * organisation owns the content, not the individual who typed it. A
	 * colleague must be able to edit a teammate's submission.
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     The capability being checked.
	 * @param int      $user_id The user being checked.
	 * @param mixed[]  $args    Context, `$args[0]` being the post ID.
	 * @return string[]
	 */
	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		$action = self::META_CAP_MAP[ $cap ] ?? null;

		if ( null === $action ) {
			// Deciding a submission also runs through the policy when scoped to an item.
			if ( Roles::CAP_MODERATE === $cap && isset( $args[0] ) ) {
				return self::can( $user_id, Policy::MODERATE_ITEM, (int) $args[0] )
					? [ Roles::CAP_MODERATE ]
					: [ 'do_not_allow' ];
			}

			return $caps;
		}

		if ( ! isset( $args[0] ) ) {
			return [ 'do_not_allow' ];
		}

		$context = self::item_context( (int) $args[0] );

		// Not one of ours. Leave WordPress's own mapping alone.
		if ( null === $context ) {
			return $caps;
		}

		if ( ! self::can( $user_id, $action, (int) $args[0] ) ) {
			return [ 'do_not_allow' ];
		}

		return Policy::VIEW_ITEM === $action ? [ 'read' ] : [ 'edit_dgl_items' ];
	}

	/**
	 * Clear the per-request cache. For tests and for long-running CLI commands
	 * that switch user.
	 */
	public static function flush_cache( ?int $user_id = null ): void {
		if ( null === $user_id ) {
			self::$user_cache = [];
			return;
		}

		unset( self::$user_cache[ $user_id ] );
	}
}

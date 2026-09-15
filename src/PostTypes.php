<?php
/**
 * The five submittable content types, plus organisations and pending revisions.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

defined( 'ABSPATH' ) || exit;

/**
 * Five separate post types rather than one type with a taxonomy, so each gets
 * its own archive, permalink base and SEO configuration. The per-type field
 * differences live in {@see Schema\FieldRegistry}, which keeps the submission,
 * moderation and digest code type-agnostic despite there being five of them.
 */
final class PostTypes {

	public const NEWS         = 'dgl_news';
	public const EVENT        = 'dgl_event';
	public const TRAINING     = 'dgl_training';
	public const GRANT        = 'dgl_grant';
	public const VOLUNTEERING = 'dgl_volunteering';

	/** An organisation. Carries its own fields, logo, approval state and trust level. */
	public const ORG = 'dgl_org';

	/** An unapproved edit to a live item, parented to the item it will replace. */
	public const REVISION = 'dgl_revision';

	/**
	 * The types a member can submit.
	 *
	 * @return string[]
	 */
	public static function submittable(): array {
		return [
			self::NEWS,
			self::EVENT,
			self::TRAINING,
			self::GRANT,
			self::VOLUNTEERING,
		];
	}

	/**
	 * Whether a post type is one members submit through the dashboard.
	 */
	/**
	 * Every type that moves through the review workflow.
	 *
	 * The five content types plus pending edits. A revision is not submittable
	 * in its own right — nobody creates one from a menu — but it is submitted,
	 * queued, approved and rejected exactly like the thing it would replace.
	 *
	 * @return string[]
	 */
	public static function reviewable(): array {
		return array_merge( self::submittable(), [ self::REVISION ] );
	}

	public static function is_reviewable( string $post_type ): bool {
		return in_array( $post_type, self::reviewable(), true );
	}

	public static function is_submittable( string $post_type ): bool {
		return in_array( $post_type, self::submittable(), true );
	}

	/**
	 * Singular and plural labels, and the public URL base, for each type.
	 *
	 * @return array<string, array{singular: string, plural: string, slug: string}>
	 */
	public static function definitions(): array {
		return [
			self::NEWS         => [
				'singular' => __( 'News item', 'dgl-platform' ),
				'plural'   => __( 'News', 'dgl-platform' ),
				'slug'     => 'news',
			],
			self::EVENT        => [
				'singular' => __( 'Event', 'dgl-platform' ),
				'plural'   => __( 'Events', 'dgl-platform' ),
				'slug'     => 'events',
			],
			self::TRAINING     => [
				'singular' => __( 'Training opportunity', 'dgl-platform' ),
				'plural'   => __( 'Training', 'dgl-platform' ),
				'slug'     => 'training',
			],
			self::GRANT        => [
				'singular' => __( 'Grant', 'dgl-platform' ),
				'plural'   => __( 'Grants', 'dgl-platform' ),
				'slug'     => 'grants',
			],
			self::VOLUNTEERING => [
				'singular' => __( 'Volunteering opportunity', 'dgl-platform' ),
				'plural'   => __( 'Volunteering', 'dgl-platform' ),
				'slug'     => 'volunteering',
			],
		];
	}

	/**
	 * Register every post type. Hooked on `init`.
	 */
	public static function register(): void {
		foreach ( self::definitions() as $post_type => $def ) {
			register_post_type( $post_type, self::submittable_args( $def ) );
		}

		register_post_type( self::ORG, self::org_args() );
		register_post_type( self::REVISION, self::revision_args() );
	}

	/**
	 * Shared arguments for the five member-submittable types.
	 *
	 * All five share one capability set (`dgl_item` / `dgl_items`) so a member's
	 * permissions follow their organisation rather than the content type. The
	 * org-level check itself is applied in {@see Access\Access::map_meta_cap()}.
	 *
	 * @param array{singular: string, plural: string, slug: string} $def Labels and URL base.
	 * @return array<string, mixed>
	 */
	private static function submittable_args( array $def ): array {
		return [
			'labels'          => self::labels( $def['singular'], $def['plural'] ),
			'public'          => true,
			'show_ui'         => true,
			'show_in_rest'    => true,
			'has_archive'     => $def['slug'],
			'rewrite'         => [
				'slug'       => $def['slug'],
				'with_front' => false,
			],
			'menu_icon'       => 'dashicons-megaphone',
			'supports'        => [ 'title', 'editor', 'thumbnail', 'author', 'revisions' ],
			'capability_type' => [ 'dgl_item', 'dgl_items' ],
			'map_meta_cap'    => true,
			'delete_with_user' => false,
		];
	}

	/**
	 * Organisations. Not publicly queryable on their own.
	 *
	 * @return array<string, mixed>
	 */
	private static function org_args(): array {
		return [
			'labels'           => self::labels(
				__( 'Organisation', 'dgl-platform' ),
				__( 'Organisations', 'dgl-platform' )
			),
			'public'           => false,
			'show_ui'          => true,
			'show_in_rest'     => false,
			'has_archive'      => false,
			'rewrite'          => false,
			'menu_icon'        => 'dashicons-groups',
			'supports'         => [ 'title', 'thumbnail' ],
			'capability_type'  => [ 'dgl_org', 'dgl_orgs' ],
			'map_meta_cap'     => true,
			'delete_with_user' => false,
		];
	}

	/**
	 * Pending edits to live items. Never public, never in the admin menu.
	 *
	 * @return array<string, mixed>
	 */
	private static function revision_args(): array {
		return [
			'labels'           => self::labels(
				__( 'Pending edit', 'dgl-platform' ),
				__( 'Pending edits', 'dgl-platform' )
			),
			'public'           => false,
			'show_ui'          => false,
			'show_in_menu'     => false,
			'show_in_rest'     => false,
			'has_archive'      => false,
			'rewrite'          => false,
			'supports'         => [ 'title', 'editor', 'thumbnail', 'author' ],
			'capability_type'  => [ 'dgl_item', 'dgl_items' ],
			'map_meta_cap'     => true,
			'delete_with_user' => false,
		];
	}

	/**
	 * Build a standard WordPress label set from a singular and plural name.
	 *
	 * @return array<string, string>
	 */
	private static function labels( string $singular, string $plural ): array {
		return [
			'name'               => $plural,
			'singular_name'      => $singular,
			'menu_name'          => $plural,
			/* translators: %s: singular content type name. */
			'add_new_item'       => sprintf( __( 'Add new %s', 'dgl-platform' ), strtolower( $singular ) ),
			/* translators: %s: singular content type name. */
			'edit_item'          => sprintf( __( 'Edit %s', 'dgl-platform' ), strtolower( $singular ) ),
			/* translators: %s: plural content type name. */
			'all_items'          => sprintf( __( 'All %s', 'dgl-platform' ), strtolower( $plural ) ),
			/* translators: %s: plural content type name. */
			'search_items'       => sprintf( __( 'Search %s', 'dgl-platform' ), strtolower( $plural ) ),
			/* translators: %s: plural content type name. */
			'not_found'          => sprintf( __( 'No %s found.', 'dgl-platform' ), strtolower( $plural ) ),
			/* translators: %s: plural content type name. */
			'not_found_in_trash' => sprintf( __( 'No %s found in the bin.', 'dgl-platform' ), strtolower( $plural ) ),
		];
	}
}

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
	 * The types a member can submit, in the order members see them.
	 *
	 * Derived from {@see self::definitions()} rather than listed again. Two
	 * hand-maintained orderings of the same five things drift, and the one that
	 * drifts is the one nobody is looking at.
	 *
	 * @return string[]
	 */
	public static function submittable(): array {
		return array_keys( self::definitions() );
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
		/*
		 * Order is the order members see everywhere: the sidebar, the submit
		 * tiles, the filters. Events lead because events are what members post
		 * most, which is what the wireframes show. Alphabetical or
		 * whatever-order-the-constants-happen-to-be-in is not a decision.
		 *
		 * The keys and the slugs are separate from the labels on purpose. A
		 * label is DGLP's wording and can change; `dgl_grant` and `/grants/` are
		 * stored data and published URLs and must not.
		 */
		return [
			self::EVENT        => [
				'singular' => __( 'Event', 'dgl-platform' ),
				'plural'   => __( 'Events', 'dgl-platform' ),
				'slug'     => 'events',
			],
			self::NEWS         => [
				'singular' => __( 'News item', 'dgl-platform' ),
				'plural'   => __( 'News', 'dgl-platform' ),
				'slug'     => 'news',
			],
			self::TRAINING     => [
				'singular' => __( 'Training opportunity', 'dgl-platform' ),
				'plural'   => __( 'Training', 'dgl-platform' ),
				'slug'     => 'training',
			],
			self::GRANT        => [
				/*
				 * "Grants" is the word in the signed proposal, which is the
				 * document DGLP actually agreed. The wireframes say "Funding"
				 * throughout and DGLP's own live site has a "Funding and
				 * Finance" page, so their members' word may well be the broader
				 * one — but that is DGLP's call to make, not a rename to slip
				 * in from a design artefact nobody signed off.
				 *
				 * It is on the open questions list. Changing it is this one
				 * line: the post type key and the /grants/ slug are separate
				 * from the label, so no data moves and no URL breaks.
				 */
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
	 * Types switched off for this release.
	 *
	 * Hidden, not removed. DGLP have said volunteering and grants are not in
	 * this version and may come back, so the schemas, the labels, the slugs and
	 * every line of code that handles them stay exactly where they are. Turning
	 * one back on is deleting a string from this array.
	 *
	 * They are still *registered* as post types. Registering them costs
	 * nothing and means any content that does exist is still addressable,
	 * still in the index and still recoverable. Unregistering a type does not
	 * delete its posts, it orphans them, and orphaned content is how a
	 * temporary decision becomes permanent data loss.
	 *
	 * @return string[]
	 */
	public static function disabled(): array {
		/**
		 * Filters the content types switched off for this release.
		 *
		 * @param string[] $disabled Post type keys.
		 */
		return (array) apply_filters( 'dgl_disabled_types', [ self::GRANT, self::VOLUNTEERING ] );
	}

	public static function is_enabled( string $post_type ): bool {
		return ! in_array( $post_type, self::disabled(), true );
	}

	/**
	 * The types members and staff actually see, in display order.
	 *
	 * Everything that draws a list, a menu, a tile, a filter or a checkbox asks
	 * this. `definitions()` stays complete so labels and slugs survive, and
	 * `submittable()` stays complete so permissions and the index keep working
	 * for content that already exists.
	 *
	 * @return array<string, array{singular:string, plural:string, slug:string}>
	 */
	public static function enabled(): array {
		return array_filter(
			self::definitions(),
			static fn( string $post_type ): bool => self::is_enabled( $post_type ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Enabled type keys only.
	 *
	 * @return string[]
	 */
	public static function enabled_keys(): array {
		return array_keys( self::enabled() );
	}

	/**
	 * Register every post type. Hooked on `init`.
	 */
	public static function register(): void {
		foreach ( self::definitions() as $post_type => $def ) {
			register_post_type( $post_type, self::submittable_args( $def, self::is_enabled( $post_type ) ) );
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
	private static function submittable_args( array $def, bool $enabled = true ): array {
		return [
			'labels'          => self::labels( $def['singular'], $def['plural'] ),
			/*
			 * A switched-off type is still registered, so nothing it owns is
			 * orphaned, but it is not public, has no archive and has no admin
			 * menu. Unregistering it instead would leave its posts addressable
			 * by nothing, which is how a decision described as temporary turns
			 * into data nobody can reach.
			 */
			'public'          => $enabled,
			'show_ui'         => $enabled,
			'show_in_menu'    => $enabled,
			'show_in_rest'    => $enabled,
			'has_archive'     => $enabled ? $def['slug'] : false,
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

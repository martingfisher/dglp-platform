<?php
/**
 * Shared taxonomy across all five content types.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

defined( 'ABSPATH' ) || exit;

/**
 * One topic taxonomy spanning every submittable type.
 *
 * This is what "send me all volunteering opportunities of type X" resolves to
 * in the digest preferences. Term relationships are indexed by WordPress, so
 * filtering digests by topic stays cheap as the content grows.
 */
final class Taxonomies {

	public const TOPIC = 'dgl_topic';

	/**
	 * Register the topic taxonomy. Hooked on `init`, after post types.
	 */
	public static function register(): void {
		register_taxonomy(
			self::TOPIC,
			PostTypes::submittable(),
			[
				'labels'            => [
					'name'          => __( 'Topics', 'dgl-platform' ),
					'singular_name' => __( 'Topic', 'dgl-platform' ),
					'search_items'  => __( 'Search topics', 'dgl-platform' ),
					'all_items'     => __( 'All topics', 'dgl-platform' ),
					'edit_item'     => __( 'Edit topic', 'dgl-platform' ),
					'add_new_item'  => __( 'Add new topic', 'dgl-platform' ),
					'not_found'     => __( 'No topics found.', 'dgl-platform' ),
				],
				'public'            => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => [
					'slug'       => 'topic',
					'with_front' => false,
				],
				'capabilities'      => [
					'manage_terms' => 'manage_dgl_topics',
					'edit_terms'   => 'manage_dgl_topics',
					'delete_terms' => 'manage_dgl_topics',
					'assign_terms' => 'edit_dgl_items',
				],
			]
		);
	}
}

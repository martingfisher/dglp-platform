<?php
/**
 * Wiring the wp-admin side.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One place the admin pieces are turned on, and one guard.
 *
 * Everything under `DGL\Admin` is wp-admin only. Loading it on the front end
 * would add hooks to every member request for screens nobody is looking at.
 */
final class Admin {

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}

		/*
		 * The classic editor for submissions, not the block editor.
		 *
		 * A submission is a form with one free-text field in it. The block
		 * editor puts that one field centre stage, pushes every other field
		 * into a cramped strip underneath, and greets DGLP's team with a
		 * welcome tour. It also cannot show our custom post statuses in its
		 * publish panel, so "Pending review" and "Changes requested" simply do
		 * not appear.
		 *
		 * `show_in_rest` stays true, so anything reading these types over the
		 * REST API is unaffected. This decides the editing screen only.
		 */
		add_filter( 'use_block_editor_for_post_type', [ self::class, 'classic_editor' ], 10, 2 );

		MetaBoxes::init();
		Columns::init();
		Moderate::init();

		add_action( 'admin_notices', [ MetaBoxes::class, 'notices' ] );
		add_action( 'admin_head', [ self::class, 'styles' ] );
	}

	/**
	 * @param bool   $use       Whether WordPress intends to use the block editor.
	 * @param string $post_type The type being edited.
	 */
	public static function classic_editor( $use, $post_type ) {
		return \DGL\PostTypes::is_submittable( (string) $post_type ) ? false : $use;
	}

	/**
	 * A few lines of CSS rather than a stylesheet request.
	 *
	 * This is all the admin screens need, and an extra HTTP request on every
	 * wp-admin page load to deliver twenty lines is a poor trade.
	 */
	public static function styles(): void {
		echo '<style>
			.dgl-admin-fields__heading { font-size: 14px; margin: 18px 0 0; }
			.dgl-admin-fields .form-table th { width: 220px; }
			.dgl-admin-required { color: #b32d2e; }
		</style>';
	}
}

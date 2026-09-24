<?php
/**
 * Wiring the wp-admin side.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Admin;

use DGL\PostTypes;

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
		Organisations::init();
		Health::init();
		Widget::init();

		add_action( 'admin_notices', [ MetaBoxes::class, 'notices' ] );
		add_action( 'admin_head', [ self::class, 'styles' ] );

		/*
		 * A switched-off type has no admin screens, and wp-admin/edit.php
		 * answers that with wp_die() and no status, which WordPress serves as
		 * a 500. Somebody at DGLP with a bookmarked Grants screen would get a
		 * "WordPress › Error" page and a server error in the logs. Sending them
		 * somewhere real is both kinder and quieter.
		 */
		add_action( 'admin_init', [ self::class, 'redirect_disabled_types' ], 5 );
	}

	/**
	 * Send anybody who lands on a switched-off type's screen to the dashboard.
	 *
	 * Priority 5, ahead of most things, because this has to happen before the
	 * screen itself runs. Only the list and add-new screens are checked: the
	 * post editor is reached by ID and would be a legitimate way to look at
	 * content of a type that has been parked.
	 */
	public static function redirect_disabled_types(): void {
		$page = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';

		if ( ! in_array( $page, [ 'edit.php', 'post-new.php' ], true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen was asked for.
		$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';

		if ( '' === $type || ! PostTypes::is_submittable( $type ) || PostTypes::is_enabled( $type ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'index.php' ) );
		exit;
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
			.dgl-admin-widget__links { margin: 0 0 12px; }
			.dgl-admin-widget__links li { margin: 0 0 6px; }
			.dgl-admin-widget__links a { font-weight: 600; }
		</style>';
	}
}

<?php
/**
 * Noticing when something changes outside the workflow.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Admin;

use DGL\Audit\Log;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Statuses;
use DGL\Workflow\Transition;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The audit trail has to be true even when somebody goes round the side.
 *
 * {@see Transition::apply()} is the only route that produces an audit entry, an
 * email and the right index state. But WordPress will happily change a post's
 * status from the Publish box, from Quick Edit, from a bulk action or from
 * WP-CLI, and none of those know this plugin exists. An administrator pressing
 * Publish on a pending submission used to move it live and leave no record that
 * anybody had done anything.
 *
 * This does not block that. Blocking it would mean fighting WordPress in five
 * places and would break legitimate admin work. It records it instead, so the
 * item's history says what actually happened rather than quietly omitting it.
 *
 * Also shows the real status in the Publish box, because WordPress's own one
 * cannot render a custom status and simply showed nothing.
 */
final class Guard {

	public static function init(): void {
		add_filter( 'wp_insert_post_data', [ self::class, 'hold_status' ], 10, 2 );
		add_action( 'transition_post_status', [ self::class, 'record' ], 10, 3 );
		add_action( 'post_submitbox_misc_actions', [ self::class, 'show_status' ] );
		add_action( 'admin_head', [ self::class, 'hide_status_controls' ] );
	}

	/**
	 * Only the workflow may change a submission's status.
	 *
	 * This was found by doing it: open a pending submission in wp-admin, fix a
	 * typo, press the normal save button, and the item went live. WordPress's
	 * publish box cannot represent `dgl_pending`, so it posts its own `pending`
	 * and the save promotes the post to `publish`. The member's work reached the
	 * public without anybody deciding anything and without the member being
	 * told.
	 *
	 * So the status is pinned to whatever is already stored unless
	 * {@see Transition} is the one doing the writing. Editing the fields from
	 * wp-admin still works exactly as before; only the status is held.
	 *
	 * @param array<string, mixed> $data    The row about to be written.
	 * @param array<string, mixed> $postarr The raw submitted post array.
	 * @return array<string, mixed>
	 */
	public static function hold_status( array $data, array $postarr ): array {
		if ( Transition::$in_progress ) {
			return $data;
		}

		/*
		 * Only wp-admin's own save paths, not every write.
		 *
		 * Pinning the status for all callers also pinned it for WP-CLI, for the
		 * test suite and for any other code, which is too strict: legitimate
		 * recovery work becomes impossible and the block is invisible when it
		 * bites. The danger being closed here is specifically a person pressing
		 * a button on a screen, so that is what is guarded. Programmatic changes
		 * stay possible and are caught by `record()` below instead, which puts
		 * them in the audit trail rather than silently allowing them.
		 */
		if ( ! self::is_admin_form_save() ) {
			return $data;
		}

		if ( ! PostTypes::is_reviewable( (string) ( $data['post_type'] ?? '' ) ) ) {
			return $data;
		}

		$post_id = (int) ( $postarr['ID'] ?? 0 );

		if ( $post_id <= 0 ) {
			// A brand new post has no status to protect yet.
			return $data;
		}

		$current = (string) get_post_status( $post_id );

		// Nothing to protect on a post that is not in the workflow yet, and an
		// auto-draft has to be allowed to become a draft.
		if ( '' === $current || 'auto-draft' === $current || ! in_array( $current, Statuses::all(), true ) ) {
			return $data;
		}

		$data['post_status'] = $current;

		return $data;
	}

	/**
	 * Whether this write is wp-admin saving a form.
	 *
	 * `post.php` covers the edit screen and Quick Edit; `edit.php` covers bulk
	 * edit. Both are somebody pressing a button on a screen.
	 */
	private static function is_admin_form_save(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ajax_action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		return self::should_hold(
			isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '',
			is_admin(),
			wp_doing_ajax(),
			wp_doing_cron(),
			$ajax_action
		);
	}

	/**
	 * The decision, separated from the globals that answer it.
	 *
	 * Gathering the context in one thin method and deciding in a pure one is
	 * the same split used throughout this plugin, and it means the rule can be
	 * asserted without a request, a screen or a superglobal.
	 *
	 * @param string $page        The wp-admin file handling the request.
	 * @param bool   $is_admin    Whether this is a wp-admin request at all.
	 * @param bool   $ajax        Whether it arrived through admin-ajax.
	 * @param bool   $cron        Whether this is a cron run.
	 * @param string $ajax_action The `action` field, for telling Quick Edit apart.
	 */
	public static function should_hold( string $page, bool $is_admin, bool $ajax, bool $cron, string $ajax_action = '' ): bool {
		if ( ! $is_admin || $cron ) {
			return false;
		}

		// Quick Edit posts through admin-ajax rather than a page.
		if ( $ajax ) {
			return 'inline-save' === $ajax_action;
		}

		// post.php is the edit screen; edit.php is where bulk edit posts.
		return in_array( $page, [ 'post.php', 'edit.php' ], true );
	}

	/**
	 * Record a status change this plugin did not make.
	 *
	 * @param string  $new New status.
	 * @param string  $old Old status.
	 * @param WP_Post $post The post.
	 */
	public static function record( string $new, string $old, WP_Post $post ): void {
		if ( Transition::$in_progress ) {
			return;
		}

		if ( ! PostTypes::is_reviewable( (string) $post->post_type ) ) {
			return;
		}

		// A brand new post is not a change of anything.
		if ( $new === $old || 'new' === $old || 'auto-draft' === $new ) {
			return;
		}

		if ( ! in_array( $new, Statuses::all(), true ) && ! in_array( $old, Statuses::all(), true ) ) {
			return;
		}

		Log::record(
			'status_changed_directly',
			PostTypes::REVISION === $post->post_type ? 'revision' : 'item',
			(int) $post->ID,
			Org::for_item( (int) $post->ID ),
			sprintf(
				/* translators: 1: previous status, 2: new status. */
				__( 'Status changed from %1$s to %2$s outside the review workflow. No notification was sent.', 'dgl-platform' ),
				Statuses::label( $old ),
				Statuses::label( $new )
			),
			[ 'status' => [ $old, $new ] ]
		);
	}

	/**
	 * Put the real status in the Publish box.
	 *
	 * WordPress's status control only knows its own statuses, so on a pending
	 * submission it displayed nothing at all and offered a Publish button that
	 * would skip the whole review trail. At minimum the screen should say where
	 * the item actually is and what that button really does.
	 */
	public static function show_status( WP_Post $post ): void {
		if ( ! PostTypes::is_submittable( (string) $post->post_type ) ) {
			return;
		}

		$status = (string) $post->post_status;

		echo '<div class="misc-pub-section">';
		echo '<strong>' . esc_html__( 'Review status:', 'dgl-platform' ) . '</strong> ';
		echo esc_html( Statuses::label( $status ) );

		echo '<p class="description" style="margin-top:6px">'
			. esc_html__( 'Saving here saves the fields. It does not change the review status: only a decision does that, so nothing reaches the public without the member being told.', 'dgl-platform' )
			. '</p>';

		echo '</div>';
	}

	/**
	 * Hide the status and visibility controls on submissions.
	 *
	 * They are inert now that the status is held, and a control that looks like
	 * it does something and does not is worse than no control at all.
	 */
	public static function hide_status_controls(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || 'post' !== $screen->base || ! PostTypes::is_submittable( (string) $screen->post_type ) ) {
			return;
		}

		echo '<style>
			#misc-publishing-actions .misc-pub-post-status,
			#misc-publishing-actions .misc-pub-visibility { display: none; }
		</style>';
	}
}

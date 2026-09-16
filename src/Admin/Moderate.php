<?php
/**
 * Deciding a submission from wp-admin.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Admin;

use DGL\Access\Access;
use DGL\Access\Policy;
use DGL\Dashboard\Router;
use DGL\PostTypes;
use DGL\Statuses;
use DGL\Workflow\StateMachine;
use DGL\Workflow\Transition;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * The quick decisions, for when somebody is already in WordPress.
 *
 * DGLP's team find WordPress clunky, which is exactly why the front-end review
 * queue exists: it puts the difference, the automatic checks and the decision on
 * one screen. That stays the place to review properly.
 *
 * But a staff member who is already in wp-admin tidying something up should not
 * have to change surface to approve the item in front of them. So the same three
 * decisions are available here, and both surfaces call the same
 * {@see Transition::apply()}. There is no second implementation of the workflow
 * and therefore nothing that can drift: the audit entry, the index update, the
 * trust rules and the email all happen identically whichever button was pressed.
 *
 * Anything that needs judgement rather than a click gets a link across to the
 * review screen instead of a worse copy of it.
 */
final class Moderate {

	private const ACTION = 'dgl_admin_decide';

	public static function init(): void {
		add_action( 'add_meta_boxes', [ self::class, 'register' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_filter( 'post_row_actions', [ self::class, 'row_actions' ], 10, 2 );
		add_action( 'admin_notices', [ self::class, 'notices' ] );
	}

	public static function register(): void {
		foreach ( PostTypes::enabled_keys() as $post_type ) {
			add_meta_box(
				'dgl-decide-' . $post_type,
				__( 'Review decision', 'dgl-platform' ),
				[ self::class, 'render' ],
				$post_type,
				'side',
				'high'
			);
		}
	}

	public static function render( WP_Post $post ): void {
		$post_id = (int) $post->ID;
		$review  = Router::url( 'review', (string) $post_id );

		if ( ! Access::current_user_can( Policy::MODERATE_ITEM, $post_id ) ) {
			echo '<p>' . esc_html__( 'This is not waiting on a decision.', 'dgl-platform' ) . '</p>';

			if ( Statuses::PENDING === $post->post_status ) {
				echo '<p>' . esc_html__( 'It is in the queue, but your account cannot decide it.', 'dgl-platform' ) . '</p>';
			}

			return;
		}

		echo '<p>' . esc_html__( 'This is waiting for a decision.', 'dgl-platform' ) . '</p>';

		printf(
			'<p><a class="button button-primary" href="%s">%s</a></p>',
			esc_url( $review ),
			esc_html__( 'Open the review screen', 'dgl-platform' )
		);

		echo '<p class="description">'
			. esc_html__( 'The review screen shows what changed, the automatic checks and the organisation\'s history.', 'dgl-platform' )
			. '</p><hr />';

		/*
		 * Links, not a form.
		 *
		 * A meta box sits inside WordPress's own <form id="post">, and nested
		 * forms are invalid HTML: the browser discards the inner one and the
		 * buttons silently submit the post form instead. The decision appeared
		 * to do nothing, which is exactly the sort of quiet failure that erodes
		 * trust in a tool.
		 *
		 * Only the decision that needs no words is offered here. Asking for a
		 * change and refusing both require a note the member will read, and
		 * that belongs on the review screen where there is room to write one
		 * and the difference is on screen beside it.
		 */
		printf(
			'<p><a class="button button-primary" href="%s">%s</a></p>',
			esc_url( self::decision_url( $post_id, 'approve' ) ),
			esc_html__( 'Approve and publish', 'dgl-platform' )
		);

		echo '<p class="description">'
			. esc_html__( 'To ask for a change or refuse it, use the review screen. Both need a reason the member will read.', 'dgl-platform' )
			. '</p>';
	}

	/**
	 * A nonced link that carries out one decision.
	 */
	private static function decision_url( int $post_id, string $decision ): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action'   => self::ACTION,
					'post'     => $post_id,
					'decision' => $decision,
				],
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $post_id
		);
	}

	/**
	 * A one-click approve on the list row, for the obvious ones.
	 *
	 * Only approve. Asking for a change and refusing both need a written reason,
	 * and a row action has nowhere to type one.
	 *
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, WP_Post $post ): array {
		if ( ! PostTypes::is_submittable( (string) $post->post_type ) ) {
			return $actions;
		}

		$post_id = (int) $post->ID;

		if ( ! Access::current_user_can( Policy::MODERATE_ITEM, $post_id ) ) {
			return $actions;
		}

		$actions['dgl_approve'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::decision_url( $post_id, 'approve' ) ),
			esc_html__( 'Approve', 'dgl-platform' )
		);
		$actions['dgl_review']  = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Router::url( 'review', (string) $post_id ) ),
			esc_html__( 'Review properly', 'dgl-platform' )
		);

		return $actions;
	}

	/**
	 * Carry out a decision.
	 *
	 * Everything that matters is delegated: the permission check, the legality
	 * of the move, the audit entry and the email all live in
	 * {@see Transition::apply()}. This reads the request and reports the answer.
	 */
	public static function handle(): void {
		$post_id = isset( $_REQUEST['post'] ) ? (int) $_REQUEST['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		check_admin_referer( self::ACTION . '_' . $post_id );

		$decision = isset( $_REQUEST['decision'] ) ? sanitize_key( wp_unslash( $_REQUEST['decision'] ) ) : '';
		$note     = isset( $_REQUEST['note'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['note'] ) ) : '';

		$action = match ( $decision ) {
			'approve' => StateMachine::APPROVE,
			'changes' => StateMachine::REQUEST_CHANGES,
			'reject'  => StateMachine::REJECT,
			default   => '',
		};

		$back = (string) get_edit_post_link( $post_id, 'raw' );
		$back = '' !== $back ? $back : admin_url();

		if ( '' === $action ) {
			wp_safe_redirect( add_query_arg( 'dgl_decided', 'unknown', $back ) );
			exit;
		}

		$result = Transition::apply( $post_id, $action, get_current_user_id(), $note );

		if ( is_wp_error( $result ) ) {
			set_transient( 'dgl_admin_decision_' . $post_id, $result->get_error_message(), 60 );
			wp_safe_redirect( add_query_arg( 'dgl_decided', 'error', $back ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'dgl_decided', $decision, $back ) );
		exit;
	}

	public static function notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$decided = isset( $_GET['dgl_decided'] ) ? sanitize_key( wp_unslash( $_GET['dgl_decided'] ) ) : '';

		if ( '' === $decided ) {
			return;
		}

		/*
		 * Submissions only. The organisations screen has its own decisions and
		 * its own wording, and this notice claimed "the member has been told"
		 * on a screen where no email is sent at all.
		 */
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || ! PostTypes::is_submittable( (string) $screen->post_type ) ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'error' === $decided ) {
			$message = get_transient( 'dgl_admin_decision_' . $post_id );
			delete_transient( 'dgl_admin_decision_' . $post_id );

			echo '<div class="notice notice-error"><p>'
				. esc_html( is_string( $message ) && '' !== $message ? $message : __( 'That decision could not be applied.', 'dgl-platform' ) )
				. '</p></div>';
			return;
		}

		$said = match ( $decided ) {
			'approve' => __( 'Approved. It is on the site and the member has been told.', 'dgl-platform' ),
			'changes' => __( 'Sent back to the member with your note.', 'dgl-platform' ),
			'reject'  => __( 'Refused. The member has been told, with your reason.', 'dgl-platform' ),
			default   => __( 'Nothing happened: that was not a decision this system understands.', 'dgl-platform' ),
		};

		$class = 'unknown' === $decided ? 'notice-warning' : 'notice-success';

		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $said ) . '</p></div>';
	}
}

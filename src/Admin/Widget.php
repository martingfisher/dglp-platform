<?php
/**
 * A wp-admin dashboard widget that points the review team at the Admin area.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Admin;

use DGL\Access\Access;
use DGL\Dashboard\Router;
use DGL\Index\ItemsTable;

defined( 'ABSPATH' ) || exit;

/**
 * The team's work happens in the Admin area on the front end, not here.
 * Somebody who lands on the WordPress dashboard, which is where a login
 * drops an administrator, should see the way there with what is waiting.
 * Shown to the review team only; a member never sees this screen.
 */
final class Widget {

	public const ID = 'dgl_admin_area';

	public static function init(): void {
		add_action( 'wp_dashboard_setup', [ self::class, 'register' ] );
	}

	public static function register(): void {
		if ( ! Access::user_context( get_current_user_id() )->is_moderator() ) {
			return;
		}

		wp_add_dashboard_widget( self::ID, __( 'DGLP Admin area', 'dgl-platform' ), [ self::class, 'render' ] );

		// First in the left column, not last: it is the reason the team are here.
		global $wp_meta_boxes;

		$boxes = $wp_meta_boxes['dashboard']['normal']['core'] ?? [];

		if ( isset( $boxes[ self::ID ] ) ) {
			$wp_meta_boxes['dashboard']['normal']['core'] = [ self::ID => $boxes[ self::ID ] ] + $boxes; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering our own widget.
		}
	}

	/**
	 * What is waiting for the team: the same count the Admin area's menu shows.
	 */
	public static function waiting(): int {
		return ItemsTable::queue_count() + count( \DGL\Org\Profile::awaiting_review() ) + \DGL\Joining\Store::awaiting_count();
	}

	public static function render(): void {
		$waiting = self::waiting();

		echo '<p>' . esc_html__( 'Reviewing submissions, joining requests and organisations happens in the Admin area on the site itself, not in wp-admin.', 'dgl-platform' ) . '</p>';

		printf(
			'<p><a class="button button-primary" href="%s">%s</a></p>',
			esc_url( Router::url( 'review' ) ),
			0 === $waiting
				? esc_html__( 'Open the review queue', 'dgl-platform' )
				: esc_html( sprintf(
					/* translators: %d: number waiting. */
					_n( 'Open the review queue: %d waiting', 'Open the review queue: %d waiting', $waiting, 'dgl-platform' ),
					$waiting
				) )
		);

		$links = [
			[ __( 'Organisations', 'dgl-platform' ), Router::url( 'review', 'orgs' ), __( 'Trust settings, details and people.', 'dgl-platform' ) ],
			[ __( 'Approved', 'dgl-platform' ), Router::url( 'review', 'decided' ), __( 'What is on the site, and what was refused.', 'dgl-platform' ) ],
			[ __( 'Reports', 'dgl-platform' ), Router::url( 'review', 'reports' ), __( 'The month in numbers.', 'dgl-platform' ) ],
			[ __( 'Team guide', 'dgl-platform' ), Router::url( 'help', 'team' ), __( 'How the queue, trust and joining work.', 'dgl-platform' ) ],
		];

		echo '<ul class="dgl-admin-widget__links">';

		foreach ( $links as [ $label, $url, $help ] ) {
			printf(
				'<li><a href="%s">%s</a> <span class="description">%s</span></li>',
				esc_url( $url ),
				esc_html( $label ),
				esc_html( $help )
			);
		}

		echo '</ul>';

		echo '<p class="description">' . esc_html__( 'Verification and email domains for an organisation are still set here in wp-admin, under Organisations.', 'dgl-platform' ) . '</p>';
	}
}

<?php
/**
 * Dispatching member area requests to screens.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Dashboard;

use DGL\Access\Access;
use DGL\Access\Policy;
use DGL\Access\UserContext;
use DGL\Index\ItemsTable;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

/**
 * Works out which screen the request wants, gathers its data, renders it.
 *
 * The gates come first and in order: signed in, then a member at all, then
 * approved. Each has its own screen rather than a redirect, because "you are
 * waiting for approval" is information, and bouncing somebody to the home page
 * tells them nothing.
 */
final class Controller {

	/**
	 * @param string[] $segments Path inside the member area.
	 */
	public static function handle( array $segments ): void {
		if ( ! is_user_logged_in() ) {
			self::screen( 'sign-in', [ 'redirect_to' => Router::url( ...$segments ) ], __( 'Sign in', 'dgl-platform' ) );
			return;
		}

		$user = Access::user_context( get_current_user_id() );

		if ( ! $user->is_member() && ! $user->is_moderator() ) {
			self::screen( 'no-access', [], __( 'No access', 'dgl-platform' ) );
			return;
		}

		if ( UserContext::ACCOUNT_SUSPENDED === $user->account_status ) {
			self::screen( 'suspended', [ 'user' => $user ], __( 'Account suspended', 'dgl-platform' ) );
			return;
		}

		// An unapproved member can draft, so they get the dashboard with a banner
		// rather than being locked out entirely.
		self::route( $segments, $user );
	}

	/**
	 * @param string[] $segments
	 */
	private static function route( array $segments, UserContext $user ): void {
		$first = $segments[0] ?? '';

		match ( true ) {
			'' === $first                  => self::home( $user ),
			'archive' === $first           => self::archive( $user ),
			'notifications' === $first     => self::stub( 'Notifications', $user ),
			'profile' === $first           => self::stub( 'Organisation and profile', $user ),
			'review' === $first            => self::review( $segments, $user ),
			null !== self::type_for( $first ) => self::category( (string) self::type_for( $first ), $user ),
			default                        => self::not_found( $user ),
		};
	}

	/**
	 * Map a URL slug back to a post type.
	 */
	public static function type_for( string $slug ): ?string {
		foreach ( PostTypes::definitions() as $post_type => $def ) {
			if ( $def['slug'] === $slug ) {
				return $post_type;
			}
		}

		return null;
	}

	/**
	 * Wireframe 1d: stat tiles, category tiles, recent activity.
	 */
	private static function home( UserContext $user ): void {
		$org_id = $user->org_id ?? 0;
		$counts = $org_id > 0 ? ItemsTable::counts_for_org( $org_id ) : array_fill_keys( Statuses::all(), 0 );

		$recent = $org_id > 0 ? ItemsTable::for_org( $org_id, null, null, 5 ) : [];

		self::screen(
			'home',
			[
				'user'     => $user,
				'org'      => $org_id > 0 ? get_post( $org_id ) : null,
				'counts'   => $counts,
				'recent'   => array_map( [ self::class, 'row' ], $recent ),
				'tiles'    => self::tiles( $org_id ),
			],
			__( 'Your dashboard', 'dgl-platform' ),
			$user
		);
	}

	/**
	 * Wireframe 1e: one content type, filtered by status.
	 */
	private static function category( string $post_type, UserContext $user ): void {
		$org_id = $user->org_id ?? 0;
		$def    = PostTypes::definitions()[ $post_type ];

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$filter = in_array( $status, Statuses::all(), true ) ? [ $status ] : null;

		$ids = $org_id > 0 ? ItemsTable::for_org( $org_id, [ $post_type ], $filter, 50 ) : [];

		self::screen(
			'category',
			[
				'user'      => $user,
				'post_type' => $post_type,
				'label'     => $def['plural'],
				'singular'  => $def['singular'],
				'slug'      => $def['slug'],
				'items'     => array_map( [ self::class, 'row' ], $ids ),
				'counts'    => $org_id > 0 ? ItemsTable::counts_for_org( $org_id, $post_type ) : [],
				'active'    => $status,
			],
			$def['plural'],
			$user
		);
	}

	/**
	 * Wireframe 1h: expired, archived and refused items.
	 */
	private static function archive( UserContext $user ): void {
		$org_id = $user->org_id ?? 0;
		$ids    = $org_id > 0 ? ItemsTable::for_org( $org_id, null, Statuses::archival(), 50 ) : [];

		self::screen(
			'archive',
			[
				'user'  => $user,
				'items' => array_map( [ self::class, 'row' ], $ids ),
			],
			__( 'Archive', 'dgl-platform' ),
			$user
		);
	}

	/**
	 * Wireframe 1k: the moderation queue.
	 *
	 * @param string[] $segments
	 */
	private static function review( array $segments, UserContext $user ): void {
		if ( ! $user->is_moderator() ) {
			self::screen( 'no-access', [], __( 'No access', 'dgl-platform' ), $user );
			return;
		}

		$ids = ItemsTable::queue( null, 50 );

		self::screen(
			'review-queue',
			[
				'user'  => $user,
				'items' => array_map( [ self::class, 'row' ], $ids ),
			],
			__( 'Review queue', 'dgl-platform' ),
			$user
		);
	}

	private static function stub( string $title, UserContext $user ): void {
		self::screen( 'stub', [ 'user' => $user, 'title' => $title ], $title, $user );
	}

	private static function not_found( UserContext $user ): void {
		status_header( 404 );
		self::screen( 'not-found', [ 'user' => $user ], __( 'Not found', 'dgl-platform' ), $user );
	}

	/**
	 * Flatten one item into what the templates need.
	 *
	 * @return array<string, mixed>
	 */
	private static function row( int $post_id ): array {
		$post = get_post( $post_id );

		if ( null === $post ) {
			return [];
		}

		$def      = PostTypes::definitions()[ $post->post_type ] ?? [ 'singular' => '', 'slug' => '' ];
		$modified = get_post_modified_time( 'Y-m-d H:i:s', true, $post );

		return [
			'id'       => $post_id,
			'title'    => $post->post_title !== '' ? $post->post_title : __( 'Untitled', 'dgl-platform' ),
			'type'     => $def['singular'],
			'status'   => $post->post_status,
			'updated'  => is_string( $modified ) ? $modified : null,
			'url'      => Router::url( 'item', (string) $post_id ),
			'can_edit' => Access::current_user_can( Policy::EDIT_ITEM, $post_id ),
		];
	}

	/**
	 * The "submit something" tiles from wireframe 1d.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function tiles( int $org_id ): array {
		$blurbs = [
			PostTypes::EVENT        => __( 'Date, time, venue, booking link.', 'dgl-platform' ),
			PostTypes::NEWS         => __( 'Headline, story, image, contact.', 'dgl-platform' ),
			PostTypes::TRAINING     => __( 'Provider, cost, dates, who it is for.', 'dgl-platform' ),
			PostTypes::GRANT        => __( 'Amount, deadline, eligibility.', 'dgl-platform' ),
			PostTypes::VOLUNTEERING => __( 'Role, commitment, location, contact.', 'dgl-platform' ),
		];

		$tiles = [];

		foreach ( PostTypes::definitions() as $post_type => $def ) {
			$tiles[] = [
				'label' => $def['plural'],
				'blurb' => $blurbs[ $post_type ] ?? '',
				'url'   => Router::url( 'new', $def['slug'] ),
			];
		}

		return $tiles;
	}

	/**
	 * Render a screen inside the theme's header and footer.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function screen( string $template, array $data, string $title, ?UserContext $user = null ): void {
		add_filter( 'pre_get_document_title', static fn(): string => $title . ' | ' . get_bloginfo( 'name' ) );

		Assets::enqueue();

		get_header();

		View::output(
			'dashboard/shell',
			[
				'title'    => $title,
				'user'     => $user,
				'nav'      => null !== $user ? Navigation::items( $user ) : [],
				'content'  => View::render( 'dashboard/' . $template, $data ),
			]
		);

		get_footer();
	}
}

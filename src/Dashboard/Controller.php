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
use DGL\Schema\FieldRegistry;
use DGL\Statuses;
use DGL\Taxonomies;
use DGL\Workflow\StateMachine;
use DGL\Workflow\Transition;

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
			'new' === $first               => self::new_item( $segments[1] ?? '', $user ),
			'edit' === $first              => self::edit( $segments, $user ),
			'item' === $first              => self::detail( (int) ( $segments[1] ?? 0 ), $user ),
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

	/**
	 * Start a new submission and go straight to step one.
	 */
	private static function new_item( string $slug, UserContext $user ): void {
		$post_type = self::type_for( $slug );

		if ( null === $post_type ) {
			self::not_found( $user );
			return;
		}

		$post_id = Wizard::create( $post_type, $user->user_id );

		if ( is_wp_error( $post_id ) ) {
			self::screen( 'error', [ 'user' => $user, 'message' => $post_id->get_error_message() ], __( 'Cannot start', 'dgl-platform' ), $user );
			return;
		}

		wp_safe_redirect( Router::url( 'edit', (string) $post_id, '1' ) );
		exit;
	}

	/**
	 * The wizard itself. Handles both showing a step and saving one.
	 *
	 * @param string[] $segments
	 */
	private static function edit( array $segments, UserContext $user ): void {
		$post_id = (int) ( $segments[1] ?? 0 );
		$step    = max( 1, min( FieldRegistry::STEP_REVIEW, (int) ( $segments[2] ?? 1 ) ) );
		$post    = get_post( $post_id );

		if ( null === $post || ! PostTypes::is_submittable( $post->post_type ) ) {
			self::not_found( $user );
			return;
		}

		if ( ! Access::can( $user->user_id, Policy::EDIT_ITEM, $post_id ) ) {
			self::screen(
				'error',
				[
					'user'    => $user,
					'message' => Statuses::PENDING === $post->post_status
						? __( 'This is with the review team at the moment, so it is locked until they have read it. That is deliberate: it means what they approve is exactly what they read.', 'dgl-platform' )
						: __( 'You cannot edit this.', 'dgl-platform' ),
				],
				__( 'Locked', 'dgl-platform' ),
				$user
			);
			return;
		}

		$errors  = [];
		$notice  = '';
		$is_post = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' );

		if ( $is_post ) {
			check_admin_referer( Wizard::NONCE );

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validator sanitises every declared field.
			$input = isset( $_POST[ FieldRenderer::INPUT_NAME ] ) ? (array) wp_unslash( $_POST[ FieldRenderer::INPUT_NAME ] ) : [];
			$intent = isset( $_POST['dgl_intent'] ) ? sanitize_key( wp_unslash( $_POST['dgl_intent'] ) ) : 'next';

			if ( 'submit' === $intent ) {
				self::handle_submit( $post_id, (string) $post->post_type, $user );
				return;
			}

			$errors = Wizard::save_step( $post_id, (string) $post->post_type, $step, $input, $_FILES );

			if ( empty( $errors ) ) {
				if ( 'close' === $intent ) {
					wp_safe_redirect( Router::url( PostTypes::definitions()[ $post->post_type ]['slug'] ) );
					exit;
				}

				$next = 'back' === $intent ? max( 1, $step - 1 ) : min( FieldRegistry::STEP_REVIEW, $step + 1 );
				wp_safe_redirect( Router::url( 'edit', (string) $post_id, (string) $next ) );
				exit;
			}

			$notice = __( 'Almost there. A few things need fixing before you can carry on.', 'dgl-platform' );
		}

		$values = Wizard::values( $post_id, (string) $post->post_type );

		// Show what they just typed, not what was saved, so nothing looks lost.
		if ( $is_post ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked above, escaped on output.
			foreach ( (array) wp_unslash( $_POST[ FieldRenderer::INPUT_NAME ] ?? [] ) as $key => $raw ) {
				if ( array_key_exists( $key, $values ) && is_scalar( $raw ) ) {
					$values[ $key ] = $raw;
				}
			}
		}

		$def = PostTypes::definitions()[ $post->post_type ];

		self::screen(
			FieldRegistry::STEP_REVIEW === $step ? 'wizard-review' : 'wizard',
			[
				'user'      => $user,
				'post'      => $post,
				'post_type' => $post->post_type,
				'singular'  => $def['singular'],
				'plural'    => $def['plural'],
				'slug'      => $def['slug'],
				'step'      => $step,
				'fields'    => FieldRegistry::for_step( (string) $post->post_type, $step ),
				'values'    => $values,
				'errors'    => $errors,
				'notice'    => $notice,
				'topics'    => get_terms( [ 'taxonomy' => Taxonomies::TOPIC, 'hide_empty' => false ] ),
				'chosen'    => wp_get_object_terms( $post_id, Taxonomies::TOPIC, [ 'fields' => 'ids' ] ),
				'chosen_names' => wp_get_object_terms( $post_id, Taxonomies::TOPIC, [ 'fields' => 'names' ] ),
				'all_errors' => FieldRegistry::STEP_REVIEW === $step
					? Wizard::validate_all( $post_id, (string) $post->post_type )
					: [],
			],
			sprintf(
				/* translators: %s: content type name. */
				__( 'New %s', 'dgl-platform' ),
				strtolower( $def['singular'] )
			),
			$user
		);
	}

	/**
	 * Send a completed draft to the review team.
	 */
	private static function handle_submit( int $post_id, string $post_type, UserContext $user ): void {
		$outstanding = Wizard::validate_all( $post_id, $post_type );

		if ( ! empty( $outstanding ) ) {
			wp_safe_redirect( Router::url( 'edit', (string) $post_id, (string) FieldRegistry::STEP_REVIEW ) );
			exit;
		}

		$result = Transition::apply( $post_id, StateMachine::SUBMIT, $user->user_id );

		if ( is_wp_error( $result ) ) {
			self::screen(
				'error',
				[ 'user' => $user, 'message' => $result->get_error_message() ],
				__( 'Could not submit', 'dgl-platform' ),
				$user
			);
			return;
		}

		wp_safe_redirect( add_query_arg( 'submitted', '1', Router::url( 'item', (string) $post_id ) ) );
		exit;
	}

	/**
	 * A single submission, its status and its history. Wireframe 1g.
	 */
	private static function detail( int $post_id, UserContext $user ): void {
		$post = get_post( $post_id );

		if ( null === $post || ! PostTypes::is_submittable( $post->post_type ) ) {
			self::not_found( $user );
			return;
		}

		if ( ! Access::can( $user->user_id, Policy::VIEW_ITEM, $post_id ) ) {
			self::screen( 'no-access', [], __( 'No access', 'dgl-platform' ), $user );
			return;
		}

		$def = PostTypes::definitions()[ $post->post_type ];

		self::screen(
			'detail',
			[
				'user'       => $user,
				'post'       => $post,
				'singular'   => $def['singular'],
				'slug'       => $def['slug'],
				'fields'     => FieldRegistry::for_type( (string) $post->post_type ),
				'values'     => Wizard::values( $post_id, (string) $post->post_type ),
				'history'    => \DGL\Audit\Log::for_object( 'item', $post_id ),
				'can_edit'   => Access::can( $user->user_id, Policy::EDIT_ITEM, $post_id ),
				'submitted'  => isset( $_GET['submitted'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			],
			$post->post_title !== '' ? $post->post_title : __( 'Submission', 'dgl-platform' ),
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

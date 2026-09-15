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
use DGL\Moderation\Checks;
use DGL\PostTypes;
use DGL\Schema\FieldRegistry;
use DGL\Statuses;
use DGL\Taxonomies;
use DGL\Workflow\Revisions;
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
			'profile' === $first           => self::profile( $segments, $user ),
			'review' === $first            => self::review( $segments, $user ),
			'new' === $first               => self::new_item( $segments[1] ?? '', $user ),
			'edit' === $first              => self::edit( $segments, $user ),
			'discard' === $first           => self::discard( (int) ( $segments[1] ?? 0 ), $user ),
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

		$post_id = (int) ( $segments[1] ?? 0 );

		if ( $post_id > 0 ) {
			self::review_item( $post_id, $user );
			return;
		}

		/*
		 * Paged. The queue is oldest-first, so an unpaged list capped at fifty
		 * hides the newest arrivals; cap it the other way and it hides the
		 * oldest, which is worse. A backlog over one page has to be reachable.
		 */
		$per_page = 50;
		$total    = ItemsTable::queue_count();
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( $pages, max( 1, (int) ( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ids      = ItemsTable::queue( null, $per_page, ( $page - 1 ) * $per_page );

		self::screen(
			'review-queue',
			[
				'user'  => $user,
				'items' => array_map( static fn( int $id ): array => self::row( $id, true ), $ids ),
				'total' => $total,
				'page'  => $page,
				'pages' => $pages,
				'first' => $total > 0 ? ( ( $page - 1 ) * $per_page ) + 1 : 0,
				'last'  => min( $total, $page * $per_page ),
			],
			__( 'Review queue', 'dgl-platform' ),
			$user
		);
	}

	/**
	 * One submission, read and decided. Wireframe 1l.
	 */
	private static function review_item( int $post_id, UserContext $user ): void {
		$post = get_post( $post_id );

		if ( null === $post || ! PostTypes::is_reviewable( $post->post_type ) ) {
			self::not_found( $user );
			return;
		}

		/*
		 * A pending edit is reviewed as itself, against its parent's schema.
		 * The moderator is reading the proposed version and the difference, not
		 * the version that is currently on the site.
		 */
		$is_edit     = PostTypes::REVISION === $post->post_type;
		$schema_type = $is_edit ? Revisions::type_of( $post_id ) : (string) $post->post_type;
		$parent      = $is_edit ? get_post( Revisions::target( $post_id ) ) : null;

		if ( $is_edit && ( '' === $schema_type || null === $parent ) ) {
			self::not_found( $user );
			return;
		}

		$error = '';

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			check_admin_referer( Wizard::NONCE );

			$intent = isset( $_POST['dgl_intent'] ) ? sanitize_key( wp_unslash( $_POST['dgl_intent'] ) ) : '';
			$note   = isset( $_POST['dgl_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dgl_note'] ) ) : '';

			if ( 'check_links' === $intent ) {
				Checks::check_links_now( $post_id, $schema_type );
				wp_safe_redirect( Router::url( 'review', (string) $post_id ) );
				exit;
			}

			$action = match ( $intent ) {
				'approve' => StateMachine::APPROVE,
				'changes' => StateMachine::REQUEST_CHANGES,
				'reject'  => StateMachine::REJECT,
				default   => '',
			};

			if ( '' !== $action ) {
				$result = Transition::apply( $post_id, $action, $user->user_id, $note );

				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
				} else {
					wp_safe_redirect( add_query_arg( 'decided', $intent, Router::url( 'review' ) ) );
					exit;
				}
			}
		}

		$queue    = ItemsTable::queue( null, max( 500, ItemsTable::queue_count() ) );
		$position = array_search( $post_id, $queue, true );
		$def      = PostTypes::definitions()[ $schema_type ];
		$org_id   = \DGL\Org\Org::for_item( $is_edit ? (int) $parent->ID : $post_id );
		$counts   = $org_id > 0 ? ItemsTable::counts_for_org( $org_id ) : [];

		self::screen(
			'review-item',
			[
				'user'      => $user,
				'post'      => $post,
				'singular'  => $def['singular'],
				'is_edit'   => $is_edit,
				'parent'    => $parent,
				/*
				 * Reading a form twice and spotting the one altered sentence is
				 * proofreading, not review, and at volume nobody does it
				 * reliably. So an edit is shown as its differences first.
				 */
				'changes'   => $is_edit ? Revisions::changed_fields( $post_id ) : [],
				'fields'    => FieldRegistry::for_type( $schema_type ),
				'values'    => Wizard::values( $post_id, $schema_type ),
				'checks'    => Checks::run( $post_id, $schema_type ),
				'history'   => \DGL\Audit\Log::for_object( $is_edit ? 'revision' : 'item', $post_id ),
				'topics'    => wp_get_object_terms( $post_id, Taxonomies::TOPIC, [ 'fields' => 'names' ] ),
				'error'     => $error,
				'org'       => $org_id > 0 ? get_post( $org_id ) : null,
				'org_trust' => \DGL\Org\Trust::label( \DGL\Org\Org::trust_level( $org_id > 0 ? $org_id : null ) ),
				'submitter' => get_userdata( (int) $post->post_author ),
				'approved'  => $counts[ Statuses::LIVE ] ?? 0,
				'rejected'  => $counts[ Statuses::REJECTED ] ?? 0,
				'position'  => false === $position ? null : (int) $position + 1,
				'total'     => count( $queue ),
				'prev'      => false !== $position && $position > 0 ? $queue[ $position - 1 ] : null,
				'next'      => false !== $position && isset( $queue[ $position + 1 ] ) ? $queue[ $position + 1 ] : null,
				'decidable' => Access::can( $user->user_id, Policy::MODERATE_ITEM, $post_id ),
			],
			$post->post_title !== '' ? $post->post_title : __( 'Review', 'dgl-platform' ),
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

		if ( null === $post || ! PostTypes::is_reviewable( $post->post_type ) ) {
			self::not_found( $user );
			return;
		}

		/*
		 * Editing something that is already on the site does not edit the thing
		 * on the site. It opens a pending edit and works on that, so the
		 * published version is never altered by anybody except a moderator
		 * approving the change. The redirect happens before the permission
		 * check does any work, because from here on the subject is the edit.
		 */
		if ( PostTypes::is_submittable( $post->post_type ) && Revisions::needs_revision( (string) $post->post_status ) ) {
			if ( ! Access::can( $user->user_id, Policy::EDIT_ITEM, $post_id ) ) {
				self::screen( 'no-access', [], __( 'No access', 'dgl-platform' ), $user );
				return;
			}

			$revision_id = Revisions::open( $post_id, $user->user_id );

			if ( is_wp_error( $revision_id ) ) {
				self::screen(
					'error',
					[ 'user' => $user, 'message' => $revision_id->get_error_message() ],
					__( 'Cannot edit', 'dgl-platform' ),
					$user
				);
				return;
			}

			wp_safe_redirect( Router::url( 'edit', (string) $revision_id, '1' ) );
			exit;
		}

		/*
		 * A pending edit carries no field schema of its own, so every screen
		 * that renders, validates or saves one works against its parent's type.
		 */
		$is_edit     = PostTypes::REVISION === $post->post_type;
		$schema_type = $is_edit ? Revisions::type_of( $post_id ) : (string) $post->post_type;
		$parent      = $is_edit ? get_post( Revisions::target( $post_id ) ) : null;

		if ( $is_edit && ( '' === $schema_type || null === $parent ) ) {
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
				self::handle_submit( $post_id, $schema_type, $user );
				return;
			}

			$errors = Wizard::save_step( $post_id, $schema_type, $step, $input, $_FILES );

			if ( empty( $errors ) ) {
				if ( 'close' === $intent ) {
					// Closing an edit goes back to the item it belongs to, not
					// to a list the edit does not appear in.
					wp_safe_redirect(
						$is_edit
							? Router::url( 'item', (string) $parent->ID )
							: Router::url( PostTypes::definitions()[ $post->post_type ]['slug'] )
					);
					exit;
				}

				$next = 'back' === $intent ? max( 1, $step - 1 ) : min( FieldRegistry::STEP_REVIEW, $step + 1 );
				wp_safe_redirect( Router::url( 'edit', (string) $post_id, (string) $next ) );
				exit;
			}

			$notice = __( 'Almost there. A few things need fixing before you can carry on.', 'dgl-platform' );
		}

		$values = Wizard::values( $post_id, $schema_type );

		// Show what they just typed, not what was saved, so nothing looks lost.
		if ( $is_post ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked above, escaped on output.
			foreach ( (array) wp_unslash( $_POST[ FieldRenderer::INPUT_NAME ] ?? [] ) as $key => $raw ) {
				if ( array_key_exists( $key, $values ) && is_scalar( $raw ) ) {
					$values[ $key ] = $raw;
				}
			}
		}

		$def = PostTypes::definitions()[ $schema_type ];

		self::screen(
			FieldRegistry::STEP_REVIEW === $step ? 'wizard-review' : 'wizard',
			[
				'user'      => $user,
				'post'      => $post,
				'post_type' => $schema_type,
				'singular'  => $def['singular'],
				'plural'    => $def['plural'],
				'slug'      => $def['slug'],
				'step'      => $step,
				'fields'    => FieldRegistry::for_step( $schema_type, $step ),
				'values'    => $values,
				'errors'    => $errors,
				'notice'    => $notice,
				'is_edit'   => $is_edit,
				'parent'    => $parent,
				'changes'   => $is_edit && FieldRegistry::STEP_REVIEW === $step ? Revisions::changed_fields( $post_id ) : [],
				'topics'    => get_terms( [ 'taxonomy' => Taxonomies::TOPIC, 'hide_empty' => false ] ),
				'chosen'    => wp_get_object_terms( $post_id, Taxonomies::TOPIC, [ 'fields' => 'ids' ] ),
				'chosen_names' => wp_get_object_terms( $post_id, Taxonomies::TOPIC, [ 'fields' => 'names' ] ),
				'all_errors' => FieldRegistry::STEP_REVIEW === $step
					? Wizard::validate_all( $post_id, $schema_type )
					: [],
			],
			$is_edit
				? sprintf(
					/* translators: %s: content type name. */
					__( 'Edit %s', 'dgl-platform' ),
					strtolower( $def['singular'] )
				)
				: sprintf(
					/* translators: %s: content type name. */
					__( 'New %s', 'dgl-platform' ),
					strtolower( $def['singular'] )
				),
			$user
		);
	}

	/**
	 * Abandon a pending edit and leave the published version as it is.
	 *
	 * A member's own edit is theirs to throw away. A moderator refusing one
	 * rejects it instead, so the refusal leaves a record behind.
	 */
	private static function discard( int $revision_id, UserContext $user ): void {
		$post = get_post( $revision_id );

		if ( null === $post || PostTypes::REVISION !== $post->post_type ) {
			self::not_found( $user );
			return;
		}

		$parent_id = Revisions::target( $revision_id );

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			wp_safe_redirect( Router::url( 'item', (string) $parent_id ) );
			exit;
		}

		check_admin_referer( Wizard::NONCE );

		/*
		 * An edit sitting with a moderator is not the member's to withdraw. It
		 * is frozen for the same reason a pending submission is: what the team
		 * decide on has to be what they read.
		 */
		if ( Statuses::PENDING === $post->post_status || ! Access::can( $user->user_id, Policy::EDIT_ITEM, $revision_id ) ) {
			self::screen(
				'error',
				[
					'user'    => $user,
					'message' => __( 'That edit is with the review team, so it cannot be withdrawn until they have read it.', 'dgl-platform' ),
				],
				__( 'Locked', 'dgl-platform' ),
				$user
			);
			return;
		}

		Revisions::discard( $revision_id );

		wp_safe_redirect( add_query_arg( 'discarded', '1', Router::url( 'item', (string) $parent_id ) ) );
		exit;
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

		$is_edit = PostTypes::REVISION === get_post_type( $post_id );

		/*
		 * An edit that changes nothing is not sent. Somebody opening a listing,
		 * looking at it and pressing through to the end should not cost a
		 * moderator a review, and it should not lock their own content for
		 * three days either.
		 */
		if ( $is_edit && Revisions::is_empty( $post_id ) ) {
			self::screen(
				'error',
				[
					'user'    => $user,
					'message' => __( 'Nothing has changed, so there is nothing to review. Make a change first, or discard the edit and leave the published version as it is.', 'dgl-platform' ),
				],
				__( 'Nothing to submit', 'dgl-platform' ),
				$user
			);
			return;
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

		// An edit sends the member back to the item it belongs to, because the
		// edit itself is about to stop existing as a thing they can open.
		$landing = $is_edit ? Revisions::target( $post_id ) : $post_id;

		wp_safe_redirect( add_query_arg( 'submitted', '1', Router::url( 'item', (string) $landing ) ) );
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

		$def      = PostTypes::definitions()[ $post->post_type ];
		$revision = Revisions::open_for( $post_id );

		self::screen(
			'detail',
			[
				'user'       => $user,
				'post'       => $post,
				'singular'   => $def['singular'],
				'slug'       => $def['slug'],
				'fields'     => FieldRegistry::for_type( (string) $post->post_type ),
				'values'     => Wizard::values( $post_id, (string) $post->post_type ),
				// One timeline, with any edits folded in. Two separate histories
				// is an accurate model and a confusing screen.
				'history'    => Revisions::history_for( $post_id ),
				'can_edit'   => Access::can( $user->user_id, Policy::EDIT_ITEM, $post_id ),
				'submitted'  => isset( $_GET['submitted'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'discarded'  => isset( $_GET['discarded'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				/*
				 * What is shown below is the published version, always. The
				 * pending edit is announced in a banner rather than rendered in
				 * place, so nobody reads an unapproved change and takes it for
				 * what is on the site.
				 */
				'revision'   => $revision,
				'changes'    => null !== $revision ? Revisions::changed_fields( (int) $revision->ID ) : [],
			],
			$post->post_title !== '' ? $post->post_title : __( 'Submission', 'dgl-platform' ),
			$user
		);
	}

	/**
	 * Wireframe 1i: the organisation, the person, and who can post.
	 *
	 * Tabs rather than one long form, because the five groups have nothing to do
	 * with each other and a member coming here to change a phone number should
	 * not scroll past their colleagues' accounts to find it.
	 *
	 * @param string[] $segments
	 */
	private static function profile( array $segments, UserContext $user ): void {
		$tabs = [
			'organisation' => __( 'Organisation', 'dgl-platform' ),
			'you'          => __( 'Your details', 'dgl-platform' ),
			'members'      => __( 'Members', 'dgl-platform' ),
			'signin'       => __( 'Sign-in and security', 'dgl-platform' ),
			'email'        => __( 'Email preferences', 'dgl-platform' ),
		];

		$tab    = (string) ( $segments[1] ?? 'organisation' );
		$tab    = isset( $tabs[ $tab ] ) ? $tab : 'organisation';
		$org_id = $user->org_id ?? 0;

		/*
		 * A review-team account has no organisation, so the organisation and
		 * members tabs have nothing behind them. They are removed rather than
		 * shown empty.
		 */
		if ( $org_id <= 0 ) {
			unset( $tabs['organisation'], $tabs['members'] );

			if ( ! isset( $tabs[ $tab ] ) ) {
				$tab = 'you';
			}
		}

		$errors = [];
		$notice = '';

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			check_admin_referer( Wizard::NONCE );

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the validator sanitises every declared field.
			$input = isset( $_POST[ FieldRenderer::INPUT_NAME ] ) ? (array) wp_unslash( $_POST[ FieldRenderer::INPUT_NAME ] ) : [];

			[ $errors, $notice ] = self::save_profile( $tab, $org_id, $user, $input );

			if ( [] === $errors ) {
				wp_safe_redirect( add_query_arg( 'saved', '1', Router::url( 'profile', $tab ) ) );
				exit;
			}
		}

		self::screen(
			'profile',
			[
				'user'       => $user,
				'tabs'       => $tabs,
				'tab'        => $tab,
				'org'        => $org_id > 0 ? get_post( $org_id ) : null,
				'org_status' => $org_id > 0 ? Org::status( $org_id ) : '',
				'org_trust'  => \DGL\Org\Trust::label( \DGL\Org\Org::trust_level( $org_id > 0 ? $org_id : null ) ),
				'fields'     => \DGL\Org\Schema::fields(),
				// The form shows what was asked for; the panel above it shows
				// what is live. Swapping those round makes the field look as if
				// it rejected the member's edit.
				'values'     => $org_id > 0 ? \DGL\Org\Profile::form_values( $org_id ) : [],
				'pending'    => $org_id > 0 ? \DGL\Org\Profile::pending_changes( $org_id ) : [],
				'person_fields' => \DGL\Org\Schema::person_fields(),
				'person'     => \DGL\Org\Profile::person_values( $user->user_id ),
				'account'    => get_userdata( $user->user_id ),
				'colleagues' => $org_id > 0 ? self::colleagues( $org_id ) : [],
				'errors'     => $errors,
				'notice'     => $notice,
				'saved'      => isset( $_GET['saved'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			],
			__( 'Organisation and profile', 'dgl-platform' ),
			$user
		);
	}

	/**
	 * Save whichever tab was posted.
	 *
	 * @param array<string, mixed> $input
	 * @return array{0: array<string,string>, 1: string} Errors, then a notice.
	 */
	private static function save_profile( string $tab, int $org_id, UserContext $user, array $input ): array {
		if ( 'you' === $tab ) {
			return [ \DGL\Org\Profile::save_person( $user->user_id, $input ), '' ];
		}

		if ( 'organisation' !== $tab ) {
			return [ [], '' ];
		}

		/*
		 * Only an owner edits the organisation. A contributor can submit content
		 * for it, which is not the same as being able to rename it.
		 */
		if ( $org_id <= 0 || ! $user->is_org_owner() ) {
			return [
				[ 'org_name' => __( 'Only an owner can change the organisation.', 'dgl-platform' ) ],
				'',
			];
		}

		$result = \DGL\Org\Profile::save( $org_id, $input, $user->user_id );

		return [ $result['errors'], '' ];
	}

	/**
	 * Everybody who can post for this organisation.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function colleagues( int $org_id ): array {
		$rows = [];

		foreach ( Org::members( $org_id ) as $user_id ) {
			$person = get_userdata( $user_id );

			if ( ! $person ) {
				continue;
			}

			$rows[] = [
				'id'      => $user_id,
				'name'    => (string) $person->display_name,
				'email'   => (string) $person->user_email,
				'role'    => Org::role_for_user( $user_id ),
				'status'  => (string) get_user_meta( $user_id, \DGL\Meta::USER_ACCOUNT_STATUS, true ),
				'is_you'  => $user_id === get_current_user_id(),
			];
		}

		// Owners first, then alphabetical, so the list reads the way people
		// think about it rather than in user-ID order.
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$rank = static fn( array $r ): int => UserContext::ORG_OWNER === $r['role'] ? 0 : 1;

				return [ $rank( $a ), strtolower( $a['name'] ) ] <=> [ $rank( $b ), strtolower( $b['name'] ) ];
			}
		);

		return $rows;
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
	private static function row( int $post_id, bool $for_review = false ): array {
		$post = get_post( $post_id );

		if ( null === $post ) {
			return [];
		}

		$is_edit  = PostTypes::REVISION === $post->post_type;
		$type_key = $is_edit ? Revisions::type_of( $post_id ) : (string) $post->post_type;
		$def      = PostTypes::definitions()[ $type_key ] ?? [ 'singular' => '', 'slug' => '' ];
		$modified = get_post_modified_time( 'Y-m-d H:i:s', true, $post );

		return [
			'id'       => $post_id,
			'title'    => $post->post_title !== '' ? $post->post_title : __( 'Untitled', 'dgl-platform' ),
			/*
			 * The queue has to say which rows are edits. A reviewer who opens
			 * what they think is a new listing and finds a published one with
			 * two words changed has been sent to the wrong screen.
			 */
			'type'     => $is_edit
				? sprintf(
					/* translators: %s: content type name, for example "event". */
					__( 'Edit to %s', 'dgl-platform' ),
					strtolower( (string) $def['singular'] )
				)
				: $def['singular'],
			'is_edit'  => $is_edit,
			'status'   => $post->post_status,
			'updated'  => is_string( $modified ) ? $modified : null,
			'url'      => $for_review
				? Router::url( 'review', (string) $post_id )
				: Router::url( 'item', (string) $post_id ),
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

		// The editor only appears in the wizard, so only the wizard pays for it.
		Assets::enqueue( in_array( $template, [ 'wizard' ], true ) );

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

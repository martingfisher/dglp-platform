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
use DGL\Invites\Invites;
use DGL\Invites\Rules as InviteRules;
use DGL\Invites\Store as InviteStore;
use DGL\Email\Digest\Frequency;
use DGL\Email\Digest\Store as DigestStore;
use DGL\Email\Digest\Copy as DigestCopy;
use DGL\Org\Org;
use DGL\Moderation\Checks;
use DGL\PostTypes;
use DGL\Schema\FieldRegistry;
use DGL\Statuses;
use DGL\Taxonomies;
use DGL\Dashboard\Notifications;
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
		/*
		 * Accepting an invitation is the one screen a stranger is supposed to
		 * reach. It comes before the sign-in gate on purpose: bouncing somebody
		 * who has never had an account to a login form, with their one-time
		 * link stuffed into a redirect parameter, is how an invitation gets
		 * abandoned.
		 */
		if ( 'invite' === ( $segments[0] ?? '' ) ) {
			self::invite( (string) ( $segments[1] ?? '' ) );
			return;
		}

		/*
		 * Unsubscribing must work from the link in the email, with no sign-in
		 * and no hunting for a setting. An opt-out that asks somebody to log in
		 * first is an opt-out that becomes a spam complaint.
		 */
		if ( 'unsubscribe' === ( $segments[0] ?? '' ) ) {
			self::unsubscribe( (string) ( $segments[1] ?? '' ) );
			return;
		}

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
			'notifications' === $first     => self::notifications( $user ),
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
		foreach ( PostTypes::enabled() as $post_type => $def ) {
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

		/*
		 * The four stat tiles filter the activity list below them rather than
		 * being decoration. A number you cannot click is a number you cannot act
		 * on: a member who sees "Needs your attention 2" wants those two, and
		 * was previously left to go and find them.
		 *
		 * Filtering in place rather than linking to a new screen, because the
		 * counts are across all five content types and no cross-type list
		 * exists. This is the list that is already here.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only filter.
		$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$filter = in_array( $filter, Statuses::all(), true ) ? $filter : '';

		$statuses = '' !== $filter ? [ $filter ] : null;
		$limit    = '' !== $filter ? 50 : 5;

		$recent = $org_id > 0 ? ItemsTable::for_org( $org_id, null, $statuses, $limit ) : [];

		// A brand new member has five zeros. Four noughts above the only thing
		// they can usefully do is noise, so the tiles stand down until there is
		// something to count.
		$has_anything = array_sum( array_map( 'intval', $counts ) ) > 0;

		self::screen(
			'home',
			[
				'user'     => $user,
				'org'      => $org_id > 0 ? get_post( $org_id ) : null,
				'counts'   => $counts,
				'recent'   => array_map( [ self::class, 'row' ], $recent ),
				'tiles'    => self::tiles( $org_id ),
				'filter'   => $filter,
				'has_any'  => $has_anything,
				'attention'=> $org_id > 0 && ( $counts[ Statuses::CHANGES ] ?? 0 ) > 0
					? array_map( [ self::class, 'row' ], ItemsTable::for_org( $org_id, null, [ Statuses::CHANGES ], 5 ) )
					: [],
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

		if ( 'org' === ( $segments[1] ?? '' ) ) {
			self::review_org( (int) ( $segments[2] ?? 0 ), $user );
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
				// What just happened, named. A decision that lands on a silent list
				// reads as a decision that did not happen.
				'decided' => isset( $_GET['decided'] ) ? sanitize_key( wp_unslash( $_GET['decided'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'user'  => $user,
				'items' => array_map( static fn( int $id ): array => self::row( $id, true ), $ids ),
				// Organisations asking to change their name or logo. Same queue,
				// same people, so the same screen.
				'org_changes' => self::org_change_rows(),
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
	 * Organisations with a name or logo change waiting, oldest first.
	 *
	 * @return array<int, array{id:int, name:string, what:string, since:string, url:string}>
	 */
	private static function org_change_rows(): array {
		$rows = [];

		foreach ( \DGL\Org\Profile::awaiting_review() as $org_id ) {
			$labels = array_map( static fn( array $c ): string => (string) $c['label'], \DGL\Org\Profile::pending_changes( $org_id ) );

			$rows[] = [
				'id'    => $org_id,
				'name'  => get_the_title( $org_id ),
				'what'  => implode( ', ', $labels ),
				'since' => Invites::readable_date( \DGL\Org\Profile::pending_at( $org_id ) ),
				'url'   => Router::url( 'review', 'org', (string) $org_id ),
			];
		}

		return $rows;
	}

	/**
	 * A requested name or logo change, read and decided, on the front end.
	 *
	 * The same decision the wp-admin Organisations screen offers, where the
	 * review team already are. The email and the queue both link here.
	 */
	private static function review_org( int $org_id, UserContext $user ): void {
		if ( $org_id <= 0 || PostTypes::ORG !== get_post_type( $org_id ) ) {
			self::not_found( $user );
			return;
		}

		$error = '';

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			check_admin_referer( Wizard::NONCE );

			$intent = isset( $_POST['dgl_intent'] ) ? sanitize_key( wp_unslash( $_POST['dgl_intent'] ) ) : '';
			$note   = isset( $_POST['dgl_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dgl_note'] ) ) : '';

			$result = match ( $intent ) {
				'approve' => \DGL\Org\Profile::approve_pending( $org_id, $user->user_id ),
				'refuse'  => \DGL\Org\Profile::reject_pending( $org_id, $user->user_id, $note ),
				default   => null,
			};

			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} elseif ( null !== $result ) {
				wp_safe_redirect( add_query_arg( 'decided', 'org_' . $intent, Router::url( 'review' ) ) );
				exit;
			}
		}

		self::screen(
			'review-org',
			[
				'user'    => $user,
				'org_id'  => $org_id,
				'name'    => get_the_title( $org_id ),
				'changes' => \DGL\Org\Profile::pending_changes( $org_id ),
				'since'   => Invites::readable_date( \DGL\Org\Profile::pending_at( $org_id ) ),
				'error'   => $error,
			],
			get_the_title( $org_id ),
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
				'approve'   => StateMachine::APPROVE,
				'changes'   => StateMachine::REQUEST_CHANGES,
				'reject'    => StateMachine::REJECT,
				'take_down' => StateMachine::TAKE_DOWN,
				default     => '',
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
				// A live item can be pulled while the team look at it. It goes
				// back to pending, so it comes down now and gets decided later.
				'can_take_down' => Access::can( $user->user_id, Policy::TAKE_DOWN_ITEM, $post_id ),
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

		if ( ! $user->is_fully_approved() ) {
			self::screen(
				'error',
				[
					'user'    => $user,
					'title'   => __( 'Saved, not sent', 'dgl-platform' ),
					'message' => UserContext::ACCOUNT_PENDING === $user->account_status
						? __( 'Your draft is saved. It can be sent for review once the team has approved your account, which usually takes a working day.', 'dgl-platform' )
						: __( 'Your draft is saved, but your organisation is not yet verified, so nothing can be sent for review. The team will be in touch.', 'dgl-platform' ),
				],
				__( 'Saved, not sent', 'dgl-platform' ),
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

		$action_error = '';

		/*
		 * Archive and restore post back to this screen. The state machine
		 * already knew both moves and nothing anywhere called them: the client
		 * were told members could archive, and the only way anything reached
		 * the Archive was an expiry date or a refusal.
		 */
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			check_admin_referer( Wizard::NONCE );

			$intent = isset( $_POST['dgl_intent'] ) ? sanitize_key( wp_unslash( $_POST['dgl_intent'] ) ) : '';
			$action = match ( $intent ) {
				'archive' => StateMachine::ARCHIVE,
				'restore' => StateMachine::RESTORE,
				default   => '',
			};

			if ( '' !== $action ) {
				$result = Transition::apply( $post_id, $action, $user->user_id );

				if ( is_wp_error( $result ) ) {
					$action_error = $result->get_error_message();
				} else {
					wp_safe_redirect( add_query_arg( $intent . 'd', '1', Router::url( 'item', (string) $post_id ) ) );
					exit;
				}
			}
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
				'can_archive' => null === $revision && Access::can( $user->user_id, Policy::ARCHIVE_ITEM, $post_id ),
				'can_restore' => Access::can( $user->user_id, Policy::RESTORE_ITEM, $post_id ),
				'action_error' => $action_error,
				'submitted'  => isset( $_GET['submitted'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'discarded'  => isset( $_GET['discarded'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'archived'   => isset( $_GET['archived'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'restored'   => isset( $_GET['restored'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

			/*
			 * The members tab posts to the same URL as the rest of the profile
			 * but is not a field form: it sends one of three named actions. It
			 * is handled first and redirects on its own, so the field validator
			 * never sees a post that has no fields in it and reports every
			 * required field as missing.
			 */
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked directly above.
			$invite_action = isset( $_POST['dgl_invite_action'] ) ? sanitize_key( wp_unslash( $_POST['dgl_invite_action'] ) ) : '';

			if ( '' !== $invite_action ) {
				self::handle_invite_action( $invite_action, $org_id, $user );
				exit;
			}

			/*
			 * The email tab is checkboxes, not schema fields, and it posts to
			 * the same URL as the rest of the profile. Handled here for the
			 * same reason the members tab is: the field validator would see a
			 * post with no fields in it and report every required one missing.
			 */
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			if ( isset( $_POST['dgl_digest_save'] ) ) {
				self::save_email_prefs( $user );
				wp_safe_redirect( Router::url( 'profile', 'email' ) );
				exit;
			}

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
				'invites'    => $org_id > 0 ? self::invite_rows( $org_id ) : [],
				'can_invite' => $org_id > 0 && [] !== InviteRules::grantable_roles( $user, $org_id ),
				'invite_notice' => self::flash_notice(),
				'invite_error'  => self::flash_error(),
				'email_prefs'   => self::email_prefs( $user ),
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

		$result = \DGL\Org\Profile::save( $org_id, $input, $user->user_id, $_FILES );

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
				'id'         => $user_id,
				'name'       => (string) $person->display_name,
				'email'      => (string) $person->user_email,
				'role'       => Org::role_for_user( $user_id ),
				'status'     => (string) get_user_meta( $user_id, \DGL\Meta::USER_ACCOUNT_STATUS, true ),
				'is_you'     => $user_id === get_current_user_id(),
				'can_remove' => Policy::can_remove_member( Access::user_context( get_current_user_id() ), $user_id, $org_id ),
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

	/**
	 * Wireframe 1j: every decision the team has made, newest first.
	 */
	private static function notifications( UserContext $user ): void {
		self::screen(
			'notifications',
			[
				'user' => $user,
				'rows' => Notifications::for_org( $user->org_id ?? 0 ),
			],
			__( 'Notifications', 'dgl-platform' ),
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

		foreach ( PostTypes::enabled() as $post_type => $def ) {
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

		/*
		 * The shell renders the whole document. It is deliberately not wrapped
		 * in `get_header()` and `get_footer()` any more: a member signed into a
		 * tool was being shown the public site's menu, a breadcrumb, and an
		 * invitation to "Register/Login". The shell still calls `wp_head()` and
		 * `wp_footer()`, so everything hooked there is unaffected.
		 */
		View::output(
			'dashboard/shell',
			[
				'title'   => $title,
				'user'    => $user,
				'nav'     => null !== $user ? Navigation::items( $user ) : [],
				'alerts'  => null !== $user ? Navigation::attention_count( $user ) : 0,
				'content' => View::render( 'dashboard/' . $template, $data ),
			]
		);
	}

	/* ---------------------------------------------------------------------
	 * Invitations
	 * ------------------------------------------------------------------ */

	/**
	 * The accept-an-invitation screen.
	 *
	 * Reachable by anybody holding the link, including somebody with no account
	 * and nobody at all. It renders in the member-area shell but never assumes
	 * a member is looking at it.
	 */
	private static function invite( string $token ): void {
		$title  = __( 'Your invitation', 'dgl-platform' );
		$invite = '' !== $token ? InviteStore::find_by_token( $token ) : null;

		if ( null === $invite ) {
			self::screen(
				'invite',
				[
					'token'  => '',
					'invite' => null,
					'error'  => __( 'That invitation link is not valid. Ask whoever invited you to send a new one.', 'dgl-platform' ),
					'done'   => false,
				],
				$title
			);

			return;
		}

		$now    = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$state  = InviteRules::state( $invite, $now );
		$error  = '';
		$done   = false;

		if ( InviteRules::OPEN !== $state ) {
			$error = InviteRules::accept_error( InviteRules::ACCEPT_CLOSED, $state );
		} elseif ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			check_admin_referer( Wizard::NONCE );

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords must not be sanitised, only checked.
			$password = isset( $_POST['dgl_password'] ) ? (string) wp_unslash( $_POST['dgl_password'] ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- as above.
			$confirm  = isset( $_POST['dgl_password_confirm'] ) ? (string) wp_unslash( $_POST['dgl_password_confirm'] ) : '';
			$name     = isset( $_POST['dgl_name'] ) ? sanitize_text_field( wp_unslash( $_POST['dgl_name'] ) ) : '';

			$existing = get_user_by( 'email', $invite->email );
			$needs_pw = ! $existing instanceof \WP_User;

			// Typed twice. A password nobody can see, mistyped once, is an
			// account its owner is locked out of on their second visit.
			$problem = $needs_pw ? InviteRules::password_problem( $password, $confirm ) : '';

			if ( '' !== $problem ) {
				$error = $problem;
			} else {
				$result = Invites::accept( $token, $name, $password );

				if ( $result['ok'] ) {
					/*
					 * Signed in straight away. An invitation that ends at a
					 * login form is an invitation that ends, and the member has
					 * just proved who they are by holding a link sent to their
					 * own address.
					 */
					if ( ! is_user_logged_in() && $result['user_id'] > 0 ) {
						wp_set_current_user( $result['user_id'] );
						wp_set_auth_cookie( $result['user_id'], false, is_ssl() );
					}

					wp_safe_redirect( Router::url() );
					exit;
				}

				$error = $result['error'];
				$done  = false;
			}
		}

		self::screen(
			'invite',
			[
				'token'     => $token,
				'invite'    => $invite,
				'org_name'  => (string) get_the_title( $invite->org_id ),
				'inviter'   => Invites::person( $invite->invited_by ),
				'role_name' => Invites::role_name( $invite->org_role ),
				'has_account' => get_user_by( 'email', $invite->email ) instanceof \WP_User,
				'expires_on'  => Invites::readable_date( $invite->expires_at ),
				'error'     => $error,
				'done'      => $done,
				// A closed invitation gets no form. The first version showed
				// the "already used" message and the form together, so the
				// button posted, was refused, and drew the same page again:
				// a loop with nothing to say about how to get out of it.
				'open'      => InviteRules::OPEN === $state,
				'signin_url' => wp_login_url( Router::url() ),
			],
			$title
		);
	}

	/**
	 * Send, withdraw or resend an invitation, then redirect.
	 *
	 * Always redirects, so a refresh cannot send a second invitation. The
	 * outcome is carried in a one-shot transient rather than a query string:
	 * an error message in a URL is a message somebody can paste to a colleague
	 * and have them see "already a member" about an address they never typed.
	 */
	private static function handle_invite_action( string $action, int $org_id, UserContext $user ): void {
		$back = Router::url( 'profile', 'members' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller checked the nonce.
		$post = wp_unslash( $_POST );

		if ( 'send' === $action ) {
			$result = Invites::send(
				$user,
				$org_id,
				isset( $post['dgl_invite_email'] ) ? sanitize_email( (string) $post['dgl_invite_email'] ) : '',
				isset( $post['dgl_invite_role'] ) ? sanitize_key( (string) $post['dgl_invite_role'] ) : UserContext::ORG_CONTRIBUTOR
			);

			if ( $result['ok'] ) {
				self::flash(
					sprintf(
						/* translators: %s: email address. */
						__( 'Invitation sent to %s.', 'dgl-platform' ),
						$result['invite']->email
					),
					''
				);
			} else {
				self::flash( '', $result['error'] );
			}

			wp_safe_redirect( $back );
			exit;
		}

		if ( 'remove' === $action ) {
			$member_id = isset( $post['dgl_member_id'] ) ? (int) $post['dgl_member_id'] : 0;
			$person    = get_userdata( $member_id );
			$result    = \DGL\Org\Org::remove_member( $member_id, $user->user_id );

			self::flash(
				is_wp_error( $result ) ? '' : sprintf(
					/* translators: %s: person's name. */
					__( '%s no longer has access. Anything they posted stays with the organisation.', 'dgl-platform' ),
					$person ? $person->display_name : __( 'That person', 'dgl-platform' )
				),
				is_wp_error( $result ) ? $result->get_error_message() : ''
			);

			wp_safe_redirect( $back );
			exit;
		}

		$id = isset( $post['dgl_invite_id'] ) ? (int) $post['dgl_invite_id'] : 0;

		if ( 'revoke' === $action ) {
			$ok = Invites::revoke( $id, $user );

			self::flash(
				$ok ? __( 'Invitation withdrawn.', 'dgl-platform' ) : '',
				$ok ? '' : __( 'That invitation could not be withdrawn. It may already have been used.', 'dgl-platform' )
			);
		}

		if ( 'resend' === $action ) {
			$ok = Invites::resend( $id, $user );

			self::flash(
				$ok ? __( 'Invitation sent again. The previous link no longer works.', 'dgl-platform' ) : '',
				$ok ? '' : __( 'That invitation could not be sent again. Withdraw it and send a new one.', 'dgl-platform' )
			);
		}

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Invitations for the members tab, newest first, with their state resolved.
	 *
	 * @return array<int, array{id:int, email:string, role:string, state:string, state_label:string, expires:string, invited_by:string}>
	 */
	private static function invite_rows( int $org_id ): array {
		$now  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$rows = [];

		foreach ( InviteStore::for_org( $org_id, 50 ) as $invite ) {
			$state = InviteRules::state( $invite, $now );

			// An accepted invitation is a person in the list above. Showing it
			// here as well makes one colleague look like two.
			if ( InviteRules::ACCEPTED === $state ) {
				continue;
			}

			$rows[] = [
				'id'          => $invite->id,
				'email'       => $invite->email,
				'role'        => Invites::role_name( $invite->org_role ),
				'state'       => $state,
				'state_label' => InviteRules::label( $state ),
				'expires'     => Invites::readable_date( $invite->expires_at ),
				'invited_by'  => Invites::person( $invite->invited_by ),
			];
		}

		return $rows;
	}

	/**
	 * Hold a message across the redirect that follows a write.
	 */
	private static function flash( string $notice, string $error ): void {
		set_transient( self::flash_key(), [ 'notice' => $notice, 'error' => $error ], 60 );
	}

	private static function flash_key(): string {
		return 'dgl_flash_' . get_current_user_id();
	}

	private static function flash_notice(): string {
		return (string) ( self::flash_read()['notice'] ?? '' );
	}

	private static function flash_error(): string {
		return (string) ( self::flash_read()['error'] ?? '' );
	}

	/** @var array<string,string>|null */
	private static ?array $flash = null;

	/**
	 * Read the one-shot message and delete it, so a refresh does not repeat it.
	 *
	 * @return array<string, string>
	 */
	private static function flash_read(): array {
		if ( null !== self::$flash ) {
			return self::$flash;
		}

		$stored = get_transient( self::flash_key() );

		if ( is_array( $stored ) ) {
			delete_transient( self::flash_key() );
		}

		return self::$flash = is_array( $stored ) ? $stored : [];
	}
	/* ---------------------------------------------------------------------
	 * Digest preferences
	 * ------------------------------------------------------------------ */

	/**
	 * Stop sending somebody digests, from the link in one.
	 *
	 * One click, no confirmation step, no sign-in. The token identifies the
	 * subscriber, so there is nothing to ask them. A screen that says "are you
	 * sure" to somebody who has already decided is a screen that gets the
	 * message marked as spam instead.
	 */
	private static function unsubscribe( string $token ): void {
		$subscription = '' !== $token ? DigestStore::for_token( $token ) : null;
		$title        = __( 'Email preferences', 'dgl-platform' );

		if ( null === $subscription ) {
			self::screen(
				'unsubscribe',
				[
					'done'  => false,
					'error' => __( 'That link is not valid. If you are signed in you can change your email preferences in your profile.', 'dgl-platform' ),
				],
				$title
			);

			return;
		}

		DigestStore::unsubscribe( $subscription->user_id );

		self::screen(
			'unsubscribe',
			[
				'done'    => true,
				'error'   => '',
				'message' => DigestCopy::unsubscribed_message(),
			],
			$title
		);
	}

	/**
	 * What the email preferences tab shows.
	 *
	 * @return array<string, mixed>
	 */
	private static function email_prefs( UserContext $user ): array {
		$subscription = DigestStore::exists() ? DigestStore::for_user( $user->user_id ) : null;

		$topics = get_terms(
			[
				'taxonomy'   => Taxonomies::TOPIC,
				'hide_empty' => false,
			]
		);

		return [
			'sub'        => $subscription,
			// Null is not "unsubscribed". Somebody who has never been asked has
			// no preferences; somebody who has asked to stop has an answer that
			// has to be respected. The screen says different things for each.
			'asked'      => null !== $subscription,
			'types'      => $subscription?->types ?? [],
			'topic_ids'  => $subscription?->topic_ids ?? [],
			'frequency'  => $subscription?->frequency ?? Frequency::WEEKLY,
			'own_org'    => (bool) ( $subscription?->include_own_org ?? false ),
			'consent_at' => $subscription?->consent_at,
			'last_sent'  => $subscription?->last_sent_at,
			'all_topics' => is_array( $topics ) ? $topics : [],
			'cadences'   => array_combine(
				Frequency::all(),
				array_map( [ Frequency::class, 'label' ], Frequency::all() )
			),
		];
	}

	/**
	 * Save the email preferences tab.
	 *
	 * Asking for nothing is a valid answer and is stored as one: the store
	 * clears consent rather than deleting the row, so the fact that this person
	 * opted out survives the next bulk change.
	 */
	private static function save_email_prefs( UserContext $user ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller checked the nonce.
		$post = wp_unslash( $_POST );

		$types = isset( $post['dgl_digest_types'] ) ? (array) $post['dgl_digest_types'] : [];
		$types = array_values( array_intersect( array_map( 'sanitize_key', $types ), PostTypes::enabled_keys() ) );

		$topics = isset( $post['dgl_digest_topics'] ) ? (array) $post['dgl_digest_topics'] : [];
		$topics = array_values( array_filter( array_map( 'intval', $topics ), static fn( int $t ): bool => $t > 0 ) );

		$frequency = isset( $post['dgl_digest_frequency'] ) ? sanitize_key( (string) $post['dgl_digest_frequency'] ) : Frequency::WEEKLY;

		DigestStore::save(
			$user->user_id,
			$types,
			$topics,
			$frequency,
			! empty( $post['dgl_digest_own_org'] )
		);

		self::flash(
			[] === $types
				? __( 'Saved. You will not get any digests.', 'dgl-platform' )
				: __( 'Saved. Your digest preferences are up to date.', 'dgl-platform' ),
			''
		);
	}
}

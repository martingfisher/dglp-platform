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
use DGL\Events\Reminder;
use DGL\Events\Schedule;
use DGL\Events\Series;
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

		// Joining starts logged out, by definition.
		if ( 'join' === ( $segments[0] ?? '' ) ) {
			self::join( (string) ( $segments[1] ?? '' ) );
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

		/*
		 * The "still running?" link in the reminder email. It carries its own
		 * single-use token, so it works from a phone with no session, and it
		 * lands on a confirm page rather than acting on the GET, so a mail
		 * scanner following the link cannot spend it.
		 */
		if ( 'extend' === ( $segments[0] ?? '' ) ) {
			self::extend( (int) ( $segments[1] ?? 0 ), (string) ( $segments[2] ?? '' ) );
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
			'help' === $first              => self::help( $segments, $user ),
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

		$counts = $org_id > 0 ? ItemsTable::counts_for_org( $org_id, $post_type ) : [];
		$total  = null === $filter ? array_sum( $counts ) : (int) ( $counts[ $status ] ?? 0 );
		$paging = self::page_args( $total, 50 );
		$ids    = $org_id > 0 ? ItemsTable::for_org( $org_id, [ $post_type ], $filter, 50, $paging['offset'] ) : [];

		self::screen(
			'category',
			[
				'user'      => $user,
				'post_type' => $post_type,
				'label'     => $def['plural'],
				'singular'  => $def['singular'],
				'slug'      => $def['slug'],
				'items'     => array_map( [ self::class, 'row' ], $ids ),
				'counts'    => $counts,
				'active'    => $status,
			] + $paging,
			$def['plural'],
			$user
		);
	}

	/**
	 * Wireframe 1h: expired, archived and refused items.
	 */
	private static function archive( UserContext $user ): void {
		$org_id = $user->org_id ?? 0;
		$counts = $org_id > 0 ? ItemsTable::counts_for_org( $org_id ) : [];
		$total  = array_sum( array_intersect_key( $counts, array_flip( Statuses::archival() ) ) );
		$paging = self::page_args( $total, 50 );
		$ids    = $org_id > 0 ? ItemsTable::for_org( $org_id, null, Statuses::archival(), 50, $paging['offset'] ) : [];

		self::screen(
			'archive',
			[
				'user'  => $user,
				'items' => array_map( [ self::class, 'row' ], $ids ),
			] + $paging,
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

		if ( 'join' === ( $segments[1] ?? '' ) ) {
			self::review_join( (int) ( $segments[2] ?? 0 ), $user );
			return;
		}

		if ( 'decided' === ( $segments[1] ?? '' ) ) {
			self::decided( $user );
			return;
		}

		if ( 'reports' === ( $segments[1] ?? '' ) ) {
			self::reports( array_slice( $segments, 2 ), $user );
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
		$paging = self::page_args( ItemsTable::queue_count(), 50 );
		$ids    = ItemsTable::queue( null, 50, $paging['offset'] );

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
				'joins'       => self::join_rows(),
			] + $paging,
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
	 * New organisations registered by somebody whose address matched nothing.
	 *
	 * @return array<int, array{id:int, org:string, who:string, email:string, since:string, url:string}>
	 */
	private static function join_rows(): array {
		$rows = [];

		foreach ( \DGL\Joining\Store::awaiting() as $signup ) {
			$person = get_userdata( $signup->user_id );

			$rows[] = [
				'id'    => $signup->id,
				'org'   => $signup->new_org_name,
				'who'   => $person ? (string) $person->display_name : '',
				'email' => $signup->email,
				'since' => Invites::readable_date( (string) $signup->completed_at ),
				'url'   => Router::url( 'review', 'join', (string) $signup->id ),
			];
		}

		return $rows;
	}

	/**
	 * A registered organisation and its first person, read and decided.
	 */
	private static function review_join( int $signup_id, UserContext $user ): void {
		$signup = \DGL\Joining\Store::find( $signup_id );

		if ( null === $signup ) {
			self::not_found( $user );
			return;
		}

		$error = '';

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			check_admin_referer( Wizard::NONCE );

			$intent = isset( $_POST['dgl_intent'] ) ? sanitize_key( wp_unslash( $_POST['dgl_intent'] ) ) : '';
			$note   = isset( $_POST['dgl_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dgl_note'] ) ) : '';

			$result = match ( $intent ) {
				'approve' => \DGL\Joining\Joining::approve( $signup_id, $user->user_id ),
				'refuse'  => \DGL\Joining\Joining::refuse( $signup_id, $user->user_id, $note ),
				default   => null,
			};

			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} elseif ( null !== $result ) {
				wp_safe_redirect( add_query_arg( 'decided', 'join_' . $intent, Router::url( 'review' ) ) );
				exit;
			}
		}

		$person = get_userdata( $signup->user_id );
		$labels = [];

		foreach ( \DGL\Org\Schema::fields() as $field ) {
			$value = (string) ( $signup->new_org_details[ $field->key ] ?? '' );

			if ( 'org_name' !== $field->key && '' !== trim( $value ) ) {
				$labels[ $field->label ] = $value;
			}
		}

		self::screen(
			'review-join',
			[
				'user'    => $user,
				'signup'  => $signup,
				'org'     => $signup->new_org_name,
				'domains' => \DGL\Org\Org::domains( $signup->org_id ),
				'details' => $labels,
				'who'     => $person ? (string) $person->display_name : '',
				'since'   => Invites::readable_date( (string) $signup->completed_at ),
				'error'   => $error,
			],
			$signup->new_org_name,
			$user
		);
	}

	/**
	 * Joining, logged out: an address, a link, then an account.
	 *
	 * Wireframes 1a to 1c. Nothing exists until the address is proven; the
	 * domain then decides between an organisation on the list and a new one
	 * for the team to verify. Somebody at a listed organisation with a Gmail
	 * address cannot join it here; they need an invitation from an owner.
	 */
	private static function join( string $token ): void {
		$title = __( 'Join the member area', 'dgl-platform' );
		$data  = [ 'stage' => 'email', 'email' => '', 'error' => '', 'token' => $token, 'orgs' => [], 'values' => [] ];

		if ( is_user_logged_in() ) {
			/*
			 * A join link opened while signed in as somebody else, which is
			 * what happens when one person tests with two addresses, or a
			 * shared computer. Say so and offer the way through; the link is
			 * untouched, so it still works after signing out.
			 */
			if ( '' !== $token && 'sent' !== $token ) {
				$data['stage']      = 'signed-in';
				$data['signed_in']  = wp_get_current_user()->display_name;
				$data['logout_url'] = wp_logout_url( Router::url( 'join', $token ) );
				self::screen( 'join', $data, $title );
				return;
			}

			wp_safe_redirect( Router::url() );
			exit;
		}

		if ( 'sent' === $token ) {
			$data['stage'] = 'sent';
			self::screen( 'join', $data, $title );
			return;
		}

		if ( '' === $token ) {
			if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
				check_admin_referer( Wizard::NONCE );
				$email = isset( $_POST['dgl_email'] ) ? sanitize_email( wp_unslash( $_POST['dgl_email'] ) ) : '';

				/*
				 * A robot is shown "sent" and nothing is sent, so it learns
				 * nothing. A person over the limit is told to wait. Both are
				 * checked before an email is ever built.
				 */
				if ( \DGL\Joining\Guard::is_robot( wp_unslash( $_POST ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, not stored.
					\DGL\Audit\Log::record( 'join_blocked', 'signup', 0, 0, __( 'A submission that looked automated was dropped.', 'dgl-platform' ), [], 0 );
					wp_safe_redirect( Router::url( 'join', 'sent' ) );
					exit;
				}

				$limited = \DGL\Joining\Guard::limited( $email, \DGL\Joining\Guard::ip() );
				$result  = '' !== $limited ? [ 'ok' => false, 'code' => 'limited', 'error' => $limited ] : \DGL\Joining\Joining::start( $email );

				if ( $result['ok'] ) {
					wp_safe_redirect( Router::url( 'join', 'sent' ) );
					exit;
				}

				// An address that already has an account gets the way in, not an error.
				if ( 'exists' === ( $result['code'] ?? '' ) ) {
					$data['stage']      = 'exists';
					$data['email']      = $email;
					$data['signin_url'] = add_query_arg( 'email', rawurlencode( $email ), Router::url() );
					$data['reset_url']  = wp_lostpassword_url( Router::url() );
					self::screen( 'join', $data, $title );
					return;
				}

				$data['error'] = $result['error'];
				$data['email'] = $email;
			}

			$data['stamp'] = \DGL\Joining\Guard::stamp();

			self::screen( 'join', $data, $title );
			return;
		}

		$verified = \DGL\Joining\Joining::verify( $token );

		if ( '' !== $verified['error'] || null === $verified['signup'] ) {
			$data['stage'] = 'dead';
			$data['error'] = $verified['error'];
			self::screen( 'join', $data, $title );
			return;
		}

		$signup        = $verified['signup'];
		$data['email'] = $signup->email;
		$data['stage'] = $verified['outcome'];
		$data['orgs']  = array_map( static fn( int $id ): array => [ 'id' => $id, 'name' => (string) get_the_title( $id ) ], $verified['orgs'] );

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			check_admin_referer( Wizard::NONCE );

			$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is handled below.
			$name     = sanitize_text_field( (string) ( $post['dgl_name'] ?? '' ) );
			$password = (string) ( $post['dgl_password'] ?? '' );
			$confirm  = (string) ( $post['dgl_password_confirm'] ?? '' );
			$intent   = sanitize_key( (string) ( $post['dgl_intent'] ?? '' ) );

			$data['values'] = [
				'name'            => $name,
				'org_name'        => sanitize_text_field( (string) ( $post['dgl_org_name'] ?? '' ) ),
				'org_website'     => esc_url_raw( (string) ( $post['dgl_org_website'] ?? '' ) ),
				'org_email'       => sanitize_email( (string) ( $post['dgl_org_email'] ?? '' ) ),
				'org_phone'       => sanitize_text_field( (string) ( $post['dgl_org_phone'] ?? '' ) ),
				'org_number'      => sanitize_text_field( (string) ( $post['dgl_org_number'] ?? '' ) ),
				'org_description' => sanitize_textarea_field( (string) ( $post['dgl_org_description'] ?? '' ) ),
			];

			$problem = InviteRules::password_problem( $password, $confirm );

			if ( '' !== $problem ) {
				$data['error'] = $problem;
			} elseif ( 'join' === $intent ) {
				$result = \DGL\Joining\Joining::join( $signup, (int) ( $post['dgl_org_id'] ?? 0 ), $name, $password );
			} elseif ( 'register' === $intent ) {
				$details = $data['values'];
				unset( $details['name'] );
				$result = \DGL\Joining\Joining::register( $signup, $details['org_name'], $details, $name, $password );
			} else {
				$data['error'] = __( 'Choose what to do.', 'dgl-platform' );
			}

			if ( isset( $result ) ) {
				if ( is_wp_error( $result ) ) {
					$data['error'] = $result->get_error_message();
				} else {
					wp_set_current_user( $result );
					wp_set_auth_cookie( $result, false, is_ssl() );
					wp_safe_redirect( Router::url() );
					exit;
				}
			}
		}

		self::screen( 'join', $data, $title );
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
	 * What has already been decided, for looking over and undoing.
	 *
	 * Newest decision first, filtered by outcome. Every row opens the same
	 * review screen, which offers whichever change of mind the state
	 * machine allows from there: take a live item down, reopen a refusal,
	 * restore an archived one.
	 */
	private static function decided( UserContext $user ): void {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = in_array( $status, ItemsTable::decided_statuses(), true ) ? [ $status ] : ItemsTable::decided_statuses();
		$paging = self::page_args( ItemsTable::decided_count( $filter ), 50 );

		$counts = [];
		foreach ( ItemsTable::decided_statuses() as $s ) {
			$counts[ $s ] = ItemsTable::decided_count( [ $s ] );
		}

		self::screen(
			'review-decided',
			[
				'user'    => $user,
				'items'   => array_map( static fn( int $id ): array => self::row( $id, true ), ItemsTable::decided( $filter, 50, $paging['offset'] ) ),
				'counts'  => $counts,
				'active'  => $status,
			] + $paging,
			__( 'Decided', 'dgl-platform' ),
			$user
		);
	}

	/**
	 * The month in numbers, and the CSV files.
	 * /dashboard/review/reports[?month=Y-m], /dashboard/review/reports/csv/<slug|decisions>[?month=Y-m].
	 *
	 * @param string[] $rest Segments after "reports".
	 */
	private static function reports( array $rest, UserContext $user ): void {
		$month = \DGL\Reports\Monthly::month_from( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'csv' === ( $rest[0] ?? '' ) ) {
			$what = sanitize_key( (string) ( $rest[1] ?? '' ) );
			$type = null;

			foreach ( PostTypes::enabled() as $key => $def ) {
				if ( $what === (string) $def['slug'] ) {
					$type = $key;
				}
			}

			if ( \DGL\Reports\Csv::DECISIONS !== $what && null === $type ) {
				self::not_found( $user );
				return;
			}

			$rows = null === $type ? \DGL\Reports\Csv::decisions( $month ) : \DGL\Reports\Csv::listings( $type );
			$body = \DGL\Reports\Csv::write( $rows );

			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . \DGL\Reports\Csv::filename( null === $type ? \DGL\Reports\Csv::DECISIONS : $type, $month ) . '"' );
			header( 'Content-Length: ' . strlen( $body ) );
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a CSV, built by Csv::write().
			exit;
		}

		$exports = [];
		foreach ( PostTypes::enabled() as $key => $def ) {
			$exports[ (string) $def['plural'] ] = Router::url( 'review', 'reports', 'csv', (string) $def['slug'] );
		}

		self::screen(
			'review-reports',
			[
				'user'          => $user,
				'report'        => \DGL\Reports\Monthly::for_month( $month ),
				'months'        => \DGL\Reports\Monthly::months(),
				'month'         => $month,
				'decisions_csv' => add_query_arg( 'month', $month, Router::url( 'review', 'reports', 'csv', \DGL\Reports\Csv::DECISIONS ) ),
				'exports'       => $exports,
			],
			__( 'Reports', 'dgl-platform' ),
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

			// Team notes: for the team, on the item, never in the member's history.
			if ( in_array( $intent, [ 'note_add', 'note_remove' ], true ) ) {
				if ( 'note_add' === $intent ) {
					$text   = isset( $_POST['dgl_note_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dgl_note_text'] ) ) : '';
					$result = \DGL\Moderation\Notes::add( $post_id, $text, $user->user_id );
				} else {
					$note_id = isset( $_POST['dgl_note_id'] ) ? sanitize_key( wp_unslash( $_POST['dgl_note_id'] ) ) : '';
					$result  = \DGL\Moderation\Notes::remove( $post_id, $note_id ) ? true : new \WP_Error( 'dgl_no_note', __( 'That note has already gone.', 'dgl-platform' ) );
				}

				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
				} else {
					wp_safe_redirect( add_query_arg( 'noted', 'note_add' === $intent ? '1' : '0', Router::url( 'review', (string) $post_id ) . '#dgl-team-notes' ) );
					exit;
				}
			}

			// Featuring is a moderator's move on a live item; it never touches review.
			if ( in_array( $intent, [ 'pin', 'unpin' ], true ) ) {
				if ( ! Access::can( $user->user_id, Policy::PIN_ITEM, $post_id ) ) {
					$error = __( 'Only something on the site can be featured, and only by the review team.', 'dgl-platform' );
				} else {
					$days   = isset( $_POST['dgl_pin_days'] ) ? (int) $_POST['dgl_pin_days'] : 0;
					$result = 'pin' === $intent
						? \DGL\Workflow\Pins::pin( $post_id, $days, $user->user_id )
						: \DGL\Workflow\Pins::unpin( $post_id, $user->user_id );

					if ( is_wp_error( $result ) ) {
						$error = $result->get_error_message();
					} else {
						wp_safe_redirect( add_query_arg( 'featured', 'pin' === $intent ? '1' : '0', Router::url( 'review', (string) $post_id ) ) );
						exit;
					}
				}
			}

			$action = match ( $intent ) {
				'approve'   => StateMachine::APPROVE,
				'changes'   => StateMachine::REQUEST_CHANGES,
				'reject'    => StateMachine::REJECT,
				'take_down' => StateMachine::TAKE_DOWN,
				'reopen'    => StateMachine::REOPEN,
				'restore'   => StateMachine::RESTORE,
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
				// A refusal or an archive can be sent back through the queue.
				'can_reopen'    => Access::can( $user->user_id, Policy::REOPEN_ITEM, $post_id ),
				'can_pin'       => ! $is_edit && Access::can( $user->user_id, Policy::PIN_ITEM, $post_id ),
				'pinned_until'  => \DGL\Workflow\Pins::is_pinned( $post_id ) ? (string) wp_date( (string) get_option( 'date_format', 'j F Y' ), \DGL\Workflow\Pins::until( $post_id )->getTimestamp() ) : '',
				'featured'      => isset( $_GET['featured'] ) ? (string) $_GET['featured'] : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'can_restore'   => ! $is_edit && Access::can( $user->user_id, Policy::RESTORE_ITEM, $post_id ),
				// The team's own notes, with who wrote each.
				'notes'         => array_map(
					static function ( array $n ): array {
						$by         = $n['by'] > 0 ? get_userdata( $n['by'] ) : null;
						$n['by_name'] = $by instanceof \WP_User ? (string) $by->display_name : __( 'Unknown', 'dgl-platform' );
						return $n;
					},
					\DGL\Moderation\Notes::all( $post_id )
				),
				'noted'         => isset( $_GET['noted'] ) ? (string) $_GET['noted'] : null, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

			if ( 'cancel' === $intent && ! $is_edit ) {
				// Nothing typed yet: the draft goes, and the list does not
				// gain an Untitled row. Anything saved is kept.
				Wizard::discard_if_empty( $post_id );
				wp_safe_redirect( Router::url( PostTypes::definitions()[ $post->post_type ]['slug'] ) );
				exit;
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
				if ( ! array_key_exists( $key, $values ) || ! ( is_scalar( $raw ) || is_array( $raw ) ) ) {
					continue;
				}

				/*
				 * A picture that arrived with this post is already stored;
				 * the posted hidden value still says the old one. Showing the
				 * stored picture keeps it on screen through a failed step, so
				 * a member asked to describe it is not also asked to upload
				 * it again.
				 */
				$field = FieldRegistry::find( $schema_type, (string) $key );

				if ( null !== $field && \DGL\Schema\Field::IMAGE === $field->type ) {
					continue;
				}

				$values[ $key ] = $raw;
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
				'copied'    => isset( $_GET['copied'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

		$action_error    = '';
		$schedule_errors = [];
		$schedule_input  = null;

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

			// A copy is a new draft with the dates blank; the wizard opens on step 1.
			if ( 'copy' === $intent ) {
				$copy = Wizard::copy( $post_id, $user->user_id );

				if ( is_wp_error( $copy ) ) {
					$action_error = $copy->get_error_message();
				} else {
					wp_safe_redirect( add_query_arg( 'copied', '1', Router::url( 'edit', (string) $copy, '1' ) ) );
					exit;
				}
			}

			/*
			 * Dates and times on a live event apply at once, no review. Both
			 * moves are refused while an edit is open, because the edit
			 * carries the dates too and would overwrite these on approval.
			 */
			if ( in_array( $intent, [ 'extend', 'schedule' ], true ) ) {
				$allowed = 'extend' === $intent
					? Access::can( $user->user_id, Policy::EXTEND_ITEM, $post_id )
					: Schedule::can_change( $user->user_id, $post_id );

				if ( ! $allowed ) {
					$action_error = __( 'You cannot change the dates of this one.', 'dgl-platform' );
				} elseif ( Schedule::is_locked( $post_id ) ) {
					$action_error = __( 'Finish or discard your open edit first. It carries the dates too.', 'dgl-platform' );
				} elseif ( 'extend' === $intent ) {
					$result = Series::is_series( $post_id )
						? Series::extend( $post_id, $user->user_id )
						: \DGL\Workflow\Lifetime::extend( $post_id, $user->user_id );

					if ( is_wp_error( $result ) ) {
						$action_error = $result->get_error_message();
					} else {
						wp_safe_redirect( add_query_arg( 'extended', '1', Router::url( 'item', (string) $post_id ) ) );
						exit;
					}
				} else {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated field by field in the schema.
					$input           = isset( $_POST['dgl'] ) && is_array( $_POST['dgl'] ) ? wp_unslash( $_POST['dgl'] ) : [];
					$schedule_errors = Schedule::save( $post_id, $input, $user->user_id );

					if ( [] === $schedule_errors ) {
						wp_safe_redirect( add_query_arg( 'scheduled', '1', Router::url( 'item', (string) $post_id ) ) );
						exit;
					}

					$schedule_input = $input;
				}
			}

			/*
			 * Cancelling is a schedule fact too: the owning organisation
			 * marks the event, or one date of a series, and it applies at once.
			 */
			if ( in_array( $intent, [ 'cancel', 'reinstate', 'cancel_date', 'reinstate_date' ], true ) ) {
				$date = isset( $_POST['dgl_date'] ) ? sanitize_text_field( wp_unslash( $_POST['dgl_date'] ) ) : '';
				$note = isset( $_POST['dgl_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dgl_note'] ) ) : '';

				if ( ! Access::can( $user->user_id, Policy::CANCEL_ITEM, $post_id ) ) {
					$action_error = __( 'You cannot cancel this one.', 'dgl-platform' );
				} elseif ( Schedule::is_locked( $post_id ) ) {
					$action_error = __( 'Finish or discard your open edit first. It carries the dates too.', 'dgl-platform' );
				} else {
					$result = match ( $intent ) {
						'cancel'         => \DGL\Events\Cancel::cancel( $post_id, $note, $user->user_id ),
						'reinstate'      => \DGL\Events\Cancel::reinstate( $post_id, $user->user_id ),
						'cancel_date'    => \DGL\Events\Cancel::cancel_date( $post_id, $date, $user->user_id ),
						default          => \DGL\Events\Cancel::reinstate_date( $post_id, $date, $user->user_id ),
					};

					if ( is_wp_error( $result ) ) {
						$action_error = $result->get_error_message();
					} else {
						wp_safe_redirect( add_query_arg( 'cancel', $intent, Router::url( 'item', (string) $post_id ) ) );
						exit;
					}
				}
			}

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
				// Anything the member can see and is not an edit can be copied into a new draft.
				'can_copy'   => PostTypes::REVISION !== $post->post_type && Access::can( $user->user_id, Policy::CREATE_ITEM ),
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
				// The schedule card: live events only, the owning organisation only.
				'can_schedule'    => Schedule::can_change( $user->user_id, $post_id ),
				'schedule_locked' => null !== $revision,
				'schedule_fields' => Schedule::fields( (string) $post->post_type ),
				'schedule_errors' => $schedule_errors,
				// After a failed save the form shows what was typed, not what is stored.
				'schedule_values' => $schedule_input ?? Wizard::values( $post_id, (string) $post->post_type ),
				'can_extend'      => Access::can( $user->user_id, Policy::EXTEND_ITEM, $post_id ) && ( Series::can_extend( $post_id ) || \DGL\Workflow\Lifetime::can_extend( $post_id ) ),
				'series_until'    => Series::is_series( $post_id ) ? Series::until_wording( $post_id ) : \DGL\Workflow\Lifetime::until_wording( $post_id ),
				'extend_spell'    => Series::is_series( $post_id )
					/* translators: %d: months. */
					? sprintf( __( '%d months', 'dgl-platform' ), Series::EXTEND_MONTHS )
					: \DGL\Workflow\Lifetime::spell_for( (string) $post->post_type ),
				'scheduled'       => isset( $_GET['scheduled'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'extended'        => isset( $_GET['extended'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				// Cancelling: the whole event, or one date of a series.
				'can_cancel'      => Access::can( $user->user_id, Policy::CANCEL_ITEM, $post_id ),
				'cancelled'       => \DGL\Events\Cancel::is_cancelled( $post_id ),
				'cancelled_note'  => \DGL\Events\Cancel::note( $post_id ),
				'cancelled_at'    => \DGL\Events\Cancel::at( $post_id ),
				'cancelled_dates' => \DGL\Events\Cancel::dates( $post_id ),
				'cancel_choices'  => Series::is_series( $post_id ) ? \DGL\Events\Cancel::choices( $post_id ) : [],
				'cancel_done'     => isset( $_GET['cancel'] ) ? sanitize_key( wp_unslash( $_GET['cancel'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			],
			$post->post_title !== '' ? $post->post_title : __( 'Submission', 'dgl-platform' ),
			$user
		);
	}

	/**
	 * The help guides: one for members, one for the review team, each also
	 * as a PDF. /dashboard/help, /dashboard/help/pdf, /dashboard/help/team,
	 * /dashboard/help/team/pdf.
	 *
	 * @param string[] $segments
	 */
	private static function help( array $segments, UserContext $user ): void {
		$rest  = array_slice( $segments, 1 );
		$which = 'team' === ( $rest[0] ?? '' ) ? \DGL\Help\Content::TEAM : \DGL\Help\Content::MEMBER;
		$pdf   = 'pdf' === end( $rest );

		if ( \DGL\Help\Content::TEAM === $which && ! $user->is_moderator() ) {
			self::screen( 'no-access', [], __( 'No access', 'dgl-platform' ), $user );
			return;
		}

		$guide = \DGL\Help\Content::guide( $which );

		if ( $pdf ) {
			$file = sanitize_file_name( 'dglp-' . \DGL\Help\Content::slug( $which ) . '-guide.pdf' );
			$body = \DGL\Help\Pdf::render(
				$guide,
				sprintf(
					/* translators: 1: site name, 2: a date. */
					__( '%1$s, %2$s', 'dgl-platform' ),
					(string) get_bloginfo( 'name' ),
					(string) wp_date( (string) get_option( 'date_format', 'j F Y' ) )
				)
			);

			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: attachment; filename="' . $file . '"' );
			header( 'Content-Length: ' . strlen( $body ) );
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a PDF, built by Pdf::render().
			exit;
		}

		self::screen(
			'help',
			[
				'user'    => $user,
				'guide'   => $guide,
				'pdf_url' => \DGL\Help\Content::TEAM === $which ? Router::url( 'help', 'team', 'pdf' ) : Router::url( 'help', 'pdf' ),
			],
			(string) $guide['title'],
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

			/*
			 * The directory switch is its own one-button form, open to any
			 * approved member rather than only the owner, so it cannot share
			 * the owner-gated profile save.
			 */
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			if ( isset( $_POST['dgl_directory'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$on     = '1' === sanitize_text_field( wp_unslash( (string) $_POST['dgl_directory'] ) );
				$result = \DGL\Org\Directory::set( $org_id, $on, $user );

				if ( is_wp_error( $result ) ) {
					self::flash( '', $result->get_error_message() );
				} else {
					self::flash( $on ? __( 'You are now shown in the directory.', 'dgl-platform' ) : __( 'You are no longer shown in the directory.', 'dgl-platform' ), '' );
				}

				wp_safe_redirect( Router::url( 'profile', 'organisation' ) );
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
				'sections'   => \DGL\Org\Schema::sections(),
				'directory_on'   => $org_id > 0 && \DGL\Org\Directory::wants_listing( $org_id ),
				'directory_live' => $org_id > 0 && \DGL\Org\Directory::is_listed( $org_id ),
				'can_toggle_directory' => $org_id > 0 && Policy::can_toggle_directory( $user, $org_id ),
				'directory_notice' => 'organisation' === $tab ? self::flash_notice() : '',
				'directory_error'  => 'organisation' === $tab ? self::flash_error() : '',
				'imported_facts' => $org_id > 0 ? \DGL\Org\Directory::imported_facts( $org_id ) : [],
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
				'can_change_role' => Policy::can_change_role( Access::user_context( get_current_user_id() ), $user_id, $org_id ),
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
		$paging = self::page_args( Notifications::count_for_org( $user->org_id ?? 0 ), 40 );

		self::screen(
			'notifications',
			[
				'user' => $user,
				'rows' => Notifications::for_org( $user->org_id ?? 0, 40, $paging['offset'] ),
			] + $paging,
			__( 'Notifications', 'dgl-platform' ),
			$user
		);
	}

	/**
	 * Paging for a list: which page, from `?paged=`, clamped to what exists.
	 *
	 * The base URL is the current request minus `paged` (and minus the
	 * one-off `decided` flash on the queue), so a status filter survives
	 * onto page two and a "Approved" banner does not.
	 *
	 * @return array{page:int, pages:int, total:int, first:int, last:int, offset:int, base:string}
	 */
	private static function page_args( int $total, int $per_page ): array {
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		$page  = min( $pages, max( 1, (int) ( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return [
			'page'   => $page,
			'pages'  => $pages,
			'total'  => $total,
			'first'  => $total > 0 ? ( ( $page - 1 ) * $per_page ) + 1 : 0,
			'last'   => min( $total, $page * $per_page ),
			'offset' => ( $page - 1 ) * $per_page,
			'base'   => remove_query_arg( [ 'paged', 'decided' ] ),
		];
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
			// Only the review team's rows carry this; a member's table never sees the key.
			'notes'    => $for_review ? \DGL\Moderation\Notes::count( $post_id ) : null,
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

		if ( 'role' === $action ) {
			$member_id = isset( $post['dgl_member_id'] ) ? (int) $post['dgl_member_id'] : 0;
			$role      = isset( $post['dgl_role'] ) ? sanitize_key( (string) $post['dgl_role'] ) : '';
			$person    = get_userdata( $member_id );
			$result    = \DGL\Org\Org::set_role( $member_id, $role, $user->user_id );

			self::flash(
				is_wp_error( $result ) ? '' : sprintf(
					UserContext::ORG_OWNER === $role
						/* translators: %s: person's name. */
						? __( '%s is now an owner. They have been told.', 'dgl-platform' )
						/* translators: %s: person's name. */
						: __( '%s is now a contributor. They have been told.', 'dgl-platform' ),
					$person ? $person->display_name : __( 'That person', 'dgl-platform' )
				),
				is_wp_error( $result ) ? $result->get_error_message() : ''
			);

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
	 * The page at the end of the "still running?" link.
	 *
	 * GET shows a confirm with one button; POST does it. The token is
	 * checked both times and spent on success, so the link works once.
	 */
	private static function extend( int $post_id, string $token ): void {
		$title = __( 'Keep it listed', 'dgl-platform' );
		$valid = $post_id > 0 && Reminder::token_is_valid( $post_id, $token );

		if ( ! $valid ) {
			self::screen(
				'extend',
				[
					'state' => 'invalid',
					'error' => __( 'That link has been used already or is not valid. If the event is still running, sign in and open it in your dashboard: there is a button to keep it listed.', 'dgl-platform' ),
					'item_url' => $post_id > 0 ? Router::url( 'item', (string) $post_id ) : Router::url(),
				],
				$title
			);

			return;
		}

		$post = get_post( $post_id );

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			check_admin_referer( 'dgl_extend_' . $post_id );

			$result = Series::is_series( $post_id )
				? Series::extend( $post_id, 0, __( 'Confirmed from the reminder email.', 'dgl-platform' ) )
				: \DGL\Workflow\Lifetime::extend( $post_id, 0, __( 'Confirmed from the reminder email.', 'dgl-platform' ) );

			if ( is_wp_error( $result ) ) {
				self::screen( 'extend', [ 'state' => 'invalid', 'error' => $result->get_error_message(), 'item_url' => Router::url( 'item', (string) $post_id ) ], $title );
				return;
			}

			Reminder::clear_token( $post_id );

			self::screen(
				'extend',
				[
					'state'    => 'done',
					'title'    => (string) get_the_title( $post ),
					'until'    => Series::is_series( $post_id ) ? Series::until_wording( $post_id ) : \DGL\Workflow\Lifetime::until_wording( $post_id ),
					'item_url' => Router::url( 'item', (string) $post_id ),
				],
				$title
			);

			return;
		}

		self::screen(
			'extend',
			[
				'state'     => 'confirm',
				'post_id'   => $post_id,
				'is_series' => Series::is_series( $post_id ),
				'title'     => (string) get_the_title( $post ),
				'until'     => Series::is_series( $post_id ) ? Series::until_wording( $post_id ) : \DGL\Workflow\Lifetime::until_wording( $post_id ),
				'new_until' => Series::is_series( $post_id )
					? (string) wp_date( (string) get_option( 'date_format', 'j F Y' ), Series::now()->setTime( 0, 0, 0 )->modify( '+' . Series::EXTEND_MONTHS . ' months' )->getTimestamp() )
					: \DGL\Workflow\Lifetime::next_until_wording( (string) $post->post_type ),
				'item_url'  => Router::url( 'item', (string) $post_id ),
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

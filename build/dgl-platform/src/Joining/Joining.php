<?php
/**
 * Joining: from an email address to an account, with the checks in between.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Joining;

use DGL\Access\Access;
use DGL\Access\UserContext;
use DGL\Audit\Log;
use DGL\Dashboard\Router;
use DGL\Email\JoinCopy;
use DGL\Email\Mailer;
use DGL\Email\Recipients;
use DGL\Invites\Invites;
use DGL\Meta;
use DGL\Org\Org;
use DGL\Org\Schema as OrgSchema;
use DGL\PostTypes;
use DGL\Workflow\Plan;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Email first. Nothing exists until the address is proven; then the domain
 * decides whether an organisation on the list is offered or a new one is
 * registered for the team to verify.
 */
final class Joining {

	/**
	 * Step 1: an address. Sends the verification link.
	 *
	 * @return array{ok: bool, error: string}
	 */
	public static function start( string $email ): array {
		$email = strtolower( trim( $email ) );

		if ( ! is_email( $email ) ) {
			return [ 'ok' => false, 'error' => __( 'That does not look like an email address.', 'dgl-platform' ) ];
		}

		if ( get_user_by( 'email', $email ) instanceof \WP_User ) {
			return [ 'ok' => false, 'error' => __( 'There is already an account for that address. Sign in instead, or use the forgotten-password link.', 'dgl-platform' ) ];
		}

		$expires = gmdate( 'Y-m-d H:i:s', time() + Rules::LINK_HOURS * 3600 );
		$started = Store::start( $email, Domains::of( $email ), $expires );

		$message = JoinCopy::verify( self::link( $started['token'] ) );
		$sent    = Mailer::send( $message->for_recipients( [ $email ] ) );

		Log::record( 'join_started', 'signup', $started['id'], 0, $email, [], 0 );

		if ( ! $sent ) {
			return [ 'ok' => false, 'error' => __( 'The confirmation email could not be sent. Try again in a minute, or contact the DGLP team.', 'dgl-platform' ) ];
		}

		return [ 'ok' => true, 'error' => '' ];
	}

	/**
	 * Step 2: the link is used. The address is proven and the domain looked up.
	 *
	 * @return array{signup: ?Signup, error: string, outcome: string, orgs: int[]}
	 */
	public static function verify( string $token ): array {
		$signup = Store::find_by_token( $token );

		if ( null === $signup ) {
			return [ 'signup' => null, 'error' => __( 'That link is not valid. Start again and a new one will be sent.', 'dgl-platform' ), 'outcome' => '', 'orgs' => [] ];
		}

		$problem = Rules::link_problem( $signup, Store::now() );

		if ( '' !== $problem ) {
			return [ 'signup' => $signup, 'error' => __( $problem, 'dgl-platform' ), 'outcome' => '', 'orgs' => [] ]; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}

		if ( Signup::UNVERIFIED === $signup->state ) {
			Store::verify( $signup->id );
			Log::record( 'join_verified', 'signup', $signup->id, 0, $signup->email, [], 0 );
			$signup = Store::find( $signup->id );
		}

		$orgs = Domains::can_match( $signup->email ) ? Org::by_domain( $signup->domain ) : [];

		return [ 'signup' => $signup, 'error' => '', 'outcome' => Rules::outcome( $signup->email, $orgs ), 'orgs' => $orgs ];
	}

	/**
	 * Step 3a: join an organisation the domain matched.
	 *
	 * @return int|WP_Error The new user ID.
	 */
	public static function join( Signup $signup, int $org_id, string $name, string $password ) {
		$offered = Domains::can_match( $signup->email ) ? Org::by_domain( $signup->domain ) : [];

		if ( Signup::VERIFIED !== $signup->state || ! in_array( $org_id, $offered, true ) ) {
			return new WP_Error( 'dgl_not_offered', __( 'That organisation is not one your email address matches.', 'dgl-platform' ) );
		}

		$existing = count( Org::members( $org_id ) );
		$role     = Rules::role_for( $existing );
		$status   = Org::is_approved( $org_id ) ? UserContext::ACCOUNT_APPROVED : UserContext::ACCOUNT_PENDING;

		$user_id = self::account( $signup, $org_id, $role, $status, $name, $password );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		Store::update( $signup->id, [ 'state' => Signup::JOINED, 'org_id' => $org_id, 'user_id' => $user_id, 'completed_at' => Store::now() ] );
		Log::record( 'join_completed', 'signup', $signup->id, $org_id, $signup->email, [ 'role' => [ '', $role ] ], $user_id );

		self::tell_the_owners( $org_id, $user_id, $role );

		return $user_id;
	}

	/**
	 * Step 3b: register an organisation that is not on the list.
	 *
	 * The organisation is created pending with this address's domain recorded
	 * (unless it is a public provider), the person is its owner, and both wait
	 * for the team. Meanwhile they can draft.
	 *
	 * @param array<string, string> $details Website, contact email, phone, number, description.
	 * @return int|WP_Error The new user ID.
	 */
	public static function register( Signup $signup, string $org_name, array $details, string $name, string $password ) {
		if ( Signup::VERIFIED !== $signup->state ) {
			return new WP_Error( 'dgl_not_verified', __( 'Confirm your email address first.', 'dgl-platform' ) );
		}

		$problem = Rules::org_name_problem( $org_name, self::all_org_names() );

		if ( '' !== $problem ) {
			return new WP_Error( 'dgl_org_name', __( $problem, 'dgl-platform' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}

		$org_name = trim( preg_replace( '/\s+/', ' ', $org_name ) ?? '' );

		$org_id = wp_insert_post(
			[
				'post_type'   => PostTypes::ORG,
				'post_title'  => $org_name,
				'post_status' => 'publish',
			],
			true
		);

		if ( is_wp_error( $org_id ) ) {
			return $org_id;
		}

		$org_id = (int) $org_id;
		update_post_meta( $org_id, Meta::ORG_STATUS, Meta::ORG_PENDING );

		if ( Domains::can_match( $signup->email ) ) {
			Org::set_domains( $org_id, [ $signup->domain ] );
		}

		foreach ( OrgSchema::fields() as $field ) {
			$value = trim( (string) ( $details[ $field->key ] ?? '' ) );

			if ( 'org_name' === $field->key || '' === $value ) {
				continue;
			}

			update_post_meta( $org_id, $field->meta_key(), \DGL\Schema\Store::sanitise( $field, $value ) );
		}

		$user_id = self::account( $signup, $org_id, UserContext::ORG_OWNER, UserContext::ACCOUNT_PENDING, $name, $password );

		if ( is_wp_error( $user_id ) ) {
			wp_delete_post( $org_id, true );
			return $user_id;
		}

		Store::update(
			$signup->id,
			[
				'state'           => Signup::AWAITING,
				'org_id'          => $org_id,
				'user_id'         => $user_id,
				'new_org_name'    => $org_name,
				'new_org_details' => (string) wp_json_encode( $details ),
				'completed_at'    => Store::now(),
			]
		);

		Log::record( 'join_registered_org', 'signup', $signup->id, $org_id, $org_name, [], $user_id );
		self::tell_the_team( $signup->id, $org_id, $user_id );

		return $user_id;
	}

	/**
	 * The team verify a new organisation: it and its first person are approved.
	 *
	 * @return true|WP_Error
	 */
	public static function approve( int $signup_id, int $actor_id ) {
		$signup = Store::find( $signup_id );

		if ( null === $signup || Signup::AWAITING !== $signup->state ) {
			return new WP_Error( 'dgl_nothing_waiting', __( 'That registration is not waiting on a decision.', 'dgl-platform' ) );
		}

		if ( ! Access::user_context( $actor_id )->is_moderator() ) {
			return new WP_Error( 'dgl_not_allowed', __( 'Only the review team can decide this.', 'dgl-platform' ) );
		}

		update_post_meta( $signup->org_id, Meta::ORG_STATUS, Meta::ORG_APPROVED );
		update_user_meta( $signup->user_id, Meta::USER_ACCOUNT_STATUS, UserContext::ACCOUNT_APPROVED );
		Store::update( $signup_id, [ 'state' => Signup::APPROVED, 'decided_at' => Store::now(), 'decided_by' => $actor_id ] );

		Log::record( 'org_status_changed', 'org', $signup->org_id, $signup->org_id, __( 'Verified from a registration.', 'dgl-platform' ), [ 'status' => [ Meta::ORG_PENDING, Meta::ORG_APPROVED ] ], $actor_id );
		Log::record( 'join_approved', 'signup', $signup_id, $signup->org_id, $signup->email, [], $actor_id );

		$message = JoinCopy::approved( get_the_title( $signup->org_id ), Router::url() );
		Mailer::send( $message->for_recipients( [ $signup->email ] ) );

		return true;
	}

	/**
	 * The team refuse: the organisation goes, the account is closed, the
	 * person is told why. Trying again means talking to the team.
	 *
	 * @return true|WP_Error
	 */
	public static function refuse( int $signup_id, int $actor_id, string $reason ) {
		$signup = Store::find( $signup_id );

		if ( null === $signup || Signup::AWAITING !== $signup->state ) {
			return new WP_Error( 'dgl_nothing_waiting', __( 'That registration is not waiting on a decision.', 'dgl-platform' ) );
		}

		if ( ! Access::user_context( $actor_id )->is_moderator() ) {
			return new WP_Error( 'dgl_not_allowed', __( 'Only the review team can decide this.', 'dgl-platform' ) );
		}

		$reason = trim( $reason );

		if ( '' === $reason ) {
			return new WP_Error( 'dgl_note_required', __( 'Say why. The person reads this, and a refusal with no reason just produces another registration.', 'dgl-platform' ) );
		}

		$org_name = get_the_title( $signup->org_id );

		delete_user_meta( $signup->user_id, Meta::USER_ORG );
		delete_user_meta( $signup->user_id, Meta::USER_ORG_ROLE );
		update_user_meta( $signup->user_id, Meta::USER_ACCOUNT_STATUS, UserContext::ACCOUNT_CLOSED );
		\WP_Session_Tokens::get_instance( $signup->user_id )->destroy_all();
		wp_trash_post( $signup->org_id );

		Store::update( $signup_id, [ 'state' => Signup::REFUSED, 'reason' => $reason, 'decided_at' => Store::now(), 'decided_by' => $actor_id ] );
		Log::record( 'join_refused', 'signup', $signup_id, $signup->org_id, $reason, [], $actor_id );

		$message = JoinCopy::refused( $org_name, $reason );
		Mailer::send( $message->for_recipients( [ $signup->email ] ) );

		return true;
	}

	public static function link( string $token ): string {
		return Router::url( 'join', $token );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * The account, linked and stated. One helper so the invitation flow and
	 * this one make the same kind of user.
	 *
	 * @return int|WP_Error
	 */
	private static function account( Signup $signup, int $org_id, string $role, string $status, string $name, string $password ) {
		if ( get_user_by( 'email', $signup->email ) instanceof \WP_User ) {
			return new WP_Error( 'dgl_exists', __( 'There is already an account for that address. Sign in instead.', 'dgl-platform' ) );
		}

		$user_id = Invites::create_account( $signup->email, $name, $password );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, Meta::USER_ORG, $org_id );
		update_user_meta( $user_id, Meta::USER_ORG_ROLE, $role );
		update_user_meta( $user_id, Meta::USER_ACCOUNT_STATUS, $status );

		return $user_id;
	}

	private static function tell_the_owners( int $org_id, int $user_id, string $role ): void {
		$to = [];

		foreach ( Org::members( $org_id ) as $member_id ) {
			if ( $member_id !== $user_id && UserContext::ORG_OWNER === Org::role_for_user( $member_id ) ) {
				$owner = get_userdata( $member_id );

				if ( $owner && '' !== (string) $owner->user_email ) {
					$to[] = (string) $owner->user_email;
				}
			}
		}

		if ( [] === $to ) {
			return;
		}

		$person  = get_userdata( $user_id );
		$message = JoinCopy::joined(
			$person ? (string) $person->display_name : __( 'Somebody', 'dgl-platform' ),
			$person ? (string) $person->user_email : '',
			get_the_title( $org_id ),
			Invites::role_label( $role ),
			Router::url( 'profile', 'members' )
		);

		Mailer::send( $message->for_recipients( $to ) );
	}

	private static function tell_the_team( int $signup_id, int $org_id, int $user_id ): void {
		$to = Recipients::for_audience( Plan::NOTIFY_MODERATORS, 0, 0 );

		if ( [] === $to ) {
			return;
		}

		$person  = get_userdata( $user_id );
		$message = JoinCopy::awaiting(
			get_the_title( $org_id ),
			$person ? (string) $person->display_name : __( 'Somebody', 'dgl-platform' ),
			$person ? (string) $person->user_email : '',
			Router::url( 'review', 'join', (string) $signup_id )
		);

		Mailer::send( $message->for_recipients( $to ) );
	}

	/**
	 * @return string[]
	 */
	private static function all_org_names(): array {
		$posts = get_posts(
			[
				'post_type'      => PostTypes::ORG,
				'post_status'    => 'publish',
				'posts_per_page' => 2000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		return array_map( static fn( $id ): string => (string) get_the_title( (int) $id ), $posts );
	}
}

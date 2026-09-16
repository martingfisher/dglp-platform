<?php
/**
 * Sending, accepting and withdrawing invitations.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Invites;

use DateTimeImmutable;
use DateTimeZone;
use DGL\Access\Access;
use DGL\Access\UserContext;
use DGL\Audit\Log;
use DGL\Dashboard\Router;
use DGL\Email\InviteCopy;
use DGL\Email\Mailer;
use DGL\Email\Routing;
use DGL\Meta;
use DGL\Plugin;
use DGL\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * The WordPress side of invitations.
 *
 * Every decision here is {@see Rules}'. This fetches what the rules need,
 * asks, and writes back what it is told. Nothing in this file decides who may
 * do what.
 */
final class Invites {

	public static function init(): void {
		// Dead invitations are swept alongside expired content rather than on a
		// schedule of their own. One cron job that must run is easier to keep
		// honest than two.
		add_action( Plugin::EXPIRY_HOOK, [ self::class, 'purge' ] );
	}

	public static function purge(): void {
		Store::purge_stale();
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------ */

	/**
	 * Invite an address into an organisation.
	 *
	 * @return array{ok:bool, reason:string, error:string, invite:?Invite}
	 */
	public static function send( UserContext $actor, int $org_id, string $email, string $org_role ): array {
		$email = Rules::normalise_email( $email );

		$reason = Rules::can_send(
			$actor,
			$org_id,
			$org_role,
			$email,
			'' !== $email && (bool) is_email( $email ),
			self::org_of_account( $email ),
			Store::has_open_for( $email, $org_id ),
			count( Store::open_for_org( $org_id ) )
		);

		if ( Rules::SEND_OK !== $reason ) {
			return [
				'ok'     => false,
				'reason' => $reason,
				'error'  => Rules::send_error( $reason ),
				'invite' => null,
			];
		}

		$now   = self::now();
		$token = Store::new_token();

		$invite = new Invite(
			id: 0,
			email: $email,
			org_id: $org_id,
			org_role: $org_role,
			invited_by: $actor->user_id,
			token_hash: Store::hash( $token ),
			created_at: $now->format( 'Y-m-d H:i:s' ),
			expires_at: Rules::expires_at( $now ),
		);

		$id = Store::insert( $invite );

		if ( $id <= 0 ) {
			return [
				'ok'     => false,
				'reason' => 'storage',
				'error'  => __( 'The invitation could not be saved. Try again.', 'dgl-platform' ),
				'invite' => null,
			];
		}

		$invite = Store::find( $id ) ?? $invite;

		/*
		 * The audit entry records the address, never the token. An audit trail
		 * that contains working credentials is not an audit trail, it is a
		 * second copy of the thing it is supposed to be watching.
		 */
		Log::record(
			'invite_sent',
			'invite',
			$id,
			$org_id,
			sprintf( '%s as %s', $email, $org_role ),
			[],
			$actor->user_id
		);

		self::deliver( $invite, $token );

		return [
			'ok'     => true,
			'reason' => Rules::SEND_OK,
			'error'  => '',
			'invite' => $invite,
		];
	}

	/**
	 * Put the invitation in the post.
	 *
	 * Sending is checked here rather than assumed, because an invitation that
	 * was stored and never sent looks identical on screen to one that arrived.
	 */
	private static function deliver( Invite $invite, string $token ): bool {
		if ( ! Routing::is_enabled() ) {
			return false;
		}

		$message = InviteCopy::invited(
			org_name: (string) get_the_title( $invite->org_id ),
			inviter: self::person( $invite->invited_by ),
			role_label: self::role_label( $invite->org_role ),
			accept_url: self::accept_url( $token ),
			expires_on: self::readable_date( $invite->expires_at ),
			site_name: (string) get_bloginfo( 'name' ),
			can_post: self::can_post_sentence()
		);

		return Mailer::send( $message->for_recipients( [ $invite->email ] ) );
	}

	/**
	 * Mint a new token for an existing invitation and send it again.
	 *
	 * The old token stops working, because the row only holds one hash. That is
	 * deliberate: "resend" should not leave two live links to the same account.
	 */
	public static function resend( int $id, UserContext $actor ): bool {
		$invite = Store::find( $id );

		if ( null === $invite || ! Rules::may_revoke( $actor, $invite ) ) {
			return false;
		}

		if ( ! Rules::is_open( $invite, self::now() ) ) {
			return false;
		}

		$token = Store::new_token();

		global $wpdb;

		$updated = $wpdb->update(
			Store::name(),
			[
				'token_hash' => Store::hash( $token ),
				'expires_at' => Rules::expires_at( self::now() ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			return false;
		}

		$fresh = Store::find( $id );

		if ( null === $fresh ) {
			return false;
		}

		Log::record( 'invite_resent', 'invite', $id, $invite->org_id, $invite->email, [], $actor->user_id );

		return self::deliver( $fresh, $token );
	}

	/* ---------------------------------------------------------------------
	 * Withdrawing
	 * ------------------------------------------------------------------ */

	public static function revoke( int $id, UserContext $actor ): bool {
		$invite = Store::find( $id );

		if ( null === $invite || ! Rules::may_revoke( $actor, $invite ) ) {
			return false;
		}

		if ( ! Store::revoke( $id, Store::now() ) ) {
			return false;
		}

		Log::record( 'invite_revoked', 'invite', $id, $invite->org_id, $invite->email, [], $actor->user_id );

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Accepting
	 * ------------------------------------------------------------------ */

	/**
	 * Take up an invitation.
	 *
	 * @param string $token    From the link.
	 * @param string $name     Display name the invitee chose. May be empty.
	 * @param string $password The password they chose. Ignored when linking an
	 *                         account that already exists.
	 * @return array{ok:bool, error:string, user_id:int, outcome:string}
	 */
	public static function accept( string $token, string $name = '', string $password = '' ): array {
		$invite = Store::find_by_token( $token );

		if ( null === $invite ) {
			return self::accept_failure( __( 'That invitation link is not valid. Ask whoever invited you to send a new one.', 'dgl-platform' ), 'unknown' );
		}

		$now      = self::now();
		$existing = get_user_by( 'email', $invite->email );
		$user_id  = $existing instanceof \WP_User ? (int) $existing->ID : null;

		$outcome = Rules::accept_outcome(
			$invite,
			$now,
			$user_id,
			null === $user_id ? null : (int) get_user_meta( $user_id, Meta::USER_ORG, true )
		);

		if ( Rules::ACCEPT_ALREADY_MEMBER === $outcome ) {
			// Nothing to do, and nothing wrong. Claim it so the invitation stops
			// showing as outstanding, and send them on their way.
			Store::claim( $invite->id, (int) $user_id, Store::now() );

			return [ 'ok' => true, 'error' => '', 'user_id' => (int) $user_id, 'outcome' => $outcome ];
		}

		if ( Rules::ACCEPT_CREATE !== $outcome && Rules::ACCEPT_LINK !== $outcome ) {
			return self::accept_failure(
				Rules::accept_error( $outcome, Rules::state( $invite, $now ) ),
				$outcome
			);
		}

		/*
		 * Claimed before the account is touched. Two requests with the same
		 * token, which is what a double click on a link in an email produces,
		 * must not produce two accounts. The loser of that race gets false here
		 * and stops.
		 */
		$claim_for = $user_id ?? 0;

		if ( ! Store::claim( $invite->id, $claim_for, Store::now() ) ) {
			return self::accept_failure( Rules::accept_error( Rules::ACCEPT_CLOSED, Rules::ACCEPTED ), Rules::ACCEPT_CLOSED );
		}

		if ( Rules::ACCEPT_CREATE === $outcome ) {
			$user_id = self::create_account( $invite->email, $name, $password );

			if ( is_wp_error( $user_id ) ) {
				// Put the invitation back so the failure is recoverable rather
				// than burning somebody's only link.
				self::unclaim( $invite->id );

				return self::accept_failure( $user_id->get_error_message(), 'create_failed' );
			}

			// The row was claimed before the ID existed. Record who it was.
			self::set_accepted_by( $invite->id, $user_id );
		}

		self::attach( (int) $user_id, $invite );

		Log::record( 'invite_accepted', 'invite', $invite->id, $invite->org_id, $invite->email, [], (int) $user_id );

		self::tell_the_inviter( $invite, (int) $user_id );

		return [ 'ok' => true, 'error' => '', 'user_id' => (int) $user_id, 'outcome' => $outcome ];
	}

	/**
	 * @return array{ok:bool, error:string, user_id:int, outcome:string}
	 */
	private static function accept_failure( string $error, string $outcome ): array {
		return [ 'ok' => false, 'error' => $error, 'user_id' => 0, 'outcome' => $outcome ];
	}

	/**
	 * Create the member's account.
	 *
	 * @return int|\WP_Error
	 */
	private static function create_account( string $email, string $name, string $password ) {
		$password = '' !== $password ? $password : wp_generate_password( 24, true, true );

		$user_id = wp_insert_user(
			[
				'user_login'   => self::unique_login( $email ),
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => '' !== trim( $name ) ? trim( $name ) : self::name_from_email( $email ),
				'role'         => Roles::MEMBER,
			]
		);

		return is_wp_error( $user_id ) ? $user_id : (int) $user_id;
	}

	/**
	 * A login that is not already taken.
	 *
	 * The address is the login where it can be, because that is what somebody
	 * will type. Where it is taken, a suffix is added rather than failing: the
	 * only case that reaches here is an address whose account was deleted and
	 * whose login lingered, and refusing would leave the invitee stuck with
	 * nothing they can do about it.
	 */
	private static function unique_login( string $email ): string {
		$base = sanitize_user( $email, true );
		$base = '' !== $base ? $base : 'member';

		if ( ! username_exists( $base ) ) {
			return $base;
		}

		for ( $n = 2; $n < 100; $n++ ) {
			$try = $base . '-' . $n;

			if ( ! username_exists( $try ) ) {
				return $try;
			}
		}

		return $base . '-' . wp_generate_password( 6, false );
	}

	private static function name_from_email( string $email ): string {
		$local = (string) strstr( $email, '@', true );
		$local = str_replace( [ '.', '_', '-' ], ' ', $local );

		return '' !== trim( $local ) ? ucwords( trim( $local ) ) : $email;
	}

	/**
	 * Put the account in the organisation.
	 *
	 * The account status is set to approved. The invitation is the approval:
	 * somebody DGLP has already verified vouched for this person, and making
	 * them wait again for a second approval nobody is watching for is how a new
	 * member's first impression becomes a dead end.
	 */
	private static function attach( int $user_id, Invite $invite ): void {
		update_user_meta( $user_id, Meta::USER_ORG, $invite->org_id );
		update_user_meta( $user_id, Meta::USER_ORG_ROLE, $invite->org_role );
		update_user_meta( $user_id, Meta::USER_ACCOUNT_STATUS, UserContext::ACCOUNT_APPROVED );

		$user = get_userdata( $user_id );

		// An existing account being linked may have no DGLP role at all. Staff
		// accounts keep what they have: nothing here should quietly demote a
		// moderator who accepted an invitation.
		if ( $user instanceof \WP_User
			&& ! in_array( Roles::MEMBER, (array) $user->roles, true )
			&& ! in_array( Roles::MODERATOR, (array) $user->roles, true )
			&& ! in_array( UserContext::ROLE_ADMIN, (array) $user->roles, true )
		) {
			$user->add_role( Roles::MEMBER );
		}

		Access::flush_cache( $user_id );
	}

	private static function set_accepted_by( int $id, int $user_id ): void {
		global $wpdb;

		$wpdb->update( Store::name(), [ 'accepted_by' => $user_id ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
	}

	private static function unclaim( int $id ): void {
		global $wpdb;

		$wpdb->update( Store::name(), [ 'accepted_at' => null, 'accepted_by' => 0 ], [ 'id' => $id ], [ '%s', '%d' ], [ '%d' ] );
	}

	/**
	 * Let whoever sent it know it landed.
	 */
	private static function tell_the_inviter( Invite $invite, int $user_id ): void {
		if ( ! Routing::is_enabled() || $invite->invited_by <= 0 ) {
			return;
		}

		$inviter = get_userdata( $invite->invited_by );

		if ( ! $inviter instanceof \WP_User || '' === (string) $inviter->user_email ) {
			return;
		}

		$who = self::person( $user_id );

		$message = InviteCopy::accepted(
			'' !== $who ? $who : $invite->email,
			(string) get_the_title( $invite->org_id ),
			self::role_label( $invite->org_role ),
			Router::url( 'profile', 'members' )
		);

		Mailer::send( $message->for_recipients( [ (string) $inviter->user_email ] ) );
	}

	/* ---------------------------------------------------------------------
	 * Odds and ends
	 * ------------------------------------------------------------------ */

	/**
	 * "events, news and training": the enabled types, as a list for a sentence.
	 *
	 * From the enabled set, never the full one. The invitation email used to
	 * name all five types, and two of them were switched off for this release.
	 */
	public static function can_post_sentence(): string {
		$labels = array_map(
			static fn( array $def ): string => strtolower( (string) $def['plural'] ),
			array_values( \DGL\PostTypes::enabled() )
		);

		if ( [] === $labels ) {
			return '';
		}

		if ( 1 === count( $labels ) ) {
			return $labels[0];
		}

		$last = array_pop( $labels );

		/* translators: 1: comma-separated list, 2: final item. */
		return sprintf( __( '%1$s and %2$s', 'dgl-platform' ), implode( ', ', $labels ), $last );
	}

	public static function accept_url( string $token ): string {
		return Router::url( 'invite', $token );
	}

	public static function role_label( string $org_role ): string {
		return match ( $org_role ) {
			UserContext::ORG_OWNER => __( 'Manage the organisation, and invite colleagues', 'dgl-platform' ),
			default                => __( 'Post on the organisation\'s behalf', 'dgl-platform' ),
		};
	}

	/**
	 * The short version, for a list.
	 */
	public static function role_name( string $org_role ): string {
		return match ( $org_role ) {
			UserContext::ORG_OWNER       => __( 'Owner', 'dgl-platform' ),
			UserContext::ORG_CONTRIBUTOR => __( 'Contributor', 'dgl-platform' ),
			default                      => $org_role,
		};
	}

	/**
	 * Somebody's name, or an empty string if there is nobody.
	 */
	public static function person( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof \WP_User ) {
			return '';
		}

		$name = trim( (string) $user->display_name );

		return '' !== $name ? $name : (string) $user->user_login;
	}

	/**
	 * The organisation an address already belongs to.
	 *
	 * Null means there is no account, or there is one and it is unattached.
	 * Both are cases where an invitation is fine.
	 */
	private static function org_of_account( string $email ): ?int {
		$user = get_user_by( 'email', $email );

		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		$org = (int) get_user_meta( (int) $user->ID, Meta::USER_ORG, true );

		return $org > 0 ? $org : null;
	}

	/**
	 * A stored UTC timestamp as the site would write the date.
	 */
	public static function readable_date( string $utc ): string {
		$time = strtotime( $utc . ' UTC' );

		if ( false === $time ) {
			return '';
		}

		return (string) wp_date( (string) get_option( 'date_format', 'j F Y' ), $time );
	}

	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
}

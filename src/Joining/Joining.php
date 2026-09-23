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
use DGL\Index\ItemsTable;
use DGL\Invites\Invites;
use DGL\Meta;
use DGL\Org\Duplicates;
use DGL\Org\Org;
use DGL\Org\Schema as OrgSchema;
use DGL\PostTypes;
use DGL\Workflow\Plan;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Email first. Nothing exists until the address is proven. Then the domain
 * decides whether an organisation on the list is offered outright; failing
 * that the person picks theirs from the list and the team check they are
 * part of it, or registers one that is not on the list, which is checked
 * against the list first so the same organisation is never recorded twice.
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
			return [ 'ok' => false, 'code' => 'invalid', 'error' => __( 'That does not look like an email address.', 'dgl-platform' ) ];
		}

		// Somebody who already has an account is sent to sign in, not round
		// the joining loop again. The screen turns this code into the way through.
		if ( get_user_by( 'email', $email ) instanceof \WP_User ) {
			return [ 'ok' => false, 'code' => 'exists', 'error' => __( 'There is already an account for that address. Sign in instead, or use the forgotten-password link.', 'dgl-platform' ) ];
		}

		$expires = gmdate( 'Y-m-d H:i:s', time() + Rules::LINK_HOURS * 3600 );
		$started = Store::start( $email, Domains::of( $email ), $expires );

		$message = JoinCopy::verify( self::link( $started['token'] ) );
		$sent    = Mailer::send( $message->for_recipients( [ $email ] ) );

		Log::record( 'join_started', 'signup', $started['id'], 0, $email, [], 0 );

		if ( ! $sent ) {
			return [ 'ok' => false, 'code' => 'unsent', 'error' => __( 'The confirmation email could not be sent. Try again in a minute, or contact the DGLP team.', 'dgl-platform' ) ];
		}

		return [ 'ok' => true, 'code' => '', 'error' => '' ];
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

		Store::update( $signup->id, [ 'state' => Signup::JOINED, 'kind' => Signup::KIND_JOIN, 'org_id' => $org_id, 'user_id' => $user_id, 'completed_at' => Store::now() ] );
		Log::record( 'join_completed', 'signup', $signup->id, $org_id, $signup->email, [ 'role' => [ '', $role ] ], $user_id );

		self::tell_the_owners( $org_id, $user_id, $role );

		return $user_id;
	}

	/**
	 * Step 3b: claim a place at an organisation on the list that the domain
	 * did not match.
	 *
	 * The account is made pending, as a contributor, linked to the
	 * organisation, and the team are asked to check. Approval decides the
	 * role: owner if nobody else is in the organisation by then. Meanwhile
	 * the person can draft.
	 *
	 * @return int|WP_Error The new user ID.
	 */
	public static function claim( Signup $signup, int $org_id, string $note, string $name, string $password ) {
		if ( Signup::VERIFIED !== $signup->state ) {
			return new WP_Error( 'dgl_not_verified', __( 'Confirm your email address first.', 'dgl-platform' ) );
		}

		if ( ! isset( Org::pickable()[ $org_id ] ) ) {
			return new WP_Error( 'dgl_not_pickable', __( 'Choose your organisation from the list, or register it if it is not there.', 'dgl-platform' ) );
		}

		// When the domain does match, the domain is the check. No claim needed.
		if ( Domains::can_match( $signup->email ) && in_array( $org_id, Org::by_domain( $signup->domain ), true ) ) {
			return self::join( $signup, $org_id, $name, $password );
		}

		$note    = trim( mb_substr( $note, 0, Rules::NOTE_MAX ) );
		$user_id = self::account( $signup, $org_id, UserContext::ORG_CONTRIBUTOR, UserContext::ACCOUNT_PENDING, $name, $password );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		Store::update(
			$signup->id,
			[
				'state'           => Signup::AWAITING,
				'kind'            => Signup::KIND_CLAIM,
				'org_id'          => $org_id,
				'user_id'         => $user_id,
				'new_org_details' => (string) wp_json_encode( [ 'note' => $note ] ),
				'completed_at'    => Store::now(),
			]
		);

		Log::record( 'join_claimed', 'signup', $signup->id, $org_id, $signup->email, [], $user_id );
		self::tell_the_team_claim( $signup->id, $org_id, $user_id, $note );

		return $user_id;
	}

	/**
	 * Step 3c: register an organisation that is not on the list.
	 *
	 * What was typed is checked against the list first. A hard match (same
	 * name once suffixes are stripped, same website or email domain, same
	 * charity number, same postcode with a similar name) refuses with the
	 * match in the error's data, so the screen can offer it instead. A soft
	 * match (a similar name, the same postcode) refuses once, until the
	 * person has seen the list and said none of them is theirs.
	 *
	 * Then the organisation is created pending with this address's domain
	 * recorded (unless it is a public provider), the person is its owner,
	 * and both wait for the team. Meanwhile they can draft.
	 *
	 * @param array<string, string> $details Website, contact email, phone, number, postcode, description.
	 * @param bool $confirmed The person has seen the soft matches and said none is theirs.
	 * @return int|WP_Error The new user ID.
	 */
	public static function register( Signup $signup, string $org_name, array $details, string $name, string $password, bool $confirmed = false ) {
		if ( Signup::VERIFIED !== $signup->state ) {
			return new WP_Error( 'dgl_not_verified', __( 'Confirm your email address first.', 'dgl-platform' ) );
		}

		// The length check only. A name already on the list is a hard match
		// below, which offers the organisation rather than just refusing.
		$problem = Rules::org_name_problem( $org_name, [] );

		if ( '' !== $problem ) {
			return new WP_Error( 'dgl_org_name', __( $problem, 'dgl-platform' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}

		$org_name = trim( preg_replace( '/\s+/', ' ', $org_name ) ?? '' );
		$matches  = self::matches_for( $signup, $org_name, $details );
		$refusal  = self::registration_problem( $matches, $confirmed );

		if ( null !== $refusal ) {
			return $refusal;
		}

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

		$record = array_filter( $details, static fn( $v ): bool => is_scalar( $v ) && '' !== trim( (string) $v ) );
		$ids    = static fn( array $list ): array => array_map( static fn( array $m ): int => $m['id'], $list );
		$soft   = $ids( Duplicates::soft( $matches ) );
		$binned = $ids( array_filter( $matches, static fn( array $m ): bool => $m['trashed'] ) );

		if ( [] !== $soft ) {
			$record['confirmed_against'] = $soft;
		}

		if ( [] !== $binned ) {
			$record['trashed_matches'] = array_values( $binned );
		}

		Store::update(
			$signup->id,
			[
				'state'           => Signup::AWAITING,
				'kind'            => Signup::KIND_REGISTER,
				'org_id'          => $org_id,
				'user_id'         => $user_id,
				'new_org_name'    => $org_name,
				'new_org_details' => (string) wp_json_encode( $record ),
				'completed_at'    => Store::now(),
			]
		);

		Log::record( 'join_registered_org', 'signup', $signup->id, $org_id, $org_name, [], $user_id );
		self::tell_the_team( $signup->id, $org_id, $user_id );

		return $user_id;
	}

	/**
	 * What on the list a described organisation could be.
	 *
	 * @param array<string, mixed> $details
	 * @return array<int, array{id: int, name: string, status: string, trashed: bool, strength: string, reasons: string[]}>
	 */
	public static function matches_for( Signup $signup, string $org_name, array $details, int $exclude_id = 0 ): array {
		return Duplicates::find(
			[
				'name'         => $org_name,
				'website'      => (string) ( $details['org_website'] ?? '' ),
				'email_domain' => Domains::can_match( $signup->email ) ? $signup->domain : '',
				'number'       => (string) ( $details['org_number'] ?? '' ),
				'postcode'     => (string) ( $details['org_postcode'] ?? '' ),
			],
			Org::duplicate_candidates( $exclude_id )
		);
	}

	/**
	 * The matches a waiting registration has on the list, for the team,
	 * the bin included. Empty for a claim.
	 *
	 * @return array<int, array{id: int, name: string, status: string, trashed: bool, strength: string, reasons: string[]}>
	 */
	public static function likely_matches( Signup $signup ): array {
		if ( ! $signup->is_registration() ) {
			return [];
		}

		return self::matches_for( $signup, $signup->new_org_name, $signup->new_org_details, $signup->org_id );
	}

	/**
	 * Whether the matches stop a registration: a hard match always, a soft
	 * match until the person has said none of them is theirs. The matches
	 * ride in the error's data for the screen.
	 *
	 * @param array<int, array{id: int, name: string, status: string, trashed: bool, strength: string, reasons: string[]}> $matches
	 */
	public static function registration_problem( array $matches, bool $confirmed ): ?WP_Error {
		$hard = Duplicates::hard( $matches );

		if ( [] !== $hard ) {
			return new WP_Error(
				'dgl_duplicate_hard',
				sprintf(
					/* translators: %s: organisation. */
					__( 'That looks like %s, which is already on the list. Join it instead and the DGLP team will check you are part of it.', 'dgl-platform' ),
					$hard[0]['name']
				),
				[ 'matches' => $hard ]
			);
		}

		$soft = Duplicates::soft( $matches );

		if ( [] !== $soft && ! $confirmed ) {
			return new WP_Error(
				'dgl_duplicate_soft',
				__( 'Some organisations on the list look like the one you typed. Check them before going on.', 'dgl-platform' ),
				[ 'matches' => $soft ]
			);
		}

		return null;
	}

	/**
	 * The team approve: a registered organisation and its first person, or
	 * a person's claim on an organisation already listed.
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

		if ( $signup->is_claim() ) {
			return self::approve_claim( $signup, $actor_id );
		}

		update_post_meta( $signup->org_id, Meta::ORG_STATUS, Meta::ORG_APPROVED );
		update_user_meta( $signup->user_id, Meta::USER_ACCOUNT_STATUS, UserContext::ACCOUNT_APPROVED );
		Access::forget( $signup->user_id );
		Store::update( $signup_id, [ 'state' => Signup::APPROVED, 'decided_at' => Store::now(), 'decided_by' => $actor_id ] );

		Log::record( 'org_status_changed', 'org', $signup->org_id, $signup->org_id, __( 'Verified from a registration.', 'dgl-platform' ), [ 'status' => [ Meta::ORG_PENDING, Meta::ORG_APPROVED ] ], $actor_id );
		Log::record( 'join_approved', 'signup', $signup_id, $signup->org_id, $signup->email, [], $actor_id );

		$message = JoinCopy::approved( get_the_title( $signup->org_id ), Router::url() );
		Mailer::send( $message->for_recipients( [ $signup->email ] ) );

		return true;
	}

	/**
	 * The team refuse. A registration: the organisation goes and the account
	 * is closed. A claim: the account is closed and the organisation is
	 * untouched. Either way the person is told why, and trying again means
	 * talking to the team.
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
		Access::forget( $signup->user_id );
		\WP_Session_Tokens::get_instance( $signup->user_id )->destroy_all();

		Store::update( $signup_id, [ 'state' => Signup::REFUSED, 'reason' => $reason, 'decided_at' => Store::now(), 'decided_by' => $actor_id ] );

		if ( $signup->is_claim() ) {
			Log::record( 'join_claim_refused', 'signup', $signup_id, $signup->org_id, $reason, [], $actor_id );
			$message = JoinCopy::claim_refused( $org_name, $reason );
		} else {
			wp_trash_post( $signup->org_id );
			Log::record( 'join_refused', 'signup', $signup_id, $signup->org_id, $reason, [], $actor_id );
			$message = JoinCopy::refused( $org_name, $reason );
		}

		Mailer::send( $message->for_recipients( [ $signup->email ] ) );

		return true;
	}

	/**
	 * The team attach the person to an organisation already on the list
	 * instead: the one they registered was a duplicate, or the one they
	 * claimed was the wrong one.
	 *
	 * The person is moved first, so a failure half way leaves them in the
	 * right organisation and a stray record still in the queue, never a
	 * person with no organisation. For a registration the duplicate's
	 * details fill any gaps in the existing record, nothing is overwritten,
	 * and the duplicate is removed for good; it must have nothing in it.
	 *
	 * @param bool $add_domain Record the person's email domain on the organisation, so colleagues can join by domain.
	 * @return true|WP_Error
	 */
	public static function attach( int $signup_id, int $existing_org_id, int $actor_id, bool $add_domain = true ) {
		$signup = Store::find( $signup_id );

		if ( null === $signup || Signup::AWAITING !== $signup->state ) {
			return new WP_Error( 'dgl_nothing_waiting', __( 'That registration is not waiting on a decision.', 'dgl-platform' ) );
		}

		if ( ! Access::user_context( $actor_id )->is_moderator() ) {
			return new WP_Error( 'dgl_not_allowed', __( 'Only the review team can decide this.', 'dgl-platform' ) );
		}

		if ( $existing_org_id === $signup->org_id || ! isset( Org::pickable()[ $existing_org_id ] ) ) {
			return new WP_Error( 'dgl_bad_target', __( 'Choose a different organisation on the list, one that is not suspended.', 'dgl-platform' ) );
		}

		$user_id   = $signup->user_id;
		$duplicate = $signup->is_registration() ? $signup->org_id : 0;

		if ( $duplicate > 0 ) {
			$others = array_values( array_diff( Org::members( $duplicate ), [ $user_id ] ) );

			if ( [] !== $others || array_sum( ItemsTable::counts_for_org( $duplicate ) ) > 0 ) {
				return new WP_Error( 'dgl_not_empty', __( 'That organisation has people or listings attached to it, so it cannot be removed as a duplicate. Verify or refuse it instead, or move its listings first.', 'dgl-platform' ) );
			}
		}

		$typed_name    = $duplicate > 0 ? $signup->new_org_name : (string) get_the_title( $signup->org_id );
		$existing_name = (string) get_the_title( $existing_org_id );
		$role          = Rules::role_for( count( array_values( array_diff( Org::members( $existing_org_id ), [ $user_id ] ) ) ) );
		$status        = Org::is_approved( $existing_org_id ) ? UserContext::ACCOUNT_APPROVED : UserContext::ACCOUNT_PENDING;

		update_user_meta( $user_id, Meta::USER_ORG, $existing_org_id );
		update_user_meta( $user_id, Meta::USER_ORG_ROLE, $role );
		update_user_meta( $user_id, Meta::USER_ACCOUNT_STATUS, $status );
		Access::forget( $user_id );

		$copied = [];

		if ( $duplicate > 0 ) {
			foreach ( [ 'org_website', 'org_number', 'org_description', 'org_phone', 'org_postcode', 'org_address_1', 'org_address_2', 'org_city', 'org_email' ] as $key ) {
				$field = OrgSchema::find( $key );

				if ( null === $field ) {
					continue;
				}

				$theirs = trim( (string) get_post_meta( $duplicate, $field->meta_key(), true ) );

				if ( '' !== $theirs && '' === trim( (string) get_post_meta( $existing_org_id, $field->meta_key(), true ) ) ) {
					update_post_meta( $existing_org_id, $field->meta_key(), $theirs );
					$copied[] = $key;
				}
			}
		}

		$domain_added = false;

		if ( $add_domain && Domains::can_match( $signup->email ) && ! in_array( $signup->domain, Org::domains( $existing_org_id ), true ) ) {
			Org::set_domains( $existing_org_id, array_merge( Org::domains( $existing_org_id ), [ $signup->domain ] ) );
			$domain_added = true;
		}

		if ( $duplicate > 0 ) {
			wp_delete_post( $duplicate, true );
		}

		Store::update(
			$signup_id,
			[
				'state'      => Signup::APPROVED,
				'org_id'     => $existing_org_id,
				'reason'     => sprintf(
					/* translators: 1: existing organisation, 2: what was typed. */
					__( 'Attached to %1$s instead of %2$s.', 'dgl-platform' ),
					$existing_name,
					$typed_name
				),
				'decided_at' => Store::now(),
				'decided_by' => $actor_id,
			]
		);

		Log::record(
			'join_attached',
			'signup',
			$signup_id,
			$existing_org_id,
			$signup->email,
			[
				'org'    => [ $typed_name, $existing_name ],
				'role'   => [ '', $role ],
				'copied' => [ '', implode( ', ', $copied ) ],
				'domain' => [ '', $domain_added ? $signup->domain : '' ],
			],
			$actor_id
		);

		if ( $duplicate > 0 ) {
			Log::record(
				'org_duplicate_removed',
				'org',
				$existing_org_id,
				$existing_org_id,
				sprintf(
					/* translators: 1: the duplicate's name, 2: email address. */
					__( 'Removed the duplicate "%1$s" registered by %2$s.', 'dgl-platform' ),
					$typed_name,
					$signup->email
				),
				[],
				$actor_id
			);
		}

		if ( [] !== $copied || $domain_added ) {
			Log::record( 'org_updated', 'org', $existing_org_id, $existing_org_id, __( 'Details filled in from a duplicate registration.', 'dgl-platform' ), [], $actor_id );
		}

		$message = JoinCopy::attached( $existing_name, $typed_name, Router::url(), UserContext::ORG_OWNER === $role, ! Org::is_approved( $existing_org_id ) );
		Mailer::send( $message->for_recipients( [ $signup->email ] ) );
		self::tell_the_owners( $existing_org_id, $user_id, $role, false );

		return true;
	}

	public static function link( string $token ): string {
		return Router::url( 'join', $token );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * @return true
	 */
	private static function approve_claim( Signup $signup, int $actor_id ): bool {
		$others = array_values( array_diff( Org::members( $signup->org_id ), [ $signup->user_id ] ) );
		$role   = Rules::role_for( count( $others ) );

		update_user_meta( $signup->user_id, Meta::USER_ORG_ROLE, $role );
		update_user_meta( $signup->user_id, Meta::USER_ACCOUNT_STATUS, UserContext::ACCOUNT_APPROVED );
		Access::forget( $signup->user_id );
		Store::update( $signup->id, [ 'state' => Signup::APPROVED, 'decided_at' => Store::now(), 'decided_by' => $actor_id ] );

		Log::record( 'join_claim_approved', 'signup', $signup->id, $signup->org_id, $signup->email, [ 'role' => [ UserContext::ORG_CONTRIBUTOR, $role ] ], $actor_id );

		$message = JoinCopy::claim_approved( get_the_title( $signup->org_id ), Router::url(), UserContext::ORG_OWNER === $role, ! Org::is_approved( $signup->org_id ) );
		Mailer::send( $message->for_recipients( [ $signup->email ] ) );
		self::tell_the_owners( $signup->org_id, $signup->user_id, $role, false );

		return true;
	}

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

	private static function tell_the_owners( int $org_id, int $user_id, string $role, bool $by_domain = true ): void {
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
			Router::url( 'profile', 'members' ),
			$by_domain
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

	private static function tell_the_team_claim( int $signup_id, int $org_id, int $user_id, string $note ): void {
		$to = Recipients::for_audience( Plan::NOTIFY_MODERATORS, 0, 0 );

		if ( [] === $to ) {
			return;
		}

		$person  = get_userdata( $user_id );
		$message = JoinCopy::claim_awaiting(
			get_the_title( $org_id ),
			$person ? (string) $person->display_name : __( 'Somebody', 'dgl-platform' ),
			$person ? (string) $person->user_email : '',
			$note,
			Router::url( 'review', 'join', (string) $signup_id )
		);

		Mailer::send( $message->for_recipients( $to ) );
	}
}

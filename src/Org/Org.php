<?php
/**
 * Organisation lookups.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Org;

use DGL\Meta;
use DGL\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Reading an organisation and a user's place in it.
 *
 * Kept deliberately thin. Anything that decides what somebody may do belongs in
 * {@see \DGL\Access\Policy}, not here.
 */
final class Org {

	/**
	 * The organisation a user belongs to, or null if they are unlinked.
	 */
	public static function for_user( int $user_id ): ?int {
		if ( $user_id <= 0 ) {
			return null;
		}

		$org_id = (int) get_user_meta( $user_id, Meta::USER_ORG, true );

		return $org_id > 0 ? $org_id : null;
	}

	/**
	 * A user's role inside their organisation, or null.
	 */
	public static function role_for_user( int $user_id ): ?string {
		if ( $user_id <= 0 ) {
			return null;
		}

		$role = (string) get_user_meta( $user_id, Meta::USER_ORG_ROLE, true );

		return '' !== $role ? $role : null;
	}

	/**
	 * Whether the DGLP team has verified the organisation.
	 */
	public static function is_approved( ?int $org_id ): bool {
		if ( null === $org_id || $org_id <= 0 ) {
			return false;
		}

		return Meta::ORG_APPROVED === get_post_meta( $org_id, Meta::ORG_STATUS, true );
	}

	/**
	 * The organisation's verification state.
	 */
	public static function status( int $org_id ): string {
		$status = (string) get_post_meta( $org_id, Meta::ORG_STATUS, true );

		return '' !== $status ? $status : Meta::ORG_PENDING;
	}

	/**
	 * The organisation's trust level, always a valid one.
	 *
	 * A suspended or unverified organisation is treated as moderated whatever
	 * is stored against it, so trust can never outlive verification.
	 */
	public static function trust_level( ?int $org_id ): int {
		if ( null === $org_id || $org_id <= 0 || ! self::is_approved( $org_id ) ) {
			return Trust::MODERATED;
		}

		return Trust::normalise( get_post_meta( $org_id, Meta::ORG_TRUST, true ) );
	}

	/**
	 * The organisation that owns an item.
	 */
	public static function for_item( int $post_id ): int {
		return (int) get_post_meta( $post_id, Meta::ITEM_ORG, true );
	}

	/**
	 * Every user linked to an organisation.
	 *
	 * @return int[] User IDs.
	 */
	public static function members( int $org_id ): array {
		$users = get_users(
			[
				'meta_key'   => Meta::USER_ORG,
				'meta_value' => (string) $org_id,
				'fields'     => 'ID',
				'number'     => 100,
			]
		);

		return array_map( 'intval', $users );
	}

	/**
	 * Whether a post ID is actually an organisation.
	 */
	/**
	 * Take a person's access to their organisation away.
	 *
	 * The link and the role go; the account stays, so the same address can be
	 * invited again or join another organisation later. Their sessions are
	 * ended, because a removal that waits for them to sign out is not one.
	 * Everything they posted stays with the organisation.
	 *
	 * @return true|\WP_Error
	 */
	/**
	 * The addresses of an organisation's approved owners.
	 *
	 * Owners, not every member: these are the people who answer for the
	 * organisation, so they are the ones asked whether something still runs
	 * and told when its details change.
	 *
	 * @return string[]
	 */
	public static function owner_emails( int $org_id ): array {
		$to = [];

		foreach ( self::members( $org_id ) as $user_id ) {
			if ( \DGL\Access\UserContext::ORG_OWNER !== self::role_for_user( $user_id ) ) {
				continue;
			}

			if ( \DGL\Access\UserContext::ACCOUNT_APPROVED !== (string) get_user_meta( $user_id, Meta::USER_ACCOUNT_STATUS, true ) ) {
				continue;
			}

			$user = get_userdata( $user_id );

			if ( $user && '' !== (string) $user->user_email ) {
				$to[] = (string) $user->user_email;
			}
		}

		return array_values( array_unique( $to ) );
	}

	public static function remove_member( int $user_id, int $actor_id ) {
		$actor  = \DGL\Access\Access::user_context( $actor_id );
		$org_id = self::for_user( $user_id );

		if ( ! \DGL\Access\Policy::can_remove_member( $actor, $user_id, $org_id ) ) {
			return new \WP_Error( 'dgl_not_allowed', __( 'You cannot remove that person.', 'dgl-platform' ) );
		}

		$person = get_userdata( $user_id );

		if ( ! $person ) {
			return new \WP_Error( 'dgl_no_user', __( 'That account no longer exists.', 'dgl-platform' ) );
		}

		delete_user_meta( $user_id, Meta::USER_ORG );
		delete_user_meta( $user_id, Meta::USER_ORG_ROLE );
		\WP_Session_Tokens::get_instance( $user_id )->destroy_all();

		\DGL\Audit\Log::record(
			'member_removed',
			'org',
			(int) $org_id,
			(int) $org_id,
			sprintf(
				/* translators: 1: name, 2: email address. */
				__( '%1$s (%2$s) no longer posts for this organisation.', 'dgl-platform' ),
				$person->display_name,
				$person->user_email
			),
			[],
			$actor_id
		);

		$message = \DGL\Email\InviteCopy::removed( get_the_title( (int) $org_id ) );
		\DGL\Email\Mailer::send( $message->for_recipients( [ (string) $person->user_email ] ) );

		return true;
	}

	/**
	 * Make a colleague an owner or a contributor.
	 *
	 * Applies at once. The person is emailed, because what they can do has
	 * changed, and the organisation's owners see it in their notifications.
	 *
	 * @return true|\WP_Error
	 */
	public static function set_role( int $user_id, string $role, int $actor_id ) {
		$actor  = \DGL\Access\Access::user_context( $actor_id );
		$org_id = self::for_user( $user_id );

		if ( ! in_array( $role, [ \DGL\Access\UserContext::ORG_OWNER, \DGL\Access\UserContext::ORG_CONTRIBUTOR ], true ) ) {
			return new \WP_Error( 'dgl_bad_role', __( 'That is not a role.', 'dgl-platform' ) );
		}

		if ( ! \DGL\Access\Policy::can_change_role( $actor, $user_id, $org_id ) ) {
			return new \WP_Error( 'dgl_not_allowed', __( 'You cannot change what that person can do.', 'dgl-platform' ) );
		}

		$person = get_userdata( $user_id );

		if ( ! $person ) {
			return new \WP_Error( 'dgl_no_user', __( 'That account no longer exists.', 'dgl-platform' ) );
		}

		if ( $role === self::role_for_user( $user_id ) ) {
			return true;
		}

		update_user_meta( $user_id, Meta::USER_ORG_ROLE, $role );
		\DGL\Access\Access::forget( $user_id );

		$is_owner = \DGL\Access\UserContext::ORG_OWNER === $role;

		\DGL\Audit\Log::record(
			'member_role_changed',
			'org',
			(int) $org_id,
			(int) $org_id,
			sprintf(
				$is_owner
					/* translators: %s: name. */
					? __( '%s is now an owner: they can manage members and the organisation page.', 'dgl-platform' )
					/* translators: %s: name. */
					: __( '%s is now a contributor: they can submit and edit listings.', 'dgl-platform' ),
				$person->display_name
			),
			[ 'role' => $role ],
			$actor_id
		);

		$message = \DGL\Email\InviteCopy::role_changed( get_the_title( (int) $org_id ), $is_owner, \DGL\Dashboard\Router::url() );
		\DGL\Email\Mailer::send( $message->for_recipients( [ (string) $person->user_email ] ) );

		return true;
	}

	/**
	 * The email domains an organisation has recorded.
	 *
	 * @return string[]
	 */
	public static function domains( int $org_id ): array {
		$rows = get_post_meta( $org_id, Meta::ORG_DOMAIN );

		return array_values( array_filter( array_map( 'strval', is_array( $rows ) ? $rows : [] ) ) );
	}

	/**
	 * Replace an organisation's recorded domains.
	 *
	 * @param string[] $domains Already normalised; see Joining\Domains::list().
	 */
	public static function set_domains( int $org_id, array $domains ): void {
		delete_post_meta( $org_id, Meta::ORG_DOMAIN );

		$clean = [];

		foreach ( $domains as $domain ) {
			$domain = \DGL\Joining\Domains::normalise( (string) $domain );

			if ( '' !== $domain && ! in_array( $domain, $clean, true ) ) {
				$clean[] = $domain;
				add_post_meta( $org_id, Meta::ORG_DOMAIN, $domain );
			}
		}
	}

	/**
	 * Organisations that have recorded a domain. Exact match, suspended ones
	 * left out: a suspended organisation does not take on new people.
	 *
	 * @return int[]
	 */
	public static function by_domain( string $domain ): array {
		$domain = \DGL\Joining\Domains::normalise( $domain );

		if ( '' === $domain || \DGL\Joining\Domains::is_public( $domain ) ) {
			return [];
		}

		$found = get_posts(
			[
				'post_type'      => PostTypes::ORG,
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_key'       => Meta::ORG_DOMAIN,
				'meta_value'     => $domain,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		return array_values(
			array_filter(
				array_map( 'intval', $found ),
				static fn( int $id ): bool => Meta::ORG_SUSPENDED !== self::status( $id )
			)
		);
	}

	public static function exists( int $org_id ): bool {
		return $org_id > 0 && PostTypes::ORG === get_post_type( $org_id );
	}
}

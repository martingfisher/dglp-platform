<?php
/**
 * Working out who hears about a transition.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Email;

use DGL\Access\UserContext;
use DGL\Meta;
use DGL\Org\Org;
use DGL\Roles;
use DGL\Workflow\Plan;

defined( 'ABSPATH' ) || exit;

/**
 * Turning an audience into addresses.
 *
 * The plan says "tell the member" or "tell the moderators". It does not say
 * which people those are, because that depends on who is in the organisation
 * and who is on the team, and neither belongs in workflow logic.
 *
 * Two rules worth stating, because both are easy to get wrong in the other
 * direction:
 *
 * - The member audience is the organisation, not the author. A contributor who
 *   submits and then leaves should not take the approval email with them, and
 *   an owner is accountable for what their organisation publishes, so they get
 *   a copy either way.
 *
 * - The actor is dropped from the moderator audience but kept in the member
 *   audience. A reviewer does not need an email about the decision they just
 *   made. A member who published on trust does need the record, even though
 *   they were the one who pressed the button.
 *
 * Suspended and closed accounts are never mailed. They cannot act on the
 * message, and a closed account is one somebody asked us to stop using.
 */
final class Recipients {

	/**
	 * Addresses for one audience.
	 *
	 * @return string[]
	 */
	public static function for_audience( string $audience, int $post_id, int $actor_id ): array {
		$ids = match ( $audience ) {
			Plan::NOTIFY_MEMBER     => self::member_ids( $post_id ),
			Plan::NOTIFY_MODERATORS => self::moderator_ids( $actor_id ),
			default                 => [],
		};

		return self::addresses( $ids );
	}

	/**
	 * The author and their organisation's owners.
	 *
	 * @return int[]
	 */
	private static function member_ids( int $post_id ): array {
		$ids = [];

		$author = (int) get_post_field( 'post_author', $post_id );

		if ( $author > 0 ) {
			$ids[] = $author;
		}

		$org_id = Org::for_item( $post_id );

		if ( $org_id > 0 ) {
			foreach ( Org::members( $org_id ) as $user_id ) {
				if ( UserContext::ORG_OWNER === Org::role_for_user( $user_id ) ) {
					$ids[] = $user_id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Everybody who can moderate, minus whoever just acted.
	 *
	 * Resolved by capability rather than by role, so an administrator who is
	 * doing review work is included without being given a second role. The
	 * filter is there for the site where "everyone who can moderate" and
	 * "everyone who wants the email" are not the same list.
	 *
	 * @return int[]
	 */
	private static function moderator_ids( int $actor_id ): array {
		static $cached = null;

		if ( null === $cached ) {
			$users = get_users(
				[
					'capability' => Roles::CAP_MODERATE,
					'fields'     => 'ID',
					'number'     => 100,
				]
			);

			$cached = array_map( 'intval', $users );
		}

		/**
		 * Filter the review team's notification list.
		 *
		 * @param int[] $ids User IDs.
		 */
		$ids = (array) apply_filters( 'dgl_moderator_recipients', $cached );

		return array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static fn( int $id ): bool => $id > 0 && $id !== $actor_id
			)
		);
	}

	/**
	 * User IDs to deliverable addresses.
	 *
	 * @param int[] $ids
	 * @return string[]
	 */
	private static function addresses( array $ids ): array {
		$addresses = [];

		foreach ( $ids as $id ) {
			$user = get_userdata( $id );

			if ( ! $user || ! is_email( (string) $user->user_email ) ) {
				continue;
			}

			if ( ! self::is_mailable( $id ) ) {
				continue;
			}

			$addresses[ strtolower( (string) $user->user_email ) ] = (string) $user->user_email;
		}

		return array_values( $addresses );
	}

	/**
	 * Whether an account is in a state where mailing it is right.
	 *
	 * A pending account is mailed: they are waiting to hear from DGLP and
	 * silence is the wrong answer. A suspended or closed one is not.
	 */
	private static function is_mailable( int $user_id ): bool {
		$status = (string) get_user_meta( $user_id, Meta::USER_ACCOUNT_STATUS, true );

		if ( '' === $status ) {
			// Moderators and administrators carry no account status of their own.
			return true;
		}

		return in_array( $status, [ UserContext::ACCOUNT_APPROVED, UserContext::ACCOUNT_PENDING ], true );
	}
}

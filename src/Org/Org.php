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
	public static function exists( int $org_id ): bool {
		return $org_id > 0 && PostTypes::ORG === get_post_type( $org_id );
	}
}

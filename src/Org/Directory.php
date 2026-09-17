<?php
/**
 * The public directory: whether an organisation is in it, and the facts the
 * import carried over that a member reads but does not edit.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Org;

use DGL\Access\Policy;
use DGL\Access\UserContext;
use DGL\Audit\Log;
use DGL\Meta;

defined( 'ABSPATH' ) || exit;

final class Directory {

	/**
	 * Whether the organisation has asked to be shown. The flag alone is not
	 * enough to appear: {@see is_listed()} also wants the organisation
	 * verified.
	 */
	public static function wants_listing( int $org_id ): bool {
		return '1' === (string) get_post_meta( $org_id, Meta::ORG_IN_DIRECTORY, true );
	}

	/**
	 * Whether the public directory would show this organisation now.
	 */
	public static function is_listed( int $org_id ): bool {
		return self::wants_listing( $org_id ) && Org::is_approved( $org_id );
	}

	/**
	 * Switch the listing on or off, on behalf of a member.
	 *
	 * @return true|\WP_Error
	 */
	public static function set( int $org_id, bool $on, UserContext $actor ) {
		if ( ! Policy::can_toggle_directory( $actor, $org_id ) ) {
			return new \WP_Error( 'dgl_forbidden', __( 'Only an approved member of the organisation can change this.', 'dgl-platform' ) );
		}

		$was = self::wants_listing( $org_id );

		if ( $was === $on ) {
			return true;
		}

		if ( $on ) {
			update_post_meta( $org_id, Meta::ORG_IN_DIRECTORY, '1' );
		} else {
			delete_post_meta( $org_id, Meta::ORG_IN_DIRECTORY );
		}

		Log::record(
			$on ? 'directory_on' : 'directory_off',
			'org',
			$org_id,
			$org_id,
			'',
			[ 'in_directory' => [ $was ? 'yes' : 'no', $on ? 'yes' : 'no' ] ],
			$actor->user_id
		);

		return true;
	}

	/**
	 * What Forum Central's records say, for the read-only panel on the
	 * Organisation tab. Empty when the organisation was not imported.
	 *
	 * @return array<string, string> Label => value.
	 */
	public static function imported_facts( int $org_id ): array {
		$fc_id = (string) get_post_meta( $org_id, Meta::ORG_FC_ID, true );

		if ( '' === $fc_id ) {
			return [];
		}

		$facts = [
			__( 'Forum Central record', 'dgl-platform' ) => $fc_id,
		];

		$volition = (string) get_post_meta( $org_id, Meta::ORG_FC_VOLITION, true );
		$lopf     = (string) get_post_meta( $org_id, Meta::ORG_FC_LOPF, true );

		if ( '' !== $volition ) {
			$facts[ __( 'Volition membership', 'dgl-platform' ) ] = $volition;
		}

		if ( '' !== $lopf ) {
			$facts[ __( 'LOPF membership', 'dgl-platform' ) ] = $lopf;
		}

		if ( '1' === (string) get_post_meta( $org_id, Meta::ORG_AGE_FRIENDLY, true ) ) {
			$facts[ __( 'Age and Dementia Friendly Business', 'dgl-platform' ) ] = __( 'Yes', 'dgl-platform' );
		}

		$facts[ __( 'Gave Forum Central permission to publish', 'dgl-platform' ) ] =
			'1' === (string) get_post_meta( $org_id, Meta::ORG_FC_PERMISSION, true ) ? __( 'Yes', 'dgl-platform' ) : __( 'Not recorded', 'dgl-platform' );

		$imported = (string) get_post_meta( $org_id, Meta::ORG_IMPORTED_AT, true );

		if ( '' !== $imported ) {
			$facts[ __( 'Imported', 'dgl-platform' ) ] = $imported;
		}

		return $facts;
	}
}

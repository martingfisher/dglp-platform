<?php
/**
 * Roles and capabilities.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

defined( 'ABSPATH' ) || exit;

/**
 * Two roles, and the capability vocabulary they draw on.
 *
 * Members never reach wp-admin: their entire experience is the front-end
 * dashboard. Moderators work the queue. Both funnel through
 * {@see Access\Policy} for anything organisation-scoped, because a WordPress
 * capability alone cannot express "this member's organisation owns this item".
 */
final class Roles {

	public const MEMBER    = 'dgl_member';
	public const MODERATOR = 'dgl_moderator';

	/** Decide on a pending submission. */
	public const CAP_MODERATE = 'moderate_dgl_items';

	/** Change what an organisation may publish without review. Any review team member. */
	public const CAP_GRANT_TRUST = 'grant_dgl_trust';

	/** Read the audit trail across all organisations. */
	public const CAP_VIEW_AUDIT = 'view_dgl_audit';

	/** Manage the shared topic taxonomy. */
	public const CAP_MANAGE_TOPICS = 'manage_dgl_topics';

	/**
	 * Capabilities a member holds. Organisation scoping is applied on top by
	 * the policy, so these are necessary but never sufficient.
	 *
	 * @return array<string, bool>
	 */
	public static function member_caps(): array {
		return [
			'read'                     => true,
			'upload_files'             => true,
			'create_dgl_items'         => true,
			'edit_dgl_items'           => true,
			'edit_published_dgl_items' => true,
			'delete_dgl_items'         => true,
			'publish_dgl_items'        => false,
			'edit_others_dgl_items'    => false,
			'delete_others_dgl_items'  => false,
			'read_private_dgl_items'   => false,
		];
	}

	/**
	 * Capabilities a moderator holds.
	 *
	 * Note the absence of delete: a moderator decides what goes live and,
	 * since 0.34.0, which organisations are trusted; an administrator decides
	 * what is destroyed.
	 *
	 * @return array<string, bool>
	 */
	public static function moderator_caps(): array {
		return [
			'read'                      => true,
			'upload_files'              => true,
			'edit_dgl_items'            => true,
			'edit_others_dgl_items'     => true,
			'edit_published_dgl_items'  => true,
			'read_private_dgl_items'    => true,
			'publish_dgl_items'         => true,
			'delete_dgl_items'          => false,
			'delete_others_dgl_items'   => false,
			'edit_dgl_orgs'             => true,
			'edit_others_dgl_orgs'      => true,
			'read_private_dgl_orgs'     => true,
			self::CAP_MODERATE          => true,
			self::CAP_GRANT_TRUST       => true,
			self::CAP_VIEW_AUDIT        => true,
			self::CAP_MANAGE_TOPICS     => true,
		];
	}

	/**
	 * Everything an administrator gets on top of their existing role.
	 *
	 * @return string[]
	 */
	public static function administrator_caps(): array {
		return [
			'create_dgl_items',
			'edit_dgl_items',
			'edit_others_dgl_items',
			'edit_published_dgl_items',
			'edit_private_dgl_items',
			'read_private_dgl_items',
			'publish_dgl_items',
			'delete_dgl_items',
			'delete_others_dgl_items',
			'delete_published_dgl_items',
			'delete_private_dgl_items',
			'edit_dgl_orgs',
			'edit_others_dgl_orgs',
			'edit_published_dgl_orgs',
			'read_private_dgl_orgs',
			'publish_dgl_orgs',
			'delete_dgl_orgs',
			'delete_others_dgl_orgs',
			self::CAP_MODERATE,
			self::CAP_GRANT_TRUST,
			self::CAP_VIEW_AUDIT,
			self::CAP_MANAGE_TOPICS,
		];
	}

	/**
	 * Create the roles and top up administrators. Runs on activation.
	 */
	public static function install(): void {
		remove_role( self::MEMBER );
		remove_role( self::MODERATOR );

		add_role( self::MEMBER, __( 'DGLP member', 'dgl-platform' ), self::member_caps() );
		add_role( self::MODERATOR, __( 'DGLP moderator', 'dgl-platform' ), self::moderator_caps() );

		$admin = get_role( 'administrator' );

		if ( $admin instanceof \WP_Role ) {
			foreach ( self::administrator_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove the roles and the administrator top-up. Runs on uninstall, not on
	 * deactivation: deactivating should not strand every member account.
	 */
	public static function uninstall(): void {
		remove_role( self::MEMBER );
		remove_role( self::MODERATOR );

		$admin = get_role( 'administrator' );

		if ( $admin instanceof \WP_Role ) {
			foreach ( self::administrator_caps() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}
}

<?php
/**
 * WordPress's own privacy tools, taught about this plugin's data.
 *
 * Tools > Export Personal Data and Tools > Erase Personal Data already
 * handle core's tables. Registering an exporter and an eraser here means
 * a subject access request or an erasure request is answered from the
 * screens the site's administrators already have, rather than by somebody
 * writing SQL against five tables from memory.
 *
 * @package DGL
 */

declare(strict_types=1);

namespace DGL\Privacy;

use DGL\Access\Access;
use DGL\Audit\Table as AuditTable;
use DGL\Email\Digest\Store as DigestStore;
use DGL\Index\ItemsTable;
use DGL\Invites\Store as InviteStore;
use DGL\Joining\Store as SignupStore;
use DGL\Meta;
use DGL\Org\Org;
use DGL\PostTypes;
use DGL\Statuses;
use WP_User;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	private const GROUP_MEMBERSHIP = 'dgl-membership';
	private const GROUP_DIGEST     = 'dgl-digest';
	private const GROUP_INVITES    = 'dgl-invites';
	private const GROUP_SIGNUPS    = 'dgl-signups';
	private const GROUP_ACTIVITY   = 'dgl-activity';
	private const GROUP_LISTINGS   = 'dgl-listings';

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'register_eraser' ] );
		add_action( 'admin_init', [ self::class, 'suggest_policy_text' ] );
	}

	/**
	 * @param array<string, array<string, mixed>> $exporters
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['dgl-platform'] = [
			'exporter_friendly_name' => __( 'DGLP user area', 'dgl-platform' ),
			'callback'               => [ self::class, 'export' ],
		];

		return $exporters;
	}

	/**
	 * @param array<string, array<string, mixed>> $erasers
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['dgl-platform'] = [
			'eraser_friendly_name' => __( 'DGLP user area', 'dgl-platform' ),
			'callback'             => [ self::class, 'erase' ],
		];

		return $erasers;
	}

	/* ----------------------------------------------------------- export */

	/**
	 * Everything the plugin holds about one email address, grouped the way
	 * the export screen displays it.
	 *
	 * Tokens and hashes are never exported: an invitation token or an
	 * unsubscribe token is a credential, and a hashed IP is not the
	 * person's data in any form they could use.
	 *
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		$email = strtolower( trim( $email ) );
		$user  = get_user_by( 'email', $email );
		$data  = [];

		if ( $user instanceof WP_User ) {
			$data = array_merge(
				$data,
				self::membership_items( $user ),
				self::digest_items( $user ),
				self::activity_items( $user ),
				self::listing_items( $user )
			);
		}

		$data = array_merge( $data, self::invite_items( $email ), self::signup_items( $email ) );

		return [ 'data' => $data, 'done' => true ];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function membership_items( WP_User $user ): array {
		$org_id = Org::for_user( $user->ID );

		if ( null === $org_id && '' === (string) get_user_meta( $user->ID, Meta::USER_ACCOUNT_STATUS, true ) ) {
			return [];
		}

		return [
			self::item(
				self::GROUP_MEMBERSHIP,
				__( 'Membership', 'dgl-platform' ),
				'membership-' . $user->ID,
				[
					__( 'Organisation', 'dgl-platform' )   => null !== $org_id ? get_the_title( $org_id ) : __( 'None', 'dgl-platform' ),
					__( 'Role', 'dgl-platform' )           => (string) ( Org::role_for_user( $user->ID ) ?? '' ),
					__( 'Account status', 'dgl-platform' ) => (string) get_user_meta( $user->ID, Meta::USER_ACCOUNT_STATUS, true ),
				]
			),
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function digest_items( WP_User $user ): array {
		if ( ! DigestStore::exists() ) {
			return [];
		}

		$sub = DigestStore::for_user( $user->ID );

		if ( null === $sub ) {
			return [];
		}

		return [
			self::item(
				self::GROUP_DIGEST,
				__( 'Email digest preferences', 'dgl-platform' ),
				'digest-' . $user->ID,
				[
					__( 'Content types', 'dgl-platform' )          => implode( ', ', $sub->types ),
					__( 'Topics', 'dgl-platform' )                 => implode( ', ', array_map( 'strval', $sub->topic_ids ) ),
					__( 'Frequency', 'dgl-platform' )              => $sub->frequency,
					__( 'Includes own organisation', 'dgl-platform' ) => $sub->include_own_org ? __( 'Yes', 'dgl-platform' ) : __( 'No', 'dgl-platform' ),
					__( 'Consent given', 'dgl-platform' )          => (string) ( $sub->consent_at ?? __( 'No', 'dgl-platform' ) ),
					__( 'Last digest sent', 'dgl-platform' )       => (string) ( $sub->last_sent_at ?? __( 'Never', 'dgl-platform' ) ),
				]
			),
		];
	}

	/**
	 * Invitations addressed to this email. Ones this person sent are the
	 * invitee's data, not theirs, and are not exported here.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function invite_items( string $email ): array {
		if ( ! InviteStore::exists() ) {
			return [];
		}

		global $wpdb;

		$table = InviteStore::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, org_id, org_role, created_at, expires_at, accepted_at, revoked_at FROM {$table} WHERE email = %s ORDER BY id", $email ), ARRAY_A );
		$out  = [];

		foreach ( (array) $rows as $row ) {
			$out[] = self::item(
				self::GROUP_INVITES,
				__( 'Invitations', 'dgl-platform' ),
				'invite-' . $row['id'],
				[
					__( 'Organisation', 'dgl-platform' ) => get_the_title( (int) $row['org_id'] ),
					__( 'Role offered', 'dgl-platform' ) => (string) $row['org_role'],
					__( 'Sent', 'dgl-platform' )         => (string) $row['created_at'],
					__( 'Expires', 'dgl-platform' )      => (string) $row['expires_at'],
					__( 'Accepted', 'dgl-platform' )     => (string) ( $row['accepted_at'] ?? __( 'No', 'dgl-platform' ) ),
					__( 'Withdrawn', 'dgl-platform' )    => (string) ( $row['revoked_at'] ?? __( 'No', 'dgl-platform' ) ),
				]
			);
		}

		return $out;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function signup_items( string $email ): array {
		if ( ! SignupStore::exists() ) {
			return [];
		}

		global $wpdb;

		$table = SignupStore::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, domain, state, org_id, new_org_name, new_org_details, reason, created_at, verified_at, completed_at, decided_at FROM {$table} WHERE email = %s ORDER BY id", $email ), ARRAY_A );
		$out  = [];

		foreach ( (array) $rows as $row ) {
			$details = json_decode( (string) $row['new_org_details'], true );
			$about   = is_array( $details ) ? implode( '; ', array_map( static fn( $k, $v ) => $k . ': ' . ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ), array_keys( $details ), $details ) ) : '';

			$out[] = self::item(
				self::GROUP_SIGNUPS,
				__( 'Joining requests', 'dgl-platform' ),
				'signup-' . $row['id'],
				[
					__( 'Email domain', 'dgl-platform' )       => (string) $row['domain'],
					__( 'State', 'dgl-platform' )              => (string) $row['state'],
					__( 'Organisation', 'dgl-platform' )       => (int) $row['org_id'] > 0 ? get_the_title( (int) $row['org_id'] ) : (string) $row['new_org_name'],
					__( 'Organisation details given', 'dgl-platform' ) => $about,
					__( 'Decision note', 'dgl-platform' )      => (string) $row['reason'],
					__( 'Started', 'dgl-platform' )            => (string) $row['created_at'],
					__( 'Email verified', 'dgl-platform' )     => (string) ( $row['verified_at'] ?? __( 'No', 'dgl-platform' ) ),
					__( 'Completed', 'dgl-platform' )          => (string) ( $row['completed_at'] ?? __( 'No', 'dgl-platform' ) ),
					__( 'Decided', 'dgl-platform' )            => (string) ( $row['decided_at'] ?? __( 'No', 'dgl-platform' ) ),
				]
			);
		}

		return $out;
	}

	/**
	 * Actions this person took, from the audit log. The IP hash is not
	 * exported, and neither is the field-level diff, which can carry other
	 * people's details.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function activity_items( WP_User $user ): array {
		global $wpdb;

		$table = AuditTable::name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, logged_at, action, object_type, object_id, org_id FROM {$table} WHERE actor_id = %d ORDER BY id LIMIT 2000", $user->ID ), ARRAY_A );
		$out  = [];

		foreach ( (array) $rows as $row ) {
			$out[] = self::item(
				self::GROUP_ACTIVITY,
				__( 'Activity', 'dgl-platform' ),
				'audit-' . $row['id'],
				[
					__( 'When', 'dgl-platform' )   => (string) $row['logged_at'],
					__( 'Action', 'dgl-platform' ) => (string) $row['action'],
					__( 'What', 'dgl-platform' )   => $row['object_type'] . ' ' . $row['object_id'],
				]
			);
		}

		return $out;
	}

	/**
	 * Listings this person wrote. They belong to the organisation, so only
	 * the fact of authorship is exported, not the content.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function listing_items( WP_User $user ): array {
		$ids = get_posts(
			[
				'post_type'   => PostTypes::submittable(),
				'post_status' => array_merge( Statuses::all(), [ 'publish', 'draft', 'pending', 'private' ] ),
				'author'      => $user->ID,
				'numberposts' => 500,
				'fields'      => 'ids',
			]
		);
		$out = [];

		foreach ( (array) $ids as $id ) {
			$out[] = self::item(
				self::GROUP_LISTINGS,
				__( 'Listings you wrote', 'dgl-platform' ),
				'listing-' . $id,
				[
					__( 'Title', 'dgl-platform' )  => get_the_title( (int) $id ),
					__( 'Type', 'dgl-platform' )   => (string) get_post_type( (int) $id ),
					__( 'Status', 'dgl-platform' ) => (string) get_post_status( (int) $id ),
					__( 'Created', 'dgl-platform' ) => (string) get_post_field( 'post_date', (int) $id ),
				]
			);
		}

		return $out;
	}

	/**
	 * One export row in the shape core's exporter expects.
	 *
	 * @param array<string, string> $fields
	 * @return array<string, mixed>
	 */
	private static function item( string $group, string $label, string $id, array $fields ): array {
		$data = [];

		foreach ( $fields as $name => $value ) {
			$data[] = [ 'name' => $name, 'value' => $value ];
		}

		return [
			'group_id'    => $group,
			'group_label' => $label,
			'item_id'     => $id,
			'data'        => $data,
		];
	}

	/* ------------------------------------------------------------ erase */

	/**
	 * Remove what can be removed and say what cannot.
	 *
	 * Removed: the digest subscription, invitations addressed to the email,
	 * joining requests for the email, the organisation link and account
	 * status, and every session. Anonymised: audit rows, which keep the
	 * event and lose the actor. Retained: listings, which belong to the
	 * organisation and stay under its name; the audit trail itself, which
	 * is the site's record of what was published and by whose decision.
	 *
	 * The WordPress account is left for core's own eraser and the
	 * administrator's delete, as core's tools expect.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		global $wpdb;

		$email    = strtolower( trim( $email ) );
		$user     = get_user_by( 'email', $email );
		$removed  = false;
		$retained = false;
		$messages = [];

		if ( InviteStore::exists() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$n = $wpdb->delete( InviteStore::name(), [ 'email' => $email ], [ '%s' ] );
			$removed = $removed || ( (int) $n > 0 );
		}

		if ( SignupStore::exists() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$n = $wpdb->delete( SignupStore::name(), [ 'email' => $email ], [ '%s' ] );
			$removed = $removed || ( (int) $n > 0 );
		}

		if ( ! $user instanceof WP_User ) {
			return [ 'items_removed' => $removed, 'items_retained' => false, 'messages' => [], 'done' => true ];
		}

		if ( DigestStore::exists() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$n = $wpdb->delete( DigestStore::name(), [ 'user_id' => $user->ID ], [ '%d' ] );
			$removed = $removed || ( (int) $n > 0 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$n = $wpdb->update(
			AuditTable::name(),
			[ 'actor_id' => 0, 'actor_ip_hash' => null ],
			[ 'actor_id' => $user->ID ],
			[ '%d', '%s' ],
			[ '%d' ]
		);

		if ( (int) $n > 0 ) {
			$removed    = true;
			$retained   = true;
			$messages[] = __( 'Audit log entries are kept as the record of what was published and decided, with the person removed from them.', 'dgl-platform' );
		}

		$listings = count(
			get_posts(
				[
					'post_type'   => PostTypes::submittable(),
					'post_status' => array_merge( Statuses::all(), [ 'publish', 'draft', 'pending', 'private' ] ),
					'author'      => $user->ID,
					'numberposts' => 1,
					'fields'      => 'ids',
				]
			)
		);

		if ( $listings > 0 ) {
			$retained   = true;
			$messages[] = __( 'Listings belong to the organisation and are kept under its name. Delete the account to reassign or remove them.', 'dgl-platform' );
		}

		foreach ( [ Meta::USER_ORG, Meta::USER_ORG_ROLE, Meta::USER_ACCOUNT_STATUS ] as $key ) {
			if ( '' !== (string) get_user_meta( $user->ID, $key, true ) ) {
				delete_user_meta( $user->ID, $key );
				$removed = true;
			}
		}

		// The index carries author ids; keep it truthful.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( ItemsTable::name(), [ 'author_id' => 0 ], [ 'author_id' => $user->ID ], [ '%d' ], [ '%d' ] );

		\WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		Access::flush_cache();

		return [
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		];
	}

	/* ----------------------------------------------------------- policy */

	/**
	 * Suggested wording for the site's privacy policy, shown under
	 * Settings > Privacy > Policy Guide. Factual, and only about what the
	 * plugin actually stores.
	 */
	public static function suggest_policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . __( 'When you join the user area we store your name, email address, the organisation you belong to and your role in it, and whether your account is pending, approved or suspended. We store the date your email address was verified and, if you registered a new organisation, the details you gave about it and the review team\'s decision.', 'dgl-platform' ) . '</p>'
			. '<p>' . __( 'If you ask for email digests we store which content types and topics you asked for, how often, and the date you agreed. You can change or withdraw this at any time from your profile or from the link in any digest.', 'dgl-platform' ) . '</p>'
			. '<p>' . __( 'Invitations record the email address invited, the organisation, who sent it and whether it was accepted. Unused invitations are removed after 90 days.', 'dgl-platform' ) . '</p>'
			. '<p>' . __( 'Actions in the user area, such as submitting a listing or a review decision, are recorded in an audit log with the date, the action and a one-way hash of the IP address. Listings you write belong to your organisation and stay published under its name if you leave.', 'dgl-platform' ) . '</p>';

		wp_add_privacy_policy_content( __( 'DGLP user area', 'dgl-platform' ), wp_kses_post( $content ) );
	}
}

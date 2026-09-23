<?php
/**
 * `wp dgl invite` - onboarding an organisation's first owner.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Invites;

use DGL\Access\Access;
use DGL\Access\UserContext;
use DGL\Meta;
use DGL\Org\Org;
use DGL\PostTypes;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Invitation commands.
 *
 * The documented way a new partner joins is that DGLP create the organisation
 * and invite its first owner, who then invites their own colleagues. The first
 * half of that was only possible by clicking through wp-admin, which makes
 * onboarding twenty organisations an afternoon of clicking and makes it
 * impossible to check what was actually sent.
 */
final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl invite', self::class );
	}

	/**
	 * Invite somebody to an organisation.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : Who to invite.
	 *
	 * --org=<org>
	 * : Organisation post ID, or its exact name.
	 *
	 * [--role=<role>]
	 * : owner or contributor. Default: owner, because this is normally used
	 * for the first person at a new organisation.
	 *
	 * [--as=<user>]
	 * : Send as this account. Defaults to the first administrator, so the
	 * invitation is attributable to a real person rather than to nobody.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl invite jo@charity.org --org="Armley Community Hub"
	 *     wp dgl invite jo@charity.org --org=8438 --role=contributor
	 *
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function send( array $args, array $assoc ): void {
		$email = (string) $args[0];
		$org   = self::resolve_org( (string) ( $assoc['org'] ?? '' ) );
		$role  = (string) ( $assoc['role'] ?? UserContext::ORG_OWNER );

		if ( ! in_array( $role, [ UserContext::ORG_OWNER, UserContext::ORG_CONTRIBUTOR ], true ) ) {
			WP_CLI::error( 'Role must be owner or contributor.' );
		}

		$actor_id = self::resolve_actor( $assoc['as'] ?? null );

		/*
		 * The sender has to be set as the current user, not just passed along.
		 * Invites::send() asks the policy, the policy reads the actor's
		 * context, and the audit entry records who did it. A command that
		 * bypassed any of that would be a second, weaker way in.
		 */
		wp_set_current_user( $actor_id );
		Access::flush_cache( $actor_id );

		$result = Invites::send( Access::user_context( $actor_id ), $org, $email, $role );

		if ( ! $result['ok'] ) {
			WP_CLI::error( $result['error'] );
		}

		WP_CLI::log( 'Organisation: ' . get_the_title( $org ) );
		WP_CLI::log( 'Invited as:   ' . Invites::role_name( $role ) );
		WP_CLI::log( 'Sent by:      ' . Invites::person( $actor_id ) );
		WP_CLI::log( 'Expires:      ' . Invites::readable_date( $result['invite']->expires_at ) );

		if ( ! \DGL\Email\Routing::is_enabled() ) {
			WP_CLI::warning( 'Sending is OFF on this site, so the invitation was stored but no email left. Nobody can accept it: the link only ever exists in that email.' );
			return;
		}

		WP_CLI::success( sprintf( 'Invitation handed to WordPress for %s. Check the inbox.', $result['invite']->email ) );
	}

	/**
	 * Every invitation for an organisation, and where it stands.
	 *
	 * ## OPTIONS
	 *
	 * --org=<org>
	 * : Organisation post ID, or its exact name.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl invite list --org="Armley Community Hub"
	 *
	 * @subcommand list
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function list_invites( array $args, array $assoc ): void {
		$org = self::resolve_org( (string) ( $assoc['org'] ?? '' ) );
		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		$rows = [];

		foreach ( Store::for_org( $org, 100 ) as $invite ) {
			$rows[] = [
				'email'   => $invite->email,
				'role'    => Invites::role_name( $invite->org_role ),
				'state'   => Rules::label( Rules::state( $invite, $now ) ),
				'sent'    => Invites::readable_date( $invite->created_at ),
				'expires' => Invites::readable_date( $invite->expires_at ),
			];
		}

		if ( [] === $rows ) {
			WP_CLI::success( 'No invitations for that organisation.' );
			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, [ 'email', 'role', 'state', 'sent', 'expires' ] );
	}

	/**
	 * Create an organisation, ready to be invited into.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The organisation's name.
	 *
	 * [--approved]
	 * : Mark it verified straight away. An unverified organisation cannot
	 * invite anybody, so onboarding normally wants this.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl invite org "Armley Community Hub" --approved
	 *
	 * @subcommand org
	 * @when after_wp_load
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function org( array $args, array $assoc ): void {
		$name = trim( (string) $args[0] );

		if ( '' === $name ) {
			WP_CLI::error( 'Give the organisation a name.' );
		}

		$existing = self::org_by_name( $name );

		if ( null !== $existing ) {
			WP_CLI::error( sprintf( '"%s" already exists, as post %d.', $name, $existing ) );
		}

		$taken = \DGL\Org\Duplicates::hard( \DGL\Org\Duplicates::find( [ 'name' => $name ], Org::duplicate_candidates() ) );

		if ( [] !== $taken ) {
			WP_CLI::error( sprintf( '"%s" is the same organisation as "%s", post %d, once "The", "Ltd" and the like are set aside.', $name, $taken[0]['name'], $taken[0]['id'] ) );
		}

		$id = wp_insert_post(
			[
				'post_type'   => PostTypes::ORG,
				'post_title'  => $name,
				'post_status' => 'publish',
			],
			true
		);

		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}

		update_post_meta( (int) $id, Meta::ORG_STATUS, isset( $assoc['approved'] ) ? Meta::ORG_APPROVED : Meta::ORG_PENDING );

		WP_CLI::success( sprintf(
			'%s created as post %d, %s.',
			$name,
			(int) $id,
			isset( $assoc['approved'] ) ? 'verified' : 'awaiting verification'
		) );
	}

	/* ------------------------------------------------------------------ */

	private static function resolve_org( string $given ): int {
		$given = trim( $given );

		if ( '' === $given ) {
			WP_CLI::error( 'Say which organisation with --org=<id or name>.' );
		}

		if ( ctype_digit( $given ) && Org::exists( (int) $given ) ) {
			return (int) $given;
		}

		$id = self::org_by_name( $given );

		if ( null === $id ) {
			WP_CLI::error( sprintf( 'No organisation matches "%s". Create one with `wp dgl invite org "<name>" --approved`.', $given ) );
		}

		return $id;
	}

	/**
	 * An organisation by its exact title, or null.
	 *
	 * Not get_page_by_title(): that has lived in deprecated.php since
	 * WordPress 6.2 and this plugin runs on 7.1. WP_Query's `title`
	 * argument is the replacement core recommends, and it is an exact
	 * match, which is what a command-line lookup should be.
	 */
	private static function org_by_name( string $name ): ?int {
		$found = get_posts(
			[
				'post_type'      => PostTypes::ORG,
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'title'          => $name,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		return [] === $found ? null : (int) $found[0];
	}

	private static function resolve_actor( ?string $given ): int {
		if ( null !== $given && '' !== $given ) {
			$user = get_user_by( 'id', (int) $given )
				?: get_user_by( 'login', $given )
				?: get_user_by( 'email', $given );

			if ( ! $user instanceof \WP_User ) {
				WP_CLI::error( sprintf( 'No account matches "%s".', $given ) );
			}

			return (int) $user->ID;
		}

		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ] );

		if ( [] === $admins ) {
			WP_CLI::error( 'No administrator to send as. Pass --as=<user>.' );
		}

		return (int) $admins[0];
	}
}

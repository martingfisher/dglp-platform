<?php
/**
 * Per-organisation trust: what goes live without a reviewer reading it.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Org;

use DGL\Access\Access;
use DGL\Access\Policy;
use DGL\Audit\Log;
use DGL\Dashboard\Router;
use DGL\Email\Mailer;
use DGL\Email\OrgCopy;
use DGL\Meta;
use DGL\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * At a few thousand members the bottleneck in this system is human reviewers,
 * not the database. Trust is the release valve: a proven organisation stops
 * consuming moderator time, while a new one is read in full.
 *
 * Trust is decided per organisation by the review team, from the Admin
 * area: a master switch, one switch per content type and one for edits to
 * already approved items (see TrustSettings). Every change is audit logged
 * and the organisation's owners are emailed. A rejection or a take-down
 * switches everything off automatically.
 *
 * Trust never bypasses account approval, organisation verification, upload
 * validation or bot checks. It only decides whether a moderator reads the
 * item before the public does. An unverified organisation's settings are
 * kept but not applied.
 *
 * The old single level (0, 1, 2) survives as a derived value because the
 * index table stores it and old rows may still carry it.
 */
final class Trust {

	/** Legacy level: every submission and every edit is reviewed. */
	public const MODERATED = 0;

	/** Legacy level: new items reviewed, edits go live. */
	public const TRUSTED_EDITS = 1;

	/** Legacy level: everything goes live. */
	public const TRUSTED = 2;

	/**
	 * Coerce a stored legacy value into a valid level, failing closed.
	 */
	public static function normalise( mixed $level ): int {
		$level = is_numeric( $level ) ? (int) $level : self::MODERATED;

		return in_array( $level, [ self::MODERATED, self::TRUSTED_EDITS, self::TRUSTED ], true ) ? $level : self::MODERATED;
	}

	/**
	 * The legacy level in words, for old audit rows and the index.
	 */
	public static function label( int $level ): string {
		return match ( self::normalise( $level ) ) {
			self::TRUSTED_EDITS => __( 'Trusted for edits', 'dgl-platform' ),
			self::TRUSTED       => __( 'Trusted', 'dgl-platform' ),
			default             => __( 'Moderated', 'dgl-platform' ),
		};
	}

	/**
	 * The content types a switch can be set for: post type => plural label.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		$labels = [];

		foreach ( PostTypes::enabled() as $post_type => $def ) {
			$labels[ $post_type ] = $def['plural'];
		}

		return $labels;
	}

	/**
	 * What is stored for the organisation, whether or not it applies.
	 * Falls back to the legacy level when the new meta has never been written.
	 */
	public static function stored( int $org_id ): TrustSettings {
		if ( $org_id <= 0 ) {
			return TrustSettings::off();
		}

		$raw = get_post_meta( $org_id, Meta::ORG_TRUST_SETTINGS, true );

		if ( ! is_array( $raw ) ) {
			$raw = self::normalise( get_post_meta( $org_id, Meta::ORG_TRUST, true ) );
		}

		return TrustSettings::from_meta( $raw, PostTypes::enabled_keys() );
	}

	/**
	 * What applies right now: everything off unless the organisation is
	 * verified, so trust never outlives verification.
	 */
	public static function settings( ?int $org_id ): TrustSettings {
		if ( null === $org_id || $org_id <= 0 || ! Org::is_approved( $org_id ) ) {
			return TrustSettings::off();
		}

		return self::stored( $org_id );
	}

	/**
	 * What applies, in words.
	 */
	public static function summary_for( ?int $org_id ): string {
		return self::settings( $org_id )->summary( self::labels() );
	}

	/**
	 * What is stored, in words.
	 */
	public static function stored_summary( int $org_id ): string {
		return self::stored( $org_id )->summary( self::labels() );
	}

	/**
	 * Write the settings without audit or email. For the migration, tests
	 * and callers that log for themselves.
	 */
	public static function set_stored( int $org_id, TrustSettings $settings ): void {
		update_post_meta( $org_id, Meta::ORG_TRUST_SETTINGS, $settings->to_meta() );
		update_post_meta( $org_id, Meta::ORG_TRUST, $settings->legacy_level() );
	}

	/**
	 * A review team member changes the settings. No-op when nothing changed.
	 * Audited, and the organisation's owners are told.
	 */
	public static function set( int $org_id, TrustSettings $new, int $actor_id ): true|\WP_Error {
		if ( ! Org::exists( $org_id ) ) {
			return new \WP_Error( 'dgl_not_found', __( 'That organisation does not exist.', 'dgl-platform' ) );
		}

		if ( ! Access::can( $actor_id, Policy::GRANT_TRUST ) ) {
			return new \WP_Error( 'dgl_not_allowed', __( 'Only the review team can change trust.', 'dgl-platform' ) );
		}

		$labels = self::labels();
		$old    = self::stored( $org_id );
		$new    = TrustSettings::from_meta( $new->to_meta(), array_keys( $labels ) );

		if ( $old->equals( $new ) ) {
			return true;
		}

		self::set_stored( $org_id, $new );

		$was = $old->summary( $labels );
		$now = $new->summary( $labels );

		Log::record( 'trust_changed', 'org', $org_id, $org_id, $now, [ 'trust' => [ $was, $now ] ], $actor_id );

		$to = Org::owner_emails( $org_id );

		if ( [] !== $to ) {
			Mailer::send(
				OrgCopy::trust_changed( (string) get_the_title( $org_id ), $now, Router::url( 'profile', 'signin' ) )->for_recipients( $to )
			);
		}

		return true;
	}

	/**
	 * Switch everything off after a refusal or a take-down. Returns whether
	 * anything was on to switch off.
	 */
	public static function revoke( int $org_id, string $after_action, int $actor_id ): bool {
		if ( $org_id <= 0 || ! self::stored( $org_id )->is_on() ) {
			return false;
		}

		$labels = self::labels();
		$was    = self::stored( $org_id )->summary( $labels );

		self::set_stored( $org_id, TrustSettings::off() );

		Log::record(
			'trust_revoked',
			'org',
			$org_id,
			$org_id,
			/* translators: %s: the action, e.g. "reject". */
			sprintf( __( 'Trust switched off automatically after %s.', 'dgl-platform' ), $after_action ),
			[ 'trust' => [ $was, TrustSettings::off()->summary( $labels ) ] ],
			$actor_id
		);

		return true;
	}

	/**
	 * One-off: give every organisation without the new meta the settings its
	 * old level amounts to. Idempotent. Returns how many were written.
	 */
	public static function migrate_levels(): int {
		$ids = get_posts(
			[
				'post_type'        => PostTypes::ORG,
				'post_status'      => 'any',
				'fields'           => 'ids',
				'numberposts'      => -1,
				'suppress_filters' => true,
				'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off migration.
					[
						'key'     => Meta::ORG_TRUST_SETTINGS,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		$known = PostTypes::enabled_keys();
		$done  = 0;

		foreach ( $ids as $id ) {
			$level = self::normalise( get_post_meta( (int) $id, Meta::ORG_TRUST, true ) );
			self::set_stored( (int) $id, TrustSettings::from_legacy( $level, $known ) );
			++$done;
		}

		return $done;
	}
}

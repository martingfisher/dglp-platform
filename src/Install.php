<?php
/**
 * Activation, deactivation and schema migration.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

use DGL\Audit\Table as AuditTable;
use DGL\Dashboard\Router;
use DGL\Index\ItemsTable;
use DGL\Invites\Store as InviteStore;
use DGL\Email\Digest\Store as DigestStore;
use DGL\Joining\Store as SignupStore;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that has to happen once, rather than on every request.
 */
final class Install {

	public const DB_VERSION_OPTION = 'dgl_platform_db_version';

	/** The plugin version the rewrite rules were last built for. */
	public const VERSION_OPTION = 'dgl_platform_version';

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		self::migrate();
		Roles::install();
		self::schedule_expiry();
		self::schedule_digests();

		/*
		 * Everything that owns a rewrite rule has to be registered before the
		 * flush, or the flush writes a rule set with that thing missing from it.
		 *
		 * The dashboard rule was left out of this list once, and the result was
		 * that activation produced a site with every content-type rule present
		 * and no `/dashboard/` rule at all. The member area then 404d on every
		 * route except its own root, which still rendered through a fallback
		 * and so hid the fault.
		 */
		PostTypes::register();
		Taxonomies::register();
		Router::add_rules();
		// The public directory's rules too: activation runs before `init`,
		// so a flush here without them wrote a rule set with no /directory/.
		\DGL\Frontend\Frontend::add_rules();
		flush_rewrite_rules();

		update_option( self::VERSION_OPTION, VERSION, false );
	}

	/**
	 * Runs on deactivation. Deliberately keeps the tables and the roles: turning
	 * the plugin off for ten minutes should not strand every member account or
	 * throw away the audit trail.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Plugin::EXPIRY_HOOK );
		wp_clear_scheduled_hook( Plugin::DIGEST_HOOK );
		flush_rewrite_rules();
	}

	/**
	 * Run the expiry sweep hourly.
	 *
	 * Hourly rather than daily because an event that finished at 15:00 looking
	 * live until midnight is a listing that sends somebody to a closed door.
	 * The sweep is batched and indexed, so an hourly run that finds nothing
	 * costs almost nothing.
	 *
	 * This needs a real system cron behind it. On WordPress's own pseudo-cron a
	 * quiet site simply will not fire it.
	 */
	public static function schedule_expiry(): void {
		if ( ! wp_next_scheduled( Plugin::EXPIRY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Plugin::EXPIRY_HOOK );
		}
	}

	/**
	 * Check for owed digests every hour.
	 *
	 * Hourly for all three cadences, not daily. Due-ness is worked out from
	 * each subscriber's own last send rather than from a calendar rule, so an
	 * hourly check means a run the server missed catches up within the hour
	 * instead of waiting a whole period. A check that finds nothing owed reads
	 * one index and stops.
	 *
	 * This needs a real system cron behind it. On WordPress's pseudo-cron a
	 * quiet site will not fire it, and a digest nobody receives looks exactly
	 * like a digest nobody wanted.
	 */
	public static function schedule_digests(): void {
		if ( ! wp_next_scheduled( Plugin::DIGEST_HOOK ) ) {
			wp_schedule_event( time() + ( 15 * MINUTE_IN_SECONDS ), 'hourly', Plugin::DIGEST_HOOK );
		}
	}

	/**
	 * Make sure the scheduled work is scheduled.
	 *
	 * Uploading a new copy of an already-active plugin does not fire the
	 * activation hook, so nothing that only happens on activation happens at
	 * all. That is how the member area's rewrite rules went missing after a
	 * routine update, and a cron job added in a later version has exactly the
	 * same hole: it would be scheduled on a fresh install and never on an
	 * upgrade, so digests would silently not send on the one site that had been
	 * running longest.
	 *
	 * Both checks read the cron option, which WordPress has already loaded.
	 */
	public static function maybe_schedule(): void {
		self::schedule_expiry();
		self::schedule_digests();
	}

	/**
	 * Bring the schema up to date. Idempotent, and cheap when already current.
	 */
	public static function migrate(): void {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $installed === DB_VERSION ) {
			return;
		}

		ItemsTable::create();
		AuditTable::create();
		InviteStore::create();
		DigestStore::create();
		SignupStore::create();
		self::backfill_event_format();

		update_option( self::DB_VERSION_OPTION, DB_VERSION, false );
	}

	/**
	 * Events from before "Where it happens" existed were all in person, so
	 * they say so. Without this their venue fields would hide behind a
	 * format nobody had chosen.
	 */
	private static function backfill_event_format(): void {
		$ids = get_posts(
			[
				'post_type'      => \DGL\PostTypes::EVENT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => [ [ 'key' => 'dgl_format', 'compare' => 'NOT EXISTS' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, 'dgl_format', 'in_person' );
		}
	}

	/**
	 * Check on every load, so a deploy that bumps the schema does not need a
	 * manual reactivation.
	 */
	public static function maybe_migrate(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) !== DB_VERSION ) {
			self::migrate();
		}
	}

	/**
	 * Rebuild the rewrite rules after a version change.
	 *
	 * Uploading a new copy of a plugin that is already active does not fire the
	 * activation hook, so nothing reflushes the rewrite rules. Every route under
	 * `/dashboard/` then 404s until somebody thinks to deactivate and reactivate
	 * the plugin, and the member area appears to have vanished after a routine
	 * update. This was found by doing exactly that.
	 *
	 * Runs on `init` rather than `admin_init`, because the routes it repairs are
	 * on the front end and nobody should have to open wp-admin to fix them. The
	 * flush is expensive, so it happens once per deployed version and then never
	 * again until the next one.
	 */
	public static function maybe_flush_rewrites(): void {
		$stale = get_option( self::VERSION_OPTION, '' ) !== VERSION;

		/*
		 * A version stamp alone is not enough, and trusting it made a real
		 * outage worse: a faulty activation wrote rules with the dashboard rule
		 * missing, stamped the version, and thereby switched off the very
		 * repair that would have fixed it.
		 *
		 * So the check is the invariant itself. If the member area's rule is not
		 * in the stored rule set, the rules are wrong whatever the stamp says.
		 */
		if ( ! $stale && self::rules_look_right() ) {
			return;
		}

		/*
		 * Written before the flush, not after. A fatal inside flush_rewrite_rules
		 * would otherwise mean this runs again on every single request, and an
		 * expensive repair on a loop is worse than the thing it repairs.
		 */
		update_option( self::VERSION_OPTION, VERSION, false );

		flush_rewrite_rules();
	}

	/**
	 * Whether the stored rewrite rules still contain the member area.
	 *
	 * Reads the option rather than the live `$wp_rewrite` object, because what
	 * matters is what was saved, not what this request happens to have built in
	 * memory.
	 */
	public static function rules_look_right(): bool {
		// Plain permalinks store no rules at all. Nothing to repair, and
		// flushing on every request would be the worse bug.
		if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
			return true;
		}

		$rules = get_option( 'rewrite_rules' );

		if ( ! is_array( $rules ) || [] === $rules ) {
			return false;
		}

		$needle = '^' . Router::base();

		foreach ( array_keys( $rules ) as $pattern ) {
			if ( str_starts_with( (string) $pattern, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}

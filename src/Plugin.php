<?php
/**
 * Bootstrap.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL;

use DGL\Access\Access;
use DGL\Admin\Admin;
use DGL\Admin\Guard;
use DGL\Dashboard\AdminLockout;
use DGL\Dashboard\Router;
use DGL\Email\Command as MailCommand;
use DGL\Email\Mailer;
use DGL\Invites\Invites;
use DGL\Invites\Command as InviteCommand;
use DGL\Email\Digest\Frequency;
use DGL\Email\Digest\Runner as DigestRunner;
use DGL\Email\Digest\Command as DigestCommand;
use DGL\Index\Sync;
use DGL\Frontend\Frontend;
use DGL\Index\Command as IndexCommand;
use DGL\Workflow\Revisions;
use DGL\Workflow\Transition;
use DGL\Schema\FieldRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 *
 * Registration order matters: post types, then statuses, then taxonomies, so
 * that statuses and terms attach to types that already exist.
 */
final class Plugin {

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'init', [ self::class, 'register_content' ], 5 );
		add_action( 'init', [ FieldRegistry::class, 'register_meta' ], 6 );
		add_action( 'init', [ self::class, 'load_textdomain' ] );
		add_action( 'admin_init', [ Install::class, 'maybe_migrate' ] );

		/*
		 * On `init`, not `admin_init`. Cron fires on the front end, and a site
		 * whose administrator has not opened wp-admin since the upgrade would
		 * otherwise never schedule anything.
		 */
		add_action( 'init', [ Install::class, 'maybe_schedule' ], 20 );

		/*
		 * After the post types and rewrite rules are registered, so the flush
		 * rebuilds from the complete set. Priority 99 on `init`, not
		 * `admin_init`: the routes this repairs are on the front end.
		 */
		add_action( 'init', [ Install::class, 'maybe_flush_rewrites' ], 99 );

		Access::init();
		Sync::init();
		Router::init();
		AdminLockout::init();
		Admin::init();
		Frontend::init();

		/*
		 * Not inside Admin::init(). A status can be changed from WP-CLI, from a
		 * REST call or from another plugin's code, none of which are wp-admin, and
		 * an audit trail that only watches one surface is an audit trail with
		 * holes in it.
		 */
		Guard::init();
		Revisions::init();
		Mailer::init();
		Invites::init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			MailCommand::register();
			DigestCommand::register();
			IndexCommand::register();
			InviteCommand::register();
		}

		add_action( self::EXPIRY_HOOK, [ Transition::class, 'run_expiry_sweep' ] );
		add_action( self::DIGEST_HOOK, [ self::class, 'run_digests' ] );
	}

	/**
	 * Register post types, statuses and taxonomies.
	 */
	public static function register_content(): void {
		PostTypes::register();
		Statuses::register();
		Taxonomies::register();
	}

	/**
	 * Cron hook name for the expiry sweep.
	 */
	public const EXPIRY_HOOK = 'dgl_run_expiry_sweep';

	/**
	 * Cron hook name for the digest sweep.
	 */
	public const DIGEST_HOOK = 'dgl_send_digests';

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'dgl-platform', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Send whatever digests are owed, across all three cadences.
	 *
	 * One hook rather than three schedules. Each cadence decides for itself
	 * whether anybody is owed anything, so a run where nothing is due costs one
	 * indexed read per cadence.
	 */
	public static function run_digests(): void {
		foreach ( Frequency::all() as $frequency ) {
			DigestRunner::run( $frequency );
		}
	}
}

<?php
/**
 * `wp dgl funding`: the weekly funding import, by hand.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Funding;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * ## EXAMPLES
 *
 *     wp dgl funding token
 *     wp dgl funding public on
 *     wp dgl funding status
 *     wp dgl funding screen
 *     wp dgl funding import run.json --dry-run
 */
final class Command {

	public static function register(): void {
		WP_CLI::add_command( 'dgl funding', self::class );
	}

	/**
	 * Make a new token for the import Worker. The old one stops working.
	 *
	 * Prints the token once. Put it in the Cloudflare Secrets Store as
	 * dgl_funding_token; this site keeps only its hash.
	 *
	 * ## OPTIONS
	 *
	 * [--quiet-token]
	 * : Print the token alone, for piping straight into the Secrets Store.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function token( array $args, array $assoc ): void {
		$token = Endpoint::new_token();

		if ( isset( $assoc['quiet-token'] ) ) {
			WP_CLI::line( $token );

			return;
		}

		WP_CLI::log( 'New token (shown once; the previous one no longer works):' );
		WP_CLI::line( $token );
	}

	/**
	 * Show funding to the public, hold it back, or say which it is.
	 *
	 * While it is held back, only signed-in administrators see /grants/, the
	 * funds' pages and the funding blocks; the weekly import keeps running.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on, off or status.
	 * ---
	 * options:
	 *   - on
	 *   - off
	 *   - status
	 * ---
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function public( array $args, array $assoc ): void {
		$state = (string) ( $args[0] ?? 'status' );

		if ( 'on' === $state || 'off' === $state ) {
			update_option( Visibility::OPTION, 'on' === $state ? '1' : '0', true );
			wp_cache_delete( 'alloptions', 'options' );
		}

		WP_CLI::success( Visibility::is_on() ? 'Funding is ON: the public sees /grants/, the funding blocks and the funds in topics, search and digests.' : 'Funding is OFF: hidden from the public, visible only to signed-in administrators. The weekly import keeps running.' );
	}

	/**
	 * Read a run from a JSON file, exactly as the Worker would post it.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The run, as JSON.
	 *
	 * [--dry-run]
	 * : Say what would happen and change nothing.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function import( array $args, array $assoc ): void {
		$raw     = file_get_contents( (string) $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file named on the command line.
		$payload = is_string( $raw ) ? json_decode( $raw, true ) : null;

		if ( ! is_array( $payload ) ) {
			WP_CLI::error( 'Not a JSON object.' );
		}

		$dry = isset( $assoc['dry-run'] );
		$run = Import::run( $payload, $dry );

		$this->report( $run, $dry );

		foreach ( $run['new'] as $title ) {
			WP_CLI::log( '  + ' . $title );
		}

		$run['ok'] ? WP_CLI::success( $dry ? 'Dry run finished. Nothing was written.' : 'Done.' ) : WP_CLI::error( 'Nothing changed.' );
	}

	/**
	 * Who sees the review team's Grants and Funding screen, as the live code decides it.
	 *
	 * Read only. Lists every administrator and moderator with yes or no.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dgl funding screen
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function screen( array $args, array $assoc ): void {
		$allowed = get_option( Visibility::SCREEN_USERS_OPTION, [] );

		WP_CLI::log( sprintf( 'Funding is %s. %s: %s.', Visibility::is_on() ? 'ON' : 'OFF', Visibility::SCREEN_USERS_OPTION, wp_json_encode( $allowed ) ) );

		$users = get_users(
			[
				'role__in' => [ \DGL\Access\UserContext::ROLE_ADMIN, \DGL\Access\UserContext::ROLE_MODERATOR ],
				'orderby'  => 'ID',
			]
		);

		foreach ( $users as $user ) {
			WP_CLI::log( sprintf( '%-4d %-40s %-14s %s', $user->ID, $user->user_login, implode( ',', $user->roles ), Visibility::screen_visible( (int) $user->ID ) ? 'SEES IT' : 'hidden' ) );
		}
		$me = get_current_user_id();

		if ( $me <= 0 ) {
			WP_CLI::log( 'Add --user=<id> to see what that person gets in the review queue and wp-admin.' );
			return;
		}

		$fund  = get_posts( [ 'post_type' => \DGL\PostTypes::GRANT, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ] );
		$type  = get_post_type_object( \DGL\PostTypes::GRANT );
		$queue = \DGL\Index\ItemsTable::queue( null, 1000 );
		$funds = count( array_filter( $queue, static fn( int $id ): bool => \DGL\PostTypes::GRANT === get_post_type( $id ) ) );

		WP_CLI::log( sprintf( 'As user %d: review queue %d waiting, of which funds %d.', $me, \DGL\Index\ItemsTable::queue_count(), $funds ) );

		$decided = \DGL\Index\ItemsTable::decided( \DGL\Index\ItemsTable::decided_statuses(), 5000 );
		$dfunds  = count( array_filter( $decided, static fn( int $id ): bool => \DGL\PostTypes::GRANT === get_post_type( $id ) ) );

		WP_CLI::log( sprintf( 'As user %d: Approved list %d, of which funds %d.', $me, \DGL\Index\ItemsTable::decided_count( \DGL\Index\ItemsTable::decided_statuses() ), $dfunds ) );
		WP_CLI::log( sprintf( 'As user %d: wp-admin funds screens %s; can edit a fund %s; Settings page %s.', $me, null !== $type && $type->show_ui ? 'yes' : 'no', [] !== $fund && current_user_can( 'edit_post', (int) $fund[0] ) ? 'yes' : 'no', Visibility::hidden_here() ? 'no' : 'yes' ) );
	}

	/**
	 * How the last run went, and what is listed.
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc
	 */
	public function status( array $args, array $assoc ): void {
		$last = Import::last();

		if ( null === $last ) {
			WP_CLI::log( 'Last run: never.' );
		} else {
			WP_CLI::log( sprintf( 'Last run: %s (%s).', wp_date( 'j M Y H:i', (int) $last['at'] ), (string) ( $last['run_id'] ?? '' ) ) );
			$this->report( $last, false );
		}

		WP_CLI::log( sprintf( 'Token saved: %s. New funds go %s.', '' !== (string) get_option( Endpoint::OPTION_TOKEN, '' ) ? 'yes' : 'NO', '1' === (string) get_option( Import::OPTION_AUTOPUBLISH, '1' ) ? 'straight on the site' : 'to the review queue' ) );

		foreach ( [ \DGL\Statuses::PENDING => 'waiting for review', \DGL\Statuses::LIVE => 'live', \DGL\Statuses::EXPIRED => 'expired' ] as $status => $label ) {
			$count = count(
				get_posts(
					[
						'post_type'      => \DGL\PostTypes::GRANT,
						'post_status'    => $status,
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'meta_key'       => Import::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a status command.
					]
				)
			);
			WP_CLI::log( sprintf( 'Imported funds %s: %d', $label, $count ) );
		}
	}

	/**
	 * @param array<string, mixed> $run
	 */
	private function report( array $run, bool $dry ): void {
		WP_CLI::log(
			sprintf(
				'%sSources read: %d of %d. Funds open: %d. New: %d. Updated: %d. Unchanged: %d. Brought back: %d. Withdrawn: %d. Skipped: %d.',
				$dry ? 'DRY RUN. ' : '',
				(int) $run['read'],
				(int) $run['sources'],
				(int) $run['funds'],
				(int) $run['added'],
				(int) $run['updated'],
				(int) $run['unchanged'],
				(int) $run['revived'],
				(int) $run['withdrawn'],
				(int) $run['skipped']
			)
		);

		foreach ( (array) $run['errors'] as $error ) {
			WP_CLI::warning( (string) $error );
		}
	}
}

<?php
/**
 * System health, in wp-admin, in words.
 *
 * Everything the command line can say about whether the site is doing its
 * job, on one screen the review team can read: cron, mail, the index, the
 * queue, the digests, the directory. Each row is green, amber or red and
 * says what to do. Nothing here changes anything.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Admin;

use DateTimeImmutable;
use DGL\Email\Routing;
use DGL\Index\ItemsTable;
use DGL\Plugin;
use DGL\PostTypes;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

final class Health {

	public const SLUG = 'dgl-health';

	/** When the hourly hooks last actually ran, stamped by us at priority 1. */
	public const OPTION_LAST_SWEEP  = 'dgl_health_last_sweep';
	public const OPTION_LAST_DIGEST = 'dgl_health_last_digest';

	public const GOOD = 'good';
	public const WARN = 'warn';
	public const BAD  = 'bad';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
	}

	/**
	 * Stamp the hourly hooks as they run. Registered from Plugin, not from
	 * the admin-only branch, because cron is never an admin request.
	 */
	public static function watch(): void {
		add_action( Plugin::EXPIRY_HOOK, static fn() => update_option( self::OPTION_LAST_SWEEP, time(), false ), 1 );
		add_action( Plugin::DIGEST_HOOK, static fn() => update_option( self::OPTION_LAST_DIGEST, time(), false ), 1 );
	}

	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostTypes::ORG,
			__( 'System health', 'dgl-platform' ),
			__( 'System health', 'dgl-platform' ),
			'manage_options',
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Every check, as rows of [status, what, value, and what to do].
	 *
	 * @return array<int, array{status:string, group:string, what:string, value:string, todo:string}>
	 */
	public static function rows(): array {
		$rows = [];
		$now  = time();

		// ---- Versions.
		$schema = (int) get_option( \DGL\Install::DB_VERSION_OPTION, 0 );
		$rows[] = [
			'status' => $schema === \DGL\DB_VERSION ? self::GOOD : self::BAD,
			'group'  => __( 'Plugin', 'dgl-platform' ),
			'what'   => __( 'Version and schema', 'dgl-platform' ),
			/* translators: 1: plugin version, 2: schema version. */
			'value'  => sprintf( __( 'Plugin %1$s, schema %2$d', 'dgl-platform' ), \DGL\VERSION, $schema ),
			'todo'   => $schema === \DGL\DB_VERSION ? '' : sprintf( __( 'Schema should be %d. Load any page to migrate, or reactivate the plugin.', 'dgl-platform' ), \DGL\DB_VERSION ),
		];

		// ---- Cron.
		$wp_cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$rows[]      = [
			'status' => $wp_cron_off ? self::GOOD : self::WARN,
			'group'  => __( 'Cron', 'dgl-platform' ),
			'what'   => __( 'How cron runs', 'dgl-platform' ),
			'value'  => $wp_cron_off
				? __( 'Server cron (DISABLE_WP_CRON is set), which runs on time whether or not anybody visits.', 'dgl-platform' )
				: __( 'Visitor-triggered WP-Cron. Hourly jobs only run when somebody loads a page, so a quiet site drifts.', 'dgl-platform' ),
			'todo'   => $wp_cron_off ? '' : __( 'Enable system cron in Wordify and add DISABLE_WP_CRON to wp-config.php.', 'dgl-platform' ),
		];

		foreach ( [
			[ Plugin::EXPIRY_HOOK, self::OPTION_LAST_SWEEP, __( 'Hourly sweep', 'dgl-platform' ), __( 'expires dated items, rolls repeating events forward, sends the "still running?" and "still current?" reminders', 'dgl-platform' ) ],
			[ Plugin::DIGEST_HOOK, self::OPTION_LAST_DIGEST, __( 'Digests', 'dgl-platform' ), __( 'sends the daily, weekly and monthly emails that are due', 'dgl-platform' ) ],
		] as [ $hook, $option, $label, $does ] ) {
			$next = wp_next_scheduled( $hook );
			$last = (int) get_option( $option, 0 );

			if ( ! $next ) {
				$status = self::BAD;
				$todo   = __( 'Not scheduled. Reactivate the plugin.', 'dgl-platform' );
			} elseif ( $last > 0 && $now - $last > 3 * HOUR_IN_SECONDS ) {
				$status = self::BAD;
				$todo   = __( 'Scheduled but has not run for over three hours. Cron is not firing.', 'dgl-platform' );
			} elseif ( 0 === $last && $next < $now - HOUR_IN_SECONDS ) {
				$status = self::BAD;
				$todo   = __( 'Scheduled, overdue, and has never been seen to run. Cron is not firing.', 'dgl-platform' );
			} elseif ( $last > 0 && $now - $last > 90 * MINUTE_IN_SECONDS ) {
				$status = self::WARN;
				$todo   = __( 'Running late. Watch it.', 'dgl-platform' );
			} else {
				$status = self::GOOD;
				$todo   = '';
			}

			$rows[] = [
				'status' => $status,
				'group'  => __( 'Cron', 'dgl-platform' ),
				'what'   => $label,
				'value'  => sprintf(
					/* translators: 1: what the job does, 2: last run, 3: next run. */
					__( 'It %1$s. Last ran %2$s. Next due %3$s.', 'dgl-platform' ),
					$does,
					$last > 0 ? self::ago( $last ) : __( 'never (as far as this page has seen)', 'dgl-platform' ),
					$next ? self::in( (int) $next ) : __( 'never', 'dgl-platform' )
				),
				'todo'   => $todo,
			];
		}

		// ---- Mail.
		$redirect = Routing::configured_redirect();
		$rows[]   = [
			'status' => Routing::is_enabled() ? self::GOOD : self::BAD,
			'group'  => __( 'Email', 'dgl-platform' ),
			'what'   => __( 'Sending', 'dgl-platform' ),
			'value'  => Routing::is_enabled() ? __( 'On.', 'dgl-platform' ) : __( 'Off. No email of any kind is sent: not approvals, not reminders, not invitations.', 'dgl-platform' ),
			'todo'   => Routing::is_enabled() ? '' : __( 'Set DGL_MAIL_ENABLED in wp-config.php, or run: wp option update dgl_mail_enabled 1', 'dgl-platform' ),
		];
		$rows[] = [
			'status' => '' === $redirect ? self::GOOD : self::WARN,
			'group'  => __( 'Email', 'dgl-platform' ),
			'what'   => __( 'Redirect', 'dgl-platform' ),
			'value'  => '' === $redirect
				? __( 'None. Email goes to the real recipients.', 'dgl-platform' )
				/* translators: %s: an email address. */
				: sprintf( __( 'Every email goes to %s and nowhere else. Right for staging, wrong for a live site.', 'dgl-platform' ), $redirect ),
			'todo'   => '' === $redirect ? '' : __( 'Remove DGL_MAIL_REDIRECT from wp-config.php before launch.', 'dgl-platform' ),
		];
		$from   = Routing::from_address();
		$rows[] = [
			'status' => '' !== $from ? self::GOOD : self::WARN,
			'group'  => __( 'Email', 'dgl-platform' ),
			'what'   => __( 'From address', 'dgl-platform' ),
			'value'  => '' !== $from ? $from : __( 'The WordPress default (wordpress@ this domain), which some inboxes distrust.', 'dgl-platform' ),
			'todo'   => '' !== $from ? '' : __( 'Set it with: wp option update dgl_mail_from partnership@doinggoodleeds.org.uk', 'dgl-platform' ),
		];

		// ---- Index.
		global $wpdb;
		$drift = [];
		foreach ( PostTypes::submittable() as $type ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$indexed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ItemsTable::name() . ' WHERE post_type = %s', $type ) );
			$in      = "'" . implode( "','", array_map( 'esc_sql', Statuses::all() ) ) . "'";
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$in})", $type ) );
			if ( $indexed !== $posts ) {
				$drift[] = sprintf( '%s %d/%d', $type, $indexed, $posts );
			}
		}
		$rows[] = [
			'status' => [] === $drift ? self::GOOD : self::BAD,
			'group'  => __( 'Data', 'dgl-platform' ),
			'what'   => __( 'Listings index', 'dgl-platform' ),
			'value'  => [] === $drift ? __( 'In step with the posts table.', 'dgl-platform' ) : __( 'Out of step: ', 'dgl-platform' ) . implode( ', ', $drift ),
			'todo'   => [] === $drift ? '' : __( 'Run: wp dgl reindex', 'dgl-platform' ),
		];

		// ---- Queue.
		$queue   = ItemsTable::queue_count();
		$oldest  = ItemsTable::queue( null, 1 );
		$waiting = 0;
		if ( [] !== $oldest ) {
			$since   = (string) get_post_meta( $oldest[0], \DGL\Meta::ITEM_SUBMITTED_AT, true );
			$waiting = '' !== $since ? (int) floor( ( $now - strtotime( $since . ' UTC' ) ) / DAY_IN_SECONDS ) : 0;
		}
		$orgs   = count( \DGL\Org\Profile::awaiting_review() );
		$joins  = \DGL\Joining\Store::awaiting_count();
		$rows[] = [
			'status' => $waiting > 7 ? self::WARN : self::GOOD,
			'group'  => __( 'Review', 'dgl-platform' ),
			'what'   => __( 'Waiting for a decision', 'dgl-platform' ),
			'value'  => sprintf(
				/* translators: 1: submissions, 2: organisation changes, 3: new organisations, 4: days. */
				__( '%1$d submissions, %2$d organisation changes, %3$d new organisations. The oldest submission has waited %4$d days.', 'dgl-platform' ),
				$queue,
				$orgs,
				$joins,
				$waiting
			),
			'todo'   => $waiting > 7 ? __( 'Somebody should look at the queue.', 'dgl-platform' ) : '',
		];

		// ---- Reminders due.
		$wall   = new DateTimeImmutable( 'now', wp_timezone() );
		$due    = ItemsTable::expiring_between( $wall->format( 'Y-m-d H:i:s' ), $wall->modify( '+14 days' )->format( 'Y-m-d H:i:s' ), 500 );
		$rows[] = [
			'status' => self::GOOD,
			'group'  => __( 'Listings', 'dgl-platform' ),
			'what'   => __( 'Coming off in the next fortnight', 'dgl-platform' ),
			/* translators: %d: a count. */
			'value'  => sprintf( _n( '%d live listing. Repeating events and news stories among them are asked whether to stay.', '%d live listings. Repeating events and news stories among them are asked whether to stay.', count( $due ), 'dgl-platform' ), count( $due ) ),
			'todo'   => '',
		];

		// ---- Digests.
		if ( \DGL\Email\Digest\Store::exists() ) {
			$table = \DGL\Email\Digest\Store::name();
			$parts = [];
			$utc   = new DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
			foreach ( \DGL\Email\Digest\Frequency::all() as $frequency ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
				$total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE frequency = %s AND consent_at IS NOT NULL", $frequency ) );
				$parts[] = sprintf( '%s %d (%d due)', \DGL\Email\Digest\Frequency::label( $frequency ), $total, count( \DGL\Email\Digest\Store::due( $frequency, $utc ) ) );
			}
			$rows[] = [
				'status' => self::GOOD,
				'group'  => __( 'Email', 'dgl-platform' ),
				'what'   => __( 'Digest subscribers', 'dgl-platform' ),
				'value'  => implode( ', ', $parts ) . '.',
				'todo'   => '',
			];
		}

		// ---- Directory.
		$total_orgs = (int) wp_count_posts( PostTypes::ORG )->publish;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$listed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'", \DGL\Meta::ORG_IN_DIRECTORY ) );
		$rows[] = [
			'status' => self::GOOD,
			'group'  => __( 'Directory', 'dgl-platform' ),
			'what'   => __( 'Organisations', 'dgl-platform' ),
			/* translators: 1: organisations, 2: listed. */
			'value'  => sprintf( __( '%1$d organisations, %2$d listed in the public directory.', 'dgl-platform' ), $total_orgs, $listed ),
			'todo'   => '',
		];

		// ---- Page cache: cannot be read from here, so it is a reminder.
		$rows[] = [
			'status' => self::WARN,
			'group'  => __( 'Hosting', 'dgl-platform' ),
			'what'   => __( 'Page cache exclusions', 'dgl-platform' ),
			'value'  => __( 'Not readable from WordPress. The host must exclude /dashboard, /wp-login.php, /wp-admin, /wp-json, /directory and /events/calendar from its page cache.', 'dgl-platform' ),
			'todo'   => __( 'Check in Wordify under the site\'s cache settings.', 'dgl-platform' ),
		];

		return $rows;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to see this.', 'dgl-platform' ) );
		}

		$rows   = self::rows();
		$counts = array_count_values( array_column( $rows, 'status' ) );
		$labels = [
			self::GOOD => __( 'Fine', 'dgl-platform' ),
			self::WARN => __( 'Look', 'dgl-platform' ),
			self::BAD  => __( 'Broken', 'dgl-platform' ),
		];
		?>
		<div class="wrap dgl-health">
			<h1><?php esc_html_e( 'System health', 'dgl-platform' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: 1: broken count, 2: look count, 3: fine count. */
					esc_html__( 'Checked just now: %1$d broken, %2$d to look at, %3$d fine. Nothing on this page changes anything.', 'dgl-platform' ),
					(int) ( $counts[ self::BAD ] ?? 0 ),
					(int) ( $counts[ self::WARN ] ?? 0 ),
					(int) ( $counts[ self::GOOD ] ?? 0 )
				);
				?>
			</p>
			<style>
				.dgl-health table { border-collapse: collapse; background: #fff; max-width: 1100px; }
				.dgl-health th, .dgl-health td { text-align: left; vertical-align: top; padding: 10px 12px; border-bottom: 1px solid #dcdcde; }
				.dgl-health th { background: #f6f7f7; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
				.dgl-health__state { white-space: nowrap; font-weight: 600; }
				.dgl-health__state::before { content: ""; display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 8px; vertical-align: middle; }
				.dgl-health__state--good::before { background: #2f6a1d; }
				.dgl-health__state--warn::before { background: #b26b00; }
				.dgl-health__state--bad::before { background: #b32d2e; }
				.dgl-health__todo { color: #1d2327; font-weight: 600; }
			</style>
			<table>
				<thead>
					<tr>
						<th><?php esc_html_e( 'State', 'dgl-platform' ); ?></th>
						<th><?php esc_html_e( 'Area', 'dgl-platform' ); ?></th>
						<th><?php esc_html_e( 'Check', 'dgl-platform' ); ?></th>
						<th><?php esc_html_e( 'What it found', 'dgl-platform' ); ?></th>
						<th><?php esc_html_e( 'What to do', 'dgl-platform' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td class="dgl-health__state dgl-health__state--<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $labels[ $row['status'] ] ); ?></td>
							<td><?php echo esc_html( $row['group'] ); ?></td>
							<td><?php echo esc_html( $row['what'] ); ?></td>
							<td><?php echo esc_html( $row['value'] ); ?></td>
							<td class="dgl-health__todo"><?php echo esc_html( $row['todo'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'The same checks from the command line: wp dgl mail status, wp dgl digest status, wp dgl index status, wp dgl series status <id>.', 'dgl-platform' ); ?></p>
		</div>
		<?php
	}

	private static function ago( int $ts ): string {
		/* translators: %s: a duration like "12 mins". */
		return sprintf( __( '%s ago', 'dgl-platform' ), human_time_diff( $ts, time() ) );
	}

	private static function in( int $ts ): string {
		if ( $ts <= time() ) {
			/* translators: %s: a duration like "12 mins". */
			return sprintf( __( '%s ago (overdue)', 'dgl-platform' ), human_time_diff( $ts, time() ) );
		}

		/* translators: %s: a duration like "12 mins". */
		return sprintf( __( 'in %s', 'dgl-platform' ), human_time_diff( time(), $ts ) );
	}
}

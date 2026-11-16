<?php
/**
 * Settings, Grants and Funding: the one switch, in wp-admin.
 *
 * @package DGL
 */

declare( strict_types=1 );

namespace DGL\Funding;

defined( 'ABSPATH' ) || exit;

/**
 * One button that shows funding to the public or holds it back, beside how
 * the weekly import last went, so the team can switch it on without the
 * command line. The same as `wp dgl funding public on|off`.
 */
final class Settings {

	public const SLUG   = 'dgl-funding';
	public const ACTION = 'dgl_funding_public';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'save' ] );
	}

	public static function menu(): void {
		add_options_page(
			__( 'Grants and Funding', 'dgl-platform' ),
			__( 'Grants and Funding', 'dgl-platform' ),
			'manage_options',
			self::SLUG,
			[ self::class, 'screen' ]
		);
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'dgl-platform' ) );
		}

		check_admin_referer( self::ACTION );

		$on = isset( $_POST['public'] ) && '1' === sanitize_key( wp_unslash( (string) $_POST['public'] ) );
		update_option( Visibility::OPTION, $on ? '1' : '0', true );

		\DGL\Audit\Log::record(
			$on ? 'funding_shown' : 'funding_hidden',
			'setting',
			0,
			0,
			$on ? __( 'Funding shown to the public.', 'dgl-platform' ) : __( 'Funding hidden from the public.', 'dgl-platform' ),
			[],
			get_current_user_id()
		);

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'saved' => $on ? 'on' : 'off' ], admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$on    = Visibility::is_on();
		$last  = Import::last();
		$count = static fn( string $status ): int => count(
			get_posts(
				[
					'post_type'      => \DGL\PostTypes::GRANT,
					'post_status'    => $status,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			)
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only notice after a redirect.
		$saved = isset( $_GET['saved'] ) ? sanitize_key( wp_unslash( (string) $_GET['saved'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Grants and Funding', 'dgl-platform' ); ?></h1>

			<?php if ( '' !== $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php echo esc_html( 'on' === $saved ? __( 'Funding is now shown to the public.', 'dgl-platform' ) : __( 'Funding is now hidden from the public.', 'dgl-platform' ) ); ?>
						<?php esc_html_e( 'If a page still looks the same to a visitor, clear the page cache (Hosting Tools > Clear Smart Cache).', 'dgl-platform' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width:720px">
				<h2><?php echo esc_html( $on ? __( 'Shown to the public', 'dgl-platform' ) : __( 'Hidden from the public', 'dgl-platform' ) ); ?></h2>
				<p>
					<?php
					echo esc_html(
						$on
							? __( 'Everyone can see the funding list, each fund\'s page, the funding blocks on the home page and the funding support page, and funds in topic pages, search and the weekly round-up.', 'dgl-platform' )
							: __( 'Only signed-in administrators can see funding, marked as a preview. Visitors get "not found" for the funding list and fund pages, and the funding blocks and feeds are left off every page. The weekly import keeps running, so the list is up to date when you switch it on.', 'dgl-platform' )
					);
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( \DGL\Frontend\Frontend::archive_url( \DGL\PostTypes::GRANT ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the funding list', 'dgl-platform' ); ?></a>
					&nbsp;·&nbsp;
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the home page', 'dgl-platform' ); ?></a>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="public" value="<?php echo $on ? '0' : '1'; ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<?php
					submit_button(
						$on ? __( 'Hide funding from the public', 'dgl-platform' ) : __( 'Show funding to the public', 'dgl-platform' ),
						$on ? 'secondary' : 'primary',
						'submit',
						false
					);
					?>
				</form>
			</div>

			<div class="card" style="max-width:720px">
				<h2><?php esc_html_e( 'The weekly import', 'dgl-platform' ); ?></h2>
				<p><?php esc_html_e( 'Every Monday morning, funders\' own pages are read and the list brought up to date: new funds added, amounts and deadlines refreshed, and funds that have gone from a funder\'s page taken off.', 'dgl-platform' ); ?></p>
				<table class="widefat striped" style="max-width:680px">
					<tbody>
						<tr><th scope="row"><?php esc_html_e( 'Last run', 'dgl-platform' ); ?></th><td><?php echo esc_html( null === $last ? __( 'Never', 'dgl-platform' ) : wp_date( 'j M Y, H:i', (int) $last['at'] ) ); ?></td></tr>
						<?php if ( null !== $last ) : ?>
							<tr><th scope="row"><?php esc_html_e( 'Funders\' pages read', 'dgl-platform' ); ?></th><td><?php echo esc_html( sprintf( '%d of %d', (int) $last['read'], (int) $last['sources'] ) ); ?></td></tr>
							<tr><th scope="row"><?php esc_html_e( 'New / updated / taken off', 'dgl-platform' ); ?></th><td><?php echo esc_html( sprintf( '%d / %d / %d', (int) $last['added'], (int) $last['updated'], (int) $last['withdrawn'] ) ); ?></td></tr>
						<?php endif; ?>
						<tr><th scope="row"><?php esc_html_e( 'Funds listed now', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) $count( \DGL\Statuses::LIVE ) ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Waiting for review', 'dgl-platform' ); ?></th><td><?php echo esc_html( (string) $count( \DGL\Statuses::PENDING ) ); ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'New funds go', 'dgl-platform' ); ?></th><td><?php echo esc_html( '1' === (string) get_option( Import::OPTION_AUTOPUBLISH, '1' ) ? __( 'straight onto the list', 'dgl-platform' ) : __( 'to the review queue first', 'dgl-platform' ) ); ?></td></tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}

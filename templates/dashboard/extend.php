<?php
/**
 * The page at the end of the "is this still running?" link.
 *
 * No sign-in: the link carries its own single-use token. One confirm
 * button, because a GET that changed anything would be spent by the first
 * mail scanner to follow it.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$state = (string) ( $data['state'] ?? 'invalid' );
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Keep it listed', 'dgl-platform' ); ?></h1>
	</div>
</header>

<section class="dgl-card dgl-extend">
	<?php if ( 'done' === $state ) : ?>

		<div class="dgl-alert dgl-alert--good" role="status">
			<p><strong><?php esc_html_e( 'Done.', 'dgl-platform' ); ?></strong>
			<?php
			printf(
				/* translators: 1: event title, 2: a date. */
				esc_html__( '%1$s stays on the site until %2$s. We will ask again two weeks before then.', 'dgl-platform' ),
				esc_html( (string) ( $data['title'] ?? '' ) ),
				esc_html( (string) ( $data['until'] ?? '' ) )
			);
			?></p>
		</div>

		<p><?php esc_html_e( 'To change the days or times, open the event in your dashboard.', 'dgl-platform' ); ?></p>

	<?php elseif ( 'confirm' === $state ) : ?>

		<p class="dgl-extend__lead">
			<?php
			printf(
				/* translators: 1: event title, 2: a date. */
				esc_html__( '%1$s is listed until %2$s.', 'dgl-platform' ),
				'<strong>' . esc_html( (string) ( $data['title'] ?? '' ) ) . '</strong>',
				esc_html( (string) ( $data['until'] ?? '' ) )
			);
			?>
		</p>
		<p>
			<?php
			printf(
				! empty( $data['is_series'] )
					/* translators: %s: a date. */
					? esc_html__( 'Still running? One click keeps it listed until %s. Nothing else changes and nothing goes through review.', 'dgl-platform' )
					/* translators: %s: a date. */
					: esc_html__( 'Still current? One click keeps it listed until %s. Nothing else changes and nothing goes through review.', 'dgl-platform' ),
				esc_html( (string) ( $data['new_until'] ?? '' ) )
			);
			?>
		</p>

		<form method="post" class="dgl-inline-form">
			<?php wp_nonce_field( 'dgl_extend_' . (int) ( $data['post_id'] ?? 0 ) ); ?>
			<button type="submit" class="dgl-button"><?php echo ! empty( $data['is_series'] ) ? esc_html__( 'Yes, still running: keep it listed', 'dgl-platform' ) : esc_html__( 'Yes, still current: keep it listed', 'dgl-platform' ); ?></button>
		</form>

		<p class="dgl-help"><?php echo ! empty( $data['is_series'] ) ? esc_html__( 'If it has stopped, close this page. It comes off the site on its last date and stays in your dashboard.', 'dgl-platform' ) : esc_html__( 'If it has had its day, close this page. It comes off the site on its last date and stays in your dashboard.', 'dgl-platform' ); ?></p>

	<?php else : ?>

		<div class="dgl-alert" role="alert">
			<p><?php echo esc_html( (string) ( $data['error'] ?? '' ) ); ?></p>
		</div>

	<?php endif; ?>

	<p>
		<a class="dgl-button dgl-button--secondary" href="<?php echo esc_url( (string) ( $data['item_url'] ?? '' ) ); ?>">
			<?php esc_html_e( 'Open it in the dashboard', 'dgl-platform' ); ?>
		</a>
	</p>
</section>

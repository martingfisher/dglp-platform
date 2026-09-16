<?php
/**
 * The screen at the end of the unsubscribe link in a digest.
 *
 * Reachable with no account and no session. It does the thing and says so;
 * there is no confirmation step, because somebody who has clicked "stop
 * emailing me" has already decided, and asking again is how a message gets
 * marked as spam instead.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$done = (bool) ( $data['done'] ?? false );
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Email preferences', 'dgl-platform' ); ?></h1>
	</div>
</header>

<section class="dgl-card">
	<?php if ( $done ) : ?>

		<div class="dgl-alert dgl-alert--good">
			<p><?php echo esc_html( (string) ( $data['message'] ?? '' ) ); ?></p>
		</div>

		<p>
			<?php esc_html_e( 'If you change your mind, the digest can be switched back on under Email preferences in your profile.', 'dgl-platform' ); ?>
		</p>

	<?php else : ?>

		<div class="dgl-alert">
			<p><?php echo esc_html( (string) ( $data['error'] ?? '' ) ); ?></p>
		</div>

	<?php endif; ?>

	<p>
		<a class="dgl-button" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php esc_html_e( 'Go to the website', 'dgl-platform' ); ?>
		</a>
	</p>
</section>

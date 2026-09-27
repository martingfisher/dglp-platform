<?php
/**
 * The screen at the end of the unsubscribe link in a digest.
 *
 * Reachable with no account and no session. A visit shows one button and
 * the button does it. Not zero buttons: email scanners open every link in a
 * message, and a page that unsubscribes on being opened unsubscribes people
 * who never asked. Not more than one: somebody who clicked "stop emailing
 * me" has decided.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$done    = (bool) ( $data['done'] ?? false );
$confirm = (bool) ( $data['confirm'] ?? false );
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Email preferences', 'dgl-platform' ); ?></h1>
	</div>
</header>

<section class="dgl-card">
	<?php if ( $done ) : ?>

		<div class="dgl-alert dgl-alert--good" role="status">
			<p><?php echo esc_html( (string) ( $data['message'] ?? '' ) ); ?></p>
		</div>

		<p>
			<?php esc_html_e( 'If you change your mind, the round-up can be switched back on under Email preferences in your profile.', 'dgl-platform' ); ?>
		</p>

	<?php elseif ( $confirm ) : ?>

		<p>
			<?php
			printf(
				/* translators: %s: email address. */
				esc_html__( 'Stop the round-up emails to %s? Your account and your listings are untouched, and you can switch it back on from your profile.', 'dgl-platform' ),
				esc_html( (string) ( $data['email'] ?? '' ) )
			);
			?>
		</p>

		<form method="post" class="dgl-form dgl-form--bare">
			<button class="dgl-button" type="submit" name="dgl_unsubscribe" value="1"><?php esc_html_e( 'Stop these emails', 'dgl-platform' ); ?></button>
		</form>

	<?php else : ?>

		<div class="dgl-alert" role="alert">
			<p><?php echo esc_html( (string) ( $data['error'] ?? '' ) ); ?></p>
		</div>

	<?php endif; ?>

	<?php if ( ! $confirm ) : ?>
		<p>
			<a class="dgl-button<?php echo $done ? ' dgl-button--secondary' : ''; ?>" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php esc_html_e( 'Go to the website', 'dgl-platform' ); ?>
			</a>
		</p>
	<?php endif; ?>
</section>

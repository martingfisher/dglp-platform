<?php
/**
 * Accepting an invitation to join an organisation.
 *
 * The one screen in the member area a stranger is meant to reach, so it
 * assumes nothing: no account, no session, no idea what the partnership is.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Wizard;

defined( 'ABSPATH' ) || exit;

$invite      = $data['invite'] ?? null;
$error       = (string) ( $data['error'] ?? '' );
$org_name    = (string) ( $data['org_name'] ?? '' );
$inviter     = (string) ( $data['inviter'] ?? '' );
$role_name   = (string) ( $data['role_name'] ?? '' );
$has_account = (bool) ( $data['has_account'] ?? false );
$expires_on  = (string) ( $data['expires_on'] ?? '' );
$token       = (string) ( $data['token'] ?? '' );
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Your invitation', 'dgl-platform' ); ?></h1>
	</div>
</header>

<?php if ( null === $invite || ( '' !== $error && '' === $token ) ) : ?>

	<section class="dgl-card">
		<p class="dgl-notice dgl-notice--bad"><?php echo esc_html( '' !== $error ? $error : __( 'That invitation link is not valid.', 'dgl-platform' ) ); ?></p>
		<p>
			<a class="dgl-button" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php esc_html_e( 'Go to the website', 'dgl-platform' ); ?>
			</a>
		</p>
	</section>

<?php else : ?>

	<section class="dgl-card">
		<h2 class="dgl-section__title">
			<?php
			printf(
				/* translators: %s: organisation name. */
				esc_html__( 'Join %s', 'dgl-platform' ),
				esc_html( $org_name )
			);
			?>
		</h2>

		<p>
			<?php
			if ( '' !== $inviter ) {
				printf(
					/* translators: 1: person's name, 2: organisation name. */
					esc_html__( '%1$s invited you to help manage %2$s on this site.', 'dgl-platform' ),
					esc_html( $inviter ),
					esc_html( $org_name )
				);
			} else {
				printf(
					/* translators: %s: organisation name. */
					esc_html__( 'You have been invited to help manage %s on this site.', 'dgl-platform' ),
					esc_html( $org_name )
				);
			}
			?>
		</p>

		<dl class="dgl-facts">
			<dt><?php esc_html_e( 'Your email', 'dgl-platform' ); ?></dt>
			<dd><?php echo esc_html( $invite->email ); ?></dd>
			<dt><?php esc_html_e( 'You would be', 'dgl-platform' ); ?></dt>
			<dd><?php echo esc_html( $role_name ); ?></dd>
			<?php if ( '' !== $expires_on ) : ?>
				<dt><?php esc_html_e( 'This link works until', 'dgl-platform' ); ?></dt>
				<dd><?php echo esc_html( $expires_on ); ?></dd>
			<?php endif; ?>
		</dl>

		<?php if ( '' !== $error ) : ?>
			<p class="dgl-notice dgl-notice--bad"><?php echo esc_html( $error ); ?></p>
		<?php endif; ?>

		<form method="post" class="dgl-form">
			<?php wp_nonce_field( Wizard::NONCE ); ?>

			<?php if ( $has_account ) : ?>

				<p>
					<?php esc_html_e( 'You already have an account with this address, so there is nothing to set up. Accepting links it to the organisation.', 'dgl-platform' ); ?>
				</p>

			<?php else : ?>

				<div class="dgl-field">
					<label class="dgl-field__label" for="dgl_name"><?php esc_html_e( 'Your name', 'dgl-platform' ); ?></label>
					<input type="text" id="dgl_name" name="dgl_name" class="dgl-input" autocomplete="name">
					<p class="dgl-help"><?php esc_html_e( 'How your colleagues and the partnership team will see you.', 'dgl-platform' ); ?></p>
				</div>

				<div class="dgl-field">
					<label class="dgl-field__label" for="dgl_password"><?php esc_html_e( 'Choose a password', 'dgl-platform' ); ?></label>
					<input type="password" id="dgl_password" name="dgl_password" class="dgl-input" required minlength="12" autocomplete="new-password">
					<p class="dgl-help"><?php esc_html_e( 'At least 12 characters. A few words you will remember beats something short and clever.', 'dgl-platform' ); ?></p>
				</div>

			<?php endif; ?>

			<button type="submit" class="dgl-button dgl-button--primary"><?php esc_html_e( 'Accept and sign in', 'dgl-platform' ); ?></button>
		</form>

		<p class="dgl-help">
			<?php esc_html_e( 'If you were not expecting this, close the page. Nothing has been created in your name and the invitation expires on its own.', 'dgl-platform' ); ?>
		</p>
	</section>

<?php endif; ?>

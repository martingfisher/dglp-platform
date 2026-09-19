<?php
/**
 * Joining, in stages: an address, a sent message, then the outcome of the
 * link: an organisation offered, or a new one to register.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\Wizard;
use DGL\Invites\Rules as InviteRules;

defined( 'ABSPATH' ) || exit;

$stage  = (string) ( $data['stage'] ?? 'email' );
$error  = (string) ( $data['error'] ?? '' );
$values = (array) ( $data['values'] ?? [] );
$orgs   = (array) ( $data['orgs'] ?? [] );
$token  = (string) ( $data['token'] ?? '' );
$v      = static fn( string $k ): string => (string) ( $values[ $k ] ?? '' );
?>
<div class="dgl-signin">
	<header class="dgl-page-head">
		<div>
			<h1 class="dgl-page-head__title"><?php esc_html_e( 'Join the member area', 'dgl-platform' ); ?></h1>
			<?php if ( 'email' === $stage ) : ?>
				<p class="dgl-page-head__lede"><?php esc_html_e( 'For people at organisations in the Doing Good Leeds Partnership. Start with your work email address: it is how we tell which organisation you are part of.', 'dgl-platform' ); ?></p>
			<?php endif; ?>
		</div>
	</header>

	<?php if ( '' !== $error ) : ?>
		<div class="dgl-alert" role="alert"><p><?php echo esc_html( $error ); ?></p></div>
	<?php endif; ?>

	<?php if ( 'exists' === $stage ) : ?>
		<div class="dgl-card dgl-join__exists">
			<h2 class="dgl-section__title"><?php esc_html_e( 'You already have an account', 'dgl-platform' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: an email address. */
					esc_html__( 'There is already a member account for %s, so there is nothing to join. Sign in with it, or send yourself a link to set a new password.', 'dgl-platform' ),
					'<strong>' . esc_html( (string) ( $data['email'] ?? '' ) ) . '</strong>'
				);
				?>
			</p>
			<div class="dgl-form__actions">
				<a class="dgl-button" href="<?php echo esc_url( (string) ( $data['signin_url'] ?? Router::url() ) ); ?>"><?php esc_html_e( 'Sign in', 'dgl-platform' ); ?></a>
				<a class="dgl-button dgl-button--secondary" href="<?php echo esc_url( (string) ( $data['reset_url'] ?? wp_lostpassword_url() ) ); ?>"><?php esc_html_e( 'Set a new password', 'dgl-platform' ); ?></a>
			</div>
			<p class="dgl-help"><?php esc_html_e( 'Not you? Somebody else at your organisation may have joined with this address. Ask them, or use a different work address.', 'dgl-platform' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( 'email' === $stage ) : ?>
		<form method="post" class="dgl-form" action="<?php echo esc_url( Router::url( 'join' ) ); ?>">
			<?php wp_nonce_field( Wizard::NONCE ); ?>
			<input type="hidden" name="<?php echo esc_attr( \DGL\Joining\Guard::STAMP ); ?>" value="<?php echo esc_attr( (string) ( $data['stamp'] ?? '' ) ); ?>">
			<?php /* Never shown, never announced; a robot that fills every field fills this one. */ ?>
			<div class="dgl-nohoney" aria-hidden="true">
				<label for="<?php echo esc_attr( \DGL\Joining\Guard::HONEYPOT ); ?>"><?php esc_html_e( 'Leave this empty', 'dgl-platform' ); ?></label>
				<input type="text" id="<?php echo esc_attr( \DGL\Joining\Guard::HONEYPOT ); ?>" name="<?php echo esc_attr( \DGL\Joining\Guard::HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off">
			</div>
			<div class="dgl-field-row">
				<label class="dgl-label" for="dgl_email"><?php esc_html_e( 'Your work email address', 'dgl-platform' ); ?> <span class="dgl-req" aria-hidden="true">*</span></label>
				<input class="dgl-field" type="email" id="dgl_email" name="dgl_email" required autocomplete="email" value="<?php echo esc_attr( (string) ( $data['email'] ?? '' ) ); ?>">
				<p class="dgl-help"><?php esc_html_e( 'We send a link to confirm it is yours. Nothing is created until you use it.', 'dgl-platform' ); ?></p>
			</div>
			<button class="dgl-button" type="submit"><?php esc_html_e( 'Send me the link', 'dgl-platform' ); ?></button>
		</form>
		<p class="dgl-signin__foot">
			<?php esc_html_e( 'Already a member?', 'dgl-platform' ); ?>
			<a href="<?php echo esc_url( Router::url() ); ?>"><?php esc_html_e( 'Sign in', 'dgl-platform' ); ?></a>
		</p>

	<?php elseif ( 'sent' === $stage ) : ?>
		<div class="dgl-card">
			<h2 class="dgl-section__title"><?php esc_html_e( 'Check your email', 'dgl-platform' ); ?></h2>
			<p><?php esc_html_e( 'We have sent you a link. Use it and you can carry on. It works once, and for two days: after that you will need to start again and request another.', 'dgl-platform' ); ?></p>
			<p class="dgl-help"><?php esc_html_e( 'Nothing arrived? Check your junk folder, then start again and a fresh link will be sent. Only the newest link works.', 'dgl-platform' ); ?></p>
		</div>

	<?php elseif ( 'signed-in' === $stage ) : ?>
		<div class="dgl-card">
			<h2 class="dgl-section__title"><?php esc_html_e( 'You are already signed in', 'dgl-platform' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: name of the signed-in account. */
					esc_html__( 'You are signed in as %s. Joining creates a new account, so sign out first and the link will carry on where it left off.', 'dgl-platform' ),
					'<strong>' . esc_html( (string) ( $data['signed_in'] ?? '' ) ) . '</strong>'
				);
				?>
			</p>
			<p><a class="dgl-button" href="<?php echo esc_url( (string) ( $data['logout_url'] ?? '' ) ); ?>"><?php esc_html_e( 'Sign out and carry on joining', 'dgl-platform' ); ?></a></p>
			<p class="dgl-help"><?php esc_html_e( 'If you meant to use this account, ignore the link and go back to your dashboard.', 'dgl-platform' ); ?></p>
		</div>

	<?php elseif ( 'dead' === $stage ) : ?>
		<p><a class="dgl-button dgl-button--secondary" href="<?php echo esc_url( Router::url( 'join' ) ); ?>"><?php esc_html_e( 'Start again', 'dgl-platform' ); ?></a></p>

	<?php else : ?>
		<form method="post" class="dgl-form" action="<?php echo esc_url( Router::url( 'join', $token ) ); ?>">
			<?php wp_nonce_field( Wizard::NONCE ); ?>

			<p>
				<?php
				printf(
					/* translators: %s: email address. */
					esc_html__( 'Your address is confirmed: %s. That is what you will sign in with.', 'dgl-platform' ),
					'<strong>' . esc_html( (string) ( $data['email'] ?? '' ) ) . '</strong>'
				);
				?>
			</p>

			<?php if ( 'match' === $stage ) : ?>
				<input type="hidden" name="dgl_intent" value="join">
				<fieldset class="dgl-field-row dgl-field-row--group">
					<legend class="dgl-label"><?php echo 1 === count( $orgs ) ? esc_html__( 'Your organisation', 'dgl-platform' ) : esc_html__( 'Which organisation are you part of?', 'dgl-platform' ); ?></legend>
					<?php foreach ( $orgs as $i => $org ) : ?>
						<label class="dgl-check">
							<input type="radio" name="dgl_org_id" value="<?php echo esc_attr( (string) $org['id'] ); ?>" <?php checked( 0 === $i ); ?>>
							<span><strong><?php echo esc_html( $org['name'] ); ?></strong></span>
						</label>
					<?php endforeach; ?>
					<p class="dgl-help"><?php esc_html_e( 'Your email address is on this organisation\'s domain, which is how we know. You will be able to post for it straight away.', 'dgl-platform' ); ?></p>
				</fieldset>

			<?php else : ?>
				<input type="hidden" name="dgl_intent" value="register">
				<div class="dgl-alert dgl-alert--edit" role="status">
					<p><strong><?php esc_html_e( 'Your email address does not match an organisation on our list.', 'dgl-platform' ); ?></strong>
					<?php esc_html_e( 'If your organisation is already a member, ask a colleague there to invite you from their Members page. Otherwise register it here. The DGLP team check every new organisation before it can post; you can draft in the meantime.', 'dgl-platform' ); ?></p>
				</div>

				<h2 class="dgl-section__title"><?php esc_html_e( 'Your organisation', 'dgl-platform' ); ?></h2>

				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_org_name"><?php esc_html_e( 'Organisation name', 'dgl-platform' ); ?> <span class="dgl-req" aria-hidden="true">*</span></label>
					<input class="dgl-field" type="text" id="dgl_org_name" name="dgl_org_name" required maxlength="120" value="<?php echo esc_attr( $v( 'org_name' ) ); ?>">
					<p class="dgl-help"><?php esc_html_e( 'The full name, as it appears on your charity or company record.', 'dgl-platform' ); ?></p>
				</div>
				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_org_website"><?php esc_html_e( 'Website', 'dgl-platform' ); ?></label>
					<input class="dgl-field" type="url" id="dgl_org_website" name="dgl_org_website" value="<?php echo esc_attr( $v( 'org_website' ) ); ?>">
				</div>
				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_org_number"><?php esc_html_e( 'Charity or company number', 'dgl-platform' ); ?></label>
					<input class="dgl-field" type="text" id="dgl_org_number" name="dgl_org_number" maxlength="40" value="<?php echo esc_attr( $v( 'org_number' ) ); ?>">
					<p class="dgl-help"><?php esc_html_e( 'If you have one. It is the quickest way for the team to verify you.', 'dgl-platform' ); ?></p>
				</div>
				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_org_email"><?php esc_html_e( 'Public contact email', 'dgl-platform' ); ?> <span class="dgl-req" aria-hidden="true">*</span></label>
					<input class="dgl-field" type="email" id="dgl_org_email" name="dgl_org_email" required value="<?php echo esc_attr( '' !== $v( 'org_email' ) ? $v( 'org_email' ) : (string) ( $data['email'] ?? '' ) ); ?>">
					<p class="dgl-help"><?php esc_html_e( 'Shown on your listings so people can get in touch.', 'dgl-platform' ); ?></p>
				</div>
				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_org_description"><?php esc_html_e( 'What you do', 'dgl-platform' ); ?></label>
					<textarea class="dgl-field dgl-field--area" id="dgl_org_description" name="dgl_org_description" rows="3" maxlength="400"><?php echo esc_textarea( $v( 'org_description' ) ); ?></textarea>
					<p class="dgl-help"><?php esc_html_e( 'Two or three sentences. Shown on your listings.', 'dgl-platform' ); ?></p>
				</div>

				<h2 class="dgl-section__title"><?php esc_html_e( 'You', 'dgl-platform' ); ?></h2>
			<?php endif; ?>

			<div class="dgl-field-row">
				<label class="dgl-label" for="dgl_name"><?php esc_html_e( 'Your name', 'dgl-platform' ); ?> <span class="dgl-req" aria-hidden="true">*</span></label>
				<input class="dgl-field" type="text" id="dgl_name" name="dgl_name" required autocomplete="name" value="<?php echo esc_attr( $v( 'name' ) ); ?>">
				<p class="dgl-help"><?php esc_html_e( 'Shown to the review team and your colleagues. You do not sign in with this.', 'dgl-platform' ); ?></p>
			</div>
			<div class="dgl-field-row">
				<label class="dgl-label" for="dgl_password"><?php esc_html_e( 'Choose a password', 'dgl-platform' ); ?> <span class="dgl-req" aria-hidden="true">*</span></label>
				<input class="dgl-field" type="password" id="dgl_password" name="dgl_password" required minlength="<?php echo (int) InviteRules::PASSWORD_MIN; ?>" autocomplete="new-password">
				<p class="dgl-help">
					<?php
					printf(
						/* translators: %d: minimum length. */
						esc_html__( 'At least %d characters.', 'dgl-platform' ),
						(int) InviteRules::PASSWORD_MIN
					);
					?>
				</p>
			</div>
			<div class="dgl-field-row">
				<label class="dgl-label" for="dgl_password_confirm"><?php esc_html_e( 'Type it again', 'dgl-platform' ); ?> <span class="dgl-req" aria-hidden="true">*</span></label>
				<input class="dgl-field" type="password" id="dgl_password_confirm" name="dgl_password_confirm" required minlength="<?php echo (int) InviteRules::PASSWORD_MIN; ?>" autocomplete="new-password">
			</div>

			<button class="dgl-button" type="submit">
				<?php echo 'match' === $stage ? esc_html__( 'Join and sign in', 'dgl-platform' ) : esc_html__( 'Register and sign in', 'dgl-platform' ); ?>
			</button>
		</form>
	<?php endif; ?>
</div>

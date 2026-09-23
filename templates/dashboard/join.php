<?php
/**
 * Joining, in stages: an address, a sent message, then the outcome of the
 * link: the organisation the domain matched, one picked from the list, or a
 * new one to register, checked against the list first.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\Wizard;
use DGL\Invites\Rules as InviteRules;
use DGL\Joining\Rules as JoinRules;
use DGL\Meta;
use DGL\Org\Duplicates;

defined( 'ABSPATH' ) || exit;

$stage    = (string) ( $data['stage'] ?? 'email' );
$check    = (string) ( $data['check'] ?? '' );
$error    = (string) ( $data['error'] ?? '' );
$values   = (array) ( $data['values'] ?? [] );
$orgs     = (array) ( $data['orgs'] ?? [] );
$pickable = (array) ( $data['pickable'] ?? [] );
$matches  = (array) ( $data['matches'] ?? [] );
$intent   = (string) ( $data['intent'] ?? '' );
$token    = (string) ( $data['token'] ?? '' );
$v        = static fn( string $k ): string => (string) ( $values[ $k ] ?? '' );

$reasons_of = static function ( array $match ): string {
	$words = array_map( [ Duplicates::class, 'reason_label' ], (array) ( $match['reasons'] ?? [] ) );

	return implode( ', ', array_unique( $words ) );
};
?>
<div class="dgl-signin">
	<header class="dgl-page-head">
		<div>
			<h1 class="dgl-page-head__title"><?php esc_html_e( 'Join the user area', 'dgl-platform' ); ?></h1>
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
		<form method="post" class="dgl-form dgl-join" action="<?php echo esc_url( Router::url( 'join', $token ) ); ?>" data-dgl-matches="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-dgl-token="<?php echo esc_attr( $token ); ?>">
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

			<?php if ( 'blocked' === $check && [] !== $matches ) : ?>
				<div class="dgl-alert dgl-alert--warn dgl-join__blocked" role="alert">
					<p>
						<?php
						printf(
							/* translators: 1: organisation, 2: why it matched. */
							esc_html__( 'That looks like %1$s, which is already on the list (%2$s). Join it instead and the DGLP team will check you are part of it.', 'dgl-platform' ),
							'<strong>' . esc_html( (string) $matches[0]['name'] ) . '</strong>',
							esc_html( $reasons_of( $matches[0] ) )
						);
						?>
					</p>
					<div class="dgl-alert__actions">
						<?php foreach ( $matches as $match ) : ?>
							<button class="dgl-button" type="submit" name="dgl_take" value="<?php echo esc_attr( (string) $match['id'] ); ?>">
								<?php
								printf(
									/* translators: %s: organisation. */
									esc_html__( 'Join %s instead', 'dgl-platform' ),
									esc_html( (string) $match['name'] )
								);
								?>
							</button>
						<?php endforeach; ?>
					</div>
					<p class="dgl-help"><?php esc_html_e( 'Not your organisation? Correct what you typed below: a wrong website is the usual cause. If it still will not go through, contact the DGLP team through the main website and they can sort it out.', 'dgl-platform' ); ?></p>
				</div>
			<?php endif; ?>

			<fieldset class="dgl-field-row dgl-field-row--group dgl-intent">
				<legend class="dgl-label"><?php esc_html_e( 'Which organisation are you part of?', 'dgl-platform' ); ?></legend>

				<?php if ( 'match' === $stage && [] !== $orgs ) : ?>
					<?php if ( 1 === count( $orgs ) ) : ?>
						<label class="dgl-check">
							<input type="radio" name="dgl_intent" value="join" <?php checked( 'join', $intent ); ?>>
							<span>
								<strong><?php echo esc_html( (string) $orgs[0]['name'] ); ?></strong>
								<span class="dgl-help"><?php esc_html_e( 'Your email address is on this organisation\'s domain, which is how we know. You will be able to post for it straight away.', 'dgl-platform' ); ?></span>
							</span>
						</label>
						<input type="hidden" name="dgl_org_id" value="<?php echo esc_attr( (string) $orgs[0]['id'] ); ?>">
					<?php else : ?>
						<label class="dgl-check">
							<input type="radio" name="dgl_intent" value="join" <?php checked( 'join', $intent ); ?>>
							<span>
								<strong><?php esc_html_e( 'One of the organisations my email address matches', 'dgl-platform' ); ?></strong>
								<span class="dgl-help"><?php esc_html_e( 'Your email address is on their domain, which is how we know. You will be able to post straight away.', 'dgl-platform' ); ?></span>
							</span>
						</label>
						<div class="dgl-intent__sub" data-dgl-when="join">
							<?php foreach ( $orgs as $i => $org ) : ?>
								<label class="dgl-check">
									<input type="radio" name="dgl_org_id" value="<?php echo esc_attr( (string) $org['id'] ); ?>" <?php checked( 0 === $i ); ?>>
									<span><?php echo esc_html( (string) $org['name'] ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<label class="dgl-check">
					<input type="radio" name="dgl_intent" value="claim" <?php checked( 'claim', $intent ); ?>>
					<span>
						<strong><?php echo 'match' === $stage ? esc_html__( 'A different organisation on the list', 'dgl-platform' ) : esc_html__( 'An organisation on the list', 'dgl-platform' ); ?></strong>
						<span class="dgl-help"><?php esc_html_e( 'Find it below. The DGLP team check you are part of it before you can post; you can draft in the meantime.', 'dgl-platform' ); ?></span>
					</span>
				</label>
				<label class="dgl-check">
					<input type="radio" name="dgl_intent" value="register" <?php checked( 'register', $intent ); ?>>
					<span>
						<strong><?php esc_html_e( 'An organisation that is not on the list yet', 'dgl-platform' ); ?></strong>
						<span class="dgl-help"><?php esc_html_e( 'Register it below. The DGLP team check every new organisation before it can post.', 'dgl-platform' ); ?></span>
					</span>
				</label>
			</fieldset>

			<div class="dgl-join__block" data-dgl-when="claim">
				<h2 class="dgl-section__title"><?php esc_html_e( 'Find your organisation', 'dgl-platform' ); ?></h2>
				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_claim_org"><?php esc_html_e( 'Organisation', 'dgl-platform' ); ?></label>
					<div class="dgl-picker" data-dgl-picker>
						<select class="dgl-field" id="dgl_claim_org" name="dgl_claim_org">
							<option value=""><?php esc_html_e( 'Choose from the list', 'dgl-platform' ); ?></option>
							<?php foreach ( $pickable as $org_id => $org ) : ?>
								<option value="<?php echo esc_attr( (string) $org_id ); ?>" <?php selected( (string) $org_id, $v( 'claim_org' ) ); ?> <?php echo Meta::ORG_PENDING === $org['status'] ? 'data-pending="1"' : ''; ?>>
									<?php
									echo esc_html( (string) $org['name'] );

									if ( Meta::ORG_PENDING === $org['status'] ) {
										echo ' ' . esc_html__( '(awaiting verification)', 'dgl-platform' );
									}
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<p class="dgl-help"><?php esc_html_e( 'Start typing the name. An organisation marked "awaiting verification" has registered but the team have not checked it yet.', 'dgl-platform' ); ?></p>
				</div>
				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_claim_note"><?php esc_html_e( 'How are you connected to it?', 'dgl-platform' ); ?></label>
					<textarea class="dgl-field dgl-field--area" id="dgl_claim_note" name="dgl_claim_note" rows="2" maxlength="<?php echo (int) JoinRules::NOTE_MAX; ?>"><?php echo esc_textarea( $v( 'claim_note' ) ); ?></textarea>
					<p class="dgl-help"><?php esc_html_e( 'Optional. A job title, or a line about what you do there, helps the team check quickly.', 'dgl-platform' ); ?></p>
				</div>
			</div>

			<div class="dgl-join__block" data-dgl-when="register">
				<h2 class="dgl-section__title"><?php esc_html_e( 'Register your organisation', 'dgl-platform' ); ?></h2>
				<p class="dgl-help"><?php esc_html_e( 'Only if it is not on the list. The DGLP team check every new organisation before it can post; you can draft in the meantime. We check what you type against the list, so the same organisation is not listed twice.', 'dgl-platform' ); ?></p>

				<?php if ( 'confirm' === $check && [] !== $matches ) : ?>
					<div class="dgl-matches" role="status">
						<h3 class="dgl-matches__title"><?php esc_html_e( 'Is it one of these?', 'dgl-platform' ); ?></h3>
						<p><?php esc_html_e( 'These organisations on the list look like the one you typed. If one of them is yours, join it instead and the DGLP team will check you are part of it.', 'dgl-platform' ); ?></p>
						<ul class="dgl-matches__list">
							<?php foreach ( $matches as $match ) : ?>
								<li class="dgl-match">
									<div class="dgl-match__who">
										<strong><?php echo esc_html( (string) $match['name'] ); ?></strong>
										<?php if ( Meta::ORG_PENDING === ( $match['status'] ?? '' ) ) : ?>
											<span class="dgl-match__status"><?php esc_html_e( 'awaiting verification', 'dgl-platform' ); ?></span>
										<?php endif; ?>
										<span class="dgl-match__why"><?php echo esc_html( $reasons_of( $match ) ); ?></span>
									</div>
									<button class="dgl-button dgl-button--secondary dgl-match__take" type="submit" name="dgl_take" value="<?php echo esc_attr( (string) $match['id'] ); ?>"><?php esc_html_e( 'Yes, that is mine', 'dgl-platform' ); ?></button>
								</li>
							<?php endforeach; ?>
						</ul>
						<button class="dgl-button dgl-button--secondary" type="submit" name="dgl_confirmed_new" value="1"><?php esc_html_e( 'No, none of these. Register a new organisation', 'dgl-platform' ); ?></button>
					</div>
				<?php endif; ?>

				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_org_name"><?php esc_html_e( 'Organisation name', 'dgl-platform' ); ?> <span class="dgl-req" aria-hidden="true">*</span></label>
					<input class="dgl-field" type="text" id="dgl_org_name" name="dgl_org_name" maxlength="120" value="<?php echo esc_attr( $v( 'org_name' ) ); ?>">
					<p class="dgl-help"><?php esc_html_e( 'The full name, as it appears on your charity or company record.', 'dgl-platform' ); ?></p>
				</div>
				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_org_website"><?php esc_html_e( 'Website', 'dgl-platform' ); ?></label>
					<input class="dgl-field" type="text" inputmode="url" autocomplete="url" spellcheck="false" placeholder="example.org.uk" id="dgl_org_website" name="dgl_org_website" value="<?php echo esc_attr( $v( 'org_website' ) ); ?>">
				</div>
				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_org_postcode"><?php esc_html_e( 'Postcode', 'dgl-platform' ); ?></label>
					<input class="dgl-field dgl-field--short" type="text" autocomplete="postal-code" id="dgl_org_postcode" name="dgl_org_postcode" maxlength="10" value="<?php echo esc_attr( $v( 'org_postcode' ) ); ?>">
					<p class="dgl-help"><?php esc_html_e( 'Where you are based. It helps the team check you are not already on the list.', 'dgl-platform' ); ?></p>
				</div>
				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_org_number"><?php esc_html_e( 'Charity or company number', 'dgl-platform' ); ?></label>
					<input class="dgl-field" type="text" id="dgl_org_number" name="dgl_org_number" maxlength="40" value="<?php echo esc_attr( $v( 'org_number' ) ); ?>">
					<p class="dgl-help"><?php esc_html_e( 'If you have one. It is the quickest way for the team to verify you.', 'dgl-platform' ); ?></p>
				</div>
				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_org_email"><?php esc_html_e( 'Public contact email', 'dgl-platform' ); ?> <span class="dgl-req" aria-hidden="true">*</span></label>
					<input class="dgl-field" type="email" id="dgl_org_email" name="dgl_org_email" value="<?php echo esc_attr( '' !== $v( 'org_email' ) ? $v( 'org_email' ) : (string) ( $data['email'] ?? '' ) ); ?>">
					<p class="dgl-help"><?php esc_html_e( 'Shown on your listings so people can get in touch.', 'dgl-platform' ); ?></p>
				</div>
				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_org_description"><?php esc_html_e( 'What you do', 'dgl-platform' ); ?></label>
					<textarea class="dgl-field dgl-field--area" id="dgl_org_description" name="dgl_org_description" rows="3" maxlength="400"><?php echo esc_textarea( $v( 'org_description' ) ); ?></textarea>
					<p class="dgl-help"><?php esc_html_e( 'Two or three sentences. Shown on your listings.', 'dgl-platform' ); ?></p>
				</div>
			</div>

			<h2 class="dgl-section__title"><?php esc_html_e( 'You', 'dgl-platform' ); ?></h2>

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

					if ( '' !== $check ) {
						echo ' ' . esc_html__( 'Type it again to finish.', 'dgl-platform' );
					}
					?>
				</p>
			</div>
			<div class="dgl-field-row">
				<label class="dgl-label" for="dgl_password_confirm"><?php esc_html_e( 'Type it again', 'dgl-platform' ); ?> <span class="dgl-req" aria-hidden="true">*</span></label>
				<input class="dgl-field" type="password" id="dgl_password_confirm" name="dgl_password_confirm" required minlength="<?php echo (int) InviteRules::PASSWORD_MIN; ?>" autocomplete="new-password">
			</div>

			<button class="dgl-button" type="submit" data-dgl-working="<?php esc_attr_e( 'Working…', 'dgl-platform' ); ?>"><?php esc_html_e( 'Continue', 'dgl-platform' ); ?></button>
		</form>
	<?php endif; ?>
</div>

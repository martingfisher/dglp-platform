<?php
/**
 * Organisation, person, members and sign-in. Wireframe 1i.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Access\UserContext;
use DGL\Dashboard\FieldRenderer;
use DGL\Dashboard\Router;
use DGL\Dashboard\View;
use DGL\Dashboard\Wizard;
use DGL\Meta;
use DGL\Org\Schema;

defined( 'ABSPATH' ) || exit;

$user     = $data['user'];
$tab      = (string) $data['tab'];
$errors   = $data['errors'] ?? [];
$is_owner = $user instanceof UserContext && $user->is_org_owner();
?>
<header class="dgl-page-head">
	<div>
		<h1 class="dgl-page-head__title"><?php esc_html_e( 'Organisation and profile', 'dgl-platform' ); ?></h1>
		<p class="dgl-page-head__lede">
			<?php esc_html_e( 'Changes to your organisation name or logo are checked by the team before they show on listings. Everything else takes effect straight away.', 'dgl-platform' ); ?>
		</p>
	</div>
</header>

<?php if ( ! empty( $data['saved'] ) ) : ?>
	<div class="dgl-alert dgl-alert--good" role="status">
		<p><?php esc_html_e( 'Saved.', 'dgl-platform' ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! empty( $errors ) ) : ?>
	<div class="dgl-alert" role="alert">
		<p><strong><?php esc_html_e( 'A few things need fixing.', 'dgl-platform' ); ?></strong></p>
		<ul>
			<?php foreach ( $errors as $message ) : ?>
				<li><?php echo esc_html( (string) $message ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<nav class="dgl-tabs" aria-label="<?php esc_attr_e( 'Profile sections', 'dgl-platform' ); ?>">
	<?php foreach ( $data['tabs'] as $key => $label ) : ?>
		<a class="dgl-tab<?php echo $key === $tab ? ' dgl-tab--on' : ''; ?>"
			href="<?php echo esc_url( Router::url( 'profile', (string) $key ) ); ?>"
			<?php echo $key === $tab ? 'aria-current="page"' : ''; ?>>
			<?php echo esc_html( (string) $label ); ?>
			<?php if ( 'members' === $key && ! empty( $data['colleagues'] ) ) : ?>
				<span class="dgl-tab__count"><?php echo esc_html( (string) count( $data['colleagues'] ) ); ?></span>
			<?php endif; ?>
		</a>
	<?php endforeach; ?>
</nav>

<?php if ( 'organisation' === $tab ) : ?>

	<?php if ( ! empty( $data['pending'] ) ) : ?>
		<div class="dgl-alert dgl-alert--edit" role="status">
			<p>
				<strong><?php esc_html_e( 'A change is waiting for the review team.', 'dgl-platform' ); ?></strong>
				<?php esc_html_e( 'Your listings carry your current name and logo until they agree, so nothing has changed on the site.', 'dgl-platform' ); ?>
			</p>
		</div>

		<?php
		View::output(
			'dashboard/changes',
			[
				'changes'       => $data['pending'],
				'changes_title' => __( 'What you have asked to change', 'dgl-platform' ),
				'changes_lede'  => __( 'The rest of your profile saved as normal. Only these two wait for the team.', 'dgl-platform' ),
			]
		);
		?>
	<?php endif; ?>

	<form class="dgl-form dgl-card" method="post" action="<?php echo esc_url( Router::url( 'profile', 'organisation' ) ); ?>" enctype="multipart/form-data">
		<?php wp_nonce_field( Wizard::NONCE ); ?>

		<?php if ( ! $is_owner ) : ?>
			<p class="dgl-help">
				<?php esc_html_e( 'Only an owner can change these. You can still submit content for the organisation.', 'dgl-platform' ); ?>
			</p>
		<?php endif; ?>

		<?php foreach ( $data['fields'] as $field ) : ?>
			<?php
			echo FieldRenderer::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
				$field,
				$data['values'][ $field->key ] ?? '',
				(string) ( $errors[ $field->key ] ?? '' )
			);
			?>
			<?php if ( Schema::needs_approval( $field->key ) ) : ?>
				<p class="dgl-help dgl-help--gated"><?php esc_html_e( 'Changing this goes to the review team first.', 'dgl-platform' ); ?></p>
			<?php endif; ?>
		<?php endforeach; ?>

		<?php if ( $is_owner ) : ?>
			<div class="dgl-form__actions">
				<div class="dgl-form__actions-end">
					<button class="dgl-button" type="submit"><?php esc_html_e( 'Save changes', 'dgl-platform' ); ?></button>
			<?php endif; ?>
	</form>

<?php elseif ( 'you' === $tab ) : ?>

	<form class="dgl-form dgl-card" method="post" action="<?php echo esc_url( Router::url( 'profile', 'you' ) ); ?>">
		<?php wp_nonce_field( Wizard::NONCE ); ?>

		<?php foreach ( $data['person_fields'] as $field ) : ?>
			<?php
			echo FieldRenderer::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
				$field,
				$data['person'][ $field->key ] ?? '',
				(string) ( $errors[ $field->key ] ?? '' )
			);
			?>
		<?php endforeach; ?>

		<p class="dgl-help">
			<?php
			printf(
				/* translators: %s: the member's sign-in email address. */
				esc_html__( 'You sign in as %s. Changing that address is handled separately, because an account whose email can be swapped from a form left open is an account somebody else can take.', 'dgl-platform' ),
				'<strong>' . esc_html( $data['account'] ? (string) $data['account']->user_email : '' ) . '</strong>'
			);
			?>
		</p>

		<div class="dgl-form__actions">
			<div class="dgl-form__actions-end">
				<button class="dgl-button" type="submit"><?php esc_html_e( 'Save changes', 'dgl-platform' ); ?></button>
		</div>
	</form>

<?php elseif ( 'members' === $tab ) : ?>

	<?php /* Ahead of the cards. An outcome the member has to scroll to find is an outcome they miss. */ ?>
	<?php if ( '' !== (string) ( $data['invite_notice'] ?? '' ) ) : ?>
		<div class="dgl-alert dgl-alert--good"><p><?php echo esc_html( (string) $data['invite_notice'] ); ?></p></div>
	<?php endif; ?>

	<?php if ( '' !== (string) ( $data['invite_error'] ?? '' ) ) : ?>
		<div class="dgl-alert"><p><?php echo esc_html( (string) $data['invite_error'] ); ?></p></div>
	<?php endif; ?>

	<section class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'Who can post for us', 'dgl-platform' ); ?></h2>

		<table class="dgl-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'dgl-platform' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email', 'dgl-platform' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Can do', 'dgl-platform' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['colleagues'] as $row ) : ?>
					<tr>
						<td>
							<?php echo esc_html( $row['name'] ); ?>
							<?php if ( $row['is_you'] ) : ?>
								<span class="dgl-edit-flag"><?php esc_html_e( 'You', 'dgl-platform' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['email'] ); ?></td>
						<td>
							<?php echo UserContext::ORG_OWNER === $row['role']
								? esc_html__( 'Everything, including this page', 'dgl-platform' )
								: esc_html__( 'Submit and edit content', 'dgl-platform' ); ?>
							<?php if ( UserContext::ACCOUNT_APPROVED !== $row['status'] && '' !== (string) $row['status'] ) : ?>
								<span class="dgl-edit-flag"><?php echo esc_html( ucfirst( (string) $row['status'] ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="dgl-help">
			<?php esc_html_e( 'Removing somebody is not built yet. Ask the DGLP team and they will do it for you.', 'dgl-platform' ); ?>
		</p>
	</section>

	<?php if ( ! empty( $data['invites'] ) ) : ?>
		<section class="dgl-card">
			<h2 class="dgl-section__title"><?php esc_html_e( 'Invitations', 'dgl-platform' ); ?></h2>

			<table class="dgl-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Email', 'dgl-platform' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Invited as', 'dgl-platform' ); ?></th>
						<th scope="col"><?php esc_html_e( 'State', 'dgl-platform' ); ?></th>
						<?php if ( ! empty( $data['can_invite'] ) ) : ?>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'dgl-platform' ); ?></span></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['invites'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['email'] ); ?></td>
							<td><?php echo esc_html( $row['role'] ); ?></td>
							<td>
								<?php echo esc_html( $row['state_label'] ); ?>
								<?php if ( 'open' === $row['state'] && '' !== $row['expires'] ) : ?>
									<span class="dgl-help">
										<?php
										printf(
											/* translators: %s: date. */
											esc_html__( 'until %s', 'dgl-platform' ),
											esc_html( $row['expires'] )
										);
										?>
									</span>
								<?php endif; ?>
							</td>
							<?php if ( ! empty( $data['can_invite'] ) ) : ?>
								<td class="dgl-row-actions">
									<?php if ( 'open' === $row['state'] ) : ?>
										<form method="post" class="dgl-inline-form">
											<?php wp_nonce_field( \DGL\Dashboard\Wizard::NONCE ); ?>
											<input type="hidden" name="dgl_invite_action" value="revoke">
											<input type="hidden" name="dgl_invite_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
											<button type="submit" class="dgl-button dgl-button--quiet"><?php esc_html_e( 'Withdraw', 'dgl-platform' ); ?></button>
										</form>
										<form method="post" class="dgl-inline-form">
											<?php wp_nonce_field( \DGL\Dashboard\Wizard::NONCE ); ?>
											<input type="hidden" name="dgl_invite_action" value="resend">
											<input type="hidden" name="dgl_invite_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
											<button type="submit" class="dgl-button dgl-button--quiet"><?php esc_html_e( 'Send again', 'dgl-platform' ); ?></button>
										</form>
									<?php endif; ?>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $data['can_invite'] ) ) : ?>
		<section class="dgl-card">
			<h2 class="dgl-section__title"><?php esc_html_e( 'Invite a colleague', 'dgl-platform' ); ?></h2>

			<p class="dgl-help">
				<?php esc_html_e( 'They get an email with a link. Nothing is created in their name until they use it, and the link stops working after fourteen days.', 'dgl-platform' ); ?>
			</p>

			<form method="post" class="dgl-form">
				<?php wp_nonce_field( \DGL\Dashboard\Wizard::NONCE ); ?>
				<input type="hidden" name="dgl_invite_action" value="send">

				<div class="dgl-field-row">
					<label class="dgl-label" for="dgl_invite_email"><?php esc_html_e( 'Their email address', 'dgl-platform' ); ?> <span class="dgl-req" aria-hidden="true">*</span></label>
					<input type="email" id="dgl_invite_email" name="dgl_invite_email" class="dgl-field" required autocomplete="off">
				</div>

				<fieldset class="dgl-field-row dgl-field-row--group">
					<legend class="dgl-label"><?php esc_html_e( 'What they can do', 'dgl-platform' ); ?></legend>

					<label class="dgl-check">
						<input type="radio" name="dgl_invite_role" value="<?php echo esc_attr( UserContext::ORG_CONTRIBUTOR ); ?>" checked>
						<span>
							<strong><?php esc_html_e( 'Contributor', 'dgl-platform' ); ?></strong>
							<span class="dgl-help"><?php esc_html_e( 'Submit and edit content for the organisation.', 'dgl-platform' ); ?></span>
						</span>
					</label>

					<label class="dgl-check">
						<input type="radio" name="dgl_invite_role" value="<?php echo esc_attr( UserContext::ORG_OWNER ); ?>">
						<span>
							<strong><?php esc_html_e( 'Owner', 'dgl-platform' ); ?></strong>
							<span class="dgl-help"><?php esc_html_e( 'Everything a contributor can do, plus editing this page and inviting other people.', 'dgl-platform' ); ?></span>
						</span>
					</label>
				</fieldset>

				<button type="submit" class="dgl-button dgl-button--primary"><?php esc_html_e( 'Send the invitation', 'dgl-platform' ); ?></button>
			</form>
		</section>
	<?php endif; ?>

<?php elseif ( 'signin' === $tab ) : ?>

	<section class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'How you sign in', 'dgl-platform' ); ?></h2>

		<dl class="dgl-review__list">
				<dt><?php esc_html_e( 'Email and password', 'dgl-platform' ); ?></dt>
				<dd>
					<?php esc_html_e( 'In use.', 'dgl-platform' ); ?>
					<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Change your password', 'dgl-platform' ); ?></a>
				</dd>
				<dt><?php esc_html_e( 'Microsoft and Google', 'dgl-platform' ); ?></dt>
				<dd><?php esc_html_e( 'Not available yet. It is planned and not built, so there is nothing here to connect.', 'dgl-platform' ); ?></dd>
				<dt><?php esc_html_e( 'Account status', 'dgl-platform' ); ?></dt>
				<dd>
					<?php
					$status = (string) get_user_meta( $user->user_id, Meta::USER_ACCOUNT_STATUS, true );
					echo esc_html( '' !== $status ? ucfirst( $status ) : __( 'Review team account', 'dgl-platform' ) );
					?>
				</dd>
			<?php if ( null !== $data['org'] ) : ?>
						<dt><?php esc_html_e( 'Organisation', 'dgl-platform' ); ?></dt>
					<dd>
						<?php echo esc_html( ucfirst( (string) $data['org_status'] ) ); ?>.
						<?php echo esc_html( (string) $data['org_trust'] ); ?>.
					</dd>
				<?php endif; ?>
		</dl>

		<p class="dgl-help">
			<?php esc_html_e( 'Closing your account is not built yet. Email the DGLP team and they will close it and tell you what happens to anything you have published.', 'dgl-platform' ); ?>
		</p>
	</section>

<?php else : ?>

	<?php $prefs = $data['email_prefs'] ?? []; ?>

	<?php if ( '' !== (string) ( $data['invite_notice'] ?? '' ) ) : ?>
		<div class="dgl-alert dgl-alert--good"><p><?php echo esc_html( (string) $data['invite_notice'] ); ?></p></div>
	<?php endif; ?>

	<section class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'The digest', 'dgl-platform' ); ?></h2>

		<p>
			<?php esc_html_e( 'A round-up of what other member organisations have posted. Tick what you want to hear about. Tick nothing and we will not send it.', 'dgl-platform' ); ?>
		</p>

		<form method="post" class="dgl-form">
			<?php wp_nonce_field( \DGL\Dashboard\Wizard::NONCE ); ?>
			<input type="hidden" name="dgl_digest_save" value="1">

			<fieldset class="dgl-field-row dgl-field-row--group">
				<legend class="dgl-label"><?php esc_html_e( 'What to include', 'dgl-platform' ); ?></legend>

				<?php foreach ( \DGL\PostTypes::definitions() as $post_type => $definition ) : ?>
					<label class="dgl-check">
						<input
							type="checkbox"
							name="dgl_digest_types[]"
							value="<?php echo esc_attr( $post_type ); ?>"
							<?php checked( in_array( $post_type, (array) ( $prefs['types'] ?? [] ), true ) ); ?>
						>
						<span><?php echo esc_html( (string) ( $definition['plural'] ?? $post_type ) ); ?></span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<?php if ( ! empty( $prefs['all_topics'] ) ) : ?>
				<fieldset class="dgl-field-row dgl-field-row--group">
					<legend class="dgl-label"><?php esc_html_e( 'Topics', 'dgl-platform' ); ?></legend>
					<p class="dgl-help"><?php esc_html_e( 'Leave all of these unticked to hear about every topic.', 'dgl-platform' ); ?></p>

					<?php foreach ( $prefs['all_topics'] as $topic ) : ?>
						<label class="dgl-check">
							<input
								type="checkbox"
								name="dgl_digest_topics[]"
								value="<?php echo esc_attr( (string) $topic->term_id ); ?>"
								<?php checked( in_array( (int) $topic->term_id, array_map( 'intval', (array) ( $prefs['topic_ids'] ?? [] ) ), true ) ); ?>
							>
							<span><?php echo esc_html( $topic->name ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>

			<div class="dgl-field-row">
				<label class="dgl-label" for="dgl_digest_frequency"><?php esc_html_e( 'How often', 'dgl-platform' ); ?></label>
				<select id="dgl_digest_frequency" name="dgl_digest_frequency" class="dgl-field">
					<?php foreach ( (array) ( $prefs['cadences'] ?? [] ) as $value => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( (string) $value, (string) ( $prefs['frequency'] ?? '' ) ); ?>>
							<?php echo esc_html( (string) $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<?php if ( null !== $data['org'] ) : ?>
				<fieldset class="dgl-field-row dgl-field-row--group">
					<legend class="dgl-label"><?php esc_html_e( 'Your own organisation', 'dgl-platform' ); ?></legend>
					<label class="dgl-check">
						<input type="checkbox" name="dgl_digest_own_org" value="1" <?php checked( ! empty( $prefs['own_org'] ) ); ?>>
						<span>
							<?php esc_html_e( 'Include things we posted ourselves', 'dgl-platform' ); ?>
							<span class="dgl-help"><?php esc_html_e( 'Off by default. Nobody needs an email about the thing they posted this morning.', 'dgl-platform' ); ?></span>
						</span>
					</label>
				</fieldset>
			<?php endif; ?>

			<button type="submit" class="dgl-button dgl-button--primary"><?php esc_html_e( 'Save preferences', 'dgl-platform' ); ?></button>
		</form>

		<?php if ( ! empty( $prefs['consent_at'] ) ) : ?>
			<p class="dgl-help">
				<?php
				printf(
					/* translators: %s: date. */
					esc_html__( 'You agreed to the digest on %s.', 'dgl-platform' ),
					esc_html( \DGL\Invites\Invites::readable_date( (string) $prefs['consent_at'] ) )
				);
				?>
				<?php if ( ! empty( $prefs['last_sent'] ) ) : ?>
					<?php
					printf(
						/* translators: %s: date. */
						esc_html__( 'The last one went out on %s.', 'dgl-platform' ),
						esc_html( \DGL\Invites\Invites::readable_date( (string) $prefs['last_sent'] ) )
					);
					?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</section>

	<section class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'Email about your own work', 'dgl-platform' ); ?></h2>
		<p class="dgl-help">
			<?php esc_html_e( 'You still get email about your own submissions: when one arrives with the team, and when they decide. Those are not a newsletter and will not be switched off here.', 'dgl-platform' ); ?>
		</p>
	</section>

<?php endif; ?>

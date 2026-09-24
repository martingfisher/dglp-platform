<?php
/**
 * One organisation, for the review team: trust switches, people, details.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\FieldRenderer;
use DGL\Dashboard\Router;
use DGL\Dashboard\View;
use DGL\Dashboard\Wizard;
use DGL\Org\TrustSettings;

defined( 'ABSPATH' ) || exit;

$settings = $data['settings'] ?? TrustSettings::off();
$labels   = (array) ( $data['labels'] ?? [] );
$members  = (array) ( $data['members'] ?? [] );
$approved = ! empty( $data['approved'] );
$org_id   = (int) ( $data['org_id'] ?? 0 );
$errors   = (array) ( $data['errors'] ?? [] );
$pending  = (array) ( $data['pending'] ?? [] );
?>
<?php if ( 'details' === (string) ( $data['saved'] ?? '' ) ) : ?>
	<div class="dgl-alert dgl-alert--good" role="status">
		<p><?php esc_html_e( 'Details saved.', 'dgl-platform' ); ?></p>
	</div>
<?php elseif ( '' !== (string) ( $data['saved'] ?? '' ) ) : ?>
	<div class="dgl-alert dgl-alert--good" role="status">
		<p><?php esc_html_e( 'Saved. The organisation\'s owners have been emailed.', 'dgl-platform' ); ?></p>
	</div>
<?php endif; ?>

<?php if ( [] !== $errors ) : ?>
	<div class="dgl-alert" role="alert">
		<p><?php esc_html_e( 'The details were not saved. Check the fields marked below.', 'dgl-platform' ); ?></p>
	</div>
<?php endif; ?>

<?php if ( '' !== (string) ( $data['error'] ?? '' ) ) : ?>
	<div class="dgl-alert" role="alert"><p><?php echo esc_html( (string) $data['error'] ); ?></p></div>
<?php endif; ?>

<header class="dgl-page-head">
	<div>
		<p class="dgl-crumbs">
			<a href="<?php echo esc_url( Router::url( 'review' ) ); ?>"><?php esc_html_e( 'Review queue', 'dgl-platform' ); ?></a>
			<span aria-hidden="true">/</span>
			<a href="<?php echo esc_url( Router::url( 'review', 'orgs' ) ); ?>"><?php esc_html_e( 'Organisations', 'dgl-platform' ); ?></a>
		</p>
		<h1 class="dgl-page-head__title"><?php echo esc_html( (string) $data['name'] ); ?></h1>
		<p class="dgl-page-head__lede">
			<?php
			printf(
				/* translators: 1: verification state, 2: trust in words. */
				esc_html__( 'Verification: %1$s. %2$s.', 'dgl-platform' ),
				esc_html( ucfirst( (string) $data['status'] ) ),
				esc_html( (string) $data['summary'] )
			);
			?>
			<?php if ( ! empty( $data['waiting'] ) ) : ?>
				<a href="<?php echo esc_url( Router::url( 'review', 'org', (string) $org_id ) ); ?>"><?php esc_html_e( 'A name or logo change is waiting.', 'dgl-platform' ); ?></a>
			<?php endif; ?>
		</p>
	</div>
</header>

<div class="dgl-detail">
	<div>
		<section class="dgl-card dgl-trust">
			<h2 class="dgl-section__title"><?php esc_html_e( 'Trust', 'dgl-platform' ); ?></h2>
			<p class="dgl-help"><?php esc_html_e( 'Off means everything this organisation submits is read by the review team before it appears. On reveals what may go live straight away. A refusal or a take-down switches everything off again.', 'dgl-platform' ); ?></p>

			<?php if ( ! $approved ) : ?>
				<p class="dgl-alert dgl-alert--warn"><?php esc_html_e( 'This organisation is not verified, so nothing here applies until it is. Verification is set in wp-admin.', 'dgl-platform' ); ?></p>
			<?php endif; ?>

			<form method="post" class="dgl-form dgl-form--bare">
				<?php wp_nonce_field( Wizard::NONCE ); ?>

				<div class="dgl-field-row dgl-field-row--group">
					<label class="dgl-check">
						<input type="checkbox" id="dgl-trust_on" name="dgl_trust_on" value="1" <?php checked( $settings->is_on() ); ?>>
						<span>
							<strong><?php esc_html_e( 'Trust this organisation', 'dgl-platform' ); ?></strong>
							<span class="dgl-help"><?php esc_html_e( 'Nothing changes until you also tick what it is trusted for.', 'dgl-platform' ); ?></span>
						</span>
					</label>
				</div>

				<fieldset class="dgl-field-row dgl-field-row--group dgl-trust__sub" data-dgl-depends="trust_on" data-dgl-depends-on="1">
					<legend class="dgl-label"><?php esc_html_e( 'Goes live without review', 'dgl-platform' ); ?></legend>

					<?php foreach ( $labels as $type => $label ) : ?>
						<label class="dgl-check">
							<input type="checkbox" id="dgl-trust_type_<?php echo esc_attr( (string) $type ); ?>" name="dgl_trust_type[<?php echo esc_attr( (string) $type ); ?>]" value="1" <?php checked( $settings->trusts( (string) $type ) ); ?>>
							<span>
								<strong>
									<?php
									printf(
										/* translators: %s: content type, plural, e.g. "News". */
										esc_html__( 'Trust %s', 'dgl-platform' ),
										esc_html( (string) $label )
									);
									?>
								</strong>
								<span class="dgl-help">
									<?php
									printf(
										/* translators: %s: content type, plural, lower case. */
										esc_html__( 'New %s and edits to them appear on the site as soon as they are submitted.', 'dgl-platform' ),
										esc_html( strtolower( (string) $label ) )
									);
									?>
								</span>
							</span>
						</label>
					<?php endforeach; ?>

					<label class="dgl-check">
						<input type="checkbox" id="dgl-trust_edits" name="dgl_trust_edits" value="1" <?php checked( $settings->trusts_edits() ); ?>>
						<span>
							<strong><?php esc_html_e( 'Trust edits to already approved items', 'dgl-platform' ); ?></strong>
							<span class="dgl-help"><?php esc_html_e( 'An edit to anything the team has already approved goes live straight away, whatever its type. New items are still reviewed unless their type is ticked above.', 'dgl-platform' ); ?></span>
						</span>
					</label>
				</fieldset>

				<div class="dgl-decision__actions">
					<button class="dgl-button" type="submit" name="dgl_trust_save" value="1"><?php esc_html_e( 'Save trust settings', 'dgl-platform' ); ?></button>
				</div>
			</form>
		</section>

		<section class="dgl-card" id="dgl-org-details">
			<h2 class="dgl-section__title"><?php esc_html_e( 'Details', 'dgl-platform' ); ?></h2>
			<p class="dgl-help"><?php esc_html_e( 'What the organisation\'s owners see on their Organisation tab and what the public directory shows. Changes you make here are live at once and recorded against you.', 'dgl-platform' ); ?></p>
			<?php if ( [] !== $pending ) : ?>
				<p class="dgl-alert dgl-alert--edit">
					<?php esc_html_e( 'The organisation has asked to change its name or logo.', 'dgl-platform' ); ?>
					<a href="<?php echo esc_url( Router::url( 'review', 'org', (string) $org_id ) ); ?>"><?php esc_html_e( 'Decide that first', 'dgl-platform' ); ?></a>,
					<?php esc_html_e( 'or set the field here and the request is answered by what you save.', 'dgl-platform' ); ?>
				</p>
			<?php endif; ?>

			<details class="dgl-fold" <?php echo [] !== $errors || 'details' === (string) ( $data['saved'] ?? '' ) ? 'open' : ''; ?>>
				<summary class="dgl-fold__summary"><?php esc_html_e( 'Show and edit the details', 'dgl-platform' ); ?></summary>
			<form class="dgl-form dgl-form--bare" method="post" action="<?php echo esc_url( Router::url( 'review', 'orgs', (string) $org_id ) ); ?>#dgl-org-details" enctype="multipart/form-data">
				<?php wp_nonce_field( Wizard::NONCE ); ?>

				<?php $section_shown = 0; ?>
				<?php foreach ( (array) ( $data['fields'] ?? [] ) as $field ) : ?>
					<?php if ( $field->step !== $section_shown && isset( $data['sections'][ $field->step ] ) ) : ?>
						<?php $section_shown = $field->step; ?>
						<h3 class="dgl-section__title dgl-section__title--form"><?php echo esc_html( (string) $data['sections'][ $field->step ] ); ?></h3>
					<?php endif; ?>
					<?php
					echo FieldRenderer::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
						$field,
						$data['values'][ $field->key ] ?? '',
						(string) ( $errors[ $field->key ] ?? '' )
					);
					?>
				<?php endforeach; ?>

				<div class="dgl-decision__actions">
					<button class="dgl-button" type="submit" name="dgl_org_save" value="1"><?php esc_html_e( 'Save details', 'dgl-platform' ); ?></button>
				</div>
			</form>
			</details>
		</section>

		<section class="dgl-card">
			<h2 class="dgl-section__title"><?php esc_html_e( 'People', 'dgl-platform' ); ?></h2>
			<?php if ( [] === $members ) : ?>
				<p class="dgl-help"><?php esc_html_e( 'Nobody is linked to this organisation yet.', 'dgl-platform' ); ?></p>
			<?php else : ?>
				<table class="dgl-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'dgl-platform' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Role', 'dgl-platform' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Account', 'dgl-platform' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $members as $member ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $member['name'] ); ?> <span class="dgl-help"><a href="<?php echo esc_url( 'mailto:' . $member['email'] ); ?>"><?php echo esc_html( (string) $member['email'] ); ?></a></span></td>
								<td><?php echo esc_html( (string) $member['role'] ); ?></td>
								<td><?php echo esc_html( ucfirst( (string) $member['account'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
	</div>

	<aside class="dgl-review-side">
		<section class="dgl-card">
			<h2 class="dgl-section__title"><?php esc_html_e( 'Set in wp-admin', 'dgl-platform' ); ?></h2>
			<dl class="dgl-review__list">
				<dt><?php esc_html_e( 'Email domains', 'dgl-platform' ); ?></dt>
				<dd><?php echo [] === (array) $data['domains'] ? esc_html__( 'None', 'dgl-platform' ) : esc_html( implode( ', ', (array) $data['domains'] ) ); ?></dd>
				<dt><?php esc_html_e( 'Live on site', 'dgl-platform' ); ?></dt>
				<dd><?php echo esc_html( (string) (int) $data['live'] ); ?></dd>
				<dt><?php esc_html_e( 'Previously refused', 'dgl-platform' ); ?></dt>
				<dd><?php echo esc_html( (string) (int) $data['rejected'] ); ?></dd>
			</dl>
			<p class="dgl-help"><a href="<?php echo esc_url( (string) $data['admin_url'] ); ?>"><?php esc_html_e( 'Verification and email domains in wp-admin', 'dgl-platform' ); ?></a></p>
		</section>

		<section class="dgl-card">
			<h2 class="dgl-section__title"><?php esc_html_e( 'Trust changes', 'dgl-platform' ); ?></h2>
			<?php if ( empty( $data['history'] ) ) : ?>
				<p class="dgl-help"><?php esc_html_e( 'Never changed.', 'dgl-platform' ); ?></p>
			<?php else : ?>
				<ol class="dgl-timeline">
					<?php foreach ( (array) $data['history'] as $entry ) : ?>
						<li class="dgl-timeline__item">
							<p class="dgl-timeline__action"><?php echo esc_html( 'trust_revoked' === (string) $entry['action'] ? __( 'Switched off automatically', 'dgl-platform' ) : __( 'Changed by the team', 'dgl-platform' ) ); ?></p>
							<p class="dgl-timeline__when"><?php echo esc_html( View::date( $entry['logged_at'], true ) ); ?></p>
							<?php if ( '' !== (string) ( $entry['note'] ?? '' ) ) : ?>
								<p class="dgl-timeline__note"><?php echo esc_html( (string) $entry['note'] ); ?></p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
	</aside>
</div>

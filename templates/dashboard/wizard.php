<?php
/**
 * A single wizard step. Wireframe 1f.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\FieldRenderer;
use DGL\Dashboard\Router;
use DGL\Dashboard\View;
use DGL\Schema\FieldRegistry;

defined( 'ABSPATH' ) || exit;

$post     = $data['post'];
$step     = (int) $data['step'];
$errors   = $data['errors'] ?? [];
$values   = $data['values'] ?? [];
$singular = (string) $data['singular'];

$heading = FieldRegistry::steps()[ $step ] ?? '';

if ( FieldRegistry::STEP_DETAILS === $step ) {
	/* translators: %s: content type name. */
	$heading = sprintf( __( '%s details', 'dgl-platform' ), $singular );
}
?>
<div class="dgl-wizard">
	<?php
	View::output(
		'dashboard/wizard-progress',
		[
			'step'     => $step,
			'post_id'  => $post->ID,
			'singular' => $singular,
		]
	);
	?>

	<div class="dgl-wizard__main">
		<header class="dgl-page-head">
			<div>
				<h1 class="dgl-page-head__title"><?php echo esc_html( $heading ); ?></h1>
				<p class="dgl-page-head__lede">
					<?php
					printf(
						/* translators: 1: current step, 2: total steps. */
						esc_html__( 'Step %1$d of %2$d.', 'dgl-platform' ),
						(int) $step,
						count( FieldRegistry::steps() )
					);
					?>
					<?php if ( FieldRegistry::STEP_DETAILS === $step ) : ?>
						<?php
						printf(
							/* translators: %s: content type name, plural and lowercase. */
							esc_html__( 'These questions are specific to %s.', 'dgl-platform' ),
							esc_html( strtolower( (string) $data['plural'] ) )
						);
						?>
					<?php endif; ?>
				</p>
			</div>
		</header>

		<?php if ( ! empty( $data['copied'] ) ) : ?>
			<div class="dgl-alert dgl-alert--good" role="status">
				<p><strong><?php esc_html_e( 'Copied into a new draft.', 'dgl-platform' ); ?></strong>
				<?php esc_html_e( 'Everything came across except the dates, which step 2 asks for. Change what you need to, then send it when it is ready.', 'dgl-platform' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( '' !== ( $data['notice'] ?? '' ) ) : ?>
			<div class="dgl-alert" role="alert">
				<p><strong><?php echo esc_html( $data['notice'] ); ?></strong></p>
				<ul>
					<?php foreach ( $errors as $key => $message ) : ?>
						<li><a href="#dgl-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $message ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form class="dgl-form" method="post" enctype="multipart/form-data" novalidate data-dgl-post="<?php echo (int) $data['post']->ID; ?>" data-dgl-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<?php wp_nonce_field( \DGL\Dashboard\Wizard::NONCE ); ?>

			<?php foreach ( $data['fields'] as $field ) : ?>
				<?php
				echo FieldRenderer::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
					$field,
					$values[ $field->key ] ?? '',
					$errors[ $field->key ] ?? ''
				);
				?>
			<?php endforeach; ?>

			<?php if ( FieldRegistry::STEP_CONTACT === $step && ! empty( $data['topics'] ) ) : ?>
				<fieldset class="dgl-field-row">
					<legend class="dgl-label"><?php esc_html_e( 'Topics', 'dgl-platform' ); ?></legend>
					<p class="dgl-help"><?php esc_html_e( 'Pick any that fit. People use these to choose what turns up in their email.', 'dgl-platform' ); ?></p>
					<div class="dgl-checks">
						<?php foreach ( $data['topics'] as $term ) : ?>
							<label class="dgl-check">
								<input
									type="checkbox"
									name="dgl_topics[]"
									value="<?php echo esc_attr( (string) $term->term_id ); ?>"
									<?php checked( in_array( (int) $term->term_id, (array) ( $data['chosen'] ?? [] ), true ) ); ?>
								>
								<span><?php echo esc_html( $term->name ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
			<?php endif; ?>

			<div class="dgl-form__actions">
				<?php if ( $step > 1 ) : ?>
					<button class="dgl-button dgl-button--secondary" type="submit" name="dgl_intent" value="back">
						<?php esc_html_e( 'Back', 'dgl-platform' ); ?>
					</button>
				<?php else : ?>
					<?php
					/*
					 * Cancelling an edit goes back to the item it belongs to,
					 * not to a list the edit does not appear in. The work is
					 * kept either way: cancelling leaves the edit open, and
					 * discarding it is a separate, deliberate action on the
					 * item screen.
					 */
					$cancel_url = ! empty( $data['is_edit'] ) && isset( $data['parent'] )
						? Router::url( 'item', (string) $data['parent']->ID )
						: Router::url( $data['slug'] );
					?>
					<?php if ( empty( $data['is_edit'] ) ) : ?>
						<?php
						/*
						 * A new draft that has had nothing typed into it is
						 * deleted on cancel rather than left as an "Untitled"
						 * row in the list. Once something is saved, cancel
						 * keeps it, the same as before.
						 */
						?>
						<button class="dgl-button dgl-button--secondary" type="submit" name="dgl_intent" value="cancel" formnovalidate>
							<?php esc_html_e( 'Cancel', 'dgl-platform' ); ?>
						</button>
					<?php else : ?>
						<a class="dgl-button dgl-button--secondary" href="<?php echo esc_url( $cancel_url ); ?>">
							<?php esc_html_e( 'Cancel', 'dgl-platform' ); ?>
						</a>
					<?php endif; ?>
				<?php endif; ?>

				<div class="dgl-form__actions-end">
					<button class="dgl-linkish" type="submit" name="dgl_intent" value="close">
						<?php esc_html_e( 'Save and close', 'dgl-platform' ); ?>
					</button>
					<button class="dgl-button" type="submit" name="dgl_intent" value="next">
						<?php esc_html_e( 'Continue', 'dgl-platform' ); ?>
					</button>
				</div>
			</div>
		</form>
	</div>
</div>

<?php
/**
 * Step four: read it back, then send it.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\View;
use DGL\Dashboard\Wizard;
use DGL\Schema\Field;
use DGL\Schema\FieldRegistry;

defined( 'ABSPATH' ) || exit;

$post       = $data['post'];
/*
 * A pending edit is a `dgl_revision`, which has no field schema of its own.
 * Every schema lookup on this screen has to use the type of the item being
 * edited, which the controller passes in, not the type of the post in front of
 * us. Reading it off the post gives a revision an empty form.
 */
$post_type  = (string) ( $data['post_type'] ?? $post->post_type );
$values     = $data['values'] ?? [];
$all_errors = $data['all_errors'] ?? [];
$is_edit    = ! empty( $data['is_edit'] );
$changes    = $data['changes'] ?? [];

/*
 * An edit that changes nothing is not sendable. Letting it through would cost a
 * moderator a review for no reason and freeze the member's own listing while
 * they waited for a decision about nothing.
 */
$ready = empty( $all_errors ) && ( ! $is_edit || ! empty( $changes ) );
?>
<div class="dgl-wizard dgl-wizard--review">
	<?php
	View::output(
		'dashboard/wizard-progress',
		[
			'step'     => FieldRegistry::STEP_REVIEW,
			'post_id'  => $post->ID,
			'singular' => $data['singular'],
		]
	);
	?>

	<div class="dgl-wizard__main">
		<header class="dgl-page-head">
			<div>
				<h1 class="dgl-page-head__title">
					<?php echo $is_edit
						? esc_html__( 'Check your changes, then send them', 'dgl-platform' )
						: esc_html__( 'Review and submit', 'dgl-platform' ); ?>
				</h1>
				<p class="dgl-page-head__lede">
					<?php echo $is_edit
						? esc_html__( 'The version on the site has not changed. It stays up while the team read your edit.', 'dgl-platform' )
						: esc_html__( 'Read it back the way the team will. Anything you change here goes back to step one, so nothing is lost.', 'dgl-platform' ); ?>
				</p>
			</div>
		</header>

		<?php if ( $is_edit && empty( $changes ) ) : ?>
			<div class="dgl-alert dgl-alert--warn" role="status">
				<p><strong><?php esc_html_e( 'Nothing has changed yet.', 'dgl-platform' ); ?></strong>
				<?php esc_html_e( 'There is nothing to send until something is different from the published version. Go back and make a change, or leave this and the site stays as it is.', 'dgl-platform' ); ?></p>
			</div>
		<?php elseif ( $is_edit ) : ?>
			<?php View::output( 'dashboard/changes', [ 'changes' => $changes ] ); ?>
		<?php endif; ?>

		<?php if ( ! $ready ) : ?>
			<div class="dgl-alert" role="alert">
				<p><strong><?php esc_html_e( 'Not quite ready to send.', 'dgl-platform' ); ?></strong></p>
				<ul>
					<?php foreach ( $all_errors as $key => $message ) : ?>
						<li>
							<a href="<?php echo esc_url( Router::url( 'edit', (string) $post->ID, (string) Wizard::step_of( $post_type, $key ) ) ); ?>">
								<?php echo esc_html( $message ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="dgl-review">
			<?php foreach ( FieldRegistry::steps() as $number => $step_label ) : ?>
				<?php
				$step_fields = FieldRegistry::for_step( $post_type, $number );

				if ( empty( $step_fields ) ) {
					continue;
				}
				?>
				<section class="dgl-review__group">
					<div class="dgl-review__head">
						<h2 class="dgl-section__title"><?php echo esc_html( $step_label ); ?></h2>
						<a href="<?php echo esc_url( Router::url( 'edit', (string) $post->ID, (string) $number ) ); ?>">
							<?php esc_html_e( 'Change', 'dgl-platform' ); ?>
						</a>
					</div>

					<dl class="dgl-review__list">
						<?php foreach ( $step_fields as $field ) : ?>
							<?php $value = $values[ $field->key ] ?? ''; ?>
							<dt><?php echo esc_html( $field->label ); ?></dt>
							<dd<?php echo isset( $all_errors[ $field->key ] ) ? ' class="dgl-review__missing"' : ''; ?>>
								<?php
								if ( isset( $all_errors[ $field->key ] ) ) {
									echo '<span class="dgl-review__missing">' . esc_html__( 'Not filled in', 'dgl-platform' ) . '</span>';
								} else {
									echo View::field_value( $field, $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
								}
								?>
							</dd>
						<?php endforeach; ?>
					</dl>
				</section>
			<?php endforeach; ?>

			<?php if ( ! empty( $data['chosen_names'] ) ) : ?>
				<section class="dgl-review__group">
					<div class="dgl-review__head">
						<h2 class="dgl-section__title"><?php esc_html_e( 'Topics', 'dgl-platform' ); ?></h2>
						<a href="<?php echo esc_url( Router::url( 'edit', (string) $post->ID, '3' ) ); ?>">
							<?php esc_html_e( 'Change', 'dgl-platform' ); ?>
						</a>
					</div>
					<p><?php echo esc_html( implode( ', ', (array) $data['chosen_names'] ) ); ?></p>
				</section>
			<?php endif; ?>
		</div>

		<form class="dgl-form" method="post">
			<?php wp_nonce_field( Wizard::NONCE ); ?>

			<div class="dgl-form__actions dgl-form__actions--alone">
				<a class="dgl-button dgl-button--secondary" href="<?php echo esc_url( Router::url( 'edit', (string) $post->ID, '3' ) ); ?>">
					<?php esc_html_e( 'Back', 'dgl-platform' ); ?>
				</a>

				<div class="dgl-form__actions-end">
					<?php if ( $ready && isset( $data['user'] ) && ! $data['user']->is_fully_approved() ) : ?>
						<?php
						/*
						 * A pending member can write everything and send nothing.
						 * Wireframe 1c promised "you can write now and submit once
						 * approved"; the button used to be there anyway, and the
						 * last click of a four-step form said "You cannot do that
						 * to this item."
						 */
						?>
						<p class="dgl-help"><?php esc_html_e( 'Saved as a draft. You can send it for review as soon as the team approves your account; nothing you have written is lost.', 'dgl-platform' ); ?></p>
					<?php elseif ( $ready ) : ?>
						<button class="dgl-button" type="submit" name="dgl_intent" value="submit">
							<?php esc_html_e( 'Send for review', 'dgl-platform' ); ?>
						</button>
					<?php else : ?>
						<p class="dgl-help"><?php esc_html_e( 'Fill in what is missing above and this button will turn on.', 'dgl-platform' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</form>
	</div>
</div>

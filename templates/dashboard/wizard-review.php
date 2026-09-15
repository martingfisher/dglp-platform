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
$values     = $data['values'] ?? [];
$all_errors = $data['all_errors'] ?? [];
$ready      = empty( $all_errors );
?>
<div class="dgl-wizard">
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
				<h1 class="dgl-page-head__title"><?php esc_html_e( 'Review and submit', 'dgl-platform' ); ?></h1>
				<p class="dgl-page-head__lede"><?php esc_html_e( 'Read it back the way the team will. Anything you change here goes back to step one, so nothing is lost.', 'dgl-platform' ); ?></p>
			</div>
		</header>

		<?php if ( ! $ready ) : ?>
			<div class="dgl-alert" role="alert">
				<p><strong><?php esc_html_e( 'Not quite ready to send.', 'dgl-platform' ); ?></strong></p>
				<ul>
					<?php foreach ( $all_errors as $key => $message ) : ?>
						<li>
							<a href="<?php echo esc_url( Router::url( 'edit', (string) $post->ID, (string) Wizard::step_of( (string) $post->post_type, $key ) ) ); ?>">
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
				$step_fields = FieldRegistry::for_step( (string) $post->post_type, $number );

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

			<div class="dgl-form__actions">
				<a class="dgl-button dgl-button--secondary" href="<?php echo esc_url( Router::url( 'edit', (string) $post->ID, '3' ) ); ?>">
					<?php esc_html_e( 'Back', 'dgl-platform' ); ?>
				</a>

				<div class="dgl-form__actions-end">
					<?php if ( $ready ) : ?>
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

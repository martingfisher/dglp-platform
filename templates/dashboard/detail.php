<?php
/**
 * One submission, its status and its history. Wireframe 1g.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Dashboard\View;
use DGL\Schema\Field;
use DGL\Statuses;

defined( 'ABSPATH' ) || exit;

$post   = $data['post'];
$values = $data['values'] ?? [];
?>
<?php if ( ! empty( $data['submitted'] ) ) : ?>
	<div class="dgl-alert dgl-alert--good" role="status">
		<p><strong><?php esc_html_e( 'Sent for review.', 'dgl-platform' ); ?></strong>
		<?php esc_html_e( 'Somebody will read it and either publish it or come back to you. You will get an email either way.', 'dgl-platform' ); ?></p>
	</div>
<?php endif; ?>

<header class="dgl-page-head">
	<div>
		<p class="dgl-crumbs">
			<a href="<?php echo esc_url( Router::url() ); ?>"><?php esc_html_e( 'Dashboard', 'dgl-platform' ); ?></a>
			<span aria-hidden="true">/</span>
			<a href="<?php echo esc_url( Router::url( $data['slug'] ) ); ?>"><?php echo esc_html( $data['singular'] ); ?></a>
		</p>
		<p class="dgl-detail__status">
			<?php echo View::chip( (string) $post->post_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</p>
		<h1 class="dgl-page-head__title">
			<?php echo esc_html( $post->post_title !== '' ? $post->post_title : __( 'Untitled', 'dgl-platform' ) ); ?>
		</h1>
	</div>

	<?php if ( ! empty( $data['can_edit'] ) ) : ?>
		<a class="dgl-button" href="<?php echo esc_url( Router::url( 'edit', (string) $post->ID, '1' ) ); ?>">
			<?php echo Statuses::CHANGES === $post->post_status
				? esc_html__( 'Edit and resubmit', 'dgl-platform' )
				: esc_html__( 'Edit', 'dgl-platform' ); ?>
		</a>
	<?php endif; ?>
</header>

<div class="dgl-detail">
	<section class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'What you submitted', 'dgl-platform' ); ?></h2>
		<dl class="dgl-review__list">
			<?php foreach ( $data['fields'] as $field ) : ?>
				<?php
				$value = $values[ $field->key ] ?? '';

				if ( '' === (string) $value && Field::CHECKBOX !== $field->type ) {
					continue;
				}
				?>
				<dt><?php echo esc_html( $field->label ); ?></dt>
				<dd>
					<?php
					echo View::field_value( $field, $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
					?>
				</dd>
			<?php endforeach; ?>
		</dl>
	</section>

	<aside class="dgl-card">
		<h2 class="dgl-section__title"><?php esc_html_e( 'History', 'dgl-platform' ); ?></h2>

		<?php if ( empty( $data['history'] ) ) : ?>
			<p class="dgl-help"><?php esc_html_e( 'Nothing yet. Steps appear here once you send it.', 'dgl-platform' ); ?></p>
		<?php else : ?>
			<ol class="dgl-timeline">
				<?php foreach ( array_reverse( $data['history'] ) as $entry ) : ?>
					<li class="dgl-timeline__item">
						<p class="dgl-timeline__action"><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $entry['action'] ) ) ); ?></p>
						<p class="dgl-timeline__when"><?php echo esc_html( View::date( $entry['logged_at'], true ) ); ?></p>
						<?php if ( '' !== (string) ( $entry['note'] ?? '' ) ) : ?>
							<p class="dgl-timeline__note"><?php echo esc_html( (string) $entry['note'] ); ?></p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>
	</aside>
</div>

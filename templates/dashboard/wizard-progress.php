<?php
/**
 * The progress rail down the side of the wizard.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Dashboard\Router;
use DGL\Schema\FieldRegistry;

defined( 'ABSPATH' ) || exit;

$current  = (int) ( $data['step'] ?? 1 );
$post_id  = (int) ( $data['post_id'] ?? 0 );
$singular = (string) ( $data['singular'] ?? '' );
?>
<nav class="dgl-progress" aria-label="<?php esc_attr_e( 'Submission steps', 'dgl-platform' ); ?>">
	<p class="dgl-progress__heading"><?php esc_html_e( 'Progress', 'dgl-platform' ); ?></p>
	<ol class="dgl-progress__list">
		<?php foreach ( FieldRegistry::steps() as $number => $label ) : ?>
			<?php
			// Step two is the only one that changes name by content type, which
			// is what the wireframe means by "fields specific to events".
			if ( FieldRegistry::STEP_DETAILS === $number && '' !== $singular ) {
				/* translators: %s: content type name. */
				$label = sprintf( __( '%s details', 'dgl-platform' ), $singular );
			}

			$state = $number === $current ? 'current' : ( $number < $current ? 'done' : 'todo' );
			?>
			<li class="dgl-progress__step dgl-progress__step--<?php echo esc_attr( $state ); ?>">
				<?php if ( $number < $current && $post_id > 0 ) : ?>
					<a href="<?php echo esc_url( Router::url( 'edit', (string) $post_id, (string) $number ) ); ?>">
						<span class="dgl-progress__num"><?php echo esc_html( (string) $number ); ?></span>
						<span class="dgl-progress__label"><?php echo esc_html( $label ); ?></span>
					</a>
				<?php else : ?>
					<span<?php echo $number === $current ? ' aria-current="step"' : ''; ?>>
						<span class="dgl-progress__num"><?php echo esc_html( (string) $number ); ?></span>
						<span class="dgl-progress__label"><?php echo esc_html( $label ); ?></span>
					</span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>

	<aside class="dgl-callout">
		<p class="dgl-callout__title"><?php esc_html_e( 'Before you submit', 'dgl-platform' ); ?></p>
		<p><?php esc_html_e( 'Nothing goes live automatically. Somebody reads every submission and either publishes it or asks you for a change.', 'dgl-platform' ); ?></p>
	</aside>
</nav>

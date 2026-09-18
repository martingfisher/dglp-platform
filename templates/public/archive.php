<?php
/**
 * A public listing of one content type.
 *
 * Follows the wireframe's list pattern: a thumbnail, the title, and one line
 * saying when and where. That line is what somebody decides on, so it is built
 * from the two facts they are deciding with rather than from the first fields
 * in the schema.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Frontend;

defined( 'ABSPATH' ) || exit;

$type  = (string) ( $data['type'] ?? '' );
$label = Frontend::type_label( $type, true );
?>
<div class="dgl-pub">
	<header class="dgl-pub__head">
		<h1 class="dgl-pub__title"><?php echo esc_html( $label ); ?></h1>
		<p class="dgl-pub__lede">
			<?php esc_html_e( 'Posted by organisations in the Doing Good Leeds Partnership.', 'dgl-platform' ); ?>
			<?php if ( \DGL\PostTypes::EVENT === $type ) : ?>
				<a class="dgl-pub__callink" href="<?php echo esc_url( \DGL\Events\Calendar::url() ); ?>"><?php esc_html_e( 'See them day by day on the calendar', 'dgl-platform' ); ?></a>
			<?php endif; ?>
		</p>
	</header>

	<?php if ( have_posts() ) : ?>

		<ul class="dgl-pub__list" data-dgl-autoload="li">
			<?php
			while ( have_posts() ) :
				the_post();

				$item     = get_post();
				$meta     = Frontend::meta_line( $item );
				$org      = Frontend::organisation( $item );
				$summary  = (string) Frontend::value( $item, 'summary' );
				$image_id = (int) Frontend::value( $item, 'image' );
				?>
				<li class="dgl-pub__row">
					<?php if ( $image_id > 0 ) : ?>
						<div class="dgl-pub__thumb">
							<a href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
								<?php echo wp_get_attachment_image( $image_id, 'medium', false, [ 'loading' => 'lazy', 'alt' => '' ] ); ?>
							</a>
						</div>
					<?php endif; ?>

					<div class="dgl-pub__rowbody">
						<?php if ( \DGL\Events\Cancel::is_cancelled( (int) $item->ID ) ) : ?>
							<p class="dgl-pub__pin dgl-pub__pin--off"><?php esc_html_e( 'Cancelled', 'dgl-platform' ); ?></p>
						<?php elseif ( \DGL\Workflow\Pins::is_pinned( (int) $item->ID ) ) : ?>
							<p class="dgl-pub__pin"><?php esc_html_e( 'Featured', 'dgl-platform' ); ?></p>
						<?php endif; ?>
						<h2 class="dgl-pub__rowtitle">
							<a href="<?php the_permalink(); ?>"><?php echo esc_html( get_the_title() ); ?></a>
						</h2>

						<?php if ( '' !== $meta ) : ?>
							<p class="dgl-pub__rowmeta"><?php echo esc_html( $meta ); ?></p>
						<?php endif; ?>

						<?php if ( '' !== trim( $summary ) ) : ?>
							<p class="dgl-pub__rowsummary"><?php echo esc_html( wp_trim_words( $summary, 32 ) ); ?></p>
						<?php endif; ?>

						<?php if ( '' !== $org['name'] ) : ?>
							<p class="dgl-pub__roworg"><?php echo esc_html( $org['name'] ); ?></p>
						<?php endif; ?>
					</div>
				</li>
			<?php endwhile; ?>
		</ul>

		<?php
		$pagination = paginate_links(
			[
				'type'      => 'list',
				'prev_text' => __( 'Previous', 'dgl-platform' ),
				'next_text' => __( 'Next', 'dgl-platform' ),
			]
		);
		?>

		<?php if ( is_string( $pagination ) && '' !== $pagination ) : ?>
			<nav class="dgl-pub__pagination" data-dgl-pager="hide" aria-label="<?php esc_attr_e( 'Pagination', 'dgl-platform' ); ?>">
				<?php echo wp_kses_post( $pagination ); ?>
			</nav>
		<?php endif; ?>

	<?php else : ?>

		<div class="dgl-pub__card dgl-pub__empty">
			<p>
				<?php
				printf(
					/* translators: %s: lower-case plural type label. */
					esc_html__( 'There are no %s listed at the moment. Check back soon.', 'dgl-platform' ),
					esc_html( strtolower( $label ) )
				);
				?>
			</p>
		</div>

	<?php endif; ?>
</div>

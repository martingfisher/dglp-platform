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

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public filter form; nothing is written.
$filter_args = \DGL\Frontend\Filters::args_from( wp_unslash( $_GET ), $type );
$topics      = \DGL\Frontend\Filters::topics();
$whens       = \DGL\PostTypes::EVENT === $type ? \DGL\Frontend\Filters::whens() : [];
$filtering   = \DGL\Frontend\Filters::is_active( $filter_args );
$filters_on  = count( array_filter( $filter_args ) );
$list_base   = Frontend::archive_url( $type );
$show_form   = [] !== $topics || [] !== $whens;
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

	<?php if ( $show_form ) : ?>
		<form class="dgl-pub__filterbar" method="get" action="<?php echo esc_url( $list_base ); ?>">
			<button class="dgl-dir__toggle" type="button" hidden data-dgl-fold aria-expanded="<?php echo $filters_on > 0 ? 'true' : 'false'; ?>" aria-controls="dgl-list-filters">
				<?php
				echo $filters_on > 0
					/* translators: %d: how many filters are set. */
					? esc_html( sprintf( _n( 'Filters (%d set)', 'Filters (%d set)', $filters_on, 'dgl-platform' ), $filters_on ) )
					: esc_html__( 'Filters', 'dgl-platform' );
				?>
			</button>

			<div class="dgl-pub__filters" id="dgl-list-filters">
				<?php if ( [] !== $topics ) : ?>
					<div class="dgl-pub__filter">
						<label class="dgl-dir__label" for="dgl-filter-topic"><?php esc_html_e( 'Topic', 'dgl-platform' ); ?></label>
						<select class="dgl-dir__input" id="dgl-filter-topic" name="<?php echo esc_attr( \DGL\Frontend\Filters::PARAM_TOPIC ); ?>">
							<option value=""><?php esc_html_e( 'Any topic', 'dgl-platform' ); ?></option>
							<?php foreach ( $topics as $slug => $name ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $filter_args['topic'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

				<?php if ( [] !== $whens ) : ?>
					<div class="dgl-pub__filter">
						<label class="dgl-dir__label" for="dgl-filter-when"><?php esc_html_e( 'When', 'dgl-platform' ); ?></label>
						<select class="dgl-dir__input" id="dgl-filter-when" name="<?php echo esc_attr( \DGL\Frontend\Filters::PARAM_WHEN ); ?>">
							<option value=""><?php esc_html_e( 'Any time', 'dgl-platform' ); ?></option>
							<?php foreach ( $whens as $spell => $when_label ) : ?>
								<option value="<?php echo esc_attr( $spell ); ?>"<?php selected( $filter_args['when'], $spell ); ?>><?php echo esc_html( $when_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>

				<div class="dgl-dir__actions">
					<button class="dgl-pub__button dgl-dir__button" type="submit"><?php esc_html_e( 'Show', 'dgl-platform' ); ?></button>
					<?php if ( $filtering ) : ?>
						<a class="dgl-dir__clear" href="<?php echo esc_url( $list_base ); ?>"><?php esc_html_e( 'Clear', 'dgl-platform' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</form>
		<?php \DGL\Dashboard\View::output( 'public/fold-script' ); ?>

		<?php if ( $filtering ) : ?>
			<p class="dgl-dir__count" role="status">
				<?php
				global $wp_query;
				$found = (int) $wp_query->found_posts;
				echo esc_html(
					0 === $found
						? __( 'Nothing matches. Try a wider window or another topic.', 'dgl-platform' )
						/* translators: 1: a number, 2: lower-case plural type label. */
						: sprintf( _n( '%1$s %2$s matches.', '%1$s %2$s match.', $found, 'dgl-platform' ), number_format_i18n( $found ), strtolower( $found === 1 ? Frontend::type_label( $type ) : $label ) )
				);
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

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
				<?php if ( $filtering ) : ?>
					<?php esc_html_e( 'Nothing matches these filters.', 'dgl-platform' ); ?>
					<a href="<?php echo esc_url( $list_base ); ?>"><?php esc_html_e( 'Show everything', 'dgl-platform' ); ?></a>
				<?php else : ?>
					<?php
					printf(
						/* translators: %s: lower-case plural type label. */
						esc_html__( 'There are no %s listed at the moment. Check back soon.', 'dgl-platform' ),
						esc_html( strtolower( $label ) )
					);
					?>
				<?php endif; ?>
			</p>
		</div>

	<?php endif; ?>
</div>

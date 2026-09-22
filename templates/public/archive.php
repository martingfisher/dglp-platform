<?php
/**
 * A public listing of one content type.
 *
 * The first page opens with one featured item large and three beside it,
 * then the rest as rows: a square picture, a topic chip, the title and one
 * meta line. The rows keep loading on scroll. Every later page is rows only,
 * which is what the autoload appends.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Cards;
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

global $wp_query;
$found     = (int) $wp_query->found_posts;
$paged     = max( 1, (int) get_query_var( 'paged' ) );
// The featured block: page one, unfiltered, two or more items (one large, up to three beside).
$with_hero = 1 === $paged && ! $filtering && $found >= 2;

/** The slash-separated meta line. */
$meta_line = static function ( WP_Post $item ): string {
	$parts = Cards::meta( $item );

	if ( [] === $parts ) {
		return '';
	}

	return '<p class="dgl-card__meta">' . implode( '', array_map( static fn( string $p ): string => '<span>' . esc_html( $p ) . '</span>', $parts ) ) . '</p>';
};
?>
<div class="dgl-pub dgl-listing">
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

		<?php if ( $with_hero ) : ?>
			<?php
			the_post();
			$lead = get_post();
			?>
			<section class="dgl-hero" aria-label="<?php esc_attr_e( 'Featured', 'dgl-platform' ); ?>">
				<article class="dgl-hero__lead<?php echo Cards::has_picture( $lead ) ? '' : ' dgl-hero__lead--nopic'; ?>">
					<?php echo Cards::picture( $lead, 'large', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					<div class="dgl-hero__body">
						<?php if ( \DGL\Workflow\Pins::is_pinned( (int) $lead->ID ) ) : ?>
							<p class="dgl-pub__pin dgl-pub__pin--onpic"><?php esc_html_e( 'Featured', 'dgl-platform' ); ?></p>
						<?php elseif ( \DGL\Events\Cancel::is_cancelled( (int) $lead->ID ) ) : ?>
							<p class="dgl-pub__pin dgl-pub__pin--off"><?php esc_html_e( 'Cancelled', 'dgl-platform' ); ?></p>
						<?php endif; ?>
						<p class="dgl-card__chip dgl-card__chip--onpic"><?php echo esc_html( Cards::chip( $lead ) ); ?></p>
						<h2 class="dgl-hero__title"><a href="<?php the_permalink(); ?>"><?php echo esc_html( get_the_title() ); ?></a></h2>
						<?php echo $meta_line( $lead ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
						<a class="dgl-hero__more" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?php esc_html_e( 'Read more', 'dgl-platform' ); ?></a>
					</div>
				</article>

				<div class="dgl-hero__side">
					<?php
					// Counted, not probed: have_posts() rewinds the loop when it runs
					// out, which would repeat the featured items as rows below.
					$side_count = min( 3, (int) $wp_query->post_count - 1 );
					for ( $i = 0; $i < $side_count; $i++ ) :
						?>
						<?php the_post(); $item = get_post(); ?>
						<article class="dgl-hero__item">
							<a class="dgl-card__pic" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?php echo Cards::picture( $item, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></a>
							<div class="dgl-card__body">
								<p class="dgl-card__chip"><?php echo esc_html( Cards::chip( $item ) ); ?></p>
								<h3 class="dgl-card__title"><a href="<?php the_permalink(); ?>"><?php echo esc_html( get_the_title() ); ?></a></h3>
								<?php echo $meta_line( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
							</div>
						</article>
					<?php endfor; ?>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( $show_form ) : ?>
			<form class="dgl-listing__filters" method="get" action="<?php echo esc_url( $list_base ); ?>">
				<button class="dgl-dir__toggle" type="button" hidden data-dgl-fold aria-expanded="<?php echo $filters_on > 0 ? 'true' : 'false'; ?>" aria-controls="dgl-list-filters">
					<?php
					echo $filters_on > 0
						/* translators: %d: how many filters are set. */
						? esc_html( sprintf( _n( 'Filters (%d set)', 'Filters (%d set)', $filters_on, 'dgl-platform' ), $filters_on ) )
						: esc_html__( 'Filters', 'dgl-platform' );
					?>
				</button>

				<div class="dgl-listing__filterrow" id="dgl-list-filters">
					<h2 class="dgl-listing__heading">
						<?php
						echo esc_html(
							$filtering
								? ( 0 === $found
									? __( 'Nothing matches', 'dgl-platform' )
									/* translators: 1: a number, 2: lower-case plural type label. */
									: sprintf( _n( '%1$s %2$s', '%1$s %2$s', $found, 'dgl-platform' ), number_format_i18n( $found ), strtolower( 1 === $found ? Frontend::type_label( $type ) : $label ) ) )
								/* translators: %s: plural type label. */
								: sprintf( __( 'All %s', 'dgl-platform' ), strtolower( $label ) )
						);
						?>
					</h2>
					<?php if ( [] !== $topics ) : ?>
						<label class="dgl-listing__filter">
							<span class="dgl-listing__filterlabel"><?php esc_html_e( 'Topic', 'dgl-platform' ); ?></span>
							<select class="dgl-listing__select" name="<?php echo esc_attr( \DGL\Frontend\Filters::PARAM_TOPIC ); ?>">
								<option value=""><?php esc_html_e( 'Any topic', 'dgl-platform' ); ?></option>
								<?php foreach ( $topics as $slug => $name ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $filter_args['topic'], $slug ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
					<?php if ( [] !== $whens ) : ?>
						<label class="dgl-listing__filter">
							<span class="dgl-listing__filterlabel"><?php esc_html_e( 'When', 'dgl-platform' ); ?></span>
							<select class="dgl-listing__select" name="<?php echo esc_attr( \DGL\Frontend\Filters::PARAM_WHEN ); ?>">
								<option value=""><?php esc_html_e( 'Any time', 'dgl-platform' ); ?></option>
								<?php foreach ( $whens as $spell => $when_label ) : ?>
									<option value="<?php echo esc_attr( $spell ); ?>"<?php selected( $filter_args['when'], $spell ); ?>><?php echo esc_html( $when_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>
					<button class="dgl-listing__apply" type="submit"><?php esc_html_e( 'Show', 'dgl-platform' ); ?></button>
					<?php if ( $filtering ) : ?>
						<a class="dgl-dir__clear" href="<?php echo esc_url( $list_base ); ?>"><?php esc_html_e( 'Clear', 'dgl-platform' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
			<?php \DGL\Dashboard\View::output( 'public/fold-script' ); ?>
		<?php elseif ( $with_hero ) : ?>
			<h2 class="dgl-listing__heading"><?php echo esc_html( sprintf( /* translators: %s: plural type label. */ __( 'All %s', 'dgl-platform' ), strtolower( $label ) ) ); ?></h2>
		<?php endif; ?>

		<ul class="dgl-pub__list" data-dgl-autoload="li">
			<?php while ( have_posts() ) : ?>
				<?php the_post(); $item = get_post(); ?>
				<li class="dgl-card">
					<a class="dgl-card__pic" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true"><?php echo Cards::picture( $item, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></a>
					<div class="dgl-card__body">
						<p class="dgl-card__chip">
							<?php echo esc_html( Cards::chip( $item ) ); ?>
							<?php if ( \DGL\Events\Cancel::is_cancelled( (int) $item->ID ) ) : ?>
								<span class="dgl-pub__pin dgl-pub__pin--off"><?php esc_html_e( 'Cancelled', 'dgl-platform' ); ?></span>
							<?php elseif ( \DGL\Workflow\Pins::is_pinned( (int) $item->ID ) ) : ?>
								<span class="dgl-pub__pin"><?php esc_html_e( 'Featured', 'dgl-platform' ); ?></span>
							<?php endif; ?>
						</p>
						<h2 class="dgl-card__title"><a href="<?php the_permalink(); ?>"><?php echo esc_html( get_the_title() ); ?></a></h2>
						<?php echo $meta_line( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
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

		<?php if ( $show_form ) : ?>
			<form class="dgl-listing__filters" method="get" action="<?php echo esc_url( $list_base ); ?>">
				<div class="dgl-listing__filterrow">
					<h2 class="dgl-listing__heading"><?php echo esc_html( $filtering ? __( 'Nothing matches', 'dgl-platform' ) : sprintf( /* translators: %s: plural type label. */ __( 'All %s', 'dgl-platform' ), strtolower( $label ) ) ); ?></h2>
					<?php if ( $filtering ) : ?>
						<a class="dgl-dir__clear" href="<?php echo esc_url( $list_base ); ?>"><?php esc_html_e( 'Show everything', 'dgl-platform' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		<?php endif; ?>

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

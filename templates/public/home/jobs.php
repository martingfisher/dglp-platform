<?php
/**
 * Jobs from Voluntary Action Leeds, as a carousel or a feed. Each job is
 * the employer's logo (or its initial), the title, the employer and one
 * line of where, hours and pay. The whole card is the way in, and it
 * goes to the advert on VAL's site. The logo is this site's copy.
 *
 * The carousel is a row that scrolls sideways on its own: swipe, trackpad
 * or the keyboard once it has focus. The previous and next buttons are
 * printed hidden and shown by assets/jobs-carousel.js, so without the
 * script nothing is offered that does not work. It never moves by itself.
 *
 * Expects `$data['jobs']` (rows from ValJobs), `$data['layout']`
 * ('carousel' or 'feed'), `$data['heading']`, `$data['all_url']`,
 * `$data['all_label']`, `$data['fetched']` and `$data['id']`.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Jobs\ValJobs;

defined( 'ABSPATH' ) || exit;

$jobs      = array_values( array_filter( (array) ( $data['jobs'] ?? [] ), 'is_array' ) );
$layout    = 'feed' === ( $data['layout'] ?? '' ) ? 'feed' : 'carousel';
$heading   = (string) ( $data['heading'] ?? '' );
$all_url   = (string) ( $data['all_url'] ?? '' );
$all_label = (string) ( $data['all_label'] ?? '' );
$fetched   = (int) ( $data['fetched'] ?? 0 );
$id        = (string) ( $data['id'] ?? 'dgl-jobs-' . wp_unique_id() );
$carousel  = 'carousel' === $layout && [] !== $jobs;
$labelled  = '' !== $heading ? ' aria-labelledby="' . esc_attr( $id ) . '-h"' : ' aria-label="' . esc_attr__( 'Jobs from Voluntary Action Leeds', 'dgl-platform' ) . '"';
?>
<section class="dgl-home__section dgl-jobs-block dgl-jobs-block--<?php echo esc_attr( $layout ); ?>" id="<?php echo esc_attr( $id ); ?>"<?php echo $labelled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?><?php echo $carousel ? ' aria-roledescription="' . esc_attr__( 'carousel', 'dgl-platform' ) . '"' : ''; ?>>
	<?php if ( '' !== $heading || ( '' !== $all_url && '' !== $all_label ) ) : ?>
		<div class="dgl-home__sechead">
			<?php if ( '' !== $heading ) : ?>
				<h2 class="dgl-home__h2" id="<?php echo esc_attr( $id ); ?>-h"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>
			<?php if ( '' !== $all_url && '' !== $all_label ) : ?>
				<a class="dgl-home__all" href="<?php echo esc_url( $all_url ); ?>"><?php echo esc_html( $all_label ); ?></a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php if ( [] === $jobs ) : ?>
		<p class="dgl-home__empty"><?php esc_html_e( 'Jobs from Voluntary Action Leeds will appear here after the overnight update.', 'dgl-platform' ); ?></p>
	<?php else : ?>
		<div class="dgl-jobs__frame"<?php echo $carousel ? ' data-dgl-carousel' : ''; ?>>
			<ul class="dgl-jobs dgl-jobs--<?php echo esc_attr( $layout ); ?>" id="<?php echo esc_attr( $id ); ?>-list"<?php echo $carousel ? ' tabindex="0" aria-label="' . esc_attr__( 'Jobs, scroll sideways for more', 'dgl-platform' ) . '"' : ''; ?>>
				<?php foreach ( $jobs as $job ) : ?>
					<?php
					$title   = (string) ( $job['title'] ?? '' );
					$company = (string) ( $job['company'] ?? '' );
					$meta    = ValJobs::meta( $job );
					$logo    = ValJobs::logo_url( $job );
					$posted  = (string) ( $job['posted'] ?? '' );
					$stamp   = '' === $posted ? false : strtotime( $posted . ' 12:00:00 UTC' );
					?>
					<li class="dgl-jobs__item">
						<article class="dgl-jobs__card">
							<span class="dgl-jobs__logo" aria-hidden="true">
								<?php if ( '' !== $logo ) : ?>
									<img src="<?php echo esc_url( $logo ); ?>" alt="" width="64" height="64" loading="lazy" decoding="async">
								<?php else : ?>
									<span class="dgl-jobs__initial"><?php echo esc_html( ValJobs::initial( $job ) ); ?></span>
								<?php endif; ?>
							</span>
							<div class="dgl-jobs__body">
								<h3 class="dgl-jobs__title"><a href="<?php echo esc_url( (string) ( $job['url'] ?? '' ) ); ?>"><?php echo esc_html( $title ); ?></a></h3>
								<?php if ( '' !== $company ) : ?>
									<p class="dgl-jobs__company"><?php echo esc_html( $company ); ?></p>
								<?php endif; ?>
								<?php if ( '' !== $meta ) : ?>
									<p class="dgl-jobs__meta"><?php echo esc_html( $meta ); ?></p>
								<?php endif; ?>
								<?php if ( false !== $stamp ) : ?>
									<?php /* translators: %s: a date like "12 Oct". */ ?>
									<p class="dgl-jobs__posted"><?php echo esc_html( sprintf( __( 'Posted %s', 'dgl-platform' ), wp_date( 'j M', $stamp ) ) ); ?></p>
								<?php endif; ?>
							</div>
						</article>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $carousel ) : ?>
				<div class="dgl-jobs__controls" data-dgl-carousel-controls hidden>
					<button type="button" class="dgl-jobs__nav" data-dgl-carousel-step="-1" aria-controls="<?php echo esc_attr( $id ); ?>-list">
						<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
						<span class="dgl-home__srlabel"><?php esc_html_e( 'Previous jobs', 'dgl-platform' ); ?></span>
					</button>
					<button type="button" class="dgl-jobs__nav" data-dgl-carousel-step="1" aria-controls="<?php echo esc_attr( $id ); ?>-list">
						<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
						<span class="dgl-home__srlabel"><?php esc_html_e( 'Next jobs', 'dgl-platform' ); ?></span>
					</button>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<p class="dgl-jobs__source">
		<?php
		echo wp_kses(
			sprintf(
				/* translators: %s: "Voluntary Action Leeds", linked to their jobs page. */
				__( 'Jobs listed by %s. Each one opens the full advert on their website.', 'dgl-platform' ),
				'<a href="' . esc_url( ValJobs::JOBS_URL ) . '">' . esc_html__( 'Voluntary Action Leeds', 'dgl-platform' ) . '</a>'
			),
			[ 'a' => [ 'href' => true ] ]
		);
		?>
		<?php if ( $fetched > 0 ) : ?>
			<?php /* translators: %s: a date like "14 October". */ ?>
			<?php echo esc_html( sprintf( __( 'Updated %s.', 'dgl-platform' ), wp_date( 'j F', $fetched ) ) ); ?>
		<?php endif; ?>
	</p>
</section>

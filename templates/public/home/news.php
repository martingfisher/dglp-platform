<?php
/**
 * The news block for the home page: the first story large, the rest as a
 * list beside it.
 *
 * Expects `$data['items']` (WP_Post[]), `$data['heading']`, `$data['all_url']`,
 * `$data['all_label']`, `$data['id']`.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Cards;
use DGL\Frontend\Frontend;
use DGL\Frontend\Home;

defined( 'ABSPATH' ) || exit;

$items     = array_values( array_filter( (array) ( $data['items'] ?? [] ), static fn( $p ): bool => $p instanceof WP_Post ) );
$heading   = (string) ( $data['heading'] ?? '' );
$all_url   = (string) ( $data['all_url'] ?? '' );
$all_label = (string) ( $data['all_label'] ?? '' );
$id        = (string) ( $data['id'] ?? 'dgl-home-' . wp_unique_id() );
$lead      = [] !== $items ? array_shift( $items ) : null;

/** Organisation and posted date, in one line. */
$posted = static function ( WP_Post $item ): string {
	/* translators: %s: a date like "24 Sep 2026". */
	return implode( ' · ', array_filter( [ Frontend::organisation( $item )['name'], sprintf( __( 'Posted: %s', 'dgl-platform' ), Cards::posted( $item ) ) ] ) );
};
?>
<section class="dgl-home__section" id="<?php echo esc_attr( $id ); ?>"<?php echo '' !== $heading ? ' aria-labelledby="' . esc_attr( $id ) . '-h"' : ''; ?>>
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
	<?php if ( null === $lead ) : ?>
		<p class="dgl-home__empty"><?php esc_html_e( 'Nothing is listed at the moment. Check back soon.', 'dgl-platform' ); ?></p>
	<?php else : ?>
		<div class="dgl-home__news">
			<article class="dgl-home__lead">
				<a class="dgl-home__leadpic" href="<?php echo esc_url( (string) get_permalink( $lead ) ); ?>" tabindex="-1" aria-hidden="true"><?php echo Cards::picture( $lead, 'large', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></a>
				<p class="dgl-card__chip">
					<?php echo esc_html( Cards::chip( $lead ) ); ?>
					<?php if ( Home::is_featured( $lead ) ) : ?>
						<span class="dgl-pub__pin"><?php esc_html_e( 'Featured', 'dgl-platform' ); ?></span>
					<?php endif; ?>
				</p>
				<h3 class="dgl-home__leadtitle"><a href="<?php echo esc_url( (string) get_permalink( $lead ) ); ?>"><?php echo esc_html( get_the_title( $lead ) ); ?></a></h3>
				<?php $summary = Cards::summary( $lead, 40 ); ?>
				<?php if ( '' !== $summary ) : ?>
					<p class="dgl-home__leadsummary"><?php echo esc_html( $summary ); ?></p>
				<?php endif; ?>
				<p class="dgl-home__rowmeta"><?php echo esc_html( $posted( $lead ) ); ?></p>
			</article>
			<?php if ( [] !== $items ) : ?>
				<ul class="dgl-home__newslist">
					<?php foreach ( $items as $item ) : ?>
						<li class="dgl-home__row dgl-home__row--news">
							<a class="dgl-home__rowpic" href="<?php echo esc_url( (string) get_permalink( $item ) ); ?>" tabindex="-1" aria-hidden="true"><?php echo Cards::picture( $item, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></a>
							<div class="dgl-home__rowbody">
								<h3 class="dgl-home__rowtitle"><a href="<?php echo esc_url( (string) get_permalink( $item ) ); ?>"><?php echo esc_html( get_the_title( $item ) ); ?></a></h3>
								<p class="dgl-home__rowmeta"><?php echo esc_html( $posted( $item ) ); ?></p>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</section>

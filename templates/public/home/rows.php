<?php
/**
 * A dated list for the home page: events, training or funding, three
 * across. Each row is the calendar leaf, the picture, the title and the
 * where/organisation line.
 *
 * Expects `$data['items']` (WP_Post[]), `$data['heading']`, `$data['all_url']`,
 * `$data['all_label']`, `$data['id']`, and optionally `$data['placeholder']`
 * (text drawn in a dashed box instead of the list) and `$data['empty']`.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Cards;
use DGL\Frontend\Frontend;
use DGL\Frontend\Home;
use DGL\PostTypes;

defined( 'ABSPATH' ) || exit;

$items       = (array) ( $data['items'] ?? [] );
$heading     = (string) ( $data['heading'] ?? '' );
$all_url     = (string) ( $data['all_url'] ?? '' );
$all_label   = (string) ( $data['all_label'] ?? '' );
$id          = (string) ( $data['id'] ?? 'dgl-home-' . wp_unique_id() );
$placeholder = (string) ( $data['placeholder'] ?? '' );
$empty       = (string) ( $data['empty'] ?? __( 'Nothing is listed at the moment. Check back soon.', 'dgl-platform' ) );
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
	<?php if ( '' !== $placeholder ) : ?>
		<div class="dgl-home__placeholderbox">
			<p class="dgl-home__placeholder"><?php echo esc_html( $placeholder ); ?></p>
		</div>
	<?php elseif ( [] === $items ) : ?>
		<p class="dgl-home__empty"><?php echo esc_html( $empty ); ?></p>
	<?php else : ?>
		<ul class="dgl-home__rows dgl-home__rows--3">
			<?php foreach ( $items as $item ) : ?>
				<?php
				if ( ! $item instanceof WP_Post ) {
					continue;
				}
				$org   = Frontend::organisation( $item )['name'];
				$where = Frontend::where( $item );
				$line  = implode( ' · ', array_filter( [ $where, $org ] ) );
				?>
				<li class="dgl-home__row">
					<?php echo Cards::date_block( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
					<a class="dgl-home__rowpic" href="<?php echo esc_url( (string) get_permalink( $item ) ); ?>" tabindex="-1" aria-hidden="true"><?php echo Cards::picture( $item, 'thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></a>
					<div class="dgl-home__rowbody">
						<?php if ( Home::is_featured( $item ) ) : ?>
							<span class="dgl-pub__pin"><?php esc_html_e( 'Featured', 'dgl-platform' ); ?></span>
						<?php elseif ( PostTypes::EVENT === $item->post_type && \DGL\Events\Cancel::is_cancelled( (int) $item->ID ) ) : ?>
							<span class="dgl-pub__pin dgl-pub__pin--off"><?php esc_html_e( 'Cancelled', 'dgl-platform' ); ?></span>
						<?php endif; ?>
						<h3 class="dgl-home__rowtitle"><a href="<?php echo esc_url( (string) get_permalink( $item ) ); ?>"><?php echo esc_html( get_the_title( $item ) ); ?></a></h3>
						<?php if ( '' !== $line ) : ?>
							<p class="dgl-home__rowmeta"><?php echo esc_html( $line ); ?></p>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<?php
/**
 * A topic feed: news, events or training, picture on top, words underneath,
 * four across on a wide screen.
 *
 * Plain HTML a crawler can read: every item is an <article> with a heading
 * that links to it and a <time> with a machine-readable date. The block also
 * prints its own ItemList (`$data['schema']`). The picture is decoration next
 * to the title link, so it is hidden from assistive technology and left out
 * of the tab order, and the title link covers the whole card.
 *
 * Expects `$data['items']` (WP_Post[]), `$data['type']`, `$data['heading']`,
 * `$data['all_url']`, `$data['all_label']`, `$data['id']` and
 * `$data['schema']` (a ready <script> element, or '').
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Cards;
use DGL\Frontend\Feed;
use DGL\Frontend\Frontend;

defined( 'ABSPATH' ) || exit;

$items     = array_values( array_filter( (array) ( $data['items'] ?? [] ), static fn( $p ): bool => $p instanceof WP_Post ) );
$type      = (string) ( $data['type'] ?? 'news' );
$heading   = (string) ( $data['heading'] ?? '' );
$all_url   = (string) ( $data['all_url'] ?? '' );
$all_label = (string) ( $data['all_label'] ?? '' );
$id        = (string) ( $data['id'] ?? wp_unique_id( 'dgl-feed-' ) );
$schema    = (string) ( $data['schema'] ?? '' );
?>
<section class="dgl-home__section dgl-feed dgl-feed--<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $id ); ?>"<?php echo '' !== $heading ? ' aria-labelledby="' . esc_attr( $id ) . '-h"' : ''; ?>>
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
	<?php if ( [] === $items ) : ?>
		<p class="dgl-home__empty"><?php esc_html_e( 'Nothing is listed at the moment. Check back soon.', 'dgl-platform' ); ?></p>
	<?php else : ?>
		<ul class="dgl-feed__list">
			<?php foreach ( $items as $item ) : ?>
				<?php
				$link  = (string) get_permalink( $item );
				$when  = Feed::date_line( $item );
				$org   = Frontend::organisation( $item )['name'];
				$where = 'news' === $type ? '' : Frontend::where( $item );
				$line  = implode( ' · ', array_filter( [ $where, $org ] ) );
				?>
				<li class="dgl-feed__item">
					<article class="dgl-feed__card">
						<a class="dgl-feed__pic" href="<?php echo esc_url( $link ); ?>" tabindex="-1" aria-hidden="true"><?php echo Feed::picture( $item, $type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></a>
						<div class="dgl-feed__body">
							<h3 class="dgl-feed__title"><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( get_the_title( $item ) ); ?></a></h3>
							<?php if ( '' !== $when['text'] ) : ?>
								<p class="dgl-feed__date"><time datetime="<?php echo esc_attr( $when['iso'] ); ?>"><?php echo esc_html( $when['text'] ); ?></time></p>
							<?php endif; ?>
							<?php if ( '' !== $line ) : ?>
								<p class="dgl-feed__meta"><?php echo esc_html( $line ); ?></p>
							<?php endif; ?>
						</div>
					</article>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php echo $schema; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Seo::schema_tag() encodes for a script element. ?>
	<?php endif; ?>
</section>

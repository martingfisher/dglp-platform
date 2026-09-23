<?php
/**
 * One row in a public list: picture, chip, title, summary, meta.
 *
 * Used by the news and events lists and by search results, so the two can
 * never drift apart. Expects `$data['item']` (WP_Post) and, optionally,
 * `$data['meta_line']`, a callable taking the post and returning escaped
 * HTML; without it the row uses Cards::meta().
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Cards;

defined( 'ABSPATH' ) || exit;

$item      = $data['item'];
$meta_line = $data['meta_line'] ?? static function ( WP_Post $post ): string {
	$parts = Cards::meta( $post );
	return [] === $parts ? '' : '<p class="dgl-card__meta"><span>' . implode( '</span><span>', array_map( 'esc_html', $parts ) ) . '</span></p>';
};
?>
<li class="dgl-card">
	<a class="dgl-card__pic" href="<?php echo esc_url( (string) get_permalink( $item ) ); ?>" tabindex="-1" aria-hidden="true"><?php echo Cards::picture( $item, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></a>
	<div class="dgl-card__body">
		<p class="dgl-card__chip">
			<?php echo esc_html( Cards::chip( $item ) ); ?>
			<?php if ( \DGL\Events\Cancel::is_cancelled( (int) $item->ID ) ) : ?>
				<span class="dgl-pub__pin dgl-pub__pin--off"><?php esc_html_e( 'Cancelled', 'dgl-platform' ); ?></span>
			<?php elseif ( \DGL\Workflow\Pins::is_pinned( (int) $item->ID ) ) : ?>
				<span class="dgl-pub__pin"><?php esc_html_e( 'Featured', 'dgl-platform' ); ?></span>
			<?php endif; ?>
		</p>
		<h2 class="dgl-card__title"><a href="<?php echo esc_url( (string) get_permalink( $item ) ); ?>"><?php echo esc_html( get_the_title( $item ) ); ?></a></h2>
		<?php $summary = Cards::summary( $item ); ?>
		<?php if ( '' !== $summary ) : ?>
			<p class="dgl-card__summary"><?php echo esc_html( $summary ); ?></p>
		<?php endif; ?>
		<?php echo $meta_line( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
	</div>
</li>

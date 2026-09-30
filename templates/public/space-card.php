<?php
/**
 * One space on its venue's page: picture, name, size, capacity by layout,
 * what is in it, the rate, and a button to enquire about it.
 *
 * Expects `$data['space']` (WP_Post), `$data['meta']` (from
 * SpacesQuery::space_meta) and `$data['enquire_url']` (the enquiry
 * form's address, '' when there is no form).
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Cards;
use DGL\Schema\Types\Space;
use DGL\Schema\Types\Venue;
use DGL\Spaces\SpacesQuery;

defined( 'ABSPATH' ) || exit;

$space = $data['space'] ?? null;
$meta  = (array) ( $data['meta'] ?? [] );

if ( ! $space instanceof WP_Post ) {
	return;
}

$id         = (int) $space->ID;
$whole      = 'whole_building' === (string) ( $meta['space_type'] ?? '' );
$type       = Venue::labelled( Space::TYPES )[ (string) ( $meta['space_type'] ?? '' ) ] ?? '';
$size       = (int) ( $meta['size_m2'] ?? 0 );
$facilities = array_intersect_key( Venue::labelled( Space::FACILITIES ), array_flip( (array) ( $meta['space_facilities'] ?? [] ) ) );
$layouts    = [];

foreach ( Space::LAYOUTS as $key => $label ) {
	if ( (int) ( $meta[ $key ] ?? 0 ) > 0 ) {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the class constant.
		$layouts[ __( $label, 'dgl-platform' ) ] = (int) $meta[ $key ];
	}
}

$summary  = Cards::summary( $space, 40 );
$enquire  = (string) ( $data['enquire_url'] ?? '' );
$note     = trim( (string) ( $meta['rate_note'] ?? '' ) );
?>
<article class="dgl-space<?php echo $whole ? ' dgl-space--whole' : ''; ?>" id="space-<?php echo (int) $id; ?>">
	<div class="dgl-space__pic"><?php echo Cards::picture( $space, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></div>
	<div class="dgl-space__body">
		<div class="dgl-space__top">
			<h3 class="dgl-space__title"><?php echo esc_html( get_the_title( $space ) ); ?></h3>
			<p class="dgl-space__kind">
				<?php echo esc_html( $type ); ?>
				<?php if ( $size > 0 ) : ?>
					<span>
						<?php
						/* translators: %s: a number of square metres. */
						echo esc_html( sprintf( __( '%s m²', 'dgl-platform' ), number_format_i18n( $size ) ) );
						?>
					</span>
				<?php endif; ?>
			</p>
		</div>
		<?php if ( '' !== $summary ) : ?>
			<p class="dgl-space__summary"><?php echo esc_html( $summary ); ?></p>
		<?php endif; ?>
		<?php if ( [] !== $layouts ) : ?>
			<dl class="dgl-space__layouts" aria-label="<?php esc_attr_e( 'How many people, by layout', 'dgl-platform' ); ?>">
				<?php foreach ( $layouts as $label => $n ) : ?>
					<div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( number_format_i18n( $n ) ); ?></dd></div>
				<?php endforeach; ?>
			</dl>
		<?php endif; ?>
		<?php if ( [] !== $facilities ) : ?>
			<ul class="dgl-space__tags">
				<?php foreach ( $facilities as $label ) : ?>
					<li class="dgl-dir__tag"><?php echo esc_html( $label ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<div class="dgl-space__foot">
			<p class="dgl-space__rate">
				<strong><?php echo esc_html( SpacesQuery::rate_words( $meta ) ); ?></strong>
				<?php if ( '' !== $note ) : ?>
					<span><?php echo esc_html( $note ); ?></span>
				<?php endif; ?>
			</p>
			<?php if ( '' !== $enquire ) : ?>
				<a class="dgl-space__enquire" href="<?php echo esc_url( add_query_arg( 'space', $id, $enquire ) . '#enquire' ); ?>"><?php esc_html_e( 'Enquire', 'dgl-platform' ); ?></a>
			<?php endif; ?>
		</div>
	</div>
</article>

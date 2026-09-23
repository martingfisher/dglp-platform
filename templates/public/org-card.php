<?php
/**
 * One organisation card: logo or initial, name, where, blurb, areas of work.
 *
 * Used by the directory and by search results. Expects `$data['org_id']`.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Org\DirectoryQuery;
use DGL\Org\Options;

defined( 'ABSPATH' ) || exit;

$org_id = (int) $data['org_id'];
$name    = (string) get_the_title( $org_id );
$logo_id = (int) get_post_meta( $org_id, 'dgl_org_logo', true );
$blurb   = (string) get_post_meta( $org_id, 'dgl_org_description', true );
$ward    = Options::wards()[ (string) get_post_meta( $org_id, 'dgl_org_ward', true ) ] ?? '';
$city    = (string) get_post_meta( $org_id, 'dgl_org_city', true );
$areas   = array_intersect_key( Options::specialism(), array_flip( (array) get_post_meta( $org_id, 'dgl_org_specialism', true ) ) );
// The ward, else the town. Forum Central's City column holds street lines in places, so anything with a digit or a comma is not a town.
$where   = '' !== $ward ? $ward : ( 1 === preg_match( '/^[^\d,]+$/', $city ) ? $city : '' );
?>
<li class="dgl-dir__card">
	<a class="dgl-dir__cardlink" href="<?php echo esc_url( DirectoryQuery::url( $org_id ) ); ?>">
		<span class="dgl-dir__mark" aria-hidden="true">
			<?php if ( $logo_id > 0 ) : ?>
				<?php echo wp_get_attachment_image( $logo_id, 'thumbnail', false, [ 'alt' => '', 'loading' => 'lazy' ] ); ?>
			<?php else : ?>
				<span class="dgl-dir__initial"><?php echo esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ); ?></span>
			<?php endif; ?>
		</span>
		<span class="dgl-dir__cardbody">
			<span class="dgl-dir__name"><?php echo esc_html( $name ); ?></span>
			<?php if ( '' !== $where ) : ?>
				<span class="dgl-dir__where"><?php echo esc_html( $where ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== $blurb ) : ?>
				<span class="dgl-dir__blurb"><?php echo esc_html( wp_trim_words( $blurb, 24, '…' ) ); ?></span>
			<?php endif; ?>
			<?php if ( [] !== $areas ) : ?>
				<span class="dgl-dir__tags">
					<?php foreach ( array_slice( $areas, 0, 3 ) as $label ) : ?>
						<span class="dgl-dir__tag"><?php echo esc_html( $label ); ?></span>
					<?php endforeach; ?>
					<?php if ( count( $areas ) > 3 ) : ?>
						<span class="dgl-dir__tag dgl-dir__tag--more">+<?php echo esc_html( (string) ( count( $areas ) - 3 ) ); ?></span>
					<?php endif; ?>
				</span>
			<?php endif; ?>
		</span>
	</a>
</li>

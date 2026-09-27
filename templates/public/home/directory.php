<?php
/**
 * Find an organisation: the navy band with a search box into the
 * directory and area-of-work chips.
 *
 * Expects `$data['heading']`, `$data['org_total']`, `$data['dir_url']`,
 * `$data['areas']` (key => label), `$data['id']`.
 *
 * @var array<string,mixed> $data
 * @package DGL
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$heading   = (string) ( $data['heading'] ?? '' );
$org_total = (int) ( $data['org_total'] ?? 0 );
$dir_url   = (string) ( $data['dir_url'] ?? '' );
$areas     = (array) ( $data['areas'] ?? [] );
$id        = (string) ( $data['id'] ?? 'dgl-home-' . wp_unique_id() );
?>
<section class="dgl-home__section dgl-home__find" id="<?php echo esc_attr( $id ); ?>"<?php echo '' !== $heading ? ' aria-labelledby="' . esc_attr( $id ) . '-h"' : ' aria-label="' . esc_attr__( 'Find an organisation', 'dgl-platform' ) . '"'; ?>>
	<?php if ( '' !== $heading ) : ?>
		<h2 class="dgl-home__h2" id="<?php echo esc_attr( $id ); ?>-h"><?php echo esc_html( $heading ); ?></h2>
	<?php endif; ?>
	<p class="dgl-home__findlede">
		<?php
		if ( $org_total > 0 ) {
			/* translators: %s: a number. */
			echo esc_html( sprintf( _n( '%s verified organisation listed so far.', '%s verified organisations, every one checked by the partnership team.', $org_total, 'dgl-platform' ), number_format_i18n( $org_total ) ) );
		} else {
			esc_html_e( 'Every organisation listed is checked by the partnership team.', 'dgl-platform' );
		}
		?>
	</p>
	<form class="dgl-home__search" method="get" action="<?php echo esc_url( $dir_url ); ?>" role="search">
		<label class="dgl-home__srlabel" for="<?php echo esc_attr( $id ); ?>-q"><?php esc_html_e( 'Search the directory', 'dgl-platform' ); ?></label>
		<input class="dgl-home__input" id="<?php echo esc_attr( $id ); ?>-q" type="search" name="q" placeholder="<?php esc_attr_e( 'Try food, mental health, older people, Armley', 'dgl-platform' ); ?>">
		<button class="dgl-home__searchbutton" type="submit"><?php esc_html_e( 'Search', 'dgl-platform' ); ?></button>
	</form>
	<div class="dgl-home__chips">
		<?php if ( [] !== $areas ) : ?>
			<span class="dgl-home__chipslabel"><?php esc_html_e( 'Browse by area of work', 'dgl-platform' ); ?></span>
			<?php foreach ( $areas as $key => $label ) : ?>
				<a class="dgl-home__chip" href="<?php echo esc_url( add_query_arg( 'area', (string) $key, $dir_url ) ); ?>"><?php echo esc_html( (string) $label ); ?></a>
			<?php endforeach; ?>
		<?php endif; ?>
		<a class="dgl-home__browse" href="<?php echo esc_url( $dir_url ); ?>"><?php esc_html_e( 'Browse the full directory', 'dgl-platform' ); ?></a>
	</div>
</section>

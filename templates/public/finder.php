<?php
/**
 * The search dialog behind the magnifying glass in the header.
 *
 * `$data`: id, action, categories (key => label, '' first), term, type.
 *
 * @package DGL
 */

declare( strict_types=1 );

use DGL\Frontend\Finder;

defined( 'ABSPATH' ) || exit;

$id         = (string) ( $data['id'] ?? Finder::DIALOG_ID );
$categories = (array) ( $data['categories'] ?? [] );
$term       = (string) ( $data['term'] ?? '' );
$type       = (string) ( $data['type'] ?? '' );
?>
<div class="dgl-finder" id="<?php echo esc_attr( $id ); ?>" hidden data-dgl-finder>
	<div class="dgl-finder__scrim" data-dgl-finder-close></div>
	<div class="dgl-finder__card" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $id ); ?>-title">
		<div class="dgl-finder__head">
			<h2 class="dgl-finder__title" id="<?php echo esc_attr( $id ); ?>-title"><?php esc_html_e( 'Search the site', 'dgl-platform' ); ?></h2>
			<button type="button" class="dgl-finder__close" data-dgl-finder-close aria-label="<?php esc_attr_e( 'Close search', 'dgl-platform' ); ?>">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
			</button>
		</div>
		<form class="dgl-finder__form" role="search" method="get" action="<?php echo esc_url( (string) ( $data['action'] ?? home_url( '/' ) ) ); ?>">
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-q"><?php esc_html_e( 'What are you looking for?', 'dgl-platform' ); ?></label>
			<div class="dgl-finder__row">
				<input class="dgl-finder__input" id="<?php echo esc_attr( $id ); ?>-q" type="search" name="s" value="<?php echo esc_attr( $term ); ?>" placeholder="<?php esc_attr_e( 'An organisation, a place, a subject', 'dgl-platform' ); ?>" autocomplete="off" required>
				<button class="dgl-finder__go" type="submit"><?php esc_html_e( 'Search', 'dgl-platform' ); ?></button>
			</div>
			<fieldset class="dgl-finder__kinds">
				<legend class="dgl-finder__legend"><?php esc_html_e( 'Look in', 'dgl-platform' ); ?></legend>
				<div class="dgl-finder__chips">
					<?php echo Finder::chips( $categories, $type, $id . '-kind' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				</div>
			</fieldset>
		</form>
	</div>
</div>

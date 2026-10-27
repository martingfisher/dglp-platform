<?php
/**
 * The search bar in the header. Looks like a search box; pressing it opens
 * the site search dialog with its kinds to look in. Without JavaScript it
 * is a plain search form and lands on the same results page.
 *
 * Blocksy hands the item's `$attr` (data-id and friends).
 *
 * @package DGL
 */

defined( 'ABSPATH' ) || exit;

$attr = isset( $attr ) && is_array( $attr ) ? $attr : [];
$attr['class'] = trim( ( $attr['class'] ?? '' ) . ' dgl-finder-bar' );
?>
<div <?php echo blocksy_attr_to_html( $attr ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the theme escapes. ?>>
	<form class="dgl-finder-bar__form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<label class="screen-reader-text" for="dgl-finder-bar-q"><?php esc_html_e( 'Search the site', 'dgl-platform' ); ?></label>
		<input class="dgl-finder-bar__input" id="dgl-finder-bar-q" type="search" name="s" placeholder="<?php esc_attr_e( 'Search', 'dgl-platform' ); ?>" autocomplete="off" data-dgl-finder-open aria-haspopup="dialog" aria-controls="<?php echo esc_attr( \DGL\Frontend\Finder::DIALOG_ID ); ?>" aria-expanded="false">
		<button class="dgl-finder-bar__go" type="submit" aria-label="<?php esc_attr_e( 'Search', 'dgl-platform' ); ?>" data-dgl-finder-open>
			<?php echo \DGL\Frontend\Finder::icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static svg. ?>
		</button>
	</form>
</div>

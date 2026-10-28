<?php
/**
 * The search element in the header: the magnifier in a navy disc. Pressing
 * it opens the site search dialog with its kinds to look in.
 *
 * Blocksy hands the item's `$attr` (data-id and friends).
 *
 * @package DGL
 */

defined( 'ABSPATH' ) || exit;

$attr = isset( $attr ) && is_array( $attr ) ? $attr : [];
$attr['class'] = trim( ( $attr['class'] ?? '' ) . ' dgl-finder-disc' );
?>
<div <?php echo blocksy_attr_to_html( $attr ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the theme escapes. ?>>
	<?php echo \DGL\Frontend\Finder::trigger(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
</div>

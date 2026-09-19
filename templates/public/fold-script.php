<?php
/**
 * Folds a filter panel behind one button on a phone.
 *
 * The button is `[data-dgl-fold]` with `aria-controls` naming the panel. It
 * starts hidden and shows only under 560px; the panel starts open when
 * `aria-expanded` is true and always shows without JavaScript.
 *
 * @package DGL
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<script>
( function () {
	var narrow = window.matchMedia( '(max-width: 560px)' );

	if ( ! narrow ) {
		return;
	}

	Array.prototype.forEach.call( document.querySelectorAll( '[data-dgl-fold]' ), function ( toggle ) {
		var panel = document.getElementById( toggle.getAttribute( 'aria-controls' ) );

		if ( ! panel ) {
			return;
		}

		function apply() {
			if ( narrow.matches ) {
				toggle.hidden = false;
				panel.hidden  = 'true' !== toggle.getAttribute( 'aria-expanded' );
			} else {
				toggle.hidden = true;
				panel.hidden  = false;
			}
		}

		toggle.addEventListener( 'click', function () {
			toggle.setAttribute( 'aria-expanded', 'true' === toggle.getAttribute( 'aria-expanded' ) ? 'false' : 'true' );
			apply();

			if ( ! panel.hidden ) {
				var first = panel.querySelector( 'select, input' );
				if ( first ) { first.focus(); }
			}
		} );

		if ( narrow.addEventListener ) { narrow.addEventListener( 'change', apply ); } else { narrow.addListener( apply ); }
		apply();
	} );
}() );
</script>

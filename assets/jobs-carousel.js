/**
 * Previous and next buttons for the jobs carousel.
 *
 * The row of jobs scrolls sideways without this script: swipe, trackpad,
 * or the arrow keys once the row has focus. This adds the two buttons,
 * which move the row by most of its width, and greys out the one that
 * has nowhere to go. Greyed with aria-disabled rather than disabled, so a
 * keyboard user pressing Next to the end keeps their place on the button.
 * When every job fits, the buttons stay hidden. It never moves the row by
 * itself.
 */
( function () {
	'use strict';

	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	Array.prototype.forEach.call( document.querySelectorAll( '[data-dgl-carousel]' ), function ( frame ) {
		var list = frame.querySelector( '.dgl-jobs--carousel' );
		var controls = frame.querySelector( '[data-dgl-carousel-controls]' );

		if ( ! list || ! controls ) {
			return;
		}

		var buttons = controls.querySelectorAll( '[data-dgl-carousel-step]' );

		function update() {
			var max = list.scrollWidth - list.clientWidth;

			controls.hidden = max <= 1;

			Array.prototype.forEach.call( buttons, function ( button ) {
				var back = Number( button.getAttribute( 'data-dgl-carousel-step' ) ) < 0;

				button.setAttribute( 'aria-disabled', String( back ? list.scrollLeft <= 1 : list.scrollLeft >= max - 1 ) );
			} );
		}

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				if ( 'true' === button.getAttribute( 'aria-disabled' ) ) {
					return;
				}

				var step = Number( button.getAttribute( 'data-dgl-carousel-step' ) );

				list.scrollBy( { left: step * list.clientWidth * 0.9, behavior: reduce ? 'auto' : 'smooth' } );
			} );
		} );

		var pending = false;

		list.addEventListener( 'scroll', function () {
			if ( pending ) {
				return;
			}

			pending = true;
			window.requestAnimationFrame( function () {
				pending = false;
				update();
			} );
		}, { passive: true } );

		window.addEventListener( 'resize', update );
		update();
	} );
}() );

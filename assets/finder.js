/**
 * The search dialog behind the magnifying glass in the header.
 *
 * The button in the menu opens the dialog printed at the foot of the page;
 * Escape, the scrim or the close button shut it. While it is open, Tab
 * stays inside it and the page behind does not scroll; when it closes,
 * focus goes back to the button that opened it. The form itself is plain:
 * without this script the button is not shown and the results page keeps
 * its own search box.
 */
( function () {
	'use strict';

	document.documentElement.classList.add( 'dgl-js' );

	var dialog = document.querySelector( '[data-dgl-finder]' );

	if ( ! dialog ) {
		return;
	}

	var input = dialog.querySelector( 'input[type="search"]' );
	var opener = null;

	function focusable() {
		return Array.prototype.filter.call(
			dialog.querySelectorAll( 'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])' ),
			function ( el ) {
				return ! el.disabled && el.offsetParent !== null;
			}
		);
	}

	function open( trigger ) {
		opener = trigger || null;
		dialog.hidden = false;
		document.body.classList.add( 'dgl-finder-open' );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-dgl-finder-open]' ), function ( b ) {
			b.setAttribute( 'aria-expanded', 'true' );
		} );

		if ( input ) {
			input.focus();
			input.select();
		}
	}

	function close() {
		dialog.hidden = true;
		document.body.classList.remove( 'dgl-finder-open' );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-dgl-finder-open]' ), function ( b ) {
			b.setAttribute( 'aria-expanded', 'false' );
		} );

		if ( opener && typeof opener.focus === 'function' ) {
			opener.focus();
		}

		opener = null;
	}

	document.addEventListener( 'click', function ( event ) {
		var trigger = event.target.closest( '[data-dgl-finder-open]' );

		if ( trigger ) {
			event.preventDefault();
			open( trigger );
			return;
		}

		if ( ! dialog.hidden && event.target.closest( '[data-dgl-finder-close]' ) ) {
			event.preventDefault();
			close();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( dialog.hidden ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			event.preventDefault();
			close();
			return;
		}

		if ( 'Tab' !== event.key ) {
			return;
		}

		var items = focusable();

		if ( ! items.length ) {
			return;
		}

		var first = items[ 0 ];
		var last = items[ items.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		} else if ( ! dialog.contains( document.activeElement ) ) {
			event.preventDefault();
			first.focus();
		}
	} );
} )();

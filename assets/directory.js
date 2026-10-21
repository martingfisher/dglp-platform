/**
 * The directory's Service and Who-they-work-with filters: a panel of
 * grouped checkboxes behind one button.
 *
 * Without this script each filter is a native disclosure (`<details>`)
 * that opens a plain list of checkboxes in the page, and the form still
 * submits. With it: opening one panel closes the others, the button says
 * how many are ticked, a box at the top narrows the list as you type, and
 * Escape or a click elsewhere closes the panel. Nothing is submitted until
 * the Search button is pressed, so ticking several is one trip.
 */
( function () {
	'use strict';

	document.documentElement.classList.add( 'dgl-js' );

	var words = window.dglDirectory || {};
	var multis = Array.prototype.slice.call( document.querySelectorAll( '[data-dgl-multi]' ) );

	if ( ! multis.length ) {
		return;
	}

	function text( key, fallback ) {
		return typeof words[ key ] === 'string' ? words[ key ] : fallback;
	}

	function fill( template, a, b ) {
		return template.replace( '%1$d', String( a ) ).replace( '%2$d', String( b ) ).replace( '%d', String( a ) );
	}

	function closeOthers( except ) {
		multis.forEach( function ( other ) {
			var d = other.querySelector( 'details' );

			if ( d && d !== except && d.open ) {
				d.open = false;
			}
		} );
	}

	multis.forEach( function ( multi ) {
		var details = multi.querySelector( 'details' );
		var summary = multi.querySelector( 'summary' );
		var label   = multi.querySelector( '[data-dgl-multi-text]' );
		var find    = multi.querySelector( '[data-dgl-multi-find]' );
		var status  = multi.querySelector( '[data-dgl-multi-status]' );
		var rows    = Array.prototype.slice.call( multi.querySelectorAll( '.dgl-dir__check' ) );
		var groups  = Array.prototype.slice.call( multi.querySelectorAll( '.dgl-dir__ddgroup' ) );

		if ( ! details || ! summary || ! label ) {
			return;
		}

		function count() {
			var n = multi.querySelectorAll( 'input[type="checkbox"]:checked' ).length;

			label.textContent = n > 0 ? fill( text( 'selected', '%d selected' ), n ) : text( 'any', 'Any' );
		}

		function narrow() {
			var term  = find ? find.value.trim().toLowerCase() : '';
			var shown = 0;

			rows.forEach( function ( row ) {
				var group  = row.closest( '.dgl-dir__ddgroup' );
				var legend = group ? group.querySelector( 'legend' ) : null;
				var hay    = ( ( legend ? legend.textContent : '' ) + ' ' + row.textContent ).toLowerCase();
				var hit    = '' === term || hay.indexOf( term ) !== -1;

				row.hidden = ! hit;

				if ( hit ) {
					shown += 1;
				}
			} );

			groups.forEach( function ( group ) {
				group.hidden = ! group.querySelector( '.dgl-dir__check:not([hidden])' );
			} );

			if ( status ) {
				status.textContent = '' === term ? '' : fill( text( 'shown', '%1$d of %2$d shown' ), shown, rows.length );
			}
		}

		multi.addEventListener( 'change', count );

		details.addEventListener( 'toggle', function () {
			if ( details.open ) {
				closeOthers( details );

				if ( find ) {
					find.focus();
				}
			}
		} );

		if ( find ) {
			find.addEventListener( 'input', narrow );
		}

		multi.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && details.open ) {
				event.preventDefault();
				details.open = false;
				summary.focus();
			}
		} );

		count();
	} );

	document.addEventListener( 'click', function ( event ) {
		multis.forEach( function ( multi ) {
			var d = multi.querySelector( 'details' );

			if ( d && d.open && ! multi.contains( event.target ) ) {
				d.open = false;
			}
		} );
	} );
}() );

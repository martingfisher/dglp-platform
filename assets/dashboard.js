/**
 * DGLP Platform dashboard.
 *
 * Progressive enhancement only. With JavaScript off every field is visible and
 * every form still submits and validates: nothing here is load-bearing. It
 * exists to stop the form asking questions that do not apply, and to stop the
 * two mistakes members actually make, which are losing work by navigating away
 * and double-clicking Continue.
 */
( function () {
	'use strict';

	/** The value of a control, normalised so a checkbox compares like a select. */
	function valueOf( el ) {
		if ( ! el ) {
			return '';
		}

		if ( 'checkbox' === el.type ) {
			return el.checked ? '1' : '0';
		}

		return el.value;
	}

	/**
	 * Show or hide fields that only apply given another answer.
	 *
	 * Hidden fields are also disabled, so they are not submitted. That matters:
	 * without it, un-ticking "this event repeats" would leave the repeat note
	 * stored against an event that does not repeat, and the reviewer would read
	 * a contradiction.
	 */
	function applyDependencies( form ) {
		var rows = form.querySelectorAll( '[data-dgl-depends]' );

		Array.prototype.forEach.call( rows, function ( row ) {
			var controllerId = 'dgl-' + row.getAttribute( 'data-dgl-depends' );
			var controller = form.querySelector( '#' + CSS.escape( controllerId ) );

			if ( ! controller ) {
				return;
			}

			var allowed = ( row.getAttribute( 'data-dgl-depends-on' ) || '' ).split( '|' );

			function sync() {
				var show = allowed.indexOf( valueOf( controller ) ) !== -1;

				row.hidden = ! show;

				Array.prototype.forEach.call(
					row.querySelectorAll( 'input, select, textarea' ),
					function ( field ) {
						field.disabled = ! show;
					}
				);
			}

			controller.addEventListener( 'change', sync );
			controller.addEventListener( 'input', sync );
			sync();
		} );
	}

	/**
	 * A quiet character count on anything with a limit.
	 *
	 * Summaries have a 300 character cap and people write to the box, not to the
	 * limit. Being told at the end that it is too long means rewriting it.
	 */
	function addCounters( form ) {
		var fields = form.querySelectorAll( 'textarea[maxlength], input[maxlength]' );

		Array.prototype.forEach.call( fields, function ( field ) {
			var max = parseInt( field.getAttribute( 'maxlength' ), 10 );

			if ( ! max || max < 60 ) {
				return;
			}

			var note = document.createElement( 'p' );
			note.className = 'dgl-counter';
			note.setAttribute( 'aria-live', 'polite' );

			// End of the row, so the count sits below the guidance rather than
			// pushing between the field and the sentence explaining it.
			var row = field.closest( '.dgl-field-row' );
			( row || field.parentNode ).appendChild( note );

			function update() {
				var left = max - field.value.length;
				note.textContent = left + ' characters left';
				note.classList.toggle( 'dgl-counter--low', left < 25 );
			}

			field.addEventListener( 'input', update );
			update();
		} );
	}

	/**
	 * Refuse a file that is over the limit before it leaves the browser.
	 *
	 * The server checks again, but a file the web server's own limit
	 * rejects never reaches PHP, and what the member sees then is a bare
	 * 403 page with their work gone. So the size is read here, the control
	 * is cleared, the reason is written under it, and the form will not
	 * submit until a smaller file is chosen.
	 */
	function guardFileSize( form ) {
		var inputs = form.querySelectorAll( 'input[type="file"][data-dgl-max-bytes]' );

		Array.prototype.forEach.call( inputs, function ( input ) {
			var max = parseInt( input.getAttribute( 'data-dgl-max-bytes' ), 10 );
			var row = input.closest ? input.closest( '.dgl-field-row' ) : input.parentNode;
			var note = null;

			function clear() {
				if ( note && note.parentNode ) {
					note.parentNode.removeChild( note );
				}
				note = null;
				if ( row ) {
					row.classList.remove( 'dgl-field-row--error' );
				}
				input.removeAttribute( 'aria-invalid' );
			}

			function human( bytes ) {
				return bytes >= 1048576 ? ( bytes / 1048576 ).toFixed( 1 ) + 'MB' : Math.round( bytes / 1024 ) + 'KB';
			}

			input.addEventListener( 'change', function () {
				clear();

				var file = input.files && input.files[ 0 ];

				if ( ! file || ! max || file.size <= max ) {
					return;
				}

				note = document.createElement( 'p' );
				note.className = 'dgl-error';
				note.id = input.id + '-size';
				note.setAttribute( 'role', 'alert' );
				note.textContent = input.getAttribute( 'data-dgl-max-message' ).replace( '%s', human( file.size ) );
				input.insertAdjacentElement( 'afterend', note );
				input.setAttribute( 'aria-invalid', 'true' );
				if ( row ) {
					row.classList.add( 'dgl-field-row--error' );
				}
				// Clearing the control is what stops the file being sent.
				input.value = '';
			} );

			form.addEventListener( 'submit', function ( event ) {
				var file = input.files && input.files[ 0 ];

				if ( file && max && file.size > max ) {
					event.preventDefault();
					event.stopImmediatePropagation();
					input.focus();
				}
			}, true );
		} );
	}

	/**
	 * Shrink a photo in the browser before it is sent.
	 *
	 * The server cuts every image to 1600px and strips its metadata anyway,
	 * so doing the same here first loses nothing and gains three things: a
	 * phone photo of 8MB becomes a few hundred KB and uploads in a moment;
	 * camera and editor metadata never leaves the device; and a firewall
	 * that dislikes something inside a file's bytes never sees those bytes.
	 * One specific 1.7MB image was refused by the web server with a 403
	 * while its neighbour passed, which is what this is for. Anything that
	 * cannot be redrawn is sent as it was.
	 */
	function shrinkImages( form ) {
		var inputs = form.querySelectorAll( 'input[type="file"][accept*="image"]' );
		var MAX_EDGE = 1600;

		if ( ! window.DataTransfer || ! window.createImageBitmap || ! document.createElement( 'canvas' ).toBlob ) {
			return;
		}

		Array.prototype.forEach.call( inputs, function ( input ) {
			input.addEventListener( 'change', function () {
				var file = input.files && input.files[ 0 ];

				if ( ! file || ! /^image\/(jpeg|png|webp)$/.test( file.type ) ) {
					return;
				}

				var keepPng = 'image/png' === file.type;

				createImageBitmap( file ).then( function ( bitmap ) {
					var scale = Math.min( 1, MAX_EDGE / Math.max( bitmap.width, bitmap.height ) );
					var canvas = document.createElement( 'canvas' );
					canvas.width = Math.round( bitmap.width * scale );
					canvas.height = Math.round( bitmap.height * scale );
					canvas.getContext( '2d' ).drawImage( bitmap, 0, 0, canvas.width, canvas.height );

					return new Promise( function ( resolve ) {
						canvas.toBlob( resolve, keepPng ? 'image/png' : 'image/jpeg', 0.86 );
					} );
				} ).then( function ( blob ) {
					if ( ! blob || ( blob.size >= file.size && blob.type === file.type ) ) {
						return;
					}

					var name = file.name.replace( /\.[^.]+$/, '' ) + ( keepPng ? '.png' : '.jpg' );
					var transfer = new DataTransfer();
					transfer.items.add( new File( [ blob ], name, { type: blob.type, lastModified: Date.now() } ) );
					input.files = transfer.files;
					input.setAttribute( 'data-dgl-shrunk', String( blob.size ) );
				} ).catch( function () {
					// Not an image the browser can decode: leave it to the server.
				} );
			} );
		} );
	}

	/**
	 * Warn before losing typed work, but never when the member is deliberately
	 * saving. Every button in the wizard submits, so submission clears the flag.
	 */
	function guardUnsavedWork( form ) {
		var dirty = false;
		var leaving = false;

		form.addEventListener( 'input', function () {
			dirty = true;
		} );

		form.addEventListener( 'submit', function () {
			leaving = true;
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! dirty || leaving ) {
				return;
			}

			event.preventDefault();
			event.returnValue = '';
		} );
	}

	/**
	 * Stop a second click creating a second save, without losing which button
	 * was pressed.
	 *
	 * A disabled button is not submitted, so its name and value would vanish and
	 * the wizard would not know whether the member pressed Continue, Back or
	 * Save and close. The value is copied into a hidden field first.
	 */
	function guardDoubleSubmit( form ) {
		var submitted = false;
		var pressed = null;

		Array.prototype.forEach.call( form.querySelectorAll( 'button[type="submit"]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				pressed = button;
			} );
		} );

		form.addEventListener( 'submit', function ( event ) {
			if ( submitted ) {
				event.preventDefault();
				return;
			}

			submitted = true;

			// The visual editor holds its content until told to write it back.
			// Without this the description saves as whatever it was on load.
			if ( window.tinymce && window.tinymce.triggerSave ) {
				window.tinymce.triggerSave();
			}

			if ( pressed && pressed.name ) {
				var carry = document.createElement( 'input' );
				carry.type = 'hidden';
				carry.name = pressed.name;
				carry.value = pressed.value;
				form.appendChild( carry );
			}

			Array.prototype.forEach.call( form.querySelectorAll( 'button[type="submit"]' ), function ( button ) {
				button.disabled = true;
				button.classList.add( 'is-working' );
			} );

			/*
			 * Say so on the button that was pressed. An image upload can take
			 * a few seconds, and a button that merely dims reads as a page
			 * that has stopped.
			 */
			if ( pressed ) {
				pressed.setAttribute( 'aria-live', 'polite' );
				pressed.textContent = pressed.getAttribute( 'data-dgl-working' ) || ( 'submit' === pressed.value ? 'Sending\u2026' : 'Saving\u2026' );
			}
		} );
	}

	/*
	 * Ask before anything that throws work away.
	 *
	 * Progressive enhancement, like everything else here: with JavaScript off
	 * the button still works, it just does not ask first. That is the right way
	 * round. A discard that silently fails because a script did not load would
	 * be worse than one that happens without a prompt.
	 */
	function confirmDestructive() {
		document.addEventListener( 'click', function ( event ) {
			var trigger = event.target.closest ? event.target.closest( '[data-dgl-confirm]' ) : null;

			if ( ! trigger ) {
				return;
			}

			if ( ! window.confirm( trigger.getAttribute( 'data-dgl-confirm' ) ) ) {
				event.preventDefault();
				event.stopPropagation();
			}
		} );
	}

	/*
	 * The sidebar is a <details> element. On a phone it should start collapsed
	 * so the page opens at the content rather than after nine navigation links;
	 * on a wide screen it should always be open and show no toggle.
	 *
	 * The `open` attribute cannot be removed by CSS, so this does it. It is
	 * progressive enhancement: with JavaScript off the markup keeps `open` and
	 * every member simply sees the full navigation, which is usable, just
	 * longer. That is the right way round for a failure.
	 */
	function responsiveMenu() {
		var menu = document.getElementById( 'dgl-menu' );

		if ( ! menu || ! window.matchMedia ) {
			return;
		}

		var narrow = window.matchMedia( '(max-width: 782px)' );

		function apply( query ) {
			// Only forced shut on a narrow screen. On a wide one it is opened
			// and left alone, because there is no toggle to reopen it with.
			if ( query.matches ) {
				menu.removeAttribute( 'open' );
			} else {
				menu.setAttribute( 'open', '' );
			}
		}

		apply( narrow );

		if ( narrow.addEventListener ) {
			narrow.addEventListener( 'change', apply );
		}
	}

	function init() {
		var forms = document.querySelectorAll( '.dgl-form' );

		Array.prototype.forEach.call( forms, function ( form ) {
			applyDependencies( form );
			addCounters( form );
			guardFileSize( form );
			shrinkImages( form );
			guardUnsavedWork( form );
			guardDoubleSubmit( form );
		} );

		confirmDestructive();
		responsiveMenu();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

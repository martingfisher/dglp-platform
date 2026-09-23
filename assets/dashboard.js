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
		var syncs = [];

		// Every change re-runs every row in document order, so a block inside a
		// block that has just been shown is disabled again by its own rule.
		function runAll() {
			syncs.forEach( function ( sync ) {
				sync();
			} );
		}

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

			syncs.push( sync );
			controller.addEventListener( 'change', runAll );
			controller.addEventListener( 'input', runAll );
		} );

		runAll();
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

		// The uploader waits for this rather than the raw change event.
		form.setAttribute( 'data-dgl-shrinks', '1' );

		function ready( input ) {
			input.dispatchEvent( new CustomEvent( 'dgl:ready' ) );
		}

		Array.prototype.forEach.call( inputs, function ( input ) {
			input.addEventListener( 'change', function () {
				var file = input.files && input.files[ 0 ];

				if ( ! file || ! /^image\/(jpeg|png|webp)$/.test( file.type ) ) {
					ready( input );
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
				} ).then( function () {
					ready( input );
				} );
			} );
		} );
	}

	/**
	 * Send a picture up as soon as it is chosen.
	 *
	 * The file used to travel with the step, so nothing could describe it
	 * until the member pressed Save. Now it goes straight away: the preview
	 * appears, the hidden id is set so the save keeps it, and the
	 * description AltText.ai wrote lands in the box while the member is
	 * still looking at the step. If the upload cannot happen, the file
	 * stays in the control and the ordinary save sends it as before.
	 */
	function uploadOnChoose( form ) {
		var post = form.getAttribute( 'data-dgl-post' );
		var url = form.getAttribute( 'data-dgl-ajax' );
		var nonce = form.querySelector( 'input[name="_wpnonce"]' );
		var words = window.dglUpload || {};

		if ( ! post || ! url || ! nonce || ! window.fetch || ! window.FormData ) {
			return;
		}

		Array.prototype.forEach.call( form.querySelectorAll( 'input[type="file"][data-dgl-upload]' ), function ( input ) {
			var key = input.getAttribute( 'data-dgl-upload' );
			var hidden = form.querySelector( 'input[type="hidden"][name="dgl[' + key + ']"]' );
			var altBox = form.querySelector( '#dgl-' + key + '_alt' );
			var status = document.createElement( 'p' );
			var busy = false;

			status.className = 'dgl-help dgl-upload-status';
			status.setAttribute( 'aria-live', 'polite' );
			input.insertAdjacentElement( 'afterend', status );

			function say( text ) {
				status.textContent = text || '';
			}

			function showPreview( html ) {
				var current = input.parentNode.querySelector( '.dgl-image-current' );

				if ( ! current ) {
					current = document.createElement( 'div' );
					current.className = 'dgl-image-current';
					input.parentNode.insertBefore( current, input );
				}

				current.innerHTML = html;
			}

			function fillAlt( text ) {
				if ( altBox && text && ! altBox.value.trim() ) {
					altBox.value = text;
					return true;
				}

				return false;
			}

			function pollAlt( id, left ) {
				if ( left <= 0 ) {
					say( words.noAlt || '' );
					return;
				}

				setTimeout( function () {
					var fd = new FormData();
					fd.append( 'action', 'dgl_image_alt' );
					fd.append( '_wpnonce', nonce.value );
					fd.append( 'post', post );
					fd.append( 'field', key );
					fd.append( 'attachment', id );

					fetch( url, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
						return r.json();
					} ).then( function ( json ) {
						if ( json && json.success && json.data.alt ) {
							fillAlt( json.data.alt );
							say( words.suggested || '' );
						} else {
							pollAlt( id, left - 1 );
						}
					} ).catch( function () {
						pollAlt( id, left - 1 );
					} );
				}, 3000 );
			}

			function send() {
				var file = input.files && input.files[ 0 ];
				var max = parseInt( input.getAttribute( 'data-dgl-max-bytes' ) || '0', 10 );

				if ( busy || ! file || ( max && file.size > max ) ) {
					return;
				}

				busy = true;
				say( words.uploading || '' );

				var fd = new FormData();
				fd.append( 'action', 'dgl_upload_image' );
				fd.append( '_wpnonce', nonce.value );
				fd.append( 'post', post );
				fd.append( 'field', key );
				fd.append( 'dgl_file_' + key, file, file.name );

				fetch( url, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
					return r.json();
				} ).then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( json && json.data && json.data.message ? json.data.message : '' );
					}

					if ( hidden ) {
						hidden.value = json.data.id;
					}

					// Stored now; the save must not send it a second time.
					input.value = '';
					input.removeAttribute( 'data-dgl-shrunk' );
					showPreview( json.data.preview );

					if ( altBox && ! altBox.value.trim() ) {
						if ( fillAlt( json.data.alt ) ) {
							say( words.suggested || '' );
						} else {
							say( words.describing || '' );
							pollAlt( json.data.id, 6 );
						}
					} else {
						say( words.done || '' );
					}
				} ).catch( function ( e ) {
					// The file is still in the control; the save will send it.
					say( ( e && e.message ) || words.failed || '' );
				} ).then( function () {
					busy = false;
				} );
			}

			if ( '1' === form.getAttribute( 'data-dgl-shrinks' ) ) {
				input.addEventListener( 'dgl:ready', send );
			} else {
				input.addEventListener( 'change', send );
			}
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

	/*
	 * The join form: which of its blocks show follows a radio group, the
	 * way applyDependencies follows one field. Hidden means disabled, so a
	 * block the person did not choose sends nothing.
	 */
	function revealByChoice( form ) {
		var blocks = form.querySelectorAll( '[data-dgl-when]' );
		var radios = form.querySelectorAll( 'input[type="radio"][name="dgl_intent"]' );

		if ( ! blocks.length || ! radios.length ) {
			return;
		}

		function chosen() {
			var value = '';

			Array.prototype.forEach.call( radios, function ( radio ) {
				if ( radio.checked ) {
					value = radio.value;
				}
			} );

			return value;
		}

		function sync() {
			var value = chosen();

			Array.prototype.forEach.call( blocks, function ( block ) {
				var show = ( block.getAttribute( 'data-dgl-when' ) || '' ).split( '|' ).indexOf( value ) !== -1;

				block.hidden = ! show;

				Array.prototype.forEach.call(
					block.querySelectorAll( 'input, select, textarea, button' ),
					function ( field ) {
						field.disabled = ! show;
					}
				);
			} );
		}

		Array.prototype.forEach.call( radios, function ( radio ) {
			radio.addEventListener( 'change', sync );
		} );

		sync();
	}

	/*
	 * A search box over a long select. The select stays in the form and
	 * carries the value; it is only hidden once this has taken over, so
	 * without JavaScript it is the control. Typing filters the options to
	 * the first eight whose words all start somewhere in the name; arrows
	 * and Enter choose; a chosen name shows with a Change button.
	 */
	function orgPicker( form ) {
		var words = window.dglJoin || {};

		function normalise( text ) {
			return String( text || '' ).toLowerCase().replace( /^the\s+/, '' ).replace( /[^a-z0-9 ]+/g, ' ' ).replace( /\s+/g, ' ' ).trim();
		}

		Array.prototype.forEach.call( form.querySelectorAll( '[data-dgl-picker]' ), function ( wrap ) {
			var select = wrap.querySelector( 'select' );

			if ( ! select ) {
				return;
			}

			var options = Array.prototype.filter.call( select.options, function ( option ) {
				return '' !== option.value;
			} ).map( function ( option ) {
				return {
					id: option.value,
					label: option.textContent.replace( /\s+/g, ' ' ).trim(),
					key: normalise( option.textContent ),
					pending: '1' === option.getAttribute( 'data-pending' )
				};
			} );

			var listId = select.id + '_list';
			var search = document.createElement( 'input' );
			var list = document.createElement( 'ul' );
			var chosen = document.createElement( 'p' );
			var chosenName = document.createElement( 'span' );
			var change = document.createElement( 'button' );
			var shown = [];
			var active = -1;

			search.type = 'text';
			search.className = 'dgl-field dgl-picker__search';
			search.setAttribute( 'role', 'combobox' );
			search.setAttribute( 'aria-autocomplete', 'list' );
			search.setAttribute( 'aria-expanded', 'false' );
			search.setAttribute( 'aria-controls', listId );
			search.setAttribute( 'autocomplete', 'off' );
			search.setAttribute( 'spellcheck', 'false' );
			search.placeholder = words.search || 'Start typing the name';
			search.id = select.id + '_search';

			list.className = 'dgl-picker__list';
			list.id = listId;
			list.setAttribute( 'role', 'listbox' );
			list.hidden = true;

			chosen.className = 'dgl-picker__chosen';
			chosen.hidden = true;
			change.type = 'button';
			change.className = 'dgl-picker__change';
			change.textContent = words.change || 'Change';
			chosen.appendChild( chosenName );
			chosen.appendChild( document.createTextNode( ' ' ) );
			chosen.appendChild( change );

			var label = form.querySelector( 'label[for="' + select.id + '"]' );

			if ( label ) {
				label.setAttribute( 'for', search.id );
			}

			select.classList.add( 'dgl-visually-hidden' );
			select.setAttribute( 'tabindex', '-1' );
			select.setAttribute( 'aria-hidden', 'true' );
			wrap.appendChild( search );
			wrap.appendChild( list );
			wrap.appendChild( chosen );

			function close() {
				list.hidden = true;
				search.setAttribute( 'aria-expanded', 'false' );
				search.removeAttribute( 'aria-activedescendant' );
				active = -1;
			}

			function highlight( index ) {
				active = index;

				Array.prototype.forEach.call( list.children, function ( item, i ) {
					var on = i === index && item.hasAttribute( 'data-id' );
					item.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					item.classList.toggle( 'is-active', on );

					if ( on ) {
						search.setAttribute( 'aria-activedescendant', item.id );
						item.scrollIntoView( { block: 'nearest' } );
					}
				} );
			}

			function choose( option ) {
				select.value = option.id;
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				chosenName.textContent = ( words.chosen || 'Chosen: %s.' ).replace( '%s', option.label );
				chosen.hidden = false;
				search.hidden = true;
				close();
				change.focus();
			}

			function render() {
				var query = normalise( search.value );
				var terms = query.split( ' ' ).filter( Boolean );

				list.innerHTML = '';
				shown = [];

				if ( ! terms.length ) {
					close();
					return;
				}

				shown = options.filter( function ( option ) {
					return terms.every( function ( term ) {
						return option.key.indexOf( term ) !== -1;
					} );
				} ).slice( 0, 8 );

				if ( ! shown.length ) {
					var none = document.createElement( 'li' );
					none.className = 'dgl-picker__none';
					none.textContent = words.noResults || 'Nothing on the list matches that.';
					list.appendChild( none );
				}

				shown.forEach( function ( option, i ) {
					var item = document.createElement( 'li' );
					item.className = 'dgl-picker__option' + ( option.pending ? ' dgl-picker__option--pending' : '' );
					item.id = listId + '_' + i;
					item.setAttribute( 'role', 'option' );
					item.setAttribute( 'aria-selected', 'false' );
					item.setAttribute( 'data-id', option.id );
					item.textContent = option.label;
					item.addEventListener( 'mousedown', function ( event ) {
						event.preventDefault();
						choose( option );
					} );
					list.appendChild( item );
				} );

				list.hidden = false;
				search.setAttribute( 'aria-expanded', 'true' );
				active = -1;
			}

			search.addEventListener( 'input', render );
			search.addEventListener( 'focus', function () {
				if ( search.value ) {
					render();
				}
			} );
			search.addEventListener( 'blur', function () {
				setTimeout( close, 150 );
			} );
			search.addEventListener( 'keydown', function ( event ) {
				if ( 'ArrowDown' === event.key && shown.length ) {
					event.preventDefault();
					highlight( ( active + 1 ) % shown.length );
				} else if ( 'ArrowUp' === event.key && shown.length ) {
					event.preventDefault();
					highlight( ( active - 1 + shown.length ) % shown.length );
				} else if ( 'Enter' === event.key ) {
					// Enter in the search box picks, never submits a half-filled form.
					event.preventDefault();

					if ( active >= 0 && shown[ active ] ) {
						choose( shown[ active ] );
					} else if ( 1 === shown.length ) {
						choose( shown[ 0 ] );
					}
				} else if ( 'Escape' === event.key ) {
					close();
				}
			} );

			change.addEventListener( 'click', function () {
				select.value = '';
				chosen.hidden = true;
				search.hidden = false;
				search.value = '';
				search.focus();
			} );

			// Another control on the page may choose for the person: the
			// review screen's "It is this one" beside a likely match.
			wrap.dglChoose = function ( id ) {
				options.some( function ( option ) {
					if ( option.id === String( id ) ) {
						choose( option );
						return true;
					}

					return false;
				} );
			};

			// A server re-render keeps the choice; show it as chosen.
			if ( '' !== select.value ) {
				options.some( function ( option ) {
					if ( option.id === select.value ) {
						choose( option );
						search.blur();
						return true;
					}

					return false;
				} );
			}
		} );
	}

	/*
	 * On the join page, ask the list while the person types their
	 * organisation's details, so a duplicate is caught before a password
	 * is chosen. The server runs the same check on submit; this only makes
	 * that round trip rare. "Yes, that is mine" switches to the picker with
	 * that organisation chosen; "No, none of these" records the answer in a
	 * hidden field that any later change to the details clears.
	 */
	function duplicateCheck( form ) {
		var url = form.getAttribute( 'data-dgl-matches' );
		var token = form.getAttribute( 'data-dgl-token' );
		var block = form.querySelector( '[data-dgl-when="register"]' );

		if ( ! url || ! token || ! block || ! window.fetch ) {
			return;
		}

		var words = window.dglJoin || {};
		var fields = {
			name: form.querySelector( '#dgl_org_name' ),
			website: form.querySelector( '#dgl_org_website' ),
			number: form.querySelector( '#dgl_org_number' ),
			postcode: form.querySelector( '#dgl_org_postcode' )
		};

		if ( ! fields.name ) {
			return;
		}

		var box = document.createElement( 'div' );
		var confirmed = null;
		var timer = null;
		var last = '';

		box.className = 'dgl-matches dgl-matches--live';
		box.setAttribute( 'role', 'status' );
		box.hidden = true;
		fields.name.closest( '.dgl-field-row' ).parentNode.insertBefore( box, fields.name.closest( '.dgl-field-row' ) );

		function clearConfirmed() {
			if ( confirmed ) {
				confirmed.parentNode.removeChild( confirmed );
				confirmed = null;
			}
		}

		function choose( id ) {
			var radio = form.querySelector( 'input[name="dgl_intent"][value="claim"]' );
			var select = form.querySelector( '#dgl_claim_org' );

			if ( radio ) {
				radio.checked = true;
				radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}

			if ( select ) {
				var wrap = select.closest( '[data-dgl-picker]' );

				if ( wrap && wrap.dglChoose ) {
					wrap.dglChoose( id );
				} else {
					select.value = String( id );
				}
			}

			if ( radio ) {
				radio.closest( 'fieldset' ).scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		}

		function render( data ) {
			box.innerHTML = '';

			if ( ! data.matches.length ) {
				box.hidden = true;
				return;
			}

			var title = document.createElement( 'h3' );
			var help = document.createElement( 'p' );
			var list = document.createElement( 'ul' );

			title.className = 'dgl-matches__title';
			title.textContent = data.hard ? ( words.blocked || 'That looks like %s, which is already on the list.' ).replace( '%s', data.matches[ 0 ].name ) : ( words.looksLike || 'Is it one of these?' );
			help.textContent = data.hard ? ( words.blockedHelp || '' ) : ( words.looksLikeHelp || '' );
			list.className = 'dgl-matches__list';

			data.matches.forEach( function ( match ) {
				var item = document.createElement( 'li' );
				var who = document.createElement( 'div' );
				var name = document.createElement( 'strong' );
				var why = document.createElement( 'span' );
				var take = document.createElement( 'button' );

				item.className = 'dgl-match dgl-match--' + match.strength;
				who.className = 'dgl-match__who';
				name.textContent = match.name;
				why.className = 'dgl-match__why';
				why.textContent = ( match.pending ? ( words.pending || 'awaiting verification' ) + ', ' : '' ) + match.why;
				take.type = 'button';
				take.className = 'dgl-button dgl-button--secondary dgl-match__take';
				take.textContent = words.take || 'Yes, that is mine';
				take.addEventListener( 'click', function () {
					choose( match.id );
				} );

				who.appendChild( name );
				who.appendChild( why );
				item.appendChild( who );
				item.appendChild( take );
				list.appendChild( item );
			} );

			box.appendChild( title );
			box.appendChild( help );
			box.appendChild( list );

			if ( ! data.hard ) {
				var none = document.createElement( 'button' );
				none.type = 'button';
				none.className = 'dgl-button dgl-button--secondary';
				none.textContent = words.none || 'No, none of these';
				none.addEventListener( 'click', function () {
					clearConfirmed();
					confirmed = document.createElement( 'input' );
					confirmed.type = 'hidden';
					confirmed.name = 'dgl_confirmed_new';
					confirmed.value = '1';
					form.appendChild( confirmed );
					box.hidden = true;
				} );
				box.appendChild( none );
			}

			box.hidden = false;
		}

		function ask() {
			var fd = new FormData();
			var key;

			fd.append( 'action', 'dgl_join_matches' );
			fd.append( 'token', token );

			for ( key in fields ) {
				if ( fields[ key ] ) {
					fd.append( key, fields[ key ].value );
				}
			}

			var signature = fd.get( 'name' ) + '|' + fd.get( 'website' ) + '|' + fd.get( 'number' ) + '|' + fd.get( 'postcode' );

			if ( signature === last ) {
				return;
			}

			last = signature;

			fetch( url, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) {
				return r.ok ? r.json() : null;
			} ).then( function ( json ) {
				if ( json && json.success && json.data ) {
					render( json.data );
				}
			} ).catch( function () {
				// The server checks on submit; nothing to say here.
			} );
		}

		Object.keys( fields ).forEach( function ( key ) {
			if ( ! fields[ key ] ) {
				return;
			}

			fields[ key ].addEventListener( 'input', function () {
				clearConfirmed();
				clearTimeout( timer );
				timer = setTimeout( ask, 600 );
			} );
			fields[ key ].addEventListener( 'blur', function () {
				clearTimeout( timer );
				ask();
			} );
		} );
	}

	/*
	 * "It is this one" beside a likely match on the review screen fills the
	 * attach picker with that organisation and takes the person to it.
	 */
	function attachShortcut() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-dgl-attach]' );

			if ( ! button ) {
				return;
			}

			var select = document.getElementById( 'dgl-attach-org' );

			if ( ! select ) {
				return;
			}

			var wrap = select.closest( '[data-dgl-picker]' );

			if ( wrap && wrap.dglChoose ) {
				wrap.dglChoose( button.getAttribute( 'data-dgl-attach' ) );
			} else {
				select.value = button.getAttribute( 'data-dgl-attach' );
			}

			var card = document.getElementById( 'dgl-attach' );

			if ( card ) {
				card.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		} );
	}

	function init() {
		var forms = document.querySelectorAll( '.dgl-form' );

		Array.prototype.forEach.call( forms, function ( form ) {
			applyDependencies( form );
			revealByChoice( form );
			orgPicker( form );
			duplicateCheck( form );
			addCounters( form );
			guardFileSize( form );
			shrinkImages( form );
			uploadOnChoose( form );
			guardUnsavedWork( form );
			guardDoubleSubmit( form );
		} );

		confirmDestructive();
		attachShortcut();
		responsiveMenu();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );

	/*
	 * WordPress's link dialog puts "http://" in front of a bare domain. Every
	 * other address on the site gets "https://", so this one does too: the
	 * scheme goes on before WordPress looks, and WordPress then leaves it.
	 * Typed "http://" is kept, as everywhere else.
	 */
	function preferHttps() {
		if ( ! window.wpLink || 'function' !== typeof window.wpLink.correctURL ) {
			return;
		}

		var original = window.wpLink.correctURL;

		window.wpLink.correctURL = function () {
			var field = document.getElementById( 'wp-link-url' );

			if ( field ) {
				var url = field.value.trim();

				if ( url && ! /^(?:[a-z][a-z0-9+.\-]*:|#|\?|\.|\/)/i.test( url ) ) {
					field.value = 'https://' + url;
				} else if ( /^http:\/\//i.test( url ) ) {
					field.value = 'https://' + url.slice( 7 );
				}
			}

			return original.apply( this, arguments );
		};
	}

	/*
	 * The editor's inline link box (the small one under the toolbar) is a
	 * TinyMCE plugin with the same "http://" habit and no setting for it.
	 * Just before it applies the link, the address in its box gets the
	 * scheme, so the plugin finds one and leaves it alone.
	 */
	function preferHttpsInline( editor ) {
		editor.on( 'BeforeExecCommand', function ( e ) {
			if ( 'wp_link_apply' !== e.command ) {
				return;
			}

			var field = document.querySelector( '.wp-link-input input' );

			if ( ! field ) {
				return;
			}

			var url = field.value.trim();

			if ( ! url || /^(?:[a-z][a-z0-9+.\-]*:|#|\?|\.|\/)/i.test( url ) ) {
				return;
			}

			// An email address becomes a mail link; anything else a web one.
			field.value = ( /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( url ) ? 'mailto:' : 'https://' ) + url;
		} );

		// The site lists nothing over plain http, so a typed http:// is
		// upgraded here rather than refused at save, where the member would
		// have to find the link again.
		editor.on( 'BeforeExecCommand', function ( e ) {
			if ( 'wp_link_apply' !== e.command ) {
				return;
			}

			var field = document.querySelector( '.wp-link-input input' );

			if ( field && /^http:\/\//i.test( field.value.trim() ) ) {
				field.value = 'https://' + field.value.trim().slice( 7 );
			}
		} );
	}

	function watchEditors() {
		if ( ! window.tinymce ) {
			return;
		}

		window.tinymce.on( 'AddEditor', function ( e ) {
			preferHttpsInline( e.editor );
		} );

		( window.tinymce.editors || [] ).forEach( preferHttpsInline );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () { preferHttps(); watchEditors(); } );
	} else {
		preferHttps();
		watchEditors();
	}
	} else {
		init();
	}
}() );

/**
 * Load the next page of a list as the reader reaches the end of it.
 *
 * No endpoint of its own: it fetches the next page's ordinary URL, lifts
 * the list items out of the returned document and appends them, so the
 * server renders every page the same way, the URLs stay shareable and
 * nothing changes for a visitor without JavaScript. The pager stays in the
 * markup for them; for everybody else it is swapped for one "Load more"
 * button that an IntersectionObserver presses on their behalf.
 *
 * A list opts in with `data-dgl-autoload` (its value, if any, is the
 * selector for one item, used for the count read out to screen readers).
 * Its pager carries `data-dgl-pager="hide"` (replaced by the button) or
 * `data-dgl-pager="keep"` (left as it is, only read for the next link),
 * and a link with `rel="next"` or the class `next`.
 */
( function () {
	'use strict';

	var root = document.documentElement;
	root.classList.add( 'dgl-js' );

	if ( ! window.fetch || ! window.DOMParser ) {
		return;
	}

	var words   = window.dglAutoload || {};
	var reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function text( key, fallback ) {
		return typeof words[ key ] === 'string' ? words[ key ] : fallback;
	}

	function fill( template, a, b ) {
		return template.replace( '%1$d', String( a ) ).replace( '%2$d', String( b ) );
	}

	function pagerFor( list ) {
		var scope = list.parentElement;

		while ( scope && ! scope.querySelector( '[data-dgl-pager]' ) ) {
			scope = scope.parentElement;
		}

		return scope ? scope.querySelector( '[data-dgl-pager]' ) : null;
	}

	function nextLink( pager ) {
		return pager ? pager.querySelector( 'a[rel="next"], a.next' ) : null;
	}

	function countItems( list ) {
		var selector = list.getAttribute( 'data-dgl-autoload' );

		return selector ? list.querySelectorAll( selector ).length : list.children.length;
	}

	function enhance( list ) {
		var pager = pagerFor( list );
		var next  = nextLink( pager );

		if ( ! next ) {
			return;
		}

		var nextUrl = next.href;
		var keep    = pager.getAttribute( 'data-dgl-pager' ) === 'keep';
		var busy    = false;
		var watcher = null;

		if ( ! keep ) {
			pager.hidden = true;
		}

		var wrap     = document.createElement( 'div' );
		var sentinel = document.createElement( 'div' );
		var button   = document.createElement( 'button' );
		var status   = document.createElement( 'p' );

		wrap.className     = 'dgl-more';
		sentinel.className = 'dgl-more__sentinel';
		button.className   = 'dgl-more__button';
		status.className   = 'dgl-more__status';
		button.type        = 'button';
		button.textContent = text( 'more', 'Load more' );
		status.setAttribute( 'aria-live', 'polite' );

		wrap.appendChild( sentinel );
		wrap.appendChild( button );
		wrap.appendChild( status );
		list.parentNode.insertBefore( wrap, list.nextSibling );

		function finish() {
			busy = false;
			button.disabled = false;
			button.textContent = text( 'more', 'Load more' );
		}

		function load( byHand ) {
			if ( busy || ! nextUrl ) {
				return;
			}

			busy = true;
			button.disabled = true;
			button.textContent = text( 'loading', 'Loading…' );

			var url = nextUrl;

			fetch( url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'dgl-autoload' } } )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( String( response.status ) );
					}

					return response.text();
				} )
				.then( function ( html ) {
					var doc      = new DOMParser().parseFromString( html, 'text/html' );
					var fetched  = doc.querySelector( '[data-dgl-autoload]' );
					var newPager = doc.querySelector( '[data-dgl-pager]' );
					var added    = 0;
					var first    = null;

					if ( fetched ) {
						while ( fetched.firstChild ) {
							var node = fetched.firstChild;

							if ( node.nodeType === 1 ) {
								added += 1;
								first = first || node;
							}

							list.appendChild( node );
						}
					}

					var newNext = nextLink( newPager );

					if ( ! keep && newPager ) {
						pager.parentNode.replaceChild( newPager, pager );
						pager = newPager;
						pager.hidden = true;
					}

					try {
						window.history.replaceState( null, '', url );
					} catch ( e ) {
						// A browser that refuses is a browser that keeps the old URL. Fine.
					}

					nextUrl = newNext ? newNext.href : '';

					status.textContent = fill( text( 'loaded', '%1$d more loaded, showing %2$d.' ), added, countItems( list ) );

					if ( ! nextUrl ) {
						button.hidden = true;
						status.textContent += ' ' + text( 'all', 'That is everything.' );

						if ( watcher ) {
							watcher.disconnect();
						}
					}

					finish();

					if ( byHand && first ) {
						var target = first.querySelector( 'a, button' ) || first;

						if ( ! target.hasAttribute( 'tabindex' ) && target.tabIndex < 0 ) {
							target.setAttribute( 'tabindex', '-1' );
						}

						target.focus();
					}
				} )
				.catch( function () {
					finish();
					status.textContent = text( 'failed', 'Could not load more. The page links are below.' );
					pager.hidden = false;
				} );
		}

		button.addEventListener( 'click', function () {
			load( true );
		} );

		if ( 'IntersectionObserver' in window && ! reduced ) {
			watcher = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						load( false );
					}
				} );
			}, { rootMargin: '240px 0px' } );

			watcher.observe( sentinel );
		}
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-dgl-autoload]' ), enhance );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

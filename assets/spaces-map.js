/**
 * The map on Find a space and on a venue page.
 *
 * Find a space: a List / Map toggle shows a map of every venue in the
 * results that has a pin, from the JSON the page carries. A venue page:
 * one marker for the venue. Leaflet and its tiles are only asked for
 * when a map is first shown, so the list view costs nothing extra.
 */
( function () {
	'use strict';

	if ( ! window.L || ! window.dglMap ) {
		return;
	}

	var settings = window.dglMap;

	L.Icon.Default.imagePath = settings.images;

	function tiles( map ) {
		L.tileLayer( settings.tiles, { maxZoom: 19, attribution: settings.attribution } ).addTo( map );
	}

	/* ---- Find a space: List / Map ------------------------------------- */

	var box = document.getElementById( 'dgl-spaces-map' );
	var data = document.getElementById( 'dgl-map-data' );
	var toggle = document.querySelector( '[data-dgl-mapview]' );
	var list = document.querySelector( '.dgl-venue-list' );
	var built = false;

	function buildResults() {
		var pins = [];

		try {
			pins = JSON.parse( data.textContent || '[]' );
		} catch ( e ) {
			pins = [];
		}

		var map = L.map( box, { scrollWheelZoom: false } );
		tiles( map );

		if ( ! pins.length ) {
			map.setView( [ 53.8008, -1.5491 ], 11 );
			return;
		}

		var group = L.featureGroup();

		pins.forEach( function ( pin ) {
			var link = document.createElement( 'a' );
			link.href = pin.url;
			link.textContent = pin.title;

			L.marker( [ pin.lat, pin.lng ], { title: pin.title } )
				.bindPopup( link.outerHTML )
				.addTo( group );
		} );

		group.addTo( map );
		map.fitBounds( group.getBounds().pad( 0.2 ), { maxZoom: 15 } );
	}

	if ( box && data && toggle ) {
		Array.prototype.forEach.call( toggle.querySelectorAll( '[data-dgl-view]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var view = button.getAttribute( 'data-dgl-view' );

				Array.prototype.forEach.call( toggle.querySelectorAll( '[data-dgl-view]' ), function ( b ) {
					b.setAttribute( 'aria-pressed', b === button ? 'true' : 'false' );
				} );

				box.hidden = 'map' !== view;

				if ( list ) {
					list.hidden = 'map' === view;
				}

				if ( 'map' === view && ! built ) {
					built = true;
					buildResults();
				}
			} );
		} );
	}

	/* ---- A venue page: one pin ---------------------------------------- */

	var single = document.getElementById( 'dgl-venue-map' );

	if ( single ) {
		var pin = null;

		try {
			pin = JSON.parse( single.getAttribute( 'data-dgl-map' ) || 'null' );
		} catch ( e ) {
			pin = null;
		}

		if ( pin && pin.lat && pin.lng ) {
			var venueMap = L.map( single, { scrollWheelZoom: false, dragging: false, zoomControl: false, attributionControl: true } );
			tiles( venueMap );
			venueMap.setView( [ pin.lat, pin.lng ], 15 );
			L.marker( [ pin.lat, pin.lng ], { title: pin.title } ).addTo( venueMap );
		}
	}
} )();

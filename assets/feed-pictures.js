/**
 * Media Library picker for the Topic feed pictures screen: one button per
 * content type, a preview, and a way to remove the choice.
 */
( function ( $ ) {
	'use strict';

	$( '[data-dgl-picker]' ).each( function () {
		var cell = $( this );
		var field = cell.find( '[data-dgl-picker-id]' );
		var preview = cell.find( '[data-dgl-picker-preview]' );
		var clear = cell.find( '[data-dgl-picker-clear]' );
		var frame;

		cell.find( '[data-dgl-picker-choose]' ).on( 'click', function () {
			if ( ! frame ) {
				frame = wp.media( { library: { type: 'image' }, multiple: false } );
				frame.on( 'select', function () {
					var file = frame.state().get( 'selection' ).first().toJSON();
					var url = file.sizes && file.sizes.medium ? file.sizes.medium.url : file.url;

					field.val( file.id );
					preview.attr( 'src', url ).show();
					clear.prop( 'hidden', false );
				} );
			}

			frame.open();
		} );

		clear.on( 'click', function () {
			field.val( 0 );
			preview.hide().attr( 'src', '' );
			clear.prop( 'hidden', true );
		} );
	} );
}( window.jQuery ) );

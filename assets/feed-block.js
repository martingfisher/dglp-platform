/**
 * The Topic feed block's editor side: two dropdowns, a count and a heading.
 * The block itself is drawn on the server (ServerSideRender), so the preview
 * is the markup the visitor gets. Nothing here runs on the public site.
 */
( function ( wp, config ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var Inspector = wp.blockEditor.InspectorControls;
	var Panel = wp.components.PanelBody;
	var Select = wp.components.SelectControl;
	var Text = wp.components.TextControl;
	var Range = wp.components.RangeControl;
	var Toggle = wp.components.ToggleControl;
	var Render = wp.serverSideRender;
	var props = wp.blockEditor.useBlockProps;

	wp.blocks.registerBlockType( 'dgl/feed', {
		edit: function ( p ) {
			var a = p.attributes;
			var set = p.setAttributes;

			return el(
				'div',
				props(),
				el(
					Inspector,
					null,
					el(
						Panel,
						{ title: __( 'What to show', 'dgl-platform' ), initialOpen: true },
						el( Select, { label: __( 'Content type', 'dgl-platform' ), value: a.type, options: config.types, onChange: function ( v ) { set( { type: v } ); } } ),
						el( Select, { label: __( 'Topic', 'dgl-platform' ), value: a.topic, options: config.topics, onChange: function ( v ) { set( { topic: v } ); }, help: __( 'If the topic has nothing yet, the most recent of this type is shown instead.', 'dgl-platform' ) } ),
						el( Range, { label: __( 'How many', 'dgl-platform' ), value: a.count, min: 1, max: config.max, onChange: function ( v ) { set( { count: v } ); } } ),
						el( Text, { label: __( 'Heading', 'dgl-platform' ), value: a.heading, onChange: function ( v ) { set( { heading: v } ); }, help: __( 'Leave empty for the standard heading.', 'dgl-platform' ) } ),
						el( Toggle, { label: __( 'Show the "All" link', 'dgl-platform' ), checked: a.link, onChange: function ( v ) { set( { link: v } ); } } )
					)
				),
				el( Render, { block: 'dgl/feed', attributes: a } )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp, window.dglFeed ) );

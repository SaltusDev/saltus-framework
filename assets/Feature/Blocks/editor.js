( function ( wp, definitions ) {
	'use strict';

	if ( ! wp || ! Array.isArray( definitions ) ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var CheckboxControl = wp.components.CheckboxControl;
	var ServerSideRender = wp.serverSideRender;

	function metaControls( definition, attributes, setAttributes ) {
		return ( definition.metaFields || [] ).map( function ( field ) {
			var path = field.path || '';
			var selected = attributes.metaFields || [];
			return el( CheckboxControl, {
				key: path,
				label: field.label || path,
				checked: selected.indexOf( path ) !== -1,
				onChange: function ( checked ) {
					var next = selected.filter( function ( item ) { return item !== path; } );
					if ( checked ) {
						next.push( path );
					}
					setAttributes( { metaFields: next } );
				}
			} );
		} );
	}

	function editComponent( definition ) {
		return function ( props ) {
			var attributes = props.attributes;
			var controls = [];

			if ( definition.view === 'list' ) {
				controls.push(
					el( RangeControl, {
						key: 'postsToShow', label: wp.i18n.__( 'Posts to show', 'saltus-framework' ),
						min: 1, max: 100, value: attributes.postsToShow,
						onChange: function ( value ) { props.setAttributes( { postsToShow: value } ); }
					} ),
					el( SelectControl, {
						key: 'orderBy', label: wp.i18n.__( 'Order by', 'saltus-framework' ), value: attributes.orderBy,
						options: [ 'date', 'title', 'modified', 'menu_order', 'ID' ].map( function ( value ) { return { label: value, value: value }; } ),
						onChange: function ( value ) { props.setAttributes( { orderBy: value } ); }
					} ),
					el( SelectControl, {
						key: 'order', label: wp.i18n.__( 'Order', 'saltus-framework' ), value: attributes.order,
						options: [ { label: 'Descending', value: 'DESC' }, { label: 'Ascending', value: 'ASC' } ],
						onChange: function ( value ) { props.setAttributes( { order: value } ); }
					} ),
					el( TextControl, {
						key: 'taxonomy', label: wp.i18n.__( 'Taxonomy slug', 'saltus-framework' ), value: attributes.taxonomy,
						onChange: function ( value ) { props.setAttributes( { taxonomy: value } ); }
					} ),
					el( TextControl, {
						key: 'terms', label: wp.i18n.__( 'Term slugs', 'saltus-framework' ),
						value: ( attributes.terms || [] ).join( ', ' ),
						onChange: function ( value ) { props.setAttributes( { terms: value.split( ',' ).map( function ( term ) { return term.trim(); } ).filter( Boolean ) } ); }
					} ),
					el( ToggleControl, {
						key: 'showExcerpt', label: wp.i18n.__( 'Show excerpt', 'saltus-framework' ), checked: attributes.showExcerpt,
						onChange: function ( value ) { props.setAttributes( { showExcerpt: value } ); }
					} ),
					el( ToggleControl, {
						key: 'showDate', label: wp.i18n.__( 'Show date', 'saltus-framework' ), checked: attributes.showDate,
						onChange: function ( value ) { props.setAttributes( { showDate: value } ); }
					} )
				);
			} else {
				controls.push(
					el( TextControl, {
						key: 'postId', label: wp.i18n.__( 'Post ID', 'saltus-framework' ), type: 'number', value: attributes.postId || '',
						onChange: function ( value ) { props.setAttributes( { postId: parseInt( value, 10 ) || 0 } ); }
					} ),
					el( ToggleControl, {
						key: 'showTitle', label: wp.i18n.__( 'Show title', 'saltus-framework' ), checked: attributes.showTitle,
						onChange: function ( value ) { props.setAttributes( { showTitle: value } ); }
					} ),
					el( ToggleControl, {
						key: 'showContent', label: wp.i18n.__( 'Show content', 'saltus-framework' ), checked: attributes.showContent,
						onChange: function ( value ) { props.setAttributes( { showContent: value } ); }
					} )
				);
			}

			return el( Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: wp.i18n.__( 'Display', 'saltus-framework' ), initialOpen: true }, controls ),
					el( PanelBody, { title: wp.i18n.__( 'Meta fields', 'saltus-framework' ), initialOpen: false }, metaControls( definition, attributes, props.setAttributes ) )
				),
				el( ServerSideRender, { block: definition.name, attributes: attributes } )
			);
		};
	}

	definitions.forEach( function ( definition ) {
		wp.blocks.registerBlockType( definition.name, {
			apiVersion: 3,
			title: definition.title,
			description: definition.description,
			category: 'widgets',
			icon: definition.view === 'list' ? 'list-view' : 'media-document',
			attributes: definition.attributes,
			edit: editComponent( definition ),
			save: function () { return null; }
		} );
	} );
}( window.wp, ( window.saltusBlockDefinitions || {} ).items || [] ) );

( function ( wp ) {
	var el = wp.element.createElement;
	var ServerSideRender = wp.serverSideRender;
	var useBlockProps = wp.blockEditor.useBlockProps;

	( window.lwptsaBlocks || [] ).forEach( function ( name ) {
		wp.blocks.registerBlockType( name, {
			edit: function ( props ) {
				return el(
					'div',
					useBlockProps(),
					el( ServerSideRender, { block: name, attributes: props.attributes, skipBlockSupportAttributes: true } )
				);
			},
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp );

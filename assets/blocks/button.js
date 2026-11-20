/**
 * Editor side of the "Withdrawal button" block. The block is rendered on the
 * server (Blocks/Shortcodes.php); the editor shows the same markup.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el } = wp.element;
	const ServerSideRender = wp.serverSideRender;
	const { useBlockProps } = wp.blockEditor;

	registerBlockType( 'primeplaced-withdrawal-button/button', {
		edit() {
			return el(
				'div',
				useBlockProps(),
				el( ServerSideRender, { block: 'primeplaced-withdrawal-button/button' } )
			);
		},
		save() {
			return null;
		},
	} );
} )( window.wp );

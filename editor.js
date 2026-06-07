// ============================================
// DATEI: js/editor.js
// Minimale React-Komponente für den Gutenberg-Editor
// ============================================

( function( wp ) {
    const { registerBlockType } = wp.blocks;
    const { createElement } = wp.element;

    registerBlockType( 'wp-llm-search-bar/anchor-search', {
        title: 'WP LLM Search Bar',
        icon: 'search',
        category: 'widgets',
        attributes: {},
        edit: function() {
            return createElement(
                'div',
                {
                    className: 'wp-llm-search-bar-editor',
                    style: {
                        padding: '20px',
                        border: '1px dashed #a0a0a0',
                        background: '#f5f5f5',
                        textAlign: 'center'
                    }
                },
                createElement( 'p', null, 'WP LLM Search Bar Block' ),
                createElement( 'p', { style: { fontSize: '0.8em', color: '#666' } }, 'Wird im Frontend als Suchfeld angezeigt' )
            );
        },
        save: function() {
            return null; // Wird über PHP render_callback ausgegeben
        },
    } );
} )( window.wp );
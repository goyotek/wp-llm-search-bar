// ============================================
// DATEI: js/editor.js
// Gutenberg Editor Component für WP LLM Search Bar
// ============================================

( function( wp ) {
    const { registerBlockType } = wp.blocks;
    const { createElement } = wp.element;

    // Search mode configurations (matching frontend.js)
    const searchModes = {
        instant: {
            name: 'Schnell',
            description: 'Sofortige Suche (nur Schlüsselwörter)',
            mode: 'keyword'
        },
        smart: {
            name: 'Intelligent',
            description: 'Kombinierte Suche (Schlüsselwörter + KI)',
            mode: 'hybrid'
        },
        deep: {
            name: 'Tiefgehend',
            description: 'Vollständige KI-Suche',
            mode: 'semantic'
        }
    };

    registerBlockType( 'wp-llm-search-bar/anchor-search', {
        title: 'WP LLM Search Bar',
        icon: 'search',
        category: 'widgets',
        attributes: {
            searchMode: {
                type: 'string',
                default: 'smart'
            }
        },
        edit: function( props ) {
            const { attributes, setAttributes } = props;
            const { searchMode } = attributes;

            // Create mode selector buttons
            const modeButtons = Object.keys(searchModes).map(function(modeKey) {
                const mode = searchModes[modeKey];
                const isActive = searchMode === modeKey;
                
                return createElement(
                    'button',
                    {
                        key: modeKey,
                        type: 'button',
                        className: 'llm-search-mode-btn',
                        onClick: function() {
                            setAttributes({ searchMode: modeKey });
                        },
                        style: {
                            background: isActive ? '#0073aa' : 'none',
                            color: isActive ? 'white' : '',
                            border: '1px solid ' + (isActive ? '#0073aa' : '#ddd'),
                            padding: '4px 8px',
                            marginRight: '5px',
                            cursor: 'pointer',
                            borderRadius: '3px',
                            marginBottom: '5px'
                        }
                    },
                    mode.name
                );
            });

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
                createElement( 'p', { style: { fontWeight: 'bold', marginBottom: '10px' } }, 'WP LLM Search Bar Block' ),
                createElement( 'p', { style: { fontSize: '0.8em', color: '#666', marginBottom: '15px' } }, 'KI-basierte semantische Suche für WordPress' ),
                createElement(
                    'div',
                    { style: { marginBottom: '15px' } },
                    createElement( 'p', { style: { fontSize: '0.85em', color: '#666', marginBottom: '5px' } }, 'Suchmodus:' ),
                    createElement( 'div', null, modeButtons )
                ),
                createElement(
                    'div',
                    {
                        style: {
                            padding: '15px',
                            border: '1px solid #ddd',
                            borderRadius: '4px',
                            background: 'white',
                            marginTop: '15px'
                        }
                    },
                    createElement( 'input', {
                        type: 'text',
                        placeholder: 'Wonach interessierst du dich?',
                        style: {
                            width: '100%',
                            padding: '12px',
                            border: '1px solid #ddd',
                            borderRadius: '4px',
                            fontSize: '16px'
                        },
                        disabled: true
                    }),
                    createElement( 'p', { style: { fontSize: '0.75em', color: '#999', marginTop: '8px' } }, 'Vorschau - Im Frontend funktioniert die Suche' )
                )
            );
        },
        save: function() {
            return null; // Wird über PHP render_callback ausgegeben
        },
    } );
} )( window.wp );

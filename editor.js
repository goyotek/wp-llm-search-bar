// ============================================
// DATEI: editor.js
// Gutenberg-Editor: Block-Registrierung mit Einstellungen
// (Antwort-Modus, Platzhalter, Quellenanzahl, CTA mit mailto).
// ============================================

( function ( wp ) {
    var registerBlockType = wp.blocks.registerBlockType;
    var createElement = wp.element.createElement;
    var __ = wp.i18n.__;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;
    var SelectControl = wp.components.SelectControl;
    var RangeControl = wp.components.RangeControl;

    registerBlockType( 'wp-llm-search-bar/anchor-search', {
        title: 'WP LLM Search Bar',
        icon: 'search',
        category: 'widgets',
        attributes: {
            mode: { type: 'string', default: 'answer' },
            placeholder: { type: 'string', default: '' },
            maxSources: { type: 'number', default: 3 },
            ctaText: { type: 'string', default: '' },
            ctaEmail: { type: 'string', default: '' },
            ctaSubject: { type: 'string', default: '' }
        },
        edit: function ( props ) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;

            var inspector = createElement( InspectorControls, {},
                createElement( PanelBody, { title: 'Einstellungen', initialOpen: true },
                    createElement( SelectControl, {
                        label: 'Modus',
                        value: attributes.mode,
                        options: [
                            { value: 'answer', label: 'KI-Antwort mit Quellen' },
                            { value: 'links', label: 'Nur Quellenliste' }
                        ],
                        onChange: function ( value ) {
                            setAttributes( { mode: value } );
                        }
                    } ),
                    createElement( TextControl, {
                        label: 'Platzhalter',
                        value: attributes.placeholder,
                        placeholder: 'Wonach möchtest du wissen?',
                        onChange: function ( value ) {
                            setAttributes( { placeholder: value } );
                        }
                    } ),
                    createElement( RangeControl, {
                        label: 'Max. Quellen',
                        value: attributes.maxSources,
                        min: 1,
                        max: 5,
                        onChange: function ( value ) {
                            setAttributes( { maxSources: value } );
                        }
                    } ),
                    createElement( PanelBody, { title: 'Kontakt (CTA)', initialOpen: false },
                        createElement( TextControl, {
                            label: 'Pitch-Text',
                            value: attributes.ctaText,
                            placeholder: 'Erzähl uns von deinem Vorhaben – wir beraten dich kostenlos.',
                            onChange: function ( value ) {
                                setAttributes( { ctaText: value } );
                            }
                        } ),
                        createElement( TextControl, {
                            label: 'E-Mail',
                            type: 'email',
                            value: attributes.ctaEmail,
                            onChange: function ( value ) {
                                setAttributes( { ctaEmail: value } );
                            }
                        } ),
                        createElement( TextControl, {
                            label: 'Betreff',
                            value: attributes.ctaSubject,
                            help: 'Betreff für den mailto-Link, z. B. die Suchanfrage.',
                            onChange: function ( value ) {
                                setAttributes( { ctaSubject: value } );
                            }
                        } )
                    )
                )
            );

            var preview = createElement(
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
                createElement( 'p', { style: { fontWeight: '600' } }, 'WP LLM Search Bar' ),
                createElement( 'p', { style: { fontSize: '0.9em', margin: '4px 0' } },
                    attributes.mode === 'links' ? 'Zeigt nur eine Quellenliste an.' : 'Zeigt eine kurze KI-Antwort mit Quellen an.'
                ),
                createElement( 'p', { style: { fontSize: '0.8em', color: '#666', margin: 0 } },
                    attributes.ctaEmail
                        ? 'CTA: ' + attributes.ctaEmail
                        : 'Kein CTA konfiguriert – Suchbereich unter Einstellungen → WP LLM Search Bar verwalten.'
                )
            );

            return [
                inspector,
                preview
            ];
        },
        save: function () {
            return null; // Server-Side-Rendering über PHP.
        }
    } );
} )( window.wp );

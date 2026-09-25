<?php
/**
 * Extrahiert durchsuchbare Abschnitte (Chunks) aus WordPress-Inhalten,
 * inklusive Anchor-IDs für interne Tiefenlinks (z. B. /angebot/#Digitalisierung).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_LLM_Search_Bar_Extractor {

    /**
     * Extrahiert Abschnitte aus einem Post-Inhalt.
     *
     * @return array[] Liste von Chunks: title, text, anchor (optional), tag.
     */
    public static function extract_from_content( $html ) {
        if ( empty( trim( (string) $html ) ) ) {
            return [];
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
        if ( function_exists( 'mb_encode_numericentity' ) ) {
            $wrapped = mb_encode_numericentity( $wrapped, [ 0x80, 0x10FFFF, 0, 0x1FFFFF ], 'UTF-8' );
        }
        $dom->loadHTML( $wrapped, LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();

        $xpath = new DOMXPath( $dom );
        $chunks = [];
        $seen   = [];

        // 1. Elemente mit ID (übliche Sprungziele), begrenzt auf inhaltstragende Tags.
        $content_tags = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'p', 'div' ];
        foreach ( $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6|//section|//div|//p' ) as $node ) {
            $id = $node->getAttribute( 'id' );
            if ( '' === $id ) {
                continue;
            }
            if ( 'div' === $node->nodeName && $node->getElementsByTagName( '*' )->length > 12 ) {
                continue; // Layout-Container überspringen.
            }
            $chunk = self::build_chunk( $node, $seen );
            if ( $chunk ) {
                $chunks[]  = $chunk;
                $seen[ $chunk['anchor'] ] = true;
            }
        }
        unset( $content_tags );

        // 2. Überschriften ohne ID: Anchor über sanitize_title ableiten (Theme-Anchor-Konvention).
        foreach ( $xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6' ) as $node ) {
            if ( '' !== $node->getAttribute( 'id' ) ) {
                continue;
            }
            $chunk = self::build_chunk( $node, $seen );
            if ( $chunk ) {
                $chunks[] = $chunk;
                $seen[ $chunk['anchor'] ] = true;
            }
        }

        return $chunks;
    }

    /**
     * Baut aus einem DOM-Element einen Chunk mit Kontext (nachfolgende Geschwister bis zur nächsten Überschrift).
     */
    private static function build_chunk( DOMElement $node, array $seen ) {
        $title = trim( $node->textContent );
        $tag   = $node->nodeName;
        $is_heading = ( 'h1' === $tag || 'h2' === $tag || 'h3' === $tag || 'h4' === $tag || 'h5' === $tag || 'h6' === $tag );

        if ( ! $is_heading ) {
            // Container/Abstracts: Text kürzen, Titel über erste Überschrift im Element suchen.
            $heading = $node->getElementsByTagName( 'h2' )->item( 0 );
            if ( ! $heading ) {
                $heading = $node->getElementsByTagName( 'h3' )->item( 0 );
            }
            $title = $heading ? trim( $heading->textContent ) : self::first_words( $title, 12 );
        }

        $anchor = $node->getAttribute( 'id' );
        if ( '' === $anchor && $is_heading ) {
            $anchor = sanitize_title( $title );
        }
        if ( '' === $anchor || isset( $seen[ $anchor ] ) ) {
            return null;
        }

        $text = $title;
        if ( ! $is_heading ) {
            $body = self::strip_node_text( $node, $title );
            if ( '' !== $body ) {
                $text = $title . ' — ' . $body;
            }
        } else {
            $context = self::sibling_context( $node );
            if ( '' !== $context ) {
                $text = $title . ' — ' . $context;
            }
        }

        $text = self::first_words( preg_replace( '/\s+/u', ' ', $text ), 90 );

        return [
            'title'  => $title,
            'text'   => $text,
            'anchor' => $anchor,
            'tag'    => $tag,
        ];
    }

    /**
     * Kontext: nachfolgende Geschwister bis zur nächsten Überschrift gleicher oder höherer Ebene.
     */
    private static function sibling_context( DOMElement $node ) {
        $parts     = [];
        $my_level  = (int) substr( $node->nodeName, 1, 1 );
        $next      = $node->nextSibling;
        $char_budget = 700;

        while ( $next && $char_budget > 0 ) {
            if ( $next instanceof DOMElement ) {
                if ( preg_match( '/^h[1-' . max( 1, $my_level ) . ']$/', $next->nodeName ) ) {
                    break;
                }
                $text = trim( $next->textContent );
                if ( '' !== $text ) {
                    $parts[]     = $text;
                    $char_budget -= mb_strlen( $text );
                }
            }
            $next = $next->nextSibling;
        }

        $context = implode( ' ', $parts );
        return mb_substr( $context, 0, 700 );
    }

    private static function strip_node_text( DOMElement $node, $title ) {
        $text = trim( preg_replace( '/\s+/u', ' ', $node->textContent ) );
        if ( '' !== $title && 0 === mb_stripos( $text, $title ) ) {
            $text = trim( mb_substr( $text, mb_strlen( $title ) ) );
        }
        return $text;
    }

    private static function first_words( $text, $words ) {
        $parts = preg_split( '/\s+/u', trim( (string) $text ) );
        if ( count( $parts ) <= $words ) {
            return implode( ' ', $parts );
        }
        return implode( ' ', array_slice( $parts, 0, $words ) ) . ' …';
    }
}

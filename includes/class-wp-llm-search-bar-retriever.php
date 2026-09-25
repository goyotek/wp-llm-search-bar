<?php
/**
 * Ruft Kandidaten-Abschnitte für eine Suchanfrage ab – ausschließlich über
 * Standard-WordPress-Strukturen (WP_Query, wp_options, Transients), ohne eigene DB-Tabellen.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_LLM_Search_Bar_Retriever {

    const CACHE_GROUP  = 'wp_llm_search_bar';
    const CACHE_TTL    = HOUR_IN_SECONDS * 12;

    /**
     * Sucht Kandidaten-Abschnitte zur Anfrage.
     *
     * @param string $query   Suchanfrage.
     * @param int    $page_id ID der Seite, auf der der Block steht (für Modus "nur diese Seite").
     * @param array  $settings Plugin-Einstellungen.
     * @return array[] Chunks mit post_id, url, title, text, anchor, tag, score.
     */
    public static function search( $query, $page_id, $settings ) {
        $query = trim( (string) $query );
        if ( '' === $query ) {
            return [];
        }

        $scope = isset( $settings['scope'] ) ? $settings['scope'] : 'site';
        if ( 'current_page' === $scope && $page_id ) {
            return self::search_current_page( $query, $page_id );
        }

        return self::search_site( $query, $settings );
    }

    /**
     * Modus "nur diese Seite": Abschnitte der aktuellen Seite nach Relevanz vorsortieren.
     */
    private static function search_current_page( $query, $page_id ) {
        $cache_key = 'chunks_' . (int) $page_id;
        $chunks    = wp_cache_get( $cache_key, self::CACHE_GROUP );

        if ( ! is_array( $chunks ) ) {
            $content = get_post_field( 'post_content', $page_id );
            $chunks  = WP_LLM_Search_Bar_Extractor::extract_from_content( $content );
            foreach ( $chunks as &$chunk ) {
                $chunk['post_id'] = (int) $page_id;
                $chunk['url']     = get_permalink( $page_id );
            }
            unset( $chunk );
            wp_cache_set( $cache_key, $chunks, self::CACHE_GROUP, self::CACHE_TTL );
        }

        return self::rank( $chunks, $query );
    }

    /**
     * Site-weite Suche über WP_Query: Seiten, Beiträge, CPTs und (optional) Taxonomie-Archive.
     */
    private static function search_site( $query, $settings ) {
        $post_types = WP_LLM_Search_Bar_Settings::get_selected_post_types( $settings );
        if ( empty( $post_types ) ) {
            return [];
        }

        $args = [
            'post_type'              => array_values( $post_types ),
            'post_status'            => 'publish',
            'posts_per_page'         => 20,
            's'                      => $query,
            'orderby'                => 'relevance',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
        ];

        $taxonomy = WP_LLM_Search_Bar_Settings::get_selected_taxonomy( $settings );
        if ( $taxonomy ) {
            $terms = self::matching_terms( $query, $taxonomy );
            if ( $terms ) {
                unset( $args['s'] );
                $args['tax_query'] = [
                    [
                        'taxonomy' => $taxonomy,
                        'field'    => 'slug',
                        'terms'    => $terms,
                    ],
                ];
                $args['posts_per_page'] = 10;
            }
        }

        $posts = get_posts( $args );
        $chunks = [];

        foreach ( $posts as $post ) {
            $post_chunks = wp_cache_get( 'chunks_' . $post->ID, self::CACHE_GROUP );
            if ( ! is_array( $post_chunks ) ) {
                $post_chunks = WP_LLM_Search_Bar_Extractor::extract_from_content( $post->post_content );
                foreach ( $post_chunks as &$chunk ) {
                    $chunk['post_id'] = $post->ID;
                    $chunk['url']     = get_permalink( $post );
                }
                unset( $chunk );
                wp_cache_set( 'chunks_' . $post->ID, $post_chunks, self::CACHE_GROUP, self::CACHE_TTL );
            }
            foreach ( $post_chunks as $chunk ) {
                $chunks[] = $chunk;
            }
        }

        // Taxonomie-Archiv selbst als Quelle (z. B. /angebot/#Digitalisierung über Archive).
        if ( $taxonomy ) {
            foreach ( self::matching_terms( $query, $taxonomy ) as $term_slug ) {
                $term = get_term_by( 'slug', $term_slug, $taxonomy );
                if ( $term && ! is_wp_error( $term ) ) {
                    $chunks[] = [
                        'post_id' => 0,
                        'url'     => get_term_link( $term ),
                        'title'   => $term->name,
                        'text'    => $term->name . ' — ' . $term->description,
                        'anchor'  => sanitize_title( $term->name ),
                        'tag'     => 'archive',
                        'score'   => 1.0,
                    ];
                }
            }
        }

        return self::rank( $chunks, $query );
    }

    /**
     * Passende Terms einer Taxonomie zur Anfrage (Namen/Beschreibung enthalten Suchbegriffe).
     */
    private static function matching_terms( $query, $taxonomy ) {
        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => 50,
        ] );
        if ( is_wp_error( $terms ) ) {
            return [];
        }

        $words   = self::keywords( $query );
        $matches = [];
        foreach ( $terms as $term ) {
            $haystack = mb_strtolower( $term->name . ' ' . $term->description );
            foreach ( $words as $word ) {
                if ( '' !== $word && false !== mb_strpos( $haystack, $word ) ) {
                    $matches[] = $term->slug;
                    break;
                }
            }
        }
        return $matches;
    }

    /**
     * Einfaches Keyword-Ranking der Chunks (Prescore vor dem LLM-Aufruf).
     */
    private static function rank( array $chunks, $query ) {
        if ( empty( $chunks ) ) {
            return [];
        }

        $words = self::keywords( $query );
        foreach ( $chunks as &$chunk ) {
            $score = 0;
            if ( isset( $chunk['score'] ) ) {
                $score = $chunk['score'];
            }
            $title = mb_strtolower( $chunk['title'] );
            $text  = mb_strtolower( $chunk['text'] );
            foreach ( $words as $word ) {
                if ( '' === $word ) {
                    continue;
                }
                $score += substr_count( $title, $word ) * 3;
                $score += substr_count( $text, $word );
            }
            $chunk['score'] = $score;
        }
        unset( $chunk );

        usort( $chunks, function ( $a, $b ) {
            return $b['score'] <=> $a['score'];
        } );

        return $chunks;
    }

    private static function keywords( $query ) {
        $stopwords = [ 'der', 'die', 'das', 'den', 'dem', 'ein', 'eine', 'einer', 'eines', 'und', 'oder', 'ich', 'mein', 'meine', 'muss', 'achten', 'worauf', 'wenn', 'wie', 'was', 'ist', 'im', 'in', 'auf', 'für', 'mit', 'man', 'sich', 'soll', 'sollte', 'will', 'wollen', 'beim', 'von', 'vom', 'zum', 'zur', 'the', 'a', 'an', 'of', 'to', 'for' ];
        $words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $query ) );
        $words = array_filter( $words, function ( $word ) use ( $stopwords ) {
            return '' !== $word && mb_strlen( $word ) >= 3 && ! in_array( $word, $stopwords, true );
        } );
        return array_values( array_unique( $words ) );
    }
}

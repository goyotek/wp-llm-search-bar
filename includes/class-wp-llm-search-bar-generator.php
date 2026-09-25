<?php
/**
 * Baut den LLM-Prompt und erzeugt die Antwort im Stil einer KI-Übersicht.
 * Die Antwort verlinkt ausschließlich interne Quellen aus der Kandidatenliste.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_LLM_Search_Bar_Generator {

    /**
     * Erzeugt eine kurze, quellenbasierte Antwort aus den Top-Kandidaten.
     *
     * @param string $query      Suchanfrage.
     * @param array  $chunks     Kandidaten-Chunks (bereits gerankt).
     * @param int    $max_sources Maximale Anzahl angefügter Quellen.
     * @param array  $cta        Optionaler Call-to-Action: ['text' => ..., 'email' => ..., 'subject' => ...].
     * @return array|WP_Error    ['answer' => string, 'sources' => array[]] bei Erfolg.
     */
    public static function generate( $query, array $chunks, $max_sources = 3, $cta = [] ) {
        if ( empty( $chunks ) ) {
            return [
                'answer'  => '',
                'sources' => [],
            ];
        }

        $candidates = array_slice( $chunks, 0, max( 1, min( 10, $max_sources * 3 ) ) );
        $prompt     = self::build_prompt( $query, $candidates, $max_sources, $cta );

        $result = wp_ai_client_prompt( $prompt )
            ->using_temperature( 0.2 )
            ->using_max_tokens( 600 )
            ->as_json_response( self::response_schema() )
            ->generate_text_result();

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $data = self::parse_json( $result );
        if ( null === $data ) {
            return new WP_Error(
                'wp_llm_search_bar_invalid_response',
                __( 'Die KI-Antwort konnte nicht gelesen werden.', 'wp-llm-search-bar' )
            );
        }

        $answer  = isset( $data['answer'] ) && is_string( $data['answer'] ) ? trim( $data['answer'] ) : '';
        $sources = self::filter_sources( isset( $data['sources'] ) && is_array( $data['sources'] ) ? $data['sources'] : [], $candidates, $max_sources );

        return [
            'answer'  => $answer,
            'sources' => $sources,
        ];
    }

    private static function build_prompt( $query, array $candidates, $max_sources, $cta ) {
        $sources_json = [];
        foreach ( $candidates as $index => $chunk ) {
            $sources_json[] = [
                'index' => $index + 1,
                'title' => $chunk['title'],
                'url'   => self::source_url( $chunk ),
                'text'  => $chunk['text'],
            ];
        }

        $cta_instructions = '';
        if ( ! empty( $cta['text'] ) && ! empty( $cta['email'] ) ) {
            $cta_instructions = sprintf(
                "\nAm Ende der Antwort, als eigener Absatz, füge diesen Hinweis hinzu: %s\n",
                $cta['text']
            );
        }

        $prompt = sprintf(
            "Du bist ein hilfreicher Assistent für eine Website. Beantworte die Anfrage eines Besuchers ausschließlich auf Basis der unten angegebenen internen Quellen. Wenn die Quellen die Anfrage nicht beantworten können, sage das kurz und schlage die trotzdem am besten passenden Quellen vor.\n\n" .
            "Anfrage: \"%s\"\n\n" .
            "Quellen (nur diese sind erlaubt):\n%s\n\n" .
            "Aufgaben:\n" .
            "1. Fasse in 1–2 kurzen Absätzen (maximal ca. 80 Wörter) zusammen, was die Quellen zur Anfrage sagen. Schreibe in der Sprache der Anfrage.\n" .
            "2. Nenne in der Antwort keine URLs und keine Quellennummern.\n" .
            "3. Wähle dann die bis zu %d passendsten Quellen aus der Liste aus (nur dort genannte URLs sind erlaubt).\n" .
            "4. Antworte ausschließlich als JSON nach dem Schema.\n%s",
            $query,
            wp_json_encode( $sources_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            (int) $max_sources,
            $cta_instructions
        );

        return $prompt;
    }

    /**
     * Nur Quellen behalten, deren URL aus der Kandidatenliste stammt (keine externen Links, keine Halluzinationen).
     */
    private static function filter_sources( array $sources, array $candidates, $max_sources ) {
        $allowed = [];
        foreach ( $candidates as $chunk ) {
            $allowed[ $chunk['url'] ] = $chunk;
        }

        $filtered = [];
        $seen     = [];
        foreach ( $sources as $source ) {
            if ( ! is_array( $source ) ) {
                continue;
            }
            $url = isset( $source['url'] ) ? trim( (string) $source['url'] ) : '';
            if ( '' === $url ) {
                continue;
            }
            $base_url = strtok( $url, '#' );
            if ( ! isset( $allowed[ $base_url ] ) && ! isset( $allowed[ $url ] ) ) {
                continue;
            }
            $chunk = isset( $allowed[ $url ] ) ? $allowed[ $url ] : $allowed[ $base_url ];

            $resolved = self::source_url( $chunk, isset( $source['anchor'] ) ? $source['anchor'] : '' );
            if ( isset( $seen[ $resolved ] ) ) {
                continue;
            }

            $filtered[] = [
                'url'   => $resolved,
                'title' => isset( $source['title'] ) && '' !== trim( (string) $source['title'] ) ? trim( (string) $source['title'] ) : $chunk['title'],
                'text'  => isset( $chunk['text'] ) ? $chunk['text'] : '',
            ];
            $seen[ $resolved ] = true;

            if ( count( $filtered ) >= $max_sources ) {
                break;
            }
        }

        return $filtered;
    }

    /**
     * Öffentliche URL eines Chunks inklusive Anchor-Fragment (z. B. /angebot/#Digitalisierung).
     */
    public static function source_url( array $chunk, $anchor_override = '' ) {
        $url = $chunk['url'];
        if ( '' === $url ) {
            return '';
        }
        $anchor = '' !== (string) $anchor_override ? (string) $anchor_override : ( isset( $chunk['anchor'] ) ? $chunk['anchor'] : '' );
        if ( 'archive' === $chunk['tag'] ) {
            return $url; // Term-Archive bekommen kein Fragment.
        }
        if ( '' !== $anchor ) {
            return rtrim( $url, '/' ) . '#' . rawurlencode( $anchor );
        }
        return $url;
    }

    private static function response_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'answer'  => [ 'type' => 'string' ],
                'sources' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'title' => [ 'type' => 'string' ],
                            'url'   => [ 'type' => 'string' ],
                            'anchor' => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
            'required' => [ 'answer' ],
        ];
    }

    private static function parse_json( $result ) {
        if ( ! is_string( $result ) ) {
            return is_array( $result ) ? $result : null;
        }
        $data = json_decode( trim( $result ), true );
        return is_array( $data ) ? $data : null;
    }
}

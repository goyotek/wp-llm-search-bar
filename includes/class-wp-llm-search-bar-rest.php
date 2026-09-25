<?php
/**
 * REST-Endpunkt für die Suche. Rate-Limiting und Caching über
 * Standard-WordPress-Mittel (Transients, Object-Cache), keine eigenen DB-Tabellen.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_LLM_Search_Bar_Rest {

    const NAMESPACE_V1 = 'wp-llm-search-bar/v1';

    public static function init() {
        register_rest_route( self::NAMESPACE_V1, '/search', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_search' ],
            'permission_callback' => [ __CLASS__, 'check_permission' ],
            'args'                => [
                'query'      => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ( $value ) {
                        return is_string( $value ) && mb_strlen( trim( $value ) ) >= 3 && mb_strlen( trim( $value ) ) <= 500;
                    },
                ],
                'page_id'    => [
                    'type'              => 'integer',
                    'required'          => false,
                    'sanitize_callback' => 'absint',
                ],
                'maxSources' => [
                    'type'              => 'integer',
                    'required'          => false,
                    'default'           => 3,
                    'sanitize_callback' => 'absint',
                ],
                'mode'       => [
                    'type'              => 'string',
                    'required'          => false,
                    'default'           => 'answer',
                    'enum'              => [ 'answer', 'links' ],
                ],
                'ctaText'    => [
                    'type'              => 'string',
                    'required'          => false,
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'ctaEmail'   => [
                    'type'              => 'string',
                    'required'          => false,
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_email',
                ],
                'ctaSubject' => [
                    'type'              => 'string',
                    'required'          => false,
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    public static function check_permission( WP_REST_Request $request ) {
        if ( ! self::check_rate_limit() ) {
            return new WP_Error(
                'wp_llm_search_bar_rate_limited',
                __( 'Zu viele Anfragen. Bitte kurz warten und erneut versuchen.', 'wp-llm-search-bar' ),
                [ 'status' => 429 ]
            );
        }
        return true;
    }

    public static function handle_search( WP_REST_Request $request ) {
        $query      = trim( $request->get_param( 'query' ) );
        $page_id    = absint( $request->get_param( 'page_id' ) );
        $max_source = min( 5, max( 1, absint( $request->get_param( 'maxSources' ) ) ) );
        $mode       = 'links' === $request->get_param( 'mode' ) ? 'links' : 'answer';
        $settings   = WP_LLM_Search_Bar_Settings::get_settings();

        $cache_key = 'resp_' . md5( wp_json_encode( [ $query, $page_id, $max_source, $settings ] ) );
        $ttl       = (int) $settings['cache_ttl'];
        if ( $ttl > 0 ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached && is_array( $cached ) ) {
                return new WP_REST_Response( $cached, 200 );
            }
        }

        $chunks = WP_LLM_Search_Bar_Retriever::search( $query, $page_id, $settings );
        if ( empty( $chunks ) ) {
            $response = [ 'answer' => '', 'sources' => [], 'message' => __( 'Keine passenden Inhalte gefunden.', 'wp-llm-search-bar' ) ];
            if ( $ttl > 0 ) {
                set_transient( $cache_key, $response, min( $ttl, 300 ) );
            }
            return new WP_REST_Response( $response, 200 );
        }

        $cta = apply_filters( 'wp_llm_search_bar_cta', [], $query, $page_id );
        foreach ( [ 'text', 'email', 'subject' ] as $field ) {
            $request_cta = $request->get_param( 'cta' . ucfirst( $field ) );
            if ( is_string( $request_cta ) && '' !== trim( $request_cta ) ) {
                $cta[ $field ] = trim( $request_cta );
            }
        }

        if ( 'links' === $mode ) {
            $sources = array_slice( array_map( function ( $chunk ) {
                return [
                    'url'   => WP_LLM_Search_Bar_Generator::source_url( $chunk ),
                    'title' => $chunk['title'],
                ];
            }, $chunks ), 0, $max_source );

            $result = [ 'answer' => '', 'sources' => $sources ];
        } else {
            $result = WP_LLM_Search_Bar_Generator::generate( $query, $chunks, $max_source, $cta );
        }

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response(
                [ 'error' => $result->get_error_message() ],
                500
            );
        }

        $result['sources'] = array_map( function ( $source ) {
            return [
                'url'   => esc_url_raw( $source['url'] ),
                'title' => sanitize_text_field( $source['title'] ),
            ];
        }, $result['sources'] );

        if ( ! empty( $cta['email'] ) && is_email( $cta['email'] ) ) {
            $result['cta'] = [
                'email'   => sanitize_email( $cta['email'] ),
                'text'    => isset( $cta['text'] ) ? sanitize_text_field( $cta['text'] ) : '',
                'subject' => isset( $cta['subject'] ) ? sanitize_text_field( $cta['subject'] ) : $query,
            ];
        }

        if ( $ttl > 0 ) {
            set_transient( $cache_key, $result, $ttl );
        }

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * Einfaches Rate-Limit pro IP über Transients.
     */
    private static function check_rate_limit() {
        $settings = WP_LLM_Search_Bar_Settings::get_settings();
        $limit    = (int) $settings['rate_limit'];
        $window   = (int) $settings['rate_window'];
        if ( $limit < 1 ) {
            return true;
        }

        $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? preg_replace( '/[^0-9a-fA-F:.]/', '', wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $key = 'rl_' . md5( $ip );
        $now = time();
        $hits = get_transient( $key );

        if ( ! is_array( $hits ) ) {
            $hits = [];
        }
        $hits = array_values( array_filter( $hits, function ( $ts ) use ( $now, $window ) {
            return (int) $ts > $now - $window;
        } ) );

        if ( count( $hits ) >= $limit ) {
            return false;
        }

        $hits[] = $now;
        set_transient( $key, $hits, $window );

        return true;
    }
}

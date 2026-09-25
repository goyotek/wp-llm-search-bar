<?php
/**
 * Admin-Einstellungen: Welche Seiten, Beiträge, Custom Post Types, Archive und
 * Taxonomien durchsucht werden. Speichert in wp_options (keine eigenen DB-Tabellen).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_LLM_Search_Bar_Settings {

    const OPTION_KEY = 'wp_llm_search_bar_settings';

    private static $defaults = [
        'scope'           => 'site',
        'post_types'      => [ 'page', 'post' ],
        'taxonomy'        => '',
        'archives'        => [],
        'rate_limit'      => 15,
        'rate_window'     => 60,
        'cache_ttl'       => 900,
    ];

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_settings_page' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'save_post', [ __CLASS__, 'flush_chunk_cache' ], 10, 2 );
    }

    public static function defaults() {
        return self::$defaults;
    }

    public static function get_settings() {
        $saved = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        return wp_parse_args( $saved, self::$defaults );
    }

    public static function get_selected_post_types( $settings = null ) {
        $settings  = $settings ? $settings : self::get_settings();
        $available = self::public_post_types();
        $selected  = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : [];
        $valid     = array_values( array_intersect( array_keys( $available ), $selected ) );
        return $valid ? array_combine( $valid, $valid ) : [];
    }

    public static function get_selected_taxonomy( $settings = null ) {
        $settings = $settings ? $settings : self::get_settings();
        $taxonomy = isset( $settings['taxonomy'] ) ? (string) $settings['taxonomy'] : '';
        if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
            return '';
        }
        return $taxonomy;
    }

    public static function public_post_types() {
        $types = get_post_types( [ 'public' => true ], 'objects' );
        $result = [];
        foreach ( $types as $type ) {
            if ( 'attachment' === $type->name ) {
                continue;
            }
            $result[ $type->name ] = $type->labels->singular_name;
        }
        return $result;
    }

    public static function public_taxonomies() {
        $taxos = get_taxonomies( [ 'public' => true ], 'objects' );
        $result = [];
        foreach ( $taxos as $taxonomy ) {
            if ( 'post_format' === $taxonomy->name ) {
                continue;
            }
            $result[ $taxonomy->name ] = $taxonomy->labels->singular_name;
        }
        return $result;
    }

    public static function add_settings_page() {
        add_options_page(
            __( 'WP LLM Search Bar', 'wp-llm-search-bar' ),
            __( 'WP LLM Search Bar', 'wp-llm-search-bar' ),
            'manage_options',
            'wp-llm-search-bar',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function register_settings() {
        register_setting(
            'wp_llm_search_bar',
            self::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
                'default'           => self::$defaults,
            ]
        );
    }

    public static function sanitize_settings( $input ) {
        $input = is_array( $input ) ? $input : [];

        $scope = isset( $input['scope'] ) ? $input['scope'] : 'site';
        if ( ! in_array( $scope, [ 'site', 'current_page' ], true ) ) {
            $scope = 'site';
        }

        $post_types = self::get_selected_post_types( [ 'post_types' => isset( $input['post_types'] ) ? $input['post_types'] : [] ] );

        $taxonomy = isset( $input['taxonomy'] ) ? (string) $input['taxonomy'] : '';
        if ( '' !== $taxonomy && ! taxonomy_exists( $taxonomy ) ) {
            $taxonomy = '';
        }

        $archives = [];
        if ( isset( $input['archives'] ) && is_array( $input['archives'] ) ) {
            foreach ( $input['archives'] as $post_type ) {
                if ( post_type_exists( $post_type ) ) {
                    $archives[] = $post_type;
                }
            }
        }

        return [
            'scope'       => $scope,
            'post_types'  => array_values( array_keys( $post_types ) ),
            'taxonomy'    => $taxonomy,
            'archives'    => $archives,
            'rate_limit'  => max( 1, min( 100, absint( $input['rate_limit'] ?? 15 ) ) ),
            'rate_window' => max( 10, min( 3600, absint( $input['rate_window'] ?? 60 ) ) ),
            'cache_ttl'   => max( 0, min( DAY_IN_SECONDS, absint( $input['cache_ttl'] ?? 900 ) ) ),
        ];
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = self::get_settings();
        $post_types = self::public_post_types();
        $taxonomies = self::public_taxonomies();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP LLM Search Bar', 'wp-llm-search-bar' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'wp_llm_search_bar' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Suchbereich', 'wp-llm-search-bar' ); ?></th>
                        <td>
                            <label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[scope]" value="site" <?php checked( $settings['scope'], 'site' ); ?>> <?php esc_html_e( 'Gesamte Website (siehe Auswahl unten)', 'wp-llm-search-bar' ); ?></label><br>
                            <label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[scope]" value="current_page" <?php checked( $settings['scope'], 'current_page' ); ?>> <?php esc_html_e( 'Nur die Seite, auf der der Block eingebunden ist', 'wp-llm-search-bar' ); ?></label>
                            <p class="description"><?php esc_html_e( 'Archiv-Modus: werden unten Archive aktiviert, durchsucht die Suche auch die Archiv-/Taxonomie-Seiten selbst.', 'wp-llm-search-bar' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Inhaltstypen', 'wp-llm-search-bar' ); ?></th>
                        <td>
                            <?php foreach ( $post_types as $name => $label ) : ?>
                                <label style="display: block;">
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[post_types][]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, (array) $settings['post_types'], true ) ); ?>>
                                    <?php echo esc_html( $label ); ?> (<?php echo esc_html( $name ); ?>)
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e( 'Seiten, Beiträge und Custom Post Types, die in die Suche einbezogen werden.', 'wp-llm-search-bar' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Taxonomie', 'wp-llm-search-bar' ); ?></th>
                        <td>
                            <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[taxonomy]">
                                <option value=""><?php esc_html_e( '— Keine —', 'wp-llm-search-bar' ); ?></option>
                                <?php foreach ( $taxonomies as $name => $label ) : ?>
                                    <option value="<?php echo esc_attr( $name ); ?>" <?php selected( $settings['taxonomy'], $name ); ?>><?php echo esc_html( $label ); ?> (<?php echo esc_html( $name ); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Wenn gesetzt, werden Beiträge passender Taxonomie-Begriffe bevorzugt und das Archiv selbst wird als Quelle verlinkt (z. B. /angebot/#Digitalisierung).', 'wp-llm-search-bar' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Archive durchsuchen', 'wp-llm-search-bar' ); ?></th>
                        <td>
                            <?php foreach ( array_keys( $post_types ) as $name ) : ?>
                                <?php $has_archive = get_post_type_object( $name )->has_archive; ?>
                                <?php if ( ! $has_archive ) { continue; } ?>
                                <label style="display: block;">
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[archives][]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, (array) $settings['archives'], true ) ); ?>>
                                    <?php echo esc_html( $post_types[ $name ] ); ?> (<?php echo esc_html( $name ); ?>)
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e( 'Archive der ausgewählten Typen werden als eigene Quelle in die Suche aufgenommen.', 'wp-llm-search-bar' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Schutz vor Missbrauch', 'wp-llm-search-bar' ); ?></th>
                        <td>
                            <label>
                                <input type="number" min="1" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[rate_limit]" value="<?php echo esc_attr( $settings['rate_limit'] ); ?>">
                                <?php esc_html_e( 'Anfragen pro', 'wp-llm-search-bar' ); ?>
                                <input type="number" min="10" max="3600" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[rate_window]" value="<?php echo esc_attr( $settings['rate_window'] ); ?>">
                                <?php esc_html_e( 'Sekunden und IP', 'wp-llm-search-bar' ); ?>
                            </label><br>
                            <label>
                                <?php esc_html_e( 'Antworten cachen (Sekunden, 0 = aus):', 'wp-llm-search-bar' ); ?>
                                <input type="number" min="0" max="86400" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cache_ttl]" value="<?php echo esc_attr( $settings['cache_ttl'] ); ?>">
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Chunk-Cache der betroffenen Seite invalidieren (Standard-Object-Cache/Transients).
     */
    public static function flush_chunk_cache( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        wp_cache_delete( 'chunks_' . (int) $post_id, WP_LLM_Search_Bar_Retriever::CACHE_GROUP );
    }
}

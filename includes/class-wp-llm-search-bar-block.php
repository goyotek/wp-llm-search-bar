<?php
/**
 * Gutenberg-Block-Registrierung: Editor-Skript, Frontend-Assets und
 * Server-Side-Rendering mit Block-Attributen (Modus, Quellenanzahl, CTA).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_LLM_Search_Bar_Block {

    const BLOCK_HANDLE = 'wp-llm-search-bar/anchor-search';
    const ASSET_HANDLE = 'wp-llm-search-bar-editor';
    const FRONT_HANDLE = 'wp-llm-search-bar-frontend';

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_block' ], 20 );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_frontend_assets' ] );
    }

    public static function register_block() {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        wp_register_script(
            self::ASSET_HANDLE,
            WP_LLM_SEARCH_BAR_URL . 'editor.js',
            [ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ],
            WP_LLM_SEARCH_BAR_VERSION,
            true
        );

        wp_register_style(
            'wp-llm-search-bar-style',
            WP_LLM_SEARCH_BAR_URL . 'css/frontend.css',
            [],
            WP_LLM_SEARCH_BAR_VERSION
        );

        wp_register_script(
            self::FRONT_HANDLE,
            WP_LLM_SEARCH_BAR_URL . 'frontend.js',
            [],
            WP_LLM_SEARCH_BAR_VERSION,
            true
        );

        register_block_type( self::BLOCK_HANDLE, [
            'editor_script'   => self::ASSET_HANDLE,
            'render_callback' => [ __CLASS__, 'render' ],
            'attributes'      => [
                'mode'         => [ 'type' => 'string', 'default' => 'answer' ],
                'placeholder'  => [ 'type' => 'string', 'default' => '' ],
                'maxSources'   => [ 'type' => 'number', 'default' => 3 ],
                'ctaText'      => [ 'type' => 'string', 'default' => '' ],
                'ctaEmail'     => [ 'type' => 'string', 'default' => '' ],
                'ctaSubject'   => [ 'type' => 'string', 'default' => '' ],
            ],
        ] );
    }

    public static function register_frontend_assets() {
        wp_enqueue_style( 'wp-llm-search-bar-style' );
        wp_enqueue_script( self::FRONT_HANDLE );
    }

    public static function render( $attributes ) {
        $mode        = isset( $attributes['mode'] ) && 'links' === $attributes['mode'] ? 'links' : 'answer';
        $placeholder = isset( $attributes['placeholder'] ) && '' !== trim( $attributes['placeholder'] )
            ? sanitize_text_field( $attributes['placeholder'] )
            : __( 'Wonach möchtest du wissen?', 'wp-llm-search-bar' );
        $max_sources = isset( $attributes['maxSources'] ) ? min( 5, max( 1, (int) $attributes['maxSources'] ) ) : 3;

        wp_enqueue_style( 'wp-llm-search-bar-style' );
        wp_enqueue_script( self::FRONT_HANDLE );

        ob_start();
        ?>
        <div class="wp-llm-search-bar"
             data-mode="<?php echo esc_attr( $mode ); ?>"
             data-max-sources="<?php echo esc_attr( $max_sources ); ?>"
             data-page-id="<?php echo esc_attr( get_the_ID() ); ?>"
             data-cta-text="<?php echo esc_attr( sanitize_text_field( isset( $attributes['ctaText'] ) ? $attributes['ctaText'] : '' ) ); ?>"
             data-cta-email="<?php echo esc_attr( sanitize_email( isset( $attributes['ctaEmail'] ) ? $attributes['ctaEmail'] : '' ) ); ?>"
             data-cta-subject="<?php echo esc_attr( sanitize_text_field( isset( $attributes['ctaSubject'] ) ? $attributes['ctaSubject'] : '' ) ); ?>">
            <form class="llm-search-form" autocomplete="off">
                <input type="text"
                       class="llm-search-input"
                       placeholder="<?php echo esc_attr( $placeholder ); ?>"
                       aria-label="<?php esc_attr_e( 'Suche', 'wp-llm-search-bar' ); ?>">
                <button type="submit" class="llm-search-button">
                    <?php esc_html_e( 'Suchen', 'wp-llm-search-bar' ); ?>
                </button>
            </form>
            <div class="llm-search-results" aria-live="polite"></div>
        </div>
        <?php
        return ob_get_clean();
    }
}

<?php
/**
 * Plugin Name: WP LLM Search Bar
 * Description: Gutenberg-Block mit KI-gestützter Suche. Erzeugt eine kurze Antwort im Stil einer KI-Übersicht und verlinkt ausschließlich Quellen innerhalb der eigenen Website.
 * Version: 2.0.0
 * Requires at least: 6.5
 * Author: GOYOTEK Communications e.U.
 * License: GPL-2.0-or-later
 *
 * ANLEITUNG:
 * 1. Plugin-Ordner: /wp-content/plugins/wp-llm-search-bar/
 * 2. Plugin im WordPress-Dashboard aktivieren.
 * 3. Stellen Sie sicher, dass ein KI-Konnektor in WordPress 7.0 konfiguriert ist (z. B. Mistral).
 * 4. Block "WP LLM Search Bar" in eine Seite einfügen und unter Einstellungen → WP LLM Search Bar
 *    festlegen, welche Seiten, Beiträge, Custom Post Types, Archive und Taxonomien durchsucht werden.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_LLM_SEARCH_BAR_VERSION', '2.0.0' );
define( 'WP_LLM_SEARCH_BAR_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_LLM_SEARCH_BAR_URL', plugin_dir_url( __FILE__ ) );

require_once WP_LLM_SEARCH_BAR_DIR . 'includes/class-wp-llm-search-bar-extractor.php';
require_once WP_LLM_SEARCH_BAR_DIR . 'includes/class-wp-llm-search-bar-retriever.php';
require_once WP_LLM_SEARCH_BAR_DIR . 'includes/class-wp-llm-search-bar-generator.php';
require_once WP_LLM_SEARCH_BAR_DIR . 'includes/class-wp-llm-search-bar-settings.php';
require_once WP_LLM_SEARCH_BAR_DIR . 'includes/class-wp-llm-search-bar-rest.php';
require_once WP_LLM_SEARCH_BAR_DIR . 'includes/class-wp-llm-search-bar-block.php';

add_action( 'init', [ 'WP_LLM_Search_Bar_Block', 'init' ] );
add_action( 'rest_api_init', [ 'WP_LLM_Search_Bar_Rest', 'init' ] );

WP_LLM_Search_Bar_Settings::init();

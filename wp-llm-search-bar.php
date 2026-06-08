<?php
/**
 * Plugin Name: WP LLM Search Bar
 * Description: Ein Gutenberg-Block mit KI-basierter semantischer Suche für Anchor-Links.
 * Version: 1.4
 * Author: GOYOTEK Communications e.U.
 * 
 * ANLEITUNG:
 * 1. Erstelle den Ordner: /wp-content/plugins/wp-llm-search-bar/
 * 2. Speichere diese Datei als: wp-llm-search-bar.php
 * 3. Aktiviere das Plugin im WordPress-Dashboard.
 * 4. Stelle sicher, dass ein KI-Konnektor in WordPress 7.0 konfiguriert ist (z. B. Mistral).
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin file constant for activation hooks
define('WP_LLM_SEARCH_BAR_PLUGIN_FILE', __FILE__);

// ============================================
// LOAD REQUIRED FILES
// ============================================
require_once plugin_dir_path(__FILE__) . 'includes/class-indexer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-semantic-search.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-result-merger.php';

// Initialize the indexer (Phase 1: Basic Indexing)
$wp_llm_search_indexer = new WP_LLM_Search_Indexer();

// Initialize semantic search (Phase 2)
$wp_llm_search_semantic = new WP_LLM_Search_Semantic($wp_llm_search_indexer);

// Initialize result merger (Phase 2)
$wp_llm_search_result_merger = new WP_LLM_Search_Result_Merger();

// ============================================
// 1. BLOCK REGISTRATION
// ============================================
add_action('init', function() {
    register_block_type('wp-llm-search-bar/anchor-search', [
        'render_callback' => 'wp_llm_search_bar_render',
        'attributes'      => [],
    ]);
});

// ============================================
// 2. BLOCK RENDER
// ============================================
function wp_llm_search_bar_render($attributes) {
    $styles = '
        <style>
            .wp-llm-search-bar { margin: 20px 0; max-width: 600px; }
            .llm-search-input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; }
            .llm-search-results { margin-top: 15px; }
            .llm-search-result-item { margin: 8px 0; padding: 10px; background: #f9f9f9; border-left: 3px solid #0073aa; }
            .llm-search-result-link { color: #0073aa; text-decoration: none; font-weight: 600; cursor: pointer; }
            .llm-search-result-link:hover { text-decoration: underline; }
            .llm-search-result-tag { color: #666; font-size: 0.85em; margin-left: 8px; }
            .llm-search-hint { color: #666; font-style: italic; }
            .llm-search-loading { color: #0073aa; }
            .llm-search-error { color: #d63638; }
        </style>
    ';

    $html = $styles;
    
    // Output nonce for frontend JavaScript
    $html .= '<script>var wpLlmSearchBar = { nonce: "' . esc_js(wp_create_nonce('wp_rest')) . '" };</script>';
    
    $html .= '<div class="wp-llm-search-bar" data-page-id="' . esc_attr(get_the_ID()) . '">';
    $html .= '<input type="text" class="llm-search-input" placeholder="Wonach interessierst du dich?" />';
    $html .= '<div class="llm-search-results"></div>';
    $html .= '</div>';

    return $html;
}

// ============================================
// 3. REST API ENDPOINT MIT VERBESSERTER KI-SUCHE
// ============================================
add_action('rest_api_init', function() {
    register_rest_route('wp-llm-search-bar/v1', '/search', [
        'methods'  => 'POST',
        'callback' => 'wp_llm_search_bar_endpoint',
        'permission_callback' => '__return_true',
        'args' => [
            'query' => [
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'page_id' => [
                'type' => 'integer',
                'default' => 0,
            ],
            'mode' => [
                'type' => 'string',
                'enum' => ['ai', 'keyword', 'hybrid', 'semantic'],
                'default' => 'hybrid',
            ],
            'scope' => [
                'type' => 'string',
                'enum' => ['page', 'site'],
                'default' => 'page',
            ],
        ],
    ]);
});

/**
 * Enhanced search endpoint with keyword, semantic, and AI fallback (Phase 2)
 * 
 * @param WP_REST_Request $request REST request object
 * @return WP_REST_Response Response with search results
 */
function wp_llm_search_bar_endpoint(WP_REST_Request $request) {
    global $wp_llm_search_indexer, $wp_llm_search_semantic, $wp_llm_search_result_merger;
    
    $query = sanitize_text_field($request->get_param('query'));
    $page_id = absint($request->get_param('page_id'));
    $mode = $request->get_param('mode') ?: 'hybrid';
    $scope = $request->get_param('scope') ?: 'page';

    if (empty($query)) {
        return new WP_REST_Response(['error' => 'Query ist erforderlich'], 400);
    }

    // Generate cache key
    $cache_key = 'llm_search_' . md5($query . $page_id . $mode . $scope);
    
    // Check cache first (Phase 2: Multi-layer caching)
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return new WP_REST_Response(['results' => $cached], 200);
    }

    $search_sources = [];
    
    // Try keyword search first if mode is keyword or hybrid
    if ($mode === 'keyword' || $mode === 'hybrid') {
        $keyword_results = [];
        
        // Use indexer if available and content is indexed
        if ($wp_llm_search_indexer && $wp_llm_search_indexer->table_exists()) {
            $keyword_results = $wp_llm_search_indexer->keyword_search($query, $scope, $page_id, 20);
        }
        
        // If we have keyword results, use them
        if (!empty($keyword_results)) {
            $search_sources['keyword'] = $keyword_results;
            
            // If mode is keyword-only, return now
            if ($mode === 'keyword') {
                set_transient($cache_key, $keyword_results, 300); // Cache for 5 minutes
                return new WP_REST_Response(['results' => $keyword_results], 200);
            }
        }
    }

    // Try semantic search if mode is semantic or hybrid
    if ($mode === 'semantic' || $mode === 'hybrid') {
        if ($wp_llm_search_semantic) {
            $semantic_results = $wp_llm_search_semantic->semantic_search($query, $scope, $page_id, 20);
            if (!empty($semantic_results)) {
                $search_sources['semantic'] = $semantic_results;
            }
        }
    }

    // Fall back to traditional AI search if:
    // - Mode is 'ai' or 'hybrid' and we have insufficient results
    // - No semantic search available
    if (($mode === 'ai' || ($mode === 'hybrid' && count($search_sources) < 1)) || empty($search_sources)) {
        // Get content based on scope
        if ($scope === 'page' && $page_id) {
            $content = get_post_field('post_content', $page_id);
        } else {
            // For site-wide AI search, we need to get content from indexed posts
            // This is a limitation - AI search currently only works on single page
            // For now, fall back to page scope
            if (!$page_id) {
                return new WP_REST_Response(['error' => 'page_id ist für AI-Suche erforderlich'], 400);
            }
            $content = get_post_field('post_content', $page_id);
        }
        
        if (empty($content)) {
            return new WP_REST_Response(['error' => 'Kein Inhalt für diese Seite gefunden'], 404);
        }

        // Extract sections with context
        $sections = extract_sections_with_context($content);
        if (empty($sections)) {
            return new WP_REST_Response(['results' => []], 200);
        }

        // Create AI prompt for semantic search
        $prompt = sprintf(
            "Du bist ein präziser Content-Matcher. Analysiere die folgende Suchanfrage und die Abschnitte einer Webseite. 
            Die Suchanfrage lautet: '%s'.
            
            Hier sind die Abschnitte mit ihren IDs und Inhalten:
            %s
            
            Aufgabe:
            1. Zerlege die Suchanfrage in ihre semantischen Bestandteile (z.B. 'Unternehmen für Hunde' → ['Unternehmen', 'Hunde']).
            2. Finde die Abschnitte, die am besten zu diesen Bestandteilen passen.
            3. Berücksichtige Synonyme und semantische Ähnlichkeit (z.B. 'Firma' = 'Unternehmen', 'Tier' = 'Hund').
            4. Gib die IDs der 3 relevantesten Abschnitte als JSON-Array zurück.
            5. Antworte NUR mit einem JSON-Array der IDs, z.B.: [\"section-1\", \"section-2\"]
            6. Falls keine passenden Abschnitte gefunden werden, gib ein leeres Array zurück: []",
            esc_js($query),
            json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Query AI connector
        $result = wp_ai_client_prompt($prompt)
            ->using_temperature(0.2)
            ->using_max_tokens(1000)
            ->as_json_response(['type' => 'array', 'items' => ['type' => 'string']])
            ->generate_text_result();

        // Error handling
        if (is_wp_error($result)) {
            // If we have other results, return those
            if (!empty($search_sources)) {
                $results = $wp_llm_search_result_merger->merge($search_sources);
                $results = $wp_llm_search_result_merger->apply_threshold($results, 0.05);
                $results = $wp_llm_search_result_merger->limit($results, 20);
                set_transient($cache_key, $results, 300);
                return new WP_REST_Response(['results' => $results], 200);
            }
            return new WP_REST_Response(['error' => 'KI-Fehler: ' . $result->get_error_message()], 500);
        }

        // Parse AI results
        $section_ids = json_decode($result, true);
        
        // Validate AI response
        if (!is_array($section_ids)) {
            $cleaned = trim($result);
            if (strpos($cleaned, '[') === 0) {
                $section_ids = json_decode($cleaned, true);
            }
            
            if (!is_array($section_ids)) {
                // Fallback to all sections
                $section_ids = array_column($sections, 'id');
            }
        }

        // Filter relevant sections and add to results
        $ai_results = array_filter($sections, function($section) use ($section_ids) {
            return in_array($section['id'], $section_ids);
        });

        // If no AI results, use all sections
        if (empty($ai_results)) {
            $ai_results = $sections;
        }

        // Sort by AI relevance
        usort($ai_results, function($a, $b) use ($section_ids) {
            $a_pos = array_search($a['id'], $section_ids);
            $b_pos = array_search($b['id'], $section_ids);
            return $a_pos <=> $b_pos;
        });

        // Add AI results to sources
        $search_sources['ai'] = array_values($ai_results);
    }

    // Merge all results using the result merger
    if (!empty($search_sources)) {
        $results = $wp_llm_search_result_merger->merge($search_sources);
    } else {
        $results = [];
    }
    
    // Apply threshold and limit
    $results = $wp_llm_search_result_merger->apply_threshold($results, 0.05);
    $results = $wp_llm_search_result_merger->limit($results, 20);

    // Cache results for 5 minutes
    set_transient($cache_key, $results, 300);

    return new WP_REST_Response(['results' => $results], 200);
}

// ============================================
// 4. VERBESSERTE HELPER FUNCTION: Abschnitte mit Kontext extrahieren
// ============================================
function extract_sections_with_context($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);
    $sections = [];

    // 1. Alle Elemente mit ID-Attribut + Textinhalt
    foreach ($xpath->query('//*[@id]') as $node) {
        $text = trim($node->nodeValue);
        if (empty($text)) {
            // Falls kein direkter Text, versuche Kind-Elemente
            $text = trim($node->textContent);
        }
        
        $sections[] = [
            'id' => $node->getAttribute('id'),
            'text' => $text,
            'tag' => $node->nodeName,
            'context' => get_section_context($node, $xpath), // Zusätzlicher Kontext
        ];
    }

    // 2. Alle <a>-Tags mit name-Attribut
    foreach ($xpath->query('//a[@name]') as $node) {
        $id = $node->getAttribute('name');
        $text = trim($node->nodeValue);
        if (empty($text)) {
            $text = trim($node->textContent);
        }
        
        if (!in_array($id, array_column($sections, 'id'))) {
            $sections[] = [
                'id' => $id,
                'text' => $text,
                'tag' => 'a',
                'context' => get_section_context($node, $xpath),
            ];
        }
    }

    // 3. Überschriften ohne ID, aber mit Text
    $headings = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
    foreach ($headings as $heading) {
        $text = trim($heading->nodeValue);
        if (!empty($text)) {
            $id = sanitize_title($text);
            if (!in_array($id, array_column($sections, 'id'))) {
                $sections[] = [
                    'id' => $id,
                    'text' => $text,
                    'tag' => $heading->nodeName,
                    'context' => get_section_context($heading, $xpath),
                ];
            }
        }
    }

    return $sections;
}

// ============================================
// 5. HELPER: Zusätzlichen Kontext für Abschnitte extrahieren
// ============================================
function get_section_context($node, $xpath) {
    // Hole den Text der nächsten 2 Geschwister-Elemente für mehr Kontext
    $context = [];
    $next = $node->nextSibling;
    for ($i = 0; $i < 2 && $next !== null; $i++) {
        if ($next instanceof DOMElement) {
            $text = trim($next->textContent);
            if (!empty($text)) {
                $context[] = $text;
            }
        }
        $next = $next->nextSibling;
    }
    
    // Hole den Text des Eltern-Elements
    if ($node->parentNode instanceof DOMElement) {
        $parentText = trim($node->parentNode->textContent);
        if (!empty($parentText) && !in_array($parentText, $context)) {
            $context[] = $parentText;
        }
    }
    
    return implode(' ', array_slice($context, 0, 3));
}

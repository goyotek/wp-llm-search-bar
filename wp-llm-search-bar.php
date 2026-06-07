<?php
/**
 * Plugin Name: WP LLM Search Bar
 * Description: Ein Gutenberg-Block mit KI-basierter semantischer Suche für Anchor-Links.
 * Version: 1.2
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
    $html .= '<div class="wp-llm-search-bar" data-page-id="' . esc_attr(get_the_ID()) . '">';
    $html .= '<input type="text" class="llm-search-input" placeholder="Wonach interessierst du dich?" />';
    $html .= '<div class="llm-search-results"></div>';
    $html .= '</div>';

    // Inline-JavaScript
    $html .= '
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const searchWrappers = document.querySelectorAll(".wp-llm-search-bar");
        
        searchWrappers.forEach(function(wrapper) {
            const input = wrapper.querySelector(".llm-search-input");
            const results = wrapper.querySelector(".llm-search-results");
            const pageId = wrapper.dataset.pageId || 0;

            if (!input || !results) return;

            let timeout;
            input.addEventListener("input", function() {
                clearTimeout(timeout);
                timeout = setTimeout(function() {
                    performSearch(input, results, pageId);
                }, 500);
            });

            async function performSearch(input, results, pageId) {
                const query = input.value.trim();
                
                if (query.length < 2) {  // Reduziert auf 2 Zeichen für bessere UX
                    results.innerHTML = "<p class=\"llm-search-hint\">Geben Sie mindestens 2 Zeichen ein...</p>";
                    return;
                }

                results.innerHTML = "<p class=\"llm-search-loading\">🔍 Suche mit KI...</p>";

                try {
                    const response = await fetch("/wp-json/wp-llm-search-bar/v1/search", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "X-WP-Nonce": "' . wp_create_nonce('wp_rest') . '"
                        },
                        body: JSON.stringify({
                            query: query,
                            page_id: pageId
                        })
                    });

                    const data = await response.json();
                    
                    if (data.error) {
                        results.innerHTML = `<p class="llm-search-error">❌ Fehler: ${data.error}</p>`;
                        return;
                    }

                    if (data.results && data.results.length > 0) {
                        results.innerHTML = data.results
                            .map(r => `
                                <div class="llm-search-result-item">
                                    <a href="#${r.id}" 
                                       onclick="event.preventDefault(); document.getElementById(\'${r.id}\').scrollIntoView({behavior: \'smooth\'});"
                                       class="llm-search-result-link">
                                        ${r.text || r.id}
                                    </a>
                                    <span class="llm-search-result-tag">(${r.tag || "section"})</span>
                                </div>
                            `)
                            .join("");
                    } else {
                        results.innerHTML = "<p class=\"llm-search-hint\">Keine passenden Abschnitte gefunden.</p>";
                    }
                } catch (error) {
                    results.innerHTML = `<p class="llm-search-error">❌ Netzwerkfehler: ${error.message}</p>`;
                }
            }
        });
    });
    </script>
    ';

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
    ]);
});

function wp_llm_search_bar_endpoint(WP_REST_Request $request) {
    $query = sanitize_text_field($request->get_param('query'));
    $page_id = absint($request->get_param('page_id'));

    if (empty($query) || !$page_id) {
        return new WP_REST_Response(['error' => 'Query und page_id sind erforderlich'], 400);
    }

    // 1. Inhalt der Seite holen
    $content = get_post_field('post_content', $page_id);
    if (empty($content)) {
        return new WP_REST_Response(['error' => 'Kein Inhalt für diese Seite gefunden'], 404);
    }

    // 2. Abschnitte mit mehr Kontext extrahieren
    $sections = extract_sections_with_context($content);
    if (empty($sections)) {
        return new WP_REST_Response(['results' => []], 200);
    }

    // 3. Verbesserten Prompt für semantische Suche erstellen
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

    // 4. KI-Konnektor abfragen
    $result = wp_ai_client_prompt($prompt)
        ->using_temperature(0.2)  // Niedrigere Temperatur für präzisere Ergebnisse
        ->using_max_tokens(1000)  // Mehr Tokens für komplexe Analysen
        ->as_json_response(['type' => 'array', 'items' => ['type' => 'string']])
        ->generate_text_result();

    // 5. Fehlerbehandlung
    if (is_wp_error($result)) {
        return new WP_REST_Response(['error' => 'KI-Fehler: ' . $result->get_error_message()], 500);
    }

    // 6. Ergebnisse parsen
    $section_ids = json_decode($result, true);
    
    // 7. Validierung der KI-Antwort
    if (!is_array($section_ids)) {
        // Versuche, die Antwort zu korrigieren (falls KI z.B. Text statt JSON zurückgibt)
        $cleaned = trim($result);
        if (strpos($cleaned, '[') === 0) {
            $section_ids = json_decode($cleaned, true);
        }
        
        if (!is_array($section_ids)) {
            // Fallback: Alle Abschnitte zurückgeben
            return new WP_REST_Response(['results' => $sections], 200);
        }
    }

    // 8. Nur die relevanten Abschnitte zurückgeben
    $results = array_filter($sections, function($section) use ($section_ids) {
        return in_array($section['id'], $section_ids);
    });

    // 9. Falls keine Ergebnisse, alle Abschnitte zurückgeben (Fallback)
    if (empty($results)) {
        $results = $sections;
    }

    // 10. Nach Relevanz sortieren (falls KI die Reihenfolge nicht beibehält)
    usort($results, function($a, $b) use ($section_ids) {
        $a_pos = array_search($a['id'], $section_ids);
        $b_pos = array_search($b['id'], $section_ids);
        return $a_pos <=> $b_pos;
    });

    return new WP_REST_Response(['results' => array_values($results)], 200);
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
<?php
/**
 * WP LLM Search Bar - Indexer Class
 * Handles content indexing for fast keyword search
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_LLM_Search_Indexer {
    
    /**
     * @var string Database table name
     */
    private $table_name;
    
    /**
     * @var wpdb WordPress database object
     */
    private $wpdb;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'llm_search_index';
        
        // Create table on initialization
        $this->create_table();
        
        // Hook into post save events
        $this->setup_hooks();
    }
    
    /**
     * Setup WordPress hooks
     */
    private function setup_hooks() {
        // Index content when post is saved
        add_action('save_post', [$this, 'index_post'], 10, 2);
        
        // Delete index when post is deleted
        add_action('delete_post', [$this, 'delete_post_index']);
        
        // Plugin activation hook for table creation
        register_activation_hook(WP_LLM_SEARCH_BAR_PLUGIN_FILE, [$this, 'create_table']);
    }
    
    /**
     * Create the search index database table
     */
    public function create_table() {
        $charset = $this->wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            post_id BIGINT UNSIGNED NOT NULL,
            post_type VARCHAR(20) NOT NULL DEFAULT 'post',
            content_hash VARCHAR(64) NOT NULL,
            content_text LONGTEXT NOT NULL,
            sections JSON NOT NULL,
            tokens INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (post_id),
            INDEX (post_type),
            INDEX (content_hash),
            FULLTEXT INDEX content_fulltext (content_text)
        ) $charset;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * Index a post's content
     * 
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */
    public function index_post($post_id, $post) {
        // Only index published posts
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Skip revisions and auto-drafts
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        // Get supported post types (filterable)
        $post_types = $this->get_supported_post_types();
        if (!in_array($post->post_type, $post_types)) {
            return;
        }
        
        // Extract and clean content
        $content = $this->extract_content($post);
        $content_hash = md5($content);
        
        // Extract sections with context
        $sections = $this->extract_sections($post->post_content);
        
        // Count tokens
        $tokens = $this->count_tokens($content);
        
        // Check if content has changed
        $existing = $this->get_indexed_post($post_id);
        if ($existing && $existing->content_hash === $content_hash) {
            return; // Content hasn't changed, no need to re-index
        }
        
        // Store in database
        $this->store_index($post_id, $post->post_type, $content_hash, $content, $sections, $tokens);
        
        // Clear any cached searches for this post
        $this->clear_post_cache($post_id);
    }
    
    /**
     * Delete index for a post
     * 
     * @param int $post_id Post ID
     */
    public function delete_post_index($post_id) {
        $this->wpdb->delete($this->table_name, ['post_id' => $post_id]);
        $this->clear_post_cache($post_id);
    }
    
    /**
     * Get supported post types for indexing
     * 
     * @return array Post types
     */
    private function get_supported_post_types() {
        // Filterable list of post types to index
        return apply_filters('wp_llm_search_index_post_types', ['post', 'page']);
    }
    
    /**
     * Extract content from post
     * 
     * @param WP_Post $post Post object
     * @return string Cleaned content
     */
    private function extract_content($post) {
        // Get the raw content
        $content = $post->post_content;
        
        // Remove shortcodes
        $content = strip_shortcodes($content);
        
        // Remove HTML tags but preserve line breaks
        $content = wp_strip_all_tags($content, true);
        
        // Normalize whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        
        // Trim
        $content = trim($content);
        
        return $content;
    }
    
    /**
     * Extract sections from HTML content
     * Reuses logic from main plugin
     * 
     * @param string $html HTML content
     * @return array Sections with context
     */
    private function extract_sections($html) {
        $sections = [];
        
        if (empty($html)) {
            return $sections;
        }
        
        try {
            $dom = new DOMDocument();
            @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            $xpath = new DOMXPath($dom);
            
            // 1. All elements with ID attribute
            foreach ($xpath->query('//*[@id]') as $node) {
                $text = trim($node->nodeValue);
                if (empty($text)) {
                    $text = trim($node->textContent);
                }
                
                if (!empty($text)) {
                    $sections[] = [
                        'id' => $node->getAttribute('id'),
                        'text' => $text,
                        'tag' => $node->nodeName,
                        'context' => $this->get_section_context($node, $xpath),
                    ];
                }
            }
            
            // 2. All <a> tags with name attribute
            foreach ($xpath->query('//a[@name]') as $node) {
                $id = $node->getAttribute('name');
                $text = trim($node->nodeValue);
                if (empty($text)) {
                    $text = trim($node->textContent);
                }
                
                if (!empty($text) && !$this->section_exists($sections, $id)) {
                    $sections[] = [
                        'id' => $id,
                        'text' => $text,
                        'tag' => 'a',
                        'context' => $this->get_section_context($node, $xpath),
                    ];
                }
            }
            
            // 3. Headings without ID
            $headings = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
            foreach ($headings as $heading) {
                $text = trim($heading->nodeValue);
                if (!empty($text)) {
                    $id = sanitize_title($text);
                    if (!$this->section_exists($sections, $id)) {
                        $sections[] = [
                            'id' => $id,
                            'text' => $text,
                            'tag' => $heading->nodeName,
                            'context' => $this->get_section_context($heading, $xpath),
                        ];
                    }
                }
            }
            
            // 4. Paragraphs and other text elements (fallback for content without IDs)
            $paragraphs = $xpath->query('//p | //article | //section | //div');
            foreach ($paragraphs as $para) {
                $text = trim($para->textContent);
                if (!empty($text) && strlen($text) > 50) { // Only index substantial paragraphs
                    $id = 'para-' . md5($text);
                    if (!$this->section_exists($sections, $id)) {
                        $sections[] = [
                            'id' => $id,
                            'text' => $text,
                            'tag' => $para->nodeName,
                            'context' => $this->get_section_context($para, $xpath),
                        ];
                    }
                }
            }
            
        } catch (Exception $e) {
            // Fallback: if DOM parsing fails, just use the cleaned content
            $content = $this->extract_content((object)['post_content' => $html]);
            if (!empty($content)) {
                $sections[] = [
                    'id' => 'content',
                    'text' => $content,
                    'tag' => 'div',
                    'context' => '',
                ];
            }
        }
        
        return $sections;
    }
    
    /**
     * Get section context (next siblings and parent)
     * 
     * @param DOMNode $node DOM node
     * @param DOMXPath $xpath XPath object
     * @return string Context text
     */
    private function get_section_context($node, $xpath) {
        $context = [];
        
        // Get text from next 2 sibling elements
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
        
        // Get parent text
        if ($node->parentNode instanceof DOMElement) {
            $parentText = trim($node->parentNode->textContent);
            if (!empty($parentText) && !in_array($parentText, $context)) {
                $context[] = $parentText;
            }
        }
        
        return implode(' ', array_slice($context, 0, 3));
    }
    
    /**
     * Check if section with ID already exists
     * 
     * @param array $sections Sections array
     * @param string $id Section ID
     * @return bool True if section exists
     */
    private function section_exists($sections, $id) {
        foreach ($sections as $section) {
            if ($section['id'] === $id) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Count tokens in text (approximate)
     * 
     * @param string $text Text to count
     * @return int Token count
     */
    private function count_tokens($text) {
        // Simple approximation: count words + punctuation
        // For more accuracy, would need to use the AI connector's tokenizer
        $words = str_word_count(strip_tags($text));
        $chars = strlen($text);
        
        // Rough estimate: 1 token ≈ 4 characters or 0.75 words
        return (int) max($words, ceil($chars / 4));
    }
    
    /**
     * Store index data in database
     * 
     * @param int $post_id Post ID
     * @param string $post_type Post type
     * @param string $content_hash Content hash
     * @param string $content_text Cleaned content
     * @param array $sections Sections array
     * @param int $tokens Token count
     */
    private function store_index($post_id, $post_type, $content_hash, $content_text, $sections, $tokens) {
        $data = [
            'post_id' => $post_id,
            'post_type' => $post_type,
            'content_hash' => $content_hash,
            'content_text' => $content_text,
            'sections' => json_encode($sections),
            'tokens' => $tokens,
            'updated_at' => current_time('mysql'),
        ];
        
        // Use replace to update existing entries
        $this->wpdb->replace($this->table_name, $data);
    }
    
    /**
     * Get indexed post data
     * 
     * @param int $post_id Post ID
     * @return object|null Indexed data or null
     */
    public function get_indexed_post($post_id) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE post_id = %d LIMIT 1",
                $post_id
            )
        );
    }
    
    /**
     * Perform keyword search
     * 
     * @param string $query Search query
     * @param string $scope Search scope (page|site)
     * @param int|null $page_id Specific page ID (for page scope)
     * @param int $limit Maximum results
     * @return array Search results
     */
    public function keyword_search($query, $scope = 'site', $page_id = null, $limit = 10) {
        $query = sanitize_text_field($query);
        
        if (empty($query) || strlen($query) < 2) {
            return [];
        }
        
        // Build the SQL query
        $sql = "SELECT post_id, post_type, content_text, sections FROM {$this->table_name} ";
        
        if ($scope === 'page' && $page_id) {
            $sql .= $this->wpdb->prepare("WHERE post_id = %d", $page_id);
        } else {
            // Fulltext search across all indexed content
            $search_term = '%' . $this->wpdb->esc_like($query) . '%';
            $sql .= $this->wpdb->prepare(
                "WHERE content_text LIKE %s OR sections LIKE %s",
                $search_term,
                $search_term
            );
        }
        
        $sql .= " ORDER BY ";
        
        // Use fulltext search if available
        if ($scope !== 'page' || !$page_id) {
            $sql .= "MATCH(content_text) AGAINST(" . $this->wpdb->prepare("%s", $query) . " IN NATURAL LANGUAGE MODE) DESC, ";
        }
        
        $sql .= "updated_at DESC LIMIT %d";
        
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare($sql, $limit)
        );
        
        if (empty($results)) {
            return [];
        }
        
        // Format results to match expected output
        $formatted = [];
        foreach ($results as $row) {
            $sections = json_decode($row->sections, true);
            if (is_array($sections)) {
                foreach ($sections as $section) {
                    // Check if section text contains query (simple match)
                    if (stripos($section['text'], $query) !== false) {
                        $formatted[] = [
                            'id' => $section['id'],
                            'text' => $section['text'],
                            'tag' => $section['tag'],
                            'context' => $section['context'] ?? '',
                            'post_id' => $row->post_id,
                            'post_type' => $row->post_type,
                            'score' => 1.0, // Will be recalculated in hybrid mode
                        ];
                    }
                }
            }
        }
        
        return array_slice($formatted, 0, $limit);
    }
    
    /**
     * Clear cache for a specific post
     * 
     * @param int $post_id Post ID
     */
    private function clear_post_cache($post_id) {
        // Clear transient cache for this post
        global $wpdb;
        $cache_keys = $wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                '%llm_search_' . $post_id . '%'
            )
        );
        
        foreach ($cache_keys as $key) {
            delete_transient(str_replace('_transient_', '', $key));
        }
    }
    
    /**
     * Get table name
     * 
     * @return string Table name
     */
    public function get_table_name() {
        return $this->table_name;
    }
    
    /**
     * Check if table exists
     * 
     * @return bool True if table exists
     */
    public function table_exists() {
        return $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $this->table_name
            )
        ) === $this->table_name;
    }
}

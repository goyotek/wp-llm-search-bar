<?php
/**
 * WP LLM Search Bar - Semantic Search Class
 * Handles AI-powered semantic search using embeddings
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_LLM_Search_Semantic {
    
    /**
     * @var string Database table name
     */
    private $table_name;
    
    /**
     * @var wpdb WordPress database object
     */
    private $wpdb;
    
    /**
     * @var WP_LLM_Search_Indexer Indexer instance
     */
    private $indexer;
    
    /**
     * Constructor
     * 
     * @param WP_LLM_Search_Indexer $indexer Indexer instance
     */
    public function __construct($indexer) {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_name = $wpdb->prefix . 'llm_search_index';
        $this->indexer = $indexer;
        
        // Add embedding column if it doesn't exist
        $this->maybe_add_embedding_column();
    }
    
    /**
     * Add embedding column to table if it doesn't exist
     */
    private function maybe_add_embedding_column() {
        $columns = $this->wpdb->get_results("SHOW COLUMNS FROM {$this->table_name}");
        $has_embedding = false;
        
        foreach ($columns as $column) {
            if ($column->Field === 'content_embedding') {
                $has_embedding = true;
                break;
            }
        }
        
        if (!$has_embedding) {
            $this->wpdb->query("ALTER TABLE {$this->table_name} ADD COLUMN content_embedding LONGTEXT AFTER sections");
            $this->wpdb->query("ALTER TABLE {$this->table_name} ADD INDEX embedding_index (content_embedding(255))");
        }
    }
    
    /**
     * Generate embedding for text using WordPress AI connector
     * 
     * @param string $text Text to generate embedding for
     * @return array|null Embedding vector or null on error
     */
    public function generate_embedding($text) {
        if (empty($text)) {
            return null;
        }
        
        // Try to use WordPress AI connector for embeddings
        if (function_exists('wp_ai_client_prompt')) {
            try {
                $result = wp_ai_client_prompt("Generate embedding for: $text")
                    ->as_embedding()
                    ->generate_text_result();
                
                if (!is_wp_error($result)) {
                    // Parse embedding - could be JSON array or other format
                    if (is_string($result)) {
                        $embedding = json_decode($result, true);
                        if (is_array($embedding)) {
                            return $embedding;
                        }
                    } elseif (is_array($result)) {
                        return $result;
                    }
                }
            } catch (Exception $e) {
                // Fall through to manual embedding
            }
        }
        
        // Fallback: Simple word frequency vector (not ideal but works without AI)
        return $this->generate_simple_embedding($text);
    }
    
    /**
     * Generate a simple embedding based on word frequency
     * This is a fallback when AI embedding is not available
     * 
     * @param string $text Text to process
     * @return array Simple embedding vector
     */
    private function generate_simple_embedding($text) {
        $words = $this->tokenize_text($text);
        $vocab = $this->get_common_vocab();
        
        $embedding = [];
        foreach ($vocab as $word) {
            $embedding[$word] = substr_count(strtolower($text), ' ' . $word . ' ');
        }
        
        return $embedding;
    }
    
    /**
     * Tokenize text into words
     * 
     * @param string $text Text to tokenize
     * @return array Array of tokens
     */
    private function tokenize_text($text) {
        $text = strtolower($text);
        $text = preg_replace('/[^\w\s]/', ' ', $text);
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        return array_filter($words, function($word) {
            return strlen($word) > 2; // Ignore very short words
        });
    }
    
    /**
     * Get common vocabulary for simple embeddings
     * 
     * @return array Common words
     */
    private function get_common_vocab() {
        // This would ideally be loaded from a file or database
        // For now, return a basic set of common German/English words
        static $vocab = null;
        if ($vocab === null) {
            $vocab = [
                'der', 'die', 'das', 'und', 'in', 'den', 'von', 'zu', 'mit', 'sich',
                'für', 'ist', 'des', 'im', 'dem', 'nicht', 'ein', 'die', 'eine', 'als',
                'auch', 'es', 'an', 'werden', 'aus', 'er', 'hat', 'dass', 'sie', 'nach',
                'wird', 'bei', 'einer', 'der', 'um', 'haben', 'nur', 'oder', 'aber', 'vor',
                'bis', 'mehr', 'durch', 'man', 'sein', 'wurde', 'so', 'wenn', 'einen', 'wieder',
                'the', 'be', 'to', 'of', 'and', 'a', 'in', 'that', 'have', 'I', 'it', 'for',
                'not', 'on', 'with', 'he', 'as', 'you', 'do', 'at', 'this', 'but', 'his',
                'by', 'from', 'they', 'we', 'say', 'her', 'she', 'or', 'an', 'will', 'my',
            ];
        }
        return $vocab;
    }
    
    /**
     * Calculate cosine similarity between two embedding vectors
     * 
     * @param array $vec1 First vector
     * @param array $vec2 Second vector
     * @return float Similarity score (0-1)
     */
    public function cosine_similarity(array $vec1, array $vec2) {
        if (empty($vec1) || empty($vec2)) {
            return 0.0;
        }
        
        // Convert associative arrays to indexed arrays if needed
        if (isset($vec1[0]) && is_numeric($vec1[0])) {
            // Already numeric
        } else {
            // Convert to numeric array
            $vec1 = array_values($vec1);
            $vec2 = array_values($vec2);
        }
        
        // Ensure vectors are the same length
        $len = min(count($vec1), count($vec2));
        if ($len === 0) {
            return 0.0;
        }
        
        $dot_product = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;
        
        for ($i = 0; $i < $len; $i++) {
            $v1 = $vec1[$i] ?? 0;
            $v2 = $vec2[$i] ?? 0;
            $dot_product += $v1 * $v2;
            $norm1 += $v1 * $v1;
            $norm2 += $v2 * $v2;
        }
        
        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);
        
        if ($norm1 === 0 || $norm2 === 0) {
            return 0.0;
        }
        
        return $dot_product / ($norm1 * $norm2);
    }
    
    /**
     * Perform semantic search using embeddings
     * 
     * @param string $query Search query
     * @param string $scope Search scope (page|site)
     * @param int|null $page_id Specific page ID
     * @param int $limit Maximum results
     * @return array Search results with similarity scores
     */
    public function semantic_search($query, $scope = 'site', $page_id = null, $limit = 10) {
        $query = sanitize_text_field($query);
        
        if (empty($query) || strlen($query) < 2) {
            return [];
        }
        
        // Generate embedding for the query
        $query_embedding = $this->generate_embedding($query);
        if (empty($query_embedding)) {
            return [];
        }
        
        // Get all indexed posts
        $sql = "SELECT id, post_id, post_type, content_text, sections, content_embedding FROM {$this->table_name}";
        
        if ($scope === 'page' && $page_id) {
            $sql .= $this->wpdb->prepare(" WHERE post_id = %d", $page_id);
        }
        
        $sql .= " ORDER BY updated_at DESC";
        
        $results = $this->wpdb->get_results($sql);
        
        if (empty($results)) {
            return [];
        }
        
        // Calculate similarity scores
        $scored_results = [];
        foreach ($results as $row) {
            $embedding = json_decode($row->content_embedding, true);
            
            if (empty($embedding)) {
                // No embedding stored, try to generate from content
                $embedding = $this->generate_embedding($row->content_text);
                if (empty($embedding)) {
                    continue;
                }
            }
            
            $similarity = $this->cosine_similarity($query_embedding, $embedding);
            
            if ($similarity > 0.1) { // Minimum similarity threshold
                $sections = json_decode($row->sections, true);
                if (is_array($sections)) {
                    foreach ($sections as $section) {
                        $scored_results[] = [
                            'id' => $section['id'],
                            'text' => $section['text'],
                            'tag' => $section['tag'],
                            'context' => $section['context'] ?? '',
                            'post_id' => $row->post_id,
                            'post_type' => $row->post_type,
                            'score' => $similarity,
                            'source' => 'semantic',
                        ];
                    }
                }
            }
        }
        
        // Sort by score (descending)
        usort($scored_results, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        return array_slice($scored_results, 0, $limit);
    }
    
    /**
     * Update embeddings for all indexed content
     * This can be run as a batch process
     * 
     * @param int $batch_size Number of posts to process at once
     * @param int $offset Offset for pagination
     * @return int Number of posts updated
     */
    public function update_all_embeddings($batch_size = 50, $offset = 0) {
        $posts = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT id, post_id, post_type, content_text FROM {$this->table_name} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
                $batch_size,
                $offset
            )
        );
        
        if (empty($posts)) {
            return 0;
        }
        
        $updated = 0;
        foreach ($posts as $post) {
            $embedding = $this->generate_embedding($post->content_text);
            
            if (!empty($embedding)) {
                $this->wpdb->update(
                    $this->table_name,
                    ['content_embedding' => json_encode($embedding)],
                    ['id' => $post->id]
                );
                $updated++;
            }
        }
        
        return $updated;
    }
    
    /**
     * Get embedding for a specific post
     * 
     * @param int $post_id Post ID
     * @return array|null Embedding or null
     */
    public function get_post_embedding($post_id) {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT content_embedding FROM {$this->table_name} WHERE post_id = %d LIMIT 1",
                $post_id
            )
        );
        
        if ($row && !empty($row->content_embedding)) {
            return json_decode($row->content_embedding, true);
        }
        
        return null;
    }
    
    /**
     * Store embedding for a post
     * 
     * @param int $post_id Post ID
     * @param array $embedding Embedding vector
     */
    public function store_post_embedding($post_id, $embedding) {
        $this->wpdb->update(
            $this->table_name,
            ['content_embedding' => json_encode($embedding)],
            ['post_id' => $post_id]
        );
    }
    
    /**
     * Compress embedding vector for storage
     * 
     * @param array $embedding Embedding vector
     * @return array Compressed embedding
     */
    public function compress_embedding($embedding) {
        if (!is_array($embedding)) {
            return $embedding;
        }
        
        // Convert to float16 precision (2 decimal places)
        $compressed = [];
        foreach ($embedding as $key => $value) {
            $compressed[$key] = round(floatval($value), 2);
        }
        
        return $compressed;
    }
}

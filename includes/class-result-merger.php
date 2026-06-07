<?php
/**
 * WP LLM Search Bar - Result Merger Class
 * Handles merging and re-ranking of search results from different sources
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_LLM_Search_Result_Merger {
    
    /**
     * Default weights for different result sources
     * @var array
     */
    private $default_weights = [
        'ai' => 0.6,
        'semantic' => 0.6,
        'keyword' => 0.4,
    ];
    
    /**
     * Custom weights (can be set via constructor)
     * @var array
     */
    private $weights;
    
    /**
     * Constructor
     * 
     * @param array $weights Custom weights for result sources
     */
    public function __construct($weights = []) {
        $this->weights = array_merge($this->default_weights, $weights);
    }
    
    /**
     * Merge results from multiple sources
     * 
     * @param array $results_array Array of result arrays from different sources
     * @return array Merged and re-ranked results
     */
    public function merge(array $results_array) {
        $all_results = [];
        
        foreach ($results_array as $source => $results) {
            if (!is_array($results)) {
                continue;
            }
            
            foreach ($results as $result) {
                // Ensure result has required fields
                $result = $this->normalize_result($result, $source);
                $all_results[] = $result;
            }
        }
        
        if (empty($all_results)) {
            return [];
        }
        
        // Deduplicate results
        $deduplicated = $this->deduplicate($all_results);
        
        // Re-rank based on combined scores
        $ranked = $this->rank_results($deduplicated);
        
        return $ranked;
    }
    
    /**
     * Normalize a result to ensure it has all required fields
     * 
     * @param array $result Result to normalize
     * @param string $source Source of the result
     * @return array Normalized result
     */
    private function normalize_result($result, $source) {
        $normalized = [
            'id' => $result['id'] ?? md5($result['text'] ?? uniqid()),
            'text' => $result['text'] ?? '',
            'tag' => $result['tag'] ?? 'div',
            'context' => $result['context'] ?? '',
            'post_id' => $result['post_id'] ?? 0,
            'post_type' => $result['post_type'] ?? 'post',
            'score' => $result['score'] ?? 1.0,
            'source' => $source,
        ];
        
        return $normalized;
    }
    
    /**
     * Deduplicate results based on ID
     * Keeps the highest-scoring version of each result
     * 
     * @param array $results Results to deduplicate
     * @return array Deduplicated results
     */
    private function deduplicate($results) {
        $deduplicated = [];
        $seen_ids = [];
        
        foreach ($results as $result) {
            $id = $result['id'];
            
            if (!isset($seen_ids[$id])) {
                $seen_ids[$id] = true;
                $deduplicated[] = $result;
            } else {
                // Find existing result with same ID and keep the higher score
                foreach ($deduplicated as &$existing) {
                    if ($existing['id'] === $id) {
                        // Apply weight based on source
                        $existing_score = $existing['score'] * ($this->weights[$existing['source']] ?? 0.5);
                        $new_score = $result['score'] * ($this->weights[$result['source']] ?? 0.5);
                        
                        if ($new_score > $existing_score) {
                            $existing = $result;
                        }
                        break;
                    }
                }
            }
        }
        
        return $deduplicated;
    }
    
    /**
     * Rank results based on weighted scores
     * 
     * @param array $results Results to rank
     * @return array Ranked results (descending by score)
     */
    private function rank_results($results) {
        // Calculate weighted scores
        foreach ($results as &$result) {
            $source = $result['source'];
            $weight = $this->weights[$source] ?? 0.5;
            $result['weighted_score'] = $result['score'] * $weight;
        }
        
        // Sort by weighted score (descending)
        usort($results, function($a, $b) {
            return $b['weighted_score'] <=> $a['weighted_score'];
        });
        
        // Remove the temporary weighted_score field
        foreach ($results as &$result) {
            unset($result['weighted_score']);
        }
        
        return $results;
    }
    
    /**
     * Merge two result sets (simpler interface)
     * 
     * @param array $results1 First set of results
     * @param array $results2 Second set of results
     * @param string $source1 Source name for first set
     * @param string $source2 Source name for second set
     * @return array Merged results
     */
    public function merge_two($results1, $results2, $source1 = 'keyword', $source2 = 'ai') {
        return $this->merge([
            $source1 => $results1,
            $source2 => $results2,
        ]);
    }
    
    /**
     * Set custom weights for result sources
     * 
     * @param array $weights Associative array of source => weight
     */
    public function set_weights($weights) {
        $this->weights = array_merge($this->weights, $weights);
    }
    
    /**
     * Get current weights
     * 
     * @return array Current weights
     */
    public function get_weights() {
        return $this->weights;
    }
    
    /**
     * Normalize scores to 0-1 range
     * 
     * @param array $results Results to normalize
     * @return array Results with normalized scores
     */
    public function normalize_scores($results) {
        if (empty($results)) {
            return $results;
        }
        
        // Find min and max scores
        $scores = array_column($results, 'score');
        $min = min($scores);
        $max = max($scores);
        
        // Avoid division by zero
        if ($max === $min) {
            foreach ($results as &$result) {
                $result['score'] = 1.0;
            }
            return $results;
        }
        
        // Normalize each score
        foreach ($results as &$result) {
            $result['score'] = ($result['score'] - $min) / ($max - $min);
        }
        
        return $results;
    }
    
    /**
     * Limit results to top N
     * 
     * @param array $results Results to limit
     * @param int $limit Maximum number of results
     * @return array Limited results
     */
    public function limit($results, $limit) {
        return array_slice($results, 0, $limit);
    }
    
    /**
     * Apply relevance thresholds
     * 
     * @param array $results Results to filter
     * @param float $min_score Minimum score threshold (0-1)
     * @return array Filtered results
     */
    public function apply_threshold($results, $min_score = 0.1) {
        return array_filter($results, function($result) use ($min_score) {
            return ($result['score'] ?? 0) >= $min_score;
        });
    }
}

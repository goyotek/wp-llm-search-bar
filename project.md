# WP LLM Search Bar - Project Roadmap

## Overview
This document outlines the enhancement roadmap for the WP LLM Search Bar plugin, focusing on adding content indexing and hybrid search capabilities to reduce dependency on structural data (IDs, anchors, headings) and improve performance, scalability, and accuracy.

---

## Current Limitations
The plugin currently relies on:
- Structural HTML elements (IDs, named anchors, headings)
- Live content parsing on every search
- AI calls for every query (expensive and slow)
- Single-page scope only

---

## Phase 1: Basic Indexing (2-3 hours)

### Objective
Add a database indexing layer to store and search content efficiently, providing a fast keyword search fallback.

### Tasks

#### 1.1 Create Database Table
- **File**: `includes/class-indexer.php`
- **Action**: Create `wp_llm_search_index` table on plugin activation
- **Schema**:
  ```sql
  CREATE TABLE wp_llm_search_index (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      post_id BIGINT UNSIGNED NOT NULL,
      content_hash VARCHAR(64) NOT NULL,
      content_text LONGTEXT NOT NULL,
      sections JSON NOT NULL,
      tokens INT UNSIGNED NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX (post_id),
      INDEX (content_hash),
      FULLTEXT INDEX (content_text)
  );
  ```

#### 1.2 Index Content on Save
- **Hook**: `save_post` (priority: 10, 2 args)
- **Action**:
  - Extract and clean post content (strip HTML tags, normalize whitespace)
  - Generate MD5 hash of content for cache invalidation
  - Extract sections (reuse existing `extract_sections_with_context()` logic)
  - Count tokens for cost tracking
  - Store in index table (replace existing entry if hash matches)

#### 1.3 Add Keyword Search Fallback
- **File**: Modify `wp-llm-search-bar.php`
- **Action**:
  - Add new endpoint parameter: `mode` (keyword|semantic|hybrid)
  - Implement `keyword_search()` function using MySQL `FULLTEXT` search
  - Update REST endpoint to check keyword search first
  - Fall back to AI search if keyword results are empty or insufficient

#### 1.4 Update REST Endpoint
- **Endpoint**: `POST /wp-json/wp-llm-search-bar/v1/search`
- **New Parameters**:
  - `mode`: Search mode (keyword|semantic|hybrid)
  - `scope`: Search scope (page|site)
- **Logic**:
  1. If `mode=keyword` or `mode=hybrid`, try keyword search first
  2. If results are empty or `< 3`, fall back to AI
  3. Cache results for 5 minutes

---

## Phase 2: Hybrid Search (4-5 hours)

### Objective
Add semantic search capabilities using AI embeddings, implement result merging, and add caching layers for optimal performance.

### Tasks

#### 2.1 Add Embedding Storage
- **Schema Update**: Add `content_embedding LONGTEXT` column to `wp_llm_search_index`
- **Action**:
  - Generate embeddings for each indexed chunk using `wp_ai_client_prompt()`
  - Store embeddings as compressed JSON arrays
  - Batch process existing content

#### 2.2 Implement Semantic Search
- **File**: `includes/class-semantic-search.php`
- **Action**:
  - Add `generate_embedding()` method for queries
  - Add `cosine_similarity()` function to compare embeddings
  - Implement `semantic_search()` to find similar content chunks
  - Support site-wide or page-specific searches

#### 2.3 Result Merging Algorithm
- **File**: `includes/class-result-merger.php`
- **Action**:
  - Normalize scores from keyword and semantic searches
  - Implement weighted scoring (e.g., 60% semantic, 40% keyword)
  - Deduplicate results
  - Sort by combined relevance score
  - Limit to top N results (configurable)

#### 2.4 Add Caching Layers
- **Transient Cache**: Individual search queries (TTL: 5-15 minutes)
- **Object Cache**: Embedding vectors (TTL: 1 hour)
- **Index Cache**: Pre-computed results for common queries (TTL: 24 hours)
- **Implementation**: Use WordPress transients and object cache

#### 2.5 Enhanced REST Endpoint
- **Endpoint**: Update `POST /wp-json/wp-llm-search-bar/v1/search`
- **Logic**:
  1. Check cache for query
  2. If cached, return immediately
  3. If `mode=hybrid`:
     - Run keyword search
     - Run semantic search
     - Merge and re-rank results
  4. Cache results
  5. Return top N

#### 2.6 Add Search Modes to Frontend
- **File**: Update `frontend.js` and `editor.js`
- **Action**:
  - Add search mode selector (Instant/Smart/Deep)
  - Update debounce times per mode:
    - Instant: 300ms, keyword only
    - Smart: 500ms, hybrid
    - Deep: 1000ms, full semantic
  - Add visual indicators for search mode

---

## Implementation Notes

### File Structure
```
goyotek__wp-llm-search-bar/
├── wp-llm-search-bar.php          # Main plugin (updated)
├── editor.js                      # Gutenberg editor (updated)
├── frontend.js                    # Frontend logic (updated)
├── project.md                     # This file
├── includes/
│   ├── class-indexer.php          # Phase 1: Database indexing
│   ├── class-semantic-search.php  # Phase 2: Semantic search
│   └── class-result-merger.php     # Phase 2: Result merging
└── README.md
```

### Backward Compatibility
- All existing functionality must remain intact
- New features are opt-in via parameters
- Default behavior matches current implementation

### Performance Considerations
- Indexing happens asynchronously (via `wp_schedule_single_event`)
- Batch processing for existing content (50 posts at a time)
- Token counting to prevent AI cost overruns
- Compression for embedding storage

### Testing Requirements
- Test with various post types (pages, posts, custom)
- Test with multilingual content
- Test with large content (>10,000 tokens)
- Test cache invalidation on content updates

---

## Success Metrics

| Metric | Current | Phase 1 Target | Phase 2 Target |
|--------|---------|----------------|----------------|
| Search Speed | 2-5s | <500ms | <100ms |
| AI Calls per Search | 1 | 0-1 | 0-1 |
| Search Scope | Single page | Single page | Site-wide |
| Accuracy | Good | Good | Excellent |
| Cost | High | Medium | Low |

---

## Timeline

### Phase 1: Basic Indexing (2-3 hours)
- Week 1: Database table + indexing logic
- Week 1: Keyword search implementation
- Week 1: Testing and validation

### Phase 2: Hybrid Search (4-5 hours)
- Week 2: Embedding generation and storage
- Week 2: Semantic search implementation
- Week 2: Result merging algorithm
- Week 2: Caching layers
- Week 2: Frontend updates

---

## Next Steps
1. Review and approve this roadmap
2. Implement Phase 1 as proof of concept
3. Test Phase 1 thoroughly
4. Proceed to Phase 2 based on results

---

*Last updated: 2025-06-07*

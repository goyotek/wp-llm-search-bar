// ============================================
// DATEI: js/frontend.js
// Frontend-Logik für das Suchfeld
// ============================================

// Search mode configurations
const LLMSearchModes = {
    instant: {
        name: 'Schnell',
        description: 'Sofortige Suche (nur Schlüsselwörter)',
        minChars: 2,
        debounce: 300,
        mode: 'keyword'
    },
    smart: {
        name: 'Intelligent',
        description: 'Kombinierte Suche (Schlüsselwörter + KI)',
        minChars: 3,
        debounce: 500,
        mode: 'hybrid'
    },
    deep: {
        name: 'Tiefgehend',
        description: 'Vollständige KI-Suche',
        minChars: 4,
        debounce: 1000,
        mode: 'semantic'
    }
};

// Current search mode (default: smart)
let currentSearchMode = 'smart';

document.addEventListener('DOMContentLoaded', function() {
    const searchWrappers = document.querySelectorAll('.wp-llm-search-bar');
    
    if (searchWrappers.length === 0) return;

    searchWrappers.forEach(function(wrapper) {
        const input = wrapper.querySelector('.llm-search-input');
        const results = wrapper.querySelector('.llm-search-results');
        const pageId = wrapper.dataset.pageId || 0;

        if (!input || !results) return;

        // Add search mode selector
        addSearchModeSelector(wrapper, input);

        let timeout;
        input.addEventListener('input', function() {
            clearTimeout(timeout);
            
            // Get current mode settings
            const modeConfig = LLMSearchModes[currentSearchMode];
            
            // Only search if query meets minimum length
            const query = input.value.trim();
            if (query.length < modeConfig.minChars) {
                if (query.length > 0) {
                    results.innerHTML = `<p class="llm-search-hint">Geben Sie mindestens ${modeConfig.minChars} Zeichen ein...</p>`;
                }
                return;
            }
            
            timeout = setTimeout(function() {
                performSearch(input, results, pageId);
            }, modeConfig.debounce);
        });
    });

    /**
     * Add search mode selector to wrapper
     * 
     * @param {HTMLElement} wrapper Wrapper element
     * @param {HTMLElement} input Input element
     */
    function addSearchModeSelector(wrapper, input) {
        // Create mode selector container
        const modeContainer = document.createElement('div');
        modeContainer.className = 'llm-search-mode-selector';
        modeContainer.style.cssText = 'margin-top: 10px; font-size: 0.85em; color: #666;';
        
        // Create mode buttons
        const modes = Object.keys(LLMSearchModes);
        modes.forEach(function(modeKey) {
            const mode = LLMSearchModes[modeKey];
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'llm-search-mode-btn';
            button.textContent = mode.name;
            button.title = mode.description;
            button.dataset.mode = modeKey;
            button.style.cssText = 'background: none; border: 1px solid #ddd; padding: 4px 8px; margin-right: 5px; cursor: pointer; border-radius: 3px;';
            
            if (modeKey === currentSearchMode) {
                button.style.backgroundColor = '#0073aa';
                button.style.color = 'white';
                button.style.borderColor = '#0073aa';
            }
            
            button.addEventListener('click', function() {
                currentSearchMode = modeKey;
                
                // Update button styles
                const allButtons = modeContainer.querySelectorAll('.llm-search-mode-btn');
                allButtons.forEach(function(btn) {
                    btn.style.backgroundColor = '';
                    btn.style.color = '';
                    btn.style.borderColor = '#ddd';
                });
                
                button.style.backgroundColor = '#0073aa';
                button.style.color = 'white';
                button.style.borderColor = '#0073aa';
                
                // Trigger search if there's a query
                if (input.value.trim().length >= mode.minChars) {
                    performSearch(input, wrapper.querySelector('.llm-search-results'), wrapper.dataset.pageId || 0);
                }
            });
            
            modeContainer.appendChild(button);
        });
        
        // Insert mode selector after input
        if (input.parentNode) {
            input.parentNode.insertBefore(modeContainer, input.nextSibling);
        }
    }

    /**
     * Perform search with current mode
     * 
     * @param {HTMLElement} input Input element
     * @param {HTMLElement} results Results container
     * @param {number} pageId Page ID
     */
    function performSearch(input, results, pageId) {
        const query = input.value.trim();
        const modeConfig = LLMSearchModes[currentSearchMode];
        
        if (query.length < modeConfig.minChars) {
            results.innerHTML = `<p class="llm-search-hint">Geben Sie mindestens ${modeConfig.minChars} Zeichen ein...</p>`;
            return;
        }

        results.innerHTML = '<p class="llm-search-loading">🔍 Suche...</p>';

        // Get current mode
        const mode = modeConfig.mode;

        // Build request data
        const requestData = {
            query: query,
            page_id: pageId,
            mode: mode
        };

        // Add scope if needed
        if (currentSearchMode === 'deep') {
            requestData.scope = 'site';
        }

        // Perform AJAX request
        fetch('/wp-json/wp-llm-search-bar/v1/search', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpLlmSearchBar.nonce
            },
            body: JSON.stringify(requestData)
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (data.error) {
                results.innerHTML = `<p class="llm-search-error">❌ Fehler: ${data.error}</p>`;
                return;
            }

            if (data.results && data.results.length > 0) {
                displayResults(results, data.results, query);
            } else {
                results.innerHTML = '<p class="llm-search-hint">Keine passenden Abschnitte gefunden.</p>';
            }
        })
        .catch(function(error) {
            results.innerHTML = `<p class="llm-search-error">❌ Netzwerkfehler: ${error.message}</p>`;
        });
    }

    /**
     * Display search results
     * 
     * @param {HTMLElement} container Results container
     * @param {Array} results Search results
     * @param {string} query Original query
     */
    function displayResults(container, results, query) {
        let html = '';
        
        results.forEach(function(result) {
            // Highlight matching terms in result text
            const highlightedText = highlightMatch(result.text || result.id, query);
            
            html += '<div class="llm-search-result-item">';
            html += '<a href="#' + result.id + '" ';
            html += 'onclick="event.preventDefault(); document.getElementById(\'' + result.id + '\').scrollIntoView({behavior: \'smooth\'});" ';
            html += 'class="llm-search-result-link">';
            html += highlightedText;
            html += '</a>';
            
            // Show source indicator for hybrid mode
            if (result.source) {
                const sourceLabel = result.source === 'ai' ? 'KI' : 
                                   result.source === 'semantic' ? 'Semantisch' : 
                                   result.source === 'keyword' ? 'Schlüsselwort' : '';
                if (sourceLabel) {
                    html += '<span class="llm-search-result-tag">(' + sourceLabel + ')</span>';
                }
            } else {
                html += '<span class="llm-search-result-tag">(' + (result.tag || 'section') + ')</span>';
            }
            
            html += '</div>';
        });
        
        container.innerHTML = html;
    }

    /**
     * Highlight matching terms in text
     * 
     * @param {string} text Text to highlight
     * @param {string} query Query to match
     * @return {string} Text with highlights
     */
    function highlightMatch(text, query) {
        if (!query) return text;
        
        const queryWords = query.toLowerCase().split(/\s+/);
        let highlighted = text;
        
        queryWords.forEach(function(word) {
            if (word.length > 2) {
                const regex = new RegExp('(' + escapeRegExp(word) + ')', 'gi');
                highlighted = highlighted.replace(regex, '<mark>$1</mark>');
            }
        });
        
        return highlighted;
    }

    /**
     * Escape special regex characters
     * 
     * @param {string} string String to escape
     * @return {string} Escaped string
     */
    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
});

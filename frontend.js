// ============================================
// DATEI: js/frontend.js
// Frontend-Logik für das Suchfeld
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    const searchWrappers = document.querySelectorAll('.wp-llm-search-bar');
    
    if (searchWrappers.length === 0) return;

    searchWrappers.forEach(function(wrapper) {
        const input = wrapper.querySelector('.llm-search-input');
        const results = wrapper.querySelector('.llm-search-results');
        const pageId = wrapper.dataset.pageId || 0;

        if (!input || !results) return;

        let timeout;
        input.addEventListener('input', function() {
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                performSearch(input, results, pageId);
            }, 500);
        });

        function performSearch(input, results, pageId) {
            const query = input.value.trim();
            
            if (query.length < 3) {
                results.innerHTML = '<p style="color: #666; font-style: italic;">Geben Sie mindestens 3 Zeichen ein...</p>';
                return;
            }

            results.innerHTML = '<p style="color: #0073aa;">🔍 Suche...</p>';

            // Demo-Modus: Suche nach Elementen mit IDs auf der Seite
            const allElements = document.querySelectorAll('[id]');
            const matchingElements = [];

            allElements.forEach(function(el) {
                if (el.id.toLowerCase().includes(query.toLowerCase())) {
                    matchingElements.push({
                        id: el.id,
                        text: el.textContent ? el.textContent.substring(0, 50) : el.id
                    });
                }
            });

            if (matchingElements.length > 0) {
                let html = '';
                matchingElements.forEach(function(el) {
                    html += '<div style="margin: 8px 0; padding: 8px; border-bottom: 1px solid #eee;">';
                    html += '<a href="#' + el.id + '" onclick="event.preventDefault(); document.getElementById(\'' + el.id + '\').scrollIntoView({behavior: \'smooth\'});" style="color: #0073aa; text-decoration: none; font-weight: bold;">';
                    html += el.text || el.id;
                    html += '</a>';
                    html += '<span style="color: #666; font-size: 0.8em; margin-left: 10px;">(#' + el.id + ')</span>';
                    html += '</div>';
                });
                results.innerHTML = html;
            } else {
                results.innerHTML = '<p style="color: #666;">Keine passenden Abschnitte gefunden.</p>';
            }
        }
    });
});
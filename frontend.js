// ============================================
// DATEI: frontend.js
// Frontend-Logik: ruft den REST-Endpunkt auf und rendert
// die KI-Antwort, die Quellenliste und den optionalen CTA.
// ============================================

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var wrappers = document.querySelectorAll('.wp-llm-search-bar');
        Array.prototype.forEach.call(wrappers, function (wrapper) {
            init(wrapper);
        });
    });

    function init(wrapper) {
        var form = wrapper.querySelector('.llm-search-form');
        var input = wrapper.querySelector('.llm-search-input');
        var results = wrapper.querySelector('.llm-search-results');
        if (!form || !input || !results) return;

        var inFlight = null;
        var timeout = null;

        input.addEventListener('input', function () {
            clearTimeout(timeout);
            timeout = setTimeout(function () {
                search(input, results);
            }, 600);
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            clearTimeout(timeout);
            search(input, results);
        });

        function search(input, results) {
            var query = input.value.trim();
            if (query.length < 3) {
                results.innerHTML = '<p class="llm-search-hint">Bitte mindestens 3 Zeichen eingeben …</p>';
                return;
            }
            if (inFlight && inFlight.abort) {
                inFlight.abort();
            }

            results.innerHTML = '<p class="llm-search-loading">🔍 Suche mit KI …</p>';

            var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            var body = JSON.stringify({
                query: query,
                page_id: parseInt(wrapper.dataset.pageId, 10) || 0,
                maxSources: parseInt(wrapper.dataset.maxSources, 10) || 3,
                mode: wrapper.dataset.mode || 'answer',
                ctaText: wrapper.dataset.ctaText || '',
                ctaEmail: wrapper.dataset.ctaEmail || '',
                ctaSubject: wrapper.dataset.ctaSubject || ''
            });

            fetch('/wp-json/wp-llm-search-bar/v1/search', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: body,
                signal: controller ? controller.signal : undefined
            })
                .then(function (response) {
                    if (!response.ok) {
                        return response.json().then(function (data) {
                            throw new Error((data && data.message) || ('HTTP ' + response.status));
                        });
                    }
                    return response.json();
                })
                .then(function (data) {
                    render(results, data);
                })
                .catch(function (error) {
                    if (error && error.name === 'AbortError') return;
                    results.innerHTML = '<p class="llm-search-error">❌ Fehler: ' + escapeHtml(error.message) + '</p>';
                });

            inFlight = controller;
        }

        function render(results, data) {
            if (data.error) {
                results.innerHTML = '<p class="llm-search-error">❌ ' + escapeHtml(data.error) + '</p>';
                return;
            }

            var hasAnswer = data.answer && data.answer.trim().length > 0;
            var hasSources = data.sources && data.sources.length > 0;

            if (!hasAnswer && !hasSources) {
                results.innerHTML = '<p class="llm-search-hint">' + escapeHtml(data.message || 'Keine passenden Inhalte gefunden.') + '</p>';
                return;
            }

            var html = '';

            if (hasAnswer) {
                html += '<div class="llm-search-answer">' + renderAnswer(data.answer) + '</div>';
            }

            if (hasSources) {
                html += '<div class="llm-search-sources">';
                html += '<p class="llm-search-sources-title">Quellen</p>';
                data.sources.forEach(function (source) {
                    html += '<div class="llm-search-source">';
                    html += '<a class="llm-search-source-link" href="' + escapeHtml(source.url) + '">' + escapeHtml(source.title) + '</a>';
                    html += '<span class="llm-search-source-url">' + escapeHtml(source.url) + '</span>';
                    html += '</div>';
                });
                html += '</div>';
            }

            if (data.cta && data.cta.email) {
                html += '<div class="llm-search-cta">';
                if (data.cta.text) {
                    html += '<p>' + escapeHtml(data.cta.text) + '</p>';
                }
                var subject = data.cta.subject || '';
                var href = 'mailto:' + data.cta.email + (subject ? '?subject=' + encodeURIComponent(subject) : '');
                html += '<a class="llm-search-cta-link" href="' + escapeHtml(href) + '">Jetzt Kontakt aufnehmen</a>';
                html += '</div>';
            }

            results.innerHTML = html;
        }

        function renderAnswer(answer) {
            var paragraphs = answer.split(/\n{2,}/).filter(function (part) {
                return part.trim().length > 0;
            });
            return paragraphs.map(function (part) {
                return '<p>' + escapeHtml(part.trim()) + '</p>';
            }).join('');
        }

        function escapeHtml(value) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(value || ''));
            return div.innerHTML;
        }
    }
})();

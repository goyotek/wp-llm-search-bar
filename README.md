# WP LLM Search Bar

Ein **Gutenberg-Block** für WordPress 6.5+/7.0, der ein Suchfeld bereitstellt und mit Hilfe der **WordPress-KI-Konnektoren** (z. B. Mistral) eine kurze Antwort im Stil einer **KI-Übersicht** (à la Google AI Overview) erzeugt – inklusive einer **Quellenliste, die ausschließlich interne Links** enthält (z. B. `https://ziel17.com/angebot/#Digitalisierung`).

## Beispiel

Anfrage: *„Worauf muss ich achten wenn ich mein Reservierungssystem im Hotel wechseln will?"*

Ergebnis: 1–2 Absätze Zusammenfassung, darunter „Quellen" mit Links auf die passenden Abschnitte der eigenen Seite (mit Anker-Fragmenten) und passende Blogartikel – optional mit kurzem Pitch und mailto-Button für Kontakt.

## Architektur

- **Retriever** (`includes/class-wp-llm-search-bar-retriever.php`): Standard `WP_Query`-Suche über die ausgewählten Inhaltstypen; Abschnitte (Chunks) mit Anchor-IDs werden pro Beitrag extrahiert und über den WordPress-Object-Cache zwischengespeichert. **Keine eigenen DB-Tabellen** – nur Standard-WordPress-Strukturen (`WP_Query`, `wp_options`, Transients, Object-Cache).
- **Generator** (`includes/class-wp-llm-search-bar-generator.php`): Retrieve→Generate-Pipeline. Das LLM erhält nur die vorgerankten Kandidaten-Abschnitte; die Antwort wird auf die übergebene URL-Whitelist gefiltert, sodass **nur interne Links** ausgegeben werden können.
- **REST-Endpunkt** (`includes/class-wp-llm-search-bar-rest.php`): `POST /wp-json/wp-llm-search-bar/v1/search` mit Validierung, Rate-Limiting pro IP (Transients) und Antwort-Caching (Transients).
- **Block** (`includes/class-wp-llm-search-bar-block.php`): Server-Side-Rendering mit Attributen für Modus (Antwort vs. Nur-Links), Platzhalter, maximale Quellenanzahl und CTA (Pitch-Text, E-Mail, Betreff für mailto).
- **Einstellungen** (`includes/class-wp-llm-search-bar-settings.php`): Admin-Seite unter *Einstellungen → WP LLM Search Bar*. Auswahl von:
  - **Suchbereich**: gesamte Website oder nur die aktuelle Seite
  - **Inhaltstypen**: Seiten, Beiträge, Custom Post Types
  - **Taxonomie** (z. B. Kategorien): passende Beiträge werden bevorzugt, das Archiv wird als Quelle verlinkt
  - **Archive**: durchsuchbare Archivseiten pro Post Type
  - **Schutz**: Rate-Limit (Anfragen pro Zeitraum) und Cache-Dauer

## Installation

1. Ordner `wp-llm-search-bar` in `/wp-content/plugins/` erstellen bzw. das Repository dorthin klonen.
2. Plugin im WordPress-Dashboard aktivieren.
3. Sicherstellen, dass ein KI-Konnektor (WordPress 7.0, z. B. Mistral) konfiguriert ist.
4. Block „WP LLM Search Bar" auf einer Seite platzieren; Suchbereich unter *Einstellungen → WP LLM Search Bar* konfigurieren.

## CTA / Pitch

Der Block unterstützt einen Call-to-Action: Pitch-Text, E-Mail-Adresse und Betreff werden als Block-Attribute im Gutenberg-Editor gesetzt; im Frontend erscheint nach der Antwort ein mailto-Button. Alternativ kann der CTA serverseitig über den Filter `wp_llm_search_bar_cta` gesetzt werden.

## Caching

- Chunk-Cache: `wp_cache_*` (Object-Cache; wird beim Speichern eines Beitrags invalidiert).
- Antwort-Cache: Transients, TTL konfigurierbar (Standard 900 s, 0 = aus).
- Rate-Limit: Transients pro IP, Standard 15 Anfragen / 60 s.

## Hinweise

- Alle ausgegebenen Quellen-URLs stammen aus der internen Kandidatenliste; externe Links sind konstruktionsbedingt ausgeschlossen.
- Frontend-Skripte (`frontend.js`) und Styles (`css/frontend.css`) werden über `wp_enqueue_*` geladen – keine Inline-Scripts, cachebar und nonce-unabhängig.

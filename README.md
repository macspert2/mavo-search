# Mavo Search

The site search: a compact, field-aware, multilingual index that understands
Maman Voyage — titles and text, the place tree of mavo-geotag-plus, hubs from
mavo-hubs, image alt text in every language and image concepts from
mavo-image-index — serving the ordinary `?s=` search. Replaces Relevanssi.

Visitors see the same search page as before: same URLs, same template, same
pagination. Results are ranked by this index, each with a contextual excerpt
with the query's words marked `<mark class="mavo-search-highlight">`.

## Operating it

- **Activate.** The index builds itself in the background (WP-Cron). Until it
  is complete, WordPress — or Relevanssi, if active — keeps answering searches;
  then Mavo Search takes over on its own. Tools → Mavo Search → **Rebuild all**
  does the same, faster.
- **From then on** saving a post keeps it current, as do hub, image and
  landing-page changes. Nothing ever rebuilds the whole index on a save.
- **Rebuild stale** after deploying a change to stopwords, taxonomies or
  custom fields indexed; **Rebuild all** after renaming places in geotag-plus.
- **Relevanssi** can stay active while you check: Mavo Search tells it to
  stand aside for every search it answers. Deactivate it when satisfied.
  Rollback: reactivate Relevanssi, deactivate Mavo Search.
- Tools → Mavo Search → **Test a search** shows any query's ranking with
  every point explained, and what visitors searched for (no results, few
  results, partial matches only) and clicked.
- **Best bets** (same screen): "query = post ID, post ID" pins posts first
  for that exact query, in their own language. Work from the no-result and
  partial-match reports.
- **Never suggest** (same screen): words or phrases kept out of the
  suggestions under the search box.

```bash
wp mavo-search status
wp mavo-search rebuild --all | --stale | --post=123   [--batch-size=250 --lang=fr --post-type=post]
wp mavo-search search "où dormir à Londres" [--lang=fr] [--explain] [--excerpts]
wp mavo-search explain "londres" --post=123
wp mavo-search terms --post=123 [--field=place]
wp mavo-search logs [--zero | --low | --fallback] [--days=30] [--lang=de]
wp mavo-search clicks [--unclicked] [--days=30] [--lang=fr]
```

## What is searched

Published posts and pages, not noindexed in Yoast, one document each in its
own Polylang language. A search only ever returns documents of the visitor's
language.

| Field | From | Weight |
|---|---|---|
| title | the title | 10 |
| guide | places this page is the landing page for (theme) | 12 |
| place | the post's place and the places above it (geotag-plus) | 7 × strength |
| heading | `<h1>`–`<h6>` | 5 |
| hub | titles of its hubs and the hubs above them (mavo-hubs) | 5 × strength |
| content | visible text, shortcodes and markup removed | 3 |
| excerpt | the hand-written excerpt | 3 |
| alt | its images' alt text in its language (mavo-image-index) | 3 |
| concept | its images' concepts (mavo-image-index) | 2.5 × confidence |
| taxonomy | tag names that are not places | 2 |
| custom | values of `mavo_search_custom_fields` (none by default) | 2 |

Bonuses: the query *is* the title +20, a phrase in the title +15, every word
in the title +6, a phrase in the text +4. Repetition has diminishing returns
(20 mentions ≈ 2.8 × one), rare words count more than common ones, and hub
pages get × 1.15. Every number is filterable (`mavo_search_field_weights`)
and explained in the test search.

Matching ignores accents (`edimbourg` finds Édimbourg), knows German's two
spellings (`zuerich` finds Zürich), plurals and longer forms (`plage` finds
Plages), curated synonyms (`leucade` finds Lefkada) and, through
mavo-image-index, concepts (`eaux turquoise` finds photos of turquoise water).
`"Quoted phrases"` are required verbatim. When no document has every word,
documents with most of them are shown and the result says `fallback: or`.

## For templates and plugins

The loop works as always. Inside a search answered by Mavo Search:

```php
if ( function_exists( 'mavo_search_result' ) ) {
    $hit = mavo_search_result();          // score, matched_fields, matched_image_concepts, places, hubs …
    $all = mavo_search_current();         // total, pages, fallback ('or' = no exact results), missing_words
    $img = mavo_search_result_image();    // the post's photo matching the query's concepts, else featured
}

mavo_search_the_title();                  // title with the query's words marked
```

| Function | Returns |
|---|---|
| `mavo_search( $query, $args )` | Ranked results with excerpts; `$args`: lang, page, per_page, post_types, excerpts, fallback, explain |
| `mavo_search_parse_query( $query, $lang )` | Word groups and variants, quoted phrases, image `concepts` — e.g. to link `mavo_image_results_url( $concept )` |
| `mavo_search_image_concepts( $query, $lang )` | The one concept the query is *entirely* about ("bunte Häuser" → `colourful_houses`, not `house`; "plage lefkada" → none), for a photo row |
| `mavo_search_suggestions( $lang, $limit )` | Queries worth suggesting: searched often lately (and this time last year), with plenty of exact results, minus "never suggest" and the current search |
| `mavo_search_url( $query, $lang )` | The search URL in a language (`/en/?s=…`) |
| `mavo_search_result_attributes( $post )` | `data-mavo-search-*` attributes for a result tile, so its clicks are counted |
| `mavo_search_result( $post )` / `mavo_search_current()` | The current search's result for a post / as a whole |
| `mavo_search_result_image( $post, $args )` | Attachment ID: best photo for the query's concepts, else the featured image |
| `mavo_search_get_excerpt( $post_id, $query, $args )` | Contextual highlighted excerpt (escaped HTML) |
| `mavo_search_highlight_terms( $html, $query, $args )` | `$html` with the query's words marked; tags, attributes, scripts untouched |
| `mavo_search_get_highlighted_title( $post )` / `mavo_search_the_title( $post )` | The title, marked |
| `mavo_search_reindex_post( $id )` / `mavo_search_delete_post( $id )` / `mavo_search_mark_post_stale( $id )` | Maintenance |

Filters: `mavo_search_search_ok`, `mavo_search_query`, `mavo_search_parsed_query`,
`mavo_search_field_weights`, `mavo_search_post_types`, `mavo_search_taxonomies`,
`mavo_search_custom_fields`, `mavo_search_index_post`, `mavo_search_document_sources`,
`mavo_search_document_boost`, `mavo_search_places`, `mavo_search_hubs`,
`mavo_search_guide_places`, `mavo_search_score`, `mavo_search_results`, `mavo_search_hits`,
`mavo_search_excerpt`, `mavo_search_excerpt_length`, `mavo_search_replace_excerpt`,
`mavo_search_highlight_query`, `mavo_search_highlight_html`, `mavo_search_image_concepts`, `mavo_search_synonyms`,
`mavo_search_stopwords`, `mavo_search_log_enabled`.

Also: `mavo_search_best_bets`, `mavo_search_suggestions`.

REST: `POST /wp-json/mavo-search/v1/click` (q, lang, post, rank, source) — the click counter.

Actions: `mavo_search_document_indexed( $post_id, $lang, $doc_id )`,
`mavo_search_index_rebuilt( $mode )`, `mavo_search_query_logged( $query, $lang, $results )`.

Coming from Relevanssi: `docs/relevanssi-compat.md`.

## On the search page (child theme)

- Each result tile shows the article's photo matching the query
  (`mavo_search_result_image()`), else its featured image.
- Partial matches are said plainly under the search box, naming the word
  left out (`missing_words`).
- "Try for example" lists searches visitors really make
  (`mavo_search_suggestions()`), falling back to the hand-written line;
  below it, the visitor's own recent searches, filled in the browser from
  mavo-for-you's profile.
- Result tiles carry `mavo_search_result_attributes()`, and
  `assets/clicks.js` counts clicks on them and on the photo row.
- A query that is exactly an image concept gets one row of photos from
  mavo-image-index (`mavo_image_concept_row()`) between the header and the
  articles, ruled off from them; first page only.

## Look

`assets/search.css` (search pages only) makes marks bold without colour; the
theme decides the rest. Relevanssi showed them red — to keep that:
`.mavo-search-highlight { color: #c00; }` in the theme.

## Tests

`tests/run.sh` — no WordPress or MySQL needed; the plugin's SQL runs against
SQLite. `php tests/bench.php` measures indexing and queries at site scale.
See `claude.md` for the design.

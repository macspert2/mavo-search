# Relevanssi compatibility

Mavo Search replaces Relevanssi as the site search. It reproduces what
visitors and templates relied on — not Relevanssi's API.

> Mavo Search provides **no** `relevanssi_*()` functions and fires **no**
> Relevanssi hooks. Nothing on Maman Voyage calls them (audit below). Each
> function and hook has a native equivalent, listed here, for code written
> later.

## Audit (2026-10-04)

Searched for `relevanssi` in every `mavo-*` plugin, `mavo26-child`, and the
Relevanssi options export (`wp_options.csv`). There are no mu-plugins or
snippets involved (confirmed by the site owner).

| Found | Where | Status after the switch |
|---|---|---|
| `add_filter( 'relevanssi_orderby', 'rlv_fix_order' )` | `mavo26-child/functions.php` | Dead code: Mavo Search always ranks by relevance. Harmless; remove once Relevanssi is uninstalled. |
| CSS for Relevanssi highlighting | — | None. Relevanssi used inline `color: #ff0000` (setting *highlight: text colour*). |
| Search template | `content.php` `is_search()` branch | Unchanged. It shows `get_the_excerpt()`, which now carries Mavo Search's excerpt. |
| Function calls `relevanssi_*()` | — | None anywhere. |

## Behaviour kept

| Relevanssi setting (live) | Mavo Search |
|---|---|
| Post types `post`, `page` | Same (`mavo_search_post_types`) |
| Taxonomies indexed: `post_tag`, weight 0.75 | Tags indexed. Place tags now go to the place field (weighted by level), other tags to `taxonomy` |
| Title boost 10, content 1, exact-match bonus on | Field weights + title / phrase bonuses, see `MVS_Engine::WEIGHTS` |
| Excerpt indexed | Yes (`excerpt` field) |
| AND, OR fallback when nothing matches | Same, with a stricter OR (half the words for 3+ words), flagged `fallback: or` |
| Fuzzy "always" (partial words) | Words of 4+ letters also match longer words starting with them, at half weight; plurals stripped |
| Contextual excerpts, 450 characters | Same length (`mavo_search_excerpt_length`) |
| Highlight in excerpts, not titles, not in documents | Same. Titles via `mavo_search_get_highlighted_title()` if wanted |
| Respect Yoast noindex | Same |
| Polylang: current language only | Same |
| Query log on, without IPs, omitting two admin users | On, never any visitor data, omitting everyone who can edit posts. Daily counters instead of a row per search |
| Stopwords: a 600-word French list on every language | A short list per language (`data/stopwords.php`) — see why there |
| Synonyms: none | A small curated list (`data/synonyms.php`) |
| Minimum word length 3 | 2, so "UK" counts |

## Function equivalents

| Relevanssi | Mavo Search |
|---|---|
| `relevanssi_do_query( $wp_query )` | `mavo_search( $query, $args )` — returns post IDs, scores, excerpts; does not mutate a `WP_Query` |
| `relevanssi_highlight_terms( $content, $query )` | `mavo_search_highlight_terms( $content, $query, [ 'lang' => … ] )` |
| `relevanssi_the_title()` / `relevanssi_get_the_title()` | `mavo_search_the_title()` / `mavo_search_get_highlighted_title()` |
| `relevanssi_do_excerpt()` | `mavo_search_get_excerpt( $post_id, $query )` |
| `relevanssi_get_permalink()` | Not provided: highlighting is not carried into the article (it was off) |
| `relevanssi_insert_edit()` / `relevanssi_remove_doc()` | `mavo_search_reindex_post()` / `mavo_search_delete_post()` |
| `$post->relevance_score` | `mavo_search_result( $post )['score']` |

## Hook equivalents

| Relevanssi | Mavo Search | Shape |
|---|---|---|
| `relevanssi_search_ok` | `mavo_search_search_ok` | `( bool $ok, WP_Query $q )` |
| `relevanssi_modify_wp_query` | `mavo_search_query` | the raw string |
| `relevanssi_results` | `mavo_search_results` | `[ post_id => score ]`, same as Relevanssi: change a score, or unset to drop |
| `relevanssi_hits_filter` | `mavo_search_hits` | the page of result arrays (post IDs, scores, excerpts) |
| `relevanssi_excerpt_content` / `relevanssi_excerpt` | `mavo_search_excerpt` | escaped HTML, post ID, parsed query, source |
| `relevanssi_highlight_query` | `mavo_search_highlight_query` | term set |
| `relevanssi_post_ok`, `relevanssi_do_not_index` | `mavo_search_index_post` | `( bool, WP_Post )` |
| `relevanssi_content_to_index` | `mavo_search_document_sources` | every field's text |
| `relevanssi_index_custom_fields` | `mavo_search_custom_fields` | meta keys |
| `relevanssi_stopword_list` | `mavo_search_stopwords` | per language |
| `relevanssi_orderby` | — | always relevance |
| `relevanssi_join`, `relevanssi_where`, `relevanssi_query_filter`, … | — | SQL internals; no equivalent by design |

Relevanssi's own `relevanssi_search_ok` is *answered* (false) for every query
Mavo Search serves, so the two never both run while Relevanssi is still
active.

## Migration

1. Deploy mavo-search, the mavo-image-index API addition and the theme filter.
2. Activate mavo-search. It builds its index in the background; Relevanssi
   keeps serving until the first full build completes (Tools → Mavo Search
   shows progress; "Rebuild all" there is faster).
3. From then on every front-end search is Mavo Search's. Check a few in
   Tools → Mavo Search → Test a search.
4. Deactivate Relevanssi when satisfied. **Rollback**: reactivate Relevanssi,
   deactivate Mavo Search.
5. Later: uninstall Relevanssi (drops its tables) and remove `rlv_fix_order`
   from the theme.

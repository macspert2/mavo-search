# mavo-search — design notes

Built from `agent.md` (a spec written without knowledge of the sibling
plugins), adapted to how the mavo-* estate actually works. This file records
what was built and, above all, **where and why it differs from agent.md**.
Consumer documentation is in `README.md`; Relevanssi specifics in
`docs/relevanssi-compat.md`.

---

## Files

| File | Purpose |
|---|---|
| `mavo-search.php` | Bootstrap: constants, requires, activation (tables, background build), `plugins_loaded` |
| `includes/api.php` | The public procedural API — the only contract consumers use |
| `includes/class-mavo-search-db.php` | `MVS_DB`: the three tables, dbDelta, schema version, field columns |
| `includes/class-mavo-search-lang.php` | `MVS_Lang`: languages, current language, a post's language |
| `includes/class-mavo-search-text.php` | `MVS_Text`: what a word is, folding, German spellings, stopwords |
| `includes/class-mavo-search-extractor.php` | `MVS_Extractor`: stored post_content → visible text + headings, without the_content |
| `includes/class-mavo-search-geo.php` | `MVS_Geo`: places via `mavo_geo_place_chain()`, guide places via the theme filter |
| `includes/class-mavo-search-hubs.php` | `MVS_Hubs`: hubs via `mavo_get_hub_ancestors()` |
| `includes/class-mavo-search-images.php` | `MVS_Images`: alt text and concepts via `mavo_image_search()`, query concepts |
| `includes/class-mavo-search-document.php` | `MVS_Document`: eligibility, one post → fields and terms, rules version |
| `includes/class-mavo-search-indexer.php` | `MVS_Indexer`: writes / skips unchanged / purges / marks failed |
| `includes/class-mavo-search-query.php` | `MVS_Query`: query → word groups with weighted variants, phrases, concepts |
| `includes/class-mavo-search-engine.php` | `MVS_Engine`: ranking, AND/OR, bonuses, explanation, paging, cache |
| `includes/class-mavo-search-highlight.php` | `MVS_Highlight`: marks in text and HTML |
| `includes/class-mavo-search-excerpt.php` | `MVS_Excerpt`: best window, summary fallback |
| `includes/class-mavo-search-status.php` | `MVS_Status`: current / stale / missing / failed / orphans, in SQL |
| `includes/class-mavo-search-rebuild.php` | `MVS_Rebuild`: cursor batches for admin, CLI and the background build |
| `includes/class-mavo-search-sync.php` | `MVS_Sync`: incremental hooks, shutdown flush, cron overflow |
| `includes/class-mavo-search-cache.php` | `MVS_Cache`: ranked lists under a generation number |
| `includes/class-mavo-search-log.php` | `MVS_Log`: daily per-query counters, reports, pruning |
| `includes/class-mavo-search-wp.php` | `MVS_WP`: `posts_pre_query`, Relevanssi standing aside, search CSS |
| `includes/class-mavo-search-admin.php` | Tools → Mavo Search (admin only) |
| `includes/class-mavo-search-cli.php` | `wp mavo-search` (WP-CLI only) |
| `data/stopwords.php`, `data/synonyms.php` | Short per-language lists |
| `assets/` | `search.css` (front end, search pages), `admin.css`, `admin.js` |
| `tests/` | `run.sh`; SQLite-backed harness; `bench.php` |

Class prefix `MVS_`, text domain `mavo-search`, admin code loaded only in
the admin, CLI only under WP-CLI — as in mavo-image-index, whose structure
this follows file for file where the concerns match.

---

## Divergences from agent.md

### 1. One mode: active (user's decision, 2026-10-04)

agent.md asked for `index-only` / `comparison` / `active` modes and a
Relevanssi comparison screen. The user tests immediately after deploy and
rolls back by switching plugins, so there are no modes and no comparison
tool. What remains of the caution is one rule that is about correctness, not
policy: **searches are answered only after the first complete full rebuild**
(`mavo_search_ready`), so activation never empties the results page.
Activation starts that rebuild in the background (WP-Cron, ~20 s per run).

While Relevanssi is still active, `relevanssi_search_ok` is answered `false`
for every query this plugin serves, so the two never both run.

### 2. No Relevanssi shims (user's decision, 2026-10-04)

The audit found no `relevanssi_*()` call anywhere and one filter
(`relevanssi_orderby` in the theme, dead after the switch). Following the
estate's rule — mavo-hubs §11: a shim nobody calls only makes wrong output
look right — none of agent.md's wrappers or legacy hooks exist. The
compatibility document maps each to its native equivalent instead.
Relevanssi's live settings (`wp_options.csv`) were matched where they mattered:
450-character contextual excerpts, title boost, AND with OR fallback, partial
words, Yoast noindex respected, no title highlighting, logging without IPs.

### 3. Hubs and places follow the live model

agent.md's "hub hierarchy" and "place adapter" are, in fact:

- **mavo-hubs**: a post's hubs are an unordered set, hubs belong to hubs, and
  `mavo_get_hub_ancestors()` gives a depth map. Indexed: every hub up to depth
  2 that is published, still a hub and in the post's language, by title, at
  100 / 60 / 36. Reindexing follows `mavo_hub_membership_added/removed`,
  `mavo_hub_marked/unmarked`, and a hub's title change (`post_updated`).
- **mavo-geotag-plus**: places are `post_tag` terms in a tree, read through
  `mavo_geo_place_chain()`. Indexed: the chain in the post's language, the
  most specific place at 100, regions 80, country 65, continent 30. Those tags
  are excluded from the generic `taxonomy` field so a place is not counted
  twice.

Neither plugin's classes, tables or meta keys are touched.

### 4. Editorial "guide" boost = the theme's landing pages

agent.md asked for a configurable "broad guide" signal and suggested post
meta. The estate already has the editorial fact: a place tag's landing page
(`_mv_hub_page_id`, set on the tag edit screen). The theme now answers a
`mavo_search_guide_places` filter (`inc/mv-geo-hub.php`, user's decision) and
those place names are indexed in a `guide` field weighing 12 — so "Londres"
puts the London page first. Pattern 1 of `sharing-between-plugins.md`: this
plugin never reads the meta key. `mavo_geo_term_url_changed` reindexes the
old and new page. Hub pages also get × 1.15 (`mavo_search_document_boost`).

### 5. Query concepts through a new mavo-image-index function

To turn "eaux turquoise" into `turquoise_water`, mavo-image-index gained
`mavo_image_match_concepts( $text, $lang )` (user's decision), wrapping its
matcher. Its matcher does not fold accents, so concept labels are also
compared folded ("foret" → forest), which is also the fallback without it.
Concepts are indexed as `#slug` and as their label, at their confidence, and
attached to the query words that named them, so a concept can satisfy AND but
weighs only 2.5. Concepts are in V1, not V1.1: the data was already there.

### 6. Accents are folded — unlike mavo-image-index

Image-index keeps accents (côte / côté in a dictionary). A search box is the
other trade: visitors type without accents, Relevanssi matched accent-blind
through MySQL's collation, and ranking separates the rare côte/côté
collision. German umlauts are indexed under both spellings in German
documents only (`zürich` → `zurich` + `zuerich`).

### 7. Schema: one row per term with a column per field

agent.md suggested one row per term × field. Instead, as Relevanssi does,
`mavo_search_terms` has one row per document × term and a SMALLINT column per
field (`TEXT_FIELDS` hold counts, `WEIGHT_FIELDS` — place, hub, concept —
hold a 0–100 strength). One index range per query word, several times fewer
rows. No positions: phrases are checked on the stored text of the best 50
candidates (300 for quoted phrases). No hub / place relation tables: results
carry place and hub IDs from the document's `signals`, enough for facets later.

The term column is `utf8mb4_bin`: terms are already folded, and an
accent-insensitive collation would merge distinct terms under the primary key.

### 8. Staleness without a frontend hash

Each document stores a hash of everything indexed (to skip unchanged rewrites)
and the post's `post_modified_gmt` plus a rules version (indexer version,
stopwords, taxonomies, custom fields). "Stale" is computed in SQL from those
two — never by rebuilding documents. What a modification date cannot show
(an alt edit, a hub renamed) arrives through hooks; a place renamed in
geotag-plus's place editor fires nothing, and needs "Rebuild all".

### 9. Fallback is simpler than agent.md's five stages

Synonyms, places, hubs and concepts are all ordinary variants and fields of
the AND pass, at their own weights, so agent.md's stages 2, 4 and 5 happen
inside stage 1. Only "relaxed OR" remains as a fallback, with a floor (half
the words, for three or more) and a squared coverage penalty, and is flagged.

### 10. The log counts, it does not record

One row per normalized query × language × day with a counter — no row per
search, no IP, user or session at all. First pages only; people who can edit
posts are not counted. Pruned after 400 days. agent.md's `clicked_*` and
`session_hash` columns were not built (later, if ever).

### 11. Smaller things

- **Stopwords** are short per-language lists; Relevanssi's 600-word French
  list (applied to every language) dropped words like "deux" and "haut".
  Stopwords stay in phrase and title comparisons.
- **Minimum word length 2** (Relevanssi: 3).
- **Excerpt length in characters** (450, Relevanssi's), not agent.md's
  25–40 words, to keep the result tiles as they look today.
- **Structured-only matches** get the hand-written excerpt or the opening of
  the text; `image_alt_only` flags a result found only through a photo, for a
  later "photo match" line.
- **No `mavo_search_get_permalink()`**: highlighting inside articles was off
  in Relevanssi.
- **`mavo_search_excerpt_sources`** was not added: sources are the text and
  the hand-written excerpt. **`mavo_search_post_reindexed`** is
  `mavo_search_document_indexed`.
- **`mavo_search_result_image()`** (query-aware thumbnail) exists but the
  theme does not call it yet.
- **Content skip marker**: `<!-- mavo-search:skip -->…<!-- /mavo-search:skip -->`.
- **Password-protected posts** are found by title and excerpt only.

---

## Ranking, in one place

```
score = ( Σ words  rarity × Σ fields  weight × amount × spelling ) + bonuses
        × document boost
```

`amount` = 1 + 0.6·ln(count) for text fields, strength/100 for the others;
`spelling` = word 1, synonym 0.8, plural 0.85, longer form 0.5 (only the best
spelling per field counts); `rarity` = ln(1 + N/df) / ln(1 + N/5) clamped to
0.2–1. See `MVS_Engine` for the bonuses. Tune with
`mavo_search_field_weights` and Tools → Mavo Search → Test a search, which
prints every component.

## Performance (SQLite, synthetic worst case)

1,600 documents of 900 words, 1.1 M term rows (Relevanssi held 460 k): full
rebuild 5.6 s; a query 35–95 ms with 3–7 SQL queries, independent of page
size. Pages 2+ of the same query come from the cache. On MySQL the term
lookups are index ranges on `(term, lang)`.

## Tests

`tests/run.sh` runs each `test-*.php` in its own process against an in-memory
SQLite `$wpdb` (`tests/harness.php`): 205 assertions over text, ranking (the
representative queries of agent.md), excerpts and highlighting, the
`posts_pre_query` integration and logging, incremental sync and status, the
admin page, WP-CLI, and a site with none of the integrations.

Not covered: dbDelta itself, real Polylang / geotag-plus / hubs / image-index
(stubbed), MySQL-specific behaviour, and the live corpus — before relying on
it, run a benchmark of real queries in Tools → Mavo Search.

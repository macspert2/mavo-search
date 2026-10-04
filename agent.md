# agent.md — `mavo-search`

## Goal

Build a new WordPress plugin named **`mavo-search`** for Maman Voyage.

The plugin replaces Relevanssi as the site's frontend search engine while preserving the Relevanssi ideas that are genuinely useful for Maman Voyage:

- indexed relevance search rather than live SQL `LIKE` scans;
- contextual excerpts around matching terms;
- safe search-term highlighting;
- normal WordPress search URLs and templates;
- pagination and normal `WP_Query` semantics;
- extension hooks and a practical compatibility layer for Mavo code that currently calls common Relevanssi helpers.

The new plugin should be **smaller, easier to understand, and deliberately Mavo-specific**.

It should understand data Relevanssi does not naturally model as first-class Mavo concepts:

- Polylang languages `fr`, `en`, `de`;
- title, content and excerpt;
- selected custom fields;
- multilingual image alt text;
- `mavo-image-index` concepts and image usages;
- Mavo geographic place hierarchy;
- multiple declared Mavo hubs;
- selected Mavo taxonomies / structured metadata;
- future Mavo-specific synonyms, facets and search UI.

The plugin should **not** attempt to reimplement every Relevanssi option or generic compatibility feature.

> Reproduce the useful search contract, not the whole Relevanssi product.

---

# Site context

Relevant environment:

- WordPress;
- Polylang active;
- languages `fr`, `en`, `de`;
- French is default/fallback;
- custom site plugins use prefix `mavo-`;
- Classic Editor content is common;
- current search engine is Relevanssi;
- `mavo-image-index` is active and provides semantic image/alt/usage APIs;
- EN/DE image alt text may live outside standard `_wp_attachment_image_alt`;
- posts have geographic context through Mavo geolocation/place infrastructure;
- posts may belong to **multiple declared hubs**;
- geography, hubs and image concepts are separate dimensions.

Relevant image API includes:

```php
mavo_image_get();
mavo_image_get_alt();
mavo_image_get_concepts();
mavo_image_has_concept();
mavo_image_get_usages();
mavo_image_get_geo_context();
mavo_image_search();
mavo_image_best_match();
mavo_image_concepts();
mavo_register_image_concept();
```

Do not duplicate image-index logic inside `mavo-search`.

---

# Primary goals

## Goal 1 — Replace Relevanssi for frontend search

The plugin must:

- intercept normal frontend WordPress searches;
- return ranked post/page results;
- support pagination;
- generate useful contextual excerpts;
- highlight matching search terms;
- preserve `?s=query` URLs;
- integrate with the current search template with minimal changes.

## Goal 2 — Improve search with Mavo-specific knowledge

Search should understand:

- multilingual content;
- multilingual image alt text;
- image concepts;
- geographic place hierarchy;
- multiple hub memberships;
- selected structured Mavo metadata;
- curated Mavo synonyms;
- broad-guide versus narrow-article intent.

---

# Non-goals

V1 must **not** attempt to reproduce:

- every Relevanssi setting;
- every Relevanssi filter;
- every page-builder compatibility layer;
- WooCommerce search;
- comments search unless explicitly required;
- user search;
- multisite search;
- arbitrary object-type search;
- vector embeddings;
- external SaaS search;
- advanced typo correction for every query;
- unrelated Relevanssi Premium features.

Keep scope focused on Maman Voyage.

---

# Before implementation: audit current Relevanssi usage

Before writing compatibility code, search the entire Mavo codebase for:

```text
relevanssi_
```

Inspect:

- child theme;
- `mavo-*` plugins;
- mu-plugins;
- functions.php;
- snippets;
- search templates;
- CSS targeting Relevanssi highlighting;
- any analytics code.

Create an internal compatibility checklist of:

1. Relevanssi functions currently called;
2. filters/actions currently used;
3. options currently relied upon;
4. search-template assumptions;
5. highlighting CSS/classes;
6. query/permalink behavior;
7. user-search logging behavior.

Compatibility should first cover **actual Mavo dependencies**, then selected common presentation helpers.

---

# Architecture

Separate the plugin into clear layers:

```text
Content adapters / index sources
            ↓
        Indexer
            ↓
     Search index DB
            ↓
       Search engine
            ↓
 Excerpt + highlighting
            ↓
 WordPress integration / API
            ↓
 Optional Mavo enhancements
```

Suggested structure:

```text
mavo-search/
├── mavo-search.php
├── includes/
│   ├── class-plugin.php
│   ├── class-db.php
│   ├── class-indexer.php
│   ├── class-document-builder.php
│   ├── class-tokenizer.php
│   ├── class-normalizer.php
│   ├── class-search-engine.php
│   ├── class-ranking.php
│   ├── class-excerpts.php
│   ├── class-highlighter.php
│   ├── class-polylang.php
│   ├── class-image-index-adapter.php
│   ├── class-place-adapter.php
│   ├── class-hub-adapter.php
│   ├── class-wordpress-integration.php
│   ├── class-relevanssi-compat.php
│   ├── class-admin.php
│   ├── class-cli.php
│   ├── class-query-log.php
│   └── functions-public.php
├── data/
│   ├── synonyms.php
│   └── stopwords.php
├── assets/
│   └── search.css
├── docs/
│   └── relevanssi-compat.md
└── tests/
```

Exact file layout may differ, but preserve modular boundaries.

---

# Search interception

Use a modern WordPress interception approach similar in spirit to Relevanssi.

Default eligibility:

```text
$query->is_search()
AND $query->is_main_query()
AND not admin unless explicitly enabled
```

Prefer `posts_pre_query` or another clean early interception mechanism.

Populate the normal query object correctly:

```text
$wp_query->posts
$wp_query->post_count
$wp_query->found_posts
$wp_query->max_num_pages
```

Do not intercept REST/admin/custom queries unless explicitly configured.

Expose:

```php
mavo_search_search_ok
```

and, when Relevanssi is not active, optionally support:

```php
relevanssi_search_ok
```

---

# Index strategy

Use a custom index.

Do **not** join and scan `wp_posts`, `wp_postmeta`, taxonomy tables and image metadata on each search request.

Search should be fast enough for ordinary uncached WordPress search pages.

---

# Suggested database model

Use `$wpdb->prefix`; never hard-code `wp_`.

## `{$wpdb->prefix}mavo_search_documents`

One row per post/language.

Suggested fields:

```text
document_id          BIGINT PK
post_id              BIGINT
lang                 VARCHAR
post_type            VARCHAR
post_status          VARCHAR
title                TEXT
excerpt              TEXT
content_plain        LONGTEXT
searchable_snapshot  LONGTEXT optional
post_date            DATETIME
post_modified        DATETIME
index_hash           VARCHAR
indexed_at           DATETIME
```

Indexes:

```text
UNIQUE(post_id, lang)
INDEX(lang, post_type, post_status)
INDEX(post_modified)
INDEX(indexed_at)
```

## `{$wpdb->prefix}mavo_search_terms`

Weighted inverted index.

Suggested fields:

```text
id
term
document_id
field
frequency
positions optional
weight_base
```

Possible fields:

```text
title
content
excerpt
image_alt
image_concept
hub
place
taxonomy
custom
```

Indexes:

```text
INDEX(term)
INDEX(term, document_id)
INDEX(document_id)
INDEX(field)
INDEX(term, field)
```

## Relation tables if useful

Use dedicated indexed relation tables for hub/place filtering if this produces cleaner SQL:

```text
mavo_search_document_hubs
mavo_search_document_places
```

Do not over-normalize unless it improves query plans.

---

# Why not one giant FULLTEXT blob?

A single MySQL/MariaDB FULLTEXT field may be used selectively, but Mavo needs field-aware relevance:

```text
title ≠ body ≠ place ≠ hub ≠ image alt ≠ image concept
```

The engine must preserve source provenance and score fields differently.

Prefer a weighted inverted index or equivalent field-aware model.

---

# Indexed objects

Default indexed post types:

```text
post
page
```

Default status:

```text
publish
```

Make both filterable.

Do not return media attachments as standalone search results by default.

---

# Initial field weights

Use centrally defined defaults that are easy to tune.

Suggested starting values:

```text
exact full-title phrase          20
exact phrase in title            15
title term                       10
place name                        8
hub name                          8
heading text                      6 optional
post content                      4
image alt text                    3
excerpt                           3
image concept                     2.5
selected taxonomy                 2
selected custom field             configurable
```

These are starting points, not immutable values.

Expose:

```php
mavo_search_field_weights
```

Avoid a huge generic settings matrix.

---

# Title ranking

Titles are especially important.

Apply explicit boosts when:

1. normalized query equals normalized title;
2. exact query phrase occurs in title;
3. all meaningful query terms occur in title;
4. individual terms occur in title.

Example:

```text
query: où dormir à Londres
```

A matching accommodation guide title should strongly outrank a generic London article with many incidental body mentions.

---

# Content extraction

Index human-visible editorial text.

For `post_content`:

- strip HTML safely;
- remove technical shortcode syntax;
- preserve useful heading text;
- decode entities;
- exclude scripts/styles/technical attributes;
- avoid indexing CSS classes, image URLs and shortcode internals;
- optionally support a Mavo no-index marker/class.

Do not blindly apply every `the_content` filter during rebuild if this invokes expensive or side-effect-heavy plugins.

Build a controlled extraction pipeline.

---

# Polylang / language model

Polylang is first-class.

Supported V1 languages:

```text
fr
en
de
```

Each search document has one language.

Normal frontend search:

1. use current Polylang language;
2. fallback to `fr` if unavailable;
3. search only same-language documents by default.

Provide an explicit API option for other/all languages, but normal search should not mix translations.

---

# Text normalization

Create language-aware normalization while preserving original text separately for excerpts/highlighting.

Common behavior:

- Unicode normalization;
- lowercase;
- decode entities;
- collapse whitespace;
- punctuation/token boundary handling;
- preserve useful apostrophe/hyphen behavior.

## French

Support accent-insensitive matching where sensible:

```text
ile ↔ île
edimbourg ↔ Édimbourg
```

## German

At minimum support common user variants:

```text
ä ↔ ae
ö ↔ oe
ü ↔ ue
ß ↔ ss
```

Do not create excessive false positives.

## English

Standard Unicode/lowercase/token normalization.

---

# Stopwords

Support per-language stopwords, but keep lists conservative.

Travel phrases may contain meaningful short words:

```text
où dormir
avec enfants
en famille
à vélo
```

Phrase matching must still recognize these constructions even if some tokens are stopwords.

Expose stopword filters/config.

---

# Morphology / stemming

Do not make sophisticated stemming a V1 dependency.

Prefer:

- normalization;
- curated synonyms;
- safe singular/plural variants.

Possible later evaluation: Snowball stemming.

Relevance quality matters more than theoretical linguistic completeness.

---

# Mavo-specific synonyms

Implement a deliberately small per-language synonym layer.

Examples to consider:

French:

```text
rando → randonnée
leucade ↔ lefkada
```

English:

```text
hike ↔ hiking
bike ↔ cycling where context permits
```

German:

```text
Wanderung ↔ wandern
```

Accommodation synonyms should be added conservatively because `hotel`, `logement`, `hébergement`, `Ferienwohnung`, etc. are not always equivalent.

Store initial mappings in:

```text
data/synonyms.php
```

Expose filters.

---

# Query parser

Support:

- multiple terms;
- quoted phrases;
- language normalization;
- AND-first logic;
- controlled OR fallback;
- synonyms;
- exact title/phrase detection.

Recommended behavior:

1. favor documents matching all meaningful terms;
2. if no or too few strong results exist, allow a controlled OR fallback;
3. exact phrase/title matches remain strongly boosted.

Expose parsed query data for debugging.

---

# Search scoring

Keep ranking deterministic and explainable.

Broad model:

```text
score =
    field_weight
  × term_frequency_factor
  × phrase/title boosts
  × Mavo contextual boosts
```

Use diminishing term frequency so long posts do not win simply by repetition.

Example concept:

```text
1 hit   → 1.0
2 hits  → 1.4
5 hits  → 1.8
20 hits → not 20× stronger
```

Use BM25-like ideas if helpful, but keep the implementation understandable.

---

# Broad guide / editorial boost

Broad destination searches should favor principal guide pages when appropriate.

Example:

```text
Londres
```

should likely rank the main London guide ahead of narrow individual London articles.

Provide a configurable editorial boost source, e.g.:

- post meta;
- hub/guide designation;
- existing Mavo metadata;
- filter.

Expose:

```php
mavo_search_document_boost
```

Do not hard-code post IDs.

---

# Geography / place indexing

Geography is separate from hubs.

Use the current Mavo place/geolocation API.

Index:

- most-specific place;
- useful ancestors such as city/region/island/country/continent.

Example:

```text
Lefkada → Greece → Europe
```

A query for:

```text
Grèce
```

should be able to find Lefkada/Santorini/etc. posts even when `Grèce` is not repeated heavily in body text.

Place terms should have meaningful but not dominant weight.

Keep raw place integration behind `class-place-adapter.php` or equivalent.

---

# Hub indexing

Posts may belong to **multiple declared hubs**.

Hubs are editorial/thematic, not geography.

Index:

- hub name/title;
- hub ancestors if the current hub model supports useful hierarchy;
- selected hub synonyms if configured.

Hub matches provide a moderate boost.

Example:

```text
query: Harry Potter Édimbourg
```

Harry Potter hub membership may help, but a direct title/body match remains stronger.

Do not infer hub membership from image concepts.

---

# Image alt text indexing

This is a core Mavo requirement.

For each indexed post:

1. obtain image usages from `mavo-image-index`;
2. retrieve language-specific alt text using:

```php
mavo_image_get_alt( $attachment_id, $lang )
```

3. index the relevant alt text under field:

```text
image_alt
```

Recommended weight: around `3`.

Do not assume `_wp_attachment_image_alt` correctly represents EN/DE.

If `mavo-image-index` is unavailable, degrade gracefully and optionally use standard attachment alt text.

---

# Image concept indexing

Index canonical `mavo-image-index` concepts as lower-weight semantic signals.

Examples:

```text
beach
turquoise_water
old_town
hiking
garden
```

Recommended field:

```text
image_concept
```

Recommended weight:

```text
2–3
```

Concept labels and synonyms in the current language should be searchable.

Image concepts improve recall and visual-intent search but should not overwhelm literal title/body relevance.

---

# Query-aware result thumbnails

Design for this from the start; implementation may be V1.1.

Expose parsed image concepts so the result template can optionally call:

```php
mavo_image_best_match( [
    'post_ids'    => [ $post_id ],
    'concepts'    => $matched_image_concepts,
    'orientation' => 'landscape',
] );
```

Fallback:

```php
get_post_thumbnail_id( $post_id );
```

Examples:

```text
plage lefkada → beach image from Lefkada article
forêt madère  → forest image from Madeira article
```

Never alter the canonical WordPress featured image automatically.

---

# Contextual excerpts

Contextual excerpts are essential.

Use a Relevanssi-inspired approach:

1. identify candidate text sources;
2. locate query terms;
3. score matching windows;
4. select the strongest window;
5. trim by words;
6. preserve UTF-8;
7. add ellipses;
8. highlight matching terms.

Candidate sources:

```text
content
manual excerpt
possibly image alt context
```

Default excerpt length should be configurable, starting around:

```text
25–40 words
```

Never emit broken HTML.

---

# Structured-field-only matches

If a result matched mostly through place/hub metadata rather than body text, use a normal article summary/body excerpt rather than showing an unnatural synthetic fragment.

If an image-alt-only match is especially important, the API may expose that fact for a future UI such as:

```text
Photo match: “Plage de Milos aux eaux turquoise…”
```

Do not force this UI in V1.

---

# Search-term highlighting

Preserve Relevanssi's useful highlighting behavior where practical.

Default HTML:

```html
<mark class="mavo-search-highlight">term</mark>
```

Prefer semantic `<mark>`.

Highlight:

- result excerpts;
- optionally titles;
- optionally selected metadata.

Do not highlight inside:

- HTML tags/attributes;
- URLs;
- scripts/styles;
- entity syntax.

Use Unicode-aware matching.

Default to whole-word highlighting.

One-letter highlights off by default.

If normalization/stemming caused the match, support sensible expanded highlighting when possible.

---

# Native highlighting API

Provide:

```php
mavo_search_highlight_terms(
    string $content,
    string|array $query,
    array $args = []
): string;
```

```php
mavo_search_get_highlighted_title(
    int|WP_Post|null $post = null
): string;
```

```php
mavo_search_the_title(
    int|WP_Post|null $post = null
): void;
```

---

# Relevanssi highlighting compatibility

When Relevanssi is not active and the function does not already exist, provide a compatibility wrapper for:

```php
relevanssi_highlight_terms(
    string $content,
    string|array $query,
    bool $convert_entities = false
)
```

Map internally to Mavo highlighting.

Official Relevanssi behavior supports highlighting in excerpts/content and uses Unicode-aware regex behavior; preserve the practical result, not every settings permutation.

Also consider wrappers for:

```text
relevanssi_the_title()
relevanssi_get_permalink()
```

**only after auditing actual Mavo usage.**

Never redeclare an existing function:

```php
if ( ! function_exists( 'relevanssi_highlight_terms' ) ) {
    // wrapper
}
```

---

# `relevanssi_get_permalink()` compatibility

Relevanssi can propagate the search query to clicked documents for full-document highlighting.

This is optional in Mavo.

If implemented, provide native:

```php
mavo_search_get_permalink();
```

and optionally a compatibility wrapper.

Any highlight query parameter must be:

- safely encoded;
- canonical-safe;
- excluded from indexing/canonical duplication concerns.

Default V1 may limit highlighting to the search-results page.

---

# Relevanssi compatibility strategy

Aim for **practical compatibility**, not complete emulation.

## Level A — WordPress behavior compatibility

Mandatory:

```text
?s=query
normal WP_Query search loop
pagination
found_posts
max_num_pages
normal WP_Post result objects
```

## Level B — common helper compatibility

Conditionally provide wrappers for functions actually used by Mavo, likely:

```text
relevanssi_highlight_terms()
relevanssi_the_title()
relevanssi_get_permalink()
```

Potentially:

```text
relevanssi_do_query()
```

only if existing Mavo code requires it and its contract can be matched safely.

## Level C — selected hook compatibility

Potentially fire selected legacy filters when safe:

```text
relevanssi_search_ok
relevanssi_results
relevanssi_hits_filter
relevanssi_excerpt
relevanssi_highlight_query
```

Do **not** blindly implement every Relevanssi hook.

Document supported compatibility precisely.

---

# `relevanssi_results` compatibility

If supported, preserve the expected broad shape:

```text
post ID => weight
```

Apply:

```php
apply_filters( 'relevanssi_results', $post_weights );
```

Then safely convert modified weights/removals back to Mavo result scores.

If a consumer removes an item, remove it from final results.

Do not fire this filter with an incompatible data shape.

---

# `relevanssi_hits_filter` compatibility

If supported, match Relevanssi's late-result intent as closely as practical.

Expected conceptual shape:

```php
$data = [
    $posts,
    $query_string,
];

$data = apply_filters(
    'relevanssi_hits_filter',
    $data,
    $wp_query
);
```

Audit current site usage before implementing.

---

# Low-level Relevanssi hooks not to emulate by default

Do not constrain Mavo Search to Relevanssi's SQL internals merely for compatibility.

Do not emulate these unless Mavo actually depends on them:

```text
relevanssi_query_filter
relevanssi_join
relevanssi_where
relevanssi_term_where
relevanssi_df_query_filter
```

These are implementation-specific to Relevanssi.

---

# Native Mavo hooks

Provide clean native extension points.

Suggested filters:

```text
mavo_search_search_ok
mavo_search_query
mavo_search_parsed_query
mavo_search_field_weights
mavo_search_document_sources
mavo_search_document_boost
mavo_search_score
mavo_search_results
mavo_search_hits
mavo_search_excerpt_sources
mavo_search_excerpt
mavo_search_highlight_query
mavo_search_highlight_html
mavo_search_synonyms
mavo_search_stopwords
```

Suggested actions:

```text
mavo_search_document_indexed
mavo_search_post_reindexed
mavo_search_index_rebuilt
mavo_search_query_logged
```

---

# Public Mavo API

Provide a stable procedural API.

## Search

```php
mavo_search( string $query, array $args = [] ): array;
```

Suggested return:

```php
[
    'query' => 'londres famille',
    'lang' => 'fr',
    'total' => 42,
    'page' => 1,
    'per_page' => 10,
    'results' => [
        [
            'post_id' => 123,
            'score' => 18.4,
            'matched_terms' => [],
            'matched_fields' => [],
            'matched_image_concepts' => [],
            'excerpt' => '...',
            'debug' => [], // only when requested/admin
        ],
    ],
]
```

Other helpers:

```php
mavo_search_reindex_post( int $post_id ): bool;

mavo_search_delete_post( int $post_id ): void;

mavo_search_parse_query(
    string $query,
    ?string $lang = null
): array;

mavo_search_get_excerpt(
    int $post_id,
    string|array $query,
    array $args = []
): string;

mavo_search_highlight_terms(
    string $content,
    string|array $query,
    array $args = []
): string;
```

---

# Incremental indexing

Reindex affected documents when:

- post created/updated;
- status changes;
- trash/delete;
- language changes;
- translation relationship changes;
- indexed taxonomy terms change;
- place/geolocation changes;
- hub memberships change;
- selected custom fields change;
- post image usages change;
- relevant image alt/concepts change.

Never rebuild the whole index on a normal post save.

---

# `mavo-image-index` integration

Use its public API/hooks.

When an image alt or concept changes:

1. determine which posts use that image;
2. mark/reindex only those posts.

Relevant hooks may include:

```text
mavo_image_indexed
mavo_image_usage_updated
mavo_image_index_cache_bumped
```

Avoid global reindexing for a single changed image.

If image index is inactive, search title/content/etc. must still work.

---

# Hub integration

When hub membership changes, affected posts must be reindexed.

Use public hub APIs/hooks if available.

If no suitable event exists, expose:

```php
mavo_search_reindex_post( $post_id );
```

for the hub plugin to call.

Do not hard-code hub storage unless necessary.

---

# Place/geolocation integration

Likewise:

- use public place/geolocation helpers;
- reindex posts when location changes;
- index useful ancestors;
- keep geographic implementation behind an adapter.

Search needs article geography, not precise image coordinates.

---

# Staleness / index hash

Each document should track a deterministic hash/version of indexed sources.

Include at least:

- title/content/excerpt;
- language;
- relevant taxonomy;
- hub membership;
- place hierarchy;
- selected custom fields;
- image enrichment version/data as appropriate.

Admin/CLI states:

```text
current
stale
missing
failed
```

Do not compute expensive hashes on frontend requests.

---

# Full rebuild

Full indexing must run in batches.

Support CLI:

```bash
wp mavo-search rebuild --all
wp mavo-search rebuild --stale
wp mavo-search rebuild --post=123
```

Useful options:

```text
--batch-size=250
--lang=fr
--post-type=post
```

Admin rebuilds should use background/AJAX batching rather than one long synchronous request.

---

# Admin UI

Add:

```text
Tools → Mavo Search
```

Keep it practical.

Show:

- indexed documents;
- counts by language;
- stale/missing/failed counts;
- DB/index size;
- latest rebuild;
- configured field weights;
- Relevanssi compatibility status;
- Polylang status;
- image-index status;
- hub adapter status;
- place adapter status.

Actions:

```text
Rebuild all
Rebuild stale
Reindex post ID
Test search
Compare with Relevanssi
```

---

# Safe migration: parallel comparison mode

This is required before production cutover.

While Relevanssi remains active, provide an admin/CLI comparison mode.

Example:

```bash
wp mavo-search compare "londres" --lang=fr
```

Output concept:

```text
Rank | Mavo Search                    | score | Relevanssi
1    | Londres en famille             | 18.4  | ...
2    | ...                            | ...   | ...
```

Admin comparison should show:

- Mavo result rank;
- Relevanssi result rank;
- score explanation;
- matched fields;
- matched terms;
- generated excerpt;
- parsed query;
- fallback mode.

Do not hijack public search while in comparison mode.

---

# Plugin operating modes

Provide explicit modes:

```text
index-only
comparison
active
```

Default after install:

```text
index-only
```

Recommended migration:

1. install;
2. build index;
3. test;
4. comparison mode;
5. tune benchmark queries;
6. explicitly enable active mode;
7. disable Relevanssi only after Mavo Search is stable.

Never require Relevanssi to be disabled before the new index is ready.

---

# Search analytics / query logging

Implement lightweight first-party query logging.

Suggested table:

```text
{$wpdb->prefix}mavo_search_queries
```

Possible fields:

```text
id
query_normalized
lang
searched_at
result_count
clicked_post_id optional later
clicked_rank optional later
session_hash optional later
```

Do **not** store IP addresses by default.

Initial reports:

- top searches;
- zero-result searches;
- low-result searches;
- searches by language.

Logging should be disableable.

---

# Zero-result fallback

For zero-result or very weak queries, attempt in a controlled order:

1. exact/AND normal search;
2. synonyms;
3. relaxed OR;
4. place/hub matches;
5. image concept matches.

Do not silently return wildly unrelated content.

Expose fallback state in result metadata so the UI may later say:

```text
No exact results. Showing related matches.
```

---

# Autocomplete / suggestions

Not required in V1.

Architecture should allow future suggestions from:

- place names;
- hub names;
- article titles;
- popular queries;
- image concept labels.

Do not build a large JS autocomplete system unless explicitly requested.

---

# Facets / grouping

Not required in V1.

Future possibilities:

```text
Guides
Articles
Destinations
```

and filters such as:

```text
Destination
Hub/theme
Travel type
```

Keep result metadata rich enough to enable these later.

---

# Search UI integration

Do not redesign the site's search UI during the core migration.

First preserve existing behavior.

Future Mavo-specific result enhancements may include:

- query-aware thumbnails;
- hub/place badges;
- visual-concept links;
- facets;
- suggestions.

The engine should expose data for these without requiring them.

---

# Bridge to public `/images/` exploration

Maman Voyage now has public image concept pages such as:

```text
/images/
/images/eaux-turquoise/
```

Do not hard-code these routes into the search engine.

Instead expose parsed image concepts through:

```php
mavo_search_parse_query();
```

A separate presentation layer may later detect:

```text
query concept = turquoise_water
```

and offer:

```text
Voir aussi : Eaux turquoise en images
```

Keep search engine and presentation responsibilities separate.

---

# Search highlighting CSS

Provide minimal default CSS only.

Example selector:

```css
.mavo-search-highlight {}
```

Do not hard-code aggressive colors or inline styles.

Let the child theme control final appearance.

---

# Search-template compatibility

Normal WordPress loop should continue to work:

```php
if ( have_posts() ) {
    while ( have_posts() ) {
        the_post();
        // existing template
    }
}
```

Populate query metadata correctly and return normal `WP_Post` objects.

Provide helper access to Mavo score/match metadata if the template wants it.

---

# Score explanation

Provide optional admin/CLI explanation.

Example:

```text
Post 123 — score 21.6
 title term                    +10.0
 exact phrase                  +6.0
 place: London                 +4.0
 hub: city trip                +1.0
 body matches                  +0.6
```

This is essential for tuning.

Never expose score debug publicly by default.

---

# Performance requirements

Frontend search must:

- use indexed tables;
- avoid scanning raw `post_content`;
- avoid scanning postmeta;
- avoid scanning image alt text;
- avoid N+1 queries;
- cap candidate sets sensibly;
- use indexes;
- avoid `ORDER BY RAND()`.

Cache where useful:

- parsed query structures;
- dictionaries;
- optionally repeated popular result sets.

Do not assume Redis/Memcached.

---

# Index cleanup

When a post is removed/unpublished:

- remove document row;
- remove term rows;
- remove relation rows;
- remove stale caches.

Provide DB stats in admin/CLI:

```text
documents
term rows
DB size
rows by language
rows by field
```

---

# Security

Requirements:

- prepared SQL;
- sanitized query/admin/CLI input;
- escaped output;
- nonce and capability checks;
- no raw SQL fragments accepted through public API;
- no untrusted regex execution;
- sensible maximum query length;
- sensible maximum token count;
- no direct file execution.

---

# Relevanssi compatibility documentation

Create:

```text
docs/relevanssi-compat.md
```

Document:

- supported functions;
- supported hooks;
- unsupported features;
- differences;
- migration notes.

Use wording such as:

> Mavo Search provides a targeted Relevanssi compatibility layer for APIs used by Maman Voyage and a small set of common presentation helpers. It is not a drop-in implementation of every Relevanssi feature.

---

# Suggested V1 compatibility surface

Subject to actual code audit.

## Functions

```text
relevanssi_highlight_terms()
relevanssi_the_title()
relevanssi_get_permalink()
```

Potentially:

```text
relevanssi_do_query()
```

only if actual site code requires it.

## Filters

```text
relevanssi_search_ok
relevanssi_results
relevanssi_hits_filter
relevanssi_excerpt
relevanssi_highlight_query
```

Only fire a legacy hook when its semantics can be honored correctly.

---

# Relevanssi best practices to preserve

Preserve these useful patterns:

1. indexed search rather than raw WP search;
2. contextual excerpts around strongest matches;
3. visible term highlighting;
4. strong title weighting;
5. normal WordPress search URLs/templates;
6. controlled synonym support;
7. hooks for ranking/result adjustment;
8. admin query testing;
9. query logging;
10. highlighted-title/permalink helpers where Mavo uses them.

Do not carry over unused generic settings complexity.

---

# Mavo-specific roadmap

## V1

Implement:

```text
title
body
excerpt
language isolation
place hierarchy
multiple hub names
image alt text
selected taxonomy/custom fields
exact phrase/title boosts
contextual excerpts
highlighting
query logging
Relevanssi compatibility layer
parallel comparison mode
```

## V1.1

Add/refine:

```text
image concepts
query-aware thumbnails
Mavo synonyms
better place/hub scoring
zero-result fallback
analytics reports
```

## V1.2

Possible later work:

```text
autocomplete
facets
result grouping
visual-concept links
"search in images"
spelling suggestions
semantic query expansion
query intent classification
```

Do not build V1.2 prematurely.

---

# Representative query expectations

The engine should eventually handle these well.

## `londres`

Expected:

- principal London guide at/near top;
- relevant London content;
- FR results only on FR frontend.

## `où dormir à Londres`

Expected:

- accommodation guide strongly boosted;
- exact phrase/title relevance dominates generic London posts.

## `harry potter edimbourg`

Expected:

- Edinburgh Harry Potter article;
- hub membership may help;
- literal title/body still important.

## `plage lefkada`

Expected:

- Lefkada article;
- place match + beach image alt/concept;
- optional contextual beach thumbnail.

## `eaux turquoise`

Expected:

- articles with matching text/image alt/concepts;
- literal body/title matches still strong;
- UI may later offer `/images/eaux-turquoise/` separately.

## `madere jardin`

Expected:

- Madeira geographic match;
- garden content/images boost relevant posts.

## `grèce`

Expected:

- posts under Greece via place hierarchy even if body text is sparse.

## `velo angleterre`

Expected:

- cycling-related England content;
- hub/image concepts may assist.

## `leucade`

Expected:

- Lefkada content via curated synonym.

---

# Benchmark corpus

Before production cutover create at least **50–100 representative real queries**.

Include:

- countries;
- cities;
- regions/islands;
- broad destination searches;
- activities;
- family intents;
- accommodation;
- seasonal terms;
- spelling/diacritic variants;
- English queries;
- German queries;
- terms found mainly in image alt text;
- weak/zero-result queries.

For each query record:

```text
expected top result(s)
acceptable top 5
clearly wrong results
```

Use this benchmark while tuning ranking.

---

# Automated tests

Cover at minimum:

## Language isolation

FR query returns FR posts by default; EN and DE behave equivalently.

## Accent normalization

```text
ile ↔ île
edimbourg ↔ Édimbourg
```

## German normalization

Test configured `ue/ü`, `ss/ß`, etc.

## Exact title phrase

Exact title/phrase beats body-only repetition.

## Long-document control

A long article with many incidental repetitions must not automatically outrank a concise highly relevant guide.

## Place hierarchy

Child place content is found by parent region/country query.

## Hub weighting

Hub match helps but does not overpower direct title relevance.

## Image alt

A term existing only in the correct-language image alt can still find the post.

## Image concepts

Localized concept terms improve recall when enabled.

## Missing image plugin

Search still works when `mavo-image-index` is unavailable.

## Excerpts

Best matching excerpt window is returned.

## Highlighting

Terms highlight safely without corrupting HTML/entities.

## Pagination

Correct result ordering, `found_posts`, pages.

## Compatibility wrappers

No function redeclaration while Relevanssi is active.

## Incremental reindex

Editing title/content/hub/place/image usage only reindexes affected documents.

## Delete/unpublish

No stale index rows remain.

## Query logging

No-result query is logged without PII.

---

# Manual acceptance testing

Compare Mavo Search against current Relevanssi for benchmark queries.

Enable public replacement only when:

- no major relevance regressions remain;
- important destination/guide queries are equal or better;
- EN/DE image-alt searches are improved;
- pagination/template behavior is correct;
- excerpts/highlighting are at least as useful;
- no material frontend performance regression exists.

---

# Migration sequence

## Phase 1 — Index only

- install plugin;
- create tables;
- build index;
- Relevanssi still serves visitors.

## Phase 2 — Compare

- admin/CLI side-by-side testing;
- tune field weights;
- build benchmark suite;
- verify highlighting/excerpts.

## Phase 3 — Optional shadow analysis

Use real query logs to run offline/admin comparisons where practical.

Do not double normal frontend query cost unnecessarily.

## Phase 4 — Activate Mavo Search

- enable frontend interception;
- monitor results/performance;
- keep Relevanssi installed but disabled temporarily for rollback if desired.

## Phase 5 — Remove Relevanssi dependency

Only after:

- no missing compatibility calls remain;
- production ranking is stable;
- incremental indexing is reliable.

---

# WP-CLI

Suggested namespace:

```bash
wp mavo-search
```

Commands:

```bash
wp mavo-search status
wp mavo-search rebuild --all
wp mavo-search rebuild --stale
wp mavo-search rebuild --post=123
wp mavo-search search "londres" --lang=fr
wp mavo-search explain "londres" --post=123
wp mavo-search compare "londres" --lang=fr
wp mavo-search terms --post=123
wp mavo-search logs --zero
```

`explain` must show score components.

---

# Cache behavior

Search pages are dynamic.

Any internal result cache key must include at least:

```text
query
language
page
filters
index generation/version
```

Keep caches bounded.

Do not trigger broad Cloudflare/page-cache purges when the search index updates.

---

# Graceful degradation

If optional systems are unavailable:

```text
Polylang unavailable      → fallback to fr
image index unavailable   → omit image alt/concept enrichment
hub API unavailable       → omit hub field
place API unavailable     → omit place field
```

Title/content/excerpt search must still function.

---

# Coding standards

Use:

- WordPress coding standards;
- `$wpdb->prepare()`;
- `$wpdb->prefix`;
- documented public APIs;
- versioned idempotent schema upgrades;
- no direct edits to WordPress core tables;
- no edits to parent theme;
- no edits to Relevanssi;
- no edits to unrelated plugins.

---

# Acceptance criteria

The plugin is production-ready when:

1. normal WordPress `?s=` searches can be served entirely by `mavo-search`;
2. standard search templates and pagination work;
3. Polylang same-language filtering works for `fr`, `en`, `de`;
4. title/body/excerpt are indexed;
5. Mavo place hierarchy is indexed;
6. multiple hub memberships are indexed;
7. multilingual image alt text from `mavo-image-index` is searchable;
8. image concepts can act as lower-weight signals;
9. ranking is field-aware and explainable;
10. exact title/phrase matches rank strongly;
11. long documents do not win merely through repetition;
12. contextual excerpts work;
13. query terms highlight safely;
14. common Relevanssi presentation helpers used by Mavo are compatibly available;
15. selected Relevanssi hooks used by Mavo are supported or documented as unsupported;
16. full rebuild is batch-safe;
17. incremental reindexing works;
18. deleted/unpublished posts leave no stale index data;
19. comparison against Relevanssi is available before cutover;
20. WP-CLI rebuild/search/explain/compare works;
21. query logging records useful aggregate data without PII;
22. optional integrations fail gracefully;
23. frontend search performance is acceptable;
24. benchmark queries are equal or better than current Relevanssi before public activation.

---

# Final implementation philosophy

`mavo-search` should not be “Relevanssi rewritten”.

It should be:

> **a compact, field-aware, multilingual search engine that understands Maman Voyage.**

Preserve Relevanssi's best user-facing ideas — indexed relevance, contextual excerpts, highlighting, WordPress-native integration and useful extension hooks — while removing generic options Mavo does not need.

Long-term advantage comes from Mavo-specific structure:

```text
query
  ↓
language-aware parsing
  ↓
title / content / excerpt
+ geography
+ multiple hubs
+ multilingual image alt text
+ image concepts
+ selected Mavo metadata
  ↓
explainable ranking
  ↓
contextual excerpt + highlighting
  ↓
optional query-aware imagery
```

The first production goal is **not maximum cleverness**. It is to match or beat current Relevanssi relevance safely, with a cleaner architecture that can then evolve around Mavo-specific search behavior.


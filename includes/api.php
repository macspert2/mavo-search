<?php
/**
 * Stable procedural API.
 *
 * This is the contract. Nothing outside this plugin should name its tables or
 * classes; a consumer guards with function_exists() and degrades when the
 * plugin is off, per sharing-between-plugins.md:
 *
 *   if ( function_exists( 'mavo_search_result' ) ) {
 *       $hit = mavo_search_result();   // score, matched fields… of the current loop post
 *   }
 *
 * Language: wherever $lang is optional, null means Polylang's current
 * language, else fr. A search only ever returns documents of one language.
 *
 * Relevanssi's functions are deliberately not provided under its names:
 * nothing on this site calls them. docs/relevanssi-compat.md maps each to
 * its equivalent here.
 */

defined( 'ABSPATH' ) || exit;

/* --------------------------------------------------------------- search */

/**
 * Search the index.
 *
 * @param array $args {
 *     @type string|null     $lang       Default current language.
 *     @type int             $page       Default 1.
 *     @type int             $per_page   Default 10, max 100.
 *     @type string|string[] $post_types Default every indexed type.
 *     @type bool            $excerpts   Default true: contextual, highlighted excerpts.
 *     @type bool            $fallback   Default true: partial matches when nothing matches every word.
 *     @type bool            $explain    Default false: score components per result, and the parsed query.
 * }
 * @return array{query:string,lang:string,total:int,page:int,per_page:int,pages:int,fallback:string,
 *               missing_words:string[],results:array<int,array{post_id:int,score:float,matched_terms:string[],matched_fields:string[],
 *               matched_image_concepts:string[],image_alt_only:bool,places:int[],hubs:int[],reason:?array,excerpt?:string,
 *               excerpt_source?:string,debug?:array}>,parsed?:array}
 *         fallback: 'none', or 'or' when the results only match some of the words;
 *         missing_words: the query's words (as typed) found in no document at all.
 */
function mavo_search( string $query, array $args = [] ): array {
	return MVS_Engine::search( $query, $args );
}

/**
 * How a query is read: its word groups and their variants, quoted phrases,
 * and the mavo-image-index concepts it names — e.g. to offer
 * mavo_image_results_url( $concept ) beside the results.
 *
 * @return array{query:string,lang:string,normalized:string,tokens:string[],groups:array,phrases:string[],concepts:array<string,float>}
 */
function mavo_search_parse_query( string $query, ?string $lang = null ): array {
	return MVS_Query::parse( $query, $lang );
}

/* -------------------------------------------------------- current search */

/**
 * The current search's result for a post in the loop: score, matched terms
 * and fields, matched image concepts, places, hubs, excerpt, and 'reason' —
 * why it was found when its title and excerpt do not show it (see
 * MVS_Reason: guide, place, hub, photo, tag, pinned), else null. Null outside a
 * search answered by Mavo Search.
 *
 * @param int|WP_Post|null $post Default the loop's post.
 */
function mavo_search_result( $post = null ): ?array {
	$post = get_post( $post );

	return $post ? MVS_WP::hit( (int) $post->ID ) : null;
}

/**
 * The current search as a whole — total, pages, fallback ('or' = no exact
 * results, these are partial matches), missing_words (words no document has,
 * as typed), guides (hub and landing pages about the whole query, taken out
 * of the results: [ post_id, score ] each) — or null.
 */
function mavo_search_current(): ?array {
	return MVS_WP::last();
}

/**
 * The image concept a query is entirely about ("eaux turquoise" →
 * [ 'turquoise_water' ]), or [] — e.g. to offer a row of matching photos
 * beside the results, apart from them. Only an exact fit: that one concept
 * names every word of the query, so "plage lefkada" and "plage jardin" give
 * []. When several fit, the most specific wins: "bunte Häuser" is colourful
 * houses, not houses.
 *
 * @param string|null $query Default the current search.
 * @return string[] One mavo-image-index concept slug, or none.
 */
function mavo_search_image_concepts( ?string $query = null, ?string $lang = null ): array {
	$query = $query ?? ( function_exists( 'get_search_query' ) ? (string) get_search_query( false ) : '' );

	return '' === trim( $query ) ? [] : MVS_Query::exact_concepts( MVS_Query::parse( $query, $lang ) );
}

/**
 * Attributes for a result tile, so its clicks are counted: print them on
 * the element wrapping the result's link(s). '' outside a search answered by
 * Mavo Search. Already escaped.
 *
 *   <article <?php echo mavo_search_result_attributes(); ?>>
 *
 * A photo row is counted when its container has the class
 * "mavo-search-photos" and each tile carries data-post-id (mavo-image-index's
 * rows do).
 */
function mavo_search_result_attributes( $post = null ): string {
	$post = get_post( $post );
	$hit  = $post ? MVS_WP::hit( (int) $post->ID ) : null;

	if ( ! $hit ) {
		return '';
	}

	return sprintf( 'data-mavo-search-post="%d" data-mavo-search-rank="%d"', (int) $hit['post_id'], (int) $hit['rank'] );
}

/**
 * Queries worth suggesting in a language: searched often lately (and around
 * this time last year), with plenty of exact results, minus the "never
 * suggest" list and the current search. Lowercased, as visitors typed them.
 * Fewer than three: keep a hand-written fallback.
 *
 * @return string[]
 */
function mavo_search_suggestions( ?string $lang = null, int $limit = 6 ): array {
	$current = function_exists( 'get_search_query' ) ? (string) get_search_query( false ) : '';

	return MVS_Suggest::for_lang( MVS_Lang::resolve( $lang ), $limit, $current );
}

/** The search URL for a query in a language: /?s=… or /en/?s=…. */
function mavo_search_url( string $query, ?string $lang = null ): string {
	$lang = MVS_Lang::resolve( $lang );
	$base = function_exists( 'pll_home_url' ) ? (string) pll_home_url( $lang ) : home_url( '/' );

	return add_query_arg( 's', rawurlencode( $query ), $base );
}

/**
 * The image to show for a result: the post's photo best matching the image
 * concepts the query named (via mavo-image-index), else its featured image.
 * Never changes the featured image itself. 0 when there is neither.
 *
 * @param array $args Passed to mavo_image_best_match(), e.g. orientation.
 */
function mavo_search_result_image( $post = null, array $args = [] ): int {
	$post = get_post( $post );

	if ( ! $post ) {
		return 0;
	}

	$concepts = MVS_WP::hit( (int) $post->ID )['matched_image_concepts'] ?? [];

	if ( $concepts && function_exists( 'mavo_image_best_match' ) ) {
		$image = mavo_image_best_match( array_merge( [ 'orientation' => 'landscape' ], $args, [
			'post_ids'         => [ (int) $post->ID ],
			'concepts'         => $concepts,
			'concept_operator' => 'OR',
			'same_language'    => false,
		] ) );

		if ( ! empty( $image['attachment_id'] ) ) {
			return (int) $image['attachment_id'];
		}
	}

	return (int) get_post_thumbnail_id( $post );
}

/* ------------------------------------------------------------ dead ends */

/**
 * The current (or given) query with its unknown words corrected —
 * "lisbone" → "lisbonne" — or '' when nothing is misspelt or the correction
 * finds nothing either. For a page with no or only partial results.
 */
function mavo_search_did_you_mean( ?string $query = null, ?string $lang = null ): string {
	$query = $query ?? ( function_exists( 'get_search_query' ) ? (string) get_search_query( false ) : '' );

	return '' === trim( $query ) ? '' : MVS_Recover::did_you_mean( $query, MVS_Lang::resolve( $lang ) );
}

/**
 * How many exact results the same query has in each other language — for
 * "no results in English, 4 in French". Languages without any are absent.
 *
 * @return array<string,int>
 */
function mavo_search_other_languages( ?string $query = null, ?string $lang = null ): array {
	$query = $query ?? ( function_exists( 'get_search_query' ) ? (string) get_search_query( false ) : '' );

	return '' === trim( $query ) ? [] : MVS_Recover::other_languages( $query, MVS_Lang::resolve( $lang ) );
}

/**
 * Posts a broken URL was probably after, searched from its slug
 * ("/2014/05/voyage-en-crete/" → "voyage en crete"). [] when the path does
 * not look like an article (a file, wp-content/…). For the 404 page; never
 * logged as a search.
 *
 * @param string|null $path Default the current request's path.
 * @return int[]
 */
function mavo_search_for_path( ?string $path = null, ?string $lang = null, int $limit = 3 ): array {
	$path = $path ?? (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only ever split into words.

	return MVS_Recover::for_path( $path, MVS_Lang::resolve( $lang ), $limit );
}

/* --------------------------------------------------------- presentation */

/**
 * Contextual, highlighted excerpt of a post for a query. Escaped HTML.
 *
 * @param string|array $query A query, or an array of words.
 * @param array        $args  length (characters, default 450), lang
 */
function mavo_search_get_excerpt( int $post_id, $query, array $args = [] ): string {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT content, excerpt, lang FROM ' . MVS_DB::docs() . ' WHERE post_id = %d', $post_id ), ARRAY_A );

	if ( ! $row ) {
		return '';
	}

	$lang = MVS_Lang::normalize( $args['lang'] ?? null ) ?? (string) $row['lang'];
	$hl   = MVS_Query::highlight_terms( MVS_Query::parse( is_array( $query ) ? implode( ' ', $query ) : (string) $query, $lang ) );

	return MVS_Excerpt::build( (string) $row['content'], (string) $row['excerpt'], $hl, $lang, isset( $args['length'] ) ? (int) $args['length'] : null )['html'];
}

/**
 * Mark a query's words in a text or HTML: <mark class="mavo-search-highlight">.
 * Tags, attributes, URLs, scripts and entities are never touched.
 *
 * @param string|array $query A query, or an array of words.
 * @param array        $args  lang
 */
function mavo_search_highlight_terms( string $content, $query, array $args = [] ): string {
	$lang = MVS_Lang::resolve( $args['lang'] ?? null );
	$hl   = MVS_Query::highlight_terms( MVS_Query::parse( is_array( $query ) ? implode( ' ', $query ) : (string) $query, $lang ) );

	/** The query whose words are highlighted. */
	$hl = (array) apply_filters( 'mavo_search_highlight_query', $hl, $query, $lang );

	return MVS_Highlight::html( $content, $hl, $lang );
}

/** A post's title with the current search's words marked. Plain title outside a search. */
function mavo_search_get_highlighted_title( $post = null ): string {
	$post  = get_post( $post );
	$title = $post ? get_the_title( $post ) : '';
	$query = function_exists( 'get_search_query' ) ? (string) get_search_query( false ) : '';

	return '' === $query || '' === $title ? $title : mavo_search_highlight_terms( $title, $query );
}

function mavo_search_the_title( $post = null ): void {
	echo mavo_search_get_highlighted_title( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_the_title() output, text re-escaped by the highlighter.
}

/* ---------------------------------------------------------- maintenance */

/** Reindex one post now. True when it is in the index afterwards. */
function mavo_search_reindex_post( int $post_id ): bool {
	return in_array( MVS_Indexer::index( [ $post_id ], true )[ $post_id ] ?? 'removed', [ 'indexed', 'unchanged' ], true );
}

/** Remove one post from the index now. */
function mavo_search_delete_post( int $post_id ): void {
	MVS_Indexer::purge( $post_id );
}

/**
 * Something a post's document depends on changed where no hook here can see
 * it — say, a plugin moved its place by SQL. Reindexed at the end of the
 * request.
 */
function mavo_search_mark_post_stale( int $post_id ): void {
	MVS_Sync::queue_post( $post_id );
}

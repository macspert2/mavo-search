<?php
/**
 * The ?s= integration: interception rules, WP_Query fields, excerpts in
 * post_excerpt, Relevanssi stepping aside, logging without personal data.
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

for ( $i = 1; $i <= 25; $i++ ) {
	mvs_post( $i, "Article $i sur Porto", "<p>Une journée à Porto, numéro $i.</p>", [ 'date' => sprintf( '2024-01-%02d 00:00:00', $i ) ] );
}
mvs_post( 30, 'Porto en anglais', '<p>Porto</p>', [ 'lang' => 'en' ] );

MVS_WP::init();

function search_query( array $vars = [] ): WP_Query {
	return new WP_Query( $vars + [ 's' => 'porto', 'posts_per_page' => 10, 'paged' => 1 ] );
}

/* ----------------------------------------------------- before it is ready */

same( 'not ready: WordPress answers', null, apply_filters( 'posts_pre_query', null, search_query() ) );
same( 'not ready: Relevanssi keeps its answer', true, apply_filters( 'relevanssi_search_ok', true, search_query() ) );

mvs_rebuild();

/* ---------------------------------------------------------------- answers */

$q     = search_query();
$posts = apply_filters( 'posts_pre_query', null, $q );

same( 'ten WP_Post objects', [ 10, true ], [ count( $posts ), $posts[0] instanceof WP_Post ] );
same( 'found_posts', 25, $q->found_posts );
same( 'max_num_pages', 3, $q->max_num_pages );
check( 'post_excerpt holds the highlighted excerpt', str_contains( $posts[0]->post_excerpt, '<mark class="mavo-search-highlight">Porto</mark>' ), $posts[0]->post_excerpt );
same( 'equal scores: newest first', 25, $posts[0]->ID );

$q3 = search_query( [ 'paged' => 3 ] );
same( 'page 3', [ 5, 4, 3, 2, 1 ], array_map( static fn( $p ) => $p->ID, apply_filters( 'posts_pre_query', null, $q3 ) ) );

same( 'fields=ids', 10, count( array_filter( apply_filters( 'posts_pre_query', null, search_query( [ 'fields' => 'ids' ] ) ), 'is_int' ) ) );
same( 'no result: an empty list, not null', [], apply_filters( 'posts_pre_query', null, search_query( [ 's' => 'zzyzx' ] ) ) );
same( 'Relevanssi told to stand aside', false, apply_filters( 'relevanssi_search_ok', true, search_query() ) );

$GLOBALS['post'] = 25;
apply_filters( 'posts_pre_query', null, search_query() );
check( 'mavo_search_result() in the loop', 25 === ( mavo_search_result()['post_id'] ?? null ) && isset( mavo_search_result()['score'] ) );
same( 'mavo_search_current()', [ 25, 'none' ], [ mavo_search_current()['total'] ?? null, mavo_search_current()['fallback'] ?? null ] );

/* -------------------------------------------------------------- not ours */

$secondary       = search_query();
$secondary->main = false;
same( 'secondary query untouched', null, apply_filters( 'posts_pre_query', null, $secondary ) );

$archive         = search_query();
$archive->search = false;
same( 'non-search query untouched', null, apply_filters( 'posts_pre_query', null, $archive ) );

same( 'empty search untouched', null, apply_filters( 'posts_pre_query', null, search_query( [ 's' => '  ' ] ) ) );
same( 'foreign post type untouched', null, apply_filters( 'posts_pre_query', null, search_query( [ 'post_type' => 'product' ] ) ) );
check( 'indexed post type handled', is_array( apply_filters( 'posts_pre_query', null, search_query( [ 'post_type' => 'post' ] ) ) ) );

$GLOBALS['MOCK_IS_ADMIN'] = true;
same( 'admin untouched', null, apply_filters( 'posts_pre_query', null, search_query() ) );
$GLOBALS['MOCK_IS_ADMIN'] = false;

add_filter( 'mavo_search_search_ok', '__return_false' );
function __return_false() { return false; }
same( 'mavo_search_search_ok can refuse', null, apply_filters( 'posts_pre_query', null, search_query() ) );
remove_all_filters( 'mavo_search_search_ok' );

same( 'an answer already given is kept', [ 99 ], apply_filters( 'posts_pre_query', [ 99 ], search_query() ) );

$GLOBALS['MOCK_LANG'] = 'en';
$en = apply_filters( 'posts_pre_query', null, search_query() );
same( 'current language', [ 30 ], array_map( static fn( $p ) => $p->ID, $en ) );
$GLOBALS['MOCK_LANG'] = 'fr';

/* --------------------------------------------------------------- logging */

global $wpdb;
$wpdb->query( 'DELETE FROM wp_mavo_search_log' );

apply_filters( 'posts_pre_query', null, search_query( [ 's' => 'Porto' ] ) );
apply_filters( 'posts_pre_query', null, search_query( [ 's' => 'porto ' ] ) );
apply_filters( 'posts_pre_query', null, search_query( [ 's' => 'porto', 'paged' => 2 ] ) );
apply_filters( 'posts_pre_query', null, search_query( [ 's' => 'Zanzibar' ] ) );

$rows = $wpdb->get_results( 'SELECT * FROM wp_mavo_search_log ORDER BY query', ARRAY_A );
same( 'one row per query and day, page 2 not counted', [ [ 'porto', 2, 25 ], [ 'zanzibar', 1, 0 ] ], array_map( static fn( $r ) => [ $r['query'], (int) $r['searches'], (int) $r['results'] ], $rows ) );
same( 'no personal data columns', [ 'id', 'query', 'lang', 'day', 'searches', 'results', 'fallback' ], array_keys( $rows[0] ) );
same( 'zero-result report', [ 'zanzibar' ], array_column( MVS_Log::report( 'zero' ), 'query' ) );
same( 'top report', 'porto', MVS_Log::report( 'top' )[0]['query'] ?? null );
same( 'by language', 3, MVS_Log::by_language()['fr']['searches'] ?? null );

$GLOBALS['MOCK_CAN_EDIT'] = true;
apply_filters( 'posts_pre_query', null, search_query( [ 's' => 'porto' ] ) );
same( 'editors not counted', 2, (int) $wpdb->get_var( "SELECT searches FROM wp_mavo_search_log WHERE query = 'porto'" ) );
$GLOBALS['MOCK_CAN_EDIT'] = false;

update_option( MVS_Log::ENABLED_OPTION, '0' );
apply_filters( 'posts_pre_query', null, search_query( [ 's' => 'porto' ] ) );
same( 'logging can be switched off', 2, (int) $wpdb->get_var( "SELECT searches FROM wp_mavo_search_log WHERE query = 'porto'" ) );

/* ------------------------------------------------------------------ guides */

mvs_post( 40, 'Porto', '<p>Notre guide de Porto.</p>', [ 'type' => 'page' ] );
$GLOBALS['MOCK_HUBS'][40] = true;
mavo_search_reindex_post( 40 );
$GLOBALS['MOCK_LANG'] = 'fr';

$q     = search_query();
$posts = apply_filters( 'posts_pre_query', null, $q );
same( 'the hub page about the query is a guide, apart', [ 40 ], array_column( mavo_search_current()['guides'], 'post_id' ) );
check( '... not among the posts', ! in_array( 40, array_map( static fn( $p ) => $p->ID, $posts ), true ) );
same( '... nor in found_posts', 25, $q->found_posts );

add_filter( 'mavo_search_guides', static fn() => 0 );
$q = search_query();
apply_filters( 'posts_pre_query', null, $q );
same( 'filter to 0: the guide is an ordinary result', [ [], 26 ], [ mavo_search_current()['guides'], $q->found_posts ] );

done();

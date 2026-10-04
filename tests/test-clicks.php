<?php
/**
 * Click counting: what is accepted, how it is stored, the reports, the
 * script's conditions, and that nothing about the visitor is kept.
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

global $wpdb;

mvs_post( 1, 'Lisbonne en famille', '<p>Lisbonne.</p>' );
mvs_post( 2, 'Porto', '<p>Lisbonne et Porto.</p>' );
mvs_post( 3, 'London', '<p>London.</p>', [ 'lang' => 'en' ] );
mvs_post( 4, 'Brouillon', '<p>Lisbonne.</p>', [ 'status' => 'draft' ] );
mvs_rebuild();

/* -------------------------------------------------------------- recording */

check( 'a result click', MVS_Clicks::record( 'Lisbonne', 'fr', 1, 1 ) );
check( 'the same again', MVS_Clicks::record( 'lisbonne ', 'fr', 1, 3 ) );
check( 'a photo-row click', MVS_Clicks::record( 'lisbonne', 'fr', 2, 2, 'photos' ) );

$rows = $wpdb->get_results( 'SELECT * FROM wp_mavo_search_clicks ORDER BY id', ARRAY_A );
same( 'one row per query, post and source; clicks and ranks summed', [ [ 'lisbonne', 1, 'result', 2, 4 ], [ 'lisbonne', 2, 'photos', 1, 2 ] ],
	array_map( static fn( $r ) => [ $r['query'], (int) $r['post_id'], $r['source'], (int) $r['clicks'], (int) $r['rank_total'] ], $rows ) );
same( 'no visitor data columns', [ 'id', 'query', 'lang', 'day', 'post_id', 'source', 'clicks', 'rank_total' ], array_keys( $rows[0] ) );

same( 'refused: post not in the index', false, MVS_Clicks::record( 'lisbonne', 'fr', 4, 1 ) );
same( 'refused: post of another language', false, MVS_Clicks::record( 'lisbonne', 'fr', 3, 1 ) );
same( 'refused: unknown language', false, MVS_Clicks::record( 'lisbonne', 'xx', 1, 1 ) );
same( 'refused: rank 0', false, MVS_Clicks::record( 'lisbonne', 'fr', 1, 0 ) );
same( 'refused: absurd rank', false, MVS_Clicks::record( 'lisbonne', 'fr', 1, 9999 ) );
check( 'a guides-band click', MVS_Clicks::record( 'porto', 'fr', 2, 1, 'guides' ) );
$wpdb->query( "DELETE FROM wp_mavo_search_clicks WHERE source = 'guides'" );
same( 'refused: unknown source', false, MVS_Clicks::record( 'lisbonne', 'fr', 1, 1, 'ads' ) );
same( 'refused: empty query', false, MVS_Clicks::record( '  <b></b> ', 'fr', 1, 1 ) );

MVS_Best_Bets::save( MVS_Best_Bets::parse( 'lisbonne = 2' ) );
MVS_Clicks::record( 'Lisbonne', 'fr', 2, 1 );
same( 'a click on a best bet is recorded as one', 'pinned', $wpdb->get_var( "SELECT source FROM wp_mavo_search_clicks WHERE post_id = 2 AND source <> 'photos'" ) );

/* ------------------------------------------------------------------- REST */

MVS_Clicks::routes();
$route = $GLOBALS['MOCK_ROUTES']['mavo-search/v1/click'] ?? null;
same( 'route: POST, public', [ 'POST', '__return_true' ], [ $route['methods'] ?? null, $route['permission_callback'] ?? null ] );
same( 'REST: accepted', 204, MVS_Clicks::rest( [ 'q' => 'porto', 'lang' => 'fr', 'post' => 2, 'rank' => 1, 'source' => 'result' ] )->status );
same( 'REST: refused', 400, MVS_Clicks::rest( [ 'q' => 'porto', 'lang' => 'fr', 'post' => 4, 'rank' => 1, 'source' => 'result' ] )->status );

/* ---------------------------------------------------------------- reports */

MVS_Log::record( 'lisbonne', 'fr', 2 );
MVS_Log::record( 'lisbonne', 'fr', 2 );
MVS_Log::record( 'sintra', 'fr', 3 );
MVS_Log::record( 'sintra', 'fr', 3 );
MVS_Log::record( 'evora', 'fr', 1 );

$report = MVS_Clicks::report();
same( 'report: most clicked query first, with its searches', [ 'lisbonne', 2, 4 ], [ $report[0]['query'], $report[0]['searches'], $report[0]['clicks'] ] );
same( 'report: average rank clicked', 1.8, $report[0]['avg_rank'] );
same( 'report: most clicked post', [ 1, 2 ], [ $report[0]['top_post'], $report[0]['top_clicks'] ] );
same( 'never clicked, searched twice or more', [ 'sintra' ], array_column( MVS_Clicks::unclicked(), 'query' ) );
same( 'by source', [ 'photos' => 1, 'pinned' => 1, 'result' => 3 ], ( static function ( $s ) { ksort( $s ); return $s; } )( MVS_Clicks::by_source() ) );

update_option( MVS_Log::ENABLED_OPTION, '0' );
same( 'the log switch also stops clicks', false, MVS_Clicks::record( 'porto', 'fr', 2, 1 ) );
update_option( MVS_Log::ENABLED_OPTION, '1' );

$wpdb->query( "UPDATE wp_mavo_search_clicks SET day = '2001-01-01' WHERE post_id = 1" );
MVS_Clicks::prune();
same( 'old rows pruned', 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_mavo_search_clicks WHERE post_id = 1' ) );

/* ------------------------------------------------------- page integration */

$GLOBALS['MOCK_IS_SEARCH'] = true;
MVS_WP::enqueue();
check( 'no script before a search was answered', ! isset( $GLOBALS['MOCK_LOCALIZED']['MAVO_SEARCH_CLICKS'] ) );

$GLOBALS['post'] = 1;
MVS_WP::pre_query( null, new WP_Query( [ 's' => 'Lisbonne', 'posts_per_page' => 10, 'paged' => 1 ] ) );
same( 'tile attributes', 'data-mavo-search-post="1" data-mavo-search-rank="2"', mavo_search_result_attributes() );
same( 'no attributes for a post not in the results', '', mavo_search_result_attributes( 3 ) );

MVS_WP::enqueue();
same( 'script told the query and language', [ 'https://example.test/wp-json/mavo-search/v1/click', 'Lisbonne', 'fr' ],
	array_values( $GLOBALS['MOCK_LOCALIZED']['MAVO_SEARCH_CLICKS'] ?? [] ) );

unset( $GLOBALS['MOCK_LOCALIZED'] );
$GLOBALS['MOCK_CAN_EDIT'] = true;
MVS_WP::enqueue();
check( 'not for editors', ! isset( $GLOBALS['MOCK_LOCALIZED']['MAVO_SEARCH_CLICKS'] ) );

done();

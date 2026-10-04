<?php
/**
 * Best bets: parsing, matching, language, order, and their place in results.
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

mvs_post( 1, 'Londres en famille', '<p>Londres.</p>' );
mvs_post( 2, 'Où dormir à Londres', '<p>Londres, hôtels.</p>' );
mvs_post( 3, 'Nos plus beaux parcs', '<p>Hyde Park, Regent’s Park.</p>' );          // never says Londres
mvs_post( 4, 'London with kids', '<p>London.</p>', [ 'lang' => 'en' ] );
mvs_post( 5, 'Brouillon', '<p>Londres.</p>', [ 'status' => 'draft' ] );
mvs_post( 6, 'Une page', '<p>Londres.</p>', [ 'type' => 'page' ] );
mvs_rebuild();

/* ------------------------------------------------------------------ parse */

$bets = MVS_Best_Bets::parse( "Londres = 3, 1\n  londres famille=2 ; 2\nno equals sign\n = 4\nzanzibar = abc\nLondres ! = 4, 5" );
same( 'lines parsed, junk dropped', [ 'Londres', 'londres famille', 'Londres !' ], array_column( $bets, 'query' ) );
same( 'IDs: order kept, duplicates dropped', [ [ 3, 1 ], [ 2 ], [ 4, 5 ] ], array_column( $bets, 'posts' ) );
same( 'normalized keys', [ 'londres', 'londres famille', 'londres' ], array_column( $bets, 'norm' ) );
same( 'round trip', "Londres = 3, 1\nlondres famille = 2\nLondres ! = 4, 5", MVS_Best_Bets::to_text( $bets ) );

MVS_Best_Bets::save( $bets );

/* --------------------------------------------------------------- matching */

$r = mavo_search( 'LONDRES', [ 'excerpts' => false ] );
same( 'pinned first in the order written, then the ranking', [ 3, 1, 2, 6 ], array_column( $r['results'], 'post_id' ) );
same( 'pinned flagged', [ true, true, false, false ], array_column( $r['results'], 'pinned' ) );
same( 'ranks are positions', [ 1, 2, 3, 4 ], array_column( $r['results'], 'rank' ) );
same( 'a pinned post the query never found has no score', 0.0, $r['results'][0]['score'] );
same( 'total counts it', 4, $r['total'] );

same( 'word for word: not for a longer query', 2, ids( 'londres famille' )[0] ?? null );
same( '... whose own line applies', [ true, false ], array_slice( array_column( mavo_search( 'londres famille' )['results'], 'pinned' ), 0, 2 ) );
same( 'only in its own language; drafts never', [ 4 ], array_column( array_filter( mavo_search( 'londres', [ 'lang' => 'en' ] )['results'], static fn( $h ) => $h['pinned'] ), 'post_id' ) );
same( 'post types respected', [ 6 ], ids( 'londres', [ 'post_types' => 'page' ] ) );
same( 'page 2 continues after the pins', [ 2 ], ids( 'londres', [ 'per_page' => 1, 'page' => 3 ] ) );

$explain = mavo_search( 'londres', [ 'explain' => true, 'per_page' => 1 ] );
check( 'explained as a best bet', in_array( 'best bet', array_column( $explain['results'][0]['debug'], 'what' ), true ) );

/* ------------------------------------------------- a pin answers the query */

MVS_Best_Bets::save( MVS_Best_Bets::parse( 'parcs londres = 3' ) );
$r = mavo_search( 'parcs londres' );
same( 'a pin for a query nothing matches fully', 3, $r['results'][0]['post_id'] ?? null );

MVS_Best_Bets::save( MVS_Best_Bets::parse( 'zanzibar = 3' ) );
$r = mavo_search( 'zanzibar' );
same( 'a pin where nothing matches at all', [ [ 3 ], 'none' ], [ array_column( $r['results'], 'post_id' ), $r['fallback'] ] );

add_filter( 'mavo_search_best_bets', static fn() => [] );
MVS_Cache::bump();
same( 'filter can clear them', [], ids( 'zanzibar' ) );

done();

<?php
/**
 * Suggestions from the search log: thresholds, season, spelling variants,
 * the "never suggest" list, the current search, and the search URL.
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

global $wpdb;

/** A log row: $days ago, $searches times, $results results. */
function logged( string $query, int $searches, int $results, int $days_ago = 1, string $lang = 'fr', string $fallback = '' ): void {
	global $wpdb;
	$wpdb->insert( 'wp_mavo_search_log', [
		'query'    => $query,
		'lang'     => $lang,
		'day'      => gmdate( 'Y-m-d', time() - $days_ago * DAY_IN_SECONDS ),
		'searches' => $searches,
		'results'  => $results,
		'fallback' => $fallback,
	] );
}

logged( 'londres', 40, 30 );
logged( 'londres', 5, 30, 10 );
logged( 'lisbonne', 20, 12 );
logged( 'édimbourg', 9, 8 );
logged( 'edimbourg', 4, 8 );            // same words, fewer searches: dropped
logged( 'porto', 2, 10 );               // too few searches
logged( 'zanzibar', 30, 0 );            // no results
logged( 'londres zanzibar', 12, 9, 1, 'fr', 'or' );   // only partial matches
logged( 'sintra', 8, 3 );               // too few results
logged( 'madère', 50, 20, 90 );         // too long ago
logged( 'x', 50, 20 );                  // too short
logged( 'toussaint en famille', 6, 15, 366 );   // last year, this season
logged( 'noël', 60, 15, 300 );                   // last year, another season
logged( 'london', 30, 10, 1, 'en' );    // another language
logged( 'idiot trip', 10, 10 );

same( 'proven searches, seasonal first, most searched next', [ 'toussaint en famille', 'londres', 'lisbonne', 'idiot trip', 'édimbourg' ], MVS_Suggest::for_lang( 'fr', 10 ) );
same( 'per language', [ 'london' ], MVS_Suggest::for_lang( 'en' ) );
same( 'limit', [ 'toussaint en famille', 'londres' ], MVS_Suggest::for_lang( 'fr', 2 ) );

MVS_Suggest::save_blocked( "idiot\n\n  " );
same( 'never suggest: a word blocks every query containing it', [ 'toussaint en famille', 'londres', 'lisbonne', 'édimbourg' ], MVS_Suggest::for_lang( 'fr', 10 ) );
same( 'stored cleanly', [ 'idiot' ], MVS_Suggest::blocked() );

$GLOBALS['MOCK_SEARCH'] = 'Londres';
same( 'the current search is not suggested to itself', [ 'toussaint en famille', 'lisbonne', 'édimbourg' ], mavo_search_suggestions( 'fr' ) );
$GLOBALS['MOCK_SEARCH'] = '';

$before = $wpdb->num_queries;
MVS_Suggest::for_lang( 'fr' );
same( 'cached for the day', 0, $wpdb->num_queries - $before );

add_filter( 'mavo_search_suggestions', static fn( $list ) => array_merge( [ 'road trip' ], $list ) );
same( 'filter', 'road trip', MVS_Suggest::for_lang( 'fr' )[0] );

same( 'search URL, encoded', 'https://example.test/?s=' . rawurlencode( 'où dormir' ), mavo_search_url( 'où dormir', 'fr' ) );

done();

<?php
/**
 * Not a test: indexing and query cost at the live site's scale (1,600
 * documents, Relevanssi held ~460,000 term rows), against SQLite.
 *
 *   php tests/bench.php [documents]
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

$n = (int) ( $argv[1] ?? 1600 );
mt_srand( 42 );

$vocab = [];
for ( $i = 0; $i < 6000; $i++ ) {
	$vocab[] = substr( md5( (string) $i ), 0, 4 + $i % 6 );
}
$vocab = array_merge( [ 'londres', 'famille', 'plage', 'enfants', 'grece', 'randonnee', 'jardin' ], $vocab );

function zipf_word( array $vocab ): string {
	$r = mt_rand() / mt_getrandmax();
	return $vocab[ (int) floor( count( $vocab ) * $r ** 3 ) ];
}

$t = microtime( true );
for ( $id = 1; $id <= $n; $id++ ) {
	$words = [];
	for ( $w = 0; $w < 900; $w++ ) {
		$words[] = zipf_word( $vocab );
	}
	$paras = implode( '</p><p>', array_map( static fn( $c ) => implode( ' ', $c ), array_chunk( $words, 60 ) ) );
	mvs_post( $id, ucfirst( zipf_word( $vocab ) ) . ' ' . zipf_word( $vocab ) . ' en famille', "<p>$paras</p>", [ 'lang' => [ 'fr', 'fr', 'en', 'de' ][ $id % 4 ] ] );
	$GLOBALS['MOCK_CHAINS'][ $id ] = [ [ 'continent', 'Europe', 900 ], [ 'country', 'Grèce', 906 ], [ 'city', 'Ville' . ( $id % 50 ), 1000 + $id % 50 ] ];
}
printf( "fixtures: %.1fs\n", microtime( true ) - $t );

global $wpdb;
$t = microtime( true );
mvs_rebuild();
printf( "full rebuild of %d posts: %.1fs (%.1f ms/post)\n", $n, microtime( true ) - $t, ( microtime( true ) - $t ) * 1000 / $n );

$s = MVS_Status::summary();
printf( "term rows: %d, distinct terms: %d\n", $s['term_rows'], $s['distinct_terms'] );

foreach ( [ 'londres', 'londres famille', 'plage grece enfants', 'où dormir à londres', '"londres famille"', 'zzzz qqqq', 'fami' ] as $q ) {
	MVS_Cache::bump();
	$before = $wpdb->num_queries;
	$t      = microtime( true );
	$r      = mavo_search( $q, [ 'lang' => 'fr' ] );
	printf( "%-24s %5d results  %6.1f ms  %d queries  fallback %s\n", $q, $r['total'], ( microtime( true ) - $t ) * 1000, $wpdb->num_queries - $before, $r['fallback'] );
}

<?php
/**
 * Dead ends: "did you mean", other languages, and searching a 404's path.
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

global $wpdb;

mvs_post( 1, 'Lisbonne en famille', '<p>Le tram 28 et les miradouros.</p>' );
mvs_post( 2, 'Voyage en Crète avec des enfants', '<p>Dix jours en Crète.</p>' );
mvs_post( 3, 'Lisboa with kids', '<p>Lisbon trams.</p>', [ 'lang' => 'en' ] );
mvs_post( 4, 'Randonnée à Madère', '<p>Levadas.</p>' );
mvs_post( 5, 'Une liste de lisières', '<p>Lisières et lisseurs.</p>' );   // near misses for "lisbone"
mvs_post( 6, 'Crète, Crete, Crêtes', '<p>Crète.</p>', [ 'lang' => 'de' ] );
mvs_rebuild();

/* ------------------------------------------------------------ did you mean */

same( 'one letter missing', 'lisbonne', mavo_search_did_you_mean( 'lisbone', 'fr' ) );
same( 'a letter too many in a long word', 'randonnee', mavo_search_did_you_mean( 'randonnnee', 'fr' ) );
same( 'only the unknown word is replaced', 'tram lisbonne', mavo_search_did_you_mean( 'tram lisbone', 'fr' ) );
same( 'the visitor’s other words keep their spelling', 'Voyage crete', mavo_search_did_you_mean( 'Voyage cretw', 'fr' ) );
same( 'nothing misspelt: nothing', '', mavo_search_did_you_mean( 'lisbonne', 'fr' ) );
same( 'nothing close: nothing', '', mavo_search_did_you_mean( 'zanzibar', 'fr' ) );
same( 'short words are not guessed', '', mavo_search_did_you_mean( 'lis', 'fr' ) );
same( 'other language’s words do not count', '', mavo_search_did_you_mean( 'lisbo', 'fr' ) );
same( 'quoted phrases are left alone', '', mavo_search_did_you_mean( '"lisbone tram"', 'fr' ) );
same( 'current search by default', 'lisbonne', ( static function () { $GLOBALS['MOCK_SEARCH'] = 'lisbone'; $r = mavo_search_did_you_mean( null, 'fr' ); $GLOBALS['MOCK_SEARCH'] = ''; return $r; } )() );

$before = $wpdb->get_var( 'SELECT COUNT(*) FROM wp_mavo_search_log' );
mavo_search_did_you_mean( 'lisbone', 'fr' );
same( 'nothing logged', $before, $wpdb->get_var( 'SELECT COUNT(*) FROM wp_mavo_search_log' ) );

/* --------------------------------------------------------- other languages */

same( 'other languages with results', [ 'fr' => 1, 'de' => 1 ], mavo_search_other_languages( 'crete', 'en' ) );
same( 'none elsewhere', [], mavo_search_other_languages( 'madere', 'fr' ) );
same( 'the current language is never listed', [ 'en' => 1 ], mavo_search_other_languages( 'lisboa', 'fr' ) );

/* ------------------------------------------------------------------- paths */

same( 'slug to words', 'voyage en crete avec des enfants', MVS_Recover::path_query( '/voyage-en-crete-avec-des-enfants/' ) );
same( 'dates, language prefix, .html dropped', 'voyage crete', MVS_Recover::path_query( '/en/2014/05/voyage_crete.html' ) );
same( 'the last two segments, last first', 'madere randonnee', MVS_Recover::path_query( '/randonnee/madere/' ) );
same( 'tag and page segments dropped', 'crete', MVS_Recover::path_query( '/tag/crete/page/2/' ) );
same( 'encoded accents', 'crete', MVS_Recover::path_query( '/cr%C3%A8te/' ) );
foreach ( [ '/wp-content/plugins/revslider/readme.txt', '/.env', '/x/.git/config', '/wp-admin/x', '/backup.sql', '/image.jpg', '/a/b/c/d/e/f/g/', '/', '/12/34/', '/ab/', '/wp-json/x' ] as $path ) {
	same( "not article-shaped: $path", '', MVS_Recover::path_query( $path ) );
}

same( 'a 404 path finds the article', [ 2 ], mavo_search_for_path( '/2014/05/voyage-en-crete/', 'fr' ) );
same( '... in the page’s language', [ 6 ], mavo_search_for_path( '/de/crete/', 'de' ) );
same( 'a file path finds nothing', [], mavo_search_for_path( '/wp-content/uploads/crete.jpg', 'fr' ) );
same( 'limit', 1, count( mavo_search_for_path( '/lisbonne-tram/', 'fr', 1 ) ) );
$_SERVER['REQUEST_URI'] = '/lisbonne-en-famille-old/?utm_source=x';
same( 'current request by default, query string ignored', [ 1 ], mavo_search_for_path( null, 'fr' ) );

done();

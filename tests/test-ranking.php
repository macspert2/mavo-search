<?php
/**
 * Ranking against a small corpus shaped like the site: the representative
 * queries of agent.md, plus language isolation, spelling variants, fallback,
 * phrases and explanation.
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

$filler = str_repeat( 'Une journée tranquille, un musée, un parc et un goûter. ', 20 );

// Places: [ level, name, term_id ], continent first.
$london    = [ [ 'continent', 'Europe', 900 ], [ 'country', 'Royaume-Uni', 901 ], [ 'region', 'Angleterre', 902 ], [ 'city', 'Londres', 903 ] ];
$edinburgh = [ [ 'continent', 'Europe', 900 ], [ 'country', 'Royaume-Uni', 901 ], [ 'region', 'Écosse', 904 ], [ 'city', 'Édimbourg', 905 ] ];
$lefkada   = [ [ 'continent', 'Europe', 900 ], [ 'country', 'Grèce', 906 ], [ 'region', 'Îles Ioniennes', 907 ], [ 'city', 'Lefkada', 908 ] ];
$santorini = [ [ 'continent', 'Europe', 900 ], [ 'country', 'Grèce', 906 ], [ 'city', 'Santorin', 909 ] ];
$madeira   = [ [ 'continent', 'Europe', 900 ], [ 'country', 'Portugal', 910 ], [ 'region', 'Madère', 911 ] ];

mvs_post( 1, 'Londres en famille : le guide', "<p>Tout pour visiter Londres avec des enfants.</p><p>Londres change vite.</p>", [ 'type' => 'page' ] );
mvs_post( 2, 'Où dormir à Londres avec des enfants', '<p>Nos hôtels préférés à Londres, quartier par quartier.</p>' );
mvs_post( 3, 'Notre week-end à Bath', '<p>' . str_repeat( 'Depuis Londres, le train. Londres encore. ', 15 ) . $filler . '</p>' );
mvs_post( 4, 'Harry Potter à Édimbourg', '<p>Sur les traces du sorcier dans la vieille ville.</p>' );
mvs_post( 5, 'Édimbourg en famille', '<p>Le château, Arthur’s Seat, et un café Harry Potter.</p>' );
mvs_post( 6, 'Plages de Lefkada', '<p>Nos criques préférées de l’île.</p>' );
mvs_post( 7, 'Randonnée à Madère', '<p>Levadas et forêt laurifère.</p>' );
mvs_post( 8, 'Santorin avec des ados', '<p>Couchers de soleil à Oia.</p>' );
mvs_post( 9, 'London with kids', '<p>Everything about London with children.</p>', [ 'lang' => 'en' ] );
mvs_post( 10, 'Zürich mit Kindern', '<p>Ein Tag in Zürich am See.</p>', [ 'lang' => 'de' ] );
mvs_post( 12, 'Notre voyage en Crète', '<p>Dix jours dans l’ouest de l’île.</p>' );
mvs_post( 13, 'Brouillon sur Londres', '<p>Londres</p>', [ 'status' => 'draft' ] );
mvs_post( 14, 'Londres noindex', '<p>Londres</p>' );
mvs_meta( 14, '_yoast_wpseo_meta-robots-noindex', '1' );
mvs_post( 15, 'Le Poudlard Express', '<p>Le train à vapeur des Highlands.</p>' );
mvs_post( 16, 'Escapade à Bath', '<p>Thermes romains et maisons georgiennes. Le week-end à Bath idéal.</p>' );
mvs_post( 50, 'Harry Potter', '<p>Tous nos articles sur les lieux du film.</p>', [ 'type' => 'page' ] );

$GLOBALS['MOCK_CHAINS'] = [ 1 => $london, 2 => $london, 3 => [ [ 'continent', 'Europe', 900 ], [ 'country', 'Royaume-Uni', 901 ], [ 'region', 'Angleterre', 902 ], [ 'city', 'Bath', 912 ] ],
	4 => $edinburgh, 5 => $edinburgh, 6 => $lefkada, 7 => $madeira, 8 => $santorini, 15 => $edinburgh ];
$GLOBALS['MOCK_HUBS']      = [ 50 => true, 1 => true ];
$GLOBALS['MOCK_POST_HUBS'] = [ 4 => [ 50 ], 15 => [ 50 ] ];
$GLOBALS['MOCK_IMAGES']    = [
	6  => [ [ 'id' => 600, 'alt' => [ 'fr' => 'Plage aux eaux turquoise', 'en' => 'Turquoise beach' ], 'concepts' => [ 'beach' => [ 'Plage', 1.0 ], 'turquoise_water' => [ 'Eaux turquoise', 1.0 ] ] ] ],
	7  => [ [ 'id' => 700, 'alt' => [ 'fr' => 'Le jardin botanique de Funchal' ], 'concepts' => [ 'garden' => [ 'Jardin', 1.0 ] ] ] ],
	12 => [ [ 'id' => 1200, 'alt' => [ 'fr' => 'Le phare de Chania', 'en' => 'Lighthouse in Chania' ] ] ],
];

add_filter( 'mavo_search_guide_places', static fn( $ids, $post_id ) => 1 === $post_id ? [ mvs_term( 'Londres' ) ] : $ids, 10, 2 );

mvs_rebuild();

/* ------------------------------------------------------- language, status */

check( 'index ready after a full rebuild', MVS_WP::ready() );
same( 'drafts and noindex never indexed', [ 1, 2, 3 ], array_values( array_intersect( [ 1, 2, 3, 13, 14 ], ids( 'londres' ) ) ) );
check( 'fr search returns no en/de documents', ! array_intersect( [ 9, 10 ], ids( 'london' ) ) );
same( 'en search, en documents only', [ 9 ], ids( 'london', [ 'lang' => 'en' ] ) );
same( 'de search, umlaut typed', [ 10 ], ids( 'zürich', [ 'lang' => 'de' ] ) );
same( 'de search, ue spelling', [ 10 ], ids( 'zuerich', [ 'lang' => 'de' ] ) );
same( 'de search, umlaut dropped', [ 10 ], ids( 'zurich', [ 'lang' => 'de' ] ) );

$GLOBALS['MOCK_LANG'] = 'en';
same( 'current language decides by default', [ 9 ], ids( 'london' ) );
$GLOBALS['MOCK_LANG'] = 'fr';

/* --------------------------------------------------- representative queries */

$londres = ids( 'londres' );
same( 'londres: the guide first', 1, $londres[0] ?? null );
check( 'londres: concise guide above the long article repeating Londres', array_search( 1, $londres, true ) < array_search( 3, $londres, true ) );
same( 'où dormir à Londres: the accommodation guide first', 2, ids( 'où dormir à Londres' )[0] ?? null );
same( 'without accents too', 2, ids( 'ou dormir a londres' )[0] ?? null );

same( 'edimbourg finds Édimbourg', [ 4, 5, 15 ], ( static function ( $a ) { sort( $a ); return $a; } )( ids( 'edimbourg' ) ) );

$hp = ids( 'harry potter edimbourg' );
same( 'harry potter edimbourg: the Harry Potter article first', 4, $hp[0] ?? null );
check( 'harry potter edimbourg: the hub page itself is not in Édimbourg', ! in_array( 50, $hp, true ) );
check( 'harry potter edimbourg: hub member without the words is found', in_array( 15, $hp, true ) );

$hp = ids( 'harry potter' );
check( 'hub membership helps but does not beat titles', array_search( 15, $hp, true ) > array_search( 4, $hp, true ) && array_search( 15, $hp, true ) > array_search( 50, $hp, true ), $hp );

same( 'grèce: posts under Greece through the place tree', [ 6, 8 ], ( static function ( $a ) { sort( $a ); return $a; } )( ids( 'grèce' ) ) );
same( 'grece without accent', ids( 'grèce' ), ids( 'grece' ) );

same( 'plage lefkada', 6, ids( 'plage lefkada' )[0] ?? null );
same( 'leucade: synonym', [ 6 ], ids( 'leucade' ) );
same( 'plages → plage and back', ids( 'plage' ), ids( 'plages' ) );

$turquoise = mavo_search( 'eaux turquoise' );
same( 'eaux turquoise: image alt and concept', [ 6 ], array_column( $turquoise['results'], 'post_id' ) );
same( 'eaux turquoise: matched concept reported', [ 'turquoise_water' ], $turquoise['results'][0]['matched_image_concepts'] ?? null );
same( 'parse exposes the concept for the /images/ bridge', [ 'turquoise_water' ], array_keys( mavo_search_parse_query( 'eaux turquoise' )['concepts'] ) );

same( 'exact fit: the query is the concept', [ 'turquoise_water' ], mavo_search_image_concepts( 'Eaux turquoise' ) );
same( 'exact fit: one word', [ 'beach' ], mavo_search_image_concepts( 'plage' ) );
same( 'exact fit: plural, stopwords ignored', [ 'beach' ], mavo_search_image_concepts( 'les plages' ) );
same( 'two concepts side by side: no exact fit', [], mavo_search_image_concepts( 'plage jardin' ) );
same( 'the concept naming more words wins over one inside it', [ 'colourful_houses' ], mavo_search_image_concepts( 'maisons colorées' ) );
same( '... whatever the order the matcher reports them in', [ 'colourful_houses' ], ( static function () {
	$GLOBALS['MOCK_MATCHER']['fr'] = [ 'maisons' => 'house' ] + $GLOBALS['MOCK_MATCHER']['fr'];
	MVS_Query::reset();
	return mavo_search_image_concepts( 'maisons colorées' );
} )() );
same( 'one word, one concept', [ 'house' ], mavo_search_image_concepts( 'maisons' ) );
same( 'not exact: a place besides the concept', [], mavo_search_image_concepts( 'plage lefkada' ) );
same( 'not exact: no concept', [], mavo_search_image_concepts( 'londres' ) );
same( 'not exact: a quoted phrase is literal', [], mavo_search_image_concepts( '"eaux turquoise"' ) );
same( 'current search by default', [ 'garden' ], ( static function () { $GLOBALS['MOCK_SEARCH'] = 'jardins'; $c = mavo_search_image_concepts(); $GLOBALS['MOCK_SEARCH'] = ''; return $c; } )() );
same( 'madere jardin: place + image concept', [ 7 ], ids( 'madere jardin' ) );
same( 'forêt without accent', [ 7 ], ids( 'foret' ) );
same( 'phare: found only in the image alt text', [ 12 ], ids( 'phare' ) );
check( 'alt-only match flagged', true === ( mavo_search( 'phare' )['results'][0]['image_alt_only'] ?? null ) );
same( 'alt text of another language does not count', [], ids( 'lighthouse' ) );

/* ----------------------------------------------------- phrases, fallback */

$bath = ids( '"week-end à Bath"' );
same( 'quoted phrase required', [ 3, 16 ], ( static function ( $a ) { sort( $a ); return $a; } )( $bath ) );
same( 'quoted phrase in title ranks first', 3, $bath[0] ?? null );

$or = mavo_search( 'londres zanzibar', [ 'excerpts' => false ] );
same( 'no document has both words: fallback', 'or', $or['fallback'] );
check( 'fallback still finds Londres', in_array( 1, array_column( $or['results'], 'post_id' ), true ) );
same( 'fallback names the word found nowhere, as typed', [ 'Zanzibar' ], mavo_search( 'londres Zanzibar', [ 'excerpts' => false ] )['missing_words'] );
same( 'words all known but never together: none missing', [], mavo_search( 'lefkada santorin', [ 'excerpts' => false ] )['missing_words'] );
same( '... and still a fallback', 'or', mavo_search( 'lefkada santorin', [ 'excerpts' => false ] )['fallback'] );
same( 'exact match: nothing missing', [], mavo_search( 'londres' )['missing_words'] );
same( 'no result at all: every word missing', [ 'zzyzx' ], mavo_search( 'zzyzx' )['missing_words'] );
same( 'no fallback when asked not to', 0, mavo_search( 'londres zanzibar', [ 'fallback' => false ] )['total'] );
same( 'exact match: fallback none', 'none', mavo_search( 'londres' )['fallback'] );
same( 'only stopwords: nothing', 0, mavo_search( 'le la les' )['total'] );
same( 'unknown word: nothing', 0, mavo_search( 'zzyzx' )['total'] );

/* -------------------------------------------------------- paging, explain */

$all  = ids( 'londres' );
$page = mavo_search( 'londres', [ 'per_page' => 1, 'page' => 2, 'excerpts' => false ] );
same( 'page 2 of 1', [ $all[1] ], array_column( $page['results'], 'post_id' ) );
same( 'total and pages', [ count( $all ), count( $all ) ], [ $page['total'], $page['pages'] ] );

$explained = mavo_search( 'londres', [ 'explain' => true, 'per_page' => 1 ] );
$what      = array_column( $explained['results'][0]['debug'], 'what' );
check( 'explain names the title term', in_array( 'title: londres', $what, true ), $what );
check( 'explain names the guide field', in_array( 'guide: londres', $what, true ), $what );
check( 'explain names the place', (bool) preg_grep( '/^place: londres/', $what ), $what );
check( 'explain shows the hub page boost', in_array( 'document boost', $what, true ), $what );
check( 'explain returns the parsed query', isset( $explained['parsed']['groups'][0]['variants']['londres'] ) );
check( 'no debug without explain', ! isset( mavo_search( 'londres' )['results'][0]['debug'] ) );

$hit = mavo_search( 'londres' )['results'][0];
check( 'results carry places and hubs for later facets', in_array( 903, $hit['places'], true ) );
check( 'matched fields', in_array( 'title', $hit['matched_fields'], true ) && in_array( 'place', $hit['matched_fields'], true ) );

/* -------------------------------------------------------------- filters */

add_filter( 'mavo_search_results', static function ( $scores ) {
	unset( $scores[1] );
	return $scores;
} );
MVS_Cache::bump();
check( 'mavo_search_results can remove a post', ! in_array( 1, ids( 'londres' ), true ) );
remove_all_filters( 'mavo_search_results' );

add_filter( 'mavo_search_field_weights', static fn( $w ) => [ 'place' => 0 ] + $w );
MVS_Engine::reset();
MVS_Cache::bump();
same( 'a field weighted 0 no longer matches', [], ids( 'grèce' ) );
remove_all_filters( 'mavo_search_field_weights' );
MVS_Engine::reset();
MVS_Cache::bump();

/* ------------------------------------------------------- post types, cache */

same( 'post_types restricts', [ 1 ], ids( 'londres', [ 'post_types' => 'page' ] ) );

global $wpdb;
mavo_search( 'santorin' );
$before = $wpdb->num_queries;
mavo_search( 'santorin', [ 'page' => 1 ] );
same( 'second identical search ranks from cache (excerpt query only)', 1, $wpdb->num_queries - $before );

/* ------------------------------------------------- query-aware thumbnails */

mvs_meta( 6, '_thumbnail_id', 601 );
MVS_WP::pre_query( null, new WP_Query( [ 's' => 'plage lefkada', 'posts_per_page' => 10, 'paged' => 1 ] ) );
same( 'result image: the photo matching the query', 600, mavo_search_result_image( 6 ) );
MVS_WP::pre_query( null, new WP_Query( [ 's' => 'lefkada', 'posts_per_page' => 10, 'paged' => 1 ] ) );
same( 'result image: no concept in the query, the featured image', 601, mavo_search_result_image( 6 ) );

/* ------------------------------------------------------ why this matched */

mvs_post( 17, 'Une semaine tranquille', '<p>Rien de particulier.</p>', [ 'tags' => [ 'Patrimoine mondial' ] ] );
mvs_post( 18, 'Notre page anglaise', '<p>Tout sur nos voyages outre-Manche.</p>', [ 'type' => 'page' ] );
$GLOBALS['MOCK_IMAGES'][17] = [ [ 'id' => 1700, 'alt' => [ 'fr' => 'Fleurs de Funchal' ], 'concepts' => [ 'garden' => [ 'Jardin', 1.0 ] ] ] ];
add_filter( 'mavo_search_guide_places', static fn( $ids, $post_id ) => 18 === $post_id ? [ mvs_term( 'Londres' ) ] : $ids, 10, 2 );
foreach ( [ 17, 18 ] as $id ) {
	mavo_search_reindex_post( $id );
}

function reason_of( string $query, int $post_id ): ?array {
	foreach ( mavo_search( $query, [ 'per_page' => 50 ] )['results'] as $hit ) {
		if ( $hit['post_id'] === $post_id ) {
			return $hit['reason'];
		}
	}
	return [ 'not found' ];
}

same( 'visible in the title: no reason', null, reason_of( 'lefkada', 6 ) );
same( 'visible in the excerpt: no reason', null, reason_of( 'oia', 8 ) );
same( 'a wider place: the article’s own, and the one matched', [ 'kind' => 'place', 'name' => 'Lefkada', 'within' => 'Grèce' ], reason_of( 'grèce', 6 ) );
same( '... without accents too', [ 'kind' => 'place', 'name' => 'Santorin', 'within' => 'Grèce' ], reason_of( 'grece', 8 ) );
same( 'its own place, not in its title', [ 'kind' => 'place', 'name' => 'Édimbourg', 'within' => '' ], reason_of( 'edimbourg', 15 ) );
same( 'a hub', [ 'kind' => 'hub', 'name' => 'Harry Potter' ], reason_of( 'harry potter', 15 ) );
same( 'a photo, quoted', [ 'kind' => 'photo', 'text' => 'Le phare de Chania', 'concept' => '' ], reason_of( 'phare', 12 ) );
same( 'a photo by concept only', [ 'kind' => 'photo', 'text' => '', 'concept' => 'Jardin' ], reason_of( 'jardin', 17 ) );
same( 'a tag', [ 'kind' => 'tag', 'name' => 'Patrimoine mondial' ], reason_of( 'patrimoine', 17 ) );
same( 'a guide page', [ 'kind' => 'guide', 'name' => 'Londres' ], reason_of( 'londres', 18 ) );
same( 'the guide named in its title: no reason', null, reason_of( 'londres', 1 ) );

MVS_Best_Bets::save( MVS_Best_Bets::parse( 'zanzibar = 17' ) );
same( 'a best bet nothing else explains', [ 'kind' => 'pinned' ], reason_of( 'zanzibar', 17 ) );
MVS_Best_Bets::save( [] );

done();

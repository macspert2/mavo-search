<?php
/**
 * Incremental indexing, staleness, removal, and the rebuild modes.
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

global $wpdb;

MVS_Sync::init();

mvs_post( 1, 'Lisbonne en famille', '<p>Le tram 28.</p>' );
mvs_post( 2, 'Porto en famille', '<p>Les caves.</p>' );
mvs_post( 3, 'Sintra', '<p>Les palais.</p>' );
mvs_post( 50, 'City trips', '<p>Nos villes.</p>', [ 'type' => 'page' ] );
$GLOBALS['MOCK_HUBS'] = [ 50 => true ];

mvs_rebuild();

function indexed_at( int $post_id ): ?string {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SELECT source_hash FROM wp_mavo_search_docs WHERE post_id = %d', $post_id ) );
}

function rows_for( int $post_id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM wp_mavo_search_terms t JOIN wp_mavo_search_docs d ON d.doc_id = t.doc_id WHERE d.post_id = %d', $post_id ) );
}

function indexed_actions(): array {
	return array_values( array_map( static fn( $a ) => $a[1], array_filter( $GLOBALS['MOCK_ACTIONS'], static fn( $a ) => 'mavo_search_document_indexed' === $a[0] ) ) );
}

$summary = MVS_Status::summary();
same( 'all current after a full rebuild', [ 4, 4, 0, 0, 0, 0 ], [ $summary['eligible'], $summary['current'], $summary['stale'], $summary['missing'], $summary['failed'], $summary['orphans'] ] );
same( 'documents by language', [ 'fr' => 4 ], $summary['by_lang'] );
check( 'rows by field counted', $summary['rows_by_field']['title'] > 0 && $summary['term_rows'] > 0 );

/* ------------------------------------------------------------ a post save */

$GLOBALS['MOCK_ACTIONS'] = [];
$hash3 = indexed_at( 3 );
mvs_post( 1, 'Lisbonne en famille', '<p>Le tram 28 et Belém.</p>', [ 'modified' => '2024-02-01 00:00:00' ] );

same( 'edited post is stale until processed', 1, MVS_Status::summary()['stale'] );

do_action( 'save_post', 1, get_post( 1 ) );
MVS_Sync::flush();

same( 'only the edited post reindexed', [ 1 ], indexed_actions() );
same( 'other documents untouched', $hash3, indexed_at( 3 ) );
same( 'new word found', [ 1 ], ids( 'belem' ) );
same( 'current again', 0, MVS_Status::summary()['stale'] );

/* ---------------------------------------------------- unchanged is cheap */

MVS_Sync::reset();
$GLOBALS['MOCK_ACTIONS'] = [];
do_action( 'save_post', 2, get_post( 2 ) );
MVS_Sync::flush();
same( 'saving without a change rewrites nothing', [], indexed_actions() );

/* ------------------------------------------------- hub membership changes */

MVS_Sync::reset();
$GLOBALS['MOCK_ACTIONS']   = [];
$GLOBALS['MOCK_POST_HUBS'] = [ 2 => [ 50 ] ];
do_action( 'mavo_hub_membership_added', 2, 50 );
MVS_Sync::flush();
same( 'membership: the member reindexed', [ 2 ], indexed_actions() );
same( 'hub title now finds the member', [ 50, 2 ], ids( 'city trips' ) );

MVS_Sync::reset();
$GLOBALS['MOCK_ACTIONS'] = [];
$before                  = get_post( 50 );
mvs_post( 50, 'Escapades urbaines', '<p>Nos villes.</p>', [ 'type' => 'page' ] );
do_action( 'post_updated', 50, get_post( 50 ), $before );
do_action( 'save_post', 50, get_post( 50 ) );
MVS_Sync::flush();
same( 'hub retitled: the hub and its members', [ 2, 50 ], ( static function ( $a ) { sort( $a ); return $a; } )( indexed_actions() ) );
same( 'members found by the new title', [ 50, 2 ], ids( 'escapades urbaines' ) );

/* ------------------------------------------------------------ image index */

MVS_Sync::reset();
$GLOBALS['MOCK_ACTIONS'] = [];
$GLOBALS['MOCK_IMAGES']  = [ 3 => [ [ 'id' => 300, 'alt' => [ 'fr' => 'Le palais de la Pena' ] ] ] ];
do_action( 'mavo_image_indexed', 300, 'fr', [] );
MVS_Sync::flush();
same( 'alt edited: the posts using the image', [ 3 ], indexed_actions() );
same( 'alt word found', [ 3 ], ids( 'pena' ) );

/* ---------------------------------------------- after the flush: deferred */

do_action( 'mavo_image_usage_updated', 300, 1 );
same( 'reported after the flush: queued for cron', [ 1 ], MVS_Sync::stored_queue() );
MVS_Sync::process_queue();
same( 'queue processed', [], MVS_Sync::stored_queue() );

/* ------------------------------------------------- unpublish and delete */

MVS_Sync::reset();
mvs_post( 3, 'Sintra', '<p>Les palais.</p>', [ 'status' => 'draft' ] );
do_action( 'save_post', 3, get_post( 3 ) );
MVS_Sync::flush();
same( 'unpublished: no document, no terms', [ null, 0 ], [ indexed_at( 3 ), rows_for( 3 ) ] );
same( 'and not found', [], ids( 'sintra' ) );

$doc_id = (int) $wpdb->get_var( 'SELECT doc_id FROM wp_mavo_search_docs WHERE post_id = 2' );
do_action( 'deleted_post', 2 );
same( 'deleted: rows gone at once', [ 0, 0 ], [ (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_mavo_search_docs WHERE post_id = 2" ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_mavo_search_terms WHERE doc_id = $doc_id" ) ] );

/* ----------------------------------------------------- noindex, failures */

MVS_Sync::reset();
mvs_meta( 1, '_yoast_wpseo_meta-robots-noindex', '1' );
do_action( 'updated_post_meta', 0, 1, '_yoast_wpseo_meta-robots-noindex' );
MVS_Sync::flush();
same( 'noindex set: removed', null, indexed_at( 1 ) );
mvs_meta( 1, '_yoast_wpseo_meta-robots-noindex', null );

mvs_post( 4, 'Évora', '<p>Le temple romain.</p>' );
add_filter( 'mavo_search_document_sources', static function ( $s, $post ) {
	if ( 4 === $post->ID ) { throw new RuntimeException( 'boom' ); }
	return $s;
}, 10, 2 );
same( 'a throwing build is reported failed', [ 4 => 'failed' ], MVS_Indexer::index( [ 4 ] ) );
same( 'failure counted', 1, MVS_Status::summary()['failed'] );
same( 'failure listed with its error', 'boom', MVS_Status::failures()[0]['error'] ?? null );
remove_all_filters( 'mavo_search_document_sources' );

/* --------------------------------------------------------- stale rebuild */

$s = MVS_Status::summary();
check( 'missing and failed before', $s['missing'] >= 1 && 1 === $s['failed'], $s );

$wpdb->insert( 'wp_mavo_search_docs', [ 'post_id' => 777, 'lang' => 'fr', 'post_type' => 'post', 'title' => 'x', 'title_norm' => 'x', 'excerpt' => '', 'content' => '', 'indexed_at' => '2024-01-01' ] );
same( 'orphan counted', 1, MVS_Status::summary()['orphans'] );

$cursor = 0;
do {
	$step   = MVS_Rebuild::step( 'stale', $cursor, 2 );
	$cursor = $step['cursor'];
} while ( ! $step['done'] );

$s = MVS_Status::summary();
same( 'stale rebuild leaves everything current', [ 0, 0, 0, 0 ], [ $s['stale'], $s['missing'], $s['failed'], $s['orphans'] ] );
same( 'and Évora indexed', [ 4 ], ids( 'evora' ) );

/* ----------------------------------------------------- rules change → stale */

add_filter( 'mavo_search_taxonomies', static fn() => [ 'post_tag', 'category' ] );
same( 'changing what is indexed makes everything stale', $s['current'], MVS_Status::summary()['stale'] );
remove_all_filters( 'mavo_search_taxonomies' );

/* --------------------------------------------------------- background */

delete_option( MVS_WP::READY_OPTION );
MVS_Rebuild::start_background();
check( 'background rebuild scheduled', (bool) wp_next_scheduled( MVS_Rebuild::BACKGROUND_HOOK ) );
MVS_Rebuild::background_step();
check( 'background rebuild completes and marks ready', MVS_WP::ready() && null === MVS_Rebuild::background_state() );

done();

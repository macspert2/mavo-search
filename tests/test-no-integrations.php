<?php
/**
 * Polylang, mavo-geotag-plus, mavo-hubs, mavo-image-index and the theme's
 * landing-page filter all absent: title, text and excerpt search still work,
 * nothing fatals.
 */

require __DIR__ . '/harness.php';

same( 'languages without Polylang', [ 'fr', 'en', 'de' ], MVS_Lang::languages() );
same( 'current language falls back to fr', 'fr', MVS_Lang::current() );

mvs_post( 1, 'Lisbonne en famille', '<p>Le tram 28 <img class="wp-image-7" src="x.jpg"></p>', [ 'excerpt' => 'Notre guide.' ] );
mvs_post( 2, 'Porto', '<p>Les caves.</p>', [ 'tags' => [ 'Portugal' ] ] );
mvs_meta( 7, '_wp_attachment_image_alt', 'Le miradouro de Graça' );
mvs_meta( 2, '_thumbnail_id', 8 );
mvs_meta( 8, '_wp_attachment_image_alt', 'Le pont Dom-Luís' );

mvs_rebuild();

same( 'title', [ 1 ], ids( 'lisbonne' ) );
same( 'text', [ 1 ], ids( 'tram' ) );
same( 'excerpt', [ 1 ], ids( 'guide' ) );
same( 'tags still indexed (as subjects: no place data)', [ 2 ], ids( 'portugal' ) );
same( 'WordPress alt text, default language: content image', [ 1 ], ids( 'miradouro' ) );
same( 'WordPress alt text, default language: featured image', [ 2 ], ids( 'pont' ) );
same( 'no concepts parsed', [], mavo_search_parse_query( 'plage' )['concepts'] );
same( 'no hub field, no error', [], MVS_Hubs::hubs( 1, 'fr' ) );
same( 'no place field, no error', [], MVS_Geo::places( 1, 'fr' ) );
same( 'no guide places, no error', [], MVS_Geo::guide_places( 1 ) );
same( 'result image falls back to the featured image', 8, mavo_search_result_image( 2 ) );

$hit = mavo_search( 'lisbonne' )['results'][0];
same( 'no places, no hubs', [ [], [] ], [ $hit['places'], $hit['hubs'] ] );
check( 'excerpt', str_contains( $hit['excerpt'], 'mavo-search-highlight' ) || 'summary' === $hit['excerpt_source'] );

same( 'excerpt helper: no match, the hand-written excerpt as summary', 'Notre guide.', mavo_search_get_excerpt( 1, 'zzz' ) );
same( 'unknown post: empty excerpt', '', mavo_search_get_excerpt( 999, 'x' ) );
check( 'reindex helper', mavo_search_reindex_post( 1 ) );
mavo_search_delete_post( 1 );
same( 'delete helper', [], ids( 'lisbonne' ) );

done();

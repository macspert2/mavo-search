<?php
/**
 * Words, folding, German spellings, stopwords, and content extraction.
 */

require __DIR__ . '/harness.php';

/* ---------------------------------------------------------------- folding */

same( 'accents folded', 'ile edimbourg cote cote', MVS_Text::normalize( 'Île Édimbourg côte côté' ) );
same( 'ß, œ, æ, ø', 'strasse coeur aero oslo', MVS_Text::normalize( 'Straße cœur ærø oslo' ) );
same( 'decomposed accent folds like a composed one', 'ile', MVS_Text::normalize( "I\u{0302}le" ) );
same( 'apostrophes and hyphens separate', 'l eau royaume uni aujourd hui', MVS_Text::normalize( "L’eau Royaume-Uni aujourd'hui" ) );
same( 'tokens', [ 'ou', 'dormir', 'a', 'londres' ], MVS_Text::tokens( 'Où dormir à Londres ?' ) );

$words = MVS_Text::words( 'Été à Édimbourg' );
same( 'words keep raw text and byte offsets', [ 'Édimbourg', 'edimbourg', 9 ], [ $words[2]['raw'], $words[2]['norm'], $words[2]['offset'] ] );
same( 'normalize agrees with words()', MVS_Text::normalize( 'Ça, c’est Zürich!' ), implode( ' ', array_column( MVS_Text::words( 'Ça, c’est Zürich!' ), 'norm' ) ) );

/* ------------------------------------------------------------------ terms */

same( 'fr terms: stopwords and single letters dropped', [ 'dormir' => 1, 'londres' => 2 ], MVS_Text::terms( 'Où dormir à Londres, Londres', 'fr' ) );
same( 'de: umlaut words indexed under both spellings', [ 'zurich' => 1, 'zuerich' => 1, 'strasse' => 1 ], MVS_Text::terms( 'Zürich Straße', 'de' ) );
same( 'fr: umlaut words only folded', [ 'zurich' => 1 ], MVS_Text::terms( 'Zürich', 'fr' ) );
same( 'stopwords are per language', [ 'the' => 1 ], MVS_Text::terms( 'die the', 'de' ) );

add_filter( 'mavo_search_stopwords', static fn( $list, $lang ) => 'fr' === $lang ? array_merge( $list, [ 'famille' ] ) : $list, 10, 2 );
MVS_Text::reset();
same( 'stopword filter', [], MVS_Text::terms( 'famille', 'fr' ) );
$v1 = MVS_Text::version();
remove_all_filters( 'mavo_search_stopwords' );
MVS_Text::reset();
check( 'stopword change changes the rules version', MVS_Text::version() !== $v1 );

/* ------------------------------------------------------------- extraction */

$html = <<<'HTML'
<!-- wp:paragraph --><p>Bienvenue à <strong>Lisbonne</strong>&nbsp;!</p><!-- /wp:paragraph -->
<h2 class="x">Où dormir</h2>
<p>Voir <a href="https://example.test/hotels-lisbonne/" class="lien-hotel">nos hôtels</a>.</p>
<script>var lisbonne = "pas ceci";</script><style>.lisbonne{color:red}</style>
[caption id="attachment_5" align="alignnone" width="800"]<img src="https://example.test/photo-tram.jpg" class="wp-image-5" alt="Tram"> Le tram 28[/caption]
[gallery ids="1,2,3"]
[su_box title="Ancien plugin"]Texte gardé[/su_box]
<!-- mavo-search:skip --><p>Publicité cachée</p><!-- /mavo-search:skip -->
<ul><li>un</li><li>deux</li></ul>Fin[sic]
HTML;

$out = MVS_Extractor::extract( $html );

check( 'visible text kept', str_contains( $out['text'], 'Bienvenue à Lisbonne !' ), $out['text'] );
check( 'link text kept, URL and class gone', str_contains( $out['text'], 'nos hôtels' ) && ! str_contains( $out['text'], 'example.test' ) && ! str_contains( $out['text'], 'lien' ) );
check( 'script and style removed', ! str_contains( $out['text'], 'pas ceci' ) && ! str_contains( $out['text'], 'color' ) );
check( 'caption text kept, shortcode syntax and image URL gone', str_contains( $out['text'], 'Le tram 28' ) && ! str_contains( $out['text'], 'attachment_5' ) && ! str_contains( $out['text'], 'photo-tram' ) );
check( 'unregistered shortcode syntax gone, its text kept', str_contains( $out['text'], 'Texte gardé' ) && ! str_contains( $out['text'], 'su_box' ) && ! str_contains( $out['text'], 'Ancien' ) );
check( 'skip marker honoured', ! str_contains( $out['text'], 'Publicité' ) );
check( 'block elements separate words', str_contains( $out['text'], "un\ndeux" ) );
check( '[sic] in prose survives', str_contains( $out['text'], '[sic]' ) );
check( 'no block comments', ! str_contains( $out['text'], 'wp:paragraph' ) );
same( 'headings returned', [ 'Où dormir' ], $out['headings'] );

done();

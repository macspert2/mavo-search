<?php
/**
 * Contextual excerpts and safe highlighting.
 */

require __DIR__ . '/harness.php';

function hl( string $query, string $lang = 'fr' ): array {
	return MVS_Query::highlight_terms( MVS_Query::parse( $query, $lang ) );
}

const MARK = '<mark class="mavo-search-highlight">';

/* -------------------------------------------------------------- highlight */

same( 'whole words, folded', 'À ' . MARK . 'Édimbourg</mark>, Dimbourg', MVS_Highlight::text( 'À Édimbourg, Dimbourg', hl( 'edimbourg' ) ) );
same( 'longer forms marked whole, as they matched', MARK . 'edimbourgeois</mark>', MVS_Highlight::text( 'edimbourgeois', hl( 'edimbourg' ) ) );
same( 'prefix expanded to the whole word', MARK . 'Plages</mark> et ' . MARK . 'plage</mark>', MVS_Highlight::text( 'Plages et plage', hl( 'plage' ) ) );
same( 'short words not expanded', 'Merveilleux ' . MARK . 'mer</mark>', MVS_Highlight::text( 'Merveilleux mer', hl( 'mer' ) ) );
same( 'stopwords and single letters never marked', 'à la ' . MARK . 'plage</mark>', MVS_Highlight::text( 'à la plage', hl( 'à la plage' ) ) );
same( 'text is escaped', '&lt;b&gt; ' . MARK . 'Lisbonne</mark> &amp; co', MVS_Highlight::text( '<b> Lisbonne & co', hl( 'lisbonne' ) ) );
same( 'German ue spelling marks the umlaut word', MARK . 'Zürich</mark>', MVS_Highlight::text( 'Zürich', hl( 'zuerich', 'de' ), 'de' ) );

$html = '<p class="lisbonne">Voir <a href="https://example.test/lisbonne/" title="Lisbonne">Lisbonne&nbsp;&amp; Porto</a></p>'
	. '<script>var lisbonne = 1;</script><!-- lisbonne --><img alt="Lisbonne" src="lisbonne.jpg"><mark>Lisbonne</mark>';
$out  = MVS_Highlight::html( $html, hl( 'lisbonne' ) );

check( 'attributes and URLs untouched', str_contains( $out, 'class="lisbonne"' ) && str_contains( $out, 'href="https://example.test/lisbonne/"' ) && str_contains( $out, 'title="Lisbonne"' ) && str_contains( $out, 'alt="Lisbonne"' ), $out );
check( 'link text marked, entities kept valid', str_contains( $out, '>' . MARK . 'Lisbonne</mark>' . "\u{00A0}" . '&amp; Porto</a>' ), $out );
check( 'script untouched', str_contains( $out, '<script>var lisbonne = 1;</script>' ) );
check( 'comment untouched', str_contains( $out, '<!-- lisbonne -->' ) );
check( 'existing mark not nested', str_contains( $out, '<mark>Lisbonne</mark>' ) && ! str_contains( $out, '<mark>' . MARK ) );
same( 'one mark per occurrence in text', 1, substr_count( $out, MARK ) );

add_filter( 'mavo_search_highlight_html', static fn() => '<strong class="hit">%s</strong>' );
same( 'markup filter', '<strong class="hit">Porto</strong>', MVS_Highlight::text( 'Porto', hl( 'porto' ) ) );
remove_all_filters( 'mavo_search_highlight_html' );
add_filter( 'mavo_search_highlight_html', static fn() => '<b>%s %s</b>' );
same( 'a broken template falls back', MARK . 'Porto</mark>', MVS_Highlight::text( 'Porto', hl( 'porto' ) ) );
remove_all_filters( 'mavo_search_highlight_html' );

same( 'public helper', 'Le ' . MARK . 'Portugal</mark>', mavo_search_highlight_terms( 'Le Portugal', 'portugal', [ 'lang' => 'fr' ] ) );

/* --------------------------------------------------------------- excerpts */

$long = str_repeat( 'Une phrase sans rapport avec le sujet. ', 20 )
	. 'Le meilleur glacier de Lisbonne est près du tram 28. '
	. str_repeat( 'Encore une phrase de remplissage. ', 20 )
	. 'Lisbonne et Porto en train, tram compris. '
	. str_repeat( 'Et la fin du texte. ', 10 );

$e = MVS_Excerpt::build( $long, '', hl( 'lisbonne tram' ), 'fr', 120 );
same( 'source: content', 'content', $e['source'] );
check( 'window holds both words', substr_count( $e['html'], MARK ) >= 2, $e['html'] );
check( 'starts at the sentence', str_starts_with( $e['html'], '… Le meilleur glacier' ) || str_starts_with( $e['html'], '… Lisbonne et Porto' ), $e['html'] );
check( 'ends with an ellipsis', str_ends_with( $e['html'], '…' ) );
check( 'about the asked length', mb_strlen( wp_strip_all_tags( $e['html'] ) ) <= 130, mb_strlen( wp_strip_all_tags( $e['html'] ) ) );

$short = MVS_Excerpt::build( 'Lisbonne en trois jours.', '', hl( 'lisbonne' ), 'fr' );
same( 'short text whole, no ellipsis', MARK . 'Lisbonne</mark> en trois jours.', $short['html'] );

$manual = MVS_Excerpt::build( 'Rien ici.', 'Notre guide de Porto.', hl( 'porto' ), 'fr' );
same( 'hand-written excerpt when the text does not match', [ 'excerpt', 'Notre guide de ' . MARK . 'Porto</mark>.' ], [ $manual['source'], $manual['html'] ] );

$summary = MVS_Excerpt::build( "Premier paragraphe.\nDeuxième paragraphe.", '', hl( 'grece' ), 'fr' );
same( 'structured-only match: plain summary from the start', [ 'summary', 'Premier paragraphe. Deuxième paragraphe.' ], [ $summary['source'], $summary['html'] ] );

$utf = MVS_Excerpt::build( str_repeat( 'Ééé àààà ', 80 ) . 'Fjällbacka', '', hl( 'fjallbacka' ), 'fr', 60 );
check( 'multibyte text never cut mid-character', false !== mb_check_encoding( $utf['html'], 'UTF-8' ) && str_contains( $utf['html'], MARK . 'Fjällbacka</mark>' ), $utf['html'] );

$html_in = MVS_Excerpt::build( 'Fish & chips <script> à Londres', '', hl( 'londres' ), 'fr' );
same( 'excerpt text escaped', 'Fish &amp; chips &lt;script&gt; à ' . MARK . 'Londres</mark>', $html_in['html'] );

done();

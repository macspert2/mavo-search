<?php
/**
 * Stand-ins for Polylang, mavo-geotag-plus, mavo-hubs and mavo-image-index,
 * driven by globals. Only the functions the plugin calls.
 */

$GLOBALS['MOCK_LANG']       = 'fr';
$GLOBALS['MOCK_POST_LANG']  = [];   // post_id => lang
$GLOBALS['MOCK_CHAINS']     = [];   // post_id => [ [ level, name, term_id ], … ] continent first, names in the post's language
$GLOBALS['MOCK_POST_HUBS']  = [];   // post_id => [ hub_id, … ]
$GLOBALS['MOCK_HUBS']       = [];   // hub_id => true
$GLOBALS['MOCK_IMAGES']     = [];   // post_id => [ [ id, alt => [ lang => text ], concepts => [ slug => [ label, confidence ] ] ], … ]
$GLOBALS['MOCK_CONCEPT_LABELS'] = [ 'fr' => [ 'beach' => 'Plage', 'turquoise_water' => 'Eaux turquoise', 'forest' => 'Forêt', 'garden' => 'Jardin' ],
                                    'en' => [ 'beach' => 'Beach', 'turquoise_water' => 'Turquoise water', 'forest' => 'Forest', 'garden' => 'Garden' ],
                                    'de' => [ 'beach' => 'Strand', 'turquoise_water' => 'Türkises Wasser', 'forest' => 'Wald', 'garden' => 'Garten' ] ];
$GLOBALS['MOCK_MATCHER']    = [ 'fr' => [ 'eaux turquoise' => 'turquoise_water', 'eau turquoise' => 'turquoise_water', 'plage' => 'beach', 'plages' => 'beach', 'jardin' => 'garden', 'jardins' => 'garden' ] ];

function pll_languages_list( $args = [] ) { return [ 'fr', 'en', 'de' ]; }
function pll_default_language( $field = 'slug' ) { return 'fr'; }
function pll_current_language( $field = 'slug' ) { return $GLOBALS['MOCK_LANG']; }
function pll_get_post_language( $id, $field = 'slug' ) { return $GLOBALS['MOCK_POST_LANG'][ $id ] ?? 'fr'; }

function mavo_geo_place_chain( int $post_id, string $lang = '' ): array {
	return array_map( static fn( $p ) => (object) [
		'level'             => $p[0],
		'name_' . $lang     => $p[1],
		'term_id_' . $lang  => $p[2] ?? 0,
	], $GLOBALS['MOCK_CHAINS'][ $post_id ] ?? [] );
}

function mavo_is_hub( int $post_id ): bool { return ! empty( $GLOBALS['MOCK_HUBS'][ $post_id ] ); }
function mavo_get_hubs( int $post_id ): array { return $GLOBALS['MOCK_POST_HUBS'][ $post_id ] ?? []; }
function mavo_get_hub_ancestors( int $post_id ): array {
	$out   = [];
	$queue = array_map( static fn( $h ) => [ $h, 0 ], mavo_get_hubs( $post_id ) );
	while ( $queue ) {
		[ $id, $depth ] = array_shift( $queue );
		if ( isset( $out[ $id ] ) ) { continue; }
		$out[ $id ] = $depth;
		foreach ( mavo_get_hubs( $id ) as $up ) { $queue[] = [ $up, $depth + 1 ]; }
	}
	return $out;
}
function mavo_get_hub_descendants( int $hub_id, array $args = [] ): array {
	$out   = [];
	$queue = [ $hub_id ];
	while ( $queue ) {
		$id = array_shift( $queue );
		foreach ( $GLOBALS['MOCK_POST_HUBS'] as $child => $hubs ) {
			if ( in_array( $id, $hubs, true ) && ! in_array( $child, $out, true ) ) {
				$out[]   = $child;
				$queue[] = $child;
			}
		}
	}
	return $out;
}

function mavo_image_search( array $args ): array {
	$out  = [];
	$lang = $args['lang'];
	foreach ( $args['post_ids'] as $post_id ) {
		foreach ( $GLOBALS['MOCK_IMAGES'][ $post_id ] ?? [] as $img ) {
			$concepts = [];
			foreach ( $img['concepts'] ?? [] as $slug => [ $label, $confidence ] ) {
				$concepts[] = [ 'slug' => $slug, 'label' => $GLOBALS['MOCK_CONCEPT_LABELS'][ $lang ][ $slug ] ?? $label, 'confidence' => $confidence ];
			}
			$out[] = [ 'attachment_id' => $img['id'], 'alt' => $img['alt'] ?? [], 'concepts' => $concepts ];
		}
	}
	return $out;
}
function mavo_image_best_match( array $args ): ?array {
	foreach ( $args['post_ids'] as $post_id ) {
		foreach ( $GLOBALS['MOCK_IMAGES'][ $post_id ] ?? [] as $img ) {
			if ( array_intersect( array_keys( $img['concepts'] ?? [] ), $args['concepts'] ) ) {
				return [ 'attachment_id' => $img['id'] ];
			}
		}
	}
	return null;
}
function mavo_image_get_usages( int $id, array $args = [] ): array {
	$out = [];
	foreach ( $GLOBALS['MOCK_IMAGES'] as $post_id => $images ) {
		foreach ( $images as $img ) {
			if ( $img['id'] === $id ) { $out[] = [ 'post_id' => $post_id ]; }
		}
	}
	return $out;
}
function mavo_image_concepts( ?string $lang = null ): array {
	return array_map( static fn( $l ) => [ 'label' => $l, 'group' => 'x' ], $GLOBALS['MOCK_CONCEPT_LABELS'][ $lang ?? 'fr' ] ?? [] );
}
function mavo_image_match_concepts( string $text, ?string $lang = null ): array {
	$text = mb_strtolower( $text );
	$out  = [];
	foreach ( $GLOBALS['MOCK_MATCHER'][ $lang ?? 'fr' ] ?? [] as $phrase => $slug ) {
		if ( preg_match( '/\b' . preg_quote( $phrase, '/' ) . '\b/u', $text ) && ! isset( $out[ $slug ] ) ) {
			$out[ $slug ] = [ 'concept' => $slug, 'confidence' => 1.0, 'matched' => $phrase ];
		}
	}
	return array_values( $out );
}

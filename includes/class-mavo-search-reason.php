<?php
/**
 * "Why this matched", for results that do not show it themselves.
 *
 * A result explains itself when one of the words it matched is in its title
 * or highlighted in its excerpt. Otherwise it was found through something
 * the reader cannot see, and one reason is given — the first that applies:
 *
 *   guide   the page is the landing page of the place searched     { name }
 *   place   its place, or a place above it                          { name, within }
 *           name: the article's own place; within: the wider place
 *           that matched, when it was not the article's own
 *   hub     a hub it belongs to                                     { name }
 *   photo   one of its photos: the alt text that matched, or the
 *           concept                                                 { text, concept }
 *   tag     one of its tags                                         { name }
 *   pinned  a best bet the query did not otherwise find             {}
 *
 * Plain facts in the search's language; wording is the theme's. Synonyms and
 * longer forms need no reason: the highlighter marks them.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Reason {

	/**
	 * @param array  $hit     A ranked hit (MVS_Engine): terms, fields, concepts, signals, pinned.
	 * @param array  $doc     title, alts (newline-separated) of its document.
	 * @param string $excerpt The excerpt shown, as HTML.
	 * @return array{kind:string}|null
	 */
	public static function explain( array $hit, array $doc, string $excerpt, array $parsed ): ?array {
		$lang  = $parsed['lang'];
		$terms = array_values( array_filter( array_map( 'strval', $hit['terms'] ), static fn( $t ) => ! str_starts_with( $t, '#' ) ) );

		if ( str_contains( $excerpt, '<mark' ) || ( $terms && self::mentions( (string) ( $doc['title'] ?? '' ), $terms, $lang ) ) ) {
			return null;
		}

		$fields  = $hit['fields'];
		$signals = $hit['signals'];
		$has     = static fn( string $field ) => in_array( $field, $fields, true );

		if ( $has( 'guide' ) ) {
			foreach ( (array) ( $signals['guide'] ?? [] ) as $name ) {
				if ( self::mentions( (string) $name, $terms, $lang ) ) {
					return [ 'kind' => 'guide', 'name' => (string) $name ];
				}
			}
		}

		if ( $has( 'place' ) ) {
			$places = (array) ( $signals['places'] ?? [] ); // most specific first
			$own    = (string) ( $places[0]['name'] ?? '' );

			foreach ( $places as $place ) {
				if ( self::mentions( (string) $place['name'], $terms, $lang ) ) {
					return [ 'kind' => 'place', 'name' => $own, 'within' => $place['name'] === $own ? '' : (string) $place['name'] ];
				}
			}
		}

		if ( $has( 'hub' ) ) {
			foreach ( (array) ( $signals['hubs'] ?? [] ) as $hub_id ) {
				$name = MVS_Extractor::clean_line( wp_strip_all_tags( (string) get_post_field( 'post_title', (int) $hub_id ) ) );

				if ( '' !== $name && self::mentions( $name, $terms, $lang ) ) {
					return [ 'kind' => 'hub', 'name' => $name ];
				}
			}
		}

		if ( $has( 'alt' ) ) {
			foreach ( preg_split( '/\R/u', (string) ( $doc['alts'] ?? '' ) ) ?: [] as $alt ) {
				if ( '' !== trim( $alt ) && self::mentions( $alt, $terms, $lang ) ) {
					return [ 'kind' => 'photo', 'text' => trim( $alt ), 'concept' => '' ];
				}
			}
		}

		if ( $has( 'concept' ) ) {
			$labels  = MVS_Images::concept_labels( $lang );
			$matched = $hit['concepts'];

			// Found through the label rather than "#slug": which concept's label?
			foreach ( (array) ( $signals['concepts'] ?? [] ) as $slug ) {
				if ( isset( $labels[ $slug ] ) && self::mentions( $labels[ $slug ], $terms, $lang ) ) {
					$matched[] = $slug;
				}
			}

			foreach ( $matched as $slug ) {
				if ( '' !== ( $labels[ $slug ] ?? '' ) ) {
					return [ 'kind' => 'photo', 'text' => '', 'concept' => $labels[ $slug ] ];
				}
			}
		}

		if ( $has( 'taxonomy' ) ) {
			foreach ( (array) ( $signals['tags'] ?? [] ) as $name ) {
				if ( self::mentions( (string) $name, $terms, $lang ) ) {
					return [ 'kind' => 'tag', 'name' => (string) $name ];
				}
			}
		}

		return ! empty( $hit['pinned'] ) ? [ 'kind' => 'pinned' ] : null;
	}

	/** Does the text contain one of the matched index terms, as a word? */
	public static function mentions( string $text, array $terms, string $lang ): bool {
		if ( ! $terms || '' === $text ) {
			return false;
		}

		$hl = [ 'terms' => array_fill_keys( $terms, true ), 'prefixes' => [] ];

		foreach ( MVS_Text::words( $text ) as $word ) {
			if ( MVS_Highlight::matches( $word, $hl, $lang ) ) {
				return true;
			}
		}

		return false;
	}
}

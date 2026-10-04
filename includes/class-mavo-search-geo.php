<?php
/**
 * The place side of a post, through mavo-geotag-plus's procedural API — never
 * its classes or its table.
 *
 * Geography on this site is the place tree geotag-plus maintains: a post
 * tagged Lefkada is in Lefkada → Ionian Islands → Greece → Europe, and
 * geotag-plus attaches every one of those as a post_tag. Every name in the
 * chain is indexed in the post's language, the most specific at full strength
 * and the wider ones less: a search for "Grèce" must find the Lefkada article,
 * but below an article about Greece itself.
 *
 *   the post's own (most specific) place   100
 *   a region / county above it              80
 *   the country                             65
 *   the continent                           30
 *
 * Guide places come from the theme: a page that is the landing page of a
 * place tag (mv-geo-hub.php answers mavo_search_guide_places). That is the
 * editorial "this is the London guide" signal, indexed as its own field.
 *
 * Without geotag-plus: no place field, no error.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Geo {

	const STRENGTH = [ 'region' => 80, 'county' => 80, 'country' => 65, 'continent' => 30 ];

	public static function available(): bool {
		return function_exists( 'mavo_geo_place_chain' );
	}

	/**
	 * The post's places, most specific first.
	 *
	 * @return array<int,array{term_id:int,name:string,level:string,strength:int}>
	 */
	public static function places( int $post_id, string $lang ): array {
		if ( ! self::available() ) {
			return [];
		}

		$chain = array_reverse( (array) mavo_geo_place_chain( $post_id, $lang ) );
		$out   = [];

		foreach ( $chain as $i => $place ) {
			$level   = (string) ( $place->level ?? '' );
			$term_id = (int) ( $place->{'term_id_' . $lang} ?? 0 );
			$name    = trim( (string) ( $place->{'name_' . $lang} ?? '' ) );

			// A place not yet named in this language still has its tag there.
			if ( '' === $name && $term_id ) {
				$term = get_term( $term_id );
				$name = $term instanceof WP_Term ? $term->name : '';
			}

			if ( '' === $name || 'world' === $level ) {
				continue;
			}

			$out[] = [
				'term_id'  => $term_id,
				'name'     => $name,
				'level'    => $level,
				'strength' => 0 === $i ? 100 : ( self::STRENGTH[ $level ] ?? 80 ),
			];
		}

		/** A post's indexed places, most specific first. */
		return (array) apply_filters( 'mavo_search_places', $out, $post_id, $lang );
	}

	/**
	 * Names of the places this post is the landing page for, in its language.
	 *
	 * @return string[]
	 */
	public static function guide_places( int $post_id ): array {
		/**
		 * The place tags (post_tag term IDs) a post is the main guide for.
		 * The child theme answers from its landing-page mapping.
		 */
		$term_ids = array_filter( array_map( 'intval', (array) apply_filters( 'mavo_search_guide_places', [], $post_id ) ) );
		$names    = [];

		foreach ( array_unique( $term_ids ) as $term_id ) {
			$term = get_term( $term_id );

			if ( $term instanceof WP_Term && '' !== $term->name ) {
				$names[] = $term->name;
			}
		}

		return $names;
	}
}

<?php
/**
 * What a visitor typed → what to look up.
 *
 * Each meaningful word becomes a GROUP: the word and the other spellings that
 * should count as it, each with a weight —
 *
 *   word       1.0   the folded word, and its ae/oe/ue spelling in German
 *   plural     0.85  "plages" also looks up "plage" (fr/en: -s/-x; de: -en/-e/-n/-s)
 *   prefix     0.5   "plage" also looks up "plages", "plagettes"… (MVS_Engine
 *                    resolves these against the index; 4 letters minimum) —
 *                    Relevanssi's "fuzzy: always" did this
 *   synonym    0.8   data/synonyms.php: "leucade" also looks up "lefkada"
 *   concept    1.0   "#turquoise_water" when mavo-image-index recognises the
 *                    concept in the query; the concept field's own low
 *                    weight keeps it a supporting signal
 *
 * A document must match every group (AND) unless MVS_Engine falls back.
 * Stopwords form no group, but stay in the phrase: "où dormir à londres" is
 * compared whole against titles and text.
 *
 * "Quoted phrases" are required verbatim (folded) in the title, text or
 * excerpt. Limits: 200 characters, 10 groups.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Query {

	const MAX_LENGTH = 200;
	const MAX_GROUPS = 10;
	const MIN_PREFIX = 4;

	const WEIGHT = [ 'word' => 1.0, 'plural' => 0.85, 'prefix' => 0.5, 'synonym' => 0.8, 'concept' => 1.0 ];

	private static array $parsed   = [];
	private static array $synonyms = [];

	/**
	 * @return array{query:string,lang:string,normalized:string,tokens:string[],
	 *               groups:array<int,array{token:string,raw:string,variants:array<string,array{weight:float,kind:string}>,prefix:bool}>,
	 *               phrases:string[],concepts:array<string,float>}
	 */
	public static function parse( string $query, ?string $lang = null ): array {
		$lang  = MVS_Lang::resolve( $lang );
		$query = self::clean( $query );
		$key   = $lang . '|' . $query;

		if ( isset( self::$parsed[ $key ] ) ) {
			return self::$parsed[ $key ];
		}

		$words    = MVS_Text::words( $query );
		$tokens   = array_column( $words, 'norm' );
		$groups   = [];
		$synonyms = self::synonyms( $lang );

		foreach ( $words as $word ) {
			$norm = $word['norm'];

			if ( isset( $groups[ $norm ] ) || ! MVS_Text::indexable( $norm, $lang ) || count( $groups ) >= self::MAX_GROUPS ) {
				continue;
			}

			$variants = [];

			foreach ( MVS_Text::forms( $word['raw'], $norm, $lang ) as $form ) {
				$variants[ $form ] = [ 'weight' => self::WEIGHT['word'], 'kind' => 'word' ];
			}

			foreach ( self::singulars( $norm, $lang ) as $singular ) {
				$variants[ $singular ] ??= [ 'weight' => self::WEIGHT['plural'], 'kind' => 'plural' ];
			}

			foreach ( $synonyms[ $norm ] ?? [] as $synonym ) {
				$variants[ $synonym ] ??= [ 'weight' => self::WEIGHT['synonym'], 'kind' => 'synonym' ];
			}

			$groups[ $norm ] = [
				'token'    => $norm,
				'raw'      => $word['raw'],
				'variants' => $variants,
				'prefix'   => mb_strlen( $norm, 'UTF-8' ) >= self::MIN_PREFIX && ! ctype_digit( $norm ),
			];
		}

		$concepts = self::concepts( $query, $tokens, $lang, $groups );

		$parsed = [
			'query'      => $query,
			'lang'       => $lang,
			'normalized' => implode( ' ', $tokens ),
			'tokens'     => $tokens,
			'groups'     => array_values( $groups ),
			'phrases'    => self::quoted( $query ),
			'concepts'   => $concepts,
		];

		/** The parsed query: groups of terms to look up, phrases, concepts. */
		$parsed = (array) apply_filters( 'mavo_search_parsed_query', $parsed, $query, $lang );

		if ( count( self::$parsed ) > 50 ) {
			self::$parsed = [];
		}

		return self::$parsed[ $key ] = $parsed;
	}

	/** Visitor input → plain text, bounded. Never a pattern, never SQL. */
	public static function clean( string $query ): string {
		$query = wp_strip_all_tags( $query );
		$query = (string) preg_replace( '/\s+/u', ' ', $query );

		return trim( mb_substr( trim( $query ), 0, self::MAX_LENGTH, 'UTF-8' ) );
	}

	/**
	 * Every term a document's words are highlighted for, and the stems whose
	 * longer forms count too.
	 *
	 * @return array{terms:array<string,true>,prefixes:string[]}
	 */
	public static function highlight_terms( array $parsed, array $extra = [] ): array {
		$terms    = [];
		$prefixes = [];

		foreach ( $parsed['groups'] as $group ) {
			foreach ( array_keys( $group['variants'] ) as $term ) {
				if ( ! str_starts_with( (string) $term, '#' ) ) {
					$terms[ (string) $term ] = true;
				}
			}

			if ( $group['prefix'] ) {
				$prefixes[] = $group['token'];
			}
		}

		foreach ( $extra as $term ) {
			if ( ! str_starts_with( (string) $term, '#' ) ) {
				$terms[ (string) $term ] = true;
			}
		}

		return [ 'terms' => $terms, 'prefixes' => array_values( array_unique( $prefixes ) ) ];
	}

	/**
	 * The one image concept a query is entirely about — "eaux turquoise",
	 * "bunte Häuser", "plage" — and only then: that concept alone must
	 * account for every word of the query. "plage lefkada" is a search for
	 * Lefkada, not for photos of beaches, and gets nothing here.
	 *
	 * Several concepts can fit: "bunte Häuser" names colourful houses, and
	 * its "Häuser" alone names houses. The most specific wins — the one
	 * accounting for the most words — then the strongest match (so a concept
	 * the matcher only implied, beach → sea, loses), then the first named.
	 * Two concepts side by side ("plage jardin") are no exact fit.
	 *
	 * @return string[] One concept slug, or [] when the query is not purely visual.
	 */
	public static function exact_concepts( array $parsed ): array {
		if ( ! $parsed['groups'] || ! $parsed['concepts'] || $parsed['phrases'] ) {
			return [];
		}

		$covers = [];
		foreach ( $parsed['groups'] as $g => $group ) {
			foreach ( $group['variants'] as $term => $variant ) {
				if ( 'concept' === $variant['kind'] ) {
					$covers[ substr( (string) $term, 1 ) ][] = $g;
				}
			}
		}

		$order  = array_flip( array_map( 'strval', array_keys( $parsed['concepts'] ) ) );
		$ranked = array_keys( $covers );

		usort( $ranked, static fn( $a, $b ) => [ count( $covers[ $b ] ), $parsed['concepts'][ $b ] ?? 0, $order[ $a ] ?? 0 ]
			<=> [ count( $covers[ $a ] ), $parsed['concepts'][ $a ] ?? 0, $order[ $b ] ?? 0 ] );

		$best  = $ranked[0] ?? null;
		$exact = null !== $best && count( $covers[ $best ] ) === count( $parsed['groups'] ) ? [ (string) $best ] : [];

		/** The image concept a query is entirely about, for a photo row beside the results. */
		return array_values( (array) apply_filters( 'mavo_search_image_concepts', $exact, $parsed ) );
	}

	/** For tests. */
	public static function reset(): void {
		self::$parsed   = [];
		self::$synonyms = [];
	}

	/* -------------------------------------------------------------- private */

	/**
	 * Safe singular forms only. "Plages" → "plage"; the prefix lookup covers
	 * the other direction.
	 *
	 * @return string[]
	 */
	private static function singulars( string $term, string $lang ): array {
		$length = mb_strlen( $term, 'UTF-8' );
		$out    = [];

		if ( in_array( $lang, [ 'fr', 'en' ], true ) && $length >= 5 && preg_match( '/[sx]$/', $term ) ) {
			$out[] = substr( $term, 0, -1 );
		}

		if ( 'en' === $lang && $length >= 6 && str_ends_with( $term, 'ies' ) ) {
			$out[] = substr( $term, 0, -3 ) . 'y';
		}

		if ( 'de' === $lang ) {
			if ( $length >= 6 && str_ends_with( $term, 'en' ) ) {
				$out[] = substr( $term, 0, -2 );
			} elseif ( $length >= 5 && preg_match( '/[ens]$/', $term ) ) {
				$out[] = substr( $term, 0, -1 );
			}
		}

		return $out;
	}

	/**
	 * Concepts in the query, attached as a variant to the groups of the words
	 * that named them.
	 *
	 * mavo-image-index's matcher reads the query with the whole dictionary
	 * ("eaux turquoise" → turquoise_water). It does not fold accents, so the
	 * concepts' labels are also compared folded ("foret" → forest), which is
	 * also all there is when the matcher is unavailable.
	 *
	 * @return array<string,float> slug => confidence
	 */
	private static function concepts( string $query, array $tokens, string $lang, array &$groups ): array {
		$found = [];

		foreach ( MVS_Images::match_concepts( $query, $lang ) as $match ) {
			$found[ (string) $match['concept'] ] = [ (float) $match['confidence'], MVS_Text::tokens( (string) $match['matched'] ) ];
		}

		$haystack = ' ' . implode( ' ', $tokens ) . ' ';

		foreach ( MVS_Images::concept_labels( $lang ) as $slug => $label ) {
			$label_tokens = MVS_Text::tokens( $label );

			if ( $label_tokens && ! isset( $found[ $slug ] ) && str_contains( $haystack, ' ' . implode( ' ', $label_tokens ) . ' ' ) ) {
				$found[ $slug ] = [ 1.0, $label_tokens ];
			}
		}

		$out = [];

		foreach ( $found as $slug => [ $confidence, $matched ] ) {
			$attached = false;

			foreach ( $groups as $norm => $group ) {
				if ( in_array( $norm, $matched, true ) ) {
					$groups[ $norm ]['variants'][ '#' . $slug ] ??= [ 'weight' => self::WEIGHT['concept'], 'kind' => 'concept' ];
					$attached = true;
				}
			}

			if ( $attached ) {
				$out[ $slug ] = round( $confidence, 3 );
			}
		}

		return $out;
	}

	/** @return string[] Quoted phrases of two words or more, normalized. */
	private static function quoted( string $query ): array {
		if ( ! preg_match_all( '/"([^"]+)"|“([^”]+)”|«([^»]+)»|„([^“”]+)[“”]/u', $query, $m, PREG_SET_ORDER ) ) {
			return [];
		}

		$out = [];

		foreach ( $m as $match ) {
			$phrase = MVS_Text::normalize( (string) end( $match ) );

			if ( substr_count( $phrase, ' ' ) >= 1 ) {
				$out[] = $phrase;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/** @return array<string,string[]> folded word => folded words it also looks up */
	private static function synonyms( string $lang ): array {
		if ( isset( self::$synonyms[ $lang ] ) ) {
			return self::$synonyms[ $lang ];
		}

		$all  = (array) include MVS_PLUGIN_DIR . 'data/synonyms.php';
		$data = (array) apply_filters( 'mavo_search_synonyms', (array) ( $all[ $lang ] ?? [] ), $lang );
		$map  = [];

		foreach ( $data as $key => $words ) {
			$words = array_values( array_filter( array_map( static fn( $w ) => MVS_Text::normalize( (string) $w ), (array) $words ) ) );

			if ( is_string( $key ) ) {
				$from         = MVS_Text::normalize( $key );
				$map[ $from ] = array_values( array_unique( array_merge( $map[ $from ] ?? [], $words ) ) );
				continue;
			}

			foreach ( $words as $word ) {
				$others       = array_values( array_diff( $words, [ $word ] ) );
				$map[ $word ] = array_values( array_unique( array_merge( $map[ $word ] ?? [], $others ) ) );
			}
		}

		return self::$synonyms[ $lang ] = $map;
	}
}

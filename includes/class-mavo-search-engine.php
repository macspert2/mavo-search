<?php
/**
 * Parsed query → ranked posts, and every number explained.
 *
 * Score of a document:
 *
 *   ( Σ over query words  rarity(word) × Σ over fields  weight(field) × amount × spelling )
 *   + title / phrase bonuses
 *   × the document's boost
 *
 *   amount     text fields: 1 + 0.6·ln(occurrences) — 1 → 1.0, 2 → 1.4,
 *              5 → 2.0, 20 → 2.8 — so a long article does not win by
 *              repeating a word; place / hub / concept: strength ÷ 100
 *   spelling   the variant's weight from MVS_Query (word 1, synonym 0.8 …);
 *              per field only the best-matching spelling counts
 *   rarity     ln(1 + N/df) ÷ ln(1 + N/5), between 0.2 and 1: a word in
 *              every document ("famille") counts for less than "lefkada"
 *   bonuses    the query is the title (20), the query is a phrase in the
 *              title (15), every word is in the title (6), the query is a
 *              phrase in the text (4)
 *
 * Every word must match somewhere (AND). With no result, documents matching
 * at least half the words (one, for a two-word query) are returned instead,
 * scored × (matched ÷ words)², and the result says fallback "or" so a
 * template can say "no exact results".
 *
 * Weights: mavo_search_field_weights. Rankings are cached per query,
 * language and index generation (MVS_Cache), so paging costs nothing.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Engine {

	const MAX_RESULTS       = 500;
	const PREFIX_TERMS      = 30;
	const ROW_CAP           = 30000;
	const PHRASE_CANDIDATES = 50;
	const QUOTED_CANDIDATES = 300;

	const WEIGHTS = [
		// Fields.
		'title'          => 10,
		'heading'        => 5,
		'content'        => 3,
		'excerpt'        => 3,
		'alt'            => 3,
		'concept'        => 2.5,
		'taxonomy'       => 2,
		'custom'         => 2,
		'place'          => 7,
		'hub'            => 5,
		'guide'          => 12,
		// Bonuses.
		'title_exact'    => 20,
		'title_phrase'   => 15,
		'title_all'      => 6,
		'content_phrase' => 4,
	];

	private static ?array $weights = null;
	private static array $counts   = [];

	/** @return array<string,float> */
	public static function weights(): array {
		if ( null !== self::$weights ) {
			return self::$weights;
		}

		/** Field weights and bonuses. Keys as MVS_Engine::WEIGHTS. */
		$weights = (array) apply_filters( 'mavo_search_field_weights', self::WEIGHTS );
		$out     = [];

		foreach ( self::WEIGHTS as $key => $default ) {
			$out[ $key ] = max( 0.0, (float) ( $weights[ $key ] ?? $default ) );
		}

		return self::$weights = $out;
	}

	/**
	 * Search, paged. What mavo_search() returns.
	 *
	 * @param array $args lang, page, per_page, post_types, explain, excerpts, fallback
	 */
	public static function search( string $query, array $args = [] ): array {
		$args = array_merge( [
			'lang'       => null,
			'page'       => 1,
			'per_page'   => 10,
			'post_types' => null,
			'explain'    => false,
			'excerpts'   => true,
			'fallback'   => true,
		], $args );

		/** The raw query, before it is read. */
		$query = (string) apply_filters( 'mavo_search_query', $query, $args );

		$parsed   = MVS_Query::parse( $query, $args['lang'] );
		$page     = max( 1, (int) $args['page'] );
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$ranking  = self::ranking( $parsed, $args );
		$total    = count( $ranking['ranked'] );
		$slice    = array_slice( $ranking['ranked'], ( $page - 1 ) * $per_page, $per_page );
		$content  = self::texts( array_column( $slice, 'doc_id' ) );
		$results  = [];

		foreach ( $slice as $i => $hit ) {
			$result = [
				'post_id'                => $hit['post_id'],
				'rank'                   => ( $page - 1 ) * $per_page + $i + 1,
				'pinned'                 => ! empty( $hit['pinned'] ),
				'score'                  => $hit['score'],
				'matched_terms'          => $hit['terms'],
				'matched_fields'         => $hit['fields'],
				'matched_image_concepts' => $hit['concepts'],
				'image_alt_only'         => in_array( 'alt', $hit['fields'], true ) && ! array_intersect( [ 'title', 'content', 'excerpt', 'heading' ], $hit['fields'] ),
				'places'                 => array_column( $hit['signals']['places'] ?? [], 'term_id' ),
				'hubs'                   => array_map( 'intval', $hit['signals']['hubs'] ?? [] ),
			];

			if ( $args['excerpts'] && isset( $content[ $hit['doc_id'] ] ) ) {
				$excerpt = MVS_Excerpt::build(
					$content[ $hit['doc_id'] ]['content'],
					$content[ $hit['doc_id'] ]['excerpt'],
					MVS_Query::highlight_terms( $parsed, $hit['terms'] ),
					$parsed['lang']
				);

				/** A result's excerpt: HTML, already escaped and highlighted. */
				$result['excerpt']        = (string) apply_filters( 'mavo_search_excerpt', $excerpt['html'], $hit['post_id'], $parsed, $excerpt['source'] );
				$result['excerpt_source'] = $excerpt['source'];
			}

			/** Why a result was found, when its title and excerpt do not show it (MVS_Reason). */
			$result['reason'] = apply_filters(
				'mavo_search_reason',
				MVS_Reason::explain( $hit, $content[ $hit['doc_id'] ] ?? [], (string) ( $result['excerpt'] ?? '' ), $parsed ),
				$hit['post_id'],
				$parsed
			);

			if ( $args['explain'] ) {
				$result['debug'] = $ranking['explain'][ $hit['doc_id'] ] ?? [];
			}

			$results[] = $result;
		}

		/** The results of one page, after excerpts. */
		$results = (array) apply_filters( 'mavo_search_hits', $results, $parsed, $args );

		$out = [
			'query'    => $parsed['query'],
			'lang'     => $parsed['lang'],
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
			'fallback' => $ranking['fallback'],
			// The visitor's own spelling of words found nowhere, for "no
			// result contains …" when the fallback answered.
			'missing_words' => array_values( array_map( static fn( $g ) => (string) ( $parsed['groups'][ $g ]['raw'] ?? $parsed['groups'][ $g ]['token'] ?? '' ), $ranking['missing'] ?? [] ) ),
			'results'  => $results,
		];

		if ( $args['explain'] ) {
			$out['parsed'] = $parsed;
		}

		return $out;
	}

	/**
	 * The whole ranked list, cached unless explaining.
	 *
	 * @return array{fallback:string,ranked:array<int,array>,explain:array,missing:int[]} missing: group indexes no document matches
	 */
	public static function ranking( array $parsed, array $args = [] ): array {
		$types   = self::types( $args['post_types'] ?? null );
		$explain = ! empty( $args['explain'] );
		$key     = 'rank:' . md5( serialize( [ $parsed['lang'], $parsed['query'], $types, ! empty( $args['fallback'] ?? true ) ] ) );

		if ( ! $explain ) {
			$cached = MVS_Cache::get( $key );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$ranking = self::rank( $parsed, $types, (bool) ( $args['fallback'] ?? true ), $explain );
		$ranking = MVS_Best_Bets::apply( $ranking, $parsed, $types, $explain );

		if ( ! $explain ) {
			MVS_Cache::set( $key, $ranking );
		}

		return $ranking;
	}

	/** For tests, and after a filter on the weights was added late. */
	public static function reset(): void {
		self::$weights = null;
		self::$counts  = [];
	}

	/* ------------------------------------------------------------- ranking */

	private static function rank( array $parsed, array $types, bool $fallback, bool $explain ): array {
		global $wpdb;

		$empty  = [ 'fallback' => 'none', 'ranked' => [], 'explain' => [], 'missing' => [] ];
		$groups = $parsed['groups'];
		$lang   = $parsed['lang'];

		if ( ! $groups || ! $types ) {
			return $empty;
		}

		$weights  = self::weights();
		$variants = self::variants( $groups, $lang );

		if ( ! $variants ) {
			return $empty;
		}

		$columns = implode( ', ', MVS_DB::fields() );
		$rows    = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT doc_id, term, $columns FROM " . MVS_DB::terms() . '
			  WHERE lang = %s AND term IN (' . MVS_DB::in_strings( array_keys( $variants ) ) . ')
			  LIMIT %d',
			$lang,
			self::ROW_CAP
		), ARRAY_A );

		// best[doc][group][field] = [ contribution, term, kind, value ]
		$best = [];

		foreach ( $rows as $row ) {
			$doc  = (int) $row['doc_id'];
			$term = (string) $row['term'];

			foreach ( $variants[ $term ] ?? [] as [ $g, $spelling, $kind ] ) {
				foreach ( MVS_DB::fields() as $field ) {
					$value = (int) $row[ $field ];

					if ( $value <= 0 || ! $weights[ $field ] ) {
						continue;
					}

					$amount = in_array( $field, MVS_DB::WEIGHT_FIELDS, true ) ? $value / 100 : 1 + 0.6 * log( $value );
					$score  = $weights[ $field ] * $amount * $spelling;

					if ( $score > ( $best[ $doc ][ $g ][ $field ][0] ?? 0 ) ) {
						$best[ $doc ][ $g ][ $field ] = [ $score, $term, $kind, $value ];
					}
				}
			}
		}

		if ( ! $best ) {
			return [ 'missing' => array_keys( $groups ) ] + $empty;
		}

		$n_groups = count( $groups );
		$docs     = self::doc_count( $lang );
		$df       = array_fill( 0, $n_groups, 0 );

		foreach ( $best as $by_group ) {
			foreach ( array_keys( $by_group ) as $g ) {
				$df[ $g ]++;
			}
		}

		// Words no document has at all: what a fallback had to leave out.
		$missing = array_keys( array_filter( $df, static fn( $count ) => 0 === $count ) );

		$rarity = [];
		foreach ( $df as $g => $count ) {
			$rarity[ $g ] = $count ? max( 0.2, min( 1.0, log( 1 + $docs / $count ) / max( log( 2 ), log( 1 + $docs / 5 ) ) ) ) : 0.0;
		}

		// AND, else the controlled OR.
		$mode     = 'none';
		$required = $n_groups;
		$matching = array_filter( $best, static fn( $by_group ) => count( $by_group ) >= $required );

		if ( ! $matching && $fallback && $n_groups > 1 ) {
			$mode     = 'or';
			$required = $n_groups >= 3 ? (int) ceil( $n_groups / 2 ) : 1;
			$matching = array_filter( $best, static fn( $by_group ) => count( $by_group ) >= $required );
		}

		if ( ! $matching ) {
			return [ 'missing' => $missing ] + $empty;
		}

		$meta = self::meta( array_keys( $matching ), $types );

		$candidates = [];
		$parts      = [];

		foreach ( $meta as $doc => $row ) {
			$score = 0.0;
			$comp  = [];

			foreach ( $matching[ $doc ] as $g => $by_field ) {
				foreach ( $by_field as $field => [ $contribution, $term, $kind, $value ] ) {
					$points = $contribution * $rarity[ $g ];
					$score += $points;

					if ( $explain ) {
						$comp[] = [
							'what'   => sprintf( '%s: %s%s', $field, ltrim( $term, '#' ), 'word' === $kind ? '' : " ($kind)" ),
							'detail' => sprintf( '%s %d × weight %s × rarity %.2f', in_array( $field, MVS_DB::WEIGHT_FIELDS, true ) ? 'strength' : 'count', $value, self::num( $weights[ $field ] ), $rarity[ $g ] ),
							'points' => round( $points, 2 ),
						];
					}
				}
			}

			if ( 'or' === $mode ) {
				$coverage = ( count( $matching[ $doc ] ) / $n_groups ) ** 2;
				$score   *= $coverage;
				$comp[]   = [ 'what' => 'partial match', 'detail' => sprintf( '%d of %d words, × %.2f', count( $matching[ $doc ] ), $n_groups, $coverage ), 'points' => null ];
			}

			$candidates[ $doc ] = $score;
			$parts[ $doc ]      = $comp;
		}

		self::title_bonuses( $parsed, $meta, $matching, $candidates, $parts, $weights );
		self::phrase_bonuses( $parsed, $meta, $candidates, $parts, $weights );

		$ranked = [];

		foreach ( $candidates as $doc => $score ) {
			$boost = (float) $meta[ $doc ]['boost'];

			if ( 1.0 !== $boost ) {
				$parts[ $doc ][] = [ 'what' => 'document boost', 'detail' => '× ' . self::num( $boost ), 'points' => null ];
			}

			$score *= $boost;

			/** One document's final score. */
			$score = (float) apply_filters( 'mavo_search_score', $score, (int) $meta[ $doc ]['post_id'], $parsed );

			if ( $score <= 0 ) {
				continue;
			}

			$terms  = [];
			$fields = [];
			$found  = [];

			foreach ( $matching[ $doc ] as $by_field ) {
				foreach ( $by_field as $field => [ , $term ] ) {
					$fields[ $field ] = true;

					if ( str_starts_with( $term, '#' ) ) {
						$found[ substr( $term, 1 ) ] = true;
					} else {
						$terms[ $term ] = true;
					}
				}
			}

			$ranked[ $doc ] = [
				'post_id'  => (int) $meta[ $doc ]['post_id'],
				'doc_id'   => $doc,
				'score'    => round( $score, 2 ),
				'date'     => (string) $meta[ $doc ]['post_date'],
				'terms'    => array_keys( $terms ),
				'fields'   => array_values( array_intersect( MVS_DB::fields(), array_keys( $fields ) ) ),
				'concepts' => array_keys( $found ),
				'signals'  => (array) json_decode( (string) $meta[ $doc ]['signals'], true ),
			];
		}

		uasort( $ranked, static fn( $a, $b ) => [ $b['score'], $b['date'], $b['post_id'] ] <=> [ $a['score'], $a['date'], $a['post_id'] ] );

		$ranked = array_slice( $ranked, 0, self::MAX_RESULTS, true );

		/**
		 * The ranked list as post ID => score, Relevanssi's relevanssi_results
		 * shape. Change a score to move a post; remove it to drop it.
		 */
		$scores = (array) apply_filters( 'mavo_search_results', array_column( $ranked, 'score', 'post_id' ), $parsed );
		$by_post = array_column( $ranked, null, 'post_id' );
		$final   = [];

		foreach ( $scores as $post_id => $score ) {
			if ( isset( $by_post[ $post_id ] ) ) {
				$final[] = [ 'score' => round( (float) $score, 2 ) ] + $by_post[ $post_id ];
			}
		}

		usort( $final, static fn( $a, $b ) => [ $b['score'], $b['date'], $b['post_id'] ] <=> [ $a['score'], $a['date'], $a['post_id'] ] );

		return [ 'fallback' => $mode, 'ranked' => $final, 'explain' => $explain ? $parts : [], 'missing' => $missing ];
	}

	/**
	 * term => [ [ group, spelling weight, kind ], … ], with each prefix group's
	 * longer forms found in the index.
	 */
	private static function variants( array $groups, string $lang ): array {
		global $wpdb;

		$out = [];

		foreach ( $groups as $g => $group ) {
			foreach ( $group['variants'] as $term => $variant ) {
				$out[ (string) $term ][] = [ $g, (float) $variant['weight'], (string) $variant['kind'] ];
			}

			if ( ! $group['prefix'] ) {
				continue;
			}

			$longer = (array) $wpdb->get_col( $wpdb->prepare(
				'SELECT DISTINCT term FROM ' . MVS_DB::terms() . ' WHERE term LIKE %s AND term <> %s AND lang = %s ORDER BY term LIMIT %d',
				$wpdb->esc_like( $group['token'] ) . '%',
				$group['token'],
				$lang,
				self::PREFIX_TERMS
			) );

			foreach ( $longer as $term ) {
				if ( ! isset( $group['variants'][ $term ] ) ) {
					$out[ (string) $term ][] = [ $g, MVS_Query::WEIGHT['prefix'], 'prefix' ];
				}
			}
		}

		return $out;
	}

	/** The query is the title / a phrase in the title / every word is in the title. */
	private static function title_bonuses( array $parsed, array $meta, array $matching, array &$scores, array &$parts, array $weights ): void {
		$query    = $parsed['normalized'];
		$content  = self::without_stopwords( $parsed['tokens'], $parsed['lang'] );
		$n_groups = count( $parsed['groups'] );
		$multi    = count( $parsed['tokens'] ) >= 2;

		foreach ( $meta as $doc => $row ) {
			$title = (string) $row['title_norm'];
			$bonus = null;

			if ( '' !== $title && ( $title === $query || ( '' !== $content && self::without_stopwords( explode( ' ', $title ), $parsed['lang'] ) === $content ) ) ) {
				$bonus = [ 'title is the query', $weights['title_exact'] ];
			} elseif ( $multi && str_contains( ' ' . $title . ' ', ' ' . $query . ' ' ) ) {
				$bonus = [ 'phrase in title', $weights['title_phrase'] ];
			}

			if ( $bonus && $bonus[1] > 0 ) {
				$scores[ $doc ] += $bonus[1];
				$parts[ $doc ][] = [ 'what' => $bonus[0], 'detail' => '', 'points' => (float) $bonus[1] ];
			}

			if ( $n_groups >= 2 && $weights['title_all'] > 0 && count( $matching[ $doc ] ) === $n_groups ) {
				$all = true;
				foreach ( $matching[ $doc ] as $by_field ) {
					$all = $all && isset( $by_field['title'] );
				}

				if ( $all ) {
					$scores[ $doc ] += $weights['title_all'];
					$parts[ $doc ][] = [ 'what' => 'every word in title', 'detail' => '', 'points' => (float) $weights['title_all'] ];
				}
			}
		}
	}

	/**
	 * The whole query as a phrase in the text, for the best candidates; and
	 * "quoted phrases", which every result must contain.
	 */
	private static function phrase_bonuses( array $parsed, array $meta, array &$scores, array &$parts, array $weights ): void {
		$quoted = $parsed['phrases'];
		$query  = $parsed['normalized'];
		$multi  = count( $parsed['tokens'] ) >= 2 && $weights['content_phrase'] > 0;

		if ( ! $quoted && ! $multi ) {
			return;
		}

		arsort( $scores );

		$limit = $quoted ? self::QUOTED_CANDIDATES : self::PHRASE_CANDIDATES;
		$top   = array_slice( array_keys( $scores ), 0, $limit );
		$texts = self::texts( $top );

		if ( $quoted ) {
			// Beyond the candidates checked, nothing can be shown to contain the phrase.
			foreach ( array_diff( array_keys( $scores ), $top ) as $doc ) {
				unset( $scores[ $doc ] );
			}
		}

		foreach ( $top as $doc ) {
			$title = ' ' . $meta[ $doc ]['title_norm'] . ' ';
			$text  = ' ' . MVS_Text::normalize( ( $texts[ $doc ]['content'] ?? '' ) . "\n" . ( $texts[ $doc ]['excerpt'] ?? '' ) ) . ' ';

			foreach ( $quoted as $phrase ) {
				if ( ! str_contains( $title, " $phrase " ) && ! str_contains( $text, " $phrase " ) ) {
					unset( $scores[ $doc ] );
					continue 2;
				}
			}

			if ( $multi && str_contains( $text, " $query " ) ) {
				$scores[ $doc ] += $weights['content_phrase'];
				$parts[ $doc ][] = [ 'what' => 'phrase in text', 'detail' => '', 'points' => (float) $weights['content_phrase'] ];
			}
		}
	}

	/** @return array<int,array> doc_id => docs row (no text) */
	private static function meta( array $doc_ids, array $types ): array {
		global $wpdb;

		$out = [];

		foreach ( array_chunk( $doc_ids, 1000 ) as $chunk ) {
			$rows = (array) $wpdb->get_results(
				'SELECT doc_id, post_id, post_type, title_norm, boost, signals, post_date FROM ' . MVS_DB::docs() . '
				  WHERE doc_id IN (' . MVS_DB::in_ints( $chunk ) . ") AND status = 'indexed'
				    AND post_type IN (" . MVS_DB::in_strings( $types ) . ')',
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$out[ (int) $row['doc_id'] ] = $row;
			}
		}

		return $out;
	}

	/** @return array<int,array{title:string,content:string,excerpt:string,alts:string}> */
	private static function texts( array $doc_ids ): array {
		global $wpdb;

		if ( ! $doc_ids ) {
			return [];
		}

		$out  = [];
		$rows = (array) $wpdb->get_results(
			'SELECT doc_id, title, content, excerpt, alts FROM ' . MVS_DB::docs() . ' WHERE doc_id IN (' . MVS_DB::in_ints( $doc_ids ) . ')',
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$out[ (int) $row['doc_id'] ] = [
				'title'   => (string) $row['title'],
				'content' => (string) $row['content'],
				'excerpt' => (string) $row['excerpt'],
				'alts'    => (string) $row['alts'],
			];
		}

		return $out;
	}

	private static function doc_count( string $lang ): int {
		global $wpdb;

		return self::$counts[ $lang ] ??= max( 1, (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . MVS_DB::docs() . " WHERE lang = %s AND status = 'indexed'",
			$lang
		) ) );
	}

	/** @return string[] */
	private static function types( $types ): array {
		$all = MVS_Document::post_types();

		if ( null === $types || 'any' === $types || [] === $types ) {
			return $all;
		}

		return array_values( array_intersect( $all, array_map( 'sanitize_key', (array) $types ) ) );
	}

	private static function without_stopwords( array $tokens, string $lang ): string {
		return implode( ' ', array_filter( $tokens, static fn( $t ) => '' !== $t && MVS_Text::indexable( $t, $lang ) ) );
	}

	private static function num( float $n ): string {
		return rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' );
	}
}

<?php
/**
 * Turning dead ends into results: "did you mean", the same search in the
 * other languages, and a search made from a 404's URL.
 *
 * All three run only where a page already failed — a search with no or only
 * partial results, a 404 — never on an ordinary search. None of them logs:
 * the search log and click counts stay what visitors typed and chose.
 *
 *   did_you_mean()     each word no document has is replaced by the closest
 *                      index term (1 edit for words up to 5 letters, 2 for
 *                      longer; same first letter), preferring terms found in
 *                      titles, places, hubs and guides, then common ones; the
 *                      correction is offered only if it finds exact results
 *   other_languages()  exact result counts of the same query in the site's
 *                      other languages — French has the most articles
 *   for_path()         the words of a 404's last URL segments ("/2014/05/
 *                      voyage-en-crete/" → "voyage en crete"), when the path
 *                      looks like an article and not like a file
 */

defined( 'ABSPATH' ) || exit;

class MVS_Recover {

	const MIN_LENGTH   = 4;
	const PATH_WORDS   = 8;
	const SKIP_SEGMENT = [ 'tag', 'category', 'categorie', 'page', 'amp', 'feed', 'author', 'auteur', 'blog' ];

	/** The query with its unknown words corrected, or '' when there is nothing to offer. */
	public static function did_you_mean( string $query, string $lang ): string {
		$parsed = MVS_Query::parse( $query, $lang );

		if ( ! $parsed['groups'] || $parsed['phrases'] ) {
			return '';
		}

		$missing = MVS_Engine::ranking( $parsed )['missing'] ?? [];

		if ( ! $missing ) {
			return '';
		}

		$fixed = $parsed['query'];

		foreach ( $missing as $g ) {
			$group = $parsed['groups'][ $g ] ?? null;
			$best  = $group ? self::closest( (string) $group['token'], $lang ) : '';

			if ( '' === $best ) {
				return '';
			}

			$fixed = (string) preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( (string) $group['raw'], '/' ) . '(?![\p{L}\p{N}])/u', $best, $fixed, 1 );
		}

		if ( MVS_Text::normalize( $fixed ) === $parsed['normalized'] ) {
			return '';
		}

		$check = MVS_Engine::search( $fixed, [ 'lang' => $lang, 'per_page' => 1, 'excerpts' => false, 'fallback' => false ] );

		return $check['total'] > 0 ? $fixed : '';
	}

	/** @return array<string,int> lang => exact results, other languages only, those with any. */
	public static function other_languages( string $query, string $lang ): array {
		$out = [];

		foreach ( MVS_Lang::languages() as $other ) {
			if ( $other === $lang ) {
				continue;
			}

			$total = MVS_Engine::search( $query, [ 'lang' => $other, 'per_page' => 1, 'excerpts' => false, 'fallback' => false ] )['total'];

			if ( $total > 0 ) {
				$out[ $other ] = $total;
			}
		}

		return $out;
	}

	/** @return int[] Up to $limit posts for a 404's path; [] when it does not look like an article. */
	public static function for_path( string $path, string $lang, int $limit = 3 ): array {
		$query = self::path_query( $path );

		if ( '' === $query ) {
			return [];
		}

		$result = MVS_Engine::search( $query, [ 'lang' => $lang, 'per_page' => max( 1, $limit ), 'excerpts' => false ] );

		return array_map( 'intval', array_column( $result['results'], 'post_id' ) );
	}

	/**
	 * A path's words, or '' when it is not article-shaped: a file
	 * extension (other than .html), a dot segment, wp-* directories, more
	 * than six segments, or no word of three letters or more.
	 */
	public static function path_query( string $path ): string {
		$path = strtolower( rawurldecode( (string) wp_parse_url( $path, PHP_URL_PATH ) ) );

		if ( '' === trim( $path, '/' ) || strlen( $path ) > 200 || preg_match( '#\.(?!html?$)[a-z0-9]{1,6}$#', $path ) || preg_match( '#(^|/)(\.|wp-(admin|content|includes|json)(/|$))#', $path ) ) {
			return '';
		}

		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );

		if ( count( $segments ) > 6 ) {
			return '';
		}

		if ( $segments && in_array( $segments[0], MVS_Lang::languages(), true ) ) {
			array_shift( $segments );
		}

		$segments = array_values( array_filter( $segments, static fn( $s ) => ! ctype_digit( $s ) && ! in_array( $s, self::SKIP_SEGMENT, true ) ) );
		$words    = [];

		// The last segment says most; the one before it adds context.
		foreach ( array_reverse( array_slice( $segments, -2 ) ) as $segment ) {
			$segment = (string) preg_replace( '/\.html?$/', '', $segment );

			foreach ( MVS_Text::tokens( (string) preg_replace( '/[-_+.]+/', ' ', $segment ) ) as $word ) {
				if ( ! ctype_digit( $word ) && count( $words ) < self::PATH_WORDS ) {
					$words[] = $word;
				}
			}
		}

		$long = array_filter( $words, static fn( $w ) => mb_strlen( $w, 'UTF-8' ) >= 3 );

		return $long ? implode( ' ', array_unique( $words ) ) : '';
	}

	/** The index term closest to an unknown word, or ''. */
	public static function closest( string $token, string $lang ): string {
		global $wpdb;

		$length = mb_strlen( $token, 'UTF-8' );

		if ( $length < self::MIN_LENGTH ) {
			return '';
		}

		$max        = $length <= 5 ? 1 : 2;
		$candidates = (array) $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT term FROM ' . MVS_DB::terms() . '
			  WHERE lang = %s AND term LIKE %s AND LENGTH(term) BETWEEN %d AND %d',
			$lang,
			$wpdb->esc_like( mb_substr( $token, 0, 1, 'UTF-8' ) ) . '%',
			$length - $max,
			$length + $max
		) );

		$close = [];
		foreach ( $candidates as $term ) {
			$distance = levenshtein( $token, (string) $term );

			if ( $distance > 0 && $distance <= $max ) {
				$close[ (string) $term ] = $distance;
			}
		}

		if ( ! $close ) {
			return '';
		}

		$stats = [];
		foreach ( (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT term, COUNT(*) AS docs,
			        SUM(CASE WHEN title > 0 OR place > 0 OR hub > 0 OR guide > 0 THEN 1 ELSE 0 END) AS strong
			   FROM ' . MVS_DB::terms() . '
			  WHERE lang = %s AND term IN (' . MVS_DB::in_strings( array_keys( $close ) ) . ')
			  GROUP BY term',
			$lang
		), ARRAY_A ) as $row ) {
			$stats[ (string) $row['term'] ] = [ (int) $row['strong'], (int) $row['docs'] ];
		}

		$terms = array_keys( $close );
		usort( $terms, static fn( $a, $b ) => [ $close[ $a ], -( $stats[ $a ][0] ?? 0 ), -( $stats[ $a ][1] ?? 0 ), $a ]
			<=> [ $close[ $b ], -( $stats[ $b ][0] ?? 0 ), -( $stats[ $b ][1] ?? 0 ), $b ] );

		return (string) $terms[0];
	}
}

<?php
/**
 * Search suggestions from what visitors actually search, per language — for
 * the "try for example" line under the search box.
 *
 * A query is suggested when, over the last 60 days, it was searched at least
 * 3 times, found at least 5 results and needed no fallback — a proven good
 * search, not just a popular one. Up to two come first from the same weeks
 * last year (seasonal: "toussaint" in October), once the log reaches back
 * that far. Spellings that fold to the same words count once (the most
 * searched wins), and the current search is never suggested to itself.
 *
 * Tools → Mavo Search → "Never suggest" lists words or phrases that must not
 * appear; a line blocks every suggestion containing it.
 *
 * Fewer than three left: the caller keeps its hand-written examples.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Suggest {

	const BLOCK_OPTION = 'mavo_search_suggest_block';
	const DAYS         = 60;
	const SEASON_DAYS  = 21;
	const MIN_SEARCHES = 3;
	const MIN_RESULTS  = 5;
	const MIN_LENGTH   = 3;
	const MAX_LENGTH   = 40;

	/** @return string[] Suggested queries, as visitors typed them (lowercased). */
	public static function for_lang( string $lang, int $limit = 6, string $exclude = '' ): array {
		$key  = 'suggest:' . $lang . ':' . current_time( 'Y-m-d' );
		$list = MVS_Cache::get( $key );

		if ( ! is_array( $list ) ) {
			$today = time();
			$year  = $today - 365 * DAY_IN_SECONDS;

			$seasonal = self::top( $lang, $year - self::SEASON_DAYS * DAY_IN_SECONDS, $year + self::SEASON_DAYS * DAY_IN_SECONDS, 2 );
			$popular  = self::top( $lang, $today - self::DAYS * DAY_IN_SECONDS, $today, 30 );
			$list     = self::merge( array_merge( $seasonal, $popular ) );

			MVS_Cache::set( $key, $list );
		}

		$exclude = MVS_Text::normalize( $exclude );
		$list    = array_values( array_filter( $list, static fn( $q ) => MVS_Text::normalize( $q ) !== $exclude ) );

		/** Suggested queries for a language, best first. */
		return array_slice( array_values( (array) apply_filters( 'mavo_search_suggestions', $list, $lang ) ), 0, max( 0, $limit ) );
	}

	/**
	 * Proven searches that share a word with this one — "londres famille",
	 * "où dormir à londres" for "Londres" — most words in common first, then
	 * most searched. Never the query itself (folded), never a blocked one.
	 *
	 * @return string[]
	 */
	public static function related( string $query, string $lang, int $limit = 6 ): array {
		$mine = array_values( array_unique( array_filter(
			MVS_Text::tokens( MVS_Query::clean( $query ) ),
			static fn( $t ) => mb_strlen( $t, 'UTF-8' ) >= 3 && MVS_Text::indexable( $t, $lang )
		) ) );

		if ( ! $mine ) {
			return [];
		}

		$self  = MVS_Text::normalize( $query );
		$found = [];

		foreach ( self::proven( $lang ) as $i => $candidate ) {
			$norm = MVS_Text::normalize( $candidate );

			if ( $norm === $self ) {
				continue;
			}

			$shared = count( array_intersect( $mine, explode( ' ', $norm ) ) );

			if ( $shared > 0 ) {
				$found[] = [ $shared, $i, $candidate ];
			}
		}

		usort( $found, static fn( $a, $b ) => [ $b[0], $a[1] ] <=> [ $a[0], $b[1] ] );

		/** Related searches for a query, best first. */
		return array_slice( array_values( (array) apply_filters( 'mavo_search_related', array_column( $found, 2 ), $query, $lang ) ), 0, max( 0, $limit ) );
	}

	/**
	 * Every proven search of the last 60 days in a language, most searched
	 * first, deduped and filtered — cached for the day.
	 *
	 * @return string[]
	 */
	public static function proven( string $lang ): array {
		$key  = 'proven:' . $lang . ':' . current_time( 'Y-m-d' );
		$list = MVS_Cache::get( $key );

		if ( ! is_array( $list ) ) {
			$list = self::merge( self::top( $lang, time() - self::DAYS * DAY_IN_SECONDS, time(), 500 ) );
			MVS_Cache::set( $key, $list );
		}

		return $list;
	}

	/** @return string[] The "never suggest" lines. */
	public static function blocked(): array {
		return array_values( array_filter( array_map( 'trim', (array) get_option( self::BLOCK_OPTION, [] ) ) ) );
	}

	/** @param string $text One word or phrase per line. */
	public static function save_blocked( string $text ): void {
		$lines = array_values( array_unique( array_filter( array_map( static fn( $l ) => MVS_Query::clean( $l ), preg_split( '/\R/u', $text ) ?: [] ) ) ) );

		update_option( self::BLOCK_OPTION, $lines, false );
		MVS_Cache::bump();
	}

	/* -------------------------------------------------------------- private */

	/** @return string[] */
	private static function top( string $lang, int $from, int $to, int $limit ): array {
		global $wpdb;

		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare(
			'SELECT query FROM ' . MVS_DB::log() . "
			  WHERE lang = %s AND day BETWEEN %s AND %s
			  GROUP BY query
			 HAVING SUM(searches) >= %d AND MIN(results) >= %d AND MAX(fallback) = ''
			  ORDER BY SUM(searches) DESC, query ASC
			  LIMIT %d",
			$lang,
			gmdate( 'Y-m-d', $from ),
			gmdate( 'Y-m-d', $to ),
			self::MIN_SEARCHES,
			self::MIN_RESULTS,
			$limit
		) ) );
	}

	/** Dedupe by folded form, drop blocked and odd-sized ones. */
	private static function merge( array $queries ): array {
		$blocked = array_map( static fn( $b ) => ' ' . MVS_Text::normalize( $b ) . ' ', self::blocked() );
		$seen    = [];
		$out     = [];

		foreach ( $queries as $query ) {
			$norm   = MVS_Text::normalize( $query );
			$length = mb_strlen( $query, 'UTF-8' );

			if ( '' === $norm || isset( $seen[ $norm ] ) || $length < self::MIN_LENGTH || $length > self::MAX_LENGTH ) {
				continue;
			}

			foreach ( $blocked as $phrase ) {
				if ( '  ' !== $phrase && str_contains( ' ' . $norm . ' ', $phrase ) ) {
					continue 2;
				}
			}

			$seen[ $norm ] = true;
			$out[]         = $query;
		}

		return $out;
	}
}

<?php
/**
 * What visitors search for, counted per query, language and day.
 *
 * One row per (query, language, day) with a counter — never a row per
 * search, and never anything about the searcher: no IP, no user, no session.
 * Only first pages of GET searches are counted, not searches by people who
 * can edit posts (Relevanssi left out the two admin accounts), and not what
 * only crawlers and scanners send (junk()). Kept 400 days.
 *
 * The query is stored lowercased and trimmed, accents kept, so a report reads
 * as visitors wrote. Off switch: Tools → Mavo Search, or the
 * mavo_search_log_enabled filter.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Log {

	const ENABLED_OPTION = 'mavo_search_log_enabled';
	const PRUNE_HOOK     = 'mavo_search_prune_log';
	const KEEP_DAYS      = 400;

	public static function init(): void {
		add_action( self::PRUNE_HOOK, [ __CLASS__, 'prune' ] );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	public static function enabled(): bool {
		/** Whether searches are counted at all. */
		return (bool) apply_filters( 'mavo_search_log_enabled', '0' !== (string) get_option( self::ENABLED_OPTION, '1' ) );
	}

	/** A query as the log and the click counts store it: lowercased, trimmed, accents kept. */
	public static function key( string $query ): string {
		return mb_substr( mb_strtolower( MVS_Query::clean( $query ), 'UTF-8' ), 0, 191, 'UTF-8' );
	}

	/**
	 * Not a visitor's search: something only a crawler or a scanner sends.
	 * Searched like any query, never counted — so it reaches neither the
	 * reports nor the suggestions.
	 *
	 *   encoded more than once (a crawler re-encoding a stored URL)
	 *   not UTF-8 once decoded (cut-off bytes, overlong quotes)
	 *   backslashes, angle brackets or control characters (injection probes)
	 *   more than two quotes, or longer than 100 characters
	 *
	 * @param string $raw The query as received, before cleaning.
	 */
	public static function junk( string $raw ): bool {
		$rounds  = 0;
		$decoded = MVS_Query::decode( $raw, $rounds );

		return $rounds > 0
			|| ! mb_check_encoding( $raw, 'UTF-8' )
			|| (bool) preg_match( '/[\\\\<>\x00-\x1f\x7f]/u', $decoded )
			|| preg_match_all( '/["\'\x{2019}]/u', $decoded ) > 2
			|| mb_strlen( trim( $decoded ), 'UTF-8' ) > 100;
	}

	public static function record( string $query, string $lang, int $results, string $fallback = 'none' ): void {
		global $wpdb;

		if ( self::junk( $query ) ) {
			return;
		}

		$query = self::key( $query );

		if ( '' === $query || ! self::enabled() ) {
			return;
		}

		$day = current_time( 'Y-m-d' );
		$id  = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . MVS_DB::log() . ' WHERE query = %s AND lang = %s AND day = %s',
			$query,
			$lang,
			$day
		) );

		$fallback = 'none' === $fallback ? '' : $fallback;

		if ( ! $id ) {
			$inserted = $wpdb->insert( MVS_DB::log(), [
				'query'    => $query,
				'lang'     => $lang,
				'day'      => $day,
				'searches' => 1,
				'results'  => $results,
				'fallback' => $fallback,
			] );

			// Lost a race with an identical search: count it on that row.
			if ( false === $inserted ) {
				$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . MVS_DB::log() . ' WHERE query = %s AND lang = %s AND day = %s', $query, $lang, $day ) );
			}
		}

		if ( $id ) {
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . MVS_DB::log() . ' SET searches = searches + 1, results = %d, fallback = %s WHERE id = %d',
				$results,
				$fallback,
				$id
			) );
		}

		/** Fires after a search was counted. Receives nothing about the visitor. */
		do_action( 'mavo_search_query_logged', $query, $lang, $results );
	}

	/**
	 * Queries by searches over the last $days.
	 *
	 * @param string $which top | zero (no result) | low (1–3 results) | fallback (only partial matches)
	 * @return array<int,array{query:string,lang:string,searches:int,results:int,fallback:string,last:string}>
	 */
	public static function report( string $which = 'top', int $days = 30, ?string $lang = null, int $limit = 25 ): array {
		global $wpdb;

		$where = [ $wpdb->prepare( 'day >= %s', gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS ) ) ];

		if ( null !== $lang ) {
			$where[] = $wpdb->prepare( 'lang = %s', $lang );
		}

		$having = match ( $which ) {
			'zero'     => 'HAVING MAX(results) = 0',
			'low'      => 'HAVING MAX(results) BETWEEN 1 AND 3',
			'fallback' => "HAVING MAX(fallback) <> ''",
			default    => '',
		};

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT query, lang, SUM(searches) AS searches, MAX(results) AS results, MAX(fallback) AS fallback, MAX(day) AS last
			   FROM ' . MVS_DB::log() . '
			  WHERE ' . implode( ' AND ', $where ) . "
			  GROUP BY query, lang $having
			  ORDER BY searches DESC, last DESC
			  LIMIT %d",
			$limit
		), ARRAY_A );

		return array_map( static fn( $r ) => [
			'query'    => (string) $r['query'],
			'lang'     => (string) $r['lang'],
			'searches' => (int) $r['searches'],
			'results'  => (int) $r['results'],
			'fallback' => (string) $r['fallback'],
			'last'     => (string) $r['last'],
		], $rows );
	}

	/** @return array<string,array{searches:int,queries:int,zero:int}> lang => totals over $days */
	public static function by_language( int $days = 30 ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT lang, SUM(searches) AS searches, COUNT(DISTINCT query) AS queries,
			        SUM(CASE WHEN results = 0 THEN searches ELSE 0 END) AS zero
			   FROM ' . MVS_DB::log() . ' WHERE day >= %s GROUP BY lang ORDER BY searches DESC',
			gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS )
		), ARRAY_A );

		$out = [];
		foreach ( $rows as $row ) {
			$out[ $row['lang'] ] = [ 'searches' => (int) $row['searches'], 'queries' => (int) $row['queries'], 'zero' => (int) $row['zero'] ];
		}

		return $out;
	}

	public static function prune(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MVS_DB::log() . ' WHERE day < %s', gmdate( 'Y-m-d', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
	}
}

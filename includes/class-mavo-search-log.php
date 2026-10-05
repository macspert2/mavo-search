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

	/**
	 * New queries counted per day, at most: a bot sending thousands of
	 * random searches cannot bloat the table. Far above what visitors type.
	 */
	const MAX_NEW_PER_DAY = 2000;

	/**
	 * What vulnerability scanners put in ?s= and no visitor types: SQL
	 * (sleep(), union select, waitfor delay, dbms_pipe, or 3=3…; a
	 * word followed by a space and a bracket, "char (voile)", is not one), template
	 * and expression injection (${…}, {{…}}, jndi:), file paths
	 * (/etc/passwd, ../), and the scanners' own callback hosts (bxss.me,
	 * Acunetix, Burp Collaborator, interactsh). Matched against the decoded,
	 * lowercased query. mavo-quick-404 keeps its own copy of this list to
	 * answer such searches with a 403 before WordPress loads; keep the two
	 * alike.
	 */
	const PROBES = '~
		\b(?:union\s+(?:all\s+)?select|insert\s+into|drop\s+table|information_schema|waitfor\s+delay|xp_cmdshell|dbms_pipe)\b
		| \b(?:sleep|pg_sleep|benchmark|md5|sha1|concat|char|chr|extractvalue|updatexml|load_file|print|eval|exec|system|phpinfo|base64_decode|sysdate|now)\(
		| @@[a-z] | \$\{ | \{\{ | <\? | \bjndi: | /etc/(?:passwd|hosts|shadow) | win\.ini | web-inf | \.\.[/\\\\]
		| bxss\.me | \.acu/ | vulnweb | acunetix | burpcollaborator | oastify | interact\.sh | \boast\.[a-z] | dnslog | nslookup | response\.write
		| \b(?:and|or|xor)\s+\d[\d\s+*-]*[=<>]
	~xu';

	/**
	 * Bump when PROBES or junk() change: rows counted under the old rules
	 * that are junk under the new ones are then deleted once (purge_junk()).
	 */
	const JUNK_RULES        = 3;
	const JUNK_RULES_OPTION = 'mavo_search_junk_rules';
	const PURGE_HOOK        = 'mavo_search_purge_junk';

	public static function init(): void {
		add_action( self::PRUNE_HOOK, [ __CLASS__, 'prune' ] );
		add_action( self::PURGE_HOOK, [ __CLASS__, 'purge_junk' ] );

		if ( self::JUNK_RULES !== (int) get_option( self::JUNK_RULES_OPTION, 0 ) && ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::PURGE_HOOK );
		}
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
	 *   { } $ = ; | ^ * @ or backticks — code, not words; a visitor's rare
	 *   "crète*" only goes uncounted
	 *   a scanner's signature (PROBES)
	 *   a run of brackets, quotes, commas and dots with at least one bracket
	 *   and one quote: the syntax breakers that follow a scanner's baseline
	 *   word, "eavz)'(,,).)"("
	 *   a locale or page segment, "maurice/fr-fr", "spa/page/7/en-gb": not a
	 *   scanner but crawlers appending a path to a search URL
	 *   more than two quotes, or longer than 100 characters
	 *
	 * @param string $raw The query as received, before cleaning.
	 */
	public static function junk( string $raw ): bool {
		$rounds  = 0;
		$decoded = MVS_Query::decode( $raw, $rounds );

		return $rounds > 0
			|| ! mb_check_encoding( $raw, 'UTF-8' )
			|| (bool) preg_match( '/[\\\\<>{}$=;|^*@`\x00-\x1f\x7f]/u', $decoded )
			|| self::probe( $decoded )
			|| self::breaker( $decoded )
			|| (bool) preg_match( '~/(?:[a-z]{2}-[a-z]{2}|page/\d+)(/|$)~i', $decoded )
			|| preg_match_all( '/["\'\x{2019}]/u', $decoded ) > 2
			|| mb_strlen( trim( $decoded ), 'UTF-8' ) > 100;
	}

	/** A scanner's signature in the (decoded) query. */
	public static function probe( string $query ): bool {
		return (bool) preg_match( self::PROBES, mb_strtolower( $query, 'UTF-8' ) );
	}

	/** Brackets, quotes, commas and dots in a run, with a bracket and a quote among them: )'(,,).)"( */
	public static function breaker( string $query ): bool {
		preg_match_all( '/[()\'"\x{2019},.]{4,}/u', $query, $runs );

		foreach ( $runs[0] as $run ) {
			if ( preg_match( '/[()]/', $run ) && preg_match( '/[\'"\x{2019}]/u', $run ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The random word a scanner searches alone before probing with it —
	 * "eavz" five times, then "eavz)'(,,).)"(" — so that the word's own rows
	 * can go when the probe shows what they were. '' when $raw does not
	 * start with a word glued to the probe.
	 */
	public static function baseline( string $raw ): string {
		return preg_match( '/^\s*(\p{L}{3,12})[()\'"\x{2019},.\\\\<>]/u', MVS_Query::decode( $raw ), $m ) ? mb_strtolower( $m[1], 'UTF-8' ) : '';
	}

	/** Delete a scanner's baseline word for that language and day — only where it found nothing. */
	private static function forget_baseline( string $raw, string $lang, string $day ): void {
		global $wpdb;

		$word = self::baseline( $raw );

		if ( '' !== $word ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MVS_DB::log() . ' WHERE query = %s AND lang = %s AND day = %s AND results = 0', $word, $lang, $day ) );
		}
	}

	/**
	 * Delete the log and click rows of queries that junk() now refuses —
	 * counted before a rule existed — and the baselines they reveal. Runs
	 * once per JUNK_RULES.
	 *
	 * @return int Queries removed.
	 */
	public static function purge_junk(): int {
		global $wpdb;

		foreach ( (array) $wpdb->get_results( 'SELECT query, lang, day FROM ' . MVS_DB::log(), ARRAY_A ) as $row ) {
			if ( self::junk( $row['query'] ) ) {
				self::forget_baseline( $row['query'], $row['lang'], $row['day'] );
			}
		}

		$queries = array_merge(
			(array) $wpdb->get_col( 'SELECT DISTINCT query FROM ' . MVS_DB::log() ),
			(array) $wpdb->get_col( 'SELECT DISTINCT query FROM ' . MVS_DB::clicks() )
		);
		$junk    = array_values( array_unique( array_filter( $queries, [ __CLASS__, 'junk' ] ) ) );

		foreach ( array_chunk( $junk, 200 ) as $chunk ) {
			$in = MVS_DB::in_strings( $chunk );
			$wpdb->query( 'DELETE FROM ' . MVS_DB::log() . " WHERE query IN ($in)" );
			$wpdb->query( 'DELETE FROM ' . MVS_DB::clicks() . " WHERE query IN ($in)" );
		}

		update_option( self::JUNK_RULES_OPTION, self::JUNK_RULES, true );

		return count( $junk );
	}

	public static function record( string $query, string $lang, int $results, string $fallback = 'none' ): void {
		global $wpdb;

		if ( self::junk( $query ) ) {
			if ( self::enabled() ) {
				self::forget_baseline( $query, $lang, current_time( 'Y-m-d' ) );
			}
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
			$cap = (int) apply_filters( 'mavo_search_log_daily_cap', self::MAX_NEW_PER_DAY );

			if ( $cap > 0 && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MVS_DB::log() . ' WHERE day = %s', $day ) ) >= $cap ) {
				return;
			}

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

<?php
/**
 * Which results visitors click, counted the way the search log counts
 * searches: one row per (query, language, day, post, source) with a counter
 * and the sum of the ranks clicked. Nothing about the visitor, ever.
 *
 *   source  result  a result tile in the list
 *           pinned  a result that was a best bet (decided here, not by the page)
 *           photos  a tile of the photo row of a purely visual query
 *           guides  a tile of the guides band above the results
 *
 * assets/clicks.js reports a click with navigator.sendBeacon to
 * POST /wp-json/mavo-search/v1/click — it never delays the navigation. It is
 * only loaded on searches Mavo Search answered, for visitors who cannot edit
 * posts, while counting is on (the search log's switch covers both).
 *
 * The endpoint is open, as any counter on a public page is; it accepts only
 * a post indexed in the given language, a sane rank and a known source, so
 * the worst a forger can do is inflate counts.
 *
 * Nothing uses the counts to rank yet. They are collected now because a
 * learned boost, or judging any ranking change, needs months of them.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Clicks {

	const REST_NS   = 'mavo-search/v1';
	const SOURCES   = [ 'result', 'pinned', 'photos', 'guides' ];
	const MAX_RANK  = 500;

	public static function init(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( MVS_Log::PRUNE_HOOK, [ __CLASS__, 'prune' ] );
	}

	public static function routes(): void {
		register_rest_route( self::REST_NS, '/click', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'q'      => [ 'type' => 'string', 'required' => true ],
				'lang'   => [ 'type' => 'string', 'required' => true ],
				'post'   => [ 'type' => 'integer', 'required' => true ],
				'rank'   => [ 'type' => 'integer', 'required' => true ],
				'source' => [ 'type' => 'string', 'default' => 'result' ],
			],
		] );
	}

	public static function rest( $request ) {
		$ok = self::record( (string) $request['q'], (string) $request['lang'], (int) $request['post'], (int) $request['rank'], (string) $request['source'] );

		return new WP_REST_Response( null, $ok ? 204 : 400 );
	}

	/** Count one click. False when anything about it does not check out. */
	public static function record( string $query, string $lang, int $post_id, int $rank, string $source = 'result' ): bool {
		global $wpdb;

		$query = MVS_Log::key( $query );
		$lang  = MVS_Lang::normalize( $lang );

		if ( '' === $query || null === $lang || $post_id <= 0 || $rank < 1 || $rank > self::MAX_RANK || ! in_array( $source, self::SOURCES, true ) || ! MVS_Log::enabled() ) {
			return false;
		}

		$indexed = $wpdb->get_var( $wpdb->prepare( 'SELECT doc_id FROM ' . MVS_DB::docs() . " WHERE post_id = %d AND lang = %s AND status = 'indexed'", $post_id, $lang ) );

		if ( ! $indexed ) {
			return false;
		}

		if ( 'result' === $source && in_array( $post_id, MVS_Best_Bets::for_query( MVS_Text::normalize( $query ) ), true ) ) {
			$source = 'pinned';
		}

		$day   = current_time( 'Y-m-d' );
		$where = $wpdb->prepare( 'query = %s AND lang = %s AND day = %s AND post_id = %d AND source = %s', $query, $lang, $day, $post_id, $source );
		$id    = (int) $wpdb->get_var( 'SELECT id FROM ' . MVS_DB::clicks() . " WHERE $where" );

		if ( ! $id ) {
			$inserted = $wpdb->insert( MVS_DB::clicks(), [
				'query'      => $query,
				'lang'       => $lang,
				'day'        => $day,
				'post_id'    => $post_id,
				'source'     => $source,
				'clicks'     => 1,
				'rank_total' => $rank,
			] );

			if ( false !== $inserted ) {
				return true;
			}

			// Lost a race with an identical click: count it on that row.
			$id = (int) $wpdb->get_var( 'SELECT id FROM ' . MVS_DB::clicks() . " WHERE $where" );
		}

		if ( $id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . MVS_DB::clicks() . ' SET clicks = clicks + 1, rank_total = rank_total + %d WHERE id = %d', $rank, $id ) );
		}

		return (bool) $id;
	}

	/**
	 * Per query over the last $days: searches (from the log), clicks, the
	 * average rank clicked, and the post clicked most.
	 *
	 * @return array<int,array{query:string,lang:string,searches:int,clicks:int,avg_rank:float,top_post:int,top_clicks:int}>
	 */
	public static function report( int $days = 30, ?string $lang = null, int $limit = 25 ): array {
		global $wpdb;

		$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$where = $wpdb->prepare( 'day >= %s', $since ) . ( null === $lang ? '' : $wpdb->prepare( ' AND lang = %s', $lang ) );

		$rows = (array) $wpdb->get_results(
			'SELECT query, lang, post_id, SUM(clicks) AS clicks, SUM(rank_total) AS rank_total
			   FROM ' . MVS_DB::clicks() . " WHERE $where
			  GROUP BY query, lang, post_id",
			ARRAY_A
		);

		$out = [];

		foreach ( $rows as $row ) {
			$key = $row['lang'] . '|' . $row['query'];
			$out[ $key ] ??= [ 'query' => (string) $row['query'], 'lang' => (string) $row['lang'], 'searches' => 0, 'clicks' => 0, 'rank_total' => 0, 'top_post' => 0, 'top_clicks' => 0 ];

			$out[ $key ]['clicks']     += (int) $row['clicks'];
			$out[ $key ]['rank_total'] += (int) $row['rank_total'];

			if ( (int) $row['clicks'] > $out[ $key ]['top_clicks'] ) {
				$out[ $key ]['top_post']   = (int) $row['post_id'];
				$out[ $key ]['top_clicks'] = (int) $row['clicks'];
			}
		}

		if ( ! $out ) {
			return [];
		}

		$searches = (array) $wpdb->get_results(
			'SELECT query, lang, SUM(searches) AS searches FROM ' . MVS_DB::log() . " WHERE $where GROUP BY query, lang",
			ARRAY_A
		);

		foreach ( $searches as $row ) {
			$key = $row['lang'] . '|' . $row['query'];

			if ( isset( $out[ $key ] ) ) {
				$out[ $key ]['searches'] = (int) $row['searches'];
			}
		}

		$out = array_map( static function ( $r ) {
			$r['avg_rank'] = round( $r['rank_total'] / max( 1, $r['clicks'] ), 1 );
			unset( $r['rank_total'] );
			return $r;
		}, array_values( $out ) );

		usort( $out, static fn( $a, $b ) => [ $b['clicks'], $b['searches'] ] <=> [ $a['clicks'], $a['searches'] ] );

		return array_slice( $out, 0, $limit );
	}

	/**
	 * Queries searched at least twice with results, but never clicked: the
	 * results probably did not look like an answer.
	 *
	 * @return array<int,array{query:string,lang:string,searches:int,results:int}>
	 */
	public static function unclicked( int $days = 30, ?string $lang = null, int $limit = 25 ): array {
		global $wpdb;

		$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$where = $wpdb->prepare( 'l.day >= %s', $since ) . ( null === $lang ? '' : $wpdb->prepare( ' AND l.lang = %s', $lang ) );

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT l.query, l.lang, SUM(l.searches) AS searches, MAX(l.results) AS results
			   FROM ' . MVS_DB::log() . " l
			  WHERE $where AND l.results > 0
			    AND NOT EXISTS ( SELECT 1 FROM " . MVS_DB::clicks() . ' c WHERE c.query = l.query AND c.lang = l.lang AND c.day >= %s )
			  GROUP BY l.query, l.lang
			 HAVING SUM(l.searches) >= 2
			  ORDER BY searches DESC
			  LIMIT %d',
			$since,
			$limit
		), ARRAY_A );

		return array_map( static fn( $r ) => [ 'query' => (string) $r['query'], 'lang' => (string) $r['lang'], 'searches' => (int) $r['searches'], 'results' => (int) $r['results'] ], $rows );
	}

	/** @return array<string,int> source => clicks over $days */
	public static function by_source( int $days = 30 ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT source, SUM(clicks) AS clicks FROM ' . MVS_DB::clicks() . ' WHERE day >= %s GROUP BY source',
			gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS )
		), ARRAY_A );

		return array_map( 'intval', array_column( $rows, 'clicks', 'source' ) );
	}

	public static function prune(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MVS_DB::clicks() . ' WHERE day < %s', gmdate( 'Y-m-d', time() - MVS_Log::KEEP_DAYS * DAY_IN_SECONDS ) ) );
	}
}

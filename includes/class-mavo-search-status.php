<?php
/**
 * How complete and current the index is. Admin and CLI only — never on a
 * search request.
 *
 * Every post that should be indexed is one of:
 *
 *   current  its document matches the post's last modification and the
 *            current index rules
 *   stale    the post was modified since, or the rules changed
 *   failed   the last attempt to index it threw
 *   missing  no document at all
 *
 * plus orphans: documents of posts that should no longer be indexed. All in
 * SQL; "should be indexed" is the SQL-expressible part of
 * MVS_Document::eligible() — type, status, Yoast noindex. A
 * mavo_search_index_post filter is honoured when a post is indexed, not here.
 *
 * What a post's modification date cannot show — a place renamed in
 * geotag-plus, a hub retitled, an alt text edited — reaches the index through
 * MVS_Sync's hooks, and "Rebuild all" catches anything those miss.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Status {

	public static function summary(): array {
		global $wpdb;

		$rules    = MVS_Document::rules_version();
		$eligible = self::eligible_sql();
		$docs     = MVS_DB::docs();

		$by_lang = [];
		foreach ( (array) $wpdb->get_results( "SELECT lang, COUNT(*) AS n FROM $docs WHERE status = 'indexed' GROUP BY lang", ARRAY_A ) as $row ) {
			$by_lang[ $row['lang'] ] = (int) $row['n'];
		}

		$row = (array) $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS eligible,
			        SUM(CASE WHEN d.doc_id IS NULL THEN 1 ELSE 0 END) AS missing,
			        SUM(CASE WHEN d.status = 'failed' THEN 1 ELSE 0 END) AS failed,
			        SUM(CASE WHEN d.status = 'indexed' AND ( d.post_modified IS NULL OR d.post_modified <> p.post_modified_gmt OR d.rules_version <> %s ) THEN 1 ELSE 0 END) AS stale
			   FROM {$wpdb->posts} p
			   LEFT JOIN $docs d ON d.post_id = p.ID
			  WHERE " . self::eligible_where(),
			$rules
		), ARRAY_A );

		$orphans = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $docs d WHERE NOT EXISTS ( SELECT 1 $eligible AND p.ID = d.post_id )" );

		$fields = [];
		$sums   = implode( ', ', array_map( static fn( $f ) => "SUM(CASE WHEN $f > 0 THEN 1 ELSE 0 END) AS $f", MVS_DB::fields() ) );
		$counts = (array) $wpdb->get_row( 'SELECT COUNT(*) AS term_rows, ' . $sums . ' FROM ' . MVS_DB::terms(), ARRAY_A );

		foreach ( MVS_DB::fields() as $field ) {
			$fields[ $field ] = (int) ( $counts[ $field ] ?? 0 );
		}

		$eligible_n = (int) ( $row['eligible'] ?? 0 );
		$failed     = (int) ( $row['failed'] ?? 0 );
		$stale      = (int) ( $row['stale'] ?? 0 );
		$missing    = (int) ( $row['missing'] ?? 0 );

		return [
			'eligible'      => $eligible_n,
			'documents'     => array_sum( $by_lang ),
			'by_lang'       => $by_lang,
			'current'       => max( 0, $eligible_n - $failed - $stale - $missing ),
			'stale'         => $stale,
			'failed'        => $failed,
			'missing'       => $missing,
			'orphans'       => $orphans,
			'term_rows'     => (int) ( $counts['term_rows'] ?? 0 ),
			'rows_by_field' => $fields,
			'distinct_terms' => (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT term) FROM ' . MVS_DB::terms() ),
			'size'          => self::size(),
			'ready'         => MVS_WP::ready(),
			'last_rebuild'  => (array) get_option( MVS_Rebuild::LAST_OPTION, [] ),
			'background'    => MVS_Rebuild::background_state(),
			'queue'         => count( MVS_Sync::stored_queue() ),
			'db_version'    => (int) get_option( MVS_DB::DB_VERSION_OPTION, 0 ),
			'rules_version' => $rules,
		];
	}

	/** Failed documents with their errors, newest first. */
	public static function failures( int $limit = 20 ): array {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT post_id, lang, error, indexed_at FROM ' . MVS_DB::docs() . " WHERE status = 'failed' ORDER BY indexed_at DESC LIMIT %d",
			$limit
		), ARRAY_A );
	}

	/**
	 * Posts needing work above a cursor, ascending: missing, stale, failed,
	 * and orphans (which indexing removes).
	 *
	 * @return int[]
	 */
	public static function stale_ids( int $after, int $limit ): array {
		global $wpdb;

		$eligible = self::eligible_sql();
		$docs     = MVS_DB::docs();

		$ids = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID $eligible
			    AND p.ID > %d
			    AND NOT EXISTS ( SELECT 1 FROM $docs d
			                      WHERE d.post_id = p.ID AND d.status = 'indexed' AND d.post_modified = p.post_modified_gmt AND d.rules_version = %s )
			  ORDER BY p.ID LIMIT %d",
			$after,
			MVS_Document::rules_version(),
			$limit
		) );

		$ids = array_merge( $ids, (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT d.post_id FROM $docs d
			  WHERE d.post_id > %d AND NOT EXISTS ( SELECT 1 $eligible AND p.ID = d.post_id )
			  ORDER BY d.post_id LIMIT %d",
			$after,
			$limit
		) ) );

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );

		return array_slice( $ids, 0, $limit );
	}

	/** "FROM posts p WHERE <should be indexed>", for embedding. */
	public static function eligible_sql(): string {
		global $wpdb;

		return "FROM {$wpdb->posts} p WHERE " . self::eligible_where();
	}

	/** The condition alone, on posts aliased p. */
	public static function eligible_where(): string {
		global $wpdb;

		return $wpdb->prepare(
			'p.post_type IN (' . MVS_DB::in_strings( MVS_Document::post_types() ) . ")
			    AND p.post_status = 'publish'
			    AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} nm WHERE nm.post_id = p.ID AND nm.meta_key = %s AND nm.meta_value = '1' )",
			MVS_Document::NOINDEX_META
		);
	}

	/** Bytes of the three tables, or null where the database will not say. */
	private static function size(): ?int {
		global $wpdb;

		if ( empty( $wpdb->is_mysql ) ) {
			return null;
		}

		$tables = [ MVS_DB::docs(), MVS_DB::terms(), MVS_DB::log() ];
		$size   = $wpdb->get_var(
			'SELECT SUM(data_length + index_length) FROM information_schema.TABLES
			  WHERE table_schema = DATABASE() AND table_name IN (' . MVS_DB::in_strings( $tables ) . ')'
		);

		return null === $size || '' !== $wpdb->last_error ? null : (int) $size;
	}
}

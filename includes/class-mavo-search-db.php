<?php
/**
 * The three tables, and nothing else that knows their shape.
 *
 *   docs   one row per indexed post: the text excerpts and titles are cut
 *          from, its language, its editorial boost, and the bookkeeping that
 *          says whether it is current
 *   terms  the inverted index: one row per document × term, with one column
 *          per field — how often the term occurs in the title, the text, the
 *          alt text… (or, for place / hub / concept, how strongly: 0–100)
 *   log    one row per normalized query × language × day
 *   clicks one row per query × language × day × clicked post × source
 *
 * One row per term with a column per field, as Relevanssi does, rather than
 * agent.md's row per term × field: a lookup is one index range per term
 * instead of one per field, and the table is several times smaller.
 */

defined( 'ABSPATH' ) || exit;

class MVS_DB {

	/**
	 * Bump whenever install()'s CREATE TABLE statements change, so
	 * maybe_upgrade() re-runs dbDelta on sites that already have the tables.
	 *
	 * 2 — the clicks table (2026-10-04).
	 */
	const DB_VERSION        = 2;
	const DB_VERSION_OPTION = 'mavo_search_db_version';

	/** The terms columns, in order. Text fields count; the others weigh. */
	const TEXT_FIELDS   = [ 'title', 'heading', 'content', 'excerpt', 'alt', 'taxonomy', 'custom', 'guide' ];
	const WEIGHT_FIELDS = [ 'place', 'hub', 'concept' ];

	public static function docs(): string {
		global $wpdb;
		return $wpdb->prefix . 'mavo_search_docs';
	}

	public static function terms(): string {
		global $wpdb;
		return $wpdb->prefix . 'mavo_search_terms';
	}

	public static function log(): string {
		global $wpdb;
		return $wpdb->prefix . 'mavo_search_log';
	}

	public static function clicks(): string {
		global $wpdb;
		return $wpdb->prefix . 'mavo_search_clicks';
	}

	/** @return string[] Every per-field column of the terms table. */
	public static function fields(): array {
		return array_merge( self::TEXT_FIELDS, self::WEIGHT_FIELDS );
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// Terms are already folded to lowercase without accents. A binary
		// collation keeps MySQL from deciding two of them are the same word
		// anyway, which would break the primary key, and makes LIKE 'plage%'
		// an exact prefix scan.
		$bin = 'utf8mb4' === ( $wpdb->charset ?? '' ) ? 'utf8mb4_bin' : 'utf8_bin';

		$columns = '';
		foreach ( self::fields() as $field ) {
			$columns .= "\t\t\t$field SMALLINT UNSIGNED NOT NULL DEFAULT 0,\n";
		}

		// dbDelta is particular: one column per line, two spaces after
		// PRIMARY KEY, and KEY rather than INDEX.
		dbDelta( 'CREATE TABLE ' . self::docs() . " (
			doc_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id       BIGINT UNSIGNED NOT NULL,
			lang          VARCHAR(10) NOT NULL,
			post_type     VARCHAR(20) NOT NULL,
			title         TEXT NOT NULL,
			title_norm    TEXT NOT NULL,
			excerpt       TEXT NOT NULL,
			content       MEDIUMTEXT NOT NULL,
			boost         DECIMAL(5,3) NOT NULL DEFAULT 1.000,
			signals       TEXT NOT NULL,
			post_date     DATETIME NULL DEFAULT NULL,
			post_modified DATETIME NULL DEFAULT NULL,
			source_hash   CHAR(32) NOT NULL DEFAULT '',
			rules_version CHAR(32) NOT NULL DEFAULT '',
			status        VARCHAR(10) NOT NULL DEFAULT 'indexed',
			error         VARCHAR(255) NOT NULL DEFAULT '',
			indexed_at    DATETIME NOT NULL,
			PRIMARY KEY  (doc_id),
			UNIQUE KEY post_id (post_id),
			KEY lang_type (lang, post_type),
			KEY indexed_at (indexed_at)
		) $charset_collate;" );

		dbDelta( 'CREATE TABLE ' . self::terms() . " (
			doc_id        BIGINT UNSIGNED NOT NULL,
			term          VARCHAR(64) COLLATE $bin NOT NULL,
			lang          VARCHAR(10) NOT NULL,
$columns			PRIMARY KEY  (doc_id, term),
			KEY term_lang (term, lang)
		) $charset_collate;" );

		dbDelta( 'CREATE TABLE ' . self::log() . " (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			query      VARCHAR(191) NOT NULL,
			lang       VARCHAR(10) NOT NULL,
			day        DATE NOT NULL,
			searches   INT UNSIGNED NOT NULL DEFAULT 0,
			results    INT UNSIGNED NOT NULL DEFAULT 0,
			fallback   VARCHAR(10) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY query_lang_day (query, lang, day),
			KEY day (day),
			KEY results (results)
		) $charset_collate;" );

		dbDelta( 'CREATE TABLE ' . self::clicks() . " (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			query      VARCHAR(191) NOT NULL,
			lang       VARCHAR(10) NOT NULL,
			day        DATE NOT NULL,
			post_id    BIGINT UNSIGNED NOT NULL,
			source     VARCHAR(10) NOT NULL DEFAULT 'result',
			clicks     INT UNSIGNED NOT NULL DEFAULT 0,
			rank_total INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY query_lang_day_post (query, lang, day, post_id, source),
			KEY day (day),
			KEY post_id (post_id)
		) $charset_collate;" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/** IN ( 1, 2 ) for a list cast to int. Never empty. */
	public static function in_ints( array $ids ): string {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		return $ids ? implode( ',', $ids ) : '0';
	}

	/** IN ( 'a', 'b' ) for strings, each escaped through prepare(). */
	public static function in_strings( array $values ): string {
		global $wpdb;

		$values = array_values( array_unique( array_map( 'strval', $values ) ) );

		if ( ! $values ) {
			return "''";
		}

		return $wpdb->prepare( implode( ',', array_fill( 0, count( $values ), '%s' ) ), ...$values );
	}

	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Throw on a failed write, so the indexer can mark one post failed and
	 * carry on rather than leave half its rows behind silently.
	 */
	public static function check( $result ): void {
		global $wpdb;

		if ( false === $result ) {
			throw new RuntimeException( $wpdb->last_error ?: 'database write failed' );
		}
	}
}

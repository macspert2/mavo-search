<?php
/**
 * wp mavo-search — status, rebuilds, searching with explanations, a post's
 * terms, and the search log.
 */

defined( 'ABSPATH' ) || exit;

class MVS_CLI {

	/**
	 * Index coverage and freshness.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 */
	public function status( $args, $assoc ) {
		$s = MVS_Status::summary();

		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			WP_CLI::line( wp_json_encode( $s, JSON_PRETTY_PRINT ) );
			return;
		}

		WP_CLI\Utils\format_items( 'table', [ [
			'eligible' => $s['eligible'],
			'current'  => $s['current'],
			'stale'    => $s['stale'],
			'missing'  => $s['missing'],
			'failed'   => $s['failed'],
			'orphans'  => $s['orphans'],
		] ], [ 'eligible', 'current', 'stale', 'missing', 'failed', 'orphans' ] );

		WP_CLI::line( sprintf( 'Documents: %d %s', $s['documents'], wp_json_encode( $s['by_lang'] ) ) );
		WP_CLI::line( sprintf( 'Term rows: %d (%d distinct) %s', $s['term_rows'], $s['distinct_terms'], wp_json_encode( $s['rows_by_field'] ) ) );

		if ( null !== $s['size'] ) {
			WP_CLI::line( 'Size: ' . size_format( $s['size'] ) );
		}

		WP_CLI::line( 'Serving searches: ' . ( $s['ready'] ? 'yes' : 'no — run a full rebuild' ) );
		WP_CLI::line( sprintf( 'Schema %d, rules %s, queue %d', $s['db_version'], substr( $s['rules_version'], 0, 8 ), $s['queue'] ) );

		foreach ( $s['last_rebuild'] as $mode => $time ) {
			WP_CLI::line( sprintf( 'Last %s rebuild: %s', $mode, wp_date( 'Y-m-d H:i', (int) $time ) ) );
		}

		foreach ( MVS_Status::failures( 10 ) as $row ) {
			WP_CLI::warning( sprintf( 'Post %d failed: %s', $row['post_id'], $row['error'] ) );
		}
	}

	/**
	 * Rebuild the index, in batches.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Every published post and page, forced. Makes the index serve searches when complete.
	 *
	 * [--stale]
	 * : Only missing, modified, failed and orphaned documents.
	 *
	 * [--post=<id>]
	 * : One post.
	 *
	 * [--batch-size=<n>]
	 * : Posts per batch.
	 * ---
	 * default: 250
	 * ---
	 *
	 * [--lang=<lang>]
	 * : Only posts in this language.
	 *
	 * [--post-type=<type>]
	 * : Only this post type.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mavo-search rebuild --all
	 *     wp mavo-search rebuild --stale
	 *     wp mavo-search rebuild --post=123
	 *     wp mavo-search rebuild --all --lang=de --batch-size=100
	 */
	public function rebuild( $args, $assoc ) {
		if ( ! empty( $assoc['post'] ) ) {
			$id     = absint( $assoc['post'] );
			$result = MVS_Indexer::index( [ $id ], true )[ $id ] ?? 'removed';

			WP_CLI::success( sprintf( 'Post %d: %s', $id, $result ) );
			return;
		}

		$mode = ! empty( $assoc['all'] ) ? 'all' : ( ! empty( $assoc['stale'] ) ? 'stale' : '' );

		if ( '' === $mode ) {
			WP_CLI::error( 'Say what to rebuild: --all, --stale or --post=<id>.' );
		}

		$filters = array_filter( [
			'lang'      => isset( $assoc['lang'] ) ? MVS_Lang::normalize( (string) $assoc['lang'] ) : null,
			'post_type' => isset( $assoc['post-type'] ) ? sanitize_key( (string) $assoc['post-type'] ) : null,
		] );

		if ( isset( $assoc['lang'] ) && empty( $filters['lang'] ) ) {
			WP_CLI::error( 'Unknown language: ' . $assoc['lang'] );
		}

		$batch    = max( 1, (int) ( $assoc['batch-size'] ?? 250 ) );
		$cursor   = 0;
		$done     = 0;
		$failed   = [];
		$progress = null;

		do {
			$step = MVS_Rebuild::step( $mode, $cursor, $batch, $filters );

			if ( null === $progress ) {
				$progress = WP_CLI\Utils\make_progress_bar( "Rebuilding ($mode)", (int) ( $step['total'] ?? 0 ) );
			}

			$cursor  = $step['cursor'];
			$done   += $step['processed'];
			$failed  = array_merge( $failed, $step['failed'] );

			$progress->tick( $step['processed'] );
		} while ( ! $step['done'] );

		$progress->finish();

		foreach ( $failed as $id ) {
			WP_CLI::warning( "Post $id failed — see wp mavo-search status." );
		}

		WP_CLI::success( sprintf( '%d posts processed, %d failed.%s', $done, count( $failed ), MVS_WP::ready() ? '' : ' The index is not serving searches yet: run --all without filters.' ) );
	}

	/**
	 * Search as the site does.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : What a visitor would type.
	 *
	 * [--lang=<lang>]
	 * : Default: the default language.
	 *
	 * [--limit=<n>]
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--explain]
	 * : Score components of each result.
	 *
	 * [--excerpts]
	 * : Show each result's excerpt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mavo-search search "où dormir à Londres" --explain
	 *     wp mavo-search search "london" --lang=en
	 */
	public function search( $args, $assoc ) {
		$result = mavo_search( (string) $args[0], [
			'lang'     => MVS_Lang::normalize( $assoc['lang'] ?? null ) ?? MVS_Lang::default_language(),
			'per_page' => max( 1, (int) ( $assoc['limit'] ?? 10 ) ),
			'explain'  => true,
			'excerpts' => ! empty( $assoc['excerpts'] ),
		] );

		self::print_parsed( $result['parsed'] );
		WP_CLI::line( sprintf( '%d results, fallback: %s', $result['total'], $result['fallback'] ) );

		$rows = [];
		foreach ( $result['results'] as $i => $hit ) {
			$rows[] = [
				'rank'   => $i + 1,
				'id'     => $hit['post_id'],
				'title'  => html_entity_decode( get_the_title( $hit['post_id'] ), ENT_QUOTES, 'UTF-8' ),
				'score'  => $hit['score'],
				'fields' => implode( ',', $hit['matched_fields'] ),
			];
		}

		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, [ 'rank', 'id', 'title', 'score', 'fields' ] );
		}

		foreach ( $result['results'] as $i => $hit ) {
			if ( ! empty( $assoc['explain'] ) ) {
				self::print_explain( $hit );
			}
			if ( ! empty( $assoc['excerpts'] ) ) {
				WP_CLI::line( sprintf( '  #%d %s', $hit['post_id'], wp_strip_all_tags( $hit['excerpt'] ?? '' ) ) );
			}
		}
	}

	/**
	 * Why one post scores what it does for a query — or why it is not found.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : The query.
	 *
	 * --post=<id>
	 * : The post.
	 *
	 * [--lang=<lang>]
	 * : Default: the post's language.
	 */
	public function explain( $args, $assoc ) {
		$post_id = absint( $assoc['post'] ?? 0 );
		$lang    = MVS_Lang::normalize( $assoc['lang'] ?? null ) ?? MVS_Lang::of_post( $post_id );
		$result  = mavo_search( (string) $args[0], [ 'lang' => $lang, 'per_page' => 100, 'explain' => true, 'excerpts' => false ] );

		self::print_parsed( $result['parsed'] );

		foreach ( $result['results'] as $i => $hit ) {
			if ( $hit['post_id'] === $post_id ) {
				WP_CLI::line( sprintf( 'Rank %d of %d.', $i + 1, $result['total'] ) );
				self::print_explain( $hit );
				return;
			}
		}

		WP_CLI::warning( sprintf( 'Post %d is not among the %d results (%s). Its terms: wp mavo-search terms --post=%d', $post_id, $result['total'], $lang, $post_id ) );
	}

	/**
	 * A post's indexed terms, by field.
	 *
	 * ## OPTIONS
	 *
	 * --post=<id>
	 * : The post.
	 *
	 * [--field=<field>]
	 * : Only terms in this field (title, content, place, hub, alt, concept…).
	 */
	public function terms( $args, $assoc ) {
		global $wpdb;

		$post_id = absint( $assoc['post'] ?? 0 );
		$doc     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MVS_DB::docs() . ' WHERE post_id = %d', $post_id ), ARRAY_A );

		if ( ! $doc ) {
			WP_CLI::error( "Post $post_id is not indexed." );
		}

		WP_CLI::line( sprintf( '%s — %s, %s, boost %s, %s, indexed %s', $doc['title'], $doc['lang'], $doc['post_type'], $doc['boost'], $doc['status'], $doc['indexed_at'] ) );
		WP_CLI::line( 'Signals: ' . $doc['signals'] );

		$fields = MVS_DB::fields();
		$where  = '';

		if ( ! empty( $assoc['field'] ) ) {
			if ( ! in_array( $assoc['field'], $fields, true ) ) {
				WP_CLI::error( 'Unknown field. One of: ' . implode( ', ', $fields ) );
			}
			$where = ' AND ' . $assoc['field'] . ' > 0';
		}

		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT term, ' . implode( ', ', $fields ) . ' FROM ' . MVS_DB::terms() . " WHERE doc_id = %d$where ORDER BY term", (int) $doc['doc_id'] ), ARRAY_A );
		$out  = [];

		foreach ( $rows as $row ) {
			$in = [];
			foreach ( $fields as $field ) {
				if ( (int) $row[ $field ] ) {
					$in[] = $field . ':' . $row[ $field ];
				}
			}
			$out[] = [ 'term' => $row['term'], 'fields' => implode( ' ', $in ) ];
		}

		WP_CLI\Utils\format_items( 'table', $out, [ 'term', 'fields' ] );
	}

	/**
	 * What visitors searched for.
	 *
	 * ## OPTIONS
	 *
	 * [--zero]
	 * : Only searches without results.
	 *
	 * [--low]
	 * : Only searches with 1–3 results.
	 *
	 * [--fallback]
	 * : Only searches answered by partial matches.
	 *
	 * [--days=<n>]
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--lang=<lang>]
	 *
	 * [--limit=<n>]
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * ---
	 */
	public function logs( $args, $assoc ) {
		$which = ! empty( $assoc['zero'] ) ? 'zero' : ( ! empty( $assoc['low'] ) ? 'low' : ( ! empty( $assoc['fallback'] ) ? 'fallback' : 'top' ) );
		$rows  = MVS_Log::report( $which, max( 1, (int) ( $assoc['days'] ?? 30 ) ), MVS_Lang::normalize( $assoc['lang'] ?? null ), max( 1, (int) ( $assoc['limit'] ?? 50 ) ) );

		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, [ 'query', 'lang', 'searches', 'results', 'fallback', 'last' ] );
	}

	/* -------------------------------------------------------------- private */

	private static function print_parsed( array $parsed ): void {
		$groups = [];

		foreach ( $parsed['groups'] as $group ) {
			$variants = [];
			foreach ( $group['variants'] as $term => $v ) {
				$variants[] = $term . ( 'word' === $v['kind'] ? '' : ' (' . $v['kind'] . ')' );
			}
			$groups[] = '[' . implode( ' | ', $variants ) . ( $group['prefix'] ? ' | ' . $group['token'] . '…' : '' ) . ']';
		}

		WP_CLI::line( sprintf( 'Query (%s): %s', $parsed['lang'], implode( ' + ', $groups ) ?: '(nothing searchable)' ) );

		if ( $parsed['concepts'] ) {
			WP_CLI::line( 'Image concepts: ' . implode( ', ', array_keys( $parsed['concepts'] ) ) );
		}
		if ( $parsed['phrases'] ) {
			WP_CLI::line( 'Required phrases: ' . implode( ' / ', $parsed['phrases'] ) );
		}
	}

	private static function print_explain( array $hit ): void {
		WP_CLI::line( sprintf( 'Post %d — score %s', $hit['post_id'], $hit['score'] ) );

		foreach ( $hit['debug'] ?? [] as $part ) {
			WP_CLI::line( sprintf( '  %-36s %-44s %s', $part['what'], $part['detail'], null === $part['points'] ? '' : '+' . $part['points'] ) );
		}
	}
}

<?php
/**
 * Writes documents and their terms; removes them.
 *
 * Indexing one post builds its document (MVS_Document), compares its hash
 * with the stored one and, only when something a search can see changed,
 * replaces its term rows. Unchanged posts cost the build and one UPDATE.
 *
 * A post that no longer belongs (unpublished, trashed, noindex, wrong type)
 * loses its document and terms. A post whose build throws keeps its old terms
 * and is marked failed, which the status report and "Rebuild stale" pick up.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Indexer {

	/**
	 * Bump when what gets indexed, or how, changes: every document becomes stale.
	 *
	 * 2 — documents keep their alt texts and tag names (2026-10-04).
	 */
	const VERSION = '2';

	/** Term rows per INSERT. */
	const INSERT_CHUNK = 250;

	/**
	 * @param int[] $post_ids
	 * @param bool  $force    Rewrite terms even when the hash is unchanged.
	 * @return array<int,string> post_id => indexed | unchanged | removed | failed
	 */
	public static function index( array $post_ids, bool $force = false ): array {
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		$results  = [];

		if ( ! $post_ids ) {
			return $results;
		}

		_prime_post_caches( $post_ids, true, true );
		update_meta_cache( 'post', $post_ids );

		$existing = self::existing( $post_ids );
		$rules    = MVS_Document::rules_version();
		$changed  = false;

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! MVS_Document::eligible( $post ) ) {
				if ( isset( $existing[ $post_id ] ) ) {
					self::purge( $post_id );
					$changed = true;
				}
				$results[ $post_id ] = 'removed';
				continue;
			}

			try {
				$doc  = MVS_Document::build( $post );
				$row  = $existing[ $post_id ] ?? null;
				$same = $row && ! $force && 'indexed' === $row['status'] && $row['source_hash'] === $doc['hash'] && $row['rules_version'] === $rules;

				if ( $same ) {
					self::touch( (int) $row['doc_id'], $doc );
					$results[ $post_id ] = 'unchanged';
					continue;
				}

				$doc_id = self::write( $doc, $row ? (int) $row['doc_id'] : 0, $rules );
				$changed = true;

				$results[ $post_id ] = 'indexed';

				/** Fires after a post's document and terms were written. */
				do_action( 'mavo_search_document_indexed', $post_id, $doc['lang'], $doc_id );
			} catch ( Throwable $e ) {
				self::fail( $post_id, $existing[ $post_id ] ?? null, $e->getMessage() );
				$results[ $post_id ] = 'failed';
			}
		}

		if ( $changed ) {
			MVS_Cache::bump();
		}

		return $results;
	}

	/** Remove a post from the index. */
	public static function purge( int $post_id ): void {
		global $wpdb;

		$doc_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT doc_id FROM ' . MVS_DB::docs() . ' WHERE post_id = %d', $post_id ) );

		if ( ! $doc_id ) {
			return;
		}

		$wpdb->delete( MVS_DB::terms(), [ 'doc_id' => $doc_id ], [ '%d' ] );
		$wpdb->delete( MVS_DB::docs(), [ 'doc_id' => $doc_id ], [ '%d' ] );
		MVS_Cache::bump();
	}

	/* -------------------------------------------------------------- private */

	/** @return array<int,array> post_id => docs row (bookkeeping columns only) */
	private static function existing( array $post_ids ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT doc_id, post_id, source_hash, rules_version, status FROM ' . MVS_DB::docs() . '
			  WHERE post_id IN (' . MVS_DB::in_ints( $post_ids ) . ')',
			ARRAY_A
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['post_id'] ] = $row;
		}

		return $out;
	}

	private static function write( array $doc, int $doc_id, string $rules ): int {
		global $wpdb;

		$data = [
			'post_id'       => $doc['post_id'],
			'lang'          => $doc['lang'],
			'post_type'     => $doc['post_type'],
			'title'         => $doc['title'],
			'title_norm'    => $doc['title_norm'],
			'excerpt'       => $doc['excerpt'],
			'content'       => $doc['content'],
			'alts'          => $doc['alts'],
			'boost'         => $doc['boost'],
			'signals'       => (string) wp_json_encode( $doc['signals'] ),
			'post_date'     => self::date( $doc['post_date'] ),
			'post_modified' => self::date( $doc['post_modified'] ),
			'source_hash'   => $doc['hash'],
			'rules_version' => $rules,
			'status'        => 'indexed',
			'error'         => '',
			'indexed_at'    => MVS_DB::now(),
		];

		if ( $doc_id ) {
			MVS_DB::check( $wpdb->update( MVS_DB::docs(), $data, [ 'doc_id' => $doc_id ] ) );
			MVS_DB::check( $wpdb->delete( MVS_DB::terms(), [ 'doc_id' => $doc_id ], [ '%d' ] ) );
		} else {
			MVS_DB::check( $wpdb->insert( MVS_DB::docs(), $data ) );
			$doc_id = (int) $wpdb->insert_id;
		}

		$fields  = MVS_DB::fields();
		$columns = 'doc_id, term, lang, ' . implode( ', ', $fields );
		$row_fmt = '(%d, %s, %s' . str_repeat( ', %d', count( $fields ) ) . ')';
		$values  = [];

		foreach ( $doc['fields'] as $term => $by_field ) {
			$args = [ $doc_id, (string) $term, $doc['lang'] ];

			foreach ( $fields as $field ) {
				$args[] = (int) ( $by_field[ $field ] ?? 0 );
			}

			$values[] = $wpdb->prepare( $row_fmt, ...$args );

			if ( count( $values ) >= self::INSERT_CHUNK ) {
				MVS_DB::check( $wpdb->query( 'INSERT INTO ' . MVS_DB::terms() . " ($columns) VALUES " . implode( ',', $values ) ) );
				$values = [];
			}
		}

		if ( $values ) {
			MVS_DB::check( $wpdb->query( 'INSERT INTO ' . MVS_DB::terms() . " ($columns) VALUES " . implode( ',', $values ) ) );
		}

		return $doc_id;
	}

	/** Unchanged: only record that it was checked against this version of the post. */
	private static function touch( int $doc_id, array $doc ): void {
		global $wpdb;

		$wpdb->update(
			MVS_DB::docs(),
			[ 'post_modified' => self::date( $doc['post_modified'] ), 'indexed_at' => MVS_DB::now() ],
			[ 'doc_id' => $doc_id ]
		);
	}

	private static function fail( int $post_id, ?array $row, string $message ): void {
		global $wpdb;

		$message = substr( $message, 0, 250 );

		if ( $row ) {
			$wpdb->update( MVS_DB::docs(), [ 'status' => 'failed', 'error' => $message, 'indexed_at' => MVS_DB::now() ], [ 'doc_id' => (int) $row['doc_id'] ] );
			return;
		}

		$wpdb->insert( MVS_DB::docs(), [
			'post_id'    => $post_id,
			'lang'       => MVS_Lang::of_post( $post_id ),
			'post_type'  => (string) get_post_type( $post_id ),
			'title'      => '',
			'title_norm' => '',
			'excerpt'    => '',
			'content'    => '',
			'alts'       => '',
			'signals'    => '{}',
			'status'     => 'failed',
			'error'      => $message,
			'indexed_at' => MVS_DB::now(),
		] );
	}

	private static function date( string $date ): ?string {
		return '' === $date || '0000-00-00 00:00:00' === $date ? null : $date;
	}
}

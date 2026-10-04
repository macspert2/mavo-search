<?php
/**
 * Resumable batch rebuilds, shared by the admin screen (one AJAX request per
 * step), WP-CLI (a loop of steps) and WP-Cron (the build after activation).
 * A step takes a cursor — the last post ID it handled — and returns the next,
 * so an interrupted rebuild resumes by starting again.
 *
 *   all     every published post of a searched type, forced
 *   stale   missing, stale, failed and orphaned ones only (MVS_Status)
 *
 * The first complete "all" makes the index ready, and only then does it
 * answer the site's searches (MVS_WP). Activation starts one in the
 * background, a batch per WP-Cron run, so deploying the plugin is enough:
 * Relevanssi keeps serving until the index is complete, then Mavo Search
 * takes over. Running it from Tools → Mavo Search is just faster.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Rebuild {

	const LAST_OPTION       = 'mavo_search_last_rebuild';
	const BACKGROUND_OPTION = 'mavo_search_background';
	const BACKGROUND_HOOK   = 'mavo_search_background_rebuild';
	const DEFAULT_BATCH     = 100;
	const BACKGROUND_BATCH  = 50;
	const MODES             = [ 'all', 'stale' ];

	public static function init(): void {
		add_action( self::BACKGROUND_HOOK, [ __CLASS__, 'background_step' ] );
	}

	/**
	 * @param array $filters post_type (string), lang (string) — CLI only. A
	 *                       filtered rebuild never marks the index ready nor
	 *                       removes other posts' documents.
	 * @return array{done:bool,cursor:int,processed:int,failed:int[],total:?int}
	 */
	public static function step( string $mode, int $cursor = 0, int $batch = self::DEFAULT_BATCH, array $filters = [] ): array {
		global $wpdb;

		$batch  = max( 1, min( 1000, $batch ) );
		$total  = null;
		$types  = MVS_Document::post_types();

		if ( ! empty( $filters['post_type'] ) ) {
			$types = array_values( array_intersect( $types, [ sanitize_key( (string) $filters['post_type'] ) ] ) );
		}

		switch ( $mode ) {
			case 'all':
				$in = MVS_DB::in_strings( $types );

				if ( 0 === $cursor ) {
					$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in)" );
				}

				$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) AND ID > %d ORDER BY ID LIMIT %d",
					$cursor,
					$batch
				) ) );
				$force = true;
				break;

			case 'stale':
				if ( 0 === $cursor ) {
					$summary = MVS_Status::summary();
					$total   = $summary['stale'] + $summary['missing'] + $summary['failed'] + $summary['orphans'];
				}

				$ids   = MVS_Status::stale_ids( $cursor, $batch );
				$force = false;
				break;

			default:
				return [ 'done' => true, 'cursor' => $cursor, 'processed' => 0, 'failed' => [], 'total' => 0 ];
		}

		$todo = $ids;

		if ( ! empty( $filters['lang'] ) ) {
			$todo = array_values( array_filter( $ids, static fn( $id ) => MVS_Lang::of_post( $id ) === $filters['lang'] ) );
		}

		$failed = [];
		foreach ( MVS_Indexer::index( $todo, $force ) as $id => $result ) {
			if ( 'failed' === $result ) {
				$failed[] = $id;
			}
		}

		$done = count( $ids ) < $batch;

		if ( $done ) {
			self::finish( $mode, ! empty( $filters['post_type'] ) || ! empty( $filters['lang'] ) );
		}

		return [
			'done'      => $done,
			'cursor'    => $ids ? max( $ids ) : $cursor,
			'processed' => count( $todo ),
			'failed'    => $failed,
			'total'     => $total,
		];
	}

	/* ---------------------------------------------------------- background */

	/** Start (or restart) a full rebuild run by WP-Cron. */
	public static function start_background(): void {
		update_option( self::BACKGROUND_OPTION, [ 'cursor' => 0, 'done' => 0, 'started' => time() ], false );

		if ( ! wp_next_scheduled( self::BACKGROUND_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::BACKGROUND_HOOK );
		}
	}

	/** One cron run: batches until about 20 seconds have passed. */
	public static function background_step(): void {
		$state = get_option( self::BACKGROUND_OPTION );

		if ( ! is_array( $state ) ) {
			return;
		}

		$until = microtime( true ) + 20;

		do {
			$step             = self::step( 'all', (int) $state['cursor'], self::BACKGROUND_BATCH );
			$state['cursor']  = $step['cursor'];
			$state['done']   += $step['processed'];
			$state['total'] ??= $step['total'];
		} while ( ! $step['done'] && microtime( true ) < $until );

		if ( $step['done'] ) {
			delete_option( self::BACKGROUND_OPTION );
			return;
		}

		update_option( self::BACKGROUND_OPTION, $state, false );
		wp_schedule_single_event( time() + 5, self::BACKGROUND_HOOK );
	}

	/** @return array{cursor:int,done:int,total:?int,started:int}|null */
	public static function background_state(): ?array {
		$state = get_option( self::BACKGROUND_OPTION );

		return is_array( $state ) ? $state : null;
	}

	/* ------------------------------------------------------------- private */

	/**
	 * Remove documents nothing should point at any more — posts unpublished,
	 * deleted, noindexed — which an ID-cursor pass never visits, and mark the
	 * index ready after a complete full rebuild.
	 */
	private static function finish( string $mode, bool $filtered ): void {
		global $wpdb;

		if ( ! $filtered ) {
			$docs = MVS_DB::docs();

			$wpdb->query( "DELETE FROM $docs WHERE NOT EXISTS ( SELECT 1 " . MVS_Status::eligible_sql() . " AND p.ID = $docs.post_id )" );
			$wpdb->query( 'DELETE FROM ' . MVS_DB::terms() . " WHERE NOT EXISTS ( SELECT 1 FROM $docs WHERE $docs.doc_id = " . MVS_DB::terms() . '.doc_id )' );

			if ( 'all' === $mode ) {
				update_option( MVS_WP::READY_OPTION, time(), true );
				delete_option( self::BACKGROUND_OPTION );
			}
		}

		$last          = (array) get_option( self::LAST_OPTION, [] );
		$last[ $mode ] = time();

		update_option( self::LAST_OPTION, $last, false );
		MVS_Cache::bump();

		/** Fires when a full or stale rebuild has gone through every post. */
		do_action( 'mavo_search_index_rebuilt', $mode );
	}
}

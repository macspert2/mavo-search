<?php
/**
 * Best bets: posts an editor pins to the top of a query's results.
 *
 * Edited in Tools → Mavo Search as lines "query = post ID, post ID", the way
 * mavo-image-index edits its link targets. A pin matches a search when the
 * two are the same once normalized — case, accents, punctuation and extra
 * spaces do not count, words do: "Londres" and "londres !" match a pin for
 * "londres", "londres famille" does not.
 *
 * A pinned post counts only in its own language, so one line can serve
 * every language ("londres = 12, 345" with 345 the English page), and it is
 * shown only while it is in the index (published, not noindexed). Pinned
 * posts come first, in the order written, ahead of everything ranked —
 * matched or not — and are marked 'pinned' in the results.
 *
 * The zero-result and partial-match reports are the list to work from.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Best_Bets {

	const OPTION = 'mavo_search_best_bets';

	/** @return array<int,array{query:string,norm:string,posts:int[]}> */
	public static function all(): array {
		$bets = (array) get_option( self::OPTION, [] );

		/** Best bets: [ query, norm, posts ] each. */
		return array_values( array_filter( (array) apply_filters( 'mavo_search_best_bets', $bets ), static fn( $b ) => is_array( $b ) && ! empty( $b['norm'] ) && ! empty( $b['posts'] ) ) );
	}

	/** @return int[] Pinned post IDs for a normalized query, any language. */
	public static function for_query( string $normalized ): array {
		$out = [];

		foreach ( self::all() as $bet ) {
			if ( $bet['norm'] === $normalized ) {
				$out = array_merge( $out, array_map( 'intval', (array) $bet['posts'] ) );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * The textarea's lines → stored bets. Lines that do not parse are dropped.
	 *
	 * @return array<int,array{query:string,norm:string,posts:int[]}>
	 */
	public static function parse( string $text ): array {
		$out = [];

		foreach ( preg_split( '/\R/u', $text ) ?: [] as $line ) {
			if ( ! str_contains( $line, '=' ) ) {
				continue;
			}

			[ $query, $ids ] = array_map( 'trim', explode( '=', $line, 2 ) );
			$query = MVS_Query::clean( $query );
			$norm  = MVS_Text::normalize( $query );
			$posts = array_values( array_unique( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', $ids ) ?: [] ) ) ) );

			if ( '' !== $norm && $posts ) {
				$out[] = [ 'query' => $query, 'norm' => $norm, 'posts' => $posts ];
			}
		}

		return $out;
	}

	/** Stored bets → the textarea's lines. */
	public static function to_text( array $bets ): string {
		return implode( "\n", array_map( static fn( $b ) => $b['query'] . ' = ' . implode( ', ', $b['posts'] ), $bets ) );
	}

	public static function save( array $bets ): void {
		update_option( self::OPTION, array_values( $bets ), false );
		MVS_Cache::bump();
	}

	/**
	 * Put a ranking's pinned posts first. Posts the ranking did not find are
	 * added, if they are indexed in the search's language and types.
	 *
	 * @param array    $ranking MVS_Engine::ranking() shape
	 * @param string[] $types
	 */
	public static function apply( array $ranking, array $parsed, array $types, bool $explain ): array {
		$pins = '' === $parsed['normalized'] ? [] : self::for_query( $parsed['normalized'] );

		if ( ! $pins || ! $types ) {
			return $ranking;
		}

		global $wpdb;

		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			'SELECT doc_id, post_id, signals, post_date FROM ' . MVS_DB::docs() . '
			  WHERE post_id IN (' . MVS_DB::in_ints( $pins ) . ") AND lang = %s AND status = 'indexed'
			    AND post_type IN (" . MVS_DB::in_strings( $types ) . ')',
			$parsed['lang']
		), ARRAY_A );

		$docs = array_column( $rows, null, 'post_id' );
		$hits = array_column( $ranking['ranked'], null, 'post_id' );
		$top  = [];

		foreach ( $pins as $post_id ) {
			if ( ! isset( $docs[ $post_id ] ) ) {
				continue;
			}

			$doc   = $docs[ $post_id ];
			$top[] = [ 'pinned' => true ] + ( $hits[ $post_id ] ?? [
				'post_id'  => (int) $post_id,
				'doc_id'   => (int) $doc['doc_id'],
				'score'    => 0.0,
				'date'     => (string) $doc['post_date'],
				'terms'    => [],
				'fields'   => [],
				'concepts' => [],
				'signals'  => (array) json_decode( (string) $doc['signals'], true ),
			] );

			if ( $explain ) {
				$ranking['explain'][ (int) $doc['doc_id'] ][] = [ 'what' => 'best bet', 'detail' => 'pinned first in Tools → Mavo Search', 'points' => null ];
			}
		}

		if ( ! $top ) {
			return $ranking;
		}

		$pinned = array_column( $top, 'post_id' );
		$rest   = array_values( array_filter( $ranking['ranked'], static fn( $hit ) => ! in_array( $hit['post_id'], $pinned, true ) ) );

		$ranking['ranked'] = array_merge( $top, $rest );

		// A pin answers the query: whatever else was found is no longer "instead".
		if ( ! $rest ) {
			$ranking['fallback'] = 'none';
		}

		return $ranking;
	}
}

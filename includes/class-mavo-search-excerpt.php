<?php
/**
 * The piece of an article that shows why it was found.
 *
 * Over the stored plain text, every window of the excerpt's length is scored
 * — ten points per different query word in it, one per occurrence — and the
 * best wins. It then starts at the beginning of its sentence when that is
 * close, else at a word, so the matches sit early rather than at the edge;
 * it ends at a word, and "…" marks a cut on either side.
 *
 * When the text never names the query — the post was found through its place,
 * its hub, a photo — a contextual fragment would be arbitrary words. The
 * hand-written excerpt is used if it matches, else as a plain summary, else
 * the opening of the text ('source' says which).
 *
 * Length: 450 characters, as Relevanssi was set (mavo_search_excerpt_length).
 * Output is escaped HTML; the only markup is the highlight.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Excerpt {

	const LENGTH   = 450;
	const ELLIPSIS = '…';

	/**
	 * @return array{html:string,source:string,hits:int} source: content | excerpt | summary
	 */
	public static function build( string $content, string $manual, array $hl, string $lang, ?int $length = null ): array {
		/** Excerpt length in characters. */
		$length = max( 60, (int) ( $length ?? apply_filters( 'mavo_search_excerpt_length', self::LENGTH ) ) );

		$window = self::window( $content, $hl, $lang, $length );

		if ( $window ) {
			return [ 'html' => self::render( $content, $window[0], $window[1], $hl, $lang ), 'source' => 'content', 'hits' => $window[2] ];
		}

		if ( '' !== $manual ) {
			$hits = self::hits( MVS_Text::words( $manual ), $hl, $lang );
			$end  = self::cut( $manual, $length );

			return [ 'html' => self::render( $manual, 0, $end, $hl, $lang ), 'source' => $hits ? 'excerpt' : 'summary', 'hits' => count( $hits ) ];
		}

		return [ 'html' => self::render( $content, 0, self::cut( $content, $length ), $hl, $lang ), 'source' => 'summary', 'hits' => 0 ];
	}

	/**
	 * Best [ start, end, hits ] in bytes, or null when nothing matches.
	 */
	private static function window( string $text, array $hl, string $lang, int $length ): ?array {
		$words = MVS_Text::words( $text );
		$hits  = self::hits( $words, $hl, $lang );

		if ( ! $hits ) {
			return null;
		}

		// Bytes per character of this text, so the window is measured in characters.
		$ratio  = strlen( $text ) / max( 1, mb_strlen( $text, 'UTF-8' ) );
		$budget = (int) ( $length * $ratio );
		$best   = null;
		$count  = count( $hits );

		for ( $i = 0; $i < $count; $i++ ) {
			$first    = $words[ $hits[ $i ] ];
			$distinct = [];
			$n        = 0;
			$last     = $first;

			for ( $j = $i; $j < $count; $j++ ) {
				$word = $words[ $hits[ $j ] ];

				if ( $word['offset'] + $word['length'] - $first['offset'] > $budget ) {
					break;
				}

				$distinct[ $word['norm'] ] = true;
				$n++;
				$last = $word;
			}

			$score = 10 * count( $distinct ) + $n;

			if ( ! $best || $score > $best[0] ) {
				$best = [ $score, $first, $last, $n ];
			}
		}

		[ , $first, $last, $n ] = $best;

		$span  = $last['offset'] + $last['length'] - $first['offset'];
		$slack = max( 0, $budget - $span );
		$start = max( 0, $first['offset'] - intdiv( $slack, 3 ) );

		// The start of the sentence, when it is within reach.
		$before   = substr( $text, 0, $first['offset'] );
		$boundary = max( (int) strrpos( $before, "\n" ), (int) strrpos( $before, '. ' ), (int) strrpos( $before, '! ' ), (int) strrpos( $before, '? ' ) );

		if ( $boundary && $first['offset'] - $boundary <= $slack ) {
			$start = $boundary + ( "\n" === $text[ $boundary ] ? 1 : 2 );
		} elseif ( $start > 0 ) {
			foreach ( $words as $word ) {
				if ( $word['offset'] >= $start ) {
					$start = $word['offset'];
					break;
				}
			}
		}

		$end = $start;

		foreach ( $words as $word ) {
			if ( $word['offset'] < $start ) {
				continue;
			}
			if ( $word['offset'] + $word['length'] - $start > $budget ) {
				break;
			}
			$end = $word['offset'] + $word['length'];
		}

		// Keep a closing full stop or the like.
		if ( isset( $text[ $end ] ) && preg_match( '/^[.!?…)»"]/u', substr( $text, $end, 3 ) ) ) {
			$end++;
		}

		return [ $start, max( $end, $last['offset'] + $last['length'] ), $n ];
	}

	/** @return int[] Indexes into $words of the words that match. */
	private static function hits( array $words, array $hl, string $lang ): array {
		$out = [];

		foreach ( $words as $i => $word ) {
			if ( MVS_Highlight::matches( $word, $hl, $lang ) ) {
				$out[] = $i;
			}
		}

		return $out;
	}

	/** The end of the last whole word within $length characters, in bytes. */
	private static function cut( string $text, int $length ): int {
		if ( mb_strlen( $text, 'UTF-8' ) <= $length ) {
			return strlen( $text );
		}

		$cut  = strlen( mb_substr( $text, 0, $length, 'UTF-8' ) );
		$end  = 0;

		foreach ( MVS_Text::words( substr( $text, 0, $cut ) ) as $word ) {
			// The last word may itself have been cut in half.
			if ( $word['offset'] + $word['length'] < $cut ) {
				$end = $word['offset'] + $word['length'];
			}
		}

		return $end ?: $cut;
	}

	private static function render( string $text, int $start, int $end, array $hl, string $lang ): string {
		$piece = trim( (string) preg_replace( '/\s*\n\s*/u', ' ', substr( $text, $start, $end - $start ) ) );
		$html  = MVS_Highlight::text( $piece, $hl, $lang );

		if ( $start > 0 ) {
			$html = self::ELLIPSIS . ' ' . $html;
		}

		if ( $end < strlen( rtrim( $text ) ) ) {
			$html .= ' ' . self::ELLIPSIS;
		}

		return $html;
	}
}

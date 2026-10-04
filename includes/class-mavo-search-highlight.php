<?php
/**
 * Marks the query's words in text or HTML.
 *
 * Whole words only, compared folded, so "edimbourg" marks "Édimbourg", and a
 * German "zuerich" marks "Zürich". A word longer than a query word of four
 * letters or more that starts with it is marked whole — "plage" marks
 * "plages" — because that is how it was found. Single letters never.
 *
 * In HTML only text is touched: never a tag, an attribute (so never a URL), a
 * comment, or the inside of <script>, <style>, <textarea> or an existing
 * <mark>. Text is decoded and re-escaped, so entities cannot be split.
 *
 * Markup: <mark class="mavo-search-highlight">…</mark>, filter
 * mavo_search_highlight_html (a sprintf template with one %s).
 */

defined( 'ABSPATH' ) || exit;

class MVS_Highlight {

	const DEFAULT_HTML = '<mark class="mavo-search-highlight">%s</mark>';
	const SKIP         = [ 'script', 'style', 'textarea', 'mark', 'title', 'noscript' ];

	/**
	 * Plain text in, escaped HTML out.
	 *
	 * @param array{terms:array<string,true>,prefixes:string[]} $hl From MVS_Query::highlight_terms().
	 */
	public static function text( string $text, array $hl, string $lang = '' ): string {
		$words = $hl['terms'] || $hl['prefixes'] ? MVS_Text::words( $text ) : [];
		$tpl   = self::template();
		$out   = '';
		$at    = 0;

		foreach ( $words as $word ) {
			if ( ! self::matches( $word, $hl, $lang ) ) {
				continue;
			}

			$out .= self::esc( substr( $text, $at, $word['offset'] - $at ) );
			$out .= sprintf( $tpl, self::esc( $word['raw'] ) );
			$at   = $word['offset'] + $word['length'];
		}

		return $out . self::esc( substr( $text, $at ) );
	}

	/** HTML in, HTML out, only text nodes touched. */
	public static function html( string $html, array $hl, string $lang = '' ): string {
		if ( ! $hl['terms'] && ! $hl['prefixes'] ) {
			return $html;
		}

		$parts = preg_split( '/(<!--.*?-->|<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$skip  = '';
		$out   = '';

		foreach ( (array) $parts as $part ) {
			if ( '<' === $part[0] && preg_match( '#^<(/?)([a-zA-Z][a-zA-Z0-9-]*)|^<!--#', $part, $m ) ) {
				$name = strtolower( $m[2] ?? '' );

				if ( '' === $skip && '' === ( $m[1] ?? '' ) && in_array( $name, self::SKIP, true ) && ! str_ends_with( $part, '/>' ) ) {
					$skip = $name;
				} elseif ( '/' === ( $m[1] ?? '' ) && $name === $skip ) {
					$skip = '';
				}

				$out .= $part;
				continue;
			}

			$out .= '' === $skip ? self::text( html_entity_decode( $part, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $hl, $lang ) : $part;
		}

		return $out;
	}

	/** @param array{raw:string,norm:string} $word */
	public static function matches( array $word, array $hl, string $lang = '' ): bool {
		$norm = $word['norm'];

		if ( mb_strlen( $norm, 'UTF-8' ) < MVS_Text::MIN_LENGTH ) {
			return false;
		}

		if ( isset( $hl['terms'][ $norm ] ) ) {
			return true;
		}

		if ( 'de' === $lang ) {
			foreach ( MVS_Text::forms( $word['raw'], $norm, $lang ) as $form ) {
				if ( isset( $hl['terms'][ $form ] ) ) {
					return true;
				}
			}
		}

		foreach ( $hl['prefixes'] as $prefix ) {
			if ( str_starts_with( $norm, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	private static function template(): string {
		/** The highlight markup: a sprintf template with one %s, the escaped word. */
		$tpl = (string) apply_filters( 'mavo_search_highlight_html', self::DEFAULT_HTML );

		return 1 === substr_count( $tpl, '%s' ) && ! preg_match( '/%(?!s)/', $tpl ) ? $tpl : self::DEFAULT_HTML;
	}

	private static function esc( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

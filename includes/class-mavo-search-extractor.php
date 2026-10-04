<?php
/**
 * Stored post_content → the text a reader sees, without running it.
 *
 * the_content is never applied: on this site it would run shortcodes that
 * query, render maps and sliders, and add For You blocks — slow, full of side
 * effects, and full of text that is not the article. Instead:
 *
 *   comments, <script> <style> <noscript> <iframe> <svg> <template>   removed
 *   <!-- mavo-search:skip --> … <!-- /mavo-search:skip -->            removed
 *   shortcode tags                       removed; enclosed text kept, so a
 *                                        [caption]…photo of Lisbon[/caption]
 *                                        still says Lisbon
 *   headings                             kept in the text, and also returned
 *                                        on their own (they weigh more)
 *   block elements, <br>                 line breaks, so words of adjacent
 *                                        paragraphs never run together
 *   every other tag, attribute and URL   removed
 *   entities                             decoded
 *
 * Image alt text is not read from the markup: mavo-image-index knows every
 * image's alt in every language (see MVS_Images).
 */

defined( 'ABSPATH' ) || exit;

class MVS_Extractor {

	const BLOCKS = 'p|div|section|article|aside|header|footer|blockquote|figure|figcaption|li|ul|ol|dl|dt|dd|table|thead|tbody|tr|td|th|h[1-6]|pre|hr|details|summary';

	/**
	 * @return array{text:string,headings:string[]}
	 */
	public static function extract( string $html ): array {
		$html = (string) preg_replace( '/<!--\s*mavo-search:skip\s*-->.*?(?:<!--\s*\/mavo-search:skip\s*-->|$)/is', ' ', $html );
		$html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
		$html = (string) preg_replace( '#<(script|style|noscript|iframe|svg|template|object)\b[^>]*>.*?</\1\s*>#is', ' ', $html );
		$html = self::strip_shortcodes( $html );

		$headings = [];
		if ( preg_match_all( '#<h[1-6]\b[^>]*>(.*?)</h[1-6]\s*>#is', $html, $m ) ) {
			foreach ( $m[1] as $inner ) {
				$heading = self::clean_line( wp_strip_all_tags( $inner ) );

				if ( '' !== $heading ) {
					$headings[] = $heading;
				}
			}
		}

		$html = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = (string) preg_replace( '#</?(?:' . self::BLOCKS . ')\b[^>]*>#i', "\n", $html );
		$text = wp_strip_all_tags( $html );

		$lines = [];
		foreach ( preg_split( '/\R/u', $text ) ?: [] as $line ) {
			$line = self::clean_line( $line );

			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return [ 'text' => implode( "\n", $lines ), 'headings' => $headings ];
	}

	/** One line of plain text: entities decoded, whitespace collapsed. */
	public static function clean_line( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[\s\x{00A0}\x{200B}]+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Removes [tag attr="…"] and [/tag], leaving what an enclosing shortcode
	 * wraps. A registered name always goes; so does anything shaped like a
	 * shortcode — an attribute, a closing tag, an underscore in the name — since
	 * old posts still carry shortcodes of plugins long removed. "[sic]" in
	 * prose survives.
	 */
	private static function strip_shortcodes( string $html ): string {
		global $shortcode_tags;

		return (string) preg_replace_callback(
			'/\[\/?([a-zA-Z][\w-]*)(?:\s[^\[\]]*)?\/?\]/',
			static function ( $m ) use ( $shortcode_tags ) {
				$registered = is_array( $shortcode_tags ) && isset( $shortcode_tags[ $m[1] ] );
				$looks_like = str_contains( $m[0], '=' ) || str_starts_with( $m[0], '[/' ) || str_contains( $m[1], '_' );

				return $registered || $looks_like ? ' ' : $m[0];
			},
			$html
		);
	}
}

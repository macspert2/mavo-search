<?php
/**
 * Words in, index terms out — the one place that decides what a "word" is,
 * shared by the indexer, the query parser, excerpts and highlighting so the
 * four can never disagree.
 *
 * A word is a run of letters and digits. Everything else separates: spaces,
 * punctuation, apostrophes (so "l'eau" holds "eau") and hyphens (so
 * "Royaume-Uni" holds "royaume" and "uni"), as Relevanssi was configured to.
 *
 * Terms are FOLDED: lowercase, accents removed, ß → ss, œ → oe. A visitor
 * typing "edimbourg" or "ile" must find Édimbourg and île, and Relevanssi did
 * find them, through MySQL's accent-insensitive collation. mavo-image-index
 * deliberately does not fold (côte / côté are different words in an alt text
 * dictionary); a search box is a different trade — the visitor's spelling is
 * the noisy side, and ranking separates côte from côté well enough.
 *
 * German umlauts have a second spelling, ae / oe / ue. German documents index
 * both "zurich" and "zuerich" for Zürich, so either typed form finds it, while
 * French and English documents keep only the plain fold — a French "Zürich"
 * should not also be "zuerich".
 */

defined( 'ABSPATH' ) || exit;

class MVS_Text {

	const MIN_LENGTH = 2;
	const MAX_LENGTH = 64;

	/** Letters that do not decompose into a base letter and an accent. */
	const SPECIAL = [
		'ß' => 'ss', 'ẞ' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o', 'đ' => 'd',
		'ð' => 'd', 'ł' => 'l', 'þ' => 'th', 'ı' => 'i', 'ŀ' => 'l',
	];

	const UMLAUTS = [ 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue' ];

	private static array $stopwords = [];

	/** Lowercase, accents removed. Keeps everything else as it is. */
	public static function fold( string $text ): string {
		if ( class_exists( 'Normalizer' ) ) {
			$text = (string) Normalizer::normalize( $text, Normalizer::FORM_C );
		}

		$text = strtr( mb_strtolower( $text, 'UTF-8' ), self::SPECIAL );

		if ( class_exists( 'Normalizer' ) ) {
			$text = (string) preg_replace( '/\p{Mn}+/u', '', (string) Normalizer::normalize( $text, Normalizer::FORM_D ) );
		} elseif ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}

		return $text;
	}

	/**
	 * Every word of a text, with where it is.
	 *
	 * @return array<int,array{raw:string,norm:string,offset:int,length:int}> Byte offsets.
	 */
	public static function words( string $text ): array {
		if ( ! preg_match_all( '/[\p{L}\p{M}\p{N}]+/u', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return [];
		}

		$out = [];

		foreach ( $m[0] as [ $raw, $offset ] ) {
			$out[] = [
				'raw'    => $raw,
				'norm'   => self::fold( $raw ),
				'offset' => $offset,
				'length' => strlen( $raw ),
			];
		}

		return $out;
	}

	/** @return string[] Every folded word, stopwords and single letters included. */
	public static function tokens( string $text ): array {
		$norm = self::normalize( $text );

		return '' === $norm ? [] : explode( ' ', $norm );
	}

	/**
	 * Folded words joined by one space: what phrase and title checks compare.
	 * The same words as words() gives, folded in one pass rather than one call
	 * per word — this runs over whole articles at query time.
	 */
	public static function normalize( string $text ): string {
		return trim( (string) preg_replace( '/[^\p{L}\p{M}\p{N}]+/u', ' ', self::fold( $text ) ) );
	}

	/**
	 * The index terms of a text and how often each occurs.
	 *
	 * @return array<string,int>
	 */
	public static function terms( string $text, string $lang ): array {
		$out = [];

		foreach ( self::words( $text ) as $word ) {
			foreach ( self::forms( $word['raw'], $word['norm'], $lang ) as $term ) {
				$out[ $term ] = ( $out[ $term ] ?? 0 ) + 1;
			}
		}

		return $out;
	}

	/**
	 * The terms one word is indexed under: its fold, plus the ae/oe/ue
	 * spelling in German. None when it is too short, too long or a stopword.
	 *
	 * @return string[]
	 */
	public static function forms( string $raw, string $norm, string $lang ): array {
		if ( ! self::indexable( $norm, $lang ) ) {
			return [];
		}

		$forms = [ $norm ];

		if ( 'de' === $lang ) {
			$umlaut = self::fold( strtr( mb_strtolower( $raw, 'UTF-8' ), self::UMLAUTS ) );

			if ( $umlaut !== $norm && strlen( $umlaut ) <= self::MAX_LENGTH ) {
				$forms[] = $umlaut;
			}
		}

		return $forms;
	}

	public static function indexable( string $term, string $lang ): bool {
		$length = mb_strlen( $term, 'UTF-8' );

		return $length >= self::MIN_LENGTH && strlen( $term ) <= self::MAX_LENGTH && ! self::is_stopword( $term, $lang );
	}

	public static function is_stopword( string $term, string $lang ): bool {
		return isset( self::stopwords( $lang )[ $term ] );
	}

	/** @return array<string,true> Folded. */
	public static function stopwords( string $lang ): array {
		if ( isset( self::$stopwords[ $lang ] ) ) {
			return self::$stopwords[ $lang ];
		}

		$all  = (array) include MVS_PLUGIN_DIR . 'data/stopwords.php';
		$list = (array) ( $all[ $lang ] ?? [] );

		/** Words never indexed nor required in a query, per language. Kept short on purpose. */
		$list = (array) apply_filters( 'mavo_search_stopwords', $list, $lang );

		$out = [];
		foreach ( $list as $word ) {
			$out[ self::fold( (string) $word ) ] = true;
		}

		return self::$stopwords[ $lang ] = $out;
	}

	/** Part of the index rules version: changing it makes every document stale. */
	public static function version(): string {
		$lists = [];

		foreach ( MVS_Lang::languages() as $lang ) {
			$lists[ $lang ] = array_keys( self::stopwords( $lang ) );
		}

		return md5( serialize( $lists ) );
	}

	/** For tests. */
	public static function reset(): void {
		self::$stopwords = [];
	}
}

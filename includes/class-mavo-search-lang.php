<?php
/**
 * Which languages exist, which one a search is in, and which one a post is in.
 *
 * Asked of Polylang when it is there. Without it the site's three languages
 * are still assumed and every post is French: a rebuild run while Polylang is
 * briefly off then files everything under fr rather than losing it, and the
 * next save puts each post back.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Lang {

	const FALLBACK  = 'fr';
	const SUPPORTED = [ 'fr', 'en', 'de' ];

	private static ?array $languages = null;

	/** @return string[] Default language first. */
	public static function languages(): array {
		if ( null !== self::$languages ) {
			return self::$languages;
		}

		$list = self::SUPPORTED;

		if ( function_exists( 'pll_languages_list' ) ) {
			$pll = array_values( array_filter( array_map( 'strval', (array) pll_languages_list( [ 'fields' => 'slug' ] ) ) ) );

			if ( $pll ) {
				$list = $pll;
			}
		}

		$default = self::default_language();
		$list    = array_merge( [ $default ], array_values( array_diff( $list, [ $default ] ) ) );

		return self::$languages = array_values( array_unique( array_filter( array_map( 'sanitize_key', $list ) ) ) );
	}

	public static function default_language(): string {
		if ( function_exists( 'pll_default_language' ) ) {
			$lang = (string) pll_default_language( 'slug' );

			if ( '' !== $lang ) {
				return $lang;
			}
		}

		return self::FALLBACK;
	}

	/** Polylang's current language, else the default. Never empty. */
	public static function current(): string {
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = self::normalize( (string) pll_current_language( 'slug' ) );

			if ( null !== $lang ) {
				return $lang;
			}
		}

		return self::default_language();
	}

	/** A known language slug, or null. */
	public static function normalize( ?string $lang ): ?string {
		if ( null === $lang ) {
			return null;
		}

		$lang = strtolower( trim( $lang ) );

		return in_array( $lang, self::languages(), true ) ? $lang : null;
	}

	/** $lang if known, else the current language. */
	public static function resolve( ?string $lang ): string {
		return self::normalize( $lang ) ?? self::current();
	}

	/** One post's language; the default when it has none. */
	public static function of_post( int $post_id ): string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$lang = self::normalize( (string) pll_get_post_language( $post_id, 'slug' ) );

			if ( null !== $lang ) {
				return $lang;
			}
		}

		return self::default_language();
	}

	/** For tests. */
	public static function reset(): void {
		self::$languages = null;
	}
}

<?php
/**
 * The images of a post, through mavo-image-index's procedural API: their alt
 * text in the post's language, and their concepts.
 *
 * Alt text is per language and never falls back: on this site the English and
 * German alt texts live in _mavo_alt_{lang}, not in _wp_attachment_image_alt,
 * and a German search must not match a French caption. mavo-image-index knows
 * where each one is; this class never names a meta key while it is active.
 *
 * Concepts are facts about the picture (an image is a beach whichever
 * language's alt said so), indexed twice: as "#beach", which a query matches
 * when mavo-image-index recognises the concept in it, and as the concept's
 * label in the post's language ("Plage"), which a plain word matches when it
 * does not. Both at the concept's confidence.
 *
 * Without mavo-image-index the default language still gets alt text, read the
 * WordPress way from the images in the markup and the featured image; the
 * other languages and concepts are omitted.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Images {

	const MAX_IMAGES = 100;

	public static function available(): bool {
		return function_exists( 'mavo_image_search' );
	}

	/**
	 * @return array{alts:string[],concepts:array<string,array{label:string,confidence:float}>}
	 */
	public static function for_post( WP_Post $post, string $lang ): array {
		if ( ! self::available() ) {
			return [ 'alts' => self::fallback_alts( $post, $lang ), 'concepts' => [] ];
		}

		$alts     = [];
		$concepts = [];

		$images = (array) mavo_image_search( [
			'post_ids'      => [ $post->ID ],
			'lang'          => $lang,
			'same_language' => false,
			'limit'         => self::MAX_IMAGES,
			'orderby'       => 'id',
		] );

		foreach ( $images as $image ) {
			$alt = trim( (string) ( $image['alt'][ $lang ] ?? '' ) );

			if ( '' !== $alt ) {
				$alts[] = $alt;
			}

			foreach ( (array) ( $image['concepts'] ?? [] ) as $concept ) {
				$slug       = (string) ( $concept['slug'] ?? '' );
				$confidence = (float) ( $concept['confidence'] ?? 1 );

				if ( '' !== $slug && $confidence > ( $concepts[ $slug ]['confidence'] ?? 0 ) ) {
					$concepts[ $slug ] = [ 'label' => (string) ( $concept['label'] ?? '' ), 'confidence' => $confidence ];
				}
			}
		}

		ksort( $concepts );

		return [ 'alts' => array_values( array_unique( $alts ) ), 'concepts' => $concepts ];
	}

	/**
	 * Posts using an image, to reindex when its alt text or concepts change.
	 *
	 * @return int[]
	 */
	public static function posts_using( int $attachment_id ): array {
		if ( ! function_exists( 'mavo_image_get_usages' ) ) {
			return [];
		}

		return array_values( array_unique( array_map( 'intval', array_column( (array) mavo_image_get_usages( $attachment_id ), 'post_id' ) ) ) );
	}

	/**
	 * The concepts a query names, as mavo-image-index reads them.
	 *
	 * @return array<int,array{concept:string,confidence:float,matched:string}>
	 */
	public static function match_concepts( string $text, string $lang ): array {
		return function_exists( 'mavo_image_match_concepts' ) ? (array) mavo_image_match_concepts( $text, $lang ) : [];
	}

	/** @return array<string,string> slug => label in $lang */
	public static function concept_labels( string $lang ): array {
		if ( ! function_exists( 'mavo_image_concepts' ) ) {
			return [];
		}

		return array_map( static fn( $c ) => (string) ( $c['label'] ?? '' ), (array) mavo_image_concepts( $lang ) );
	}

	/**
	 * WordPress's own alt text, default language only — it is the only one
	 * _wp_attachment_image_alt holds.
	 *
	 * @return string[]
	 */
	private static function fallback_alts( WP_Post $post, string $lang ): array {
		if ( MVS_Lang::default_language() !== $lang ) {
			return [];
		}

		$ids = [ (int) get_post_meta( $post->ID, '_thumbnail_id', true ) ];

		if ( preg_match_all( '/\bwp-image-(\d+)\b/', (string) $post->post_content, $m ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $m[1] ) );
		}

		$alts = [];
		foreach ( array_slice( array_unique( array_filter( $ids ) ), 0, self::MAX_IMAGES ) as $id ) {
			$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );

			if ( '' !== $alt ) {
				$alts[] = $alt;
			}
		}

		return array_values( array_unique( $alts ) );
	}
}

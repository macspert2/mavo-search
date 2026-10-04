<?php
/**
 * One post → one search document: its text, and every term it is found by,
 * per field.
 *
 * Fields, and what goes in each:
 *
 *   title     post_title
 *   heading   <h1>–<h6> text of the content
 *   content   the visible text of post_content (MVS_Extractor)
 *   excerpt   the hand-written excerpt
 *   alt       its images' alt text in its language (MVS_Images)
 *   taxonomy  names of its terms in mavo_search_taxonomies (default post_tag,
 *             as Relevanssi indexed), place tags excepted — those are…
 *   place     …its place chain, by strength (MVS_Geo)
 *   hub       its hubs' titles, by strength (MVS_Hubs)
 *   concept   its images' concepts, "#slug" and label, by confidence
 *   guide     names of the places it is the landing page for (MVS_Geo)
 *   custom    values of the meta keys in mavo_search_custom_fields (none by
 *             default — nothing on this site needed one in Relevanssi)
 *
 * Which posts: published post and page (mavo_search_post_types), not marked
 * noindex in Yoast — Relevanssi's "respect noindex" was on — and whatever
 * mavo_search_index_post says. Password-protected posts are found by title
 * and excerpt only.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Document {

	/** Yoast's per-post noindex flag ('1' = noindex). Read here and in MVS_Status's SQL. */
	const NOINDEX_META = '_yoast_wpseo_meta-robots-noindex';

	/** Hub pages are guides to what they gather: a mild lift over a single article. */
	const HUB_BOOST = 1.15;

	/** @return string[] */
	public static function post_types(): array {
		/** Post types searched. Attachments are never results. */
		$types = (array) apply_filters( 'mavo_search_post_types', [ 'post', 'page' ] );

		return array_values( array_diff( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ), [ 'attachment' ] ) );
	}

	/** @return string[] */
	public static function taxonomies(): array {
		/** Taxonomies whose term names are indexed. Place tags go to the place field instead. */
		return array_values( array_filter( array_map( 'sanitize_key', (array) apply_filters( 'mavo_search_taxonomies', [ 'post_tag' ] ) ) ) );
	}

	/** @return string[] */
	public static function custom_fields(): array {
		/** Meta keys whose values are indexed in the custom field. */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'mavo_search_custom_fields', [] ) ) ) );
	}

	public static function eligible( ?WP_Post $post ): bool {
		$ok = $post instanceof WP_Post
			&& 'publish' === $post->post_status
			&& in_array( $post->post_type, self::post_types(), true )
			&& '1' !== (string) get_post_meta( $post->ID, self::NOINDEX_META, true );

		/** Whether a post belongs in the index at all. */
		return (bool) apply_filters( 'mavo_search_index_post', $ok, $post );
	}

	/**
	 * @return array{post_id:int,lang:string,post_type:string,title:string,title_norm:string,
	 *               excerpt:string,content:string,boost:float,signals:array,
	 *               fields:array<string,array<string,int>>,post_date:string,post_modified:string,hash:string}
	 */
	public static function build( WP_Post $post ): array {
		$lang      = MVS_Lang::of_post( $post->ID );
		$protected = '' !== (string) $post->post_password;
		$extracted = $protected ? [ 'text' => '', 'headings' => [] ] : MVS_Extractor::extract( (string) $post->post_content );
		$places    = MVS_Geo::places( $post->ID, $lang );
		$hubs      = MVS_Hubs::hubs( $post->ID, $lang );
		$images    = MVS_Images::for_post( $post, $lang );
		$guide     = MVS_Geo::guide_places( $post->ID );

		$sources = [
			'title'    => [ MVS_Extractor::clean_line( wp_strip_all_tags( (string) $post->post_title ) ) ],
			'heading'  => $extracted['headings'],
			'content'  => [ $extracted['text'] ],
			'excerpt'  => [ MVS_Extractor::clean_line( wp_strip_all_tags( (string) $post->post_excerpt ) ) ],
			'alt'      => $images['alts'],
			'taxonomy' => self::term_names( $post->ID, array_column( $places, 'term_id' ) ),
			'custom'   => self::custom_values( $post->ID ),
			'guide'    => $guide,
			// Weighted fields: [ text, strength 0–100 ].
			'place'    => array_map( static fn( $p ) => [ $p['name'], $p['strength'] ], $places ),
			'hub'      => array_map( static fn( $h ) => [ $h['name'], $h['strength'] ], $hubs ),
			'concept'  => self::concept_sources( $images['concepts'] ),
		];

		/**
		 * Everything indexed for a post, by field, before it becomes terms.
		 * Text fields hold strings; place, hub and concept hold [ text, 0–100 ].
		 */
		$sources = (array) apply_filters( 'mavo_search_document_sources', $sources, $post, $lang );

		$boost = MVS_Hubs::is_hub( $post->ID ) ? self::HUB_BOOST : 1.0;

		/** A post's score multiplier — the place to lift guides or bury stale pages. */
		$boost = max( 0.1, min( 10.0, (float) apply_filters( 'mavo_search_document_boost', $boost, $post->ID, $lang ) ) );

		$signals = [
			'places'   => array_map( static fn( $p ) => [ 'term_id' => $p['term_id'], 'name' => $p['name'], 'level' => $p['level'] ], $places ),
			'hubs'     => array_column( $hubs, 'hub_id' ),
			'concepts' => array_keys( $images['concepts'] ),
			'guide'    => $guide,
			'is_hub'   => MVS_Hubs::is_hub( $post->ID ),
		];

		$title = (string) ( $sources['title'][0] ?? '' );
		$doc   = [
			'post_id'       => (int) $post->ID,
			'lang'          => $lang,
			'post_type'     => (string) $post->post_type,
			'title'         => $title,
			'title_norm'    => MVS_Text::normalize( $title ),
			'excerpt'       => (string) ( $sources['excerpt'][0] ?? '' ),
			'content'       => (string) ( $sources['content'][0] ?? '' ),
			'boost'         => round( $boost, 3 ),
			'signals'       => $signals,
			'fields'        => self::fields( $sources, $lang ),
			'post_date'     => (string) $post->post_date_gmt,
			'post_modified' => (string) $post->post_modified_gmt,
		];

		$doc['hash'] = md5( serialize( [ $doc['title'], $doc['excerpt'], $doc['content'], $doc['boost'], $doc['signals'], $doc['fields'], $lang, $doc['post_type'] ] ) );

		return $doc;
	}

	/**
	 * term => [ field => value ], the shape the terms table stores.
	 *
	 * @return array<string,array<string,int>>
	 */
	public static function fields( array $sources, string $lang ): array {
		$out = [];

		foreach ( MVS_DB::TEXT_FIELDS as $field ) {
			foreach ( (array) ( $sources[ $field ] ?? [] ) as $text ) {
				foreach ( MVS_Text::terms( (string) $text, $lang ) as $term => $count ) {
					$out[ $term ][ $field ] = min( 65535, ( $out[ $term ][ $field ] ?? 0 ) + $count );
				}
			}
		}

		foreach ( MVS_DB::WEIGHT_FIELDS as $field ) {
			foreach ( (array) ( $sources[ $field ] ?? [] ) as $entry ) {
				[ $text, $strength ] = array_pad( (array) $entry, 2, 100 );
				$strength            = max( 1, min( 100, (int) $strength ) );
				$terms               = str_starts_with( (string) $text, '#' ) ? [ (string) $text => 1 ] : MVS_Text::terms( (string) $text, $lang );

				foreach ( array_keys( $terms ) as $term ) {
					$out[ $term ][ $field ] = max( $out[ $term ][ $field ] ?? 0, $strength );
				}
			}
		}

		ksort( $out );

		return $out;
	}

	/** The index rules: change any of them and every document is stale. */
	public static function rules_version(): string {
		return md5( MVS_Indexer::VERSION . '|' . MVS_Text::version() . '|' . implode( ',', self::taxonomies() ) . '|' . implode( ',', self::custom_fields() ) );
	}

	/* -------------------------------------------------------------- private */

	/** @param int[] $exclude Place term IDs, indexed as places instead. */
	private static function term_names( int $post_id, array $exclude ): array {
		$names = [];

		foreach ( self::taxonomies() as $taxonomy ) {
			$terms = get_the_terms( $post_id, $taxonomy );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( ! in_array( (int) $term->term_id, $exclude, true ) ) {
					$names[] = (string) $term->name;
				}
			}
		}

		return $names;
	}

	private static function custom_values( int $post_id ): array {
		$values = [];

		foreach ( self::custom_fields() as $key ) {
			foreach ( (array) get_post_meta( $post_id, $key ) as $value ) {
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$values[] = MVS_Extractor::clean_line( wp_strip_all_tags( (string) $value ) );
				}
			}
		}

		return $values;
	}

	/** @param array<string,array{label:string,confidence:float}> $concepts */
	private static function concept_sources( array $concepts ): array {
		$out = [];

		foreach ( $concepts as $slug => $concept ) {
			$strength = (int) round( 100 * max( 0.0, min( 1.0, (float) $concept['confidence'] ) ) );
			$out[]    = [ '#' . $slug, $strength ];

			if ( '' !== $concept['label'] ) {
				$out[] = [ $concept['label'], $strength ];
			}
		}

		return $out;
	}
}

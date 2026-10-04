<?php
/**
 * The hub side of a post, through mavo-hubs' procedural API.
 *
 * A post belongs to any number of hubs, and hubs to hubs. Every hub above the
 * post is indexed by its title — "Harry Potter", "City trips en famille" — its
 * own hubs at full strength and each step up weaker, using the depth map
 * mavo-hubs already derives:
 *
 *   depth 0  100     depth 1  60     depth 2  36     deeper: not indexed
 *
 * Only hubs a reader could be sent to count: published, still a hub, in the
 * post's language. Hubs are editorial themes, never geography — that is
 * MVS_Geo — and never inferred from image concepts.
 *
 * Without mavo-hubs: no hub field, no boost, no error.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Hubs {

	const MAX_DEPTH = 2;
	const DECAY     = 0.6;

	public static function available(): bool {
		return function_exists( 'mavo_get_hub_ancestors' );
	}

	public static function is_hub( int $post_id ): bool {
		return function_exists( 'mavo_is_hub' ) && mavo_is_hub( $post_id );
	}

	/**
	 * @return array<int,array{hub_id:int,name:string,depth:int,strength:int}> Nearest first.
	 */
	public static function hubs( int $post_id, string $lang ): array {
		if ( ! self::available() ) {
			return [];
		}

		$out = [];

		foreach ( (array) mavo_get_hub_ancestors( $post_id ) as $hub_id => $depth ) {
			$hub_id = (int) $hub_id;
			$depth  = (int) $depth;

			if ( $depth > self::MAX_DEPTH || $hub_id === $post_id || ! self::is_hub( $hub_id ) ) {
				continue;
			}

			$hub = get_post( $hub_id );

			if ( ! $hub instanceof WP_Post || 'publish' !== $hub->post_status || MVS_Lang::of_post( $hub_id ) !== $lang ) {
				continue;
			}

			$out[] = [
				'hub_id'   => $hub_id,
				'name'     => MVS_Extractor::clean_line( wp_strip_all_tags( $hub->post_title ) ),
				'depth'    => $depth,
				'strength' => (int) round( 100 * ( self::DECAY ** $depth ) ),
			];
		}

		usort( $out, static fn( $a, $b ) => [ $a['depth'], $a['hub_id'] ] <=> [ $b['depth'], $b['hub_id'] ] );

		/** A post's indexed hubs, nearest first. */
		return (array) apply_filters( 'mavo_search_hubs', $out, $post_id, $lang );
	}

	/**
	 * Posts whose hub field shows this hub: everything below it, as deep as
	 * the field goes. For reindexing after a hub is renamed or (un)marked.
	 *
	 * @return int[]
	 */
	public static function affected_by( int $hub_id ): array {
		if ( ! function_exists( 'mavo_get_hub_descendants' ) ) {
			return [];
		}

		return array_values( array_unique( array_map( 'intval', (array) mavo_get_hub_descendants( $hub_id ) ) ) );
	}
}

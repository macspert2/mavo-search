<?php
/**
 * Ranked result lists, so page 2 of a search does not rank everything again.
 *
 * Two layers — a per-request array and the WordPress object cache — under a
 * generation number that any index write increments, the same scheme as
 * mavo-image-index's MII_Cache. Without a persistent object cache the second
 * layer lives one request and costs nothing; with one, old generations simply
 * stop being read and expire. No transients: one per distinct query would be
 * rows in wp_options.
 *
 * Bumping touches nothing outside this plugin: no page cache is purged when
 * the index changes. Search pages are not page-cached on this site anyway
 * (the theme bypasses Cache Enabler for is_search()).
 */

defined( 'ABSPATH' ) || exit;

class MVS_Cache {

	const GROUP      = 'mavo_search';
	const GEN_OPTION = 'mavo_search_cache_gen';
	const TTL        = HOUR_IN_SECONDS;

	private static ?int $gen = null;
	private static array $local = [];

	public static function get( string $key ) {
		$key = self::key( $key );

		if ( array_key_exists( $key, self::$local ) ) {
			return self::$local[ $key ];
		}

		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );

		if ( $found ) {
			self::$local[ $key ] = $value;
			return $value;
		}

		return null;
	}

	public static function set( string $key, $value ): void {
		$key = self::key( $key );

		// Bounded: a request ranking many queries (a CLI benchmark) must not
		// keep every list in memory.
		if ( count( self::$local ) > 50 ) {
			self::$local = [];
		}

		self::$local[ $key ] = $value;
		wp_cache_set( $key, $value, self::GROUP, self::TTL );
	}

	/** Invalidate everything. */
	public static function bump(): void {
		self::$gen   = self::gen() + 1;
		self::$local = [];

		update_option( self::GEN_OPTION, self::$gen, true );
	}

	public static function gen(): int {
		if ( null === self::$gen ) {
			self::$gen = max( 1, (int) get_option( self::GEN_OPTION, 1 ) );
		}

		return self::$gen;
	}

	private static function key( string $key ): string {
		return self::gen() . ':' . $key;
	}

	/** For tests. */
	public static function reset(): void {
		self::$gen   = null;
		self::$local = [];
	}
}

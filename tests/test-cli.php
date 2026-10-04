<?php
/**
 * wp mavo-search: every command runs and says something sensible.
 */

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ) {
		foreach ( $items as $item ) { \WP_CLI::line( implode( ' | ', array_map( static fn( $f ) => (string) ( ( (array) $item )[ $f ] ?? '' ), $fields ) ) ); }
	}
	function make_progress_bar( $label, $total ) {
		return new class { public function tick( $n = 1 ) {} public function finish() {} };
	}
}

namespace {
	require __DIR__ . '/harness.php';
	require __DIR__ . '/stubs-integrations.php';

	class WP_CLI {
		public static array $out = [];
		public static function line( $s = '' ) { self::$out[] = $s; }
		public static function success( $s ) { self::$out[] = "Success: $s"; }
		public static function warning( $s ) { self::$out[] = "Warning: $s"; }
		public static function error( $s ) { throw new RuntimeException( $s ); }
		public static function take(): string { $o = implode( "\n", self::$out ); self::$out = []; return $o; }
	}
	function size_format( $b ) { return "$b B"; }
	function wp_date( $f, $t ) { return gmdate( $f, $t ); }

	require MVS_PLUGIN_DIR . 'includes/class-mavo-search-cli.php';

	mvs_post( 1, 'Lisbonne en famille', '<p>Le tram 28.</p>' );
	mvs_post( 2, 'Porto', '<p>Les caves.</p>', [ 'lang' => 'en' ] );
	$cli = new MVS_CLI();

	$cli->rebuild( [], [ 'all' => true, 'lang' => 'fr' ] );
	$out = WP_CLI::take();
	check( 'filtered rebuild does not make the index serve', str_contains( $out, 'not serving searches yet' ), $out );

	$cli->rebuild( [], [ 'all' => true ] );
	check( 'full rebuild', str_contains( WP_CLI::take(), 'Success: 2 posts processed, 0 failed.' ) );
	check( 'ready', MVS_WP::ready() );

	$cli->rebuild( [], [ 'post' => '1' ] );
	same( 'one post', 'Success: Post 1: indexed', WP_CLI::take() );

	try { $cli->rebuild( [], [] ); check( 'rebuild needs a mode', false ); } catch ( RuntimeException $e ) { check( 'rebuild needs a mode', str_contains( $e->getMessage(), '--all' ) ); }

	$cli->status( [], [] );
	check( 'status', str_contains( WP_CLI::take(), 'Serving searches: yes' ) );

	$cli->search( [ 'lisbonne tram' ], [ 'explain' => true, 'excerpts' => true ] );
	$out = WP_CLI::take();
	check( 'search: parsed query, table, explanation, excerpt', str_contains( $out, 'Query (fr): [lisbonne' ) && str_contains( $out, '1 | 1 | Lisbonne en famille' ) && str_contains( $out, 'title: lisbonne' ) && str_contains( $out, '#1 Le tram 28.' ), $out );

	$cli->explain( [ 'tram' ], [ 'post' => '1' ] );
	check( 'explain', str_contains( WP_CLI::take(), 'Rank 1 of 1.' ) );
	$cli->explain( [ 'caves' ], [ 'post' => '1' ] );
	check( 'explain a post not found', str_contains( WP_CLI::take(), 'is not among' ) );

	$cli->terms( [], [ 'post' => '1', 'field' => 'title' ] );
	$out = WP_CLI::take();
	check( 'terms by field', str_contains( $out, 'lisbonne | title:1' ) && ! str_contains( $out, 'tram' ), $out );

	MVS_Log::record( 'zanzibar', 'fr', 0 );
	$cli->logs( [], [ 'zero' => true ] );
	check( 'logs --zero', str_contains( WP_CLI::take(), 'zanzibar | fr | 1 | 0' ) );

	done();
}

<?php
/**
 * WordPress stubs over an in-memory SQLite database, so the plugin's real SQL
 * — indexing, ranking, status — runs in the tests rather than a mock of it.
 * The plugin's SQL is kept to what MySQL and SQLite share for that reason.
 *
 * Polylang, mavo-geotag-plus, mavo-hubs and mavo-image-index are NOT stubbed
 * here: a test that wants them includes stubs-integrations.php, so the tests
 * that leave them out prove the plugin works without them.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'MVS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'MVS_PLUGIN_URL', 'https://example.test/wp-content/plugins/mavo-search/' );
define( 'MVS_VERSION', 'test' );

/* ------------------------------------------------------------------ wpdb */

class Test_WPDB {
	public string $prefix             = 'wp_';
	public string $posts              = 'wp_posts';
	public string $postmeta           = 'wp_postmeta';
	public string $terms              = 'wp_terms';
	public string $term_taxonomy      = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $charset            = 'utf8mb4';
	public string $last_error         = '';
	public int $insert_id             = 0;
	public array $queries             = [];
	public int $num_queries           = 0;
	public PDO $pdo;

	public function __construct() {
		$this->pdo = class_exists( 'Pdo\\Sqlite' ) ? PDO::connect( 'sqlite::memory:' ) : new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT );
		$this->schema();
	}

	private function schema(): void {
		$fields = '';
		foreach ( MVS_FIELDS as $f ) {
			$fields .= ", $f INTEGER DEFAULT 0";
		}

		$this->pdo->exec( "
			CREATE TABLE wp_posts ( ID INTEGER PRIMARY KEY, post_type TEXT DEFAULT 'post', post_status TEXT DEFAULT 'publish',
				post_title TEXT DEFAULT '', post_content TEXT DEFAULT '', post_excerpt TEXT DEFAULT '', post_password TEXT DEFAULT '',
				post_date TEXT DEFAULT '2024-01-01 00:00:00', post_date_gmt TEXT DEFAULT '2024-01-01 00:00:00',
				post_modified_gmt TEXT DEFAULT '2024-01-01 00:00:00', post_mime_type TEXT DEFAULT '' );
			CREATE TABLE wp_postmeta ( meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT );
			CREATE TABLE wp_terms ( term_id INTEGER PRIMARY KEY, name TEXT, slug TEXT );
			CREATE TABLE wp_term_taxonomy ( term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT );
			CREATE TABLE wp_term_relationships ( object_id INTEGER, term_taxonomy_id INTEGER );

			CREATE TABLE wp_mavo_search_docs ( doc_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER UNIQUE, lang TEXT, post_type TEXT,
				title TEXT, title_norm TEXT, excerpt TEXT, content TEXT, alts TEXT DEFAULT '', boost REAL DEFAULT 1, signals TEXT DEFAULT '{}',
				post_date TEXT, post_modified TEXT, source_hash TEXT DEFAULT '', rules_version TEXT DEFAULT '',
				status TEXT DEFAULT 'indexed', error TEXT DEFAULT '', indexed_at TEXT );
			CREATE TABLE wp_mavo_search_terms ( doc_id INTEGER, term TEXT, lang TEXT $fields, PRIMARY KEY ( doc_id, term ) );
			CREATE INDEX wp_mavo_search_terms_term ON wp_mavo_search_terms ( term, lang );
			CREATE TABLE wp_mavo_search_log ( id INTEGER PRIMARY KEY AUTOINCREMENT, query TEXT, lang TEXT, day TEXT,
				searches INTEGER DEFAULT 0, results INTEGER DEFAULT 0, fallback TEXT DEFAULT '', UNIQUE ( query, lang, day ) );
			CREATE TABLE wp_mavo_search_clicks ( id INTEGER PRIMARY KEY AUTOINCREMENT, query TEXT, lang TEXT, day TEXT, post_id INTEGER,
				source TEXT DEFAULT 'result', clicks INTEGER DEFAULT 0, rank_total INTEGER DEFAULT 0, UNIQUE ( query, lang, day, post_id, source ) );
		" );
	}

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$i = 0;

		return preg_replace_callback( '/%%|%[dsf]/', function ( $m ) use ( &$i, $args ) {
			if ( '%%' === $m[0] ) {
				return '%';
			}
			$v = $args[ $i++ ] ?? null;
			return match ( $m[0] ) {
				'%d' => (string) (int) $v,
				'%f' => (string) (float) $v,
				default => $this->pdo->quote( (string) $v ),
			};
		}, $query );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_charset_collate() {
		return '';
	}

	private function run( string $sql ) {
		$this->num_queries++;
		$this->queries[] = $sql;

		// MySQL's LIKE escape is a backslash by default; SQLite needs it said.
		$sql  = preg_replace( "/(LIKE\s+'(?:[^']|'')*\\\\[_%\\\\](?:[^']|'')*')/", "$1 ESCAPE '\\'", $sql );
		$stmt = $this->pdo->query( $sql );

		if ( false === $stmt ) {
			$this->last_error = implode( ' ', $this->pdo->errorInfo() ) . " in: $sql";
			fwrite( STDERR, "SQL ERROR: {$this->last_error}\n" );
		} else {
			$this->last_error = '';
		}

		return $stmt;
	}

	public function get_results( $sql, $output = OBJECT ) {
		$stmt = $this->run( $sql );

		if ( ! $stmt ) {
			return null;
		}

		$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );

		return ARRAY_A === $output ? $rows : array_map( static fn( $r ) => (object) $r, $rows );
	}

	public function get_row( $sql, $output = OBJECT ) {
		$rows = $this->get_results( $sql, $output );
		return $rows[0] ?? null;
	}

	public function get_var( $sql ) {
		$stmt = $this->run( $sql );
		if ( ! $stmt ) {
			return null;
		}
		$v = $stmt->fetchColumn();
		return false === $v ? null : $v;
	}

	public function get_col( $sql ) {
		$stmt = $this->run( $sql );
		return $stmt ? $stmt->fetchAll( PDO::FETCH_COLUMN, 0 ) : [];
	}

	public function query( $sql ) {
		$stmt = $this->run( $sql );
		return $stmt ? $stmt->rowCount() : false;
	}

	private function write( string $verb, string $table, array $data ) {
		$cols = array_keys( $data );
		$sql  = "$verb INTO $table (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', array_fill( 0, count( $cols ), '?' ) ) . ')';
		$stmt = $this->pdo->prepare( $sql );
		$this->num_queries++;

		if ( ! $stmt || ! @$stmt->execute( array_values( $data ) ) ) {
			$this->last_error = implode( ' ', ( $stmt ?: $this->pdo )->errorInfo() );
			return false;
		}

		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}

	public function insert( $table, $data, $format = null ) {
		return $this->write( 'INSERT', $table, $data );
	}

	public function replace( $table, $data, $format = null ) {
		return $this->write( 'REPLACE', $table, $data );
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$set  = implode( ',', array_map( static fn( $c ) => "$c = ?", array_keys( $data ) ) );
		$cond = implode( ' AND ', array_map( static fn( $c ) => "$c = ?", array_keys( $where ) ) );
		$stmt = $this->pdo->prepare( "UPDATE $table SET $set WHERE $cond" );
		$this->num_queries++;
		return $stmt && $stmt->execute( array_merge( array_values( $data ), array_values( $where ) ) ) ? $stmt->rowCount() : false;
	}

	public function delete( $table, $where, $format = null ) {
		$cond = implode( ' AND ', array_map( static fn( $c ) => "$c = ?", array_keys( $where ) ) );
		$stmt = $this->pdo->prepare( "DELETE FROM $table WHERE $cond" );
		$this->num_queries++;
		return $stmt && $stmt->execute( array_values( $where ) ) ? $stmt->rowCount() : false;
	}
}

const MVS_FIELDS = [ 'title', 'heading', 'content', 'excerpt', 'alt', 'taxonomy', 'custom', 'guide', 'place', 'hub', 'concept' ];

$GLOBALS['wpdb'] = new Test_WPDB();

/* ----------------------------------------------------------------- hooks */

$GLOBALS['MOCK_HOOKS']      = [];
$GLOBALS['MOCK_ACTIONS']    = [];
$GLOBALS['MOCK_OPTIONS']    = [];
$GLOBALS['MOCK_CACHE']      = [];
$GLOBALS['MOCK_META_CACHE'] = [];
$GLOBALS['MOCK_CRON']       = [];
$GLOBALS['MOCK_CAN_EDIT']   = false;
$GLOBALS['MOCK_IS_ADMIN']   = false;
$GLOBALS['shortcode_tags']  = [ 'gallery' => true, 'caption' => true, 'mavo_hub_strip' => true ];

function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['MOCK_HOOKS'][ $tag ][ $priority ][] = [ $cb, $args ];
	ksort( $GLOBALS['MOCK_HOOKS'][ $tag ] );
	return true;
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	return add_filter( $tag, $cb, $priority, $args );
}
function remove_all_filters( $tag ) {
	unset( $GLOBALS['MOCK_HOOKS'][ $tag ] );
}
function apply_filters( $tag, $value, ...$rest ) {
	foreach ( $GLOBALS['MOCK_HOOKS'][ $tag ] ?? [] as $callbacks ) {
		foreach ( $callbacks as [ $cb, $n ] ) {
			$value = $cb( ...array_slice( array_merge( [ $value ], $rest ), 0, max( 1, $n ) ) );
		}
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	$GLOBALS['MOCK_ACTIONS'][] = array_merge( [ $tag ], $args );
	foreach ( $GLOBALS['MOCK_HOOKS'][ $tag ] ?? [] as $callbacks ) {
		foreach ( $callbacks as [ $cb, $n ] ) {
			$cb( ...array_slice( $args, 0, $n ) );
		}
	}
}

/* --------------------------------------------------------- core functions */

function get_option( $k, $d = false ) { return $GLOBALS['MOCK_OPTIONS'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['MOCK_OPTIONS'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['MOCK_OPTIONS'][ $k ] ); return true; }
function wp_cache_get( $k, $g = '', $force = false, &$found = null ) {
	$found = array_key_exists( "$g:$k", $GLOBALS['MOCK_CACHE'] );
	return $found ? $GLOBALS['MOCK_CACHE'][ "$g:$k" ] : false;
}
function wp_cache_set( $k, $v, $g = '', $ttl = 0 ) { $GLOBALS['MOCK_CACHE'][ "$g:$k" ] = $v; return true; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function __( $s, $d = '' ) { return $s; }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function wp_is_post_revision( $id ) { return false; }
function wp_is_post_autosave( $id ) { return false; }
function wp_next_scheduled( $hook ) { return $GLOBALS['MOCK_CRON'][ $hook ] ?? false; }
function wp_schedule_single_event( $t, $hook ) { $GLOBALS['MOCK_CRON'][ $hook ] = $t; return true; }
function wp_schedule_event( $t, $r, $hook ) { $GLOBALS['MOCK_CRON'][ $hook ] = $t; return true; }
function current_time( $f ) { return gmdate( $f ); }
function current_user_can( $cap ) { return $GLOBALS['MOCK_CAN_EDIT']; }
function is_admin() { return $GLOBALS['MOCK_IS_ADMIN']; }
function is_search() { return ! empty( $GLOBALS['MOCK_IS_SEARCH'] ); }
function get_search_query( $escaped = true ) { return $GLOBALS['MOCK_SEARCH'] ?? ''; }
function wp_enqueue_style( ...$a ) { $GLOBALS['MOCK_STYLES'][] = $a[0]; }
function get_the_title( $post = 0 ) { $p = get_post( $post ); return $p ? $p->post_title : ''; }
function get_post_thumbnail_id( $post = null ) { $p = get_post( $post ); return $p ? (int) get_post_meta( $p->ID, '_thumbnail_id', true ) : 0; }
function add_query_arg( $args, $url = '' ) {
	if ( is_string( $args ) ) { $args = [ $args => $url ]; $url = func_get_arg( 2 ); }
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . implode( '&', array_map( static fn( $k, $v ) => "$k=$v", array_keys( $args ), $args ) );
}
function wp_parse_url( $url, $c = -1 ) { return -1 === $c ? parse_url( $url ) : parse_url( $url, $c ); }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . $p; }
function wp_enqueue_script( ...$a ) { $GLOBALS['MOCK_SCRIPTS'][] = $a[0]; }
function wp_localize_script( $h, $name, $data ) { $GLOBALS['MOCK_LOCALIZED'][ $name ] = $data; }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['MOCK_ROUTES'][ "$ns$route" ] = $args; }
class WP_REST_Response { public $data; public $status; public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; } }
function get_post_field( $field, $post ) { $p = get_post( $post ); return $p ? (string) ( $p->$field ?? '' ) : ''; }
function get_post_type( $id ) { $p = get_post( $id ); return $p ? $p->post_type : false; }

function update_meta_cache( $type, $ids ) {
	global $wpdb;
	$ids = array_values( array_filter( array_map( 'intval', (array) $ids ), static fn( $id ) => ! isset( $GLOBALS['MOCK_META_CACHE'][ $id ] ) ) );
	if ( ! $ids ) { return true; }
	foreach ( $ids as $id ) { $GLOBALS['MOCK_META_CACHE'][ $id ] = []; }
	foreach ( $wpdb->get_results( 'SELECT post_id, meta_key, meta_value FROM wp_postmeta WHERE post_id IN (' . implode( ',', $ids ) . ') ORDER BY meta_id', ARRAY_A ) as $row ) {
		$GLOBALS['MOCK_META_CACHE'][ (int) $row['post_id'] ][ $row['meta_key'] ][] = $row['meta_value'];
	}
	return true;
}
function _prime_post_caches( $ids, $a = true, $b = true ) {}
function get_post_meta( $id, $key = '', $single = false ) {
	update_meta_cache( 'post', [ $id ] );
	$values = $GLOBALS['MOCK_META_CACHE'][ (int) $id ][ $key ] ?? [];
	return $single ? ( $values[0] ?? '' ) : $values;
}

#[AllowDynamicProperties]
class WP_Post {
	public $ID;
	public $post_type = 'post';
	public $post_status = 'publish';
	public $post_title = '';
	public $post_content = '';
	public $post_excerpt = '';
	public $post_password = '';
	public $post_date = '';
	public $post_date_gmt = '';
	public $post_modified_gmt = '';
}

#[AllowDynamicProperties]
class WP_Term {
	public $term_id;
	public $name;
	public $slug;
	public $taxonomy;
}

function get_post( $id = null ) {
	global $wpdb;
	if ( null === $id ) { $id = $GLOBALS['post'] ?? 0; }
	if ( $id instanceof WP_Post ) { return $id; }
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM wp_posts WHERE ID = %d', (int) $id ), ARRAY_A );
	if ( ! $row ) { return null; }
	$p = new WP_Post();
	foreach ( $row as $k => $v ) { $p->$k = $v; }
	$p->ID = (int) $row['ID'];
	return $p;
}

function get_term( $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT t.term_id, t.name, t.slug, tt.taxonomy FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id WHERE t.term_id = %d', (int) $id ), ARRAY_A );
	if ( ! $row ) { return null; }
	$t = new WP_Term();
	foreach ( $row as $k => $v ) { $t->$k = $v; }
	$t->term_id = (int) $row['term_id'];
	return $t;
}

function get_the_terms( $post_id, $taxonomy ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT t.term_id, t.name, t.slug, tt.taxonomy FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		  JOIN wp_terms t ON t.term_id = tt.term_id WHERE tr.object_id = %d AND tt.taxonomy = %s', (int) $post_id, $taxonomy ), ARRAY_A );
	if ( ! $rows ) { return false; }
	return array_map( static function ( $r ) { $t = new WP_Term(); foreach ( $r as $k => $v ) { $t->$k = $v; } $t->term_id = (int) $r['term_id']; return $t; }, $rows );
}

/** A query object for the WordPress integration tests. */
class WP_Query {
	public array $query_vars = [];
	public $found_posts = 0;
	public $max_num_pages = 0;
	public bool $main = true;
	public bool $search = true;
	public function __construct( array $vars = [] ) { $this->query_vars = $vars; }
	public function get( $k, $d = '' ) { return $this->query_vars[ $k ] ?? $d; }
	public function is_main_query() { return $this->main; }
	public function is_search() { return $this->search; }
}

/* ----------------------------------------------------------------- plugin */

require MVS_PLUGIN_DIR . 'includes/class-mavo-search-db.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-lang.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-text.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-cache.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-extractor.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-geo.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-hubs.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-images.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-document.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-indexer.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-query.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-best-bets.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-engine.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-highlight.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-excerpt.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-reason.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-recover.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-status.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-rebuild.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-sync.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-log.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-clicks.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-suggest.php';
require MVS_PLUGIN_DIR . 'includes/class-mavo-search-wp.php';
require MVS_PLUGIN_DIR . 'includes/api.php';

if ( array_diff( MVS_FIELDS, MVS_DB::fields() ) || array_diff( MVS_DB::fields(), MVS_FIELDS ) ) {
	fwrite( STDERR, "harness MVS_FIELDS out of step with MVS_DB::fields()\n" );
	exit( 1 );
}

/* --------------------------------------------------------------- fixtures */

function mvs_meta( int $post_id, string $key, $value ): void {
	global $wpdb;
	unset( $GLOBALS['MOCK_META_CACHE'][ $post_id ] );
	$wpdb->delete( 'wp_postmeta', [ 'post_id' => $post_id, 'meta_key' => $key ] );
	if ( null !== $value ) {
		$wpdb->insert( 'wp_postmeta', [ 'post_id' => $post_id, 'meta_key' => $key, 'meta_value' => (string) $value ] );
	}
}

/** A post. $opts: type, status, excerpt, lang, date, modified, password, tags (names). */
function mvs_post( int $id, string $title, string $content = '', array $opts = [] ): void {
	global $wpdb;
	$wpdb->replace( 'wp_posts', [
		'ID'                => $id,
		'post_type'         => $opts['type'] ?? 'post',
		'post_status'       => $opts['status'] ?? 'publish',
		'post_title'        => $title,
		'post_content'      => $content,
		'post_excerpt'      => $opts['excerpt'] ?? '',
		'post_password'     => $opts['password'] ?? '',
		'post_date'         => $opts['date'] ?? '2024-01-01 00:00:00',
		'post_date_gmt'     => $opts['date'] ?? '2024-01-01 00:00:00',
		'post_modified_gmt' => $opts['modified'] ?? '2024-01-01 00:00:00',
	] );
	if ( isset( $opts['lang'] ) ) {
		$GLOBALS['MOCK_POST_LANG'][ $id ] = $opts['lang'];
	}
	foreach ( $opts['tags'] ?? [] as $tag ) {
		mvs_term_rel( $id, mvs_term( $tag ) );
	}
}

function mvs_term( string $name, string $taxonomy = 'post_tag', ?int $id = null ): int {
	global $wpdb;
	$slug     = strtolower( preg_replace( '/\W+/u', '-', $name ) );
	$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT t.term_id FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id WHERE t.name = %s AND tt.taxonomy = %s', $name, $taxonomy ) );
	if ( $existing ) { return (int) $existing; }
	$id = $id ?? ( 1000 + (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_terms' ) );
	$wpdb->insert( 'wp_terms', [ 'term_id' => $id, 'name' => $name, 'slug' => $slug ] );
	$wpdb->insert( 'wp_term_taxonomy', [ 'term_taxonomy_id' => $id, 'term_id' => $id, 'taxonomy' => $taxonomy ] );
	return $id;
}

function mvs_term_rel( int $object_id, int $tt_id ): void {
	global $wpdb;
	$wpdb->insert( 'wp_term_relationships', [ 'object_id' => $object_id, 'term_taxonomy_id' => $tt_id ] );
}

/** Index everything, as a full rebuild does, and mark the index ready. */
function mvs_rebuild(): void {
	$cursor = 0;
	do {
		$step   = MVS_Rebuild::step( 'all', $cursor, 50 );
		$cursor = $step['cursor'];
	} while ( ! $step['done'] );
}

/** Post IDs of a search, in order. */
function ids( string $query, array $args = [] ): array {
	return array_column( mavo_search( $query, $args + [ 'excerpts' => false ] )['results'], 'post_id' );
}

/* -------------------------------------------------------------- assertions */

$GLOBALS['MVS_FAILS']  = 0;
$GLOBALS['MVS_PASSES'] = 0;

function check( string $label, bool $ok, $detail = null ): void {
	if ( $ok ) {
		$GLOBALS['MVS_PASSES']++;
		return;
	}
	$GLOBALS['MVS_FAILS']++;
	echo "  FAIL: $label" . ( null !== $detail ? ' — ' . ( is_string( $detail ) ? $detail : json_encode( $detail, JSON_UNESCAPED_UNICODE ) ) : '' ) . "\n";
}

function same( string $label, $expected, $actual ): void {
	check( $label, $expected === $actual, [ 'expected' => $expected, 'actual' => $actual ] );
}

function done(): void {
	printf( "  %d passed, %d failed\n", $GLOBALS['MVS_PASSES'], $GLOBALS['MVS_FAILS'] );
	exit( $GLOBALS['MVS_FAILS'] ? 1 : 0 );
}

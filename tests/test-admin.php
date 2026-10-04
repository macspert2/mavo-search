<?php
/**
 * Tools → Mavo Search renders — status, a test search with explanations,
 * integrations, weights, the log — and its forms are guarded.
 */

require __DIR__ . '/harness.php';
require __DIR__ . '/stubs-integrations.php';

function add_management_page( ...$a ) {}
function wp_nonce_field( $a ) { echo '<input type="hidden" name="_wpnonce" value="n">'; }
function submit_button( $t, $c = '', $n = '', $w = true ) { echo '<button>' . esc_html( $t ) . '</button>'; }
function esc_html_e( $s ) { echo esc_html( $s ); }
function esc_attr_e( $s ) { echo esc_attr( $s ); }
function esc_html__( $s ) { return esc_html( $s ); }
function esc_attr__( $s ) { return esc_attr( $s ); }
function esc_url( $s ) { return (string) $s; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function selected( $a, $b ) { echo $a === $b ? ' selected' : ''; }
function checked( $a ) { echo $a ? ' checked' : ''; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function size_format( $b ) { return $b . ' B'; }
function wp_date( $f, $t ) { return gmdate( $f, $t ); }
function get_permalink( $id ) { return "https://example.test/?p=$id"; }
function get_edit_post_link( $id ) { return "https://example.test/wp-admin/post.php?post=$id"; }
function wp_kses( $s, $allowed ) { return $s; }
function has_filter( $tag ) { return ! empty( $GLOBALS['MOCK_HOOKS'][ $tag ] ); }
function check_admin_referer( $a ) { if ( empty( $_POST['_wpnonce'] ) ) { throw new RuntimeException( 'bad nonce' ); } return true; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $v ) { return $v; }
function wp_die( $m ) { throw new RuntimeException( 'die: ' . $m ); }
function wp_safe_redirect( $u ) { throw new RuntimeException( 'redirect: ' . $u ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }

require MVS_PLUGIN_DIR . 'includes/class-mavo-search-admin.php';

mvs_post( 1, 'Lisbonne en famille', '<p>Le tram 28 à Lisbonne.</p>' );
mvs_post( 2, 'Porto', '<p>Les caves.</p>' );
mvs_rebuild();
MVS_Log::record( 'Lisbonne', 'fr', 1 );
MVS_Log::record( 'zanzibar', 'fr', 0 );

$GLOBALS['MOCK_CAN_EDIT'] = true;
$_POST = [ 'mvs_test' => '1', '_wpnonce' => 'n', 'lang' => 'fr', 'query' => 'lisbonne <b>tram</b>' ];

ob_start();
MVS_Admin::render_page();
$html = ob_get_clean();

check( 'status table', str_contains( $html, 'Should be indexed' ) );
check( 'test search ran, with the parsed query', str_contains( $html, '[lisbonne' ) && str_contains( $html, '[tram' ), substr( $html, 0, 0 ) );
check( 'result row with its highlighted excerpt', str_contains( $html, 'Lisbonne en famille' ) && str_contains( $html, '<mark class="mavo-search-highlight">' ) );
check( 'explanation rows', str_contains( $html, 'title: lisbonne' ) );
check( 'query input is escaped', ! str_contains( $html, '<b>tram</b>' ) );
check( 'integrations listed', str_contains( $html, 'mavo-image-index' ) && str_contains( $html, 'Relevanssi' ) );
check( 'weights listed', str_contains( $html, 'title_exact' ) );
check( 'log reports', str_contains( $html, 'zanzibar' ) && str_contains( $html, 'lisbonne' ) );
check( 'no readiness warning once ready', ! str_contains( $html, 'not complete yet' ) );

delete_option( MVS_WP::READY_OPTION );
$_POST = [];
ob_start();
MVS_Admin::render_page();
check( 'readiness warning before the first full rebuild', str_contains( ob_get_clean(), 'not complete yet' ) );

$GLOBALS['MOCK_CAN_EDIT'] = false;
try {
	MVS_Admin::render_page();
	check( 'page refused without the capability', false );
} catch ( RuntimeException $e ) {
	check( 'page refused without the capability', str_starts_with( $e->getMessage(), 'die:' ) );
}

$GLOBALS['MOCK_CAN_EDIT'] = true;
$_POST = [ 'post_id' => '1' ];
try {
	MVS_Admin::handle_reindex_post();
	check( 'reindex without a nonce refused', false );
} catch ( RuntimeException $e ) {
	same( 'reindex without a nonce refused', 'bad nonce', $e->getMessage() );
}

$_POST = [ 'post_id' => '1', '_wpnonce' => 'n' ];
try {
	MVS_Admin::handle_reindex_post();
} catch ( RuntimeException $e ) {
	check( 'reindex redirects back with the result', str_contains( $e->getMessage(), 'mvs_result=indexed' ), $e->getMessage() );
}

$_POST = [ '_wpnonce' => 'n' ];
try {
	MVS_Admin::handle_save_settings();
} catch ( RuntimeException $e ) {
	same( 'logging switched off', false, MVS_Log::enabled() );
}

done();

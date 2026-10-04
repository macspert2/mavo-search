<?php
/**
 * Deleting the plugin (not deactivating it) removes its tables and options.
 * The index is derived from posts, so a reinstall rebuilds it by itself; only
 * the search log is lost.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( [ 'mavo_search_docs', 'mavo_search_terms', 'mavo_search_log' ] as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

foreach ( [
	'mavo_search_db_version',
	'mavo_search_cache_gen',
	'mavo_search_ready',
	'mavo_search_last_rebuild',
	'mavo_search_background',
	'mavo_search_queue',
	'mavo_search_log_enabled',
] as $option ) {
	delete_option( $option );
}

foreach ( [ 'mavo_search_process_queue', 'mavo_search_background_rebuild', 'mavo_search_prune_log' ] as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

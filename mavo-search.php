<?php
/**
 * Plugin Name: Mavo Search
 * Plugin URI:  https://mamanvoyage.com
 * Description: The site search. A field-aware, multilingual index of posts and pages — title, text, places from mavo-geotag-plus, hubs from mavo-hubs, image alt text and concepts from mavo-image-index — serving ordinary ?s= searches with ranked results, contextual excerpts and highlighting. Replaces Relevanssi.
 * Version:     1.0.0
 * Author:      Mavo
 * Text Domain: mavo-search
 * Requires at least: 6.3
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'MVS_VERSION',     '1.0.0' );
define( 'MVS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'MVS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'MVS_PLUGIN_FILE', __FILE__ );

require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-db.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-lang.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-text.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-cache.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-extractor.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-geo.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-hubs.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-images.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-document.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-indexer.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-query.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-best-bets.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-engine.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-highlight.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-excerpt.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-reason.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-recover.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-status.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-rebuild.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-sync.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-log.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-clicks.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-suggest.php';
require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-wp.php';
require_once MVS_PLUGIN_DIR . 'includes/api.php';

register_activation_hook( __FILE__, static function () {
	MVS_DB::install();
	MVS_Log::schedule();

	// Build the index in the background; searches switch over when it is complete.
	if ( ! MVS_WP::ready() ) {
		MVS_Rebuild::start_background();
	}
} );

register_deactivation_hook( __FILE__, static function () {
	wp_clear_scheduled_hook( MVS_Log::PRUNE_HOOK );
	wp_clear_scheduled_hook( MVS_Sync::CRON_HOOK );
	wp_clear_scheduled_hook( MVS_Rebuild::BACKGROUND_HOOK );
} );

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'mavo-search', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	MVS_DB::maybe_upgrade();
	MVS_Sync::init();
	MVS_Rebuild::init();
	MVS_Log::init();
	MVS_Clicks::init();
	MVS_WP::init();

	if ( is_admin() ) {
		require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-admin.php';
		MVS_Admin::init();
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once MVS_PLUGIN_DIR . 'includes/class-mavo-search-cli.php';
		WP_CLI::add_command( 'mavo-search', 'MVS_CLI' );
	}
} );

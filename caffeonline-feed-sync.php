<?php
/**
 * Plugin Name: CaffeOnline Feed Sync
 * Plugin URI:  https://github.com/webjungle/caffeonline-feed-sync
 * Description: CSV-Feed (GTIN) → Woo SKU Matching. Batch-Sync (AJAX), Uploads-Cache und 3h-Cron für Lagerbestand.
 * Author: Webjungle
 * Version: 0.4.15
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: caffeonline-feed-sync
 * Update URI:  https://github.com/webjungle/caffeonline-feed-sync
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define('COFS_VERSION','0.4.15');
define( 'COFS_FILE', __FILE__ );
define( 'COFS_DIR', plugin_dir_path( __FILE__ ) );
define( 'COFS_URL', plugin_dir_url( __FILE__ ) );

$cofs_update_checker_file = COFS_DIR . 'includes/update-checker.php';
if ( file_exists( $cofs_update_checker_file ) ) {
    require_once $cofs_update_checker_file;

    if ( class_exists( 'COFS_Update_Checker', false ) ) {
        add_action(
            'plugins_loaded',
            function() {
                COFS_Update_Checker::init( COFS_FILE );
            },
            99
        );
    }
}

/**
 * Ensure clean JSON responses for our AJAX endpoints.
 *
 * Some admin-side code (other plugins/themes) may accidentally echo markup/styles
 * during admin-ajax requests (often on admin_init). That breaks JSON parsing in
 * the browser even though WordPress returns HTTP 200.
 *
 * We start an output buffer early for our own AJAX actions so we can reliably
 * discard any stray output right before sending JSON.
 */
add_action( 'plugins_loaded', function() {
    if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
        $action = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '';
        if ( $action && strpos( $action, 'cofs_' ) === 0 ) {
            // Start buffer only once.
            if ( ob_get_level() === 0 ) {
                ob_start();
            }
        }
    }
}, 0 );

require_once COFS_DIR . 'includes/class-cofs-admin.php';
require_once COFS_DIR . 'includes/class-cofs-feed.php';
require_once COFS_DIR . 'includes/class-cofs-sync.php';
require_once COFS_DIR . 'includes/class-cofs-cache.php';
require_once COFS_DIR . 'includes/class-cofs-price-log.php';
require_once COFS_DIR . 'includes/class-cofs-deleted-feed-items.php';
require_once COFS_DIR . 'includes/class-caffeonline-scraper.php';
require_once COFS_DIR . 'includes/class-cofs-scraper.php';
require_once COFS_DIR . 'includes/class-cofs-supplier-report.php';

add_action( 'plugins_loaded', function() {
    load_plugin_textdomain( 'caffeonline-feed-sync', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
});

add_action( 'init', function() {
    if ( class_exists( 'COFS_Price_Log' ) ) {
        COFS_Price_Log::maybe_install();
    }

    if ( class_exists( 'COFS_Deleted_Feed_Items' ) ) {
        COFS_Deleted_Feed_Items::init();
    }

    new COFS_Admin();

    if ( class_exists( 'COFS_Supplier_Report' ) ) {
        COFS_Supplier_Report::init();
        // Safety: ensure schedule exists even if activation hook was missed
        COFS_Supplier_Report::schedule();
    }
});

register_activation_hook( __FILE__, function() {
    if ( class_exists( 'COFS_Price_Log' ) ) {
        COFS_Price_Log::install();
    }

    if ( class_exists( 'COFS_Supplier_Report' ) ) {
        COFS_Supplier_Report::schedule();
    }
});

register_deactivation_hook( __FILE__, function() {
    if ( class_exists( 'COFS_Supplier_Report' ) ) {
        COFS_Supplier_Report::unschedule();
    }
});

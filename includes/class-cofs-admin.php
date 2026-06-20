<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Ensure AJAX scraper class is available (Missing Products → Importieren)
if ( ! class_exists( 'COFS_Scraper' ) && defined( 'COFS_DIR' ) ) {
    $scraper_file = trailingslashit( COFS_DIR ) . 'includes/class-cofs-scraper.php';
    if ( file_exists( $scraper_file ) ) {
        require_once $scraper_file;
    }
}


class COFS_Admin {
    const OPT_KEY = 'cofs_settings';
    private static $feed_index = null;

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Sync
        add_action( 'wp_ajax_cofs_prepare_feed', [ $this, 'ajax_prepare_feed' ] );
        add_action( 'wp_ajax_cofs_sync_step',    [ $this, 'ajax_sync_step' ] );

        // Produktliste: Feed Sync Spalte
        add_filter( 'manage_edit-product_columns', [ $this, 'add_product_feed_column' ], 25 );
        add_action( 'manage_product_posts_custom_column', [ $this, 'render_product_feed_column' ], 10, 2 );

        // Feed-Spalte sortierbar + Filter-Dropdown + Query-Anpassung
        add_filter( 'manage_edit-product_sortable_columns', [ $this, 'make_feed_column_sortable' ] );
        add_action( 'restrict_manage_posts', [ $this, 'add_feed_sync_filter_dropdown' ] );
        add_action( 'pre_get_posts', [ $this, 'handle_feed_sync_filter_and_sorting' ] );

        // Missing Products
        add_action( 'wp_ajax_cofs_missing_scan',       [ $this, 'ajax_missing_scan' ] );
        add_action( 'admin_post_cofs_export_missing',  [ $this, 'export_missing_csv' ] );

        // NEW: Scraper für fehlende Produkte (CSV → Shop)
        add_action( 'wp_ajax_cofs_scrape_product', [ $this, 'ajax_scrape_product' ] );        
    }

    public function enqueue_assets( $hook ) {
        // Styles/JS für COFS-Seiten
        if ( strpos( $hook, 'cofs' ) !== false ) {
            wp_enqueue_style( 'cofs-admin', COFS_URL . 'assets/admin.css', [], COFS_VERSION );
            wp_enqueue_script( 'cofs-admin', COFS_URL . 'assets/admin.js', [ 'jquery' ], COFS_VERSION, true );

            if ( function_exists( 'wc_get_product' ) ) {
                wp_enqueue_script( 'wc-enhanced-select' );
                wp_enqueue_script( 'wc-product-search' );
                wp_enqueue_style( 'woocommerce_admin_styles' );
            }

            wp_localize_script(
                'cofs-admin',
                'COFS',
                [
                    'ajax'       => admin_url( 'admin-ajax.php' ),
                    'nonce'      => wp_create_nonce( 'cofs_ajax' ),
                    // Für Scraper-UI: generische Texte
                    'scrape'     => [
                        'invalidUrl' => __( 'Bitte eine gültige caffeonline.ch Produkt-URL einfügen.', 'caffeonline-feed-sync' ),
                        'working'    => __( 'Lade Produktdaten & erstelle Produkt …', 'caffeonline-feed-sync' ),
                        'ok'         => __( 'Produkt wurde angelegt.', 'caffeonline-feed-sync' ),
                        'error'      => __( 'Fehler beim Scrapen der Produktseite.', 'caffeonline-feed-sync' ),
                    ],
                ]
            );
        }

        // Nur CSS auch in der Produktliste laden (für die Feed-Spalte)
        if ( 'edit.php' === $hook && isset( $_GET['post_type'] ) && 'product' === $_GET['post_type'] ) {
            wp_enqueue_style( 'cofs-admin', COFS_URL . 'assets/admin.css', [], COFS_VERSION );
        }
    }

    public function admin_menu() {
        add_menu_page(
            __( 'CaffeOnline Sync', 'caffeonline-feed-sync' ),
            __( 'CaffeOnline Sync', 'caffeonline-feed-sync' ),
            'manage_options',
            'cofs_dashboard',
            [ $this, 'render_page' ],
            'dashicons-update',
            56
        );

        add_submenu_page(
            'cofs_dashboard',
            __( 'Fehlende Produkte', 'caffeonline-feed-sync' ),
            __( 'Fehlende Produkte', 'caffeonline-feed-sync' ),
            'manage_options',
            'cofs_missing',
            [ $this, 'render_missing_page' ]
        );

        add_submenu_page(
            'cofs_dashboard',
            __( 'Supplier Sales', 'caffeonline-feed-sync' ),
            __( 'Supplier Sales', 'caffeonline-feed-sync' ),
            'manage_options',
            'cofs_supplier_sales',
            [ $this, 'render_supplier_sales_page' ]
        );

        add_submenu_page(
            'cofs_dashboard',
            __( 'Preisänderungen', 'caffeonline-feed-sync' ),
            __( 'Preisänderungen', 'caffeonline-feed-sync' ),
            'manage_options',
            'cofs_price_log',
            [ $this, 'render_price_log_page' ]
        );
    }

    /**
     * Supplier Sales (3h Compare)
     * Shows SKUs that were sold in WooCommerce but are missing in the supplier feed.
     */
    public function render_supplier_sales_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $notice = '';
        $result = [];

        if ( isset( $_POST['cofs_supplier_create_baseline'] ) && check_admin_referer( 'cofs_supplier_create_baseline' ) ) {
            if ( class_exists( 'COFS_Supplier_Report' ) ) {
                $result = COFS_Supplier_Report::create_baseline();
                $notice = __( 'Baseline snapshot saved.', 'caffeonline-feed-sync' );
            }
        }

        if ( isset( $_POST['cofs_supplier_force_run'] ) && check_admin_referer( 'cofs_supplier_force_run' ) ) {
            if ( class_exists( 'COFS_Supplier_Report' ) ) {
                $result = COFS_Supplier_Report::force_run_now();
                $notice = __( 'Stock delta job executed.', 'caffeonline-feed-sync' );
            }
        }

        if ( isset( $_POST['cofs_supplier_reset_totals'] ) && check_admin_referer( 'cofs_supplier_reset_totals' ) ) {
            if ( class_exists( 'COFS_Supplier_Report' ) ) {
                COFS_Supplier_Report::reset_totals();
                $notice = __( 'Totals reset.', 'caffeonline-feed-sync' );
            }
        }

        $meta    = class_exists( 'COFS_Supplier_Report' ) ? COFS_Supplier_Report::get_meta() : [];
        $totals  = class_exists( 'COFS_Supplier_Report' ) ? COFS_Supplier_Report::get_totals() : [];
        $missing = class_exists( 'COFS_Supplier_Report' ) ? COFS_Supplier_Report::get_missing_products_rows( 500 ) : [];
        $top     = class_exists( 'COFS_Supplier_Report' ) ? COFS_Supplier_Report::get_top_sold_rows( 100 ) : [];

        $baseline_at = isset( $meta['last_baseline_at'] ) ? (int) $meta['last_baseline_at'] : 0;
        $last_run_at = isset( $meta['last_run_at'] ) ? (int) $meta['last_run_at'] : 0;
        $last_seen   = isset( $meta['last_seen_count'] ) ? (int) $meta['last_seen_count'] : 0;
        $last_sum    = isset( $meta['last_delta_sum'] ) ? (int) $meta['last_delta_sum'] : 0;
        $stock_sync  = isset( $meta['last_stock_sync'] ) && is_array( $meta['last_stock_sync'] ) ? $meta['last_stock_sync'] : [];
        $stock_at    = isset( $meta['last_stock_sync_at'] ) ? (int) $meta['last_stock_sync_at'] : 0;
        $note        = isset( $meta['note'] ) ? (string) $meta['note'] : '';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Supplier Sales (Stock Delta)', 'caffeonline-feed-sync' ) . '</h1>';

        if ( $notice ) {
            echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
        }

        if ( $note ) {
            echo '<div class="notice notice-info"><p>' . esc_html( $note ) . '</p></div>';
        }

        echo '<p>' . esc_html__( 'Workflow: Save a baseline snapshot once, then the 3-hour cron compares supplier stock, stores decreases as “sold at supplier”, and updates WooCommerce stock plus purchase prices for matched SKUs.', 'caffeonline-feed-sync' ) . '</p>';

        echo '<p><strong>' . esc_html__( 'Baseline saved:', 'caffeonline-feed-sync' ) . '</strong> ' . ( $baseline_at ? esc_html( date_i18n( 'Y-m-d H:i:s', $baseline_at ) ) : '—' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Last run:', 'caffeonline-feed-sync' ) . '</strong> ' . ( $last_run_at ? esc_html( date_i18n( 'Y-m-d H:i:s', $last_run_at ) ) : '—' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Feed SKUs seen:', 'caffeonline-feed-sync' ) . '</strong> ' . esc_html( (string) $last_seen ) . ' &nbsp; | &nbsp; <strong>' . esc_html__( 'Last run sold (sum):', 'caffeonline-feed-sync' ) . '</strong> ' . esc_html( (string) $last_sum ) . '</p>';

        if ( ! empty( $stock_sync ) ) {
            $stock_changed   = isset( $stock_sync['stock_changed'] ) ? (int) $stock_sync['stock_changed'] : (int) ( $stock_sync['changed'] ?? 0 );
            $stock_unchanged = isset( $stock_sync['stock_unchanged'] ) ? (int) $stock_sync['stock_unchanged'] : (int) ( $stock_sync['unchanged'] ?? 0 );
            $stock_summary = sprintf(
                /* translators: 1: matched products, 2: changed stock rows, 3: unchanged stock rows, 4: changed purchase prices, 5: written price logs, 6: missing purchase prices, 7: skipped excluded products, 8: errors */
                __( 'Matched: %1$d, stock changed: %2$d, stock unchanged: %3$d, purchase changed: %4$d, price logs: %5$d, purchase missing: %6$d, excluded: %7$d, errors: %8$d', 'caffeonline-feed-sync' ),
                (int) ( $stock_sync['matched'] ?? 0 ),
                $stock_changed,
                $stock_unchanged,
                (int) ( $stock_sync['purchase_changed'] ?? 0 ),
                (int) ( $stock_sync['price_logs'] ?? 0 ),
                (int) ( $stock_sync['purchase_missing'] ?? 0 ),
                (int) ( $stock_sync['skipped_excluded'] ?? 0 ),
                (int) ( $stock_sync['errors'] ?? 0 )
            );
            echo '<p><strong>' . esc_html__( 'Last Woo stock/price sync:', 'caffeonline-feed-sync' ) . '</strong> ' . ( $stock_at ? esc_html( date_i18n( 'Y-m-d H:i:s', $stock_at ) ) : '—' ) . ' &nbsp; | &nbsp; ' . esc_html( $stock_summary ) . '</p>';
        }

        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin:12px 0;">';

        echo '<form method="post">';
        wp_nonce_field( 'cofs_supplier_create_baseline' );
        echo '<button class="button button-primary" name="cofs_supplier_create_baseline" value="1">' . esc_html__( 'Save baseline now', 'caffeonline-feed-sync' ) . '</button>';
        echo '</form>';

        echo '<form method="post">';
        wp_nonce_field( 'cofs_supplier_force_run' );
        echo '<button class="button button-secondary" name="cofs_supplier_force_run" value="1">' . esc_html__( 'Run compare now', 'caffeonline-feed-sync' ) . '</button>';
        echo '</form>';

        echo '<form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Reset all sold totals? This cannot be undone.', 'caffeonline-feed-sync' ) ) . '\');">';
        wp_nonce_field( 'cofs_supplier_reset_totals' );
        echo '<button class="button" name="cofs_supplier_reset_totals" value="1">' . esc_html__( 'Reset totals', 'caffeonline-feed-sync' ) . '</button>';
        echo '</form>';

        echo '</div>';

        echo '<hr />';

        echo '<h2>' . esc_html__( 'Top sold (Supplier)', 'caffeonline-feed-sync' ) . '</h2>';
        echo '<p>' . esc_html__( 'Accumulated from 3-hour stock decreases in the supplier feed.', 'caffeonline-feed-sync' ) . '</p>';

        if ( empty( $top ) ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'No sold totals yet. Save a baseline, then wait for the next 3-hour run (or click “Run compare now”).', 'caffeonline-feed-sync' ) . '</p></div>';
        } else {
            echo '<table class="widefat striped" style="max-width:1100px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'SKU (Feed / GTIN)', 'caffeonline-feed-sync' ) . '</th>';
            echo '<th>' . esc_html__( 'Vendor SKU', 'caffeonline-feed-sync' ) . '</th>';
            echo '<th>' . esc_html__( 'Total sold (supplier)', 'caffeonline-feed-sync' ) . '</th>';
            echo '<th>' . esc_html__( 'In shop?', 'caffeonline-feed-sync' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $top as $row ) {
                $in_shop = ! empty( $row['local_id'] );
                echo '<tr>';
                echo '<td><code>' . esc_html( (string) $row['sku'] ) . '</code></td>';
                echo '<td>' . ( $row['vendor_sku'] ? '<code>' . esc_html( (string) $row['vendor_sku'] ) . '</code>' : '—' ) . '</td>';
                echo '<td>' . esc_html( (string) (int) $row['sold'] ) . '</td>';
                echo '<td>' . ( $in_shop ? '✅' : '—' ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<hr />';

        echo '<h2>' . esc_html__( 'Fehlende Produkte', 'caffeonline-feed-sync' ) . '</h2>';
        echo '<p>' . esc_html__( 'SKUs with supplier-sales (stock decreases) that do not exist as products in your shop (by SKU).', 'caffeonline-feed-sync' ) . '</p>';

        if ( empty( $missing ) ) {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'No missing products found (yet).', 'caffeonline-feed-sync' ) . '</p></div>';
        } else {
            echo '<p><button type="button" class="button button-primary" id="cofs-bulk-import">' . esc_html__( 'Bulk import all with URL', 'caffeonline-feed-sync' ) . '</button> <span style="margin-left:8px;color:#666;">' . esc_html__( 'Imports rows where a caffeonline.ch URL is filled in.', 'caffeonline-feed-sync' ) . '</span></p>';
            echo '<table class="widefat striped" id="cofs-supplier-missing-table" style="max-width:1100px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__( 'SKU (Feed / GTIN)', 'caffeonline-feed-sync' ) . '</th>';
            echo '<th>' . esc_html__( 'Vendor SKU', 'caffeonline-feed-sync' ) . '</th>';
            echo '<th>' . esc_html__( 'Total sold (supplier)', 'caffeonline-feed-sync' ) . '</th>';
            echo '<th>' . esc_html__( 'Current stock', 'caffeonline-feed-sync' ) . '</th>';
            echo '<th>' . esc_html__( 'Purchase price (feed)', 'caffeonline-feed-sync' ) . '</th>';
            echo '<th>' . esc_html__( 'caffeonline.ch URL', 'caffeonline-feed-sync' ) . '</th>';
            echo '<th>' . esc_html__( 'Import', 'caffeonline-feed-sync' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $missing as $row ) {
                $key        = (string) ( $row['sku'] ?? '' );
                $vendor_sku = (string) ( $row['vendor_sku'] ?? '' );
                $stock      = (int) ( $row['stock'] ?? 0 );
                $purchase   = (string) ( $row['purchase_price'] ?? '' );

                echo '<tr data-key="' . esc_attr( $key ) . '" data-vendor-sku="' . esc_attr( $vendor_sku ) . '" data-stock="' . esc_attr( (string) $stock ) . '" data-purchase-price="' . esc_attr( $purchase ) . '">';
                echo '<td><code>' . esc_html( $key ) . '</code></td>';
                echo '<td>' . ( $vendor_sku ? '<code>' . esc_html( $vendor_sku ) . '</code>' : '—' ) . '</td>';
                echo '<td>' . esc_html( (string) (int) ( $row['sold'] ?? 0 ) ) . '</td>';
                echo '<td>' . esc_html( (string) $stock ) . '</td>';
                echo '<td>' . ( $purchase !== '' ? esc_html( $purchase ) : '—' ) . '</td>';

                echo '<td><input type="url" class="regular-text cofs-source-url" placeholder="https://caffeonline.ch/produkt/..." /></td>';
                echo '<td>';
                echo '  <button type="button" class="button button-small cofs-scrape-btn">' . esc_html__( 'Import', 'caffeonline-feed-sync' ) . '</button>';
                echo '  <div class="cofs-scrape-status" style="margin-top:6px;font-size:12px;"></div>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div>';
    }

    public function render_price_log_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $limit = isset( $_GET['cofs_limit'] ) ? intval( $_GET['cofs_limit'] ) : 200;
        $limit = max( 20, min( 1000, $limit ) );

        $rows = class_exists( 'COFS_Price_Log' ) ? COFS_Price_Log::get_recent( $limit ) : [];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Preisänderungen (Einkaufspreis)', 'caffeonline-feed-sync' ) . '</h1>';
        echo '<p>' . esc_html__( 'Jede Änderung von _purchase_price wird beim manuellen Sync und beim 3h-Lieferanten-Cron in einer Log-Tabelle gespeichert.', 'caffeonline-feed-sync' ) . '</p>';

        echo '<form method="get" style="margin:12px 0;">';
        echo '<input type="hidden" name="page" value="cofs_price_log" />';
        echo '<label>' . esc_html__( 'Anzeigen:', 'caffeonline-feed-sync' ) . ' <input type="number" class="small-text" min="20" max="1000" step="10" name="cofs_limit" value="' . esc_attr( (string) $limit ) . '" /> ' . esc_html__( 'Einträge', 'caffeonline-feed-sync' ) . '</label> ';
        submit_button( __( 'Aktualisieren', 'caffeonline-feed-sync' ), 'secondary', '', false );
        echo '</form>';

        if ( empty( $rows ) ) {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'Noch keine Preisänderungen geloggt.', 'caffeonline-feed-sync' ) . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:1200px;">';
        echo '<thead><tr>';
        echo '<th style="width:16%">' . esc_html__( 'Zeitpunkt', 'caffeonline-feed-sync' ) . '</th>';
        echo '<th style="width:28%">' . esc_html__( 'Produkt', 'caffeonline-feed-sync' ) . '</th>';
        echo '<th style="width:14%">' . esc_html__( 'Feed / SKU', 'caffeonline-feed-sync' ) . '</th>';
        echo '<th style="width:12%">' . esc_html__( 'Vendor SKU', 'caffeonline-feed-sync' ) . '</th>';
        echo '<th style="width:9%">' . esc_html__( 'Alt', 'caffeonline-feed-sync' ) . '</th>';
        echo '<th style="width:9%">' . esc_html__( 'Neu', 'caffeonline-feed-sync' ) . '</th>';
        echo '<th style="width:10%">' . esc_html__( 'Differenz', 'caffeonline-feed-sync' ) . '</th>';
        echo '<th style="width:10%">' . esc_html__( 'Quelle', 'caffeonline-feed-sync' ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            $product_id   = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
            $product_name = isset( $row['product_name'] ) ? (string) $row['product_name'] : '';
            $product_sku  = isset( $row['product_sku'] ) ? (string) $row['product_sku'] : '';
            $feed_sku     = isset( $row['feed_sku'] ) ? (string) $row['feed_sku'] : '';
            $vendor_sku   = isset( $row['vendor_sku'] ) ? (string) $row['vendor_sku'] : '';
            $old_price    = isset( $row['old_purchase_price'] ) ? (string) $row['old_purchase_price'] : '';
            $new_price    = isset( $row['new_purchase_price'] ) ? (string) $row['new_purchase_price'] : '';
            $source       = isset( $row['source'] ) ? (string) $row['source'] : 'sync';
            $changed_raw  = isset( $row['changed_at_gmt'] ) ? (string) $row['changed_at_gmt'] : '';

            if ( '' === $product_name && $product_id > 0 ) {
                $title = get_the_title( $product_id );
                if ( is_string( $title ) && $title !== '' ) {
                    $product_name = $title;
                }
            }

            $product_label = $product_name !== '' ? $product_name : __( '(ohne Titel)', 'caffeonline-feed-sync' );
            $edit_url      = $product_id > 0 ? get_edit_post_link( $product_id ) : '';

            $changed_ts = $changed_raw !== '' ? strtotime( $changed_raw . ' UTC' ) : false;
            $changed    = $changed_ts ? date_i18n( 'Y-m-d H:i:s', $changed_ts ) : $changed_raw;

            $sku_cell = $feed_sku !== '' ? $feed_sku : $product_sku;
            $diff_cell = '—';
            $diff_style = '';
            if ( is_numeric( $old_price ) && is_numeric( $new_price ) ) {
                $old_num = (float) $old_price;
                $new_num = (float) $new_price;
                $diff    = $new_num - $old_num;
                $pct     = $old_num > 0 ? ( $diff / $old_num ) * 100 : null;
                $sign    = $diff > 0 ? '+' : '';
                $diff_cell = $sign . wc_format_decimal( $diff, 2 );
                if ( null !== $pct ) {
                    $diff_cell .= ' (' . $sign . wc_format_decimal( $pct, 1 ) . '%)';
                    if ( abs( $pct ) >= 10 ) {
                        $diff_style = 'font-weight:700;color:' . ( $diff > 0 ? '#b32d2e' : '#008a20' ) . ';';
                    }
                }
            }

            echo '<tr>';
            echo '<td>' . esc_html( $changed !== '' ? $changed : '—' ) . '</td>';
            echo '<td>';
            if ( $edit_url ) {
                echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $product_label ) . '</a>';
            } else {
                echo esc_html( $product_label );
            }
            if ( $product_id > 0 ) {
                echo '<br><code>#' . esc_html( (string) $product_id ) . '</code>';
            }
            echo '</td>';
            echo '<td>' . ( $sku_cell !== '' ? '<code>' . esc_html( $sku_cell ) . '</code>' : '—' ) . '</td>';
            echo '<td>' . ( $vendor_sku !== '' ? '<code>' . esc_html( $vendor_sku ) . '</code>' : '—' ) . '</td>';
            echo '<td>' . esc_html( $old_price !== '' ? $old_price : '—' ) . '</td>';
            echo '<td><strong>' . esc_html( $new_price !== '' ? $new_price : '—' ) . '</strong></td>';
            echo '<td' . ( $diff_style ? ' style="' . esc_attr( $diff_style ) . '"' : '' ) . '>' . esc_html( $diff_cell ) . '</td>';
            echo '<td><code>' . esc_html( $source ) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function register_settings() {
        register_setting( self::OPT_KEY, self::OPT_KEY, [ $this, 'sanitize_settings' ] );

        add_settings_section(
            'cofs_main',
            __( 'Allgemein', 'caffeonline-feed-sync' ),
            function(){
                echo '<p>' . esc_html__( 'Grundlegende Einstellungen zum CSV-Feed und der Synchronisation.', 'caffeonline-feed-sync' ) . '</p>';
            },
            'cofs_settings'
        );

        add_settings_field(
            'feed_url',
            __( 'CSV Feed URL', 'caffeonline-feed-sync' ),
            [ $this, 'field_feed_url' ],
            'cofs_settings',
            'cofs_main'
        );

        add_settings_field(
            'batch_size',
            __( 'Batch-Größe (pro Schritt)', 'caffeonline-feed-sync' ),
            [ $this, 'field_batch_size' ],
            'cofs_settings',
            'cofs_main'
        );

        add_settings_field(
            'cache_ttl',
            __( 'Cache TTL (Minuten)', 'caffeonline-feed-sync' ),
            [ $this, 'field_cache_ttl' ],
            'cofs_settings',
            'cofs_main'
        );

        add_settings_field(
            'max_rows',
            __( 'Max. Zeilen (0 = alle)', 'caffeonline-feed-sync' ),
            [ $this, 'field_max_rows' ],
            'cofs_settings',
            'cofs_main'
        );

        // Produkte vom Sync ausschliessen
        add_settings_field(
            'excluded_product_ids',
            __( 'Produkte vom Sync ausschliessen', 'caffeonline-feed-sync' ),
            [ $this, 'field_excluded_products' ],
            'cofs_settings',
            'cofs_main'
        );
    }

    public function sanitize_settings( $input ) {
        $out = get_option( self::OPT_KEY, [] );

        $out['feed_url']   = isset( $input['feed_url'] ) ? esc_url_raw( trim( $input['feed_url'] ) ) : '';
        $out['batch_size'] = max( 1, intval( $input['batch_size'] ?? 50 ) );
        $out['cache_ttl']  = max( 1, intval( $input['cache_ttl'] ?? 60 ) );
        $out['max_rows']   = max( 0, intval( $input['max_rows'] ?? 0 ) );

        // Ausschlussliste normalisieren
        if ( isset( $input['excluded_product_ids'] ) && is_array( $input['excluded_product_ids'] ) ) {
            $ids = array_map( 'absint', $input['excluded_product_ids'] );
            $ids = array_values( array_unique( array_filter( $ids ) ) );
            $out['excluded_product_ids'] = $ids;
        } else {
            $out['excluded_product_ids'] = isset( $out['excluded_product_ids'] ) && is_array( $out['excluded_product_ids'] )
                ? array_values( array_unique( array_filter( array_map( 'absint', $out['excluded_product_ids'] ) ) ) )
                : [];
        }

        return $out;
    }

    // --------------------------------------------------
    // Feed-Index (für Spalte "Feed Sync")
    // --------------------------------------------------
    private static function get_feed_index() {
        if ( null !== self::$feed_index ) {
            return self::$feed_index;
        }

        $opts     = get_option( self::OPT_KEY, [] );
        $feed_url = $opts['feed_url'] ?? '';
        $ttl      = max( 1, intval( $opts['cache_ttl'] ?? 60 ) );
        $max_rows = max( 0, intval( $opts['max_rows'] ?? 0 ) );

        if ( empty( $feed_url ) || ! class_exists( 'COFS_Cache' ) ) {
            self::$feed_index = [];
            return self::$feed_index;
        }

        $cache = new COFS_Cache( $feed_url, $ttl, $max_rows );
        $meta  = method_exists( $cache, 'meta' ) ? $cache->meta() : false;

        // Kein Cache vorhanden → false (eigenes Signal)
        if ( ! $meta || empty( $meta['path'] ) || ! file_exists( $meta['path'] ) ) {
            self::$feed_index = false;
            return self::$feed_index;
        }

        $index  = [];
        $offset = 0;
        $limit  = 500;

        while ( true ) {
            if ( ! method_exists( $cache, 'read_slice' ) ) {
                break;
            }

            $slice = $cache->read_slice( $offset, $limit );
            if ( is_wp_error( $slice ) ) {
                $index = [];
                break;
            }

            $rows  = isset( $slice['rows'] ) ? (array) $slice['rows'] : [];
            $total = isset( $slice['total'] ) ? (int) $slice['total'] : 0;
            $next  = isset( $slice['next_offset'] ) ? (int) $slice['next_offset'] : ( $offset + $limit );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                foreach ( [ 'GTIN','gtin','EAN','ean','sku','SKU' ] as $key ) {
                    if ( isset( $row[ $key ] ) && $row[ $key ] !== '' ) {
                        $val = (string) $row[ $key ];
                        $index[ $val ] = true;
                        break;
                    }
                }
            }

            if ( $total && $next >= $total ) {
                break;
            }
            if ( $next <= $offset ) {
                break;
            }

            $offset = $next;
        }

        self::$feed_index = $index;
        return self::$feed_index;
    }

    // --------------------------------------------------
    // Settings-Fields
    // --------------------------------------------------
    public function field_feed_url() {
        $opts = get_option( self::OPT_KEY, [] );
        $val  = $opts['feed_url'] ?? '';
        echo '<input type="url" class="regular-text ltr" name="' . esc_attr( self::OPT_KEY ) . '[feed_url]" value="' . esc_attr( $val ) . '" placeholder="https://example.com/feed.csv" />';
    }

    public function field_batch_size() {
        $opts = get_option( self::OPT_KEY, [] );
        $val  = $opts['batch_size'] ?? 50;
        echo '<input type="number" min="1" step="1" class="small-text" name="' . esc_attr( self::OPT_KEY ) . '[batch_size]" value="' . esc_attr( $val ) . '" />';
    }

    public function field_cache_ttl() {
        $opts = get_option( self::OPT_KEY, [] );
        $val  = $opts['cache_ttl'] ?? 60;
        echo '<input type="number" min="1" step="1" class="small-text" name="' . esc_attr( self::OPT_KEY ) . '[cache_ttl]" value="' . esc_attr( $val ) . '" />';
    }

    public function field_max_rows() {
        $opts = get_option( self::OPT_KEY, [] );
        $val  = $opts['max_rows'] ?? 0;
        echo '<input type="number" min="0" step="1" class="small-text" name="' . esc_attr( self::OPT_KEY ) . '[max_rows]" value="' . esc_attr( $val ) . '" />';
        echo '<p class="description">' . esc_html__( 'Wichtig: „Feed vorbereiten“ mit aktivierter Option „Neu laden erzwingen“, damit die Begrenzung sofort greift.', 'caffeonline-feed-sync' ) . '</p>';
    }

    public function field_excluded_products() {
        if ( ! function_exists( 'wc_get_product' ) ) {
            echo '<em>' . esc_html__( 'WooCommerce ist erforderlich, um Produkte auszuwählen.', 'caffeonline-feed-sync' ) . '</em>';
            return;
        }

        $opts     = get_option( self::OPT_KEY, [] );
        $selected = isset( $opts['excluded_product_ids'] ) && is_array( $opts['excluded_product_ids'] )
            ? array_map( 'absint', $opts['excluded_product_ids'] )
            : [];

        echo '<select
                id="cofs-excluded-products"
                class="wc-product-search"
                multiple="multiple"
                style="width: 100%;"
                name="' . esc_attr( self::OPT_KEY ) . '[excluded_product_ids][]"
                data-placeholder="' . esc_attr__( 'Nach Produktname oder SKU suchen …', 'caffeonline-feed-sync' ) . '"
                data-action="woocommerce_json_search_products_and_variations">';

        if ( ! empty( $selected ) ) {
            foreach ( $selected as $pid ) {
                $product = wc_get_product( $pid );
                if ( $product ) {
                    echo '<option value="' . esc_attr( $pid ) . '" selected>' . wp_kses_post( $product->get_formatted_name() ) . '</option>';
                }
            }
        }

        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Ausgewählte Produkte werden im Sync komplett übersprungen.', 'caffeonline-feed-sync' ) . '</p>';
    }

    // --------------------------------------------------
    // Hauptseite: Settings + Sync
    // --------------------------------------------------
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'caffeonline-feed-sync' ) );
        }

        echo '<div class="wrap cofs-wrap">';
        echo '<h1>' . esc_html__( 'CaffeOnline Feed Sync', 'caffeonline-feed-sync' ) . '</h1>';

        // Einstellungen
        echo '<form method="post" action="options.php" class="cofs-settings">';
        settings_fields( self::OPT_KEY );
        do_settings_sections( 'cofs_settings' );
        submit_button( __( 'Speichern', 'caffeonline-feed-sync' ) );
        echo '</form>';

        // Sync
        echo '<div class="cofs-box">';
        echo '<h2>' . esc_html__( 'Sync (Batch)', 'caffeonline-feed-sync' ) . '</h2>';
        echo '<p>' . esc_html__( '„Sync starten“ führt automatisch zuerst „Feed vorbereiten“ aus (inkl. Max-Zeilen). Mit „Neu laden erzwingen“ wird der Cache davor komplett neu erstellt.', 'caffeonline-feed-sync' ) . '</p>';

        echo '<div id="cofs-controls">';
        echo '  <button class="button" id="cofs-prepare" type="button">' . esc_html__( 'Feed vorbereiten', 'caffeonline-feed-sync' ) . '</button> ';
        echo '  <label style="margin-left:8px;"><input type="checkbox" id="cofs-force" /> ' . esc_html__( 'Neu laden erzwingen', 'caffeonline-feed-sync' ) . '</label> ';
        echo '  <button class="button button-primary" id="cofs-run" type="button">' . esc_html__( 'Sync starten', 'caffeonline-feed-sync' ) . '</button> ';
        echo '  <button class="button" id="cofs-cancel" type="button" disabled>' . esc_html__( 'Abbrechen', 'caffeonline-feed-sync' ) . '</button>';
        echo '</div>';

        echo '<div class="cofs-progress"><div class="bar"></div></div>';
        echo '<div id="cofs-status" class="cofs-status"></div>';

        echo '<table class="widefat striped" id="cofs-report">';
        echo '  <thead><tr>';
        echo '    <th style="width:35%">' . esc_html__( 'Produkt / Admin', 'caffeonline-feed-sync' ) . '</th>';
        echo '    <th>' . esc_html__( 'Änderungen', 'caffeonline-feed-sync' ) . '</th>';
        echo '  </tr></thead>';
        echo '  <tbody></tbody>';
        echo '</table>';
        echo '</div>';

        echo '</div>'; // .wrap
    }

    // --------------------------------------------------
    // Ajax-Helper
    // --------------------------------------------------
    private function verify_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) {
            $this->send_json_error( [ 'message' => 'forbidden' ], 403 );
        }
        if ( ! check_ajax_referer( 'cofs_ajax', 'nonce', false ) ) {
            $this->send_json_error( [ 'message' => 'bad nonce' ], 400 );
        }
    }

    /**
     * Some plugins/themes accidentally output HTML/CSS during admin-ajax requests
     * (often on admin_init), which breaks JSON parsing in the browser.
     *
     * We start an output buffer early (see main plugin file) and then discard
     * everything right before returning JSON.
     */
    private function clean_ajax_output() {
        // Drain ALL buffers to guarantee a clean JSON response.
        while ( ob_get_level() > 0 ) {
            @ob_end_clean();
        }
    }

    private function send_json_success( $data = null, $status_code = null ) {
        $this->clean_ajax_output();
        if ( null !== $status_code ) {
            wp_send_json_success( $data, (int) $status_code );
        }
        wp_send_json_success( $data );
    }

    private function send_json_error( $data = null, $status_code = null ) {
        $this->clean_ajax_output();
        if ( null !== $status_code ) {
            wp_send_json_error( $data, (int) $status_code );
        }
        wp_send_json_error( $data );
    }

    // --------------------------------------------------
    // Feed vorbereiten / Sync (AJAX)
    // --------------------------------------------------
    public function ajax_prepare_feed() {
        $this->verify_ajax();

        $opts     = get_option( self::OPT_KEY, [] );
        $feed_url = $opts['feed_url'] ?? '';
        $ttl      = max( 1, intval( $opts['cache_ttl'] ?? 60 ) );
        $max_rows = max( 0, intval( $opts['max_rows'] ?? 0 ) );
        $force    = ! empty( $_POST['force'] );

        if ( empty( $feed_url ) ) {
            $this->send_json_error( [ 'message' => __( 'Keine Feed-URL gesetzt.', 'caffeonline-feed-sync' ) ] );
        }

        $cache = new COFS_Cache( $feed_url, $ttl, $max_rows );
        $meta  = $cache->prepare( $force );
        if ( is_wp_error( $meta ) ) {
            $this->send_json_error( [ 'message' => $meta->get_error_message() ] );
        }

        $this->send_json_success( $meta );
    }

    public function ajax_sync_step() {
        $this->verify_ajax();

        $opts      = get_option( self::OPT_KEY, [] );
        $feed_url  = $opts['feed_url'] ?? '';
        $batch     = max( 1, intval( $opts['batch_size'] ?? 50 ) );
        $offset    = max( 0, intval( $_POST['offset'] ?? 0 ) );

        $cache = new COFS_Cache(
            $feed_url,
            max( 1, intval( $opts['cache_ttl'] ?? 60 ) ),
            max( 0, intval( $opts['max_rows'] ?? 0 ) )
        );
        $slice = $cache->read_slice( $offset, $batch );
        if ( is_wp_error( $slice ) ) {
            $this->send_json_error( [ 'message' => $slice->get_error_message() ] );
        }

        $feed   = new COFS_Feed( $feed_url );
        $sync   = new COFS_Sync( $feed );
        $report = $sync->apply( $slice['rows'] );

        $this->send_json_success( [
            'offset'   => $offset,
            'next'     => $slice['next_offset'],
            'total'    => $slice['total'],
            'finished' => $slice['next_offset'] >= $slice['total'],
            'count'    => $report['count'],
            'changes'  => $report['changes'],
        ] );
    }

    // --------------------------------------------------
    // Produktliste: Feed Sync Spalte (Render)
    // --------------------------------------------------
    public function add_product_feed_column( $columns ) {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'sku' === $key ) {
                $new['cofs_feed'] = __( 'Feed Sync', 'caffeonline-feed-sync' );
            }
        }
        if ( ! isset( $new['cofs_feed'] ) ) {
            $new['cofs_feed'] = __( 'Feed Sync', 'caffeonline-feed-sync' );
        }
        return $new;
    }

    public function render_product_feed_column( $column, $post_id ) {
        if ( 'cofs_feed' !== $column ) {
            return;
        }

        $opts = get_option( self::OPT_KEY, [] );
        $excluded_ids = ! empty( $opts['excluded_product_ids'] ) && is_array( $opts['excluded_product_ids'] )
            ? array_map( 'intval', $opts['excluded_product_ids'] )
            : [];

        $index = self::get_feed_index();
        if ( $index === false ) {
            echo '<span class="cofs-feed-status cofs-feed-nocache" title="' . esc_attr__( 'Kein vorbereiteter Feed-Cache – bitte im CaffeOnline Feed Sync zuerst „Feed vorbereiten“ ausführen.', 'caffeonline-feed-sync' ) . '">?</span>';
            return;
        }

        if ( in_array( (int) $post_id, $excluded_ids, true ) ) {
            echo '<span class="cofs-feed-status cofs-feed-excluded" title="' . esc_attr__( 'Dieses Produkt ist in den Feed-Sync-Einstellungen ausgeschlossen.', 'caffeonline-feed-sync' ) . '">⛔</span>';
            return;
        }

        $sku = get_post_meta( $post_id, '_sku', true );
        if ( '' === $sku ) {
            echo '<span class="cofs-feed-status cofs-feed-nosku" title="' . esc_attr__( 'Keine SKU hinterlegt – kein Match möglich.', 'caffeonline-feed-sync' ) . '">—</span>';
            return;
        }

        if ( isset( $index[ $sku ] ) ) {
            echo '<span class="cofs-feed-status cofs-feed-ok" title="' . esc_attr__( 'Im CaffeOnline-Feed gefunden – wird vom Sync berücksichtigt.', 'caffeonline-feed-sync' ) . '">✓</span>';
        } else {
            echo '<span class="cofs-feed-status cofs-feed-missing" title="' . esc_attr__( 'Nicht im CaffeOnline-Feed gefunden.', 'caffeonline-feed-sync' ) . '">–</span>';
        }
    }

    // --------------------------------------------------
    // Produktliste: Sortierung & Filter für Feed Sync
    // --------------------------------------------------
    public function make_feed_column_sortable( $columns ) {
        $columns['cofs_feed'] = 'cofs_feed';
        return $columns;
    }

    public function add_feed_sync_filter_dropdown( $post_type ) {
        if ( 'product' !== $post_type ) {
            return;
        }

        $current = isset( $_GET['cofs_feed_status'] )
            ? sanitize_text_field( wp_unslash( $_GET['cofs_feed_status'] ) )
            : '';
        ?>
        <select name="cofs_feed_status">
            <option value=""><?php esc_html_e( 'Feed Sync – alle Stati', 'caffeonline-feed-sync' ); ?></option>
            <option value="in_feed" <?php selected( $current, 'in_feed' ); ?>>
                <?php esc_html_e( 'Im Feed (✓)', 'caffeonline-feed-sync' ); ?>
            </option>
            <option value="not_in_feed" <?php selected( $current, 'not_in_feed' ); ?>>
                <?php esc_html_e( 'Nicht im Feed (–)', 'caffeonline-feed-sync' ); ?>
            </option>
            <option value="no_sku" <?php selected( $current, 'no_sku' ); ?>>
                <?php esc_html_e( 'Ohne SKU (—)', 'caffeonline-feed-sync' ); ?>
            </option>
            <option value="excluded" <?php selected( $current, 'excluded' ); ?>>
                <?php esc_html_e( 'Ausgeschlossen (⛔)', 'caffeonline-feed-sync' ); ?>
            </option>
        </select>
        <?php
    }

    public function handle_feed_sync_filter_and_sorting( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'edit-product' !== $screen->id ) {
            return;
        }

        $feed_status = isset( $_GET['cofs_feed_status'] )
            ? sanitize_text_field( wp_unslash( $_GET['cofs_feed_status'] ) )
            : '';

        $opts = get_option( self::OPT_KEY, [] );
        $excluded_ids = ! empty( $opts['excluded_product_ids'] ) && is_array( $opts['excluded_product_ids'] )
            ? array_map( 'intval', $opts['excluded_product_ids'] )
            : [];

        // Sortierung nach Spalte "Feed Sync" → sortiere nach _vendor_sku (als Proxy)
        if ( 'cofs_feed' === $query->get( 'orderby' ) ) {
            $query->set( 'meta_key', '_vendor_sku' );
            $query->set( 'orderby', 'meta_value' );
        }

        if ( '' === $feed_status ) {
            return;
        }

        $meta_query = (array) $query->get( 'meta_query' );
        $index      = self::get_feed_index();

        switch ( $feed_status ) {
            case 'excluded':
                if ( ! empty( $excluded_ids ) ) {
                    $query->set( 'post__in', $excluded_ids );
                } else {
                    $query->set( 'post__in', [ 0 ] );
                }
                break;

            case 'no_sku':
                $meta_query[] = [
                    'key'     => '_sku',
                    'compare' => '=',
                    'value'   => '',
                ];
                break;

            case 'in_feed':
            case 'not_in_feed':
                if ( is_array( $index ) && ! empty( $index ) ) {
                    $ids = array_values( $this->get_existing_product_ids_by_skus( array_keys( $index ) ) );

                    if ( 'in_feed' === $feed_status ) {
                        if ( ! empty( $ids ) ) {
                            $query->set( 'post__in', array_map( 'intval', $ids ) );
                        } else {
                            $query->set( 'post__in', [ 0 ] );
                        }
                    } else { // not_in_feed
                        if ( ! empty( $ids ) ) {
                            $query->set( 'post__not_in', array_map( 'intval', $ids ) );
                        }
                    }
                }
                break;
        }

        if ( ! empty( $meta_query ) ) {
            $query->set( 'meta_query', $meta_query );
        }
    }

    private function get_feed_match_key( COFS_Feed $feed, array $row ) : string {
        return trim( (string) $feed->col( $row, [
            'GTIN', 'gtin',
            'EAN', 'ean',
            'Key(GTIN/EAN/SKU)',
            'Key', 'key',
            'SKU', 'sku',
        ] ) );
    }

    private function get_feed_vendor_sku( COFS_Feed $feed, array $row ) : string {
        return trim( (string) $feed->col( $row, [
            'Vendor SKU', 'vendor_sku', 'VendorSKU', 'VENDOR_SKU', 'vendor sku',
            'Lieferant SKU', 'lieferant_sku', 'LieferantSKU', 'lieferant sku',
            'Supplier SKU', 'supplier_sku', 'supplier sku',
            'Hersteller SKU', 'HerstellerSKU', 'Hersteller ArtNr', 'Hersteller-Nummer',
            'Artikelnummer', 'Artikel-Nr', 'Art.-Nr.', 'ArtNr', 'Artikel Nr',
            'SKU', 'sku',
        ] ) );
    }

    private function normalize_feed_decimal( $value ) : string {
        if ( $value === '' || $value === null ) {
            return '';
        }

        $normalized = wc_format_decimal( (string) $value );
        return $normalized === '' ? '' : (string) $normalized;
    }

    private function get_feed_purchase_price( COFS_Feed $feed, array $row ) : string {
        return $this->normalize_feed_decimal(
            $feed->col( $row, [ 'Purchase Price', 'purchase_price', 'Einkaufspreis', 'EK', 'Cost', 'cost' ] )
        );
    }

    private function get_feed_uvp( COFS_Feed $feed, array $row ) : string {
        return $this->normalize_feed_decimal(
            $feed->col( $row, [ 'UVP', 'uvp', 'Uvp', 'RRP', 'rrp', 'MSRP', 'msrp', 'Regular Price', 'regular_price', 'List Price', 'list_price', 'Verkaufspreis', 'verkaufspreis' ] )
        );
    }

    private function get_supplier_sales_map() : array {
        if ( ! class_exists( 'COFS_Supplier_Report' ) ) {
            return [];
        }

        $totals = COFS_Supplier_Report::get_totals();
        if ( ! is_array( $totals ) ) {
            return [];
        }

        $sales_map = [];
        foreach ( $totals as $feed_sku => $data ) {
            $sales_map[ (string) $feed_sku ] = max( 0, (int) ( $data['sold'] ?? 0 ) );
        }

        return $sales_map;
    }

    private function get_existing_product_ids_by_skus( array $skus ) : array {
        global $wpdb;

        $clean = [];
        foreach ( $skus as $sku ) {
            $sku = trim( (string) $sku );
            if ( '' !== $sku ) {
                $clean[ $sku ] = true;
            }
        }

        if ( empty( $clean ) || ! $wpdb ) {
            return [];
        }

        $map = [];
        foreach ( array_chunk( array_keys( $clean ), 500 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $sql = "
                SELECT pm.meta_value AS sku, pm.post_id AS post_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = '_sku'
                  AND pm.meta_value IN ($placeholders)
                  AND p.post_type IN ('product', 'product_variation')
                  AND p.post_status NOT IN ('trash', 'auto-draft')
                ORDER BY CASE WHEN p.post_type = 'product' THEN 0 ELSE 1 END, pm.post_id ASC
            ";

            $rows = $wpdb->get_results( $wpdb->prepare( $sql, $chunk ), ARRAY_A );
            if ( ! is_array( $rows ) ) {
                continue;
            }

            foreach ( $rows as $row ) {
                $sku = isset( $row['sku'] ) ? (string) $row['sku'] : '';
                if ( '' === $sku || isset( $map[ $sku ] ) ) {
                    continue;
                }
                $map[ $sku ] = (int) $row['post_id'];
            }
        }

        return $map;
    }

    private function get_margin_data( string $purchase, string $uvp ) : array {
        if ( $purchase === '' || $uvp === '' ) {
            return [
                'value'   => '',
                'percent' => '',
            ];
        }

        $purchase_float = (float) $purchase;
        $uvp_float      = (float) $uvp;

        if ( $uvp_float <= 0 ) {
            return [
                'value'   => '',
                'percent' => '',
            ];
        }

        $margin_value = wc_format_decimal( $uvp_float - $purchase_float );
        $margin_pct   = wc_format_decimal( ( ( $uvp_float - $purchase_float ) / $uvp_float ) * 100, 2 );

        return [
            'value'   => (string) $margin_value,
            'percent' => (string) $margin_pct,
        ];
    }

    private function get_missing_feed_dataset( bool $force = false ) {
        $opts     = get_option( self::OPT_KEY, [] );
        $feed_url = $opts['feed_url'] ?? '';
        $ttl      = max( 1, intval( $opts['cache_ttl'] ?? 60 ) );

        if ( empty( $feed_url ) ) {
            return new WP_Error( 'cofs_missing_feed_url', __( 'Keine Feed-URL gesetzt.', 'caffeonline-feed-sync' ) );
        }

        $feed = new COFS_Feed( $feed_url );

        if ( class_exists( 'COFS_Cache' ) ) {
            // Missing Products should always evaluate the full feed.
            $cache = new COFS_Cache( $feed_url, $ttl, 0 );
            $meta  = $cache->prepare( $force );

            if ( is_wp_error( $meta ) ) {
                return $meta;
            }

            $total = isset( $meta['total'] ) ? max( 0, (int) $meta['total'] ) : 0;
            $rows  = [];

            if ( $total > 0 ) {
                $slice = $cache->read_slice( 0, $total );
                if ( is_wp_error( $slice ) ) {
                    return $slice;
                }

                $rows = isset( $slice['rows'] ) && is_array( $slice['rows'] )
                    ? $slice['rows']
                    : [];
            }

            return [
                'feed'       => $feed,
                'rows'       => $rows,
                'cache_meta' => $meta,
                'cache_used' => true,
            ];
        }

        $rows = $feed->get_rows();
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }

        return [
            'feed'       => $feed,
            'rows'       => is_array( $rows ) ? $rows : [],
            'cache_meta' => null,
            'cache_used' => false,
        ];
    }

    private function collect_missing_feed_rows( COFS_Feed $feed, array $rows, int $stock_min = 0 ) : array {
        $supplier_sales = $this->get_supplier_sales_map();
        $entries        = [];
        $keys           = [];
        $missing        = [];

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            if ( class_exists( 'COFS_Deleted_Feed_Items' ) && COFS_Deleted_Feed_Items::is_feed_row_blocked( $feed, $row ) ) {
                continue;
            }

            $key       = $this->get_feed_match_key( $feed, $row );
            $stock_val = (int) $feed->col( $row, [ 'Stock', 'stock', 'qty', 'quantity', 'Quantity' ] );

            if ( $stock_min > 0 && $stock_val < $stock_min ) {
                continue;
            }

            $name     = trim( (string) $feed->col( $row, [ 'Name', 'Product', 'Title', 'name', 'product', 'title' ] ) );
            $vendor   = $this->get_feed_vendor_sku( $feed, $row );
            $purchase = $this->get_feed_purchase_price( $feed, $row );
            $uvp      = $this->get_feed_uvp( $feed, $row );
            $margin   = $this->get_margin_data( $purchase, $uvp );
            $sold     = ( $key !== '' && isset( $supplier_sales[ $key ] ) ) ? (int) $supplier_sales[ $key ] : 0;

            $entry = [
                'key'            => $key,
                'name'           => $name,
                'vendor_sku'     => $vendor,
                'stock'          => $stock_val,
                'supplier_sales' => $sold,
                'purchase'       => $purchase,
                'uvp'            => $uvp,
                'margin_value'   => $margin['value'],
                'margin_percent' => $margin['percent'],
            ];

            if ( $key === '' ) {
                $entry['note'] = 'Kein GTIN/EAN/SKU im CSV gefunden';
                $missing[]     = $entry;
                continue;
            }

            $entries[] = $entry;
            $keys[]    = $key;
        }

        $existing_ids = $this->get_existing_product_ids_by_skus( $keys );

        foreach ( $entries as $entry ) {
            $key = (string) ( $entry['key'] ?? '' );
            if ( '' === $key || isset( $existing_ids[ $key ] ) ) {
                continue;
            }

            $entry['note'] = 'Kein Produkt mit diesem Schlüssel im Shop';
            $missing[]     = $entry;
        }

        return $missing;
    }

    private function compare_nullable_numbers( $left, $right, bool $desc = false ) : int {
        $left_is_null  = ( $left === '' || $left === null );
        $right_is_null = ( $right === '' || $right === null );

        if ( $left_is_null && $right_is_null ) {
            return 0;
        }
        if ( $left_is_null ) {
            return 1;
        }
        if ( $right_is_null ) {
            return -1;
        }

        $left  = (float) $left;
        $right = (float) $right;

        if ( $left === $right ) {
            return 0;
        }

        if ( $desc ) {
            return ( $left > $right ) ? -1 : 1;
        }

        return ( $left < $right ) ? -1 : 1;
    }

    private function sort_missing_rows( array $rows, string $sort_mode ) : array {
        if ( empty( $rows ) || $sort_mode === '' || $sort_mode === 'none' ) {
            return $rows;
        }

        usort( $rows, function( $left, $right ) use ( $sort_mode ) {
            switch ( $sort_mode ) {
                case 'name_asc':
                    return strnatcasecmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) );

                case 'name_desc':
                    return strnatcasecmp( (string) ( $right['name'] ?? '' ), (string) ( $left['name'] ?? '' ) );

                case 'margin_desc':
                case 'margin_asc':
                    $cmp = $this->compare_nullable_numbers(
                        $left['margin_percent'] ?? '',
                        $right['margin_percent'] ?? '',
                        $sort_mode === 'margin_desc'
                    );
                    if ( $cmp === 0 ) {
                        $cmp = $this->compare_nullable_numbers(
                            $left['margin_value'] ?? '',
                            $right['margin_value'] ?? '',
                            $sort_mode === 'margin_desc'
                        );
                    }
                    if ( $cmp === 0 ) {
                        $cmp = strnatcasecmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) );
                    }
                    return $cmp;
            }

            return 0;
        } );

        return $rows;
    }

    /* -----------------------------------------------------------
     *  Missing Products Page (Scan + Export) + Scraper-Spalte
     * --------------------------------------------------------- */
    public function render_missing_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'caffeonline-feed-sync' ) );
        }

        echo '<div class="wrap cofs-wrap">';
        echo '<h1>' . esc_html__( 'Fehlende Produkte (CSV → Shop)', 'caffeonline-feed-sync' ) . '</h1>';
        echo '<p class="description">' . esc_html__( 'Zeigt Zeilen aus dem CSV-Feed, für die kein WooCommerce-Produkt (Match per GTIN/EAN/SKU) gefunden wurde. Du kannst hier direkt eine caffeonline.ch Produkt-URL einfügen und automatisch ein neues Produkt anlegen lassen.', 'caffeonline-feed-sync' ) . '</p>';

        echo '<div class="cofs-box">';
        echo '  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">';
        echo '    <button id="cofs-missing-scan" class="button button-primary" type="button">' . esc_html__( 'Scan starten', 'caffeonline-feed-sync' ) . '</button>';
        echo '    <label><input type="checkbox" id="cofs-missing-force" /> ' . esc_html__( 'Neu laden erzwingen (Feed-Cache ignorieren)', 'caffeonline-feed-sync' ) . '</label>';
        echo '    <label>' . esc_html__( 'Anzeigen (Vorschau):', 'caffeonline-feed-sync' ) . ' <input type="number" min="1" step="1" id="cofs-missing-limit" class="small-text" value="200" /> ' . esc_html__( 'Zeilen', 'caffeonline-feed-sync' ) . '</label>';
        echo '    <label>' . esc_html__( 'Filter Stock ≥', 'caffeonline-feed-sync' ) . ' <input type="number" min="0" step="1" id="cofs-missing-stockmin" class="small-text" value="0" /></label>';

        echo '    <label>' . esc_html__( 'Sortierung:', 'caffeonline-feed-sync' ) . ' 
            <select id="cofs-missing-sort">
                <option value="name_asc">' . esc_html__( 'Name (A–Z)', 'caffeonline-feed-sync' ) . '</option>
                <option value="name_desc">' . esc_html__( 'Name (Z–A)', 'caffeonline-feed-sync' ) . '</option>
                <option value="margin_desc">' . esc_html__( 'Marge (hoch–tief)', 'caffeonline-feed-sync' ) . '</option>
                <option value="margin_asc">' . esc_html__( 'Marge (tief–hoch)', 'caffeonline-feed-sync' ) . '</option>
                <option value="none" selected>' . esc_html__( 'Keine', 'caffeonline-feed-sync' ) . '</option>
            </select></label>';

        echo '    <label>' . esc_html__( 'Suche:', 'caffeonline-feed-sync' ) . ' 
            <input type="text" id="cofs-missing-search" class="regular-text" placeholder="' . esc_attr__( 'Produktname…', 'caffeonline-feed-sync' ) . '" />
        </label>';


        // Export-Link (Basis; Stock-Min/Force setzt JS)
        $export_url = add_query_arg(
            [
                'action'    => 'cofs_export_missing',
                'stock_min' => 0,
                'force'     => 0,
            ],
            admin_url( 'admin-post.php' )
        );
        $export_url = wp_nonce_url( $export_url, 'cofs_export_missing' );
        echo '    <a href="' . esc_url( $export_url ) . '" id="cofs-missing-export" class="button">' . esc_html__( 'CSV exportieren (alle fehlenden)', 'caffeonline-feed-sync' ) . '</a>';
        echo '  </div>';

        echo '  <div id="cofs-missing-status" style="margin:12px 0;"></div>';

        echo '  <table class="widefat striped" id="cofs-missing-table" style="margin-top:8px;">';
        echo '    <thead><tr>';
        echo '      <th style="width:12%;">' . esc_html__( 'GTIN/EAN/SKU', 'caffeonline-feed-sync' ) . '</th>';
        echo '      <th style="width:22%;">' . esc_html__( 'Produktname', 'caffeonline-feed-sync' ) . '</th>';
        echo '      <th style="width:10%;">' . esc_html__( 'Vendor SKU', 'caffeonline-feed-sync' ) . '</th>';
        echo '      <th style="width:6%;">' . esc_html__( 'Stock', 'caffeonline-feed-sync' ) . '</th>';
        echo '      <th style="width:8%;">' . esc_html__( 'Supplier Sales', 'caffeonline-feed-sync' ) . '</th>';
        echo '      <th style="width:7%;">' . esc_html__( 'EK', 'caffeonline-feed-sync' ) . '</th>';
        echo '      <th style="width:7%;">' . esc_html__( 'UVP', 'caffeonline-feed-sync' ) . '</th>';
        echo '      <th style="width:10%;">' . esc_html__( 'Marge', 'caffeonline-feed-sync' ) . '</th>';
        echo '      <th style="width:10%;">' . esc_html__( 'Quelle / Hinweise', 'caffeonline-feed-sync' ) . '</th>';
        echo '      <th style="width:8%;">' . esc_html__( 'caffeonline.ch URL & Import', 'caffeonline-feed-sync' ) . '</th>';
        echo '    </tr></thead>';
        echo '    <tbody></tbody>';
        echo '  </table>';

        echo '</div>'; // .cofs-box
        echo '</div>'; // .wrap
    }

    public function ajax_missing_scan() {
        if ( ! current_user_can( 'manage_options' ) ) {
            $this->send_json_error( [ 'message' => 'forbidden' ], 403 );
        }
        if ( ! check_ajax_referer( 'cofs_ajax', 'nonce', false ) ) {
            $this->send_json_error( [ 'message' => 'bad nonce' ], 400 );
        }

        $limit     = max( 1, intval( $_POST['limit'] ?? 200 ) );
        $stock_min = max( 0, intval( $_POST['stock_min'] ?? 0 ) );
        $sort_mode = sanitize_key( wp_unslash( $_POST['sort'] ?? 'none' ) );
        $force     = ! empty( $_POST['force'] );

        try {
            $feed_data = $this->get_missing_feed_dataset( $force );
        } catch ( \Throwable $e ) {
            $this->send_json_error( [ 'message' => 'Feed-Fehler: ' . $e->getMessage() ] );
        }

        if ( is_wp_error( $feed_data ) ) {
            $this->send_json_error( [ 'message' => 'Feed-Fehler: ' . $feed_data->get_error_message() ] );
        }

        $feed = $feed_data['feed'];
        $rows = $feed_data['rows'];

        if ( empty( $rows ) || ! is_array( $rows ) ) {
            $this->send_json_success( [
                'count'           => 0,
                'displayed_count' => 0,
                'total_count'     => 0,
                'limited'         => false,
                'rows'            => [],
            ] );
        }

        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
            $this->send_json_error( [ 'message' => 'WooCommerce nicht verfügbar (wc_get_product_id_by_sku fehlt).' ] );
        }

        $missing         = $this->collect_missing_feed_rows( $feed, $rows, $stock_min );
        $missing         = $this->sort_missing_rows( $missing, $sort_mode );
        $total_missing   = count( $missing );
        $displayed_rows  = $missing;
        $displayed_count = $total_missing;

        if ( $limit > 0 && $total_missing > $limit ) {
            $displayed_rows  = array_slice( $missing, 0, $limit );
            $displayed_count = count( $displayed_rows );
        }

        $this->send_json_success( [
            'count'           => $displayed_count,
            'displayed_count' => $displayed_count,
            'total_count'     => $total_missing,
            'limited'         => $displayed_count < $total_missing,
            'rows'            => $displayed_rows,
        ] );
    }

    public function export_missing_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'forbidden', 403 );
        }
        check_admin_referer( 'cofs_export_missing' );

        $stock_min = max( 0, intval( $_GET['stock_min'] ?? 0 ) );
        $force     = ! empty( $_GET['force'] );

        try {
            $feed_data = $this->get_missing_feed_dataset( $force );
        } catch ( \Throwable $e ) {
            wp_die( 'Feed-Fehler: ' . esc_html( $e->getMessage() ) );
        }

        if ( is_wp_error( $feed_data ) ) {
            wp_die( 'Feed-Fehler: ' . esc_html( $feed_data->get_error_message() ) );
        }

        $feed = $feed_data['feed'];
        $rows = $feed_data['rows'];

        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
            wp_die( 'WooCommerce-Funktion wc_get_product_id_by_sku() nicht verfügbar.' );
        }

        $missing = $this->collect_missing_feed_rows( $feed, $rows, $stock_min );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=missing-products.csv' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'Key(GTIN/EAN/SKU)', 'Name', 'Vendor SKU', 'Stock', 'Supplier Sales', 'Purchase Price', 'UVP', 'Margin', 'Margin %', 'Note' ] );

        foreach ( $missing as $m ) {
            fputcsv( $out, [
                $m['key'],
                $m['name'],
                $m['vendor_sku'],
                $m['stock'],
                $m['supplier_sales'],
                $m['purchase'],
                $m['uvp'],
                $m['margin_value'],
                $m['margin_percent'],
                $m['note'],
            ] );
        }

        fclose( $out );
        exit;
    }
    
    private function scrape_caffeonline_product( string $url ) {
        $response = wp_remote_get(
            $url,
            [
                'timeout' => 20,
                'headers' => [
                    'User-Agent' => 'COFS-Scraper/1.0; ' . home_url(),
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) {
            return new WP_Error( 'http_error', 'Fehler beim Laden der Produktseite (' . $code . ').' );
        }

        $html = wp_remote_retrieve_body( $response );
        if ( ! $html ) {
            return new WP_Error( 'empty_html', 'Leere Antwort von der Produktseite.' );
        }

        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'no_dom', 'DOMDocument nicht verfügbar (PHP-XML Erweiterung fehlt).' );
        }

        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $dom->loadHTML( $html );
        $xpath = new DOMXPath( $dom );
        libxml_clear_errors();

        // Titel
        $title = '';
        $nodes = $xpath->query( "//h1[contains(@class,'product_title')]" );
        if ( $nodes && $nodes->length ) {
            $title = trim( $nodes->item(0)->textContent );
        }

        // Bild (og:image → Produktbild)
        $image_url = '';
        $og = $xpath->query( "//meta[@property='og:image']/@content" );
        if ( $og && $og->length ) {
            $image_url = esc_url_raw( trim( $og->item(0)->nodeValue ) );
        } else {
            $img = $xpath->query( "//img[contains(@class,'wp-post-image') or contains(@class,'attachment-woocommerce_single')]/@src" );
            if ( $img && $img->length ) {
                $image_url = esc_url_raw( trim( $img->item(0)->nodeValue ) );
            }
        }

        // Variationserkennung: WooCommerce data-product_variations
        $is_variable = false;
        $variations  = [];

        if ( preg_match( '/data-product_variations=("|\')(.+?)\1/Us', $html, $m ) ) {
            $json_raw = html_entity_decode( $m[2] );
            $data = json_decode( $json_raw, true );
            if ( is_array( $data ) && ! empty( $data ) ) {
                $is_variable = true;
                foreach ( $data as $var ) {
                    $variations[] = [
                        'attributes' => isset( $var['attributes'] ) && is_array( $var['attributes'] ) ? $var['attributes'] : [],
                        'sku'        => isset( $var['sku'] ) ? (string) $var['sku'] : '',
                        'price'      => isset( $var['display_price'] ) ? $var['display_price'] : ( $var['regular_price'] ?? '' ),
                    ];
                }
            }
        }

        return [
            'title'       => $title,
            'image_url'   => $image_url,
            'is_variable' => $is_variable,
            'variations'  => $variations,
        ];
    }
    /**
     * Bild von URL in die Mediathek importieren.
     */
    private function import_image_to_media( string $image_url, string $title = '' ) {
        if ( empty( $image_url ) ) {
            return 0;
        }

        if ( ! function_exists( 'download_url' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'wp_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'wp_insert_attachment' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $tmp = download_url( $image_url );
        if ( is_wp_error( $tmp ) ) {
            return 0;
        }

        $name = basename( parse_url( $image_url, PHP_URL_PATH ) );
        $file = [
            'name'     => $name,
            'type'     => 'image/jpeg',
            'tmp_name' => $tmp,
            'error'    => 0,
            'size'     => filesize( $tmp ),
        ];

        $overrides = [ 'test_form' => false ];
        $results   = wp_handle_sideload( $file, $overrides );

        if ( ! empty( $results['error'] ) ) {
            @unlink( $tmp );
            return 0;
        }

        $attachment = [
            'post_mime_type' => $results['type'],
            'post_title'     => $title ?: sanitize_file_name( $name ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment( $attachment, $results['file'] );
        if ( is_wp_error( $attach_id ) ) {
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata( $attach_id, $results['file'] );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        return $attach_id;
    }

    /**
     * Aus Scrape-Daten Produkt anlegen oder aktualisieren.
     * - $key:  GTIN/EAN/SKU aus CSV (falls vorhanden)
     * - $vendor_sku: optionale Lieferant-/Hersteller-SKU als Fallback
     */
    private function create_or_update_product_from_scrape( array $scraped, string $key, string $vendor_sku, string $source_url ) {
        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
            return new WP_Error( 'no_wc', 'WooCommerce nicht verfügbar.' );
        }

        if (
            class_exists( 'COFS_Deleted_Feed_Items' )
            && COFS_Deleted_Feed_Items::is_blocked(
                [
                    'sku'        => $key,
                    'global_id'  => $key,
                    'vendor_sku' => $vendor_sku,
                    'source_url' => $source_url,
                ]
            )
        ) {
            return new WP_Error( 'cofs_deleted_feed_item', __( 'Dieses Feed-Produkt wurde geloescht und ist fuer den erneuten Import gesperrt.', 'caffeonline-feed-sync' ) );
        }

        $base_sku = '';
        if ( $key !== '' ) {
            $base_sku = $key;
        } elseif ( $vendor_sku !== '' ) {
            $base_sku = $vendor_sku;
        }

        // 1) Wenn ein Produkt mit dieser SKU bereits existiert → updaten
        if ( $base_sku !== '' ) {
            $existing_id = wc_get_product_id_by_sku( $base_sku );
            if ( $existing_id ) {
                if ( ! empty( $scraped['title'] ) ) {
                    wp_update_post(
                        [
                            'ID'         => $existing_id,
                            'post_title' => wp_strip_all_tags( $scraped['title'] ),
                        ]
                    );
                }
                if ( ! empty( $scraped['image_url'] ) && ! has_post_thumbnail( $existing_id ) ) {
                    $thumb_id = $this->import_image_to_media( $scraped['image_url'], $scraped['title'] );
                    if ( $thumb_id ) {
                        set_post_thumbnail( $existing_id, $thumb_id );
                    }
                }
                update_post_meta( $existing_id, '_cofs_source_url', esc_url_raw( $source_url ) );
                return $existing_id;
            }
        }

        // 2) Neues Produkt anlegen
        $thumb_id = 0;
        if ( ! empty( $scraped['image_url'] ) ) {
            $thumb_id = $this->import_image_to_media( $scraped['image_url'], $scraped['title'] );
        }

        // Variable Produkt?
        if ( ! empty( $scraped['is_variable'] ) && ! empty( $scraped['variations'] ) ) {
            return $this->create_variable_product_from_scrape( $scraped, $base_sku, $vendor_sku, $source_url, $thumb_id );
        }

        // Einfaches Produkt (Standard)
        $post_id = wp_insert_post(
            [
                'post_type'   => 'product',
                'post_status' => 'draft',
                'post_title'  => wp_strip_all_tags( $scraped['title'] ),
            ]
        );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        if ( $base_sku !== '' ) {
            update_post_meta( $post_id, '_sku', $base_sku );
        }
        if ( $vendor_sku !== '' ) {
            update_post_meta( $post_id, '_vendor_sku', $vendor_sku );
        }

        wp_set_object_terms( $post_id, 'simple', 'product_type' );
        update_post_meta( $post_id, '_stock_status', 'instock' );
        update_post_meta( $post_id, '_cofs_source_url', esc_url_raw( $source_url ) );

        if ( $thumb_id ) {
            set_post_thumbnail( $post_id, $thumb_id );
        }

        return $post_id;
    }

    /**
     * Variables Produkt inkl. Variationen anlegen.
     * Nutzt data-product_variations von caffeonline.ch.
     */
    private function create_variable_product_from_scrape( array $scraped, string $base_sku, string $vendor_sku, string $source_url, int $thumb_id = 0 ) {
        if ( empty( $scraped['variations'] ) ) {
            return new WP_Error( 'no_variations', 'Keine Variationen gefunden.' );
        }

        // Parent anlegen
        $parent_id = wp_insert_post(
            [
                'post_type'   => 'product',
                'post_status' => 'draft',
                'post_title'  => wp_strip_all_tags( $scraped['title'] ),
            ]
        );
        if ( is_wp_error( $parent_id ) ) {
            return $parent_id;
        }

        wp_set_object_terms( $parent_id, 'variable', 'product_type' );
        update_post_meta( $parent_id, '_cofs_source_url', esc_url_raw( $source_url ) );

        if ( $base_sku !== '' ) {
            // optional: Parent-SKU als Orientierung
            update_post_meta( $parent_id, '_sku', $base_sku );
        }
        if ( $vendor_sku !== '' ) {
            update_post_meta( $parent_id, '_vendor_sku', $vendor_sku );
        }
        if ( $thumb_id ) {
            set_post_thumbnail( $parent_id, $thumb_id );
        }

        $first_var = $scraped['variations'][0];
        if ( empty( $first_var['attributes'] ) || ! is_array( $first_var['attributes'] ) ) {
            return $parent_id;
        }

        $attr_keys = array_keys( $first_var['attributes'] );
        $attr_key  = $attr_keys[0]; // z.B. attribute_pa_packung

        $taxonomy = $attr_key;
        if ( strpos( $taxonomy, 'attribute_' ) === 0 ) {
            $taxonomy = substr( $taxonomy, strlen( 'attribute_' ) );
        }

        // Terme sammeln
        $terms = [];
        foreach ( $scraped['variations'] as $var ) {
            if ( empty( $var['attributes'][ $attr_key ] ) ) {
                continue;
            }
            $val = $var['attributes'][ $attr_key ];
            if ( function_exists( 'wc_sanitize_term_text_based' ) ) {
                $val = wc_sanitize_term_text_based( $val );
            } else {
                $val = sanitize_text_field( $val );
            }
            if ( $val !== '' ) {
                $terms[] = $val;
            }
        }
        $terms = array_unique( $terms );

        $is_tax = taxonomy_exists( $taxonomy );
        if ( $is_tax ) {
            foreach ( $terms as $term_name ) {
                if ( ! term_exists( $term_name, $taxonomy ) ) {
                    wp_insert_term( $term_name, $taxonomy );
                }
            }
        }

        // Parent-Attribut setzen
        $product_attributes = [
            $taxonomy => [
                'name'         => $taxonomy,
                'value'        => implode( ' | ', $terms ),
                'is_visible'   => 1,
                'is_variation' => 1,
                'is_taxonomy'  => $is_tax ? 1 : 0,
            ],
        ];
        update_post_meta( $parent_id, '_product_attributes', $product_attributes );

        // Variationen anlegen
        foreach ( $scraped['variations'] as $var ) {
            $raw_val = $var['attributes'][ $attr_key ] ?? '';
            if ( $raw_val === '' ) {
                continue;
            }

            if ( function_exists( 'wc_sanitize_term_text_based' ) ) {
                $attr_val = wc_sanitize_term_text_based( $raw_val );
            } else {
                $attr_val = sanitize_text_field( $raw_val );
            }
            if ( $attr_val === '' ) {
                continue;
            }

            $variation_id = wp_insert_post(
                [
                    'post_type'   => 'product_variation',
                    'post_status' => 'publish',
                    'post_parent' => $parent_id,
                    'post_title'  => wp_strip_all_tags( $scraped['title'] . ' ' . $attr_val ),
                ]
            );
            if ( is_wp_error( $variation_id ) ) {
                continue;
            }

            // Attribut-Meta setzen
            if ( $is_tax ) {
                if ( ! term_exists( $attr_val, $taxonomy ) ) {
                    wp_insert_term( $attr_val, $taxonomy );
                }
                $attribute_key = 'attribute_' . $taxonomy;
                update_post_meta( $variation_id, $attribute_key, sanitize_title( $attr_val ) );
            } else {
                $attribute_key = 'attribute_' . $taxonomy;
                update_post_meta( $variation_id, $attribute_key, $attr_val );
            }

            // SKU / Preis
            if ( ! empty( $var['sku'] ) ) {
                update_post_meta( $variation_id, '_sku', wc_clean( $var['sku'] ) );
            }

            if ( isset( $var['price'] ) && $var['price'] !== '' ) {
                $price = wc_format_decimal( $var['price'] );
                update_post_meta( $variation_id, '_regular_price', $price );
                update_post_meta( $variation_id, '_price', $price );
            }

            update_post_meta( $variation_id, '_stock_status', 'instock' );
        }

        return $parent_id;
    }
    
    public function ajax_scrape_product() {
        if ( ! class_exists( 'COFS_Scraper' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'class-cofs-scraper.php';
        }
    
        $scraper = new COFS_Scraper();
        $scraper->handle_ajax();
    }
}

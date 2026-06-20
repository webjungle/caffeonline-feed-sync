<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Supplier Sales (Stock Delta)
 *
 * Spec:
 * 1) User triggers a baseline sync -> we store supplier stock per SKU.
 * 2) Hourly cron runs -> stores new snapshot and adds positive decreases as "sold".
 *
 * Notes:
 * - Restocks (stock increase) are ignored for sold (but snapshot is updated).
 * - Uses feed SKU (GTIN/EAN/SKU key column) as the primary key.
 * - Also stores vendor SKU (supplier article number) for display.
 */
class COFS_Supplier_Report {

    const CRON_HOOK = 'cofs_hourly_supplier_stock_delta';
    const CRON_SCHEDULE = 'cofs_every_three_hours';

    // Stored data
    const OPTION_SNAPSHOT = 'cofs_supplier_stock_snapshot';
    const OPTION_TOTALS   = 'cofs_supplier_stock_sold_totals';
    const OPTION_META     = 'cofs_supplier_stock_meta';
    private static $schedule_filter_added = false;

    public static function init() {
        self::register_schedule_filter();
        add_action( self::CRON_HOOK, [ __CLASS__, 'cron_run' ] );
    }

    private static function register_schedule_filter() : void {
        if ( self::$schedule_filter_added ) {
            return;
        }

        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_schedule' ] );
        self::$schedule_filter_added = true;
    }

    public static function add_cron_schedule( array $schedules ) : array {
        $schedules[ self::CRON_SCHEDULE ] = [
            'interval' => 3 * HOUR_IN_SECONDS,
            'display'  => __( 'Every 3 hours (CaffeOnline Feed Sync)', 'caffeonline-feed-sync' ),
        ];

        return $schedules;
    }

    public static function schedule() {
        self::register_schedule_filter();

        $current_schedule = wp_get_schedule( self::CRON_HOOK );
        if ( $current_schedule && self::CRON_SCHEDULE !== $current_schedule ) {
            self::unschedule();
        }

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }

    public static function unschedule() {
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
            return;
        }

        while ( $ts = wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
    }

    /**
     * Manual: create baseline snapshot (no sold calc).
     */
    public static function create_baseline() : array {
        $current = self::fetch_supplier_stock_map();
        $stock_sync = self::sync_local_product_data_from_map( $current );

        update_option( self::OPTION_SNAPSHOT, $current, false );

        // Keep totals; user may want to accumulate over time.
        $meta = self::get_meta();
        $meta['last_baseline_at'] = time();
        $meta['last_run_at']      = 0;
        $meta['last_delta']       = [];
        $meta['last_seen_count']  = count( $current );
        $meta['last_stock_sync']  = $stock_sync;
        $meta['last_stock_sync_at'] = time();
        update_option( self::OPTION_META, $meta, false );

        return [
            'baseline_count' => count( $current ),
            'baseline_at'    => $meta['last_baseline_at'],
            'stock_sync'     => $stock_sync,
        ];
    }

    /**
     * Manual: run compare now (same as cron).
     */
    public static function force_run_now() : array {
        return self::run_delta_job();
    }

    public static function cron_run() {
        self::run_delta_job();
    }

    /**
     * Core job: compare prev snapshot with current stock and add positive decreases to totals.
     */
    private static function run_delta_job() : array {
        $prev = get_option( self::OPTION_SNAPSHOT, [] );
        if ( ! is_array( $prev ) ) $prev = [];

        $current = self::fetch_supplier_stock_map();
        $totals  = get_option( self::OPTION_TOTALS, [] );
        if ( ! is_array( $totals ) ) $totals = [];

        // If no baseline exists yet, set baseline and exit.
        if ( empty( $prev ) ) {
            $stock_sync = self::sync_local_product_data_from_map( $current );

            update_option( self::OPTION_SNAPSHOT, $current, false );
            $meta = self::get_meta();
            $meta['last_run_at']     = time();
            $meta['last_delta']      = [];
            $meta['last_seen_count'] = count( $current );
            $meta['last_stock_sync'] = $stock_sync;
            $meta['last_stock_sync_at'] = time();
            $meta['note']            = 'No baseline found. Created baseline automatically.';
            update_option( self::OPTION_META, $meta, false );
            return [ 'created_baseline' => true, 'seen' => count( $current ), 'stock_sync' => $stock_sync ];
        }

        $delta_added = 0;
        $delta_map   = [];

        // Compute deltas for SKUs present in both snapshots
        foreach ( $current as $feed_sku => $row ) {
            $cur_stock = (int) ( $row['stock'] ?? 0 );
            if ( ! isset( $prev[ $feed_sku ] ) ) {
                continue;
            }
            $prev_stock = (int) ( $prev[ $feed_sku ]['stock'] ?? 0 );
            $d = $prev_stock - $cur_stock;
            if ( $d > 0 ) {
                if ( ! isset( $totals[ $feed_sku ] ) ) {
                    $totals[ $feed_sku ] = [
                        'sold'       => 0,
                        'vendor_sku' => (string) ( $row['vendor_sku'] ?? '' ),
                    ];
                }
                $totals[ $feed_sku ]['sold'] = (int) $totals[ $feed_sku ]['sold'] + (int) $d;
                // Keep latest vendor sku for display
                if ( ! empty( $row['vendor_sku'] ) ) {
                    $totals[ $feed_sku ]['vendor_sku'] = (string) $row['vendor_sku'];
                }
                $delta_map[ $feed_sku ] = (int) $d;
                $delta_added += (int) $d;
            }
        }

        $stock_sync = self::sync_local_product_data_from_map( $current );

        // Update snapshot to current
        update_option( self::OPTION_SNAPSHOT, $current, false );
        update_option( self::OPTION_TOTALS, $totals, false );

        $meta = self::get_meta();
        $meta['last_run_at']     = time();
        $meta['last_delta']      = $delta_map;
        $meta['last_delta_sum']  = $delta_added;
        $meta['last_seen_count'] = count( $current );
        $meta['last_stock_sync'] = $stock_sync;
        $meta['last_stock_sync_at'] = time();
        unset( $meta['note'] );
        update_option( self::OPTION_META, $meta, false );

        return [
            'seen'       => count( $current ),
            'delta_sum'  => $delta_added,
            'delta_rows' => count( $delta_map ),
            'stock_sync' => $stock_sync,
        ];
    }

    /**
     * Fetch supplier feed and build stock map keyed by feed SKU (GTIN/EAN/SKU).
     *
     * Structure:
     *  [feed_sku] => [ 'stock' => int, 'vendor_sku' => string ]
     */
    private static function fetch_supplier_stock_map() : array {
        $opts = get_option( 'cofs_settings', [] );
        $url  = isset( $opts['feed_url'] ) ? trim( (string) $opts['feed_url'] ) : '';
        if ( ! $url ) {
            return [];
        }

        $feed = new COFS_Feed( $url );
        $rows = $feed->get_rows();
        if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
            return [];
        }

        $map = [];
        foreach ( $rows as $r ) {
            if ( ! is_array( $r ) ) continue;

            $feed_sku = $feed->col( $r, [
                'GTIN', 'gtin',
                'EAN',  'ean',
                'Key(GTIN/EAN/SKU)',
                'Key',  'key',
                'SKU',  'sku',
            ] );

            $feed_sku = trim( (string) $feed_sku );
            if ( $feed_sku === '' ) continue;

            $vendor_sku = $feed->col( $r, [ 'SKU', 'sku' ] );
            $vendor_sku = trim( (string) $vendor_sku );

            $csv_stock = $feed->col( $r, [ 'Stock', 'stock', 'qty', 'quantity', 'Quantity' ] );
            $stock     = ( $csv_stock === '' || $csv_stock === null ) ? 0 : (int) $csv_stock;

            $purchase = $feed->col( $r, [ 'Purchase', 'purchase', 'Purchase Price', 'purchase_price', 'Einkaufspreis', 'EK', 'Cost', 'cost' ] );
            $purchase = ( $purchase === '' || $purchase === null ) ? '' : (string) $purchase;
            $purchase = $purchase !== '' ? wc_format_decimal( $purchase ) : '';

            $map[ $feed_sku ] = [
                'stock'          => $stock,
                'vendor_sku'     => $vendor_sku,
                'purchase_price' => $purchase,
            ];
        }

        return $map;
    }

    public static function get_meta() : array {
        $meta = get_option( self::OPTION_META, [] );
        if ( ! is_array( $meta ) ) $meta = [];
        return $meta;
    }

    public static function get_totals() : array {
        $totals = get_option( self::OPTION_TOTALS, [] );
        return is_array( $totals ) ? $totals : [];
    }

    public static function reset_totals() : void {
        update_option( self::OPTION_TOTALS, [], false );
        $meta = self::get_meta();
        $meta['totals_reset_at'] = time();
        update_option( self::OPTION_META, $meta, false );
    }

    private static function get_existing_product_records_by_skus( array $skus ) : array {
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
                SELECT pm.meta_value AS sku, pm.post_id AS post_id, p.post_type AS post_type, p.post_parent AS post_parent
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
                $map[ $sku ] = [
                    'id'        => (int) $row['post_id'],
                    'post_type' => isset( $row['post_type'] ) ? (string) $row['post_type'] : '',
                    'parent_id' => isset( $row['post_parent'] ) ? (int) $row['post_parent'] : 0,
                ];
            }
        }

        return $map;
    }

    private static function get_existing_product_ids_by_skus( array $skus ) : array {
        $records = self::get_existing_product_records_by_skus( $skus );
        $ids     = [];

        foreach ( $records as $sku => $record ) {
            $ids[ $sku ] = (int) ( $record['id'] ?? 0 );
        }

        return $ids;
    }

    private static function get_excluded_product_ids() : array {
        $opts = get_option( 'cofs_settings', [] );
        if ( empty( $opts['excluded_product_ids'] ) || ! is_array( $opts['excluded_product_ids'] ) ) {
            return [];
        }

        return array_fill_keys(
            array_values( array_unique( array_filter( array_map( 'absint', $opts['excluded_product_ids'] ) ) ) ),
            true
        );
    }

    private static function empty_product_sync_result() : array {
        return [
            'matched'          => 0,
            'stock_changed'    => 0,
            'stock_unchanged'  => 0,
            'purchase_changed' => 0,
            'purchase_unchanged' => 0,
            'purchase_missing' => 0,
            'price_logs'       => 0,
            'skipped_excluded' => 0,
            'errors'           => 0,
        ];
    }

    private static function normalize_purchase_price_value( $value ) : string {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }

        if ( function_exists( 'wc_format_decimal' ) ) {
            $normalized = wc_format_decimal( $value );
            return '' === $normalized ? '' : (string) $normalized;
        }

        $value = str_replace( ',', '.', $value );
        return is_numeric( $value ) ? (string) (float) $value : $value;
    }

    private static function sync_local_product_data_from_map( array $current ) : array {
        if ( empty( $current ) || ! function_exists( 'wc_get_product' ) ) {
            return self::empty_product_sync_result();
        }

        $records  = self::get_existing_product_records_by_skus( array_keys( $current ) );
        $excluded = self::get_excluded_product_ids();
        $result   = self::empty_product_sync_result();

        foreach ( $records as $feed_sku => $record ) {
            $product_id = (int) ( $record['id'] ?? 0 );
            $parent_id  = (int) ( $record['parent_id'] ?? 0 );

            if ( ! $product_id || isset( $excluded[ $product_id ] ) || ( $parent_id && isset( $excluded[ $parent_id ] ) ) ) {
                $result['skipped_excluded']++;
                continue;
            }

            if ( ! isset( $current[ $feed_sku ] ) || ! is_array( $current[ $feed_sku ] ) ) {
                continue;
            }

            $feed_row     = $current[ $feed_sku ];
            $new_stock    = max( 0, (int) ( $feed_row['stock'] ?? 0 ) );
            $new_status   = $new_stock > 0 ? 'instock' : 'outofstock';
            $new_purchase = self::normalize_purchase_price_value( $feed_row['purchase_price'] ?? '' );
            $product      = wc_get_product( $product_id );

            if ( ! $product ) {
                $result['errors']++;
                continue;
            }

            $result['matched']++;

            $old_stock_raw = $product->get_stock_quantity( 'edit' );
            $old_stock     = null === $old_stock_raw ? null : (int) $old_stock_raw;
            $old_status    = (string) $product->get_stock_status( 'edit' );
            $manage_stock  = (bool) $product->get_manage_stock( 'edit' );
            $stock_changed = ! ( $manage_stock && $old_stock === $new_stock && $old_status === $new_status );

            if ( $stock_changed ) {
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $new_stock );
                $product->set_stock_status( $new_status );
                $product->save();

                if ( function_exists( 'wc_delete_product_transients' ) ) {
                    wc_delete_product_transients( $product_id );
                }

                $result['stock_changed']++;
            } else {
                $result['stock_unchanged']++;
            }

            if ( '' === $new_purchase ) {
                $result['purchase_missing']++;
                continue;
            }

            $old_purchase_raw = get_post_meta( $product_id, '_purchase_price', true );
            $old_purchase     = self::normalize_purchase_price_value( $old_purchase_raw );

            if ( $old_purchase === $new_purchase ) {
                $result['purchase_unchanged']++;
                continue;
            }

            update_post_meta( $product_id, '_purchase_price', $new_purchase );
            $result['purchase_changed']++;

            if ( class_exists( 'COFS_Price_Log' ) ) {
                $logged = COFS_Price_Log::log_purchase_price_change(
                    [
                        'product_id'   => $product_id,
                        'product_name' => $product->get_name(),
                        'product_sku'  => (string) $product->get_sku( 'edit' ),
                        'feed_sku'     => (string) $feed_sku,
                        'vendor_sku'   => (string) ( $feed_row['vendor_sku'] ?? '' ),
                        'old_price'    => (string) $old_purchase_raw,
                        'new_price'    => $new_purchase,
                        'source'       => 'supplier_cron',
                    ]
                );

                if ( $logged ) {
                    $result['price_logs']++;
                }
            }
        }

        return $result;
    }

    /**
     * Returns missing products: sold > 0 but no local WC product exists with that SKU.
     * Output rows sorted by sold desc.
     */
    public static function get_missing_products_rows( int $limit = 500 ) : array {
        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
            return [];
        }

        $totals   = self::get_totals();
        $existing = self::get_existing_product_ids_by_skus( array_keys( $totals ) );
        // Snapshot contains the latest known stock per feed SKU.
        $snapshot = get_option( self::OPTION_SNAPSHOT, [] );
        if ( ! is_array( $snapshot ) ) {
            $snapshot = [];
        }
        $rows   = [];

        foreach ( $totals as $feed_sku => $data ) {
            $sold = isset( $data['sold'] ) ? (int) $data['sold'] : 0;
            if ( $sold <= 0 ) continue;
            $pid = isset( $existing[ (string) $feed_sku ] ) ? (int) $existing[ (string) $feed_sku ] : 0;
            if ( $pid ) continue;

            $cur_stock = 0;
            if ( isset( $snapshot[ $feed_sku ] ) && is_array( $snapshot[ $feed_sku ] ) ) {
                $cur_stock = (int) ( $snapshot[ $feed_sku ]['stock'] ?? 0 );
            }
            $rows[] = [
                'sku'        => (string) $feed_sku,
                'vendor_sku' => (string) ( $data['vendor_sku'] ?? '' ),
                'sold'       => $sold,
                'stock'          => $cur_stock,
                'purchase_price' => ( isset( $snapshot[ $feed_sku ] ) && is_array( $snapshot[ $feed_sku ] ) ) ? (string) ( $snapshot[ $feed_sku ]['purchase_price'] ?? '' ) : '',
            ];
        }

        usort( $rows, function( $a, $b ) {
            return (int) $b['sold'] <=> (int) $a['sold'];
        } );

        if ( $limit > 0 && count( $rows ) > $limit ) {
            $rows = array_slice( $rows, 0, $limit );
        }

        return $rows;
    }

    /**
     * Returns top sold rows (all), sorted by sold desc.
     */
    public static function get_top_sold_rows( int $limit = 100 ) : array {
        $totals = self::get_totals();
        $existing = self::get_existing_product_ids_by_skus( array_keys( $totals ) );
        $rows   = [];

        foreach ( $totals as $feed_sku => $data ) {
            $sold = isset( $data['sold'] ) ? (int) $data['sold'] : 0;
            if ( $sold <= 0 ) continue;
            $rows[] = [
                'sku'        => (string) $feed_sku,
                'vendor_sku' => (string) ( $data['vendor_sku'] ?? '' ),
                'sold'       => $sold,
                'local_id'   => isset( $existing[ (string) $feed_sku ] ) ? (int) $existing[ (string) $feed_sku ] : 0,
            ];
        }

        usort( $rows, function( $a, $b ) {
            return (int) $b['sold'] <=> (int) $a['sold'];
        } );

        if ( $limit > 0 && count( $rows ) > $limit ) {
            $rows = array_slice( $rows, 0, $limit );
        }

        return $rows;
    }
}

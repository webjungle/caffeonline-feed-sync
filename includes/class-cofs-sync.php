<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class COFS_Sync {
    private $feed;
    private $opts;
    private $excluded_ids = [];
    private $sku_to_product_id = [];

    public function __construct( $feed ) {
        $this->feed = $feed;
        $this->opts = get_option( 'cofs_settings', [] );
        if ( isset( $this->opts['excluded_product_ids'] ) && is_array( $this->opts['excluded_product_ids'] ) ) {
            $this->excluded_ids = array_values( array_unique( array_filter( array_map( 'absint', $this->opts['excluded_product_ids'] ) ) ) );
        } else {
            $this->excluded_ids = [];
        }
    }

    public function apply( $rows ) {
        $changes = [];
        $this->prime_product_ids( $rows );

        foreach ( $rows as $r ) {
            $one = $this->diff_row( $r );
            if ( ! $one ) {
                continue;
            }

            $pid = $one['product_id'];

            // Vendor SKU speichern
            if ( isset( $one['vendor_sku'] ) ) {
                update_post_meta(
                    $pid,
                    '_vendor_sku',
                    sanitize_text_field( $one['vendor_sku']['new'] )
                );
            }

            // Stock übernehmen
            if ( isset( $one['stock'] ) ) {
                $new = (int) $one['stock']['new'];

                if ( function_exists( 'wc_get_product' ) ) {
                    $product = wc_get_product( $pid );
                    if ( $product ) {
                        $product->set_manage_stock( true );
                        $product->set_stock_quantity( $new );
                        $product->set_stock_status( $new > 0 ? 'instock' : 'outofstock' );
                        $product->save();
                    } else {
                        update_post_meta( $pid, '_stock',        $new );
                        update_post_meta( $pid, '_manage_stock', 'yes' );
                        update_post_meta( $pid, '_stock_status', $new > 0 ? 'instock' : 'outofstock' );
                    }
                } else {
                    update_post_meta( $pid, '_stock',        $new );
                    update_post_meta( $pid, '_manage_stock', 'yes' );
                    update_post_meta( $pid, '_stock_status', $new > 0 ? 'instock' : 'outofstock' );
                }

                if ( function_exists( 'wc_delete_product_transients' ) ) {
                    wc_delete_product_transients( $pid );
                }
            }

            // Purchase Price übernehmen
            if ( isset( $one['purchase_price'] ) ) {
                $new_price = (string) $one['purchase_price']['new'];
                update_post_meta(
                    $pid,
                    '_purchase_price',
                    $new_price
                );

                if ( class_exists( 'COFS_Price_Log' ) ) {
                    $vendor_sku = '';
                    if ( isset( $one['vendor_sku']['new'] ) ) {
                        $vendor_sku = (string) $one['vendor_sku']['new'];
                    } elseif ( isset( $one['vendor_sku_feed'] ) ) {
                        $vendor_sku = (string) $one['vendor_sku_feed'];
                    } else {
                        $vendor_sku = (string) get_post_meta( $pid, '_vendor_sku', true );
                    }

                    COFS_Price_Log::log_purchase_price_change( [
                        'product_id'   => $pid,
                        'product_name' => get_the_title( $pid ),
                        'product_sku'  => (string) get_post_meta( $pid, '_sku', true ),
                        'feed_sku'     => (string) ( $one['feed_sku'] ?? '' ),
                        'vendor_sku'   => $vendor_sku,
                        'old_price'    => (string) ( $one['purchase_price']['old'] ?? '' ),
                        'new_price'    => $new_price,
                        'source'       => 'manual_sync',
                    ] );
                }
            }

            $changes[] = $one;
        }

        return [
            'mode'    => 'apply',
            'count'   => count( $changes ),
            'changes' => $changes,
        ];
    }

    /**
     * Prüft, ob eine Produkt-ID exkludiert ist.
     */
    private function is_excluded( $product_id ) : bool {
        if ( ! $product_id ) {
            return false;
        }
        return in_array( (int) $product_id, $this->excluded_ids, true );
    }

    private function prime_product_ids( array $rows ) : void {
        global $wpdb;

        $this->sku_to_product_id = [];
        if ( empty( $rows ) || ! $wpdb ) {
            return;
        }

        $skus = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            foreach ( $this->get_feed_match_keys( $row ) as $sku ) {
                $skus[ $sku ] = true;
            }
        }

        if ( empty( $skus ) ) {
            return;
        }

        $values       = array_keys( $skus );
        $placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
        $sql          = "
            SELECT pm.meta_value AS sku, pm.post_id AS post_id
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key IN ('_sku', '_vendor_sku', '_bcl_original_sku', '_global_unique_id')
              AND pm.meta_value IN ($placeholders)
              AND p.post_type IN ('product', 'product_variation')
              AND p.post_status NOT IN ('trash', 'auto-draft')
            ORDER BY CASE WHEN p.post_type = 'product' THEN 0 ELSE 1 END, pm.post_id ASC
        ";

        $found = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
        if ( ! is_array( $found ) ) {
            return;
        }

        foreach ( $found as $row ) {
            $sku = isset( $row['sku'] ) ? (string) $row['sku'] : '';
            if ( '' === $sku || isset( $this->sku_to_product_id[ $sku ] ) ) {
                continue;
            }
            $this->sku_to_product_id[ $sku ] = (int) $row['post_id'];
        }
    }

    private function get_product_id_by_sku( string $sku ) : int {
        if ( isset( $this->sku_to_product_id[ $sku ] ) ) {
            return (int) $this->sku_to_product_id[ $sku ];
        }

        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
            return 0;
        }

        return (int) wc_get_product_id_by_sku( $sku );
    }

    private function get_feed_match_keys( $row ) : array {
        $keys = [
            $this->feed->col( $row, [ 'SKU', 'sku' ] ),
            $this->feed->col( $row, [ 'GTIN', 'gtin', 'EAN', 'ean', 'Key(GTIN/EAN/SKU)', 'Key', 'key' ] ),
        ];

        $clean = [];
        foreach ( $keys as $key ) {
            $key = trim( (string) $key );
            if ( '' !== $key ) {
                $clean[ $key ] = true;
            }
        }

        return array_keys( $clean );
    }

    private function diff_row( $r ) {
        // Produkt finden: zuerst Lieferanten-SKU, danach GTIN/EAN als Fallback.
        $feed_sku = '';
        $pid      = 0;
        foreach ( $this->get_feed_match_keys( $r ) as $candidate_sku ) {
            $candidate_id = $this->get_product_id_by_sku( $candidate_sku );
            if ( $candidate_id ) {
                $feed_sku = $candidate_sku;
                $pid      = $candidate_id;
                break;
            }
        }

        if ( ! $pid ) {
            return null;
        }

        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
            return null;
        }

        // Excluded?
        if ( $this->is_excluded( $pid ) ) {
            return null;
        }

        $diff = [
            'product_id'    => $pid,
            'feed_sku'      => (string) $feed_sku,
            'product_admin' => get_edit_post_link( $pid ),
        ];

        // 2) Vendor SKU = Lieferanten-Artikelnummer (erste Spalte "SKU" = CO-xxxxx)
        $csv_vendor_sku = $this->feed->col( $r, [ 'SKU', 'sku' ] );

        if ( $csv_vendor_sku !== '' && $csv_vendor_sku !== null ) {
            $diff['vendor_sku_feed'] = (string) $csv_vendor_sku;
            $cur = get_post_meta( $pid, '_vendor_sku', true );
            if ( (string) $cur !== (string) $csv_vendor_sku ) {
                $diff['vendor_sku'] = [
                    'old' => $cur,
                    'new' => $csv_vendor_sku,
                ];
            }
        }

        // 3) Stock
        $csv_stock = $this->feed->col( $r, [ 'Stock','stock','qty','quantity','Quantity' ] );
        if ( $csv_stock !== '' ) {
            $ni  = (int) $csv_stock;
            $cur = get_post_meta( $pid, '_stock', true );
            $ci  = (int) $cur;

            if ( $cur === '' || $ci !== $ni ) {
                $diff['stock'] = [
                    'old' => ( $cur === '' ? null : $ci ),
                    'new' => $ni,
                ];
            }
        }

        // 4) Purchase Price
        $csv_purchase = $this->feed->col( $r, [ 'Purchase Price','purchase_price','Einkaufspreis','cost','Cost' ] );
        if ( $csv_purchase !== '' ) {
            $cur = get_post_meta( $pid, '_purchase_price', true );
            if ( $cur === '' || (string) $cur !== (string) $csv_purchase ) {
                $diff['purchase_price'] = [
                    'old' => $cur,
                    'new' => $csv_purchase,
                ];
            }
        }

        $has_changes = isset( $diff['vendor_sku'] )
            || isset( $diff['stock'] )
            || isset( $diff['purchase_price'] );

        return $has_changes ? $diff : null;
    }
}

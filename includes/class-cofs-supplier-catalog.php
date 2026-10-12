<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Persistent, locked catalogue batches share the existing supplier stock engine. */
class COFS_Supplier_Catalog {
    const STATE = 'cofs_catalog_scan_state';
    const CACHE = 'cofs_catalog_items';
    const DEALER = 'cofs_thali_dealer_data';
    const STEP = 'cofs_catalog_scan_step';
    const OZII_RETAIL_MARKUP_PERCENT = 10;

    public static function categories() : array {
        $root = 'https://www.thali.ch/de/shop/haushalt/';
        return [
            'thali' => [ $root . 'kaffee/pads', $root . 'kaffeemaschinen/filterkaffeemaschine', $root . 'kaffeemaschinen/kaffeemuhle', $root . 'kaffeemaschinen/kaffeebereiter-manuell', $root . 'kaffeemaschinen/kaffeemaschinen-padsystem', $root . 'kaffeemaschinen/milchaufschaumer', $root . 'kaffeemaschinen/kapselmaschine', $root . 'kaffeemaschinen/kaffeemaschinen-zubehor' ],
            'oziicaffe' => [ 'https://www.oziicaffe.ch/product-category/maschines-a-cafe/aroma/', 'https://www.oziicaffe.ch/product-category/maschines-a-cafe/faber/', 'https://www.oziicaffe.ch/product-category/maschines-a-cafe/didiesse/', 'https://www.oziicaffe.ch/product-category/cafe/dosettes/' ],
        ];
    }

    public static function init() : void {
        add_action( self::STEP, [ __CLASS__, 'process_step' ] );
        add_action( 'admin_menu', [ __CLASS__, 'admin_menu' ] );
        add_action( 'admin_post_cofs_catalog_action', [ __CLASS__, 'admin_action' ] );
        add_action( 'wp_ajax_cofs_catalog_start', [ __CLASS__, 'ajax_start' ] );
        add_action( 'woocommerce_product_options_inventory_product_data', [ __CLASS__, 'product_fields' ] );
        add_action( 'woocommerce_admin_process_product_object', [ __CLASS__, 'save_fields' ] );
        $state = get_option( self::STATE, [] );
        if ( ! empty( $state['running'] ) ) self::schedule_step();
    }

    public static function unschedule() : void { wp_clear_scheduled_hook( self::STEP ); }

    private static function schedule_step() : void {
        if ( ! wp_next_scheduled( self::STEP ) ) wp_schedule_single_event( time() + 10, self::STEP );
    }

    private static function locked( callable $callback ) : array {
        global $wpdb;
        $name = 'cofs_catalog_' . md5( DB_NAME . '|' . $wpdb->prefix );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) return [ 'busy' => true ];
        try {
            wp_cache_delete( self::STATE, 'options' );
            wp_cache_delete( self::CACHE, 'options' );
            return $callback();
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
        }
    }

    public static function start_cycle() : array {
        return self::locked( static function() {
            $state = get_option( self::STATE, [] );
            if ( ! empty( $state['running'] ) ) { self::schedule_step(); return $state; }
            $settings = get_option( 'cofs_settings', [] );
            $listings = [];
            foreach ( self::categories() as $supplier => $urls ) {
                if ( empty( $settings[ $supplier . '_enabled' ] ) ) continue;
                foreach ( $urls as $url ) $listings[] = [ 'supplier' => $supplier, 'url' => $url ];
            }
            if ( ! $listings ) return [ 'running' => false ];
            $state = [ 'running' => true, 'started_at' => time(), 'phase' => 'discovery', 'listings' => $listings, 'seen_listings' => [], 'urls' => [], 'listed' => [], 'offset' => 0, 'errors' => [], 'discovery_errors' => [], 'missing' => [], 'synced' => 0, 'created' => 0 ];
            update_option( self::STATE, $state, false );
            self::schedule_step();
            return $state;
        } );
    }

    private static function fetch( string $supplier, string $url ) {
        if ( ! COFS_Catalog_Parser::valid_url( $supplier, $url ) ) return new WP_Error( 'cofs_catalog_url', 'Ungültige Lieferanten-URL.' );
        $response = wp_safe_remote_get( $url, [ 'timeout' => 25, 'redirection' => 3, 'user-agent' => 'webjungle Supplier Sync/' . COFS_VERSION . '; ' . home_url() ] );
        if ( is_wp_error( $response ) ) return $response;
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) return new WP_Error( 'cofs_catalog_http', 'HTTP ' . $code . ': ' . $url, [ 'status' => $code ] );
        return (string) wp_remote_retrieve_body( $response );
    }

    public static function process_step() : array {
        $result = self::locked( static function() {
            $state = get_option( self::STATE, [] );
            if ( empty( $state['running'] ) ) return $state;
            $cache = get_option( self::CACHE, [] );
            if ( ! is_array( $cache ) ) $cache = [];
            if ( 'discovery' === $state['phase'] ) {
                for ( $i = 0; $i < 3 && $state['listings']; $i++ ) {
                    $entry = array_shift( $state['listings'] );
                    $url = $entry['url']; $supplier = $entry['supplier'];
                    if ( isset( $state['seen_listings'][ $url ] ) ) continue;
                    $state['seen_listings'][ $url ] = true;
                    $html = self::fetch( $supplier, $url );
                    if ( is_wp_error( $html ) ) {
                        $state['discovery_errors'][ $supplier ] = true;
                        $state['errors'][] = $html->get_error_message();
                        continue;
                    }
                    $listing = COFS_Catalog_Parser::listing( $supplier, $html, $url );
                    if ( ! $listing['products'] ) {
                        $state['discovery_errors'][ $supplier ] = true;
                        $state['errors'][] = 'Keine Produkte im Katalog: ' . $url;
                        continue;
                    }
                    foreach ( $listing['products'] as $product_url ) {
                        $state['urls'][ $product_url ] = $supplier;
                        $state['listed'][ $product_url ] = true;
                    }
                    foreach ( $listing['pages'] as $page ) if ( ! isset( $state['seen_listings'][ $page ] ) && count( $state['seen_listings'] ) < 100 ) $state['listings'][] = [ 'supplier' => $supplier, 'url' => $page ];
                }
                if ( ! $state['listings'] ) {
                    $settings = get_option( 'cofs_settings', [] );
                    foreach ( $cache as $item ) if ( ! empty( $settings[ $item['supplier'] . '_enabled' ] ) && ! empty( $item['url'] ) ) $state['urls'][ $item['parent_url'] ?? $item['url'] ] = $item['supplier'];
                    foreach ( get_posts( [ 'post_type' => 'product', 'post_status' => [ 'publish', 'draft', 'private', 'pending' ], 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_thali_source_url' ] ) as $id ) {
                        $url = get_post_meta( $id, '_thali_source_url', true );
                        if ( ! empty( $settings['thali_enabled'] ) && COFS_Catalog_Parser::valid_url( 'thali', $url ) ) $state['urls'][ $url ] = 'thali';
                    }
                    $state['queue'] = [];
                    foreach ( $state['urls'] as $url => $supplier ) $state['queue'][] = [ 'url' => $url, 'supplier' => $supplier ];
                    $state['phase'] = 'products';
                }
            } else {
                foreach ( array_slice( $state['queue'], $state['offset'], 6 ) as $entry ) {
                    $url = $entry['url']; $supplier = $entry['supplier']; $key = md5( $url );
                    $html = self::fetch( $supplier, $url );
                    if ( is_wp_error( $html ) ) {
                        $data = (array) $html->get_error_data();
                        if ( in_array( $data['status'] ?? 0, [ 404, 410 ], true ) && empty( $state['discovery_errors'][ $supplier ] ) && empty( $state['listed'][ $url ] ) ) {
                            foreach ( $cache as $cached_key => $cached ) if ( ( $cached['parent_url'] ?? $cached['url'] ) === $url && $cached['supplier'] === $supplier ) $state['missing'][ $cached_key ] = true;
                        }
                        else $state['errors'][] = $html->get_error_message();
                    } else {
                        $items = COFS_Catalog_Parser::products( $supplier, $html, $url );
                        if ( is_wp_error( $items ) ) $state['errors'][] = $items->get_error_message();
                        else {
                            $seen_items = [];
                            foreach ( $items as $item ) {
                                $key = md5( $item['url'] ); $seen_items[ $key ] = true;
                                $item['listed'] = isset( $state['listed'][ $url ] );
                                $cache[ $key ] = $item;
                                try {
                                    $sync = self::sync_item( $item );
                                    $cache[ $key ]['product_id'] = $sync['product_id'];
                                    $state['synced'] += $sync['product_id'] ? 1 : 0;
                                    $state['created'] += ! empty( $sync['created'] ) ? 1 : 0;
                                } catch ( Throwable $error ) { $state['errors'][] = $url . ': ' . $error->getMessage(); }
                            }
                            // A successfully read parent confirms removal of a missing pack.
                            foreach ( $cache as $cached_key => $cached ) if ( ! empty( $cached['parent_url'] ) && $cached['parent_url'] === $url && ! isset( $seen_items[ $cached_key ] ) ) $state['missing'][ $cached_key ] = true;
                        }
                    }
                    $state['offset']++;
                }
                if ( $state['offset'] >= count( $state['queue'] ) ) {
                    self::mark_removed( $cache, $state );
                    $state['running'] = false;
                    $state['finished_at'] = time();
                    self::unschedule();
                }
            }
            update_option( self::CACHE, $cache, false );
            update_option( self::STATE, $state, false );
            if ( $state['running'] ) self::schedule_step();
            return $state;
        } );
        if ( ! empty( $result['busy'] ) ) self::schedule_step();
        return $result;
    }

    /** A shared URL change or temporary zero stock must never retire the product. */
    private static function mark_removed( array &$cache, array $state ) : void {
        foreach ( array_keys( $state['missing'] ) as $key ) {
            if ( empty( $cache[ $key ] ) ) continue;
            $item = $cache[ $key ]; $id = (int) ( $item['product_id'] ?? 0 );
            if ( ! $id ) continue;
            $fresh = false;
            foreach ( $cache as $other_key => $other ) if ( $other_key !== $key && empty( $other['removed'] ) && (int) ( $other['product_id'] ?? 0 ) === $id && $other['supplier'] === $item['supplier'] && (int) ( $other['updated_at'] ?? 0 ) >= $state['started_at'] ) $fresh = true;
            if ( $fresh ) continue;
            $item['removed'] = true; $item['stock'] = 0;
            $cache[ $key ] = $item;
            COFS_Multi_Supplier_Stock::sync_supplier_item( $id, $item );
            update_post_meta( $id, '_cofs_catalog_removed_at', time() );
            // Retirement is reversible. Automatic runs only unpublish confirmed
            // removed Thali Aroma articles that have no available replacement source.
            $product = wc_get_product( $id );
            if ( $product && 'thali' === $item['supplier'] && 'Aroma' === $item['brand'] && 'outofstock' === $product->get_stock_status() && 'publish' === $product->get_status() ) {
                $product->update_meta_data( '_cofs_previous_status', 'publish' );
                $product->set_status( 'draft' ); $product->save();
            }
        }
    }

    /** EAN is global; supplier SKUs and explicitly reviewed URL links are scoped. */
    public static function find_product( array $item ) : int {
        $supplier = $item['supplier'];
        $links = get_option( 'cofs_catalog_links', [] );
        $linked = (int) ( $links[ md5( $item['url'] ) ] ?? 0 );
        if ( $linked && wc_get_product( $linked ) && ! in_array( get_post_status( $linked ), [ 'trash', 'auto-draft' ], true ) ) return $linked;
        if ( ! empty( $item['ean'] ) ) {
            $id = wc_get_product_id_by_sku( $item['ean'] );
            if ( $id && ! in_array( get_post_status( $id ), [ 'trash', 'auto-draft' ], true ) ) return (int) $id;
            $matches = get_posts( [ 'post_type' => [ 'product', 'product_variation' ], 'post_status' => [ 'publish', 'draft', 'private', 'pending' ], 'numberposts' => 2, 'fields' => 'ids', 'meta_query' => [ 'relation' => 'OR', [ 'key' => '_global_unique_id', 'value' => $item['ean'] ], [ 'key' => '_product_gtin_data', 'value' => $item['ean'] ], [ 'key' => '_cofs_' . $supplier . '_ean', 'value' => $item['ean'] ] ] ] );
            if ( count( $matches ) === 1 ) return (int) $matches[0];
        }
        $matches = get_posts( [ 'post_type' => 'product', 'post_status' => [ 'publish', 'draft', 'private', 'pending' ], 'numberposts' => 2, 'fields' => 'ids', 'meta_key' => '_cofs_' . $supplier . '_url', 'meta_value' => $item['url'] ] );
        if ( count( $matches ) !== 1 ) return 0;
        $id = (int) $matches[0];
        $known = get_post_meta( $id, '_cofs_' . $supplier . '_ean', true );
        return ! empty( $item['ean'] ) && $known && $known !== $item['ean'] ? 0 : $id;
    }

    public static function sync_item( array $item, bool $create_unidentified = false ) : array {
        $supplier = $item['supplier'];
        if ( ! in_array( $supplier, [ 'thali', 'oziicaffe' ], true ) || ! COFS_Catalog_Parser::valid_url( $supplier, $item['url'] ) ) throw new InvalidArgumentException( 'Ungültige Quelle.' );
        $id = self::find_product( $item );
        if ( in_array( $id, array_map( 'absint', (array) ( get_option( 'cofs_settings', [] )['excluded_product_ids'] ?? [] ) ), true ) ) return [ 'product_id' => 0, 'created' => false ];
        if ( class_exists( 'COFS_Deleted_Feed_Items' ) && COFS_Deleted_Feed_Items::is_blocked( [ 'sku' => $item['ean'] ?? '', 'global_id' => $item['ean'] ?? '', 'vendor_sku' => $item['sku'], 'source_url' => $item['url'] ] ) ) return [ 'product_id' => 0, 'created' => false ];
        $created = ! $id;
        if ( $created && ! $create_unidentified && ( empty( $item['ean'] ) || ( 'oziicaffe' === $supplier && 'pads' === ( $item['product_kind'] ?? '' ) ) ) ) return [ 'product_id' => 0, 'created' => false ];
        $product = $id ? wc_get_product( $id ) : new WC_Product_Simple();
        if ( ! $product ) throw new RuntimeException( 'Produkt konnte nicht geladen werden.' );
        if ( $created ) {
            $product->set_name( $item['title'] );
            $product->set_status( 'draft' );
            if ( 'thali' === $supplier ) $product->update_meta_data( '_cofs_catalog_pending_publish', 'yes' );
            $product->set_sku( ! empty( $item['ean'] ) ? $item['ean'] : 'OZII-' . $item['sku'] );
            $id = $product->save();
        }
        $item = self::with_dealer_data( $item );
        if ( 'oziicaffe' === $supplier ) {
            $manual_cost = COFS_Catalog_Parser::decimal( (string) get_post_meta( $id, '_cofs_oziicaffe_purchase_price', true ) );
            if ( $manual_cost ) $item['purchase_price'] = $manual_cost;
        }
        foreach ( [ 'sku', 'ean', 'url', 'uvp' ] as $field ) if ( isset( $item[ $field ] ) ) $product->update_meta_data( '_cofs_' . $supplier . '_' . $field, $item[ $field ] );
        if ( 'oziicaffe' === $supplier ) {
            // Keep fresh supplier prices separate from the manually approved base.
            foreach ( [ 'retail_price', 'regular_retail_price' ] as $field ) if ( ! empty( $item[ $field ] ) ) $product->update_meta_data( '_cofs_oziicaffe_' . $field, $item[ $field ] );
            $product->update_meta_data( '_cofs_oziicaffe_retail_checked_at', (int) ( $item['updated_at'] ?? time() ) );
        }
        if ( ! empty( $item['purchase_price'] ) ) $product->update_meta_data( '_cofs_' . $supplier . '_purchase_price', $item['purchase_price'] );
        if ( 'thali' === $supplier ) {
            $product->update_meta_data( '_thali_source_url', $item['url'] );
            $product->update_meta_data( '_vendor_sku', $item['sku'] );
            $product->update_meta_data( '_cofs_content_supplier', 'thali' );
            $product->update_meta_data( '_cofs_uvp', $item['uvp'] );
            $product->update_meta_data( '_cofs_uvp_supplier', 'thali' );
            $product->update_meta_data( '_cofs_uvp_checked_at', time() );
            $product->update_meta_data( '_cofs_uvp_includes_tax', 'yes' );
            $product->set_name( $item['title'] );
            if ( ! empty( $item['description'] ) ) $product->set_description( wp_kses_post( $item['description'] ) );
            if ( ! empty( $item['short_description'] ) ) $product->set_short_description( wp_kses_post( $item['short_description'] ) );
            foreach ( [ '_global_unique_id', '_product_gtin_data', '_yoast_wpseo_gtin13' ] as $key ) $product->update_meta_data( $key, $item['ean'] );
            if ( method_exists( $product, 'set_global_unique_id' ) ) $product->set_global_unique_id( $item['ean'] );
            // Thali provides gross UVP; convert only for shops that store net prices.
            $uvp = $item['uvp'];
            if ( ! wc_prices_include_tax() && $product->is_taxable() ) $uvp = (float) $uvp - array_sum( WC_Tax::calc_tax( $uvp, WC_Tax::get_base_tax_rates( $product->get_tax_class() ), true ) );
            $product->set_regular_price( $uvp );
            $product->set_sale_price( '' );
            $product->set_date_on_sale_from( null );
            $product->set_date_on_sale_to( null );
            self::attributes( $product, $item );
        } elseif ( $created ) {
            $product->update_meta_data( '_cofs_retail_price_supplier', 'oziicaffe' );
            $product->update_meta_data( '_cofs_retail_base_price', $item['regular_retail_price'] ?? $item['retail_price'] ?? '' );
            $product->update_meta_data( '_cofs_retail_price_source', $item['url'] );
            $product->update_meta_data( '_cofs_retail_price_checked_at', (int) ( $item['updated_at'] ?? time() ) );
        }
        if ( 'oziicaffe' === $supplier ) self::apply_ozii_retail_price( $product );
        $product->save();
        if ( 'thali' === $supplier || $created ) {
            self::categories_for_product( $id, $item );
            self::images( $id, $item );
        }
        COFS_Multi_Supplier_Stock::sync_supplier_item( $id, $item, wc_get_product( $id ) );
        // Publication requires a verified public UVP and image, never a purchase cost.
        if ( 'thali' === $supplier && 'yes' === get_post_meta( $id, '_cofs_catalog_pending_publish', true ) && ! empty( $item['uvp'] ) && get_post_thumbnail_id( $id ) ) {
            $product = wc_get_product( $id ); $product->set_status( 'publish' ); $product->delete_meta_data( '_cofs_catalog_pending_publish' ); $product->save();
        }
        return [ 'product_id' => $id, 'created' => $created ];
    }

    /** Reuses the approved gross base, never the marked-up shop price or an EK. */
    public static function apply_ozii_retail_price( WC_Product $product ) : void {
        if ( 'oziicaffe' !== $product->get_meta( '_cofs_retail_price_supplier' ) || 'thali' === $product->get_meta( '_cofs_content_supplier' ) || 'thali' === $product->get_meta( '_cofs_uvp_supplier' ) ) return;
        $base = COFS_Catalog_Parser::decimal( (string) $product->get_meta( '_cofs_retail_base_price' ) );
        // Migrate previously approved Ozii prices once; subsequent runs use base.
        if ( ! $base && ! $product->get_meta( '_cofs_retail_markup_percent' ) ) $base = COFS_Catalog_Parser::decimal( (string) $product->get_meta( '_cofs_retail_price' ) );
        if ( ! $base ) return;
        $gross = number_format( round( (float) $base * ( 1 + self::OZII_RETAIL_MARKUP_PERCENT / 100 ), 2 ), 2, '.', '' );
        $price = $gross;
        if ( ! wc_prices_include_tax() && $product->is_taxable() ) $price = (float) $gross - array_sum( WC_Tax::calc_tax( $gross, WC_Tax::get_base_tax_rates( $product->get_tax_class() ), true ) );
        $product->update_meta_data( '_cofs_retail_base_price', $base );
        $product->update_meta_data( '_cofs_retail_markup_percent', self::OZII_RETAIL_MARKUP_PERCENT );
        $product->update_meta_data( '_cofs_retail_price', $gross );
        $product->update_meta_data( '_cofs_retail_price_includes_tax', 'yes' );
        $product->set_regular_price( $price );
        $product->set_sale_price( '' );
        $product->set_date_on_sale_from( null );
        $product->set_date_on_sale_to( null );
    }

    private static function with_dealer_data( array $item ) : array {
        if ( 'thali' !== $item['supplier'] ) return $item;
        $dealer = get_option( self::DEALER, [] );
        $row = $dealer[ $item['sku'] ] ?? [];
        if ( ! empty( $row['ean'] ) && $row['ean'] !== $item['ean'] ) return $item;
        if ( ! empty( $row['purchase_price'] ) ) {
            $item['purchase_price'] = $row['purchase_price'];
            $item['purchase_checked_at'] = (int) $row['checked_at'];
            $item['purchase_price_basis'] = 'net';
            $item['purchase_price_source'] = $row['source'] ?? 'dealer_import';
        }
        if ( isset( $row['stock'] ) && time() - (int) ( $row['checked_at'] ?? 0 ) < 10800 ) {
            $item['stock'] = max( 0, (int) $row['stock'] );
            $item['stock_mode'] = 'quantity';
            $item['stock_lower_bound'] = ! empty( $row['stock_lower_bound'] );
        }
        return $item;
    }

    /** CSV from Thali's documented export, or a captured dealer table with matching column names. */
    public static function import_dealer_rows( array $rows, string $source = 'dealer_import' ) : array {
        $dealer = get_option( self::DEALER, [] ); $count = 0;
        foreach ( $rows as $row ) {
            $sku = trim( (string) ( $row['sku'] ?? $row['Artikelnummer'] ?? '' ) );
            $price = COFS_Catalog_Parser::decimal( (string) ( $row['purchase_price'] ?? $row['Kunden-EP'] ?? '' ) );
            if ( ! preg_match( '/^\d{6}$/', $sku ) || ! $price ) continue;
            $entry = [ 'purchase_price' => $price, 'checked_at' => time(), 'source' => sanitize_key( $source ), 'ean' => trim( (string) ( $row['ean'] ?? $row['EAN'] ?? '' ) ) ];
            $stock = $row['stock'] ?? $row['Bestand'] ?? null;
            if ( null !== $stock && preg_match( '/^\d+\+?$/', (string) $stock ) ) { $entry['stock'] = (int) $stock; $entry['stock_lower_bound'] = false !== strpos( (string) $stock, '+' ); }
            $dealer[ $sku ] = $entry; $count++;
        }
        update_option( self::DEALER, $dealer, false );
        $synced = 0;
        foreach ( (array) get_option( self::CACHE, [] ) as $item ) if ( 'thali' === ( $item['supplier'] ?? '' ) && isset( $dealer[ $item['sku'] ] ) && self::sync_item( $item )['product_id'] ) $synced++;
        return [ 'imported' => $count, 'synced' => $synced ];
    }

    private static function attributes( WC_Product $product, array $item ) : void {
        $attributes = $product->get_attributes();
        $dealer_fields = [ 'Art.-Nr.', 'Hersteller Artikel Nr.', 'Mindestbestellmenge', 'Bestellschritte', 'Sprache' ];
        foreach ( $dealer_fields as $name ) unset( $attributes[ sanitize_title( $name ) ] );
        foreach ( (array) ( $item['attributes'] ?? [] ) as $name => $value ) {
            if ( in_array( $name, $dealer_fields, true ) ) continue;
            $attribute = new WC_Product_Attribute(); $attribute->set_name( $name ); $attribute->set_options( [ $value ] ); $attribute->set_visible( true );
            $attributes[ sanitize_title( $name ) ] = $attribute;
        }
        if ( preg_match( '/\b(\d+)\s*ESE\s*Pads/i', $item['title'], $match ) && taxonomy_exists( 'pa_stueck' ) ) {
            $attribute = new WC_Product_Attribute(); $attribute->set_id( wc_attribute_taxonomy_id_by_name( 'stueck' ) ); $attribute->set_name( 'pa_stueck' );
            $term = term_exists( $match[1], 'pa_stueck' ); if ( ! $term ) $term = wp_insert_term( $match[1], 'pa_stueck' );
            if ( ! is_wp_error( $term ) ) { $attribute->set_options( [ (int) ( is_array( $term ) ? $term['term_id'] : $term ) ] ); $attribute->set_visible( true ); $attributes['pa_stueck'] = $attribute; }
        }
        $product->set_attributes( $attributes );
    }

    private static function categories_for_product( int $id, array $item ) : void {
        $slugs = [];
        if ( 'thali' === $item['supplier'] ) {
            $map = [ '/kaffee/pads/' => [ 'e-s-e-pads' ], '/kaffeemaschinen-padsystem/' => [ 'kaffeemaschinen', 'padsmaschinen' ], '/filterkaffeemaschine/' => [ 'kaffeemaschinen', 'filterkaffeemaschinen' ], '/kaffeemuhle/' => [ 'kaffeemuehle' ], '/kaffeebereiter-manuell/' => [ 'kaffeebereiter-manuell' ], '/milchaufschaumer/' => [ 'milchaufschaeumer' ], '/kapselmaschine/' => [ 'kaffeemaschinen', 'kapselmaschinen' ], '/kaffeemaschinen-zubehor/' => [ 'kaffeemaschinen-zubehoer' ] ];
            foreach ( $map as $path => $values ) if ( false !== strpos( $item['url'], $path ) ) $slugs = array_merge( $slugs, $values );
        } else $slugs = 'pads' === ( $item['product_kind'] ?? '' ) ? [ 'e-s-e-pads' ] : [ 'kaffeemaschinen', 'padsmaschinen' ];
        if ( 'Aroma' === $item['brand'] ) {
            $slugs[] = 'pads' === ( $item['product_kind'] ?? '' ) || false !== strpos( $item['url'], '/kaffee/pads/' ) ? 'aroma-e-s-e-pads' : 'aroma-padsmaschine';
            if ( false !== stripos( $item['title'], 'Iconica' ) ) $slugs[] = 'aroma-iconica';
        }
        $new_categories = [ 'filterkaffeemaschinen' => [ 'Filterkaffeemaschinen', 'kaffeemaschinen' ], 'kaffeebereiter-manuell' => [ 'Manuelle Kaffeebereiter', 'kaffeemaschinen' ], 'kapselmaschinen' => [ 'Kapselmaschinen', 'kaffeemaschinen' ], 'kaffeemaschinen-zubehoer' => [ 'Kaffeemaschinen Zubehör', 'kaffeezubehoer' ] ];
        $ids = [];
        foreach ( $slugs as $slug ) {
            $term = get_term_by( 'slug', $slug, 'product_cat' );
            if ( ! $term && isset( $new_categories[ $slug ] ) ) {
                [ $name, $parent_slug ] = $new_categories[ $slug ]; $parent = get_term_by( 'slug', $parent_slug, 'product_cat' );
                $created = wp_insert_term( $name, 'product_cat', [ 'slug' => $slug, 'parent' => $parent ? $parent->term_id : 0 ] );
                if ( ! is_wp_error( $created ) ) $term = get_term( $created['term_id'], 'product_cat' );
            }
            if ( $term ) $ids[] = (int) $term->term_id;
        }
        if ( ! empty( $item['brand'] ) ) { $term = get_term_by( 'slug', sanitize_title( $item['brand'] ), 'product_cat' ); if ( $term ) $ids[] = (int) $term->term_id; }
        if ( $ids ) wp_set_object_terms( $id, $ids, 'product_cat', true );
    }

    private static function images( int $id, array $item ) : void {
        if ( empty( $item['images'] ) ) return;
        $supplier = $item['supplier']; $hash = md5( implode( '|', $item['images'] ) );
        if ( get_post_thumbnail_id( $id ) && $hash === get_post_meta( $id, '_cofs_' . $supplier . '_image_hash', true ) ) return;
        require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
        $ids = [];
        foreach ( array_slice( $item['images'], 0, 8 ) as $url ) {
            $known = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_cofs_catalog_image_url', 'meta_value' => $url ] );
            $image_id = $known ? (int) $known[0] : media_sideload_image( $url, $id, $item['title'], 'id' );
            if ( is_wp_error( $image_id ) ) continue;
            update_post_meta( $image_id, '_cofs_catalog_image_url', $url ); $ids[] = $image_id;
        }
        if ( ! $ids ) return;
        $product = wc_get_product( $id ); $product->set_image_id( array_shift( $ids ) ); $product->set_gallery_image_ids( $ids ); $product->save();
        update_post_meta( $id, '_cofs_' . $supplier . '_image_hash', $hash );
    }

    public static function register_settings_fields() : void {
        foreach ( [ 'thali' => 'Thali', 'oziicaffe' => 'Ozii Caffè' ] as $supplier => $label ) {
            add_settings_section( 'cofs_' . $supplier, $label, static function() use ( $supplier ) { echo '<p>' . ( 'thali' === $supplier ? 'Alle acht Kaffeekategorien. Führende Quelle für Produktdaten und UVP; Händler-EK wird separat mit Prüfdatum importiert.' : 'Aroma, Faber, Didiesse und E.S.E.-Pads inklusive Folgeseiten. Packungsvarianten werden getrennt geprüft; öffentliche Verkaufspreise sind keine Einkaufspreise oder UVP.' ) . '</p>'; }, 'cofs_settings' );
            add_settings_field( $supplier . '_catalog', 'Abgleich und Lager', static function() use ( $supplier ) {
                $settings = get_option( 'cofs_settings', [] );
                echo '<label><input type="checkbox" name="cofs_settings[' . esc_attr( $supplier ) . '_enabled]" value="1" ' . checked( ! empty( $settings[ $supplier . '_enabled' ] ), true, false ) . '> Automatischer Katalogabgleich</label><br>';
                wp_dropdown_categories( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'show_option_none' => '— Lager wählen —', 'option_none_value' => 0, 'name' => 'cofs_settings[' . $supplier . '_warehouse_term_id]', 'selected' => absint( $settings[ $supplier . '_warehouse_term_id' ] ?? 0 ) ] );
                echo '<p>Lager-Toleranz: <input type="number" min="0" name="cofs_settings[' . esc_attr( $supplier ) . '_stock_tolerance]" value="' . esc_attr( $settings[ $supplier . '_stock_tolerance' ] ?? 0 ) . '" class="small-text"> (nur bei bekanntem Mengenbestand)</p>';
            }, 'cofs_settings', 'cofs_' . $supplier );
        }
    }

    public static function admin_menu() : void {
        add_submenu_page( 'cofs_dashboard', 'Lieferantenkatalog', 'Thali / Ozii Katalog', 'manage_woocommerce', 'cofs_catalog', [ __CLASS__, 'admin_page' ] );
    }

    public static function ajax_start() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        check_ajax_referer( 'cofs_ajax', 'nonce' );
        wp_send_json_success( self::start_cycle() );
    }

    public static function admin_action() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Keine Berechtigung.' );
        check_admin_referer( 'cofs_catalog_action' );
        $action = sanitize_key( $_POST['catalog_action'] ?? '' );
        if ( 'scan' === $action ) self::start_cycle();
        if ( 'dealer' === $action ) {
            $text = trim( wp_unslash( $_POST['dealer_csv'] ?? '' ) ); $lines = preg_split( '/\r\n|\n|\r/', preg_replace( '/^\xEF\xBB\xBF/', '', $text ) );
            $header_line = array_shift( $lines ); $delimiter = strpos( $header_line, "\t" ) !== false ? "\t" : ( strpos( $header_line, ';' ) !== false ? ';' : ',' );
            $header = str_getcsv( $header_line, $delimiter ); $rows = [];
            foreach ( $lines as $line ) { $values = str_getcsv( $line, $delimiter ); if ( count( $values ) === count( $header ) ) $rows[] = array_combine( $header, $values ); }
            self::import_dealer_rows( $rows );
        }
        if ( 'link' === $action || 'create' === $action ) {
            $key = sanitize_key( $_POST['item_key'] ?? '' ); $cache = get_option( self::CACHE, [] ); $item = $cache[ $key ] ?? null;
            if ( $item ) {
                $id = absint( $_POST['product_id'] ?? 0 );
                if ( 'link' === $action && $id && wc_get_product( $id ) ) {
                    $links = get_option( 'cofs_catalog_links', [] ); $links[ $key ] = $id; update_option( 'cofs_catalog_links', $links, false );
                }
                if ( 'create' === $action || $id ) { $sync = self::sync_item( $item, 'create' === $action ); $cache[ $key ]['product_id'] = $sync['product_id']; update_option( self::CACHE, $cache, false ); }
            }
        }
        wp_safe_redirect( admin_url( 'admin.php?page=cofs_catalog' ) ); exit;
    }

    public static function admin_page() : void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        $state = get_option( self::STATE, [] ); $cache = get_option( self::CACHE, [] ); $dealer = get_option( self::DEALER, [] );
        echo '<div class="wrap"><h1>Lieferantenkatalog</h1><p>Priorität: Thali → CaffeOnline → Ozii Caffè → TopItaly. Die ausgewählte Quelle bestimmt Bestand, Lieferantenartikelnummer, Einkaufspreis und Lager. Thali führt die Produktdaten und UVP.</p>';
        echo '<p><strong>' . ( ! empty( $state['running'] ) ? 'Abgleich läuft im Hintergrund' : 'Letzter Abgleich abgeschlossen / bereit' ) . '</strong> · ' . (int) ( $state['offset'] ?? 0 ) . ' / ' . count( $state['queue'] ?? [] ) . ' Seiten · ' . (int) ( $state['synced'] ?? 0 ) . ' zugeordnet · ' . count( $state['errors'] ?? [] ) . ' Fehler</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'cofs_catalog_action' ); echo '<input type="hidden" name="action" value="cofs_catalog_action"><button class="button button-primary" name="catalog_action" value="scan">Thali und Ozii jetzt abgleichen</button></form>';
        if ( ! empty( $state['errors'] ) ) echo '<details><summary>Abruffehler (bestehende Daten bleiben erhalten)</summary><pre>' . esc_html( implode( "\n", array_slice( $state['errors'], -30 ) ) ) . '</pre></details>';
        echo '<h2>Thali Händlerpreise</h2><p>' . count( $dealer ) . ' Artikel mit geprüftem Einkaufspreis. Ohne Händlerfeed werden EK nicht automatisch aktualisiert. Öffentliche Thali-Seiten aktualisieren Verfügbarkeit und Produktdaten; genaue Mengen aus dem Händlerimport gelten drei Stunden.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'cofs_catalog_action' ); echo '<input type="hidden" name="action" value="cofs_catalog_action"><p>Thali-Artikelstamm (Tabulator/CSV) oder Spalten <code>sku,purchase_price,stock,ean</code>. Einkaufspreis in CHF ohne MwSt.</p><textarea name="dealer_csv" rows="6" class="large-text"></textarea><p><button class="button" name="catalog_action" value="dealer">Händlerdaten übernehmen</button></p></form>';
        echo '<h2>Produkte und Zuordnungen</h2><table class="widefat striped"><thead><tr><th>Quelle / Produkt</th><th>Artikel / EAN</th><th>UVP / Verkaufspreis Quelle</th><th>EK netto / Stand</th><th>Verfügbarkeit</th><th>Shop</th></tr></thead><tbody>';
        foreach ( $cache as $key => $item ) {
            $id = self::find_product( $item ); $row = 'thali' === $item['supplier'] ? ( $dealer[ $item['sku'] ] ?? [] ) : [];
            echo '<tr><td>' . esc_html( $item['supplier'] ) . '<br><a href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $item['title'] ) . '</a></td><td>' . esc_html( $item['sku'] . ' / ' . ( $item['ean'] ?: 'EAN fehlt' ) ) . '</td><td>' . esc_html( isset( $item['uvp'] ) ? 'UVP CHF ' . $item['uvp'] : 'Verkauf CHF ' . ( $item['retail_price'] ?? '' ) . ' (keine UVP)' ) . '</td><td>' . esc_html( ! empty( $row['purchase_price'] ) ? 'CHF ' . $row['purchase_price'] . ' · ' . wp_date( 'd.m.Y H:i', $row['checked_at'] ) : 'Nicht hinterlegt' ) . '</td><td>' . esc_html( ! empty( $item['removed'] ) ? 'Entfernt' : ( $item['stock'] > 0 ? 'Verfügbar' : 'Nicht verfügbar' ) ) . '</td><td>';
            if ( $id ) echo '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
            else {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'cofs_catalog_action' ); echo '<input type="hidden" name="action" value="cofs_catalog_action"><input type="hidden" name="item_key" value="' . esc_attr( $key ) . '"><select class="wc-product-search" name="product_id" style="width:240px" data-placeholder="Shopprodukt wählen" data-action="woocommerce_json_search_products_and_variations"></select><button class="button" name="catalog_action" value="link">Zuordnen</button><button class="button" name="catalog_action" value="create">Neu als Entwurf</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function product_fields() : void {
        global $post; if ( ! $post ) return;
        echo '<div class="options_group"><p class="form-field"><strong>Lieferanten Sync</strong></p>';
        foreach ( [ 'thali' => 'Thali', 'oziicaffe' => 'Ozii Caffè' ] as $supplier => $label ) {
            woocommerce_wp_text_input( [ 'id' => '_cofs_' . $supplier . '_url', 'label' => $label . ' Produkt-URL', 'value' => get_post_meta( $post->ID, '_cofs_' . $supplier . '_url', true ) ] );
            woocommerce_wp_text_input( [ 'id' => '_cofs_' . $supplier . '_purchase_price', 'label' => $label . ' EK CHF netto', 'data_type' => 'price', 'value' => get_post_meta( $post->ID, '_cofs_' . $supplier . '_purchase_price', true ) ] );
        }
        $sources = get_post_meta( $post->ID, COFS_Multi_Supplier_Stock::META_SOURCES, true );
        $uvp = get_post_meta( $post->ID, '_cofs_uvp', true );
        $retail = get_post_meta( $post->ID, '_cofs_retail_price', true );
        if ( 'oziicaffe' === get_post_meta( $post->ID, '_cofs_retail_price_supplier', true ) ) {
            woocommerce_wp_text_input( [ 'id' => '_cofs_retail_base_price', 'label' => 'Ozii Basispreis CHF inkl. MwSt.', 'data_type' => 'price', 'value' => get_post_meta( $post->ID, '_cofs_retail_base_price', true ), 'description' => 'Freigegebener Ozii-Endkundenpreis. Der Shoppreis enthält zusätzlich 10 % Aufschlag.', 'desc_tip' => true ] );
        }
        echo '<p class="form-field">Aktiver Lieferant: <strong>' . esc_html( get_post_meta( $post->ID, COFS_Multi_Supplier_Stock::META_ACTIVE, true ) ) . '</strong>';
        if ( $uvp ) echo '<br>UVP: CHF ' . esc_html( $uvp ) . ' inkl. MwSt.';
        elseif ( $retail ) echo '<br>Verkaufspreis: CHF ' . esc_html( $retail ) . ' inkl. MwSt. (Ozii Basispreis + ' . esc_html( get_post_meta( $post->ID, '_cofs_retail_markup_percent', true ) ?: '0' ) . ' %, keine bestätigte Hersteller-UVP)';
        echo '</p>';
        foreach ( (array) $sources as $supplier => $source ) if ( is_array( $source ) ) echo '<p class="form-field">' . esc_html( $supplier . ' · Artikel ' . ( $source['sku'] ?? '' ) . ' · EK ' . ( $source['purchase_price'] ?? 'fehlt' ) . ' · geprüft ' . ( ! empty( $source['purchase_checked_at'] ) ? wp_date( 'd.m.Y H:i', $source['purchase_checked_at'] ) : '—' ) ) . '</p>';
        echo '</div>';
    }

    public static function save_fields( $product ) : void {
        if ( ! $product instanceof WC_Product ) return;
        if ( 'oziicaffe' === $product->get_meta( '_cofs_retail_price_supplier' ) && isset( $_POST['_cofs_retail_base_price'] ) ) {
            $base = COFS_Catalog_Parser::decimal( wp_unslash( $_POST['_cofs_retail_base_price'] ) );
            if ( $base ) { $product->update_meta_data( '_cofs_retail_base_price', $base ); self::apply_ozii_retail_price( $product ); }
        }
        foreach ( [ 'thali', 'oziicaffe' ] as $supplier ) {
            $key = '_cofs_' . $supplier . '_url';
            if ( isset( $_POST[ $key ] ) ) { $url = esc_url_raw( wp_unslash( $_POST[ $key ] ) ); if ( ! $url || COFS_Catalog_Parser::valid_url( $supplier, $url ) ) $product->update_meta_data( $key, $url ); }
            $key = '_cofs_' . $supplier . '_purchase_price';
            if ( ! isset( $_POST[ $key ] ) ) continue;
            $price = COFS_Catalog_Parser::decimal( wp_unslash( $_POST[ $key ] ) ); $product->update_meta_data( $key, $price );
            if ( 'thali' === $supplier && $price && $price !== (string) get_post_meta( $product->get_id(), $key, true ) ) {
                $sku = $product->get_meta( '_cofs_thali_sku' );
                if ( $sku ) { $dealer = get_option( self::DEALER, [] ); $dealer[ $sku ] = [ 'purchase_price' => $price, 'checked_at' => time(), 'source' => 'manual', 'ean' => $product->get_meta( '_cofs_thali_ean' ) ]; update_option( self::DEALER, $dealer, false ); }
            }
            $sources = $product->get_meta( COFS_Multi_Supplier_Stock::META_SOURCES );
            if ( is_array( $sources ) && isset( $sources[ $supplier ] ) && $price !== (string) ( $sources[ $supplier ]['purchase_price'] ?? '' ) ) {
                $sources[ $supplier ]['purchase_price'] = $price; $sources[ $supplier ]['purchase_checked_at'] = time(); $sources[ $supplier ]['purchase_price_basis'] = 'net'; $sources[ $supplier ]['purchase_price_source'] = 'manual';
                $product->update_meta_data( COFS_Multi_Supplier_Stock::META_SOURCES, $sources );
                if ( $supplier === $product->get_meta( COFS_Multi_Supplier_Stock::META_ACTIVE ) ) { if ( $price ) $product->update_meta_data( '_purchase_price', $price ); else $product->delete_meta_data( '_purchase_price' ); }
            }
        }
    }
}

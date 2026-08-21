<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Keeps supplier-specific product data separate. CaffeOnline is always the
 * primary supplier; TopItaly is only used as a stock fallback.
 */
class COFS_Multi_Supplier_Stock {
    const CRON_HOOK       = 'cofs_multi_supplier_sync';
    const SCAN_HOOK       = 'cofs_topitaly_sitemap_scan';
    const TOPITALY_CACHE  = 'cofs_topitaly_supplier_cache';
    const TOPITALY_STATE  = 'cofs_topitaly_supplier_scan_state';
    const META_SOURCES    = '_cofs_supplier_sources';
    const META_ACTIVE     = '_cofs_active_supplier';
    const META_ACTIVE_SKU = '_cofs_active_supplier_sku';
    const META_TOPITALY_MATCH_BLOCKED = '_cofs_topitaly_match_blocked';

    public static function init() : void {
        add_action( self::CRON_HOOK, [ __CLASS__, 'cron_run' ] );
        add_action( self::SCAN_HOOK, [ __CLASS__, 'process_topitaly_scan' ] );
        add_action( 'woocommerce_product_options_inventory_product_data', [ __CLASS__, 'product_fields' ] );
        add_action( 'woocommerce_admin_process_product_object', [ __CLASS__, 'save_product_fields' ] );
    }

    public static function schedule() : void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 120, 'cofs_every_three_hours', self::CRON_HOOK );
        }
    }

    public static function unschedule() : void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_clear_scheduled_hook( self::SCAN_HOOK );
    }

    public static function is_enabled() : bool {
        $settings = get_option( 'cofs_settings', [] );
        return ! empty( $settings['topitaly_enabled'] ) && ! empty( $settings['topitaly_sitemap_url'] );
    }

    public static function register_settings_fields() : void {
        add_settings_section(
            'cofs_topitaly',
            __( 'TopItaly Lieferant', 'caffeonline-feed-sync' ),
            function() {
                echo '<p>' . esc_html__( 'TopItaly wird über die Produktsitemap eingelesen. Die Quelle bleibt je Produkt getrennt von CaffeOnline gespeichert; CaffeOnline bleibt primär, TopItaly ist nur der Fallback bei effektivem CaffeOnline-Bestand 0.', 'caffeonline-feed-sync' ) . '</p>';
            },
            'cofs_settings'
        );

        add_settings_field( 'topitaly_enabled', __( 'TopItaly aktivieren', 'caffeonline-feed-sync' ), [ __CLASS__, 'field_enabled' ], 'cofs_settings', 'cofs_topitaly' );
        add_settings_field( 'topitaly_sitemap_url', __( 'TopItaly Sitemap URL', 'caffeonline-feed-sync' ), [ __CLASS__, 'field_sitemap_url' ], 'cofs_settings', 'cofs_topitaly' );
        add_settings_field( 'topitaly_batch_size', __( 'TopItaly Seiten pro Hintergrundlauf', 'caffeonline-feed-sync' ), [ __CLASS__, 'field_batch_size' ], 'cofs_settings', 'cofs_topitaly' );
        add_settings_field( 'caffeonline_warehouse_term_id', __( 'CaffeOnline Lager-Kategorie', 'caffeonline-feed-sync' ), [ __CLASS__, 'field_caffeonline_warehouse' ], 'cofs_settings', 'cofs_topitaly' );
        add_settings_field( 'caffeonline_stock_tolerance', __( 'CaffeOnline Lager-Toleranz', 'caffeonline-feed-sync' ), [ __CLASS__, 'field_caffeonline_stock_tolerance' ], 'cofs_settings', 'cofs_topitaly' );
        add_settings_field( 'topitaly_warehouse_term_id', __( 'TopItaly Lager-Kategorie', 'caffeonline-feed-sync' ), [ __CLASS__, 'field_topitaly_warehouse' ], 'cofs_settings', 'cofs_topitaly' );
        add_settings_field( 'topitaly_stock_tolerance', __( 'TopItaly Lager-Toleranz', 'caffeonline-feed-sync' ), [ __CLASS__, 'field_topitaly_stock_tolerance' ], 'cofs_settings', 'cofs_topitaly' );
    }

    public static function field_enabled() : void {
        $settings = get_option( 'cofs_settings', [] );
        echo '<label><input type="checkbox" name="cofs_settings[topitaly_enabled]" value="1" ' . checked( ! empty( $settings['topitaly_enabled'] ), true, false ) . ' /> ' . esc_html__( 'Sitemap-Scan und intelligente Lieferantenauswahl aktivieren', 'caffeonline-feed-sync' ) . '</label>';
    }

    public static function field_sitemap_url() : void {
        $settings = get_option( 'cofs_settings', [] );
        $value = isset( $settings['topitaly_sitemap_url'] ) ? (string) $settings['topitaly_sitemap_url'] : 'https://www.topitaly.ch/sitemap.xml';
        echo '<input type="url" class="regular-text ltr" name="cofs_settings[topitaly_sitemap_url]" value="' . esc_attr( $value ) . '" />';
    }

    public static function field_batch_size() : void {
        $settings = get_option( 'cofs_settings', [] );
        $value = max( 1, min( 30, (int) ( $settings['topitaly_batch_size'] ?? 12 ) ) );
        echo '<input type="number" min="1" max="30" class="small-text" name="cofs_settings[topitaly_batch_size]" value="' . esc_attr( (string) $value ) . '" />';
        echo '<p class="description">' . esc_html__( 'Die Seiten eines Batches werden parallel geladen, damit grosse Sitemaps den Server nicht blockieren.', 'caffeonline-feed-sync' ) . '</p>';
    }

    public static function field_caffeonline_warehouse() : void {
        self::warehouse_select_field( 'caffeonline' );
    }

    public static function field_topitaly_warehouse() : void {
        self::warehouse_select_field( 'topitaly' );
    }

    public static function field_caffeonline_stock_tolerance() : void {
        self::warehouse_tolerance_field( 'caffeonline' );
    }

    public static function field_topitaly_stock_tolerance() : void {
        self::warehouse_tolerance_field( 'topitaly' );
    }

    private static function warehouse_select_field( string $supplier ) : void {
        $settings = get_option( 'cofs_settings', [] );
        $key = $supplier . '_warehouse_term_id';
        wp_dropdown_categories( [
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'show_option_none' => __( '— Keine automatische Lager-Kategorie —', 'caffeonline-feed-sync' ),
            'option_none_value' => 0,
            'name' => 'cofs_settings[' . $key . ']',
            'selected' => absint( $settings[ $key ] ?? 0 ),
        ] );
        echo '<p class="description">' . esc_html__( 'Wird dem Produkt automatisch zugeordnet, solange diese Quelle der aktive Lieferant ist.', 'caffeonline-feed-sync' ) . '</p>';
    }

    /** Stock at or below this value is deliberately treated as unavailable for this source. */
    private static function warehouse_tolerance_field( string $supplier ) : void {
        $settings = get_option( 'cofs_settings', [] );
        $key = $supplier . '_stock_tolerance';
        $warehouse_id = absint( $settings[ $supplier . '_warehouse_term_id' ] ?? 0 );
        $warehouse = $warehouse_id ? get_term( $warehouse_id, 'product_cat' ) : null;
        $warehouse_name = $warehouse && ! is_wp_error( $warehouse ) ? $warehouse->name : __( 'diesem Lager', 'caffeonline-feed-sync' );
        $value = max( 0, (int) ( $settings[ $key ] ?? 0 ) );

        echo '<input type="number" min="0" step="1" class="small-text" name="cofs_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '" />';
        echo '<p class="description">' . sprintf( esc_html__( 'Bestand kleiner oder gleich diesem Wert gilt für %s als 0. Der Rohbestand bleibt je Quelle gespeichert.', 'caffeonline-feed-sync' ), esc_html( $warehouse_name ) ) . '</p>';
    }

    public static function product_fields() : void {
        global $post;
        if ( ! $post ) return;
        echo '<div class="options_group">';
        echo '<p class="form-field"><strong>' . esc_html__( 'TopItaly Lieferant', 'caffeonline-feed-sync' ) . '</strong><br><span class="description">' . esc_html__( 'Diese Werte gehören ausschliesslich zu TopItaly und ersetzen nie die Shop-SKU.', 'caffeonline-feed-sync' ) . '</span></p>';
        woocommerce_wp_text_input( [ 'id' => '_cofs_topitaly_ean', 'label' => __( 'TopItaly EAN', 'caffeonline-feed-sync' ), 'value' => get_post_meta( $post->ID, '_cofs_topitaly_ean', true ) ] );
        woocommerce_wp_text_input( [ 'id' => '_cofs_topitaly_sku', 'label' => __( 'TopItaly SKU', 'caffeonline-feed-sync' ), 'value' => get_post_meta( $post->ID, '_cofs_topitaly_sku', true ) ] );
        woocommerce_wp_text_input( [ 'id' => '_cofs_topitaly_purchase_price', 'label' => __( 'TopItaly Einkaufspreis (CHF)', 'caffeonline-feed-sync' ), 'data_type' => 'price', 'value' => get_post_meta( $post->ID, '_cofs_topitaly_purchase_price', true ), 'description' => __( 'Manuell gepflegt; wird nur übernommen, wenn TopItaly der aktive Lieferant ist.', 'caffeonline-feed-sync' ), 'desc_tip' => true ] );
        woocommerce_wp_checkbox( [ 'id' => self::META_TOPITALY_MATCH_BLOCKED, 'label' => __( 'TopItaly-Zuordnung sperren', 'caffeonline-feed-sync' ), 'description' => __( 'Verhindert, dass ein TopItaly-Artikel mit abweichender Packungsgrösse diesem Shopprodukt erneut zugeordnet wird.', 'caffeonline-feed-sync' ) ] );
        echo '</div>';
    }

    public static function save_product_fields( $product ) : void {
        if ( ! $product instanceof WC_Product ) return;
        foreach ( [ '_cofs_topitaly_ean', '_cofs_topitaly_sku' ] as $key ) {
            if ( isset( $_POST[ $key ] ) ) $product->update_meta_data( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
        }
        if ( isset( $_POST['_cofs_topitaly_purchase_price'] ) ) {
            $product->update_meta_data( '_cofs_topitaly_purchase_price', wc_format_decimal( wp_unslash( $_POST['_cofs_topitaly_purchase_price'] ) ) );
        }
        if ( isset( $_POST[ self::META_TOPITALY_MATCH_BLOCKED ] ) ) {
            $product->update_meta_data( self::META_TOPITALY_MATCH_BLOCKED, 'yes' );
        } else {
            $product->delete_meta_data( self::META_TOPITALY_MATCH_BLOCKED );
        }
    }

    public static function cron_run() : void {
        self::sync_caffeonline_feed();
        if ( self::is_enabled() ) self::process_topitaly_scan();
    }

    public static function start_topitaly_scan( bool $schedule_fallback = true ) : array {
        if ( ! self::is_enabled() ) return [ 'ok' => false, 'message' => __( 'TopItaly ist noch nicht aktiviert oder die Sitemap-URL fehlt.', 'caffeonline-feed-sync' ) ];
        $urls = self::discover_topitaly_urls();
        if ( is_wp_error( $urls ) ) return [ 'ok' => false, 'message' => $urls->get_error_message() ];

        update_option( self::TOPITALY_STATE, [ 'urls' => array_values( $urls ), 'offset' => 0, 'total' => count( $urls ), 'matched_items' => 0, 'error_count' => 0, 'last_errors' => [], 'started_at' => time(), 'updated_at' => time() ], false );
        if ( $schedule_fallback ) wp_schedule_single_event( time() + 5, self::SCAN_HOOK );
        return [ 'ok' => true, 'total' => count( $urls ) ];
    }

    public static function get_topitaly_state() : array {
        $state = get_option( self::TOPITALY_STATE, [] );
        return is_array( $state ) ? $state : [];
    }

    public static function process_topitaly_scan() : array {
        $state = self::get_topitaly_state();
        $urls = isset( $state['urls'] ) && is_array( $state['urls'] ) ? $state['urls'] : [];
        $offset = max( 0, (int) ( $state['offset'] ?? 0 ) );
        if ( empty( $urls ) || $offset >= count( $urls ) ) return [ 'processed' => 0, 'offset' => $offset, 'total' => count( $urls ), 'errors' => [], 'last_errors' => (array) ( $state['last_errors'] ?? [] ), 'matched_total' => (int) ( $state['matched_items'] ?? 0 ), 'error_count' => (int) ( $state['error_count'] ?? 0 ), 'finished' => true ];

        $settings = get_option( 'cofs_settings', [] );
        $batch_size = max( 1, min( 30, (int) ( $settings['topitaly_batch_size'] ?? 12 ) ) );
        $batch = array_slice( $urls, $offset, $batch_size );
        $fetched = self::fetch_many( $batch );
        $pages   = $fetched['pages'];
        $errors  = $fetched['errors'];
        $cache = get_option( self::TOPITALY_CACHE, [] );
        if ( ! is_array( $cache ) ) $cache = [];
        $matched = 0;

        foreach ( $pages as $url => $html ) {
            $item = self::parse_topitaly_product( $url, $html );
            if ( ! $item ) continue;
            $item['updated_at'] = time();
            $cache[ $item['ean'] ] = $item;
            self::sync_topitaly_item( $item );
            $matched++;
        }

        update_option( self::TOPITALY_CACHE, $cache, false );
        $state['offset'] = min( count( $urls ), $offset + count( $batch ) );
        $state['updated_at'] = time();
        $state['matched_items'] = (int) ( $state['matched_items'] ?? 0 ) + $matched;
        $state['error_count'] = (int) ( $state['error_count'] ?? 0 ) + count( $errors );
        $state['last_errors'] = array_slice( array_merge( (array) ( $state['last_errors'] ?? [] ), $errors ), -10 );
        update_option( self::TOPITALY_STATE, $state, false );

        if ( $state['offset'] < count( $urls ) ) wp_schedule_single_event( time() + 10, self::SCAN_HOOK );
        return [ 'processed' => count( $batch ), 'offset' => (int) $state['offset'], 'total' => count( $urls ), 'matched' => $matched, 'matched_total' => (int) $state['matched_items'], 'errors' => $errors, 'last_errors' => (array) $state['last_errors'], 'error_count' => (int) $state['error_count'], 'finished' => $state['offset'] >= count( $urls ) ];
    }

    private static function discover_topitaly_urls() {
        $settings = get_option( 'cofs_settings', [] );
        $root = trim( (string) ( $settings['topitaly_sitemap_url'] ?? '' ) );
        if ( '' === $root ) return new WP_Error( 'cofs_topitaly_sitemap_missing', __( 'TopItaly Sitemap-URL fehlt.', 'caffeonline-feed-sync' ) );

        $xml = self::fetch_url( $root );
        if ( is_wp_error( $xml ) ) return $xml;
        $sitemaps = self::xml_locations( $xml );
        $urls = [];
        foreach ( $sitemaps as $sitemap ) {
            $body = self::fetch_url( $sitemap );
            if ( is_wp_error( $body ) ) continue;
            foreach ( self::xml_locations( $body ) as $url ) {
                if ( false === strpos( $url, '/account/' ) && false === strpos( $url, '/checkout/' ) && false === strpos( $url, '/widgets/' ) ) $urls[ esc_url_raw( $url ) ] = true;
            }
        }
        return array_keys( $urls );
    }

    private static function xml_locations( string $body ) : array {
        if ( 0 === strpos( $body, "\x1f\x8b" ) && function_exists( 'gzdecode' ) ) $body = (string) @gzdecode( $body );
        preg_match_all( '#<loc>\s*(.*?)\s*</loc>#si', $body, $matches );
        if ( empty( $matches[1] ) || ! is_array( $matches[1] ) ) return [];
        return array_values( array_filter( array_map( static function( $url ) {
            return trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
        }, $matches[1] ) ) );
    }

    private static function fetch_url( string $url ) {
        $response = wp_remote_get( $url, [ 'timeout' => 30, 'redirection' => 3, 'headers' => [ 'Accept-Encoding' => 'gzip' ], 'user-agent' => 'CaffeOnline-Feed-Sync/0.5 (+ ' . home_url() . ')' ] );
        if ( is_wp_error( $response ) ) return $response;
        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) return new WP_Error( 'cofs_topitaly_http', sprintf( __( 'TopItaly lieferte HTTP %d.', 'caffeonline-feed-sync' ), (int) wp_remote_retrieve_response_code( $response ) ) );
        return (string) wp_remote_retrieve_body( $response );
    }

    private static function fetch_many( array $urls ) : array {
        $result = [ 'pages' => [], 'errors' => [] ];
        $requests_class = class_exists( '\\WpOrg\\Requests\\Requests' ) ? '\\WpOrg\\Requests\\Requests' : ( class_exists( 'Requests' ) ? 'Requests' : '' );
        if ( $requests_class && method_exists( $requests_class, 'request_multiple' ) ) {
            $requests = [];
            foreach ( $urls as $url ) $requests[ $url ] = [ 'url' => $url, 'type' => 'GET', 'headers' => [ 'User-Agent' => 'CaffeOnline-Feed-Sync/0.5' ], 'options' => [ 'timeout' => 30 ] ];
            $responses = call_user_func( [ $requests_class, 'request_multiple' ], $requests );
            foreach ( $responses as $url => $response ) {
                if ( is_object( $response ) && isset( $response->status_code, $response->body ) && 200 === (int) $response->status_code ) {
                    $result['pages'][ $url ] = (string) $response->body;
                    continue;
                }
                $reason = is_wp_error( $response ) ? $response->get_error_message() : ( is_object( $response ) && isset( $response->status_code ) ? 'HTTP ' . (int) $response->status_code : __( 'Unbekannter Abruffehler.', 'caffeonline-feed-sync' ) );
                $result['errors'][] = [ 'url' => (string) $url, 'message' => $reason ];
            }
            return $result;
        }
        foreach ( $urls as $url ) {
            $body = self::fetch_url( $url );
            if ( ! is_wp_error( $body ) ) {
                $result['pages'][ $url ] = $body;
            } else {
                $result['errors'][] = [ 'url' => (string) $url, 'message' => $body->get_error_message() ];
            }
        }
        return $result;
    }

    private static function parse_topitaly_product( string $url, string $html ) : ?array {
        if ( ! class_exists( 'DOMDocument' ) ) return null;
        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        if ( ! $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html ) ) return null;
        $xpath = new DOMXPath( $dom );
        $text = static function( $query ) use ( $xpath ) {
            $node = $xpath->query( $query )->item( 0 );
            return $node ? trim( preg_replace( '/\s+/u', ' ', $node->textContent ) ) : '';
        };
        $ean = preg_replace( '/\D+/', '', $text( '//*[contains(concat(" ", normalize-space(@class), " "), " ft-product-detail-ean ")]' ) );
        $sku = $text( '//*[contains(concat(" ", normalize-space(@class), " "), " product-detail-ordernumber ")]' );
        if ( '' === $ean || '' === $sku ) return null;
        $stock_node = $xpath->query( '//input[contains(concat(" ", normalize-space(@class), " "), " quantity-selector-group-input ")]' )->item( 0 );
        $stock = $stock_node ? max( 0, (int) $stock_node->getAttribute( 'max' ) ) : 0;
        $price = self::decimal_from_text( $text( '//*[contains(concat(" ", normalize-space(@class), " "), " product-detail-price ")]' ) );
        $title = $text( '//h1' );
        $description = $text( '//*[contains(concat(" ", normalize-space(@class), " "), " product-detail-description-text ")]' );
        $categories = [];
        foreach ( $xpath->query( '//a[contains(concat(" ", normalize-space(@class), " "), " breadcrumb-link ")]' ) as $breadcrumb ) {
            $label = trim( preg_replace( '/\s+/u', ' ', $breadcrumb->textContent ) );
            if ( '' !== $label ) $categories[] = $label;
        }
        $images = [];
        foreach ( $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " product-detail-media ")]//img[contains(concat(" ", normalize-space(@class), " "), " gallery-slider-image ")]' ) as $image ) {
            $image_url = trim( (string) ( $image->getAttribute( 'data-full-image' ) ?: $image->getAttribute( 'src' ) ?: $image->getAttribute( 'data-src' ) ) );
            $image_url = str_replace( ' ', '%20', $image_url );
            if ( '' !== $image_url && false !== strpos( $image_url, 'topitaly.ch/' ) ) $images[] = esc_url_raw( $image_url );
        }
        return [ 'supplier' => 'topitaly', 'url' => esc_url_raw( $url ), 'ean' => $ean, 'sku' => $sku, 'stock' => $stock, 'uvp' => $price, 'title' => $title, 'description' => $description, 'categories' => array_values( array_unique( $categories ) ), 'images' => array_values( array_unique( $images ) ) ];
    }

    private static function decimal_from_text( string $value ) : string {
        $value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
        if ( ! preg_match( '/[0-9][0-9\s\x{00A0}\.,]*/u', $value, $match ) ) return '';
        $number = preg_replace( '/[\s\x{00A0}]/u', '', $match[0] );
        $comma = strrpos( $number, ',' );
        $dot = strrpos( $number, '.' );

        if ( false !== $comma && false !== $dot ) {
            $decimal_at = max( $comma, $dot );
            $integer = preg_replace( '/[\.,]/', '', substr( $number, 0, $decimal_at ) );
            $fraction = preg_replace( '/\D/', '', substr( $number, $decimal_at + 1 ) );
            $number = $integer . '.' . $fraction;
        } elseif ( false !== $comma ) {
            $number = str_replace( '.', '', $number );
            $number = str_replace( ',', '.', $number );
        }

        if ( ! is_numeric( $number ) ) return '';
        return function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $number ) : (string) (float) $number;
    }

    public static function sync_caffeonline_feed() : array {
        $settings = get_option( 'cofs_settings', [] );
        $url = trim( (string) ( $settings['feed_url'] ?? '' ) );
        if ( '' === $url ) return self::empty_result();
        $feed = new COFS_Feed( $url );
        $rows = $feed->get_rows();
        return is_wp_error( $rows ) ? self::empty_result() : self::sync_caffeonline_rows( $feed, $rows );
    }

    public static function sync_caffeonline_rows( $feed, array $rows ) : array {
        $result = self::empty_result();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $ean = trim( (string) $feed->col( $row, [ 'GTIN', 'gtin', 'EAN', 'ean', 'Key(GTIN/EAN/SKU)', 'Key', 'key' ] ) );
            $sku = trim( (string) $feed->col( $row, [ 'SKU', 'sku' ] ) );
            $stock = $feed->col( $row, [ 'Stock', 'stock', 'qty', 'quantity', 'Quantity' ] );
            if ( '' === $ean && '' === $sku ) continue;
            $product_id = self::find_product_id( [ $ean, $sku ] );
            if ( ! $product_id ) continue;
            $candidate = [ 'supplier' => 'caffeonline', 'sku' => $sku, 'ean' => $ean, 'stock' => '' === $stock ? 0 : max( 0, (int) $stock ), 'purchase_price' => self::decimal_from_text( (string) $feed->col( $row, [ 'Purchase', 'purchase', 'Purchase Price', 'purchase_price', 'Einkaufspreis', 'EK', 'Cost', 'cost' ] ) ), 'updated_at' => time() ];
            self::apply_supplier_candidate( $product_id, $candidate, $result );
        }
        return $result;
    }

    private static function sync_topitaly_item( array $item ) : void {
        if (
            class_exists( 'COFS_Deleted_Feed_Items' )
            && COFS_Deleted_Feed_Items::is_blocked(
                [
                    'sku'        => (string) ( $item['sku'] ?? '' ),
                    'global_id'  => (string) ( $item['ean'] ?? '' ),
                    'vendor_sku' => (string) ( $item['sku'] ?? '' ),
                    'source_url' => (string) ( $item['url'] ?? '' ),
                ]
            )
        ) {
            return;
        }
        if ( self::is_excluded_topitaly_item( $item ) ) {
            self::remove_excluded_topitaly_product( $item );
            return;
        }
        $product_id = self::find_product_id( [ $item['ean'] ?? '', $item['sku'] ?? '' ] );
        if ( $product_id && self::is_topitaly_match_blocked( $product_id ) ) {
            self::remove_topitaly_source_from_shared_product( $product_id );
            return;
        }
        if ( ! $product_id ) {
            $product_id = self::create_topitaly_product( $item );
        }
        if ( ! $product_id ) return;
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;
        $purchase = self::decimal_from_text( (string) get_post_meta( $product_id, '_cofs_topitaly_purchase_price', true ) );
        $item['purchase_price'] = $purchase;
        update_post_meta( $product_id, '_cofs_topitaly_ean', (string) $item['ean'] );
        update_post_meta( $product_id, '_cofs_topitaly_sku', (string) $item['sku'] );
        update_post_meta( $product_id, '_cofs_topitaly_stock', max( 0, (int) ( $item['stock'] ?? 0 ) ) );
        update_post_meta( $product_id, '_cofs_topitaly_uvp', (string) $item['uvp'] );
        self::sync_topitaly_gtin_fields( $product, (string) $item['ean'] );
        self::assign_topitaly_categories( $product_id, $item );
        self::apply_topitaly_presentation( $product, $item );
        self::sync_topitaly_images( $product_id, $item );
        if ( 'yes' === get_post_meta( $product_id, '_cofs_topitaly_only', true ) && '' !== (string) $item['uvp'] ) {
            $product->set_regular_price( (string) $item['uvp'] );
        }
        if ( '' === (string) $product->get_short_description( 'edit' ) && ! empty( $item['description'] ) ) $product->set_short_description( wp_kses_post( $item['description'] ) );
        $result = self::empty_result();
        self::apply_supplier_candidate( $product_id, $item, $result, $product );
    }

    /** Returns whether a known pack-size mismatch must never be linked to TopItaly again. */
    private static function is_topitaly_match_blocked( int $product_id ) : bool {
        return 'yes' === get_post_meta( $product_id, self::META_TOPITALY_MATCH_BLOCKED, true );
    }

    /** Blocks a shared product from TopItaly and immediately removes that source from its stock calculation. */
    public static function block_topitaly_match( int $product_id ) : bool {
        if ( ! wc_get_product( $product_id ) ) return false;
        update_post_meta( $product_id, self::META_TOPITALY_MATCH_BLOCKED, 'yes' );
        self::remove_topitaly_source_from_shared_product( $product_id );
        return true;
    }

    /** Removes a deleted TopItaly source while preserving any valid alternate supplier. */
    public static function remove_stale_topitaly_source( int $product_id ) : bool {
        if ( ! wc_get_product( $product_id ) ) return false;
        self::remove_topitaly_source_from_shared_product( $product_id );
        return true;
    }

    /** TopItaly departments that must never become shop products. */
    private static function is_excluded_topitaly_item( array $item ) : bool {
        $excluded = [ 'grundzutaten', 'waschmittel hygiene', 'waschmittel und hygiene', 'spielzeug' ];
        foreach ( (array) ( $item['categories'] ?? [] ) as $category ) {
            if ( in_array( self::normalize_category_label( (string) $category ), $excluded, true ) ) return true;
        }
        return false;
    }

    /** Permanently removes only products which were created exclusively from the excluded TopItaly source. */
    private static function remove_excluded_topitaly_product( array $item ) : void {
        $product_id = self::find_product_id( [ $item['ean'] ?? '', $item['sku'] ?? '' ] );
        if ( ! $product_id ) return;

        if ( 'yes' !== get_post_meta( $product_id, '_cofs_topitaly_only', true ) ) {
            self::remove_topitaly_source_from_shared_product( $product_id );
            return;
        }

        self::delete_product_images( $product_id );
        $product = wc_get_product( $product_id );
        if ( $product ) $product->delete( true );
    }

    /** A shared CaffeOnline product stays in the shop, but can no longer fall back to an excluded TopItaly item. */
    private static function remove_topitaly_source_from_shared_product( int $product_id ) : void {
        $sources = get_post_meta( $product_id, self::META_SOURCES, true );
        if ( ! is_array( $sources ) ) $sources = [];
        unset( $sources['topitaly'] );
        update_post_meta( $product_id, self::META_SOURCES, $sources );
        delete_post_meta( $product_id, '_cofs_topitaly_ean' );
        delete_post_meta( $product_id, '_cofs_topitaly_sku' );
        delete_post_meta( $product_id, '_cofs_topitaly_stock' );
        delete_post_meta( $product_id, '_cofs_topitaly_uvp' );

        $active = self::choose_active_supplier( $sources, '' );
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;
        if ( ! $active || empty( $sources[ $active ] ) ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( 0 );
            $product->set_stock_status( 'outofstock' );
            $product->delete_meta_data( self::META_ACTIVE );
            $product->delete_meta_data( self::META_ACTIVE_SKU );
            $product->save();
            self::sync_active_supplier_warehouse( $product_id, '' );
            return;
        }
        $data = $sources[ $active ];
        $stock = self::effective_supplier_stock( $active, $data );
        $product->set_manage_stock( true );
        $product->set_stock_quantity( $stock );
        $product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
        $product->update_meta_data( self::META_ACTIVE, $active );
        $product->update_meta_data( self::META_ACTIVE_SKU, (string) ( $data['sku'] ?? '' ) );
        $product->save();
        self::sync_active_supplier_warehouse( $product_id, $active );
    }

    /**
     * Imports products that exist only at TopItaly. They stay draft until a
     * shop manager has reviewed their selling price and publication state.
     */
    private static function create_topitaly_product( array $item ) : int {
        if ( ! class_exists( 'WC_Product_Simple' ) ) return 0;

        $title = trim( (string) ( $item['title'] ?? '' ) );
        $sku   = trim( (string) ( $item['sku'] ?? '' ) );
        $ean   = trim( (string) ( $item['ean'] ?? '' ) );
        if ( '' === $title || '' === $sku || '' === $ean ) return 0;

        $product = new WC_Product_Simple();
        $product->set_name( $title );
        $product->set_status( 'draft' );
        $product->set_catalog_visibility( 'hidden' );
        $product->set_sku( $sku );
        $product->set_regular_price( self::decimal_from_text( (string) ( $item['uvp'] ?? '' ) ) );
        $product->set_manage_stock( true );
        $product->set_stock_quantity( max( 0, (int) ( $item['stock'] ?? 0 ) ) );
        $product->set_stock_status( (int) ( $item['stock'] ?? 0 ) > 0 ? 'instock' : 'outofstock' );
        if ( ! empty( $item['description'] ) ) $product->set_short_description( wp_kses_post( $item['description'] ) );
        $product->update_meta_data( '_cofs_topitaly_ean', $ean );
        $product->update_meta_data( '_cofs_topitaly_sku', $sku );
        $product->update_meta_data( '_cofs_topitaly_stock', max( 0, (int) ( $item['stock'] ?? 0 ) ) );
        $product->update_meta_data( '_cofs_topitaly_uvp', (string) ( $item['uvp'] ?? '' ) );
        $product->update_meta_data( '_global_unique_id', $ean );
        $product->update_meta_data( '_product_gtin_data', $ean );
        $product->update_meta_data( '_ts_gtin', $ean );
        $product->update_meta_data( 'gtin', $ean );
        $product->update_meta_data( 'yoast_wpseo_gtin13', $ean );
        $product->update_meta_data( '_cofs_topitaly_only', 'yes' );
        $product->update_meta_data( self::META_ACTIVE, 'topitaly' );
        $product->update_meta_data( self::META_ACTIVE_SKU, $sku );
        return (int) $product->save();
    }

    /** Store TopItaly GTIN in standard product fields without overwriting an existing primary-supplier GTIN. */
    private static function sync_topitaly_gtin_fields( WC_Product $product, string $ean ) : void {
        $ean = preg_replace( '/\D+/', '', $ean );
        if ( '' === $ean ) return;

        $product_id = $product->get_id();
        $is_topitaly_only = 'yes' === get_post_meta( $product_id, '_cofs_topitaly_only', true );
        // Yoast must always receive the GTIN of the currently imported TopItaly item.
        $product->update_meta_data( 'yoast_wpseo_gtin13', $ean );
        foreach ( [ '_global_unique_id', '_product_gtin_data', '_ts_gtin', 'gtin' ] as $field ) {
            $current = trim( (string) get_post_meta( $product_id, $field, true ) );
            if ( $is_topitaly_only || '' === $current || $current === $ean ) $product->update_meta_data( $field, $ean );
        }
    }

    /** Assign only the requested product-type categories; never supplier brands or random sitemap categories. */
    private static function assign_topitaly_categories( int $product_id, array $item ) : void {
        $labels = isset( $item['categories'] ) && is_array( $item['categories'] ) ? $item['categories'] : [];
        $terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false ] );
        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) return;

        $type = self::topitaly_category_type( (string) ( $item['title'] ?? '' ) );
        $wanted = self::topitaly_type_category_ids( $terms, $type );
        $compatibility = self::topitaly_compatibility( (string) ( $item['title'] ?? '' ) );
        if ( '' !== $compatibility ) $wanted = array_merge( $wanted, self::topitaly_compatibility_category_ids( $terms, $compatibility ) );
        $wanted = array_values( array_unique( $wanted ) );
        $managed = array_filter( array_map( 'absint', (array) get_post_meta( $product_id, '_cofs_topitaly_auto_categories', true ) ) );

        // One-time cleanup for categories added by the earlier broad sitemap matching.
        if ( empty( $managed ) ) {
            foreach ( $labels as $label ) {
                $needle = self::normalize_category_label( (string) $label );
                if ( '' === $needle || in_array( $needle, [ 'kaffee', 'cafe', 'pads', 'kaffeebohnen', 'kaffeekapseln', 'topitaly' ], true ) ) continue;
                foreach ( $terms as $term ) {
                    if ( self::normalize_category_label( $term->name ) === $needle ) $managed[] = (int) $term->term_id;
                }
            }
        }
        $managed = array_values( array_unique( $managed ) );
        if ( ! empty( $managed ) ) wp_remove_object_terms( $product_id, $managed, 'product_cat' );
        if ( ! empty( $wanted ) ) wp_set_object_terms( $product_id, $wanted, 'product_cat', true );
        update_post_meta( $product_id, '_cofs_topitaly_auto_categories', $wanted );
    }

    private static function topitaly_type_category_ids( array $terms, string $type ) : array {
        if ( '' === $type ) return [];
        $needle = self::normalize_category_label( $type );
        $ids = [];
        foreach ( $terms as $term ) {
            if ( self::normalize_category_label( $term->name ) === $needle ) $ids[] = (int) $term->term_id;
        }
        return array_values( array_unique( $ids ) );
    }

    /** Categories are based on the product title only; machines are a category but not a title suffix. */
    private static function topitaly_category_type( string $title ) : string {
        $title = self::normalize_category_label( $title );
        if ( false !== strpos( $title, 'kaffeemaschine' ) || false !== strpos( $title, 'kaffeemaschinen' ) ) return 'Kaffeemaschinen';
        return self::topitaly_product_type( $title, [] );
    }

    private static function topitaly_compatibility( string $title ) : string {
        $title = self::normalize_category_label( $title );
        if ( false !== strpos( $title, 'nespresso professional' ) ) return 'Nespresso® Professional';
        return false !== strpos( $title, 'nespresso' ) ? 'Nespresso®' : '';
    }

    private static function topitaly_compatibility_category_ids( array $terms, string $compatibility ) : array {
        $labels = 'Nespresso® Professional' === $compatibility ? [ 'Nespresso® Professional' ] : [ 'Nespresso®', 'Nespresso® kompatibel' ];
        $ids = [];
        foreach ( $terms as $term ) {
            if ( in_array( self::normalize_category_label( $term->name ), array_map( [ __CLASS__, 'normalize_category_label' ], $labels ), true ) ) $ids[] = (int) $term->term_id;
        }
        return array_values( array_unique( $ids ) );
    }

    private static function normalize_category_label( string $value ) : string {
        $value = remove_accents( strtolower( $value ) );
        return trim( preg_replace( '/[^a-z0-9]+/', ' ', $value ) );
    }

    /** Formats TopItaly titles and moves pack counts / weights into existing global attributes. */
    private static function apply_topitaly_presentation( WC_Product $product, array $item ) : void {
        $raw_title = trim( preg_replace( '/\s+/u', ' ', (string) ( $item['title'] ?? '' ) ) );
        if ( '' === $raw_title ) return;

        $categories = isset( $item['categories'] ) && is_array( $item['categories'] ) ? $item['categories'] : [];
        $brand = '';
        foreach ( array_reverse( $categories ) as $category ) {
            $candidate = trim( (string) $category );
            if ( '' !== $candidate && ! in_array( self::normalize_category_label( $candidate ), [ 'kaffee', 'cafe', 'pads', 'kaffeebohnen', 'kaffeekapseln' ], true ) ) {
                $brand = $candidate;
                break;
            }
        }

        $name = $raw_title;
        if ( '' !== $brand && 0 === stripos( $name, $brand ) ) $name = trim( substr( $name, strlen( $brand ) ), " -–—:" );

        $type = self::topitaly_product_type( $name, $categories );
        $quantity = '';
        $size = '';
        if ( preg_match( '/\b(\d+)\s*[x×]\s*(\d+(?:[\.,]\d+)?\s*(?:kg|g|ml|cl|l))\b/ui', $name, $match ) ) {
            $quantity = $match[1];
            $size = self::normalize_size_value( $match[2] );
            $name = str_replace( $match[0], '', $name );
        } else {
            if ( preg_match( '/\b(\d+)\s*(?:er\s*[-–—]?\s*)?pack\b/ui', $name, $match ) ) {
                $quantity = $match[1];
                $name = str_replace( $match[0], '', $name );
            }
            if ( preg_match( '/\b(\d+(?:[\.,]\d+)?\s*(?:kg|g|ml|cl|l))\b/ui', $name, $match ) ) {
                $size = self::normalize_size_value( $match[1] );
                $name = str_replace( $match[0], '', $name );
            }
            if ( preg_match( '/\b(\d+)\s*(?:stück|stk\.?|(?=(?:e\.?s\.?e\.?\s*)?(?:pads?|kapseln?)))\b/ui', $name, $match ) ) {
                $quantity = $match[1];
                $name = str_replace( $match[0], '', $name );
            }
        }

        if ( '' !== $type ) {
            $type_pattern = preg_quote( $type, '/' );
            $name = preg_replace( '/\b' . $type_pattern . '\b/ui', '', $name );
            if ( 'E.S.E. Pads' === $type ) $name = preg_replace( '/\b(?:e\.?s\.?e\.?\s*)?pads?\b/ui', '', $name );
        }
        $name = trim( preg_replace( '/\s*(?:-|–|—|:)\s*/u', ' ', $name ) );
        $parts = array_values( array_filter( [ self::format_title_case( $brand ), self::format_title_case( $name ), $type ] ) );
        $formatted = implode( ' - ', array_values( array_unique( $parts ) ) );
        if ( '' !== $formatted ) $product->set_name( $formatted );
        if ( '' !== $quantity ) self::set_global_product_attribute( $product, 'pa_stueck', $quantity );
        if ( '' !== $size ) self::set_global_product_attribute( $product, 'pa_groesse', $size );
        $compatibility = self::topitaly_compatibility( $raw_title );
        if ( '' !== $compatibility ) self::set_global_product_attribute( $product, 'pa_kompatibilitaet', $compatibility );
    }

    private static function topitaly_product_type( string $title, array $categories ) : string {
        $haystack = self::normalize_category_label( $title . ' ' . implode( ' ', $categories ) );
        if ( false !== strpos( $haystack, 'e s e pads' ) || false !== strpos( $haystack, 'pads' ) ) return 'E.S.E. Pads';
        if ( false !== strpos( $haystack, 'kaffeebohnen' ) || false !== strpos( $haystack, 'bohnen' ) ) return 'Kaffeebohnen';
        if ( false !== strpos( $haystack, 'kaffeekapseln' ) || false !== strpos( $haystack, 'kapseln' ) ) return 'Kaffeekapseln';
        return '';
    }

    private static function normalize_size_value( string $value ) : string {
        $value = strtolower( str_replace( ',', '.', preg_replace( '/\s+/', '', $value ) ) );
        return trim( $value );
    }

    private static function format_title_case( string $value ) : string {
        $value = trim( $value );
        if ( '' === $value || ! function_exists( 'mb_strtoupper' ) || ! function_exists( 'mb_convert_case' ) ) return $value;
        return $value === mb_strtoupper( $value, 'UTF-8' ) ? mb_convert_case( $value, MB_CASE_TITLE, 'UTF-8' ) : $value;
    }

    private static function set_global_product_attribute( WC_Product $product, string $taxonomy, string $value ) : void {
        if ( ! taxonomy_exists( $taxonomy ) || ! class_exists( 'WC_Product_Attribute' ) ) return;
        $term = get_term_by( 'name', $value, $taxonomy );
        if ( ! $term ) {
            $created = wp_insert_term( $value, $taxonomy );
            if ( is_wp_error( $created ) ) return;
            $term_id = (int) $created['term_id'];
        } else {
            $term_id = (int) $term->term_id;
        }

        $attributes = $product->get_attributes();
        $attribute = new WC_Product_Attribute();
        $attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
        $attribute->set_name( $taxonomy );
        $attribute->set_options( [ $term_id ] );
        $attribute->set_position( isset( $attributes[ $taxonomy ] ) ? $attributes[ $taxonomy ]->get_position() : count( $attributes ) );
        $attribute->set_visible( true );
        $attribute->set_variation( false );
        $attributes[ $taxonomy ] = $attribute;
        $product->set_attributes( $attributes );
    }

    private static function sync_topitaly_images( int $product_id, array $item ) : void {
        $urls = isset( $item['images'] ) && is_array( $item['images'] ) ? array_slice( array_values( array_unique( $item['images'] ) ), 0, 6 ) : [];
        if ( empty( $urls ) ) return;
        $imported = get_post_meta( $product_id, '_cofs_topitaly_images', true );
        if ( ! is_array( $imported ) ) $imported = [];

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $existing_ids = self::product_image_ids( $product_id );
        $known_ids = $existing_ids;
        $known_urls = [];
        foreach ( $imported as $source_url => $attachment_id ) {
            $attachment_id = absint( $attachment_id );
            if ( $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
                $known_urls[ self::canonical_topitaly_image_url( (string) $source_url ) ] = $attachment_id;
                $known_ids[] = $attachment_id;
            }
        }
        foreach ( $existing_ids as $attachment_id ) {
            $source_url = self::canonical_topitaly_image_url( (string) get_post_meta( $attachment_id, '_cofs_topitaly_source_url', true ) );
            if ( '' !== $source_url ) $known_urls[ $source_url ] = $attachment_id;
        }
        $known_ids = array_values( array_unique( array_filter( array_map( 'absint', $known_ids ) ) ) );
        $hashes = [];
        foreach ( $known_ids as $attachment_id ) {
            $hash = self::attachment_image_hash( $attachment_id );
            if ( '' !== $hash && ! isset( $hashes[ $hash ] ) ) $hashes[ $hash ] = $attachment_id;
        }

        $attachment_ids = [];
        $resolved = [];
        foreach ( $urls as $url ) {
            $url = esc_url_raw( (string) $url );
            if ( '' === $url ) continue;
            $canonical_url = self::canonical_topitaly_image_url( $url );
            if ( '' === $canonical_url || isset( $resolved[ $canonical_url ] ) ) continue;
            $attachment_id = isset( $known_urls[ $canonical_url ] ) ? (int) $known_urls[ $canonical_url ] : 0;
            if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
                $attachment_id = media_sideload_image( $url, $product_id, null, 'id' );
                if ( is_wp_error( $attachment_id ) ) continue;
                $attachment_id = (int) $attachment_id;
                $hash = self::attachment_image_hash( $attachment_id );
                if ( '' !== $hash && isset( $hashes[ $hash ] ) ) {
                    wp_delete_attachment( $attachment_id, true );
                    $attachment_id = $hashes[ $hash ];
                } elseif ( '' !== $hash ) {
                    $hashes[ $hash ] = $attachment_id;
                }
            }
            update_post_meta( $attachment_id, '_cofs_topitaly_source_url', $canonical_url );
            $resolved[ $canonical_url ] = $attachment_id;
            $attachment_ids[] = $attachment_id;
        }
        $attachment_ids = array_values( array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) ) );
        if ( empty( $attachment_ids ) ) return;
        update_post_meta( $product_id, '_cofs_topitaly_images', $resolved );

        if ( 'yes' === get_post_meta( $product_id, '_cofs_topitaly_only', true ) ) {
            set_post_thumbnail( $product_id, $attachment_ids[0] );
            $gallery = array_slice( $attachment_ids, 1 );
            update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery ) );
            self::delete_replaced_product_images( $product_id, $existing_ids, $attachment_ids );
            return;
        }

        $featured_image = get_post_thumbnail_id( $product_id );
        if ( ! $featured_image ) {
            $featured_image = $attachment_ids[0];
            set_post_thumbnail( $product_id, $featured_image );
        }
        $gallery = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $product_id, '_product_image_gallery', true ) ) ) );
        $gallery = array_values( array_unique( array_merge( $gallery, $attachment_ids ) ) );
        $gallery = array_values( array_diff( $gallery, [ (int) $featured_image ] ) );
        update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery ) );
    }

    private static function canonical_topitaly_image_url( string $url ) : string {
        $url = esc_url_raw( trim( $url ) );
        if ( '' === $url ) return '';
        $url = preg_replace( '/[?#].*$/', '', $url );
        return strtolower( preg_replace( '#^https?:#i', '', $url ) );
    }

    private static function attachment_image_hash( int $attachment_id ) : string {
        $path = get_attached_file( $attachment_id );
        return $path && is_readable( $path ) ? (string) md5_file( $path ) : '';
    }

    private static function product_image_ids( int $product_id ) : array {
        $ids = [ get_post_thumbnail_id( $product_id ) ];
        $ids = array_merge( $ids, array_map( 'absint', explode( ',', (string) get_post_meta( $product_id, '_product_image_gallery', true ) ) ) );
        $imported = get_post_meta( $product_id, '_cofs_topitaly_images', true );
        if ( is_array( $imported ) ) $ids = array_merge( $ids, array_map( 'absint', array_values( $imported ) ) );
        return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
    }

    private static function delete_replaced_product_images( int $product_id, array $old_ids, array $new_ids ) : void {
        foreach ( array_diff( $old_ids, $new_ids ) as $attachment_id ) {
            $attachment_id = absint( $attachment_id );
            if ( $attachment_id && (int) get_post_field( 'post_parent', $attachment_id ) === $product_id ) wp_delete_attachment( $attachment_id, true );
        }
    }

    private static function delete_product_images( int $product_id ) : void {
        $ids = self::product_image_ids( $product_id );
        foreach ( get_attached_media( 'image', $product_id ) as $attachment ) $ids[] = (int) $attachment->ID;
        foreach ( array_unique( $ids ) as $attachment_id ) {
            if ( $attachment_id ) wp_delete_attachment( (int) $attachment_id, true );
        }
    }

    private static function find_product_id( array $identifiers ) : int {
        global $wpdb;
        $identifiers = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $identifiers ) ) ) ) );
        if ( empty( $identifiers ) || ! $wpdb ) return 0;
        $placeholders = implode( ',', array_fill( 0, count( $identifiers ), '%s' ) );
        $keys = "'_sku', '_vendor_sku', '_bcl_original_sku', '_global_unique_id', '_product_gtin_data', '_ts_gtin', 'gtin', 'yoast_wpseo_gtin13', '_cofs_topitaly_ean', '_cofs_topitaly_sku'";
        $sql = "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ($keys) AND pm.meta_value IN ($placeholders) AND p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft') ORDER BY CASE pm.meta_key WHEN '_sku' THEN 0 WHEN '_global_unique_id' THEN 1 WHEN '_vendor_sku' THEN 2 ELSE 3 END, pm.post_id ASC LIMIT 1";
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $identifiers ) );
    }

    private static function apply_supplier_candidate( int $product_id, array $candidate, array &$result, $product = null ) : void {
        $supplier = sanitize_key( (string) ( $candidate['supplier'] ?? '' ) );
        if ( '' === $supplier || ! self::is_product_in_scope( $product_id ) ) return;
        $sources = get_post_meta( $product_id, self::META_SOURCES, true );
        if ( ! is_array( $sources ) ) $sources = [];
        $candidate['stock'] = max( 0, (int) ( $candidate['stock'] ?? 0 ) );
        $candidate['updated_at'] = (int) ( $candidate['updated_at'] ?? time() );
        $previous_source = isset( $sources[ $supplier ] ) && is_array( $sources[ $supplier ] ) ? $sources[ $supplier ] : [];
        $source_changed = empty( $previous_source )
            || (string) ( $previous_source['sku'] ?? '' ) !== (string) ( $candidate['sku'] ?? '' )
            || (string) ( $previous_source['ean'] ?? '' ) !== (string) ( $candidate['ean'] ?? '' )
            || (int) ( $previous_source['stock'] ?? 0 ) !== (int) $candidate['stock']
            || self::decimal_from_text( (string) ( $previous_source['purchase_price'] ?? '' ) ) !== self::decimal_from_text( (string) ( $candidate['purchase_price'] ?? '' ) );
        if ( $source_changed ) {
            $sources[ $supplier ] = $candidate;
            update_post_meta( $product_id, self::META_SOURCES, $sources );
        }
        $active = self::choose_active_supplier( $sources, (string) get_post_meta( $product_id, self::META_ACTIVE, true ) );
        if ( ! $active ) return;
        $active_data = $sources[ $active ];
        $product = $product ?: wc_get_product( $product_id );
        if ( ! $product ) { $result['errors']++; return; }
        $new_stock = self::effective_supplier_stock( $active, $active_data );
        $old_stock = $product->get_stock_quantity( 'edit' );
        $changed = ! $product->get_manage_stock( 'edit' ) || (int) $old_stock !== $new_stock || (string) $product->get_stock_status( 'edit' ) !== ( $new_stock > 0 ? 'instock' : 'outofstock' );
        if ( $changed ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $new_stock );
            $product->set_stock_status( $new_stock > 0 ? 'instock' : 'outofstock' );
        }
        $purchase = self::decimal_from_text( (string) ( $active_data['purchase_price'] ?? '' ) );
        $purchase_changed = false;
        $old_purchase = '';
        if ( '' !== $purchase && self::decimal_from_text( (string) get_post_meta( $product_id, '_purchase_price', true ) ) !== $purchase ) {
            $old_purchase = (string) get_post_meta( $product_id, '_purchase_price', true );
            $product->update_meta_data( '_purchase_price', $purchase );
            $purchase_changed = true;
            $result['purchase_changed']++;
            if ( class_exists( 'COFS_Price_Log' ) ) COFS_Price_Log::log_purchase_price_change( [ 'product_id' => $product_id, 'product_name' => $product->get_name(), 'product_sku' => $product->get_sku( 'edit' ), 'feed_sku' => (string) ( $active_data['ean'] ?? '' ), 'vendor_sku' => (string) ( $active_data['sku'] ?? '' ), 'old_price' => $old_purchase, 'new_price' => $purchase, 'source' => 'smart_supplier:' . $active ] );
        }
        $active_sku = (string) ( $active_data['sku'] ?? '' );
        $active_changed = (string) get_post_meta( $product_id, self::META_ACTIVE, true ) !== $active || (string) get_post_meta( $product_id, self::META_ACTIVE_SKU, true ) !== $active_sku;
        if ( $active_changed ) {
            $product->update_meta_data( self::META_ACTIVE, $active );
            $product->update_meta_data( self::META_ACTIVE_SKU, $active_sku );
        }
        if ( $changed || $purchase_changed || $active_changed || ! empty( $product->get_changes() ) ) $product->save();
        self::sync_active_supplier_warehouse( $product_id, $active );
        if ( $changed ) $result['stock_changed']++; else $result['stock_unchanged']++;
        $result['matched']++;
        if ( $changed || $purchase_changed ) {
            $change = [ 'product_id' => $product_id, 'product_admin' => get_edit_post_link( $product_id ), 'feed_sku' => (string) ( $active_data['ean'] ?? '' ), 'supplier' => $active ];
            if ( $changed ) $change['stock'] = [ 'old' => null === $old_stock ? null : (int) $old_stock, 'new' => $new_stock ];
            if ( $purchase_changed ) $change['purchase_price'] = [ 'old' => $old_purchase, 'new' => $purchase ];
            $result['changes'][] = $change;
        }
    }

    private static function choose_active_supplier( array $sources, string $current ) : string {
        $caffeonline = isset( $sources['caffeonline'] ) && is_array( $sources['caffeonline'] ) ? $sources['caffeonline'] : null;
        $topitaly    = isset( $sources['topitaly'] ) && is_array( $sources['topitaly'] ) ? $sources['topitaly'] : null;

        // CaffeOnline is the default supplier. A positive CaffeOnline stock
        // switches back immediately, independent of the TopItaly quantity.
        if ( $caffeonline && self::effective_supplier_stock( 'caffeonline', $caffeonline ) > 0 ) return 'caffeonline';

        // TopItaly is a backup only when CaffeOnline is out of stock. It is
        // also the permanent supplier for products not present at CaffeOnline.
        if ( $topitaly && self::effective_supplier_stock( 'topitaly', $topitaly ) > 0 ) return 'topitaly';
        if ( $caffeonline ) return 'caffeonline';
        if ( $topitaly ) return 'topitaly';
        return $current;
    }

    /** Returns the stock that may be offered in WooCommerce after the source-specific tolerance. */
    private static function effective_supplier_stock( string $supplier, array $data ) : int {
        $stock = max( 0, (int) ( $data['stock'] ?? 0 ) );
        $settings = get_option( 'cofs_settings', [] );
        $tolerance = max( 0, (int) ( $settings[ sanitize_key( $supplier ) . '_stock_tolerance' ] ?? 0 ) );
        return $stock <= $tolerance ? 0 : $stock;
    }

    private static function is_product_in_scope( int $product_id ) : bool {
        $settings = get_option( 'cofs_settings', [] );
        return ! in_array( $product_id, array_map( 'absint', (array) ( $settings['excluded_product_ids'] ?? [] ) ), true );
    }

    /** Keeps the selected source's configured warehouse category in sync without touching manual categories. */
    private static function sync_active_supplier_warehouse( int $product_id, string $supplier ) : void {
        $settings = get_option( 'cofs_settings', [] );
        $selected = absint( $settings[ $supplier . '_warehouse_term_id' ] ?? 0 );
        $managed = array_filter( array_map( 'absint', (array) get_post_meta( $product_id, '_cofs_supplier_warehouse_terms', true ) ) );
        if ( $selected && [ $selected ] === array_values( $managed ) && has_term( $selected, 'product_cat', $product_id ) ) return;
        if ( ! $selected && empty( $managed ) ) return;
        if ( ! empty( $managed ) ) wp_remove_object_terms( $product_id, $managed, 'product_cat' );

        if ( $selected && term_exists( $selected, 'product_cat' ) ) {
            wp_set_object_terms( $product_id, [ $selected ], 'product_cat', true );
            update_post_meta( $product_id, '_cofs_supplier_warehouse_terms', [ $selected ] );
            return;
        }
        delete_post_meta( $product_id, '_cofs_supplier_warehouse_terms' );
    }

    private static function empty_result() : array {
        return [ 'matched' => 0, 'stock_changed' => 0, 'stock_unchanged' => 0, 'purchase_changed' => 0, 'purchase_unchanged' => 0, 'purchase_missing' => 0, 'price_logs' => 0, 'skipped_excluded' => 0, 'errors' => 0, 'changes' => [] ];
    }
}

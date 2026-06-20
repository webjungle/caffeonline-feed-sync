<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class COFS_Price_Log {
    const DB_VERSION     = '1.0.0';
    const OPT_DB_VERSION = 'cofs_price_log_db_version';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'cofs_price_change_log';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            product_name varchar(191) NOT NULL DEFAULT '',
            product_sku varchar(191) NOT NULL DEFAULT '',
            feed_sku varchar(191) NOT NULL DEFAULT '',
            vendor_sku varchar(191) NOT NULL DEFAULT '',
            old_purchase_price varchar(64) NOT NULL DEFAULT '',
            new_purchase_price varchar(64) NOT NULL DEFAULT '',
            source varchar(50) NOT NULL DEFAULT 'sync',
            changed_at_gmt datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY feed_sku (feed_sku),
            KEY changed_at_gmt (changed_at_gmt)
        ) {$charset};";

        dbDelta( $sql );
        update_option( self::OPT_DB_VERSION, self::DB_VERSION, false );
    }

    public static function maybe_install() {
        $current = get_option( self::OPT_DB_VERSION, '' );
        if ( $current !== self::DB_VERSION ) {
            self::install();
        }
    }

    public static function log_purchase_price_change( array $args ) {
        global $wpdb;

        self::maybe_install();

        $old_price = self::normalize_price_value( $args['old_price'] ?? '' );
        $new_price = self::normalize_price_value( $args['new_price'] ?? '' );

        if ( $old_price === $new_price ) {
            return false;
        }

        $product_id = absint( $args['product_id'] ?? 0 );
        if ( $product_id <= 0 ) {
            return false;
        }

        $data = [
            'product_id'         => $product_id,
            'product_name'       => sanitize_text_field( (string) ( $args['product_name'] ?? '' ) ),
            'product_sku'        => sanitize_text_field( (string) ( $args['product_sku'] ?? '' ) ),
            'feed_sku'           => sanitize_text_field( (string) ( $args['feed_sku'] ?? '' ) ),
            'vendor_sku'         => sanitize_text_field( (string) ( $args['vendor_sku'] ?? '' ) ),
            'old_purchase_price' => $old_price,
            'new_purchase_price' => $new_price,
            'source'             => sanitize_key( (string) ( $args['source'] ?? 'sync' ) ),
            'changed_at_gmt'     => gmdate( 'Y-m-d H:i:s' ),
        ];

        $format = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

        return (bool) $wpdb->insert( self::table_name(), $data, $format );
    }

    public static function get_recent( $limit = 200 ) {
        global $wpdb;

        self::maybe_install();

        $limit = max( 1, min( 2000, intval( $limit ) ) );
        $table = self::table_name();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table}
                 ORDER BY changed_at_gmt DESC, id DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }

    private static function normalize_price_value( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) {
            return '';
        }

        $value = str_replace( ',', '.', $value );
        if ( is_numeric( $value ) ) {
            $value = rtrim( rtrim( number_format( (float) $value, 6, '.', '' ), '0' ), '.' );
        }

        return $value;
    }
}

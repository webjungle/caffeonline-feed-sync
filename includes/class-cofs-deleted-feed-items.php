<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class COFS_Deleted_Feed_Items {
    const OPT_KEY = 'cofs_deleted_feed_items';

    public static function init() : void {
        add_action( 'wp_trash_post', [ __CLASS__, 'capture_deleted_post' ], 10, 1 );
        add_action( 'before_delete_post', [ __CLASS__, 'capture_deleted_post' ], 10, 1 );
    }

    public static function capture_deleted_post( $post_id ) : void {
        if ( defined( 'COFS_SKIP_DELETE_TOMBSTONE' ) && COFS_SKIP_DELETE_TOMBSTONE ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, [ 'product', 'product_variation' ], true ) ) {
            return;
        }

        $identifiers = self::identifiers_from_post( (int) $post_id );
        if ( ! self::has_identifier( $identifiers ) ) {
            return;
        }

        self::block_identifiers( $identifiers, 'deleted_product' );
    }

    public static function identifiers_from_post( int $post_id ) : array {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return [];
        }

        $parent_id = ( 'product_variation' === $post->post_type ) ? (int) $post->post_parent : 0;

        $source_url = (string) get_post_meta( $post_id, '_cofs_source_url', true );
        if ( '' === $source_url && $parent_id ) {
            $source_url = (string) get_post_meta( $parent_id, '_cofs_source_url', true );
        }

        $vendor_sku = (string) get_post_meta( $post_id, '_vendor_sku', true );
        if ( '' === $vendor_sku && $parent_id ) {
            $vendor_sku = (string) get_post_meta( $parent_id, '_vendor_sku', true );
        }

        $sku       = (string) get_post_meta( $post_id, '_sku', true );
        $global_id = (string) get_post_meta( $post_id, '_global_unique_id', true );

        return [
            'post_id'    => $post_id,
            'post_type'  => $post->post_type,
            'title'      => get_the_title( $post_id ),
            'sku'        => $sku,
            'global_id'  => $global_id,
            'vendor_sku' => $vendor_sku,
            'source_url' => $source_url,
        ];
    }

    public static function is_feed_row_blocked( COFS_Feed $feed, array $row ) : bool {
        return self::is_blocked(
            [
                'sku'        => trim(
                    (string) $feed->col(
                        $row,
                        [
                            'GTIN', 'gtin',
                            'EAN', 'ean',
                            'Key(GTIN/EAN/SKU)',
                            'Key', 'key',
                            'SKU', 'sku',
                        ]
                    )
                ),
                'vendor_sku' => trim(
                    (string) $feed->col(
                        $row,
                        [
                            'Vendor SKU', 'vendor_sku', 'VendorSKU', 'VENDOR_SKU', 'vendor sku',
                            'Lieferant SKU', 'lieferant_sku', 'LieferantSKU', 'lieferant sku',
                            'Supplier SKU', 'supplier_sku', 'supplier sku',
                            'Hersteller SKU', 'HerstellerSKU', 'Hersteller ArtNr', 'Hersteller-Nummer',
                            'Artikelnummer', 'Artikel-Nr', 'Art.-Nr.', 'ArtNr', 'Artikel Nr',
                        ]
                    )
                ),
            ]
        );
    }

    public static function is_blocked( array $identifiers ) : bool {
        $blocked = self::get_blocklist();

        foreach ( self::identifier_keys( $identifiers ) as $bucket => $key ) {
            if ( isset( $blocked[ $bucket ][ $key ] ) ) {
                return true;
            }
        }

        return false;
    }

    public static function block_identifiers( array $identifiers, string $reason = 'manual' ) : void {
        $keys = self::identifier_keys( $identifiers );
        if ( empty( $keys ) ) {
            return;
        }

        $blocked = self::get_blocklist();
        $record  = [
            'post_id'    => isset( $identifiers['post_id'] ) ? (int) $identifiers['post_id'] : 0,
            'post_type'  => isset( $identifiers['post_type'] ) ? (string) $identifiers['post_type'] : '',
            'title'      => isset( $identifiers['title'] ) ? (string) $identifiers['title'] : '',
            'sku'        => isset( $identifiers['sku'] ) ? (string) $identifiers['sku'] : '',
            'global_id'  => isset( $identifiers['global_id'] ) ? (string) $identifiers['global_id'] : '',
            'vendor_sku' => isset( $identifiers['vendor_sku'] ) ? (string) $identifiers['vendor_sku'] : '',
            'source_url' => isset( $identifiers['source_url'] ) ? (string) $identifiers['source_url'] : '',
            'reason'     => $reason,
            'blocked_at' => current_time( 'mysql' ),
        ];

        foreach ( $keys as $bucket => $key ) {
            if ( ! isset( $blocked[ $bucket ] ) || ! is_array( $blocked[ $bucket ] ) ) {
                $blocked[ $bucket ] = [];
            }

            $blocked[ $bucket ][ $key ] = $record;
        }

        update_option( self::OPT_KEY, $blocked, false );
    }

    public static function get_blocklist() : array {
        $blocked = get_option( self::OPT_KEY, [] );
        if ( ! is_array( $blocked ) ) {
            $blocked = [];
        }

        foreach ( [ 'sku', 'global_id', 'vendor_sku', 'source_url' ] as $bucket ) {
            if ( ! isset( $blocked[ $bucket ] ) || ! is_array( $blocked[ $bucket ] ) ) {
                $blocked[ $bucket ] = [];
            }
        }

        return $blocked;
    }

    private static function has_identifier( array $identifiers ) : bool {
        return ! empty( self::identifier_keys( $identifiers ) );
    }

    private static function identifier_keys( array $identifiers ) : array {
        $keys = [];

        foreach ( [ 'sku', 'global_id', 'vendor_sku' ] as $bucket ) {
            $value = isset( $identifiers[ $bucket ] ) ? self::normalize_scalar( $identifiers[ $bucket ] ) : '';
            if ( '' !== $value ) {
                $keys[ $bucket ] = $value;
            }
        }

        if ( isset( $identifiers['source_url'] ) ) {
            $url = self::normalize_url( (string) $identifiers['source_url'] );
            if ( '' !== $url ) {
                $keys['source_url'] = $url;
            }
        }

        return $keys;
    }

    private static function normalize_scalar( $value ) : string {
        $value = strtolower( trim( (string) $value ) );
        return preg_replace( '/\s+/', '', $value );
    }

    private static function normalize_url( string $url ) : string {
        $url = trim( $url );
        if ( '' === $url ) {
            return '';
        }

        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return strtolower( untrailingslashit( $url ) );
        }

        $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
        $host   = strtolower( $parts['host'] );
        $path   = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';

        return $scheme . '://' . $host . $path;
    }
}

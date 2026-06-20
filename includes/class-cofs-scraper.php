<?php
/**
 * COFS_Scraper
 *
 * Ausgelagerte Scraper-Logik für "Fehlende Produkte (CSV → Shop)".
 * - Nimmt eine caffeonline.ch Produkt-URL entgegen
 * - Liest Titel, Bild und (falls vorhanden) Variationen
 * - Legt WooCommerce-Produkte an (simple oder variable)
 * - Wird über wp_ajax_cofs_scrape_product aufgerufen
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'COFS_Scraper' ) ) :

class COFS_Scraper {

    /**
     * Drain all output buffers to guarantee a clean JSON response.
     * Some third-party code can echo markup/styles during admin-ajax.
     */
    private function clean_ajax_output() {
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

    /**
     * Entry-Point für AJAX (von COFS_Admin::ajax_scrape_product aufgerufen).
     */
    public function handle_ajax() {
        // Berechtigungen
        if ( ! current_user_can( 'manage_options' ) ) {
            $this->send_json_error( [ 'message' => 'forbidden' ], 403 );
        }

        // Nonce (gleicher wie restliches COFS-Admin-AJAX)
        if ( ! check_ajax_referer( 'cofs_ajax', 'nonce', false ) ) {
            $this->send_json_error( [ 'message' => 'bad nonce' ], 400 );
        }

        if ( ! function_exists( 'wc_get_product' ) ) {
            $this->send_json_error( [ 'message' => __( 'WooCommerce ist erforderlich.', 'caffeonline-feed-sync' ) ] );
        }

        $url        = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        $key        = isset( $_POST['key'] ) ? wc_clean( wp_unslash( $_POST['key'] ) ) : '';
        $vendor_sku = isset( $_POST['vendor_sku'] ) ? wc_clean( wp_unslash( $_POST['vendor_sku'] ) ) : '';
        $feed_stock = isset( $_POST['stock'] ) ? (int) wc_clean( wp_unslash( $_POST['stock'] ) ) : null;
        $purchase_price = isset( $_POST['purchase_price'] ) ? wc_format_decimal( wc_clean( wp_unslash( $_POST['purchase_price'] ) ) ) : '';

        if ( empty( $url ) ) {
            $this->send_json_error( [ 'message' => __( 'Keine URL übergeben.', 'caffeonline-feed-sync' ) ] );
        }

        // Nur Produkt-URLs von caffeonline.ch erlauben
        $host = parse_url( $url, PHP_URL_HOST );
        if ( ! $host || substr( $host, -strlen( 'caffeonline.ch' ) ) !== 'caffeonline.ch' ) {
            $this->send_json_error( [ 'message' => __( 'Nur Produkt-URLs von caffeonline.ch sind erlaubt.', 'caffeonline-feed-sync' ) ] );
        }

        if (
            class_exists( 'COFS_Deleted_Feed_Items' )
            && COFS_Deleted_Feed_Items::is_blocked(
                [
                    'sku'        => $key,
                    'global_id'  => $key,
                    'vendor_sku' => $vendor_sku,
                    'source_url' => $url,
                ]
            )
        ) {
            $this->send_json_error( [ 'message' => __( 'Dieses Feed-Produkt wurde geloescht und ist fuer den erneuten Import gesperrt.', 'caffeonline-feed-sync' ) ] );
        }

        $scraped = $this->scrape_caffeonline_product( $url );
        if ( is_wp_error( $scraped ) ) {
            $this->send_json_error( [ 'message' => $scraped->get_error_message() ] );
        }

        if ( empty( $scraped['title'] ) ) {
            $this->send_json_error( [ 'message' => __( 'Titel konnte nicht ausgelesen werden.', 'caffeonline-feed-sync' ) ] );
        }

        $product_id = $this->create_or_update_product_from_scrape( $scraped, $key, $vendor_sku, $url, $feed_stock, $purchase_price );
        if ( is_wp_error( $product_id ) ) {
            $this->send_json_error( [ 'message' => $product_id->get_error_message() ] );
        }

        $resp = [
            'product_id' => $product_id,
            'edit_link'  => get_edit_post_link( $product_id, 'raw' ),
            'title'      => get_the_title( $product_id ),
        ];

        $this->send_json_success( $resp );
    }

    /**
     * Lädt caffeonline.ch Produktseite und extrahiert Titel, Bild & Variationen.
     */
    private function scrape_caffeonline_product( $url ) {
        $response = wp_remote_get(
            $url,
            [
                'timeout' => 20,
                'headers' => [
                    'User-Agent' => 'CaffeOnlineFeedSync-Scraper/1.0; ' . home_url(),
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) {
            return new WP_Error(
                'http_error',
                sprintf( __( 'Fehler beim Laden der Produktseite (%d).', 'caffeonline-feed-sync' ), $code )
            );
        }

        $html = wp_remote_retrieve_body( $response );
        if ( ! $html ) {
            return new WP_Error(
                'empty_html',
                __( 'Leere Antwort von der Produktseite.', 'caffeonline-feed-sync' )
            );
        }

        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error(
                'no_dom',
                __( 'PHP-XML / DOMDocument nicht verfügbar. Scraper kann nicht ausgeführt werden.', 'caffeonline-feed-sync' )
            );
        }

        libxml_use_internal_errors( true );
        $dom   = new DOMDocument();
        $dom->loadHTML( $html );
        $xpath = new DOMXPath( $dom );
        libxml_clear_errors();

        // Titel
        $title = '';
        $nodes = $xpath->query( "//h1[contains(@class,'product_title')]" );
        if ( $nodes && $nodes->length ) {
            $title = trim( $nodes->item(0)->textContent );
        }

        // Bild (og:image bevorzugt)
        $image_url = '';
        $og        = $xpath->query( "//meta[@property='og:image']/@content" );
        if ( $og && $og->length ) {
            $image_url = esc_url_raw( trim( $og->item(0)->nodeValue ) );
        } else {
            $img = $xpath->query( "//img[contains(@class,'wp-post-image') or contains(@class,'attachment-woocommerce_single')]/@src" );
            if ( $img && $img->length ) {
                $image_url = esc_url_raw( trim( $img->item(0)->nodeValue ) );
            }
        }

        // Preis (robust: JSON-LD -> meta -> sichtbarer Preis)
        $price = '';

        // JSON-LD Product
        $jsonld_nodes = $xpath->query( "//script[@type='application/ld+json']" );
        if ( $jsonld_nodes && $jsonld_nodes->length ) {
            foreach ( $jsonld_nodes as $node ) {
                $raw = trim( (string) $node->textContent );
                if ( $raw === '' ) continue;
                $data = json_decode( $raw, true );
                if ( json_last_error() !== JSON_ERROR_NONE ) continue;

                $candidates = is_array( $data ) && isset( $data[0] ) ? $data : [ $data ];
                foreach ( $candidates as $obj ) {
                    if ( ! is_array( $obj ) ) continue;
                    $type = $obj['@type'] ?? '';
                    if ( is_array( $type ) ) {
                        $type = implode( ',', $type );
                    }
                    if ( stripos( (string) $type, 'Product' ) === false ) continue;

                    $offers = $obj['offers'] ?? null;
                    if ( is_array( $offers ) && isset( $offers['price'] ) ) {
                        $price = (string) $offers['price'];
                    } elseif ( is_array( $offers ) && isset( $offers[0]['price'] ) ) {
                        $price = (string) $offers[0]['price'];
                    }
                    if ( $price !== '' ) break 2;
                }
            }
        }

        // meta property="product:price:amount"
        if ( $price === '' ) {
            $meta_price = $xpath->query( "//meta[@property='product:price:amount']/@content" );
            if ( $meta_price && $meta_price->length ) {
                $price = trim( (string) $meta_price->item(0)->nodeValue );
            }
        }

        // Visible price element
        if ( $price === '' ) {
            $price_nodes = $xpath->query( "//*[contains(@class,'woocommerce-Price-amount') or contains(@class,'price')][1]" );
            if ( $price_nodes && $price_nodes->length ) {
                $price = trim( preg_replace( '/\s+/', ' ', (string) $price_nodes->item(0)->textContent ) );
            }
        }

        // Normalize price to decimal string
        $price = $this->normalize_price( $price );

        // Variationen aus data-product_variations (WooCommerce Standard)
        $is_variable = false;
        $variations  = [];

        if ( preg_match( '/data-product_variations=("|\')(.+?)\1/Us', $html, $m ) ) {
            $json_raw = html_entity_decode( $m[2] );
            $data     = json_decode( $json_raw, true );

            if ( is_array( $data ) && ! empty( $data ) ) {
                $is_variable = true;

                foreach ( $data as $var ) {
                    $variations[] = [
                        'attributes' => isset( $var['attributes'] ) && is_array( $var['attributes'] ) ? $var['attributes'] : [],
                        'sku'        => isset( $var['sku'] ) ? (string) $var['sku'] : '',
                        'price'      => isset( $var['display_price'] )
                            ? $var['display_price']
                            : ( $var['regular_price'] ?? '' ),
                    ];
                }
            }
        }

        return [
            'title'       => $title,
            'image_url'   => $image_url,
            'price'       => $price,
            'is_variable' => $is_variable,
            'variations'  => $variations,
        ];
    }

    /**
     * Attempt to normalize a scraped price into a WC-compatible decimal string.
     */
    private function normalize_price( $raw ) : string {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) return '';

        // Remove currency symbols and non-number chars (keep dot/comma)
        $raw = preg_replace( '/[^0-9\,\.]/', '', $raw );
        $raw = trim( (string) $raw );
        if ( $raw === '' ) return '';

        // If both comma and dot exist, assume comma is thousand-separator and strip it.
        if ( strpos( $raw, ',' ) !== false && strpos( $raw, '.' ) !== false ) {
            $raw = str_replace( ',', '', $raw );
        } elseif ( strpos( $raw, ',' ) !== false && strpos( $raw, '.' ) === false ) {
            // Use comma as decimal separator
            $raw = str_replace( ',', '.', $raw );
        }

        // Last cleanup
        $raw = preg_replace( '/\.(?=.*\.)/', '', $raw ); // remove all but last dot
        $raw = trim( (string) $raw );
        return $raw;
    }

    /**
     * Bild von externer URL in die Mediathek importieren.
     */
    private function import_image_to_media( $image_url, $title = '', $preferred_basename = '' ) {
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

        // Optional: rename file before import (e.g. use SKU/GTIN)
        if ( $preferred_basename ) {
            $preferred_basename = sanitize_file_name( $preferred_basename );
            $ext = pathinfo( $name, PATHINFO_EXTENSION );
            if ( ! $ext ) {
                $ext = 'jpg';
            }
            $name = $preferred_basename . '.' . $ext;
        }

        // Dateityp best guess
        $filetype = wp_check_filetype( $name );
        $type     = $filetype['type'] ? $filetype['type'] : 'image/jpeg';

        $file = [
            'name'     => $name,
            'type'     => $type,
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
            'post_title'     => $title ? $title : sanitize_file_name( $name ),
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
     * Aus den Scrape-Daten ein Produkt erstellen oder bestehendes aktualisieren.
     *
     * $key        = GTIN/EAN/SKU aus dem CSV (Missing-Liste)
     * $vendor_sku = optionale Lieferant-/Hersteller-SKU
     */
    private function create_or_update_product_from_scrape( $scraped, $key, $vendor_sku, $source_url, $feed_stock = null, $purchase_price = '' ) {
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

        // 1) Existierendes Produkt mit dieser SKU? → updaten
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
                    $thumb_id = $this->import_image_to_media( $scraped['image_url'], $scraped['title'], $base_sku );
                    if ( $thumb_id ) {
                        set_post_thumbnail( $existing_id, $thumb_id );
                    }
                }

                update_post_meta( $existing_id, '_cofs_source_url', esc_url_raw( $source_url ) );

                // Vendor SKU
                if ( $vendor_sku !== '' ) {
                    update_post_meta( $existing_id, '_vendor_sku', $vendor_sku );
                }

                // Global unique id (EAN/GTIN) + fixed vendor
                if ( $base_sku !== '' ) {
                    update_post_meta( $existing_id, '_global_unique_id', $base_sku );
                }
                update_post_meta( $existing_id, '_product_vendor', 'Aargau' );

                // Purchase price from feed: only set if empty
                if ( $purchase_price !== '' ) {
                    $existing_pp = get_post_meta( $existing_id, '_purchase_price', true );
                    if ( $existing_pp === '' || $existing_pp === null ) {
                        update_post_meta( $existing_id, '_purchase_price', $purchase_price );
                    }
                }

                $this->apply_stock_and_price( $existing_id, $scraped, $feed_stock );

                return $existing_id;
            }
        }

        // 2) Neues Produkt anlegen

        // Bild laden
        $thumb_id = 0;
        if ( ! empty( $scraped['image_url'] ) ) {
            $thumb_id = $this->import_image_to_media( $scraped['image_url'], $scraped['title'], $base_sku );
        }

        // Variable Produkt?
        if ( ! empty( $scraped['is_variable'] ) && ! empty( $scraped['variations'] ) ) {
            $parent_id = $this->create_variable_product_from_scrape(
                $scraped,
                $base_sku,
                $vendor_sku,
                $source_url,
                $thumb_id
            );
            if ( ! is_wp_error( $parent_id ) ) {
                $this->apply_stock_and_price( $parent_id, $scraped, $feed_stock, true );
            }
            return $parent_id;
        }

        // Einfaches Produkt
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

        // Global unique id (EAN/GTIN) + fixed vendor
        if ( $base_sku !== '' ) {
            update_post_meta( $post_id, '_global_unique_id', $base_sku );
        }
        update_post_meta( $post_id, '_product_vendor', 'Aargau' );

        // Purchase price from feed: only set if empty
        if ( $purchase_price !== '' ) {
            $existing_pp = get_post_meta( $post_id, '_purchase_price', true );
            if ( $existing_pp === '' || $existing_pp === null ) {
                update_post_meta( $post_id, '_purchase_price', $purchase_price );
            }
        }

        wp_set_object_terms( $post_id, 'simple', 'product_type' );
        update_post_meta( $post_id, '_stock_status', 'instock' );
        update_post_meta( $post_id, '_cofs_source_url', esc_url_raw( $source_url ) );

        $this->apply_stock_and_price( $post_id, $scraped, $feed_stock );

        if ( $thumb_id ) {
            set_post_thumbnail( $post_id, $thumb_id );
        }

        return $post_id;
    }

    /**
     * Apply stock (from feed, preferred) and scraped price (if found) to product.
     * For variable products we only set parent stock_status, not per-variation qty.
     */
    private function apply_stock_and_price( int $product_id, array $scraped, $feed_stock = null, bool $is_variable_parent = false ) : void {
        // Price
        if ( ! empty( $scraped['price'] ) ) {
            $price = wc_format_decimal( (string) $scraped['price'] );
            if ( $price !== '' ) {
                update_post_meta( $product_id, '_regular_price', $price );
                update_post_meta( $product_id, '_price', $price );
            }
        }

        // Stock (feed wins)
        if ( $feed_stock !== null ) {
            $qty = max( 0, (int) $feed_stock );

            if ( ! $is_variable_parent ) {
                update_post_meta( $product_id, '_manage_stock', 'yes' );
                update_post_meta( $product_id, '_stock', $qty );
            }

            update_post_meta( $product_id, '_stock_status', $qty > 0 ? 'instock' : 'outofstock' );
        }
    }

    /**
     * Variables Produkt inkl. Variationen anlegen.
     */
    private function create_variable_product_from_scrape( $scraped, $base_sku, $vendor_sku, $source_url, $thumb_id = 0 ) {
        if ( empty( $scraped['variations'] ) ) {
            return new WP_Error( 'no_variations', 'Keine Variationen gefunden.' );
        }

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
            update_post_meta( $parent_id, '_sku', $base_sku );
        }
        if ( $vendor_sku !== '' ) {
            update_post_meta( $parent_id, '_vendor_sku', $vendor_sku );
        }
        if ( $thumb_id ) {
            set_post_thumbnail( $parent_id, $thumb_id );
        }

        // Attribut aus erster Variation ableiten
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

        // Alle Werte sammeln
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

        // Attribut am Parent hinterlegen
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

        // Variationen erstellen
        foreach ( $scraped['variations'] as $var ) {
            $raw_val = isset( $var['attributes'][ $attr_key ] ) ? $var['attributes'][ $attr_key ] : '';
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

            // Attribut-Wert zuweisen
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

}

endif;

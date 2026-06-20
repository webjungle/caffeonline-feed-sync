<?php
/**
 * CaffeOnline Scraper Admin Page
 *
 * Drop this file into your plugin's /includes folder and include it from the main plugin file:
 *   require_once __DIR__ . '/includes/class-caffeonline-scraper.php';
 *
 * It adds a submenu page under your plugin: CaffeOnline Feed Sync → CaffeOnline Scraper
 * Paste one or more product URLs from https://caffeonline.ch/ (one per line) to scrape
 * the title, main image, and price, and create WooCommerce products (draft by default).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'CaffeOnline_Scraper_Page' ) ) :

class CaffeOnline_Scraper_Page {
    const SLUG  = 'caffeonline-scraper';
    const NONCE = 'caffeonline_scraper_nonce';

    public function __construct() {
        // Später ausführen, damit der Parent sicher schon registriert ist
        add_action( 'admin_menu', [ $this, 'register_menu' ], 99 );
        add_action( 'admin_post_caffeonline_scrape', [ $this, 'handle_form_submit' ] );
    }

    public function register_menu() {
        // Kandidaten für deinen Plugin-Parent (ggf. erweitern)
        $candidates = [
            'caffeonline-feed-sync',
            'cofs',
            'caffeonline_feed_sync',
        ];

        $cap  = 'manage_options';
        $hook = false;

        // Versuche, dich unter bestehendem Plugin-Menü einzuhängen
        foreach ( $candidates as $parent_slug ) {
            $hook = add_submenu_page(
                $parent_slug,
                __( 'CaffeOnline Scraper', 'caffeonline-feed-sync' ),
                __( 'CaffeOnline Scraper', 'caffeonline-feed-sync' ),
                $cap,
                self::SLUG,
                [ $this, 'render_page' ]
            );
            if ( $hook ) {
                return; // Erfolg: Wir sind drin
            }
        }

        // Fallback: eigenen Top-Level anlegen (nur wenn nicht vorhanden) und Scraper darunter hängen
        $top_slug = 'caffeonline-feed-sync';
        global $admin_page_hooks;
        if ( empty( $admin_page_hooks[ $top_slug ] ) ) {
            add_menu_page(
                __( 'CaffeOnline Feed Sync', 'caffeonline-feed-sync' ),
                __( 'CaffeOnline Feed Sync', 'caffeonline-feed-sync' ),
                $cap,
                $top_slug,
                function () {
                    echo '<div class="wrap"><h1>CaffeOnline Feed Sync</h1><p>'
                       . esc_html__( 'Use the submenu to access tools like the CaffeOnline Scraper.', 'caffeonline-feed-sync' )
                       . '</p></div>';
                },
                'dashicons-database-import',
                56
            );
        }

        add_submenu_page(
            $top_slug,
            __( 'CaffeOnline Scraper', 'caffeonline-feed-sync' ),
            __( 'CaffeOnline Scraper', 'caffeonline-feed-sync' ),
            $cap,
            self::SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'You do not have sufficient permissions to access this page.', 'caffeonline-feed-sync' ) );
        }

        $last_results = isset( $_GET['results'] ) ? wp_unslash( $_GET['results'] ) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'CaffeOnline Scraper', 'caffeonline-feed-sync' ); ?></h1>
            <p><?php esc_html_e( 'Paste one product URL per line from caffeonline.ch. The scraper will fetch the title, main image, and price. Stock level and purchase price will be filled later from your feed.', 'caffeonline-feed-sync' ); ?></p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( self::NONCE, '_wpnonce' ); ?>
                <input type="hidden" name="action" value="caffeonline_scrape" />

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="co_urls"><?php esc_html_e( 'Product URLs', 'caffeonline-feed-sync' ); ?></label></th>
                            <td>
                                <textarea id="co_urls" name="co_urls" rows="8" cols="80" class="large-text code" placeholder="https://caffeonline.ch/prodotto/..."></textarea>
                                <p class="description"><?php esc_html_e( 'One URL per line.', 'caffeonline-feed-sync' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Product status', 'caffeonline-feed-sync' ); ?></th>
                            <td>
                                <label><input type="radio" name="co_status" value="draft" checked /> <?php esc_html_e( 'Draft (recommended)', 'caffeonline-feed-sync' ); ?></label><br>
                                <label><input type="radio" name="co_status" value="publish" /> <?php esc_html_e( 'Publish', 'caffeonline-feed-sync' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="co_category"><?php esc_html_e( 'Assign to category (optional)', 'caffeonline-feed-sync' ); ?></label></th>
                            <td>
                                <?php
                                wp_dropdown_categories( [
                                    'taxonomy'         => 'product_cat',
                                    'hide_empty'       => false,
                                    'name'             => 'co_category',
                                    'id'               => 'co_category',
                                    'orderby'          => 'name',
                                    'show_option_none' => __( '— None —', 'caffeonline-feed-sync' ),
                                ] );
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button( __( 'Scrape & Create Products', 'caffeonline-feed-sync' ) ); ?>
            </form>

            <?php if ( $last_results ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Results', 'caffeonline-feed-sync' ); ?></h2>
                <?php echo wp_kses_post( $last_results ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_form_submit() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'caffeonline-feed-sync' ) );
        }

        check_admin_referer( self::NONCE );

        if ( ! class_exists( 'WC_Product_Simple' ) ) {
            wp_die( __( 'WooCommerce is required.', 'caffeonline-feed-sync' ) );
        }

        $urls_raw  = isset( $_POST['co_urls'] ) ? (string) wp_unslash( $_POST['co_urls'] ) : '';
        $status    = isset( $_POST['co_status'] ) && $_POST['co_status'] === 'publish' ? 'publish' : 'draft';
        $cat_id    = isset( $_POST['co_category'] ) ? absint( $_POST['co_category'] ) : 0;

        $urls = array_filter( array_map( 'trim', preg_split( '/\r?\n/', $urls_raw ) ) );
        $out  = '';

        if ( empty( $urls ) ) {
            $this->redirect_with_results( '<div class="notice notice-error"><p>' . esc_html__( 'No URLs provided.', 'caffeonline-feed-sync' ) . '</p></div>' );
        }

        foreach ( $urls as $url ) {
            $row_html = $this->process_single_url( $url, $status, $cat_id );
            $out     .= $row_html; // already escaped within
        }

        $table = '<table class="widefat fixed striped"><thead><tr>'
               . '<th>' . esc_html__( 'URL', 'caffeonline-feed-sync' ) . '</th>'
               . '<th>' . esc_html__( 'Title', 'caffeonline-feed-sync' ) . '</th>'
               . '<th>' . esc_html__( 'Price', 'caffeonline-feed-sync' ) . '</th>'
               . '<th>' . esc_html__( 'Result', 'caffeonline-feed-sync' ) . '</th>'
               . '</tr></thead><tbody>' . $out . '</tbody></table>';

        $this->redirect_with_results( $table );
    }

    private function redirect_with_results( $html ) {
        $url = add_query_arg(
            [ 'page' => self::SLUG, 'results' => rawurlencode( wp_kses_post( $html ) ) ],
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    private function process_single_url( $url, $status, $cat_id ) {
        if ( ! preg_match( '#^https?://(?:www\.)?caffeonline\.ch/#i', $url ) ) {
            return '<tr><td>' . esc_html( $url ) . '</td><td colspan="3"><span class="notice-error">' . esc_html__( 'URL must be from caffeonline.ch', 'caffeonline-feed-sync' ) . '</span></td></tr>';
        }

        $response = wp_remote_get( $url, [ 'timeout' => 20, 'redirection' => 5, 'user-agent' => 'Mozilla/5.0 (WordPress Importer)' ] );
        if ( is_wp_error( $response ) ) {
            return '<tr><td>' . esc_html( $url ) . '</td><td colspan="3">' . esc_html( $response->get_error_message() ) . '</td></tr>';
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        if ( $code !== 200 || empty( $body ) ) {
            return '<tr><td>' . esc_html( $url ) . '</td><td colspan="3">' . esc_html__( 'Failed to fetch page HTML.', 'caffeonline-feed-sync' ) . '</td></tr>';
        }

        $parsed = $this->parse_product_page( $body, $url );
        if ( is_wp_error( $parsed ) ) {
            return '<tr><td>' . esc_html( $url ) . '</td><td colspan="3">' . esc_html( $parsed->get_error_message() ) . '</td></tr>';
        }

        // Create product
        $product = new WC_Product_Simple();
        $product->set_status( $status );
        $product->set_catalog_visibility( 'visible' );
        $product->set_name( $parsed['title'] );
        if ( $parsed['price'] > 0 ) {
            $product->set_regular_price( wc_format_decimal( $parsed['price'], wc_get_price_decimals() ) );
        }
        $product_id = $product->save();

        if ( $product_id && $cat_id ) {
            wp_set_object_terms( $product_id, [ (int) $cat_id ], 'product_cat', true );
        }

        // Download & attach image
        if ( $product_id && ! empty( $parsed['image'] ) ) {
            $attachment_id = $this->sideload_image_to_product( $parsed['image'], $product_id, $parsed['title'] );
            if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
                set_post_thumbnail( $product_id, $attachment_id );
            }
        }

        $edit_link = $product_id ? sprintf(
            '<a href="%s" target="_blank">%s</a>',
            esc_url( get_edit_post_link( $product_id, '' ) ),
            esc_html__( 'Edit product', 'caffeonline-feed-sync' )
        ) : '';

        return '<tr>'
            . '<td><a href="' . esc_url( $url ) . '" target="_blank" rel="noreferrer noopener">' . esc_html( $url ) . '</a></td>'
            . '<td>' . esc_html( $parsed['title'] ) . '</td>'
            . '<td>' . ( $parsed['price'] > 0 ? esc_html( wc_price( $parsed['price'] ) ) : '&mdash;' ) . '</td>'
            . '<td>' . ( $product_id ? ( esc_html__( 'Created', 'caffeonline-feed-sync' ) . ' #' . intval( $product_id ) . ' ' . $edit_link ) : esc_html__( 'Failed creating product', 'caffeonline-feed-sync' ) ) . '</td>'
            . '</tr>';
    }

    /**
     * Parse caffeonline.ch product HTML
     * Tries several selectors and finally JSON-LD product schema if present.
     */
    private function parse_product_page( $html, $url ) {
        $title = '';
        $price = 0.0;
        $image = '';

        // 1) Try JSON-LD product schema
        if ( preg_match_all( '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $scripts ) ) {
            foreach ( $scripts[1] as $json ) {
                $data = json_decode( html_entity_decode( trim( $json ) ), true );
                if ( ! $data ) { continue; }
                $items = isset( $data['@type'] ) ? [ $data ] : ( ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) ? $data['@graph'] : [] );
                foreach ( $items as $node ) {
                    if ( isset( $node['@type'] ) && ( $node['@type'] === 'Product' || ( is_array( $node['@type'] ) && in_array( 'Product', $node['@type'], true ) ) ) ) {
                        if ( empty( $title ) && ! empty( $node['name'] ) ) {
                            $title = sanitize_text_field( $node['name'] );
                        }
                        if ( empty( $image ) && ! empty( $node['image'] ) ) {
                            $image = is_array( $node['image'] ) ? reset( $node['image'] ) : $node['image'];
                        }
                        if ( isset( $node['offers'] ) ) {
                            $offers = is_array( $node['offers'] ) && isset( $node['offers'][0] ) ? $node['offers'][0] : $node['offers'];
                            if ( is_array( $offers ) && ! empty( $offers['price'] ) ) {
                                $price = (float) preg_replace( '/[^0-9\.\,]/', '', (string) $offers['price'] );
                                $price = $this->normalize_price( $price );
                            }
                        }
                    }
                }
            }
        }

        // 2) Fallback: meta tags and common selectors
        if ( empty( $title ) && preg_match( '#<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']#i', $html, $m ) ) {
            $title = sanitize_text_field( html_entity_decode( $m[1] ) );
        }
        if ( empty( $image ) && preg_match( '#<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']#i', $html, $m ) ) {
            $image = esc_url_raw( $m[1] );
        }
        if ( empty( $price ) ) {
            // Try common WooCommerce selectors
            if ( preg_match( '#<p[^>]*class=\"price\"[^>]*>.*?(\d+[\.,]\d{2}).*?</p>#is', $html, $m ) ) {
                $price = $this->normalize_price( $m[1] );
            } elseif ( preg_match( '#<span[^>]*class=\"amount\"[^>]*>\s*([^<]+)\s*</span>#is', $html, $m ) ) {
                $price = $this->normalize_price( $m[1] );
            }
        }

        // 3) DOM parsing for a more robust fallback
        if ( function_exists( 'libxml_use_internal_errors' ) ) {
            libxml_use_internal_errors( true );
        }
        $dom = new DOMDocument();
        if ( @$dom->loadHTML( $html ) ) {
            $xpath = new DOMXPath( $dom );
            if ( empty( $title ) ) {
                $nodes = $xpath->query( '//h1' );
                if ( $nodes && $nodes->length ) {
                    $title = sanitize_text_field( trim( $nodes->item(0)->textContent ) );
                }
            }
            if ( empty( $image ) ) {
                // Try product gallery first image
                $candidates = $xpath->query( '//img[contains(@class, "wp-post-image") or contains(@class, "attachment-woocommerce_thumbnail") or contains(@class, "attachment-woocommerce_single")]' );
                if ( $candidates && $candidates->length ) {
                    $image = $this->absolutize_url( $candidates->item(0)->getAttribute('src'), $url );
                }
            }
        }

        if ( empty( $title ) ) {
            return new WP_Error( 'no_title', __( 'Could not parse product title.', 'caffeonline-feed-sync' ) );
        }

        return [
            'title' => $title,
            'price' => $price ? (float) $price : 0.0,
            'image' => $image ? esc_url_raw( $image ) : '',
        ];
    }

    private function normalize_price( $raw ) {
        if ( is_numeric( $raw ) ) {
            return (float) $raw;
        }
        $s = (string) $raw;
        $s = trim( wp_strip_all_tags( html_entity_decode( $s ) ) );
        // Remove currency symbols and spaces
        $s = preg_replace( '/[^0-9\.,]/', '', $s );
        // If comma is decimal separator like 12,50
        if ( preg_match( '/\d+,[0-9]{2}$/', $s ) && substr_count( $s, ',' ) >= 1 ) {
            $s = str_replace( '.', '', $s );
            $s = str_replace( ',', '.', $s );
        } else {
            // Otherwise strip grouping commas
            $s = str_replace( ',', '', $s );
        }
        return (float) $s;
    }

    private function sideload_image_to_product( $image_url, $product_id, $desc = '' ) {
        if ( empty( $image_url ) ) { return 0; }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Avoid duplicates: check by source URL stored in attachment meta
        $existing = $this->find_existing_attachment_by_source( $image_url );
        if ( $existing ) {
            return $existing;
        }

        $tmp = download_url( $image_url, 30 );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }
        $file_array = [
            'name'     => basename( parse_url( $image_url, PHP_URL_PATH ) ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $product_id, $desc );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return $attachment_id;
        }
        update_post_meta( $attachment_id, '_source_url', esc_url_raw( $image_url ) );
        return (int) $attachment_id;
    }

    private function find_existing_attachment_by_source( $image_url ) {
        global $wpdb;
        $like = '%' . $wpdb->esc_like( $image_url ) . '%';
        $id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_source_url' AND meta_value LIKE %s LIMIT 1", $like ) );
        return $id ? (int) $id : 0;
    }

    private function absolutize_url( $src, $base ) {
        if ( empty( $src ) ) return '';
        // Already absolute
        if ( preg_match( '#^https?://#i', $src ) ) return $src;
        // Protocol-relative
        if ( strpos( $src, '//' ) === 0 ) {
            $scheme = parse_url( home_url(), PHP_URL_SCHEME );
            return $scheme . ':' . $src;
        }
        // Build from base
        $p = parse_url( $base );
        if ( ! $p || empty( $p['scheme'] ) || empty( $p['host'] ) ) return $src;
        $root = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
        if ( isset( $src[0] ) && $src[0] === '/' ) {
            return $root . $src;
        }
        $path = isset( $p['path'] ) ? preg_replace( '#/[^/]*$#', '/', $p['path'] ) : '/';
        return $root . $path . $src;
    }
}

// Bootstrap
new CaffeOnline_Scraper_Page();

endif;

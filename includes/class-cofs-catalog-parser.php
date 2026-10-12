<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Parsers deliberately distinguish retail prices, UVP and private dealer costs. */
class COFS_Catalog_Parser {
    public static function xpath( string $html ) : DOMXPath {
        $old = libxml_use_internal_errors( true );
        $doc = new DOMDocument();
        $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
        libxml_clear_errors();
        libxml_use_internal_errors( $old );
        return new DOMXPath( $doc );
    }

    public static function text( DOMXPath $xp, string $query ) : string {
        $nodes = $xp->query( $query );
        return $nodes && $nodes->length ? trim( preg_replace( '/\s+/u', ' ', $nodes->item( 0 )->textContent ) ) : '';
    }

    public static function decimal( string $value ) : string {
        $value = preg_replace( '/[^0-9,.]/u', '', $value );
        if ( false !== strpos( $value, ',' ) && false !== strpos( $value, '.' ) ) {
            $value = strrpos( $value, ',' ) > strrpos( $value, '.' ) ? str_replace( ',', '.', str_replace( '.', '', $value ) ) : str_replace( ',', '', $value );
        } else {
            $value = str_replace( ',', '.', $value );
        }
        return is_numeric( $value ) && (float) $value > 0 ? number_format( (float) $value, 2, '.', '' ) : '';
    }

    public static function absolute_url( string $url, string $base ) : string {
        if ( 0 === strpos( $url, '//' ) ) return 'https:' . $url;
        if ( preg_match( '~^https?://~i', $url ) ) return $url;
        $parts = parse_url( $base );
        $root = 'https://' . ( $parts['host'] ?? '' );
        if ( 0 === strpos( $url, '/' ) ) return $root . $url;
        if ( 0 === strpos( $url, '?' ) ) return $root . ( $parts['path'] ?? '/' ) . $url;
        return $root . rtrim( dirname( $parts['path'] ?? '/' ), '/' ) . '/' . $url;
    }

    public static function valid_url( string $supplier, string $url ) : bool {
        $host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
        return 'https' === parse_url( $url, PHP_URL_SCHEME ) && in_array( $host, 'thali' === $supplier ? [ 'thali.ch', 'www.thali.ch' ] : [ 'oziicaffe.ch', 'www.oziicaffe.ch' ], true );
    }

    /** Only listing product links and actual pagination links, never the main navigation. */
    public static function listing( string $supplier, string $html, string $url ) : array {
        $xp = self::xpath( $html );
        $query = 'thali' === $supplier ? '//table//tbody//tr//a[@href]' : '//li[contains(concat(" ",normalize-space(@class)," ")," product ")]//a[@href]';
        $products = [];
        foreach ( $xp->query( $query ) as $node ) {
            $href = self::absolute_url( $node->getAttribute( 'href' ), $url );
            if ( ! self::valid_url( $supplier, $href ) ) continue;
            $path = (string) parse_url( $href, PHP_URL_PATH );
            if ( 'thali' === $supplier && ! preg_match( '~^/de/shop/.+/.+/.+/.+~', $path ) ) continue;
            if ( 'oziicaffe' === $supplier && 0 !== strpos( $path, '/product/' ) ) continue;
            $products[] = $href;
        }
        $pages = [];
        foreach ( $xp->query( '//a[@href]' ) as $node ) {
            $href = self::absolute_url( $node->getAttribute( 'href' ), $url );
            if ( ! self::valid_url( $supplier, $href ) ) continue;
            $path = (string) parse_url( $href, PHP_URL_PATH );
            $base_path = preg_replace( '~/page/\d+/?$~', '/', (string) parse_url( $url, PHP_URL_PATH ) );
            if ( 'thali' === $supplier && $path === parse_url( $url, PHP_URL_PATH ) && preg_match( '/[?&]Page=\d+/i', $href ) ) $pages[] = $href;
            if ( 'oziicaffe' === $supplier && preg_replace( '~/page/\d+/?$~', '/', $path ) === $base_path && false !== strpos( $node->getAttribute( 'class' ), 'page-numbers' ) ) $pages[] = $href;
        }
        return [ 'products' => array_values( array_unique( $products ) ), 'pages' => array_values( array_unique( $pages ) ) ];
    }

    private static function structured_product( DOMXPath $xp ) : array {
        foreach ( $xp->query( '//script[@type="application/ld+json"]' ) as $node ) {
            $data = json_decode( $node->textContent, true );
            $queue = [ $data ];
            while ( $queue ) {
                $value = array_shift( $queue );
                if ( ! is_array( $value ) ) continue;
                if ( in_array( 'Product', (array) ( $value['@type'] ?? [] ), true ) ) return $value;
                foreach ( $value as $child ) if ( is_array( $child ) ) $queue[] = $child;
            }
        }
        return [];
    }

    private static function inner_html( DOMNode $node ) : string {
        $html = '';
        foreach ( $node->childNodes as $child ) $html .= $node->ownerDocument->saveHTML( $child );
        return $html;
    }

    /** Each WooCommerce variation is its own offer; parent minimum prices are unsafe. */
    public static function products( string $supplier, string $html, string $url ) {
        if ( 'oziicaffe' === $supplier ) {
            $xp = self::xpath( $html );
            $form = $xp->query( '//form[@data-product_variations]' )->item( 0 );
            if ( $form ) {
                $variations = json_decode( $form->getAttribute( 'data-product_variations' ), true );
                if ( ! is_array( $variations ) || ! $variations ) return new WP_Error( 'cofs_ozii_variations', 'Varianten fehlen: ' . $url );
                $items = [];
                $parent = explode( '?', $url )[0];
                foreach ( $variations as $variation ) {
                    $attributes = (array) ( $variation['attributes'] ?? [] );
                    if ( empty( $variation['variation_id'] ) || ! $attributes || in_array( '', $attributes, true ) ) return new WP_Error( 'cofs_ozii_variations', 'Variante nicht eindeutig: ' . $url );
                    ksort( $attributes );
                    $item = self::product( $supplier, $html, $parent . '?' . http_build_query( $attributes, '', '&', PHP_QUERY_RFC3986 ), $variation );
                    if ( $item instanceof WP_Error ) return $item;
                    $items[] = $item;
                }
                return $items;
            }
        }
        $item = self::product( $supplier, $html, $url );
        return $item instanceof WP_Error ? $item : [ $item ];
    }

    public static function product( string $supplier, string $html, string $url, ?array $variation = null ) {
        $xp = self::xpath( $html );
        $schema = self::structured_product( $xp );
        $title = self::text( $xp, '//h1' );
        if ( ! $title || ! $schema ) return new WP_Error( 'cofs_catalog_parse', 'Keine eindeutige Produktseite: ' . $url );
        $ean = trim( (string) ( $schema['gtin13'] ?? $schema['gtin'] ?? $schema['gtin14'] ?? '' ) );
        $sku = trim( (string) ( $schema['sku'] ?? '' ) );
        $brand = (string) ( is_array( $schema['brand'] ?? null ) ? ( $schema['brand']['name'] ?? '' ) : ( $schema['brand'] ?? '' ) );
        $item = [ 'supplier' => $supplier, 'url' => $url, 'ean' => $ean, 'sku' => $sku, 'title' => $title, 'brand' => $brand, 'stock_mode' => 'availability', 'removed' => false, 'updated_at' => time(), 'description' => '', 'short_description' => '', 'attributes' => [], 'images' => [] ];
        $item['product_kind'] = false !== strpos( $url, '/kaffee/pads/' ) || ( 'oziicaffe' === $supplier && preg_match( '/\bdosettes\b/iu', $title ) ) ? 'pads' : 'machine';
        if ( 'thali' === $supplier ) {
            $details = self::text( $xp, '//div[contains(@class,"product-details")]' );
            if ( preg_match( '/Art\.-Nr\.:\s*(\d+)/u', $details, $match ) ) $item['sku'] = $match[1];
            if ( preg_match( '/Barcode:\s*(\d{8,14})/u', $details, $match ) ) $item['ean'] = $match[1];
            if ( preg_match( '/Hersteller:\s*(.*?)\s*Hersteller Artikel Nr\./u', $details, $match ) ) $item['brand'] = $match[1];
            $price_text = self::text( $xp, '//div[contains(@class,"product-details")]//div[contains(@class,"box-price")]' );
            if ( preg_match( '/CHF\s*([0-9\x{2019}\x{0027},.]+)\s*inkl\.?\s*MwSt/iu', $price_text, $match ) ) $item['uvp'] = self::decimal( $match[1] );
            $regular_price = self::decimal( self::text( $xp, '//div[contains(@class,"product-details")]//div[contains(@class,"box-price")]//del' ) );
            if ( $regular_price && (float) $regular_price > (float) ( $item['uvp'] ?? 0 ) ) $item['uvp'] = $regular_price;
            if ( preg_match( '/(?:VP|UVP):\s*([\d,.]+)/u', $details, $match ) ) $item['uvp'] = self::decimal( $match[1] );
            if ( empty( $item['ean'] ) || empty( $item['sku'] ) || empty( $item['uvp'] ) ) return new WP_Error( 'cofs_thali_identity', 'EAN, Artikelnummer oder UVP fehlen: ' . $url );
            $availability = preg_match( '/Voraussichtliche Verfügbarkeit:\s*(.*)/u', $details, $match ) ? trim( $match[1] ) : '';
            if ( preg_match( '/^(?:Termin unbekannt|nicht verfügbar|nicht mehr lieferbar|\d{1,2}\.\d{1,2}\.\d{4}|\d+\s*(?:[-–]\s*\d+\s*)?(?:Wochen?|Tage?))/iu', $availability ) ) $item['stock'] = 0;
            elseif ( preg_match( '/^Verfügbar/iu', $availability ) ) $item['stock'] = 1;
            else return new WP_Error( 'cofs_thali_stock', 'Verfügbarkeit nicht eindeutig: ' . $url );
            if ( preg_match( '/^(\d{1,2}\.\d{1,2}\.\d{4})/', $availability, $match ) ) $item['expected_delivery'] = $match[1];
            elseif ( preg_match( '/^(\d+\s*(?:[-–]\s*\d+\s*)?(?:Wochen?|Tage?))/iu', $availability, $match ) ) $item['expected_delivery'] = $match[1];
            $description = $xp->query( '//div[contains(@class,"product-description")]//div[contains(@class,"col-lg-8")]' )->item( 0 );
            if ( $description ) {
                foreach ( iterator_to_array( $description->childNodes ) as $child ) if ( 'h2' === $child->nodeName ) $description->removeChild( $child );
                $item['description'] = self::inner_html( $description );
            }
            $info = $xp->query( '//div[contains(@class,"product-details")]//div[contains(@class,"fs-sm")]' )->item( 0 );
            if ( $info ) {
                $short = '';
                foreach ( $info->childNodes as $child ) if ( XML_TEXT_NODE === $child->nodeType && mb_strlen( trim( $child->textContent ) ) > 50 ) $short .= ' ' . trim( $child->textContent );
                $item['short_description'] = trim( $short );
            }
            foreach ( $xp->query( '//a[contains(@class,"product-gallery-thumblist-item")][@data-image]' ) as $node ) $item['images'][] = self::absolute_url( $node->getAttribute( 'data-image' ), $url );
            foreach ( $xp->query( '//table//tr[th or td]' ) as $row ) {
                $cells = $xp->query( './th|./td', $row );
                if ( $cells->length === 2 ) {
                    $label = trim( $cells->item( 0 )->textContent );
                    $value = trim( $cells->item( 1 )->textContent );
                    if ( $label && $value && mb_strlen( $label ) < 70 && mb_strlen( $value ) < 200 ) $item['attributes'][ $label ] = $value;
                }
            }
            $item['uvp_source'] = 'thali';
        } else {
            if ( null === $variation && $xp->query( '//form[@data-product_variations]' )->length ) return new WP_Error( 'cofs_ozii_variations', 'Variablen Artikel nur packungsweise einlesen: ' . $url );
            if ( $variation ) {
                $item['parent_url'] = explode( '?', $url )[0];
                $item['variation_id'] = (int) $variation['variation_id'];
                // Ozii reuses/omits SKUs between packs. Variation ID is scoped and stable.
                $item['sku'] = 'variation-' . $item['variation_id'];
                $item['supplier_sku'] = (string) ( $variation['sku'] ?? '' );
                $item['ean'] = ''; // A parent barcode must never identify every pack.
                $item['retail_price'] = self::decimal( (string) ( $variation['display_price'] ?? '' ) );
                $item['regular_retail_price'] = self::decimal( (string) ( $variation['display_regular_price'] ?? '' ) );
                if ( ! $item['regular_retail_price'] || ! array_key_exists( 'is_in_stock', $variation ) ) return new WP_Error( 'cofs_ozii_variation_data', 'Variantenpreis oder Bestand fehlt: ' . $url );
                $item['stock'] = ! empty( $variation['is_in_stock'] ) && ! empty( $variation['is_purchasable'] ) ? 1 : 0;
                if ( $item['stock'] && preg_match( '/(\d+)\s+en stock/iu', strip_tags( (string) ( $variation['availability_html'] ?? '' ) ), $match ) ) { $item['stock'] = (int) $match[1]; $item['stock_mode'] = 'quantity'; }
                foreach ( $variation['attributes'] as $name => $value ) {
                    $item['attributes'][ $name ] = $value;
                    if ( 'attribute_quantite' === $name && ctype_digit( (string) $value ) ) { $item['pack_quantity'] = (int) $value; $item['title'] .= ' – ' . $value . ' Stück'; }
                }
                $item['description'] = (string) ( $schema['description'] ?? '' );
            } else {
            $stock_text = self::text( $xp, '//p[contains(concat(" ",normalize-space(@class)," ")," stock ")]' );
            if ( preg_match( '/(\d+)\s+en stock/iu', $stock_text, $match ) ) { $item['stock'] = (int) $match[1]; $item['stock_mode'] = 'quantity'; }
            elseif ( preg_match( '/rupture|indisponible|out of stock/iu', $stock_text ) ) $item['stock'] = 0;
            elseif ( preg_match( '/en stock|disponible/iu', $stock_text ) ) $item['stock'] = 1;
            else {
                $offers = $schema['offers'] ?? [];
                $availability = (string) ( $offers['availability'] ?? $offers[0]['availability'] ?? '' );
                if ( false !== strpos( $availability, 'OutOfStock' ) ) $item['stock'] = 0;
                elseif ( false !== strpos( $availability, 'InStock' ) ) $item['stock'] = 1;
                else return new WP_Error( 'cofs_ozii_stock', 'Verfügbarkeit nicht eindeutig: ' . $url );
            }
            $price_query = '//div[contains(@class,"summary")]//p[contains(concat(" ",normalize-space(@class)," ")," price ")]';
            $price = self::decimal( self::text( $xp, $price_query . '//ins//bdi' ) );
            if ( ! $price ) $price = self::decimal( self::text( $xp, $price_query . '//bdi' ) );
            if ( ! $price ) $price = self::decimal( self::text( $xp, $price_query ) );
            $regular = self::decimal( self::text( $xp, $price_query . '//del//bdi' ) );
            $item['retail_price'] = $price;
            // A crossed-out normal retail price is still not a manufacturer UVP.
            $item['regular_retail_price'] = $regular ?: $price;
            $item['description'] = (string) ( $schema['description'] ?? '' );
            }
            foreach ( $xp->query( '//div[contains(@class,"woocommerce-product-gallery")]//img[@src]' ) as $node ) $item['images'][] = self::absolute_url( $node->getAttribute( 'src' ), $url );
            if ( ! $item['brand'] && preg_match( '/^(Aroma|Faber|Didiesse)\b/i', $title, $match ) ) $item['brand'] = $match[1];
        }
        if ( ! $item['images'] && ! empty( $schema['image'] ) ) $item['images'] = (array) $schema['image'];
        $item['images'] = array_values( array_unique( array_filter( $item['images'], static function( $image ) use ( $supplier ) { return is_string( $image ) && self::valid_url( $supplier, $image ); } ) ) );
        return $item;
    }
}

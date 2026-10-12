<?php
// No database/network required. Covers identity, price meaning and failover risks.
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $message;
    public function __construct( $code, $message, $data = null ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function get_option( $key, $default = [] ) { return $GLOBALS['settings'][ $key ] ?? $default; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) ); }
function expect( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); echo "PASS: $message\n"; }
require dirname( __DIR__ ) . '/includes/class-cofs-catalog-parser.php';
require dirname( __DIR__ ) . '/includes/class-cofs-multi-supplier-stock.php';
require dirname( __DIR__ ) . '/includes/class-cofs-supplier-catalog.php';
function wc_prices_include_tax() { return true; }
class WC_Product {
    public $meta = [], $regular = '', $sale = '99.00';
    public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function set_regular_price( $price ) { $this->regular = $price; }
    public function set_sale_price( $price ) { $this->sale = $price; }
    public function set_date_on_sale_from( $date ) {}
    public function set_date_on_sale_to( $date ) {}
}
$choose = new ReflectionMethod( 'COFS_Multi_Supplier_Stock', 'choose_active_supplier' );
$effective = new ReflectionMethod( 'COFS_Multi_Supplier_Stock', 'effective_supplier_stock' );
if ( PHP_VERSION_ID < 80100 ) { $choose->setAccessible( true ); $effective->setAccessible( true ); }
$sources = [ 'thali' => [ 'stock' => 2 ], 'caffeonline' => [ 'stock' => 40 ], 'oziicaffe' => [ 'stock' => 20 ], 'topitaly' => [ 'stock' => 50 ] ];
expect( 'thali' === $choose->invoke( null, $sources, 'topitaly' ), 'Thali wins by priority, regardless of larger fallback stock' );
$sources['thali']['stock'] = 0;
expect( 'caffeonline' === $choose->invoke( null, $sources, 'thali' ), 'Unavailable Thali falls back to CaffeOnline' );
$sources['caffeonline']['stock'] = 0;
expect( 'oziicaffe' === $choose->invoke( null, $sources, 'thali' ), 'Ozii precedes TopItaly' );
$sources['oziicaffe']['stock'] = 0;
expect( 'topitaly' === $choose->invoke( null, $sources, 'thali' ), 'TopItaly remains the final replacement supplier' );
$sources['topitaly']['stock'] = 0;
expect( 'thali' === $choose->invoke( null, $sources, 'topitaly' ), 'All unavailable keeps the primary identity with zero stock' );
$sources['thali']['stock'] = 5; $sources['thali']['removed'] = true;
expect( 0 === $effective->invoke( null, 'thali', $sources['thali'] ), 'Removed source cannot provide stock' );
$GLOBALS['settings']['cofs_settings'] = [ 'thali_stock_tolerance' => 2 ];
expect( 0 === $effective->invoke( null, 'thali', [ 'stock' => 2 ] ), 'Quantity stock honours supplier tolerance' );
expect( 1 === $effective->invoke( null, 'thali', [ 'stock' => 1, 'stock_mode' => 'availability' ] ), 'Binary availability is not mistaken for one remaining unit' );
$schema = '<script type="application/ld+json">{"@type":"Product","gtin13":"8052879792670","sku":"417324","brand":{"name":"Aroma"}}</script>';
$html = '<h1>Aroma Iconica Monica+</h1><div class="product-details"><div class="box-price">CHF 599.00 inkl. MwSt.</div><div class="fs-sm">Art.-Nr.: 417324<br>Barcode: 8052879792670<br>Voraussichtliche Verfügbarkeit: Verfügbar<br>Die Maschine liefert gleichbleibend guten Espresso und besitzt eine Messinggruppe.</div></div><div class="product-description"><div class="col-lg-8"><h2>Monica+</h2>Originaltext mit <strong>Standby</strong>.</div></div>' . $schema;
$item = COFS_Catalog_Parser::product( 'thali', $html, 'https://www.thali.ch/de/shop/haushalt/kaffeemaschinen/kaffeemaschinen-padsystem/monica' );
expect( is_array( $item ) && '599.00' === $item['uvp'] && ! isset( $item['purchase_price'] ), 'Public Thali UVP is separate from private purchase costs' );
expect( '8052879792670' === $item['ean'] && false !== strpos( $item['description'], '<strong>Standby</strong>' ), 'Parser preserves exact EAN and formatted product description' );
expect( COFS_Catalog_Parser::product( 'thali', str_replace( ': Verfügbar<br>', ': Termin unbekannt<br>', $html ), 'https://www.thali.ch/test' )['stock'] === 0, 'Unknown delivery date is unavailable' );
expect( COFS_Catalog_Parser::product( 'thali', str_replace( ': Verfügbar<br>', ': 26.10.2026<br>', $html ), 'https://www.thali.ch/test' )['stock'] === 0, 'Announced delivery date is unavailable until explicitly in stock' );
expect( COFS_Catalog_Parser::product( 'thali', str_replace( ': Verfügbar<br>', ': 1 - 2 Wochen<br>', $html ), 'https://www.thali.ch/test' )['stock'] === 0, 'Supplier lead time is not mistaken for available warehouse stock' );
expect( COFS_Catalog_Parser::product( 'thali', str_replace( 'CHF 599.00 inkl. MwSt.', 'CHF 398.11 exkl. MwSt. VP: 599.00', $html ), 'https://www.thali.ch/test' )['uvp'] === '599.00', 'Authenticated dealer price is not used as UVP' );
expect( COFS_Catalog_Parser::product( 'thali', str_replace( 'CHF 599.00 inkl. MwSt.', 'CHF 499.00 inkl. MwSt.<del>599.00</del>', $html ), 'https://www.thali.ch/test' )['uvp'] === '599.00', 'Supplier promotion uses the regular UVP rather than discounted retail price' );
expect( COFS_Catalog_Parser::product( 'thali', str_replace( 'CHF 599.00 inkl. MwSt.', 'CHF 398.11 exkl. MwSt.', $html ), 'https://www.thali.ch/test' ) instanceof WP_Error, 'Missing UVP blocks a destructive price import' );
$ozii = '<h1>Faber Pro Deluxe</h1><div class="summary"><p class="price">CHF 329.90</p><p class="stock in-stock">5 en stock</p></div><script type="application/ld+json">{"@type":"Product","sku":"FPDB","brand":{"name":"Faber"}}</script>';
$item = COFS_Catalog_Parser::product( 'oziicaffe', $ozii, 'https://www.oziicaffe.ch/product/faber/' );
expect( $item['stock'] === 5 && '329.90' === $item['retail_price'] && ! isset( $item['uvp'] ) && ! isset( $item['purchase_price'] ), 'Ozii stock is read, retail price never becomes UVP or purchase cost' );
$promotion = str_replace( 'CHF 329.90', '<del><bdi>CHF 349.90</bdi></del><ins><bdi>CHF 299.90</bdi></ins>', $ozii );
$item = COFS_Catalog_Parser::product( 'oziicaffe', $promotion, 'https://www.oziicaffe.ch/product/faber/' );
expect( '299.90' === $item['retail_price'] && '349.90' === $item['regular_retail_price'] && ! isset( $item['uvp'] ), 'Ozii promotion separates current and normal retail prices without inventing UVP' );
expect( ! COFS_Catalog_Parser::valid_url( 'thali', 'https://www.thali.ch.attacker.test/item' ), 'Supplier requests reject lookalike domains' );
$listing = '<nav><a href="/de/shop/haushalt/kaffee/pads">Category</a></nav><table><tbody><tr><td><a href="/de/shop/haushalt/kaffee/pads/aroma-strong">Product</a></td></tr></tbody></table>';
expect( count( COFS_Catalog_Parser::listing( 'thali', $listing, 'https://www.thali.ch/de/shop/haushalt/kaffee/pads' )['products'] ) === 1, 'Discovery excludes navigation categories' );
$product = new WC_Product();
$product->meta = [ '_cofs_retail_price_supplier' => 'oziicaffe', '_cofs_retail_price' => '349.90' ];
COFS_Supplier_Catalog::apply_ozii_retail_price( $product );
expect( '384.89' === $product->regular && '349.90' === $product->get_meta( '_cofs_retail_base_price' ) && '' === $product->sale, 'Approved Ozii retail receives exactly 10 percent and replaces stale sale prices' );
COFS_Supplier_Catalog::apply_ozii_retail_price( $product );
expect( '384.89' === $product->regular, 'Repeated supplier runs never compound the Ozii markup' );
$product->meta['_cofs_retail_base_price'] = '159.99';
COFS_Supplier_Catalog::apply_ozii_retail_price( $product );
expect( '175.99' === $product->regular, 'Manual base changes calculate the new selling price rounded to cents' );
$product->meta['_cofs_content_supplier'] = 'thali'; $product->regular = '219.00';
COFS_Supplier_Catalog::apply_ozii_retail_price( $product );
expect( '219.00' === $product->regular, 'Thali master UVP stays authoritative even when Ozii supplies stock' );
$product = new WC_Product();
$product->meta = [ '_cofs_oziicaffe_regular_retail_price' => '159.90' ];
COFS_Supplier_Catalog::apply_ozii_retail_price( $product );
expect( '' === $product->regular, 'Unapproved Ozii source prices cannot replace an existing shop price' );
echo "25 supplier catalogue scenarios passed.\n";

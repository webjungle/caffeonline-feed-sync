<?php
// Standalone scheduling regression tests: no WordPress database or network calls.
define( 'ABSPATH', __DIR__ );
define( 'DB_NAME', 'cofs_cron_test' );

class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class Test_DB {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $busy = false;
    public $locked = false;
    public function prepare( $sql, ...$args ) { return $sql; }
    public function get_var( $sql ) {
        if ( strpos( $sql, 'GET_LOCK' ) !== false ) {
            if ( $this->busy ) return '0';
            $this->locked = true;
            return '1';
        }
        if ( strpos( $sql, 'RELEASE_LOCK' ) !== false ) { $this->locked = false; return '1'; }
        return 1;
    }
}
function __( $text, $domain = '' ) { return $text; }
function home_url() { return 'https://shop.test'; }
function esc_url_raw( $url ) { return $url; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wc_get_product( $id ) { return null; } // Woo persistence is checked on the real import.
function get_post_meta( $id, $key, $single = false ) { return ''; }
function get_option( $key, $default = false ) {
    if ( array_key_exists( $key, $GLOBALS['option_cache'] ) ) return $GLOBALS['option_cache'][ $key ];
    return $GLOBALS['option_cache'][ $key ] = $GLOBALS['options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) { $GLOBALS['option_cache'][ $key ] = $GLOBALS['options'][ $key ] = $value; return true; }
function wp_cache_delete( $key, $group ) { unset( $GLOBALS['option_cache'][ $key ] ); }
function wp_next_scheduled( $hook ) { return $GLOBALS['events'][ $hook ]['time'] ?? false; }
function wp_schedule_single_event( $time, $hook ) {
    $GLOBALS['scheduled_calls']++;
    $GLOBALS['events'][ $hook ] = [ 'time' => $time, 'schedule' => false ];
    return true;
}
function wp_schedule_event( $time, $schedule, $hook ) {
    $GLOBALS['scheduled_calls']++;
    $GLOBALS['events'][ $hook ] = [ 'time' => $time, 'schedule' => $schedule ];
    return true;
}
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['events'][ $hook ] ); }
function wp_remote_get( $url, $args = [] ) {
    $GLOBALS['requests'][] = $url;
    $value = $GLOBALS['responses'][ $url ] ?? new WP_Error( 'missing_fixture', 'Missing response: ' . $url );
    if ( $value instanceof Closure ) return $value();
    return $value;
}
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function response( $body, $status = 200 ) { return [ 'body' => $body, 'status' => $status ]; }
function expect( $ok, $message ) { if ( ! $ok ) throw new RuntimeException( $message ); }
function reset_case() {
    $GLOBALS['wpdb'] = new Test_DB();
    $GLOBALS['options'] = [ 'cofs_settings' => [ 'topitaly_enabled' => 1, 'topitaly_sitemap_url' => 'https://source.test/sitemap.xml', 'topitaly_batch_size' => 1 ] ];
    $GLOBALS['events'] = [];
    $GLOBALS['option_cache'] = [];
    $GLOBALS['scheduled_calls'] = 0;
    $GLOBALS['requests'] = [];
    $GLOBALS['responses'] = [
        'https://source.test/sitemap.xml' => response( '<sitemapindex><sitemap><loc>https://source.test/products.xml</loc></sitemap></sitemapindex>' ),
        'https://source.test/products.xml' => response( '<urlset><url><loc>https://source.test/a</loc></url><url><loc>https://source.test/b</loc></url></urlset>' ),
        'https://source.test/a' => response( '<html><h1>Coffee</h1><span class="ft-product-detail-ean">1234567890123</span><span class="product-detail-ordernumber">TI001</span><input class="quantity-selector-group-input" max="8"></html>' ),
        'https://source.test/b' => response( '<html><h1>Category</h1></html>' ),
    ];
}

require $argv[1] ?? dirname( __DIR__ ) . '/includes/class-cofs-multi-supplier-stock.php';
$tests = 0;
function scenario( $name, $callback ) {
    reset_case();
    $callback();
    expect( ! $GLOBALS['wpdb']->locked, 'DB lock was not released' );
    $GLOBALS['tests']++;
    echo "PASS: $name\n";
}

scenario( 'Recurring hook keeps a single three-hour schedule', function() {
    COFS_Multi_Supplier_Stock::schedule();
    COFS_Multi_Supplier_Stock::schedule();
    expect( $GLOBALS['scheduled_calls'] === 1, 'Duplicate recurring event' );
    expect( $GLOBALS['events'][ COFS_Multi_Supplier_Stock::CRON_HOOK ]['schedule'] === 'cofs_every_three_hours', 'Wrong interval' );
} );
scenario( 'Cron discovers, reads fresh stock and schedules continuation without an admin request', function() {
    COFS_Multi_Supplier_Stock::cron_run();
    $state = COFS_Multi_Supplier_Stock::get_topitaly_state();
    expect( $state['offset'] === 1 && $state['total'] === 2, 'First batch did not run' );
    expect( get_option( COFS_Multi_Supplier_Stock::TOPITALY_CACHE )['1234567890123']['stock'] === 8, 'Source stock not fetched' );
    expect( wp_next_scheduled( COFS_Multi_Supplier_Stock::SCAN_HOOK ) !== false, 'Missing continuation' );
} );
scenario( 'Overlapping cycle and manual start resume instead of resetting progress', function() {
    COFS_Multi_Supplier_Stock::cron_run();
    $before = COFS_Multi_Supplier_Stock::get_topitaly_state();
    $calls = $GLOBALS['scheduled_calls'];
    $result = COFS_Multi_Supplier_Stock::start_topitaly_scan();
    expect( ! empty( $result['resumed'] ), 'Active scan was restarted' );
    expect( COFS_Multi_Supplier_Stock::get_topitaly_state() === $before, 'Progress was overwritten' );
    expect( $GLOBALS['scheduled_calls'] === $calls, 'Duplicate continuation' );
    COFS_Multi_Supplier_Stock::cron_run();
    expect( COFS_Multi_Supplier_Stock::get_topitaly_state()['offset'] === 2, 'Did not resume remaining batch' );
    expect( count( array_keys( $GLOBALS['requests'], 'https://source.test/sitemap.xml', true ) ) === 1, 'Unnecessary sitemap reset' );
} );
scenario( 'Completed scans restart next cycle and replace old supplier stock', function() {
    COFS_Multi_Supplier_Stock::cron_run();
    COFS_Multi_Supplier_Stock::process_topitaly_scan();
    expect( COFS_Multi_Supplier_Stock::get_topitaly_state()['completed_at'] > 0, 'Missing completion time' );
    expect( wp_next_scheduled( COFS_Multi_Supplier_Stock::SCAN_HOOK ) === false, 'Completed scan left a pending step' );
    $GLOBALS['responses']['https://source.test/a']['body'] = str_replace( 'max="8"', 'max="3"', $GLOBALS['responses']['https://source.test/a']['body'] );
    COFS_Multi_Supplier_Stock::cron_run();
    expect( COFS_Multi_Supplier_Stock::get_topitaly_state()['offset'] === 1, 'Next cycle did not restart' );
    expect( get_option( COFS_Multi_Supplier_Stock::TOPITALY_CACHE )['1234567890123']['stock'] === 3, 'Next cycle reused stale stock' );
} );
scenario( 'Failed and empty discovery preserve previous stocks and scan result', function() {
    COFS_Multi_Supplier_Stock::cron_run();
    COFS_Multi_Supplier_Stock::process_topitaly_scan();
    $cache = get_option( COFS_Multi_Supplier_Stock::TOPITALY_CACHE );
    foreach ( [ new WP_Error( 'network', 'Network failure' ), response( '<html>Maintenance</html>' ) ] as $failure ) {
        $GLOBALS['responses']['https://source.test/sitemap.xml'] = $failure;
        COFS_Multi_Supplier_Stock::cron_run();
        $state = COFS_Multi_Supplier_Stock::get_topitaly_state();
        expect( ! empty( $state['last_start_error'] ) && $state['offset'] === 2, 'Failure replaced previous scan' );
        expect( get_option( COFS_Multi_Supplier_Stock::TOPITALY_CACHE ) === $cache, 'Failure changed supplier stock' );
    }
} );
scenario( 'Parallel AJAX or cron processing does not fetch or overwrite a locked batch', function() {
    COFS_Multi_Supplier_Stock::cron_run();
    $before = COFS_Multi_Supplier_Stock::get_topitaly_state();
    $count = count( $GLOBALS['requests'] );
    $GLOBALS['wpdb']->busy = true;
    expect( ! empty( COFS_Multi_Supplier_Stock::process_topitaly_scan()['busy'] ), 'Concurrent batch was not blocked' );
    expect( ! empty( COFS_Multi_Supplier_Stock::start_topitaly_scan()['busy'] ), 'Concurrent reset was not blocked' );
    expect( COFS_Multi_Supplier_Stock::get_topitaly_state() === $before && count( $GLOBALS['requests'] ) === $count, 'Concurrent request changed data' );
    expect( $GLOBALS['scheduled_calls'] === 1, 'Concurrent worker duplicated continuation' );
} );
scenario( 'Exception releases the database lock', function() {
    $GLOBALS['responses']['https://source.test/sitemap.xml'] = function() { throw new RuntimeException( 'network exception' ); };
    try { COFS_Multi_Supplier_Stock::start_topitaly_scan(); } catch ( RuntimeException $error ) { expect( $error->getMessage() === 'network exception', 'Unexpected exception' ); }
} );
scenario( 'Next locked batch reads progress committed by another request', function() {
    COFS_Multi_Supplier_Stock::cron_run();
    $count = count( $GLOBALS['requests'] );
    // Simulate a second process completing the scan outside this request cache.
    $GLOBALS['options'][ COFS_Multi_Supplier_Stock::TOPITALY_STATE ]['offset'] = 2;
    $result = COFS_Multi_Supplier_Stock::process_topitaly_scan();
    expect( $result['finished'] && $result['offset'] === 2, 'Read stale request-local scan progress' );
    expect( count( $GLOBALS['requests'] ) === $count, 'Repeated a batch another worker already processed' );
} );
scenario( 'Disabled TopItaly does not continue pending imports', function() {
    COFS_Multi_Supplier_Stock::cron_run();
    $state = COFS_Multi_Supplier_Stock::get_topitaly_state();
    $count = count( $GLOBALS['requests'] );
    $settings = get_option( 'cofs_settings' );
    $settings['topitaly_enabled'] = 0;
    update_option( 'cofs_settings', $settings );
    COFS_Multi_Supplier_Stock::cron_run();
    COFS_Multi_Supplier_Stock::process_topitaly_scan();
    expect( $state === COFS_Multi_Supplier_Stock::get_topitaly_state() && count( $GLOBALS['requests'] ) === $count, 'Disabled source was processed' );
} );
echo "$tests scenarios passed.\n";

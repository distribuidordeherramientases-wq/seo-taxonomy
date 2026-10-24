<?php
/**
 * Standalone regression check: rendering Google/Bing report tabs must not call
 * remote APIs, even when date filters change or local snapshots exist.
 */
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_timezone() { return new DateTimeZone( 'Europe/Madrid' ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function get_current_blog_id() { return 1; }
function get_current_user_id() { return 1; }
function get_transient( $key ) { return false; }
function delete_transient( $key ) { return true; }
function get_option( $key, $fallback = false ) {
    return isset( $GLOBALS['tgb_test_options'][ $key ] ) ? $GLOBALS['tgb_test_options'][ $key ] : $fallback;
}
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $params, $url ) { return $url . '?' . http_build_query( $params ); }
function wp_nonce_field( $name ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function wp_nonce_url( $url, $name ) { return $url . '&_wpnonce=test'; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function number_format_i18n( $number, $precision = 0 ) {
    return number_format( (float) $number, $precision, ',', '.' );
}
function wp_parse_url( $url, $part = -1 ) { return parse_url( $url, $part ); }
function wp_remote_get() { throw new RuntimeException( 'Unexpected HTTP GET on report render' ); }
function wp_remote_post() { throw new RuntimeException( 'Unexpected HTTP POST on report render' ); }

require dirname( __DIR__ ) . '/includes/class-taxocommerce-google-bing-reports.php';

foreach ( array( 'analytics', 'search', 'bing' ) as $source ) {
    $_GET = array( 'period' => '7' );
    ob_start();
    TaxoCommerce_Google_Bing_Reports::render( $source );
    $html = ob_get_clean();
    if ( false === strpos( $html, 'Download report' ) || false === strpos( $html, 'No saved data yet' ) ) {
        fwrite( STDERR, 'Unexpected empty state: ' . $source . "\n" );
        exit( 1 );
    }
}

$_GET = array(
    'period' => 'custom',
    'start' => '2026-09-01',
    'end' => '2026-09-07',
);
ob_start();
TaxoCommerce_Google_Bing_Reports::render( 'search' );
$html = ob_get_clean();
if ( false === strpos( $html, '01/09/2026' ) || false === strpos( $html, '25/08/2026' ) ) {
    fwrite( STDERR, "Custom date range comparison failed\n" );
    exit( 1 );
}

$history = array(
    'source' => 'bing',
    'schema' => 1,
    'downloaded_at' => 1791500000,
    'current' => array( 'start' => '2026-08-01', 'end' => '2026-08-07', 'label' => '01/08/2026 – 07/08/2026' ),
    'previous' => array( 'start' => '2026-07-25', 'end' => '2026-07-31', 'label' => '25/07/2026 – 31/07/2026' ),
    'data' => array(
        'traffic' => array(
            'current' => array( 'impressions' => 9, 'clicks' => 3, 'ctr' => 1/3 ),
            'previous' => array( 'impressions' => 6, 'clicks' => 2, 'ctr' => 1/3 ),
        ),
        'crawl' => array(
            'current' => array( 'crawled_pages'=>1,'http_errors'=>0,'in_index'=>1,'code2xx'=>1,'code4xx'=>0,'code5xx'=>0,'crawl_errors'=>0,'blocked_robots'=>0,'in_links'=>1 ),
            'previous' => array( 'crawled_pages'=>1,'http_errors'=>0,'in_index'=>1 ),
        ),
        'daily' => array( 'labels'=>array('1','2'),'current_impressions'=>array(4,5),'previous_impressions'=>array(3,3),'current_clicks'=>array(1,2),'previous_clicks'=>array(1,1) ),
        'queries'=>array(),
        'pages'=>array(),
        'warnings'=>array(),
    ),
);
$GLOBALS['tgb_test_options']['tgb_manual_report_v1_bing'] = array(
    md5( '2026-08-01|2026-08-07' ) => $history,
);
$_GET = array( 'period' => '7' );
ob_start();
TaxoCommerce_Google_Bing_Reports::render( 'bing' );
$html = ob_get_clean();
if ( false === strpos( $html, 'latest available snapshot' ) || false === strpos( $html, '01/08/2026' ) || false === strpos( $html, 'Export JSON copy' ) ) {
    fwrite( STDERR, "Saved snapshot rendering failed\n" );
    exit( 1 );
}
echo "Report rendering smoke test: OK (no network requests)\n";

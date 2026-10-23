<?php
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function get_option( $key, $fallback = array() ) { return $fallback; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function esc_url( $v ) { return htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return esc_html( $v ); }
function get_current_user_id() { return 7; }
function get_transient( $key ) { return false; }
function delete_transient( $key ) { return true; }
function wp_unslash( $v ) { return $v; }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function wp_nonce_field( $v ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function wp_remote_get() { throw new RuntimeException( 'Unexpected remote request' ); }
function wp_safe_remote_get() { throw new RuntimeException( 'Unexpected remote request' ); }

require dirname( __DIR__ ) . '/includes/class-taxocommerce-google-bing-trends.php';
foreach ( array( 'ES', 'DE', 'FR', 'NL', 'US' ) as $geo ) {
    $_GET = array( 'trends_geo' => $geo );
    ob_start();
    TaxoCommerce_Google_Bing_Trends::render();
    $output = ob_get_clean();
    if ( false === strpos( $output, 'Buscar tendencias' ) || false === strpos( $output, 'tgb-trends-results' ) || false !== strpos( $output, 'Búsqueda en tendencia' ) ) {
        fwrite( STDERR, "Trends tab failed: $geo\n" );
        exit( 1 );
    }
}
$rss = '<?xml version="1.0"?><rss xmlns:ht="https://trends.google.com/trending/rss" version="2.0"><channel><item><title>taladros</title><pubDate>Fri, 23 Oct 2026 10:00:00 GMT</pubDate><ht:approx_traffic>2K+</ht:approx_traffic></item></channel></rss>';
$parsed = TaxoCommerce_Google_Bing_Trends::parse_rss( $rss );
if ( function_exists( 'simplexml_load_string' ) ) {
    if ( is_wp_error( $parsed ) || count( $parsed ) !== 1 || $parsed[0]['title'] !== 'taladros' || $parsed[0]['traffic_label'] !== '2K+' ) {
        fwrite( STDERR, "Google Trends RSS parser failed\n" );
        exit( 1 );
    }
} elseif ( ! is_wp_error( $parsed ) ) {
    fwrite( STDERR, "Missing SimpleXML should return an error\n" );
    exit( 1 );
}
$terms = TaxoCommerce_Google_Bing_Trends::parse_terms( "taladros a batería\namoladoras\ntaladros a batería" );
if ( is_wp_error( $terms ) || count( $terms ) !== 2 ) { fwrite( STDERR, "Terms parsing failed\n" ); exit( 1 ); }
$link = TaxoCommerce_Google_Bing_Trends::explore_link( $terms, '', 'today 12-m' );
if ( strpos( $link, 'geo=' ) !== false || strpos( $link, 'trends/explore' ) === false ) { fwrite( STDERR, "Worldwide explore failed\n" ); exit( 1 ); }
$over = TaxoCommerce_Google_Bing_Trends::parse_terms( "a,b,c,d,e,f" );
if ( ! is_wp_error( $over ) ) { fwrite( STDERR, "Limit failed\n" ); exit( 1 ); }
echo "Trends smoke: OK - no remote call on rendering\n";

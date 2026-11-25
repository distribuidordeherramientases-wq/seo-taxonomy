<?php
/**
 * CLI regression for ChatGPT Search attribution tab.
 *
 * @package TaxoCommerce_Google_Bing
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
function get_option( $key, $fallback = array() ) { return $fallback; }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function get_current_user_id() { return 1; }
function get_transient( $key ) { return false; }
function delete_transient( $key ) { return true; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_nonce_field( $name ) { echo '<input type="hidden" name="_wpnonce">'; }
function __( $value, $domain = '' ) { return $value; }
function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function number_format_i18n( $value ) { return (string) $value; }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function wp_remote_get() { throw new RuntimeException( 'GET must not call external APIs' ); }
function wp_remote_post() { throw new RuntimeException( 'GET must not call external APIs' ); }

class TaxoCommerce_Google_Bing_Google_Service {
    public static $requests = array();
    public static function analytics_run_report( $request ) {
        self::$requests[] = $request;
        if ( 'chatgpt.com' !== $request['dimensionFilter']['filter']['inListFilter']['values'][0] ) {
            throw new RuntimeException( 'Invalid GA4 sessionSource filter.' );
        }
        if ( ! empty( $request['dimensions'] ) ) {
            return array(
                'rows' => array(
                    array( 'dimensionValues' => array( array( 'value' => '/producto/taladro/?utm_source=chatgpt.com' ) ), 'metricValues' => array( array( 'value' => '4' ) ) ),
                    array( 'dimensionValues' => array( array( 'value' => '/producto/taladro/?secret=123' ) ), 'metricValues' => array( array( 'value' => '2' ) ) ),
                    array( 'dimensionValues' => array( array( 'value' => '/otro/?x=1' ) ), 'metricValues' => array( array( 'value' => '1' ) ) ),
                ),
            );
        }
        return array(
            'rows' => array(
                array( 'metricValues' => array(
                    array( 'value' => '10' ),
                    array( 'value' => '9' ),
                    array( 'value' => '7' ),
                    array( 'value' => '23' ),
                ) ),
            ),
        );
    }
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-chatgpt-search.php';

$period = TaxoCommerce_Insights_ChatGPT_Search::periods();
if ( 28 !== ( ( strtotime( $period['end'] ) - strtotime( $period['start'] ) ) / 86400 ) + 1 ) {
    throw new RuntimeException( 'Incorrect period width.' );
}
$report = TaxoCommerce_Insights_ChatGPT_Search::fetch();
if ( count( TaxoCommerce_Google_Bing_Google_Service::$requests ) !== 3
    || 10 !== $report['current']['sessions']
    || 7 !== $report['current']['engaged_sessions']
    || 6 !== $report['landing_pages']['/producto/taladro/']
    || isset( $report['landing_pages']['/producto/taladro/?secret=123'] )
    || null !== $report['organic_search_impressions']
    || null !== $report['organic_search_ctr'] ) {
    throw new RuntimeException( 'Incorrect GA4 attribution or invented ChatGPT organic metrics.' );
}
ob_start();
TaxoCommerce_Insights_ChatGPT_Search::render();
$html = ob_get_clean();
if ( false === strpos( $html, 'Consultar GA4' )
    || false === strpos( $html, 'no proporciona actualmente una API pública' )
    || false !== strpos( $html, 'secret=123' )
    || false !== strpos( $html, '100 %' ) ) {
    throw new RuntimeException( 'Incorrect tab rendering or leakage of query parameters.' );
}
if ( 3 !== count( TaxoCommerce_Google_Bing_Google_Service::$requests ) ) {
    throw new RuntimeException( 'Rendering unexpectedly called GA4.' );
}
echo "ChatGPT Search GA4 tab smoke: OK.\n";

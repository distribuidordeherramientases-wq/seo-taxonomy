<?php
/** Smoke contract for the customer-facing Analyst commercial summary. */
define( 'ABSPATH', '/' );
function number_format_i18n( $v, $d = 0 ) { return number_format( (float) $v, $d ); }
function remove_accents( $s ) { return str_replace( array( 'ñ', 'á' ), array( 'n', 'a' ), $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function admin_url( $s ) { return '/wp-admin/' . $s; }
function add_query_arg( $p, $url ) { return $url . '?' . http_build_query( $p ); }
require_once dirname( __DIR__ ) . '/includes/class-taxocommerce-google-bing-commercial.php';
function check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
$empty = TaxoCommerce_Google_Bing_Commercial::build( array(), array() );
check( null === $empty['kpis']['sessions']['current'], 'Missing GA4 must not become 0' );
check( null === $empty['kpis']['clicks']['current'], 'Missing Search must not become 0' );
check( count( $empty['directives'] ) === 2, 'Missing providers should trigger update directions' );
$prepared = array(
    'search' => array(
        'snapshot' => array( 'available' => true ),
        'summary' => array(
            'current' => array( 'clicks' => 11, 'impressions' => 3392 ),
            'previous' => array( 'clicks' => 11, 'impressions' => 3306 ),
        ),
    ),
    'analytics' => array(
        'snapshot' => array( 'available' => true ),
        'summary' => array( 'current' => array( 'sessions' => 359, 'ecommercePurchases' => 1 ), 'previous' => array( 'sessions' => 250, 'ecommercePurchases' => 0 ) ),
        'funnel' => array( 'current' => array( 'view_item' => 152, 'add_to_cart' => 11, 'begin_checkout' => 5, 'purchase' => 1 ), 'previous' => array( 'purchase' => 0 ) ),
        'countries' => array( 'labels' => array( 'Spain', 'United States' ), 'current' => array( 127, 147 ) ),
    ),
);
$r = TaxoCommerce_Google_Bing_Commercial::build( $prepared, array( 'available' => true ) );
check( $r['kpis']['clicks']['previous'] === 11, 'GSC comparison broken' );
check( $r['kpis']['sessions']['previous'] === 250, 'GA4 comparison broken' );
check( $r['kpis']['purchase_per_session_pct'] === 0.28, 'Event/session rate broken' );
check( $r['countries']['us_users'] === 147, 'Country split broken' );
$ids = array_column( $r['directives'], 'id' );
check( in_array( 'CHECKOUT_TEST', $ids, true ), 'Checkout test missing' );
check( in_array( 'SEARCH_VISIBILITY_TO_CLICKS', $ids, true ), 'Visibility issue missing' );
check( in_array( 'CHECK_MARKET_PRICES', $ids, true ), 'Market cross-check missing' );
check( count( $r['directives'] ) <= 6, 'Summary not short' );
check( strpos( json_encode( $r ), 'price_malo' ) === false, 'Invented competitor price' );
ob_start();
TaxoCommerce_Google_Bing_Commercial::render( $r );
$html = ob_get_clean();
check( strpos( $html, 'Open Market' ) !== false, 'Market link missing' );
check( strpos( $html, 'tgb_tab=mercado' ) !== false, 'Market tab key mismatch' );
check( strpos( $html, 'Data limitations' ) !== false, 'Limitations disclosure missing' );
check( substr_count( $html, 'tgb-analyst-commercial-action' ) <= 6, 'Rendered too many actions' );
$incomplete = array( 'search' => array( 'snapshot' => array( 'available' => true ), 'summary' => array( 'current' => array( 'clicks' => 0, 'impressions' => 0 ) ) ) );
$missing = TaxoCommerce_Google_Bing_Commercial::build( $incomplete, array() );
check( null === $missing['kpis']['clicks']['previous'], 'Missing baseline must not become zero' );
echo "OK: commercial summary, missing data, period comparisons, market, funnel, and rendering\n";

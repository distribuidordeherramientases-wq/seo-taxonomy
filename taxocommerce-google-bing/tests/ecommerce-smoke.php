<?php
/**
 * Standalone functional checks for Insights ecommerce configuration and gating.
 * Run: php tests/ecommerce-smoke.php
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['tci_options'] = array();
$GLOBALS['tci_actions'] = array();
$GLOBALS['tci_consent'] = false;
$GLOBALS['tci_admin'] = false;
$GLOBALS['tci_is_admin'] = false;
$GLOBALS['tci_doing_ajax'] = false;
function get_option( $name, $default = array() ) { return $GLOBALS['tci_options'][ $name ] ?? $default; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function add_action( $name, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['tci_actions'][ $name ][] = $callback; }
function is_admin() { return $GLOBALS['tci_is_admin']; }
function wp_doing_ajax() { return $GLOBALS['tci_doing_ajax']; }
function current_user_can( $capability ) { return $GLOBALS['tci_admin']; }
function wp_has_consent( $category ) { return 'statistics' === $category && $GLOBALS['tci_consent']; }
function apply_filters( $filter, $value ) { return $value; }
function __( $str, $domain = '' ) { return $str; }
function esc_html__( $str, $domain = '' ) { return $str; }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_html( $v ); }
function checked( $value, $expected, $echo = true ) { $out = $value === $expected ? 'checked="checked"' : ''; if ( $echo ) { echo $out; } return $out; }
function selected( $value, $expected, $echo = true ) { $out = $value === $expected ? 'selected="selected"' : ''; if ( $echo ) { echo $out; } return $out; }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_nonce_field( $nonce ) { echo '<input name="_wpnonce" value="ok">'; }
function submit_button( $text ) { echo '<button type="submit">' . esc_html( $text ) . '</button>'; }
function WC() { return (object) array( 'session' => null ); }
function wc_get_product( $id ) { return null; }

require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-ecommerce.php';
require dirname( __DIR__ ) . '/includes/ecommerce/ga4-events.php';

function ecommerce_assert( $condition, $error ) {
    if ( ! $condition ) { throw new RuntimeException( $error ); }
}
$id = 'G-DBBND5D5CS';
$GLOBALS['tci_options']['taxocommerce_insights_head_tags'] = array(
    'ga4' => array(
        'name' => 'Google Analytics 4',
        'kind' => 'tracking',
        'enabled' => true,
        'provider' => 'google_analytics',
        'provider_id' => $id,
        'code' => '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $id . '"></script>',
    ),
);
ecommerce_assert( 'disabled' === TaxoCommerce_Insights_Ecommerce::readiness(), 'Opt-in defaults to disabled' );
ecommerce_assert( ! TaxoCommerce_Insights_Ecommerce::active(), 'Opt-in disabled must never emit events' );
$GLOBALS['tci_options'][ TaxoCommerce_Insights_Ecommerce::OPTION ] = array( 'enabled' => 1, 'measurement_id' => $id );
ecommerce_assert( 'ready' === TaxoCommerce_Insights_Ecommerce::readiness(), 'Active tag and WooCommerce must be sufficient' );
ecommerce_assert( ! TaxoCommerce_Insights_Ecommerce::active(), 'No statistics consent must block collection' );
$GLOBALS['tci_consent'] = true;
ecommerce_assert( TaxoCommerce_Insights_Ecommerce::active(), 'Consent and enabled GA4 should permit collection' );
ecommerce_assert( tci_google_ecommerce_tracking_active(), 'Modern module must not depend on legacy GA4 activation' );
$GLOBALS['tci_admin'] = true;
ecommerce_assert( ! tci_google_ecommerce_tracking_active(), 'Exclude logged-in administrators' );
$GLOBALS['tci_admin'] = false;
$GLOBALS['tci_options']['seo_google_search_settings'] = array( 'tracking_enabled' => 1, 'measurement_id' => $id );
ecommerce_assert( 'legacy_conflict' === TaxoCommerce_Insights_Ecommerce::readiness(), 'Old GA4 frontend must block new events' );
ecommerce_assert( ! tci_google_ecommerce_tracking_active(), 'Never duplicate events while old plugin tracks' );
$GLOBALS['tci_options']['seo_google_search_settings']['tracking_enabled'] = 0;
$GLOBALS['tci_options'][ TaxoCommerce_Insights_Ecommerce::OPTION ]['measurement_id'] = 'G-INVALID1234';
ecommerce_assert( 'missing_tag' === TaxoCommerce_Insights_Ecommerce::readiness(), 'Unregistered ID must not be tracked' );
$GLOBALS['tci_options'][ TaxoCommerce_Insights_Ecommerce::OPTION ]['measurement_id'] = $id;
$GLOBALS['tci_is_admin'] = true;
ecommerce_assert( ! tci_google_ecommerce_tracking_active(), 'Do not track admin screens' );
$GLOBALS['tci_is_admin'] = false;
ob_start();
TaxoCommerce_Insights_Ecommerce::render();
$html = ob_get_clean();
foreach ( array( 'Comercio electrónico', 'Google Analytics 4', 'G-DBBND5D5CS',
    'tci_ecommerce_save', 'enabled', 'measurement_id',
    'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart',
    'begin_checkout', 'add_shipping_info', 'add_payment_info', 'purchase' ) as $needle ) {
    ecommerce_assert( false !== strpos( $html, $needle ), 'UI missing: ' . $needle );
}
foreach ( array( 'wp_footer', 'woocommerce_add_to_cart',
    'woocommerce_cart_item_removed', 'woocommerce_after_cart_item_quantity_update',
    'wp_ajax_tci_google_ecommerce_pop_queue', 'wp_ajax_nopriv_tci_google_ecommerce_pop_queue' ) as $hook ) {
    ecommerce_assert( ! empty( $GLOBALS['tci_actions'][ $hook ] ), 'Missing event hook: ' . $hook );
}
echo "Insights ecommerce configuration and event hooks smoke test passed\n";

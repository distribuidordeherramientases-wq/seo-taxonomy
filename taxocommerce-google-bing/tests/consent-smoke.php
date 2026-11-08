<?php
/**
 * No-network consent regression with WordPress stubs.
 * Covers option lifecycle, editable legal defaults, strict opt-in, cookie
 * revision, XSS escaping, Clarity Consent V2, and cache separation.
 */
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_GOOGLE_BING_VERSION', '1.1.25' );
define( 'TAXOCOMMERCE_GOOGLE_BING_URL', 'https://shop.test/wp-content/plugins/taxocommerce-google-bing/' );
$GLOBALS['opts'] = array();
$GLOBALS['locale'] = 'es_ES';
$GLOBALS['cache_called'] = false;
$GLOBALS['assets'] = array();
function consent_test( $value, $why ) {
    if ( ! $value ) {
        fwrite( STDERR, 'FAIL consent: ' . $why . PHP_EOL );
        exit( 1 );
    }
}
function add_option( $key, $value, $deprecated = '', $autoload = false ) {
    if ( isset( $GLOBALS['opts'][ $key ] ) ) return false;
    $GLOBALS['opts'][ $key ] = $value;
    return true;
}
function get_option( $key, $default = array() ) {
    return $GLOBALS['opts'][ $key ] ?? $default;
}
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function determine_locale() { return $GLOBALS['locale']; }
function get_locale() { return $GLOBALS['locale']; }
function is_multisite() { return false; }
function is_admin() { return false; }
function is_feed() { return false; }
function wp_unslash( $value ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_js( $value ) { return addslashes( $value ); }
function esc_url( $value ) { return esc_attr( $value ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function add_action( $name, $callback, $priority = 10, $args = 1 ) { return true; }
function add_filter( $name, $callback, $priority = 10, $args = 1 ) { return true; }
function wp_enqueue_script( $handle, $url ) { $GLOBALS['assets'][] = $url; }
function wp_enqueue_style( $handle, $url ) { $GLOBALS['assets'][] = $url; }
function wp_add_inline_script( $handle, $script, $placement = 'after' ) {
    $GLOBALS['config'] = $script;
}
function nocache_headers() { $GLOBALS['cache_called'] = true; }

require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-consent.php';

TaxoCommerce_Insights_Consent::install();
$cfg = get_option( TaxoCommerce_Insights_Consent::OPTION );
consent_test( is_array( $cfg ) && 0 === $cfg['enabled'], 'must be disabled at installation' );
consent_test( 5 === count( $cfg['texts'] ), 'all five required locales' );
foreach ( array( 'es', 'en', 'fr', 'de', 'nl' ) as $language ) {
    consent_test( ! empty( $cfg['texts'][ $language ]['legal'] ), 'legal text missing: ' . $language );
    consent_test( ! empty( $cfg['texts'][ $language ]['accept'] ) &&
        ! empty( $cfg['texts'][ $language ]['reject'] ), 'accept/reject labels missing' );
}
$cfg['texts']['es']['intro'] = 'Texto propio del cliente <script>alert(1)</script>';
$GLOBALS['opts'][ TaxoCommerce_Insights_Consent::OPTION ] = $cfg;
TaxoCommerce_Insights_Consent::install();
consent_test( 'Texto propio del cliente <script>alert(1)</script>' ===
    get_option( TaxoCommerce_Insights_Consent::OPTION )['texts']['es']['intro'],
    'activation overwrote custom legal text' );
consent_test( ! TaxoCommerce_Insights_Consent::active(), 'consent must remain inactive before review' );

$cfg['enabled'] = 1;
$cfg['legal_reviewed'] = 1;
$cfg['cookie_policy_url'] = 'https://shop.test/politica-cookies/';
$GLOBALS['opts'][ TaxoCommerce_Insights_Consent::OPTION ] = $cfg;
consent_test( TaxoCommerce_Insights_Consent::active(), 'reviewed site consent manager not enabled' );
consent_test( ! TaxoCommerce_Insights_Consent::has_consent( 'statistics' ), 'missing cookie authorized statistics' );
$_COOKIE['tci_consent'] = '1%7C1%7C1%7C0%7C0';
consent_test( TaxoCommerce_Insights_Consent::has_consent( 'statistics' ), 'statistics consent cookie ignored' );
consent_test( ! TaxoCommerce_Insights_Consent::has_consent( 'marketing' ), 'marketing permitted without consent' );
consent_test( TaxoCommerce_Insights_Consent::tracking_allowed( false, array( 'provider' => 'clarity' ) ), 'Clarity blocked despite consent' );
consent_test( ! TaxoCommerce_Insights_Consent::tracking_allowed( true, array( 'provider' => 'google_tag_manager' ) ), 'marketing GTM allowed without permission' );
consent_test( 'optin' === TaxoCommerce_Insights_Consent::consent_type( false ), 'WP Consent API type not opt-in' );
consent_test( TaxoCommerce_Insights_Consent::consent_api( false, 'statistics' ), 'WP Consent API integration missing' );
consent_test( ! TaxoCommerce_Insights_Consent::consent_api( true, 'marketing' ), 'WP Consent API marketing default deny broken' );

$GLOBALS['locale'] = 'es_ES';
ob_start();
TaxoCommerce_Insights_Consent::banner();
$markup = ob_get_clean();
consent_test( false !== strpos( $markup, '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'legal text HTML not escaped' );
consent_test( false === strpos( $markup, '<script>alert(1)</script>' ), 'legal text XSS' );
consent_test( false !== strpos( $markup, 'data-tci-consent="accept"' ) &&
    false !== strpos( $markup, 'data-tci-consent="reject"' ) &&
    false !== strpos( $markup, 'data-tci-consent="configure"' ), 'equal prominence buttons missing' );
consent_test( false !== strpos( $markup, 'data-tci-category="statistics"' ) &&
    false !== strpos( $markup, 'data-tci-category="marketing"' ), 'purpose-specific choice missing' );

$GLOBALS['opts']['taxocommerce_insights_head_tags'] = array(
    array( 'enabled' => true, 'provider' => 'clarity', 'kind' => 'tracking' ),
);
ob_start();
TaxoCommerce_Insights_Consent::clarity_consent();
$clarity = ob_get_clean();
consent_test( false !== strpos( $clarity, 'consentv2' )
    && false !== strpos( $clarity, 'analytics_Storage:"granted"' )
    && false !== strpos( $clarity, 'ad_Storage:"denied"' ), 'Clarity Consent V2 mapping incorrect' );

$_COOKIE['tci_consent'] = '1%7C1%7C0%7C0%7C0';
ob_start();
TaxoCommerce_Insights_Consent::clarity_consent();
$denied = ob_get_clean();
consent_test( '' === $denied, 'Clarity ran after explicit rejection' );
$_COOKIE['tci_consent'] = '1%7C0%7C1%7C1%7C1';
consent_test( ! TaxoCommerce_Insights_Consent::has_consent( 'statistics' ), 'stale revision accepted' );
$_COOKIE['tci_consent'] = '1%7C1%7C1%7C1%7C1<script>';
consent_test( ! TaxoCommerce_Insights_Consent::has_consent( 'statistics' ), 'malformed cookie accepted' );
unset( $_COOKIE['tci_consent'] );

TaxoCommerce_Insights_Consent::assets();
consent_test( 2 === count( $GLOBALS['assets'] ) &&
    false !== strpos( $GLOBALS['config'], '"revision":1' ), 'owned assets or cookie config missing' );
TaxoCommerce_Insights_Consent::privacy_cache_headers();
consent_test( true === $GLOBALS['cache_called'] && defined( 'DONOTCACHEPAGE' ), 'shared cached pages can leak consented scripts' );

$GLOBALS['locale'] = 'fr_FR';
consent_test( 'fr' === TaxoCommerce_Insights_Consent::language(), 'French locale fallback wrong' );
$GLOBALS['locale'] = 'xx_XX';
consent_test( 'es' === TaxoCommerce_Insights_Consent::language(), 'unknown locale fallback wrong' );
$uninstall = file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
consent_test( false !== strpos( $uninstall, "delete_option( 'taxocommerce_insights_consent_settings' )" ), 'uninstall does not remove owned texts' );
$client = file_get_contents( dirname( __DIR__ ) . '/assets/consent.js' );
consent_test( false !== strpos( $client, 'window.wp_set_consent' ) &&
    false !== strpos( $client, 'location.reload()' ) &&
    false !== strpos( $client, 'SameSite=Lax' ), 'browser persistence or WP Consent API missing' );

echo "Consent legal texts and lifecycle: 5 languages, opt-in, Clarity, cookie, no-cache, XSS: OK\n";

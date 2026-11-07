<?php
/**
 * Run: php tests/head-tags-smoke.php
 * No WordPress installation or outbound provider calls required.
 */
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_GOOGLE_BING_VERSION', '1.1.21' );
function get_option( $key, $default = array() ) {
    return isset( $GLOBALS['test_head_options'][ $key ] ) ? $GLOBALS['test_head_options'][ $key ] : $default;
}
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function is_admin() { return false; }
function is_feed() { return false; }
function wp_has_consent( $category ) { return 'statistics' === $category ? ! empty( $GLOBALS['test_head_consent'] ) : ( 'marketing' === $category && ! empty( $GLOBALS['test_head_marketing'] ) ); }
function apply_filters( $name, $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_get_script_tag( $args ) { return '<script async src="' . htmlspecialchars( $args['src'], ENT_QUOTES, 'UTF-8' ) . '"></script>'; }
function wp_get_inline_script_tag( $content ) { return '<script>' . $content . '</script>'; }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $key ) ); }
function wp_enqueue_script( $handle, $source, $dependencies, $version, $args ) { $GLOBALS['test_ga_scripts'][] = array( 'handle' => $handle, 'src' => $source, 'args' => $args ); }
function wp_add_inline_script( $handle, $content, $position ) { $GLOBALS['test_ga_inline'][] = array( $handle, $content, $position ); }

require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-head-tags.php';

$verify = '<meta name="google-site-verification" content="sample-token" />';
$bing = '<meta name="msvalidate.01" content="sample-token" />';
$clarity = '<script type="text/javascript">(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};})(window,document,"clarity","script","x");</script>';
$google = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST123"></script><script>window.dataLayer=window.dataLayer||[];</script>';
$clarity_builder = new ReflectionMethod( 'TaxoCommerce_Insights_Head_Tags', 'clarity_code' );
$clarity_builder->setAccessible( true );
$generated_clarity = $clarity_builder->invoke( null, 'ww0i0jxbs8' );
if ( false === strpos( $generated_clarity, 'https://www.clarity.ms/tag/' ) || false === strpos( $generated_clarity, '"ww0i0jxbs8"' ) ) {
    throw new RuntimeException( 'Canonical Clarity loader missing project identifier' );
}
$normalizer = new ReflectionMethod( 'TaxoCommerce_Insights_Head_Tags', 'normalized_provider_code' );
$normalizer->setAccessible( true );
foreach ( array(
    array( 'google_verification', 'abCD_efGH-123456', 'verification', 'google-site-verification' ),
    array( 'google_analytics', 'G-ABC123456', 'tracking', 'googletagmanager.com/gtag/js?id=G-ABC123456' ),
    array( 'google_tag_manager', 'GTM-ABCD123', 'tracking', 'googletagmanager.com/gtm.js?id=' ),
    array( 'clarity', 'ww0i0jxbs8', 'tracking', 'clarity.ms/tag' ),
) as $case ) {
    $html = $normalizer->invoke( null, $case[0], $case[1], $case[2] );
    if ( false === strpos( $html, $case[3] ) ) {
        throw new RuntimeException( 'Known provider not normalized: ' . $case[0] );
    }
}
if ( '' !== $normalizer->invoke( null, 'google_verification', '<script>alert(1)</script>', 'verification' ) ) {
    throw new RuntimeException( 'Unsafe Google verification token accepted' );
}
if ( '' !== $normalizer->invoke( null, 'google_analytics', 'G-ABC123456', 'verification' ) ) {
    throw new RuntimeException( 'Tracking ID accepted as consent-free verification' );
}
if ( '' !== $normalizer->invoke( null, 'google_tag_manager', 'GTM-ABC";alert(1)', 'tracking' ) ) {
    throw new RuntimeException( 'Unsafe GTM identifier accepted' );
}

$validator = new ReflectionMethod( 'TaxoCommerce_Insights_Head_Tags', 'validate_code' );
$validator->setAccessible( true );
foreach ( array( $verify, $bing ) as $example ) {
    if ( ! $validator->invoke( null, $example, 'verification' ) ) {
        throw new RuntimeException( 'Valid verification meta tag rejected' );
    }
}
foreach ( array( $clarity, $google, $generated_clarity, $normalizer->invoke( null, 'google_analytics', 'G-ABC123456', 'tracking' ), $normalizer->invoke( null, 'google_tag_manager', 'GTM-ABCD123', 'tracking' ) ) as $example ) {
    if ( ! $validator->invoke( null, $example, 'tracking' ) ) {
        throw new RuntimeException( 'Valid tracking snippet rejected' );
    }
}
if ( $validator->invoke( null, '<meta http-equiv="refresh" content="0;url=//example.org">', 'verification' ) ) {
    throw new RuntimeException( 'Dangerous meta refresh accepted' );
}
if ( $validator->invoke( null, '<iframe src="//example.org"></iframe>', 'tracking' ) ) {
    throw new RuntimeException( 'Iframe accepted in head' );
}
$GLOBALS['test_head_options'] = array(
    'taxocommerce_insights_head_tags' => array(
        'one' => array( 'name' => 'Google', 'kind' => 'verification', 'code' => $verify, 'enabled' => true ),
        'two' => array( 'name' => 'Clarity', 'kind' => 'tracking', 'code' => $clarity, 'enabled' => true ),
        'three' => array( 'name' => 'Inactive', 'kind' => 'verification', 'code' => $bing, 'enabled' => false ),
        'four' => array( 'name' => 'Google Tag Manager', 'kind' => 'tracking', 'code' => $normalizer->invoke( null, 'google_tag_manager', 'GTM-ABCD123', 'tracking' ), 'enabled' => true, 'provider' => 'google_tag_manager', 'provider_id' => 'GTM-ABCD123' ),
        'five' => array( 'name' => 'GA4', 'kind' => 'tracking',
            'code' => $normalizer->invoke( null, 'google_analytics', 'G-ABC123456', 'tracking' ),
            'enabled' => true, 'provider' => 'google_analytics', 'provider_id' => 'G-ABC123456' ),
    ),
);
$GLOBALS['test_head_consent'] = false;
ob_start();
TaxoCommerce_Insights_Head_Tags::output();
$without_consent = ob_get_clean();
if ( false === strpos( $without_consent, 'google-site-verification' ) || false !== strpos( $without_consent, 'clarity' ) || false !== strpos( $without_consent, 'msvalidate.01' ) ) {
    throw new RuntimeException( 'Consent or inactive-entry gating failed' );
}
$GLOBALS['test_head_consent'] = true;
ob_start();
TaxoCommerce_Insights_Head_Tags::output();
$with_consent = ob_get_clean();
if ( false !== strpos( $with_consent, 'googletagmanager.com/gtm.js' ) ) {
    throw new RuntimeException( 'GTM loaded without marketing consent' );
}
$GLOBALS['test_head_marketing'] = true;
ob_start();
TaxoCommerce_Insights_Head_Tags::output();
$with_marketing = ob_get_clean();
if ( false === strpos( $with_marketing, 'googletagmanager.com/gtm.js' ) ) {
    throw new RuntimeException( 'GTM missing after marketing consent' );
}
if ( false === strpos( $with_consent, 'clarity' ) ) {
    throw new RuntimeException( 'Consented tracking script missing' );
}
// GA4 scripts must be handled by the WordPress enqueue API and consent.
$GLOBALS['test_ga_scripts'] = array();
$GLOBALS['test_ga_inline'] = array();
$GLOBALS['test_head_consent'] = false;
TaxoCommerce_Insights_Head_Tags::enqueue_analytics();
if ( $GLOBALS['test_ga_scripts'] ) {
    throw new RuntimeException( 'GA4 enqueued without statistics consent' );
}
$GLOBALS['test_head_consent'] = true;
TaxoCommerce_Insights_Head_Tags::enqueue_analytics();
if ( 1 !== count( $GLOBALS['test_ga_scripts'] )
    || 1 !== count( $GLOBALS['test_ga_inline'] )
    || false === strpos( $GLOBALS['test_ga_scripts'][0]['src'], 'G-ABC123456' )
    || 'after' !== $GLOBALS['test_ga_inline'][0][2]
    || false === strpos( $GLOBALS['test_ga_inline'][0][1], 'gtag("config","G-ABC123456")' ) ) {
    throw new RuntimeException( 'GA4 not correctly registered using WordPress script API' );
}
ob_start();
TaxoCommerce_Insights_Head_Tags::output();
$head_with_ga = ob_get_clean();
if ( false !== strpos( $head_with_ga, 'googletagmanager.com/gtag/js' ) ) {
    throw new RuntimeException( 'GA4 was echoed directly despite being enqueued' );
}
// If the legacy Google integration is enabled, avoid duplicate gtag requests.
$GLOBALS['test_head_options']['seo_google_search_settings'] = array(
    'tracking_enabled' => true,
    'measurement_id' => 'G-ABC123456',
);
$GLOBALS['test_ga_scripts'] = array();
TaxoCommerce_Insights_Head_Tags::enqueue_analytics();
if ( $GLOBALS['test_ga_scripts'] ) {
    throw new RuntimeException( 'GA4 duplicated an existing Google integration' );
}

echo "Head tags smoke test passed\n";

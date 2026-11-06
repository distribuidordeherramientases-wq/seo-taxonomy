<?php
/**
 * Run: php tests/head-tags-smoke.php
 * No WordPress installation or outbound provider calls required.
 */
define( 'ABSPATH', __DIR__ );
function get_option( $key, $default = array() ) {
    return isset( $GLOBALS['test_head_options'][ $key ] ) ? $GLOBALS['test_head_options'][ $key ] : $default;
}
function is_admin() { return false; }
function is_feed() { return false; }
function wp_has_consent( $category ) { return 'statistics' === $category && ! empty( $GLOBALS['test_head_consent'] ); }
function apply_filters( $name, $value ) { return $value; }

require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-head-tags.php';

$verify = '<meta name="google-site-verification" content="sample-token" />';
$bing = '<meta name="msvalidate.01" content="sample-token" />';
$clarity = '<script type="text/javascript">(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};})(window,document,"clarity","script","x");</script>';
$google = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST123"></script><script>window.dataLayer=window.dataLayer||[];</script>';
$validator = new ReflectionMethod( 'TaxoCommerce_Insights_Head_Tags', 'validate_code' );
$validator->setAccessible( true );
foreach ( array( $verify, $bing ) as $example ) {
    if ( ! $validator->invoke( null, $example, 'verification' ) ) {
        throw new RuntimeException( 'Valid verification meta tag rejected' );
    }
}
foreach ( array( $clarity, $google ) as $example ) {
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
if ( false === strpos( $with_consent, 'clarity' ) ) {
    throw new RuntimeException( 'Consented tracking script missing' );
}
echo "Head tags smoke test passed\n";

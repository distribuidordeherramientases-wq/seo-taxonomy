<?php
/**
 * Deterministic GSC credential/property fallback regression.
 * No WordPress site, provider credentials or HTTP network access required.
 */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct( $code, $message, $data = null ) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    // Match WordPress: get_error_data($code) accepts an error CODE, not a key.
    public function get_error_data( $code = '' ) {
        return '' === $code || $code === $this->code ? $this->data : null;
    }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return strip_tags( (string) $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_remote_retrieve_response_code( $value ) { return (int) $value['code']; }
function wp_remote_retrieve_body( $value ) { return (string) $value['body']; }
function get_option( $name, $fallback = false ) {
    return array_key_exists( $name, $GLOBALS['gsc_options'] ) ? $GLOBALS['gsc_options'][ $name ] : $fallback;
}
final class TaxoCommerce_Google_Bing_Connections {
    public static function google_settings() { return $GLOBALS['gsc_service_settings']; }
    public static function access_token( $scope = '' ) {
        return $GLOBALS['gsc_tokens']['service_account'] ?? new WP_Error( 'no_service', 'Missing account' );
    }
}
final class TaxoCommerce_Google_Bing_OAuth {
    public static function settings() { return $GLOBALS['gsc_oauth_settings']; }
    public static function access_token() {
        return $GLOBALS['gsc_tokens']['oauth'] ?? new WP_Error( 'no_oauth', 'Missing OAuth' );
    }
}
function seo_google_get_access_token() {
    return $GLOBALS['gsc_tokens']['legacy_oauth'] ?? new WP_Error( 'no_legacy', 'Legacy not connected' );
}
function wp_remote_post( $url, $arguments ) {
    $head = (string) ( $arguments['headers']['Authorization'] ?? '' );
    $site_path = explode( '/sites/', $url, 2 );
    $site_path = isset( $site_path[1] ) ? explode( '/searchAnalytics/query', $site_path[1], 2 ) : array();
    $site = rawurldecode( (string) ( $site_path[0] ?? '' ) );
    $body = json_decode( (string) ( $arguments['body'] ?? '' ), true );
    $GLOBALS['gsc_calls'][] = array(
        'header' => $head,
        'site' => $site,
        'start' => $body['startDate'] ?? '',
        'dimensions' => $body['dimensions'] ?? array(),
    );
    if ( ! empty( $GLOBALS['gsc_fatal500'] ) ) {
        return array( 'code' => 500, 'body' => json_encode( array( 'error' => array( 'message' => 'Server unavailable' ) ) ) );
    }
    $allowed = (array) ( $GLOBALS['gsc_allowed'][ $head ] ?? array() );
    if ( ! in_array( $site, $allowed, true ) ) {
        return array( 'code' => 403, 'body' => json_encode( array( 'error' => array( 'message' => 'Insufficient permissions' ) ) ) );
    }
    $rows = array( array(
        'keys' => array( (string) ( $body['startDate'] ?? '' ) ),
        'clicks' => 5,
        'impressions' => 100,
        'ctr' => .05,
        'position' => 9.2,
    ) );
    return array( 'code' => 200, 'body' => json_encode( array( 'rows' => $rows ) ) );
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-google-bing-gsc.php';
function gsc_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAILED GSC credential regression: ' . $message . PHP_EOL );
        exit( 1 );
    }
}
function gsc_scenario( $oauth_site, $service_site, $legacy_site, $allowed, $tokens = null ) {
    $GLOBALS['gsc_oauth_settings'] = array( 'property_id' => $oauth_site );
    $GLOBALS['gsc_service_settings'] = array(
        'search_console_site_url' => $service_site,
        'service_account_json' => $service_site ? 'already stored in WP options' : '',
    );
    $GLOBALS['gsc_options'] = array(
        'seo_google_intelligence_settings' => array( 'property_id' => $legacy_site ),
    );
    $GLOBALS['gsc_tokens'] = null === $tokens
        ? array( 'oauth' => 'modern-good', 'service_account' => 'service-good', 'legacy_oauth' => 'legacy-good' )
        : $tokens;
    $GLOBALS['gsc_allowed'] = $allowed;
    $GLOBALS['gsc_calls'] = array();
    $GLOBALS['gsc_fatal500'] = false;
}
function gsc_download() {
    return TaxoCommerce_Google_Bing_GSC::download( 7, array(
        'current' => array( 'start' => '2026-10-01', 'end' => '2026-10-07' ),
        'previous' => array( 'start' => '2026-09-24', 'end' => '2026-09-30' ),
    ) );
}
$correct = 'https://www.example.test/';
$wrong = 'https://www.example.test';
$domain = 'sc-domain:example.test';

// First preference: the exact property authorized by the modern OAuth user.
gsc_scenario(
    $correct, $wrong, $domain,
    array( 'Bearer modern-good' => array( $correct ), 'Bearer legacy-good' => array( $domain ) )
);
$result = gsc_download();
gsc_assert( ! is_wp_error( $result ), 'Modern OAuth should work despite misconfigured service account property' );
gsc_assert( $correct === $result['source_property'] && 'oauth' === $result['credential_source'], 'Chosen modern property and auth' );
gsc_assert( 100 === $result['summary']['current']['impressions'] && 9.2 === $result['summary']['current']['position'], 'GSC report metrics retained' );
gsc_assert( 10 === count( $GLOBALS['gsc_calls'] ), 'One probe must be reused instead of downloading current_total twice' );
gsc_assert( 10 === count( array_filter( $GLOBALS['gsc_calls'], static function ( $call ) use ( $correct ) {
    return 'Bearer modern-good' === $call['header'] && $correct === $call['site'];
} ) ), 'No source mix between credential and property' );

// Stale modern OAuth must not block the still-working historical OAuth token.
gsc_scenario(
    $wrong, $wrong, $domain,
    array( 'Bearer legacy-good' => array( $domain ) )
);
$result = gsc_download();
gsc_assert( ! is_wp_error( $result ), 'Legacy live OAuth works when new and copied OAuth return 403' );
gsc_assert( $domain === $result['source_property'] && 'legacy_oauth' === $result['credential_source'], 'Legacy authorized site chosen' );
gsc_assert( count( $GLOBALS['gsc_calls'] ) > 10, 'Failed modern candidate should not block legacy probe' );
gsc_assert( 'Bearer legacy-good' === $GLOBALS['gsc_calls'][ count( $GLOBALS['gsc_calls'] ) - 1 ]['header'], 'Later requests reuse legacy authorization' );

// Service account is supported when it is the only configured valid identity.
gsc_scenario( '', $correct, '', array( 'Bearer service-good' => array( $correct ) ) );
$result = gsc_download();
gsc_assert( ! is_wp_error( $result ) && 'service_account' === $result['credential_source'], 'Standalone service account' );
gsc_assert( 10 === count( $GLOBALS['gsc_calls'] ), 'Single authorized service candidate' );

// OAuth may fail while an independent, correctly authorized service account works.
gsc_scenario( $wrong, $correct, '', array( 'Bearer service-good' => array( $correct ) ) );
$result = gsc_download();
gsc_assert( ! is_wp_error( $result ) && 'service_account' === $result['credential_source'], 'Fallback from OAuth to correctly paired service account' );

// Failure must describe identity/property mismatch but never display the token.
gsc_scenario( $wrong, $correct, $domain, array() );
$result = gsc_download();
gsc_assert( is_wp_error( $result ) && 'tgb_gsc_incomplete' === $result->get_error_code(), 'All-denied status reported' );
gsc_assert( 403 === $result->get_error_data()['status'], 'WordPress WP_Error status extraction' );
gsc_assert( false === strpos( $result->get_error_message(), 'modern-good' ), 'Never include OAuth bearer token in diagnostics' );
gsc_assert( false === strpos( $result->get_error_message(), 'legacy-good' ), 'Never include legacy token in diagnostics' );

// Non-auth provider errors must be preserved rather than masked by fallbacks.
gsc_scenario( $correct, $correct, $domain, array() );
$GLOBALS['gsc_fatal500'] = true;
$result = gsc_download();
gsc_assert( is_wp_error( $result ) && 500 === $result->get_error_data()['status'], 'Non-authorization HTTP error' );
gsc_assert( 1 === count( $GLOBALS['gsc_calls'] ), 'Do not retry unrelated HTTP 500 against other credentials' );

// No credentials or property must not create any external API calls.
gsc_scenario( '', '', '', array(), array() );
$result = gsc_download();
gsc_assert( is_wp_error( $result ) && 'tgb_gsc_missing_site' === $result->get_error_code(), 'Missing property' );
gsc_assert( 0 === count( $GLOBALS['gsc_calls'] ), 'No API traffic without a configured property' );

echo "GSC existing connection fallback smoke: OK (OAuth, legacy, service, 403, 500, no credentials)\n";

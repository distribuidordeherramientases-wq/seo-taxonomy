<?php
/**
 * Core external excerpt consent regression (isolated WordPress stubs).
 *
 * @package TaxoCommerceCore
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'TAXOCOMMERCE_CORE_FILE', dirname( __DIR__, 3 ) . '/taxocommerce-core/taxocommerce-core.php' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['tc_excerpt_transients'] = array();
$GLOBALS['tc_excerpt_http'] = 0;
$GLOBALS['tc_excerpt_can'] = true;
$GLOBALS['tc_excerpt_nonce'] = 'valid';

function __( $text, $domain = '' ) { return $text; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return is_string( $value ) ? strip_tags( $value ) : ''; }
function esc_url_raw( $value, $schemes = array() ) { return (string) $value; }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function wp_nonce_field( $action, $name = '_wpnonce' ) { echo '<input name="' . esc_html( $name ) . '" value="valid">'; }
function current_user_can( $capability ) { return 'manage_options' === $capability && $GLOBALS['tc_excerpt_can']; }
function wp_verify_nonce( $nonce, $action ) { return 'tc_core_load_official_excerpt' === $action && $GLOBALS['tc_excerpt_nonce'] === $nonce; }
$GLOBALS['tc_excerpt_options'] = array();
function get_option( $key, $default = '' ) { return $GLOBALS['tc_excerpt_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['tc_excerpt_options'][ $key ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['tc_excerpt_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['tc_excerpt_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['tc_excerpt_transients'][ $key ] ); return true; }
function get_file_data( $path, $headers, $context = '' ) { return array( 'uri' => 'https://asociacionfocazul.com/taxocommerce-core/', 'description' => 'Trusted offline description' ); }
function wp_parse_url( $url, $part = -1 ) { return parse_url( $url, $part ); }
function sanitize_title( $value ) { return strtolower( preg_replace( '/[^a-z0-9-]/i', '-', $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function add_query_arg( $params, $url ) { return $url . '?' . http_build_query( $params ); }
function wp_safe_remote_get( $url, $args ) {
    ++$GLOBALS['tc_excerpt_http'];
    if ( 0 !== strpos( $url, 'https://asociacionfocazul.com/wp-json/wp/v2/pages' ) ) {
        throw new RuntimeException( 'Unexpected external host or path' );
    }
    return array( 'status' => 200, 'body' => json_encode( array(
        array( 'link' => 'https://asociacionfocazul.com/taxocommerce-core/', 'excerpt' => array( 'rendered' => '<p>Public developer excerpt</p>' ) ),
    ) ) );
}
function is_wp_error( $value ) { return false; }
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_html_excerpt( $text, $max, $more = '' ) { return strlen( $text ) > $max ? substr( $text, 0, $max ) . $more : $text; }

require dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-dashboard.php';
$info = require dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/official-excerpt.php';
$method = new ReflectionMethod( 'TaxoCommerce_Core_Dashboard', 'render_official_info' );
$method->setAccessible( true );

function tc_check( $condition, $label ) {
    if ( ! $condition ) {
        throw new RuntimeException( $label );
    }
}
function tc_view( $method, $info ) {
    ob_start();
    $method->invoke( null, $info, 'es' );
    return ob_get_clean();
}
$_POST = array();
$html = tc_view( $method, $info );
tc_check( 0 === $GLOBALS['tc_excerpt_http'], 'Opening dashboard cannot contact developer website.' );
tc_check( false !== strpos( $html, 'Cargar extracto del desarrollador' ), 'Administrator must have an explicit opt-in action.' );
tc_check( false !== strpos( $html, 'Trusted offline description' ), 'Developer description is available offline on first install.' );
tc_check( false !== strpos( $html, 'https://asociacionfocazul.com/taxocommerce-core/' ), 'Public developer link remains available.' );

$_POST = array( 'tc_core_load_official_excerpt' => '1', 'tc_core_official_excerpt_nonce' => 'invalid' );
tc_view( $method, $info );
tc_check( 0 === $GLOBALS['tc_excerpt_http'], 'Invalid nonce cannot request remote excerpt.' );

$GLOBALS['tc_excerpt_can'] = false;
$_POST['tc_core_official_excerpt_nonce'] = 'valid';
tc_view( $method, $info );
tc_check( 0 === $GLOBALS['tc_excerpt_http'], 'Insufficient permission cannot request remote excerpt.' );

$GLOBALS['tc_excerpt_can'] = true;
$html = tc_view( $method, $info );
tc_check( 1 === $GLOBALS['tc_excerpt_http'], 'Only valid explicit action may fetch developer excerpt.' );
tc_check( false !== strpos( $html, 'Public developer excerpt' ), 'Expected excerpt must render.' );

$_POST = array();
tc_view( $method, $info );
tc_check( 1 === $GLOBALS['tc_excerpt_http'], 'A cached excerpt must never cause another external request.' );

$GLOBALS['tc_excerpt_transients'] = array();
tc_view( $method, $info );
tc_check( 1 === $GLOBALS['tc_excerpt_http'], 'After cache expiry ordinary navigation still does not contact external servers.' );
$html = tc_view( $method, $info );
tc_check( false !== strpos( $html, 'Public developer excerpt' ), 'Last verified developer excerpt survives cache expiry without a remote call.' );
echo "Core external developer excerpt consent regression: PASS\n";

<?php
/**
 * Verify the original encrypted Cloudflare option remains compatible.
 * No network access, credentials, or remote writes.
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['tci_cloudflare_options'] = array();
$GLOBALS['tci_cloudflare_actions'] = array();
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $value ) ); }
function wp_parse_args( $arguments, $defaults ) { return array_merge( $defaults, is_array( $arguments ) ? $arguments : array() ); }
function wp_salt( $scheme ) { return 'test-installation-secret-' . $scheme; }
function home_url( $path = '/' ) { return 'https://store.example.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function get_option( $name, $default = array() ) { return $GLOBALS['tci_cloudflare_options'][ $name ] ?? $default; }
function add_action( $hook, $callback, $priority = 10, $num_args = 1 ) { $GLOBALS['tci_cloudflare_actions'][ $hook ] = $callback; }
function absint( $number ) { return abs( (int) $number ); }
function get_transient( $name ) { return null; }

require dirname( __DIR__ ) . '/includes/connections/cloudflare.php';

$original_token = 'sensitive-test-token';
$legacy_cipher = seo_cloudflare_encrypt_token( $original_token );
if ( ! is_string( $legacy_cipher ) || 0 !== strpos( $legacy_cipher, 'v1:' ) ) {
    throw new RuntimeException( 'Cloudflare AES-256-GCM compatibility format missing' );
}
$GLOBALS['tci_cloudflare_options']['seo_cloudflare_settings'] = array(
    'enabled' => 1,
    'zone_name' => 'store.example.test',
    'token_cipher' => $legacy_cipher,
);
if ( $original_token !== seo_cloudflare_api_token() ) {
    throw new RuntimeException( 'Cloudflare previously encrypted token no longer decrypts' );
}
$state = seo_cloudflare_connection_state();
if ( empty( $state['configured'] ) || 'database' !== $state['token_source']
    || 'store.example.test' !== $state['zone_name'] ) {
    throw new RuntimeException( 'Cloudflare original settings were not reused' );
}
if ( ! isset( $GLOBALS['tci_cloudflare_actions']['admin_post_seo_cloudflare_save'] ) ) {
    throw new RuntimeException( 'Cloudflare save handler was not registered' );
}
if ( ! function_exists( 'seo_cloudflare_security_audit' )
    || ! function_exists( 'seo_cloudflare_get_dnssec' )
    || ! function_exists( 'seo_cloudflare_get_security_rulesets' ) ) {
    throw new RuntimeException( 'Cloudflare security diagnostics were not migrated' );
}
$module = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-cloudflare-ui.php' );
if ( false === strpos( $module, 'tci_cloudflare_test' )
    || false === strpos( $module, 'seo_cloudflare_save' )
    || false === strpos( $module, 'nonce' ) ) {
    throw new RuntimeException( 'New Cloudflare Connections controls missing' );
}
echo "Cloudflare legacy credentials and diagnostics migration smoke passed\n";

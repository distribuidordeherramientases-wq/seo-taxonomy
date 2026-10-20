<?php
/**
 * Minimal independent diagnostic contract test.
 * Run: php taxocommerce-system-check/tests/php-check-smoke.php
 */
if ( 'cli' !== PHP_SAPI ) {
    exit;
}
define( 'ABSPATH', '/' );
// Simulate Core's presence in a separate process. This is NOT a full
// WordPress plugin integration test; it checks the independent PHP service.
$with_core = in_array( '--with-core', isset( $argv ) ? $argv : array(), true );
if ( $with_core ) {
    define( 'TAXOCOMMERCE_CORE_VERSION', 'test' );
}
function current_user_can( $capability ) {
    return 'manage_options' === $capability;
}
function admin_url( $path = '' ) {
    return 'https://example.test/wp-admin/' . $path;
}
function add_query_arg( $args, $url ) {
    return $url . '?' . http_build_query( $args );
}
function esc_html( $value ) {
    return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $value ) {
    return esc_html( $value );
}
require_once dirname( __DIR__ ) . '/includes/class-taxocommerce-system-check-php.php';

$required = array(
    'php_version', 'memory_limit', 'max_execution_time',
    'max_input_vars', 'upload_max_filesize', 'post_max_size',
    'ext_curl', 'ext_zip', 'ext_mbstring', 'ext_xml',
    'ext_json', 'ext_openssl', 'image_library', 'ext_intl',
    'opcache',
);
$rows = TaxoCommerce_System_Check_PHP::collect();
$ids = array();
foreach ( $rows as $row ) {
    if ( ! is_array( $row )
        || array_keys( $row ) !== array( 'id', 'label', 'value', 'status', 'detail' )
        || ! in_array( $row['status'], array( 'ok', 'warning', 'info' ), true )
        || isset( $ids[ $row['id'] ] ) ) {
        fwrite( STDERR, "Invalid PHP diagnostic row.\n" );
        exit( 1 );
    }
    $ids[ $row['id'] ] = true;
}
foreach ( $required as $id ) {
    if ( ! isset( $ids[ $id ] ) ) {
        fwrite( STDERR, "Missing diagnostic: {$id}\n" );
        exit( 1 );
    }
}
foreach ( array( '256M' => 268435456, '32M' => 33554432, '1G' => 1073741824, '-1' => -1, 'bogus' => 0 ) as $raw => $expected ) {
    if ( TaxoCommerce_System_Check_PHP::ini_bytes( $raw ) !== $expected ) {
        fwrite( STDERR, "PHP ini conversion failed: {$raw}\n" );
        exit( 1 );
    }
}
foreach ( get_defined_functions()['user'] as $function ) {
    if ( 0 === strpos( $function, 'seo_server_status_' ) || 0 === strpos( $function, 'seo_core_system_test_' ) ) {
        fwrite( STDERR, "Legacy functions must not be loaded by the native PHP checker.\n" );
        exit( 1 );
    }
}
ob_start();
TaxoCommerce_System_Check_PHP::render();
$html = ob_get_clean();
if ( false === strpos( $html, 'Diagnóstico PHP autónomo' )
    || substr_count( $html, '<th scope="row">' ) !== count( $rows )
    || false === strpos( $html, 'tcs_tab=system' )
    || function_exists( 'seo_server_status' ) ) {
    fwrite( STDERR, "Independent PHP view failed.\n" );
    exit( 1 );
}
echo $with_core ? "Independent PHP checks OK (Core simulated).\n" : "Independent PHP checks OK (standalone).\n";

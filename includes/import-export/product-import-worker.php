<?php
/**
 * Launcher CLI del importador de productos.
 *
 * Este archivo no es un endpoint web. Arranca un proceso PHP independiente
 * del scheduler, carga WordPress y mantiene un controlador persistente. El mismo
 * proceso encadena lotes y aplica las pausas del regulador sin volver a pasar
 * por WP-Cron ni Action Scheduler.
 */

if ( 'cli' !== PHP_SAPI ) {
    if ( ! headers_sent() ) {
        http_response_code( 404 );
    }
    exit( 1 );
}

if ( $argc < 7 ) {
    fwrite( STDERR, "Argumentos insuficientes.\n" );
    exit( 64 );
}

$wp_load     = (string) $argv[1];
$user_id     = (int) $argv[2];
$token       = (string) $argv[3];
$dispatch_id = (string) $argv[4];
$not_before  = (int) $argv[5];
$signature   = (string) $argv[6];

if ( '' === $wp_load || ! is_readable( $wp_load ) ) {
    fwrite( STDERR, "No se puede cargar wp-load.php.\n" );
    exit( 66 );
}

if ( ! defined( 'WP_USE_THEMES' ) ) {
    define( 'WP_USE_THEMES', false );
}
if ( ! defined( 'SEO_IE_DIRECT_WORKER' ) ) {
    define( 'SEO_IE_DIRECT_WORKER', true );
}
if ( ! defined( 'SEO_IE_DIRECT_WORKER_LOOP' ) ) {
    define( 'SEO_IE_DIRECT_WORKER_LOOP', true );
}

require_once $wp_load;

if (
    ! function_exists( 'seo_ie_product_import_direct_request_is_valid' )
    || ! function_exists( 'seo_ie_product_import_claim_direct_dispatch' )
    || ! function_exists( 'seo_ie_product_import_run_direct_loop' )
) {
    fwrite( STDERR, "El plugin no ha cargado el motor directo.\n" );
    exit( 69 );
}

if ( ! seo_ie_product_import_direct_request_is_valid( $user_id, $token, $dispatch_id, $not_before, $signature ) ) {
    exit( 0 );
}

$wait = max( 0, $not_before - time() );
if ( 0 < $wait ) {
    sleep( min( 600, $wait ) );
}

if ( ! seo_ie_product_import_claim_direct_dispatch( $user_id, $token, $dispatch_id, $not_before, $signature, 'direct_cli' ) ) {
    exit( 0 );
}

seo_ie_product_import_run_direct_loop( $user_id, $token, 'direct_cli', 3600 );
exit( 0 );

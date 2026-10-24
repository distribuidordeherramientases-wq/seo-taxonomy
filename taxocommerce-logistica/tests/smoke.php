<?php
define( 'ABSPATH', __DIR__ );
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/taxocommerce-logistica.php' );
$logistica = file_get_contents( $root . '/includes/seo-logistica.php' );
$transporte = file_get_contents( $root . '/includes/seo-transporte.php' );

$checks = array(
    strpos( $main, "'seo-logistica'" ) !== false,
    strpos( $main, "'seo-transporte'" ) !== false,
    strpos( $main, "'logistica'" ) !== false,
    strpos( $logistica, 'seo_proveedores_productos' ) !== false,
    strpos( $logistica, 'wc_get_orders' ) !== false,
    strpos( $transporte, 'seo_transporte_costes_settings' ) !== false,
    strpos( $transporte, 'woocommerce_package_rates' ) !== false,
    strpos( $transporte, 'seo_transporte_export' ) !== false,
    strpos( $transporte, 'seo_transporte_import' ) !== false,
    strpos( $logistica, 'SEO_SYSTEM_URL' ) === false,
    strpos( $transporte, 'SEO_SYSTEM_URL' ) === false,
);
foreach ( $checks as $index => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL logistica migration guard ' . ( $index + 1 ) . PHP_EOL );
        exit( 1 );
    }
}
echo "TaxoCommerce Logística migration guards passed.\n";

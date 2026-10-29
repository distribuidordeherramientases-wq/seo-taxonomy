<?php
define( 'ABSPATH', __DIR__ );
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/taxocommerce-logistica.php' );
$transport = file_get_contents( $root . '/includes/seo-transporte.php' );

$checks = array(
    strpos( $main, "Version: 0.2.3" ) !== false,
    strpos( $main, "'taxocommerce-logistica'" ) !== false,
    strpos( $main, "SEO_Transporte_Costes" ) !== false,
    strpos( $main, "seo-logistica.php" ) === false,
    strpos( $main, "add_submenu_page(" ) === false,
    strpos( $main, "taxocommerce_logistica_force_core_parent" ) === false,
    strpos( $transport, "const PAGE        = 'taxocommerce-logistica';" ) !== false,
    strpos( $transport, "add_action('admin_menu', array(__CLASS__, 'register_legacy_page')" ) === false,
    strpos( $transport, 'seo_transporte_costes_settings' ) !== false,
    strpos( $transport, 'woocommerce_package_rates' ) !== false,
    strpos( $transport, 'package_metrics' ) !== false,
    strpos( $transport, 'order_weight_metrics' ) !== false,
    strpos( $transport, 'is_scalar($posted[$key])' ) !== false,
    strpos( $transport, "&& '1' === (string)" ) !== false,
    strpos( $transport, 'sanitize_text_field(wp_unslash((string) $_POST[$key]))' ) !== false,
    strpos( $transport, 'get_shipping_total' ) !== false,
    strpos( $transport, 'seo_transporte_export' ) !== false,
    strpos( $transport, "self::render_tabs('shipping')" ) !== false,
    strpos( $transport, "TaxoCommerce_Logistica_Auto_Test::render()" ) !== false,
    strpos( $transport, "TaxoCommerce_Logistica_User_Guide::render()" ) !== false,
    strpos( $transport, 'seo_transporte_import' ) !== false,
    strpos( $transport, 'seo_proveedores_productos' ) === false,
    strpos( $transport, 'proveedor_id_externo' ) === false,
    strpos( $transport, 'url_origen' ) === false,
    strpos( $transport, 'SEO_SYSTEM_URL' ) === false,
);

foreach ( $checks as $index => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL Logistics shipping-only guard ' . ( $index + 1 ) . PHP_EOL );
        exit( 1 );
    }
}

echo "TaxoCommerce Logistics shipping-only guards passed.\n";

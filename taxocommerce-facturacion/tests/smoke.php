<?php
define( 'ABSPATH', __DIR__ );
$root = dirname( __DIR__ );
$repo = dirname( $root );
$main = file_get_contents( $root . '/taxocommerce-facturacion.php' );
$install = file_get_contents( $root . '/includes/class-seo-facturas-install.php' );
$settings = file_get_contents( $root . '/includes/class-seo-facturas-settings.php' );
$pdf = file_get_contents( $root . '/includes/class-seo-facturas-pdf.php' );
$quotes = file_get_contents( $root . '/includes/class-seo-facturas-quotes.php' );

$checks = array(
    strpos( $main, "class_exists( 'SEO_Facturas_Admin', false )" ) !== false,
    strpos( $main, "'seo-facturas'" ) !== false,
    strpos( $install, 'seo_factura_documents' ) !== false,
    strpos( $settings, 'seo_facturas_company_settings' ) !== false,
    strpos( $settings, 'seo_facturas_tax_settings' ) !== false,
    strpos( $settings, 'seo_facturas_invoice_settings' ) !== false,
    strpos( $settings, 'seo_facturas_proforma_settings' ) !== false,
    strpos( $settings, 'seo_facturas_quote_settings' ) !== false,
    strpos( $pdf, "Dompdf\\\\Dompdf" ) !== false,
    strpos( $quotes, 'render_block_woocommerce/cart' ) !== false,
    is_file( $root . '/includes/templates/document.php' ),
    is_file( $root . '/includes/templates/quote.php' ),
    is_file( $repo . '/includes/facturas/dompdf/autoload.inc.php' ),
);
foreach ( $checks as $index => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL facturacion migration guard ' . ( $index + 1 ) . PHP_EOL );
        exit( 1 );
    }
}
echo "TaxoCommerce Facturación migration guards passed.\n";

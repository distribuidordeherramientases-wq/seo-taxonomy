<?php
/**
 * Static safety checks for the editorial-only WooCommerce product mini-importer.
 * This test does not need WordPress or a database.
 */
$root = dirname( __DIR__ );
$csv = file_get_contents( $root . '/includes/class-taxocommerce-import-export-csv.php' );
$batch = file_get_contents( $root . '/includes/class-taxocommerce-import-export-batch.php' );
$start = strpos( $csv, 'private function import_product_editorial( $data )' );
$end = strpos( $csv, 'private function import_product( $data )', $start );
if ( false === $start || false === $end ) {
    fwrite( STDERR, "Missing editorial-only importer.\n" );
    exit( 1 );
}
$section = substr( $csv, $start, $end - $start );
$required = array(
    "\$product->is_type( 'external' )",
    "\$product->get_sku( 'edit' )",
    "\$product->set_short_description( \$excerpt )",
    "\$product->set_description( \$description )",
    "\$product->set_status( 'publish' )",
    "\$product->save()",
);
$forbidden = array(
    'new WC_Product_External',
    'set_product_url(',
    'set_button_text(',
    'set_price(',
    'set_regular_price(',
    'set_sale_price(',
    'set_category_ids(',
    'set_tag_ids(',
    'set_attributes(',
    'set_stock_quantity(',
    'set_manage_stock(',
    'set_image_id(',
    'set_gallery_image_ids(',
);
foreach ( $required as $needle ) {
    if ( false === strpos( $section, $needle ) ) {
        fwrite( STDERR, "Missing safety condition: {$needle}\n" );
        exit( 1 );
    }
}
foreach ( $forbidden as $needle ) {
    if ( false !== strpos( $section, $needle ) ) {
        fwrite( STDERR, "Unsafe editorial mutation: {$needle}\n" );
        exit( 1 );
    }
}
if ( false === strpos( $batch, "'product_editorial' => array(" ) ||
    false === strpos( $csv, "if ( 'product_editorial' === \$type )" ) ) {
    fwrite( STDERR, "Editorial importer is not registered for single and queued imports.\n" );
    exit( 1 );
}
echo "Editorial CSV safety guards passed.\n";

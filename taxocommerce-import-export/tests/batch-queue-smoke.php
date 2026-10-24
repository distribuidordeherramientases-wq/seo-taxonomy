<?php
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/taxocommerce-import-export.php' );
$core = file_get_contents( $root . '/includes/class-taxocommerce-import-export.php' );
$csv = file_get_contents( $root . '/includes/class-taxocommerce-import-export-csv.php' );
$batch = file_get_contents( $root . '/includes/class-taxocommerce-import-export-batch.php' );

$checks = array(
    strpos( $main, "Version: 0.3.1" ) !== false,
    strpos( $main, "class-taxocommerce-import-export-batch.php" ) !== false,
    strpos( $core, "Importación por lotes" ) !== false,
    strpos( $core, "new TaxoCommerce_Import_Export_Batch" ) !== false,
    strpos( $csv, "import_row_for_batch" ) !== false,
    strpos( $csv, "normalize_headers_for_batch" ) !== false,
    strpos( $csv, "infer_record_type_from_headers" ) !== false,
    strpos( $csv, "'product_id'            => 'id'") !== false,
    strpos( $csv, "'titulo'                => 'name'") !== false,
    strpos( $csv, "'marca_taxonomia'       => 'brand_taxonomy'") !== false,
    strpos( $csv, "'_partial_update'") !== false,
    strpos( $csv, "taxocommerce_ie_partial_identifier" ) !== false,
    strpos( $csv, "taxocommerce_ie_partial_missing_product" ) !== false,
    strpos( $csv, "array_key_exists( 'name', $data )" ) !== false,
    strpos( $csv, "apply_brand_provider_fields" ) !== false,
    strpos( $csv, "resolve_existing_brand_terms" ) !== false,
    strpos( $csv, "product_brand_export_payload" ) !== false,
    strpos( $batch, "taxocommerce_import_export_batch_importers" ) !== false,
    strpos( $batch, "taxocommerce_import_export_batch_detect_type" ) !== false,
    strpos( $batch, "infer_record_type_from_headers" ) !== false,
    strpos( $batch, "taxocommerce-import-export/queue" ) !== false,
    strpos( $batch, "taxocommerce_ie_batch_worker" ) !== false,
    strpos( $batch, "seo_process_supervisor" ) === false,
    strpos( $batch, "seo_ie_process_import_batch_queue" ) === false,
    strpos( $batch, "Action Scheduler" ) === false || strpos( $batch, "as_schedule_single_action" ) !== false,
    strpos( $batch, "Pausar después del archivo actual" ) !== false,
    strpos( $batch, "Detener importación actual" ) !== false,
    strpos( $batch, "detect_delimiter" ) !== false,
    strpos( $batch, "user_id" ) !== false,
    strpos( $batch, "wp_set_current_user" ) !== false,
    strpos( $batch, "remaining" ) !== false,
    strpos( $batch, "worker();" ) !== false,
);

foreach ( $checks as $index => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL batch queue guard ' . ( $index + 1 ) . PHP_EOL );
        exit( 1 );
    }
}

echo "TaxoCommerce Import-Export batch queue guards passed.\n";

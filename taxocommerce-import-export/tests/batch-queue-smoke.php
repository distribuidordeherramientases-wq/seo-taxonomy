<?php
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/taxocommerce-import-export.php' );
$core = file_get_contents( $root . '/includes/class-taxocommerce-import-export.php' );
$csv = file_get_contents( $root . '/includes/class-taxocommerce-import-export-csv.php' );
$batch = file_get_contents( $root . '/includes/class-taxocommerce-import-export-batch.php' );

$checks = array(
    strpos( $main, "Version: 0.3.0" ) !== false,
    strpos( $main, "class-taxocommerce-import-export-batch.php" ) !== false,
    strpos( $core, "Importación por lotes" ) !== false,
    strpos( $core, "new TaxoCommerce_Import_Export_Batch" ) !== false,
    strpos( $csv, "import_row_for_batch" ) !== false,
    strpos( $csv, "normalize_headers_for_batch" ) !== false,
    strpos( $batch, "taxocommerce_import_export_batch_importers" ) !== false,
    strpos( $batch, "taxocommerce_import_export_batch_detect_type" ) !== false,
    strpos( $batch, "taxocommerce-import-export/queue" ) !== false,
    strpos( $batch, "taxocommerce_ie_batch_worker" ) !== false,
    strpos( $batch, "seo_process_supervisor" ) === false,
    strpos( $batch, "seo_ie_process_import_batch_queue" ) === false,
    strpos( $batch, "Action Scheduler" ) === false || strpos( $batch, "as_schedule_single_action" ) !== false,
    strpos( $batch, "Pausar después del archivo actual" ) !== false,
    strpos( $batch, "Detener importación actual" ) !== false,
);

foreach ( $checks as $index => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL batch queue guard ' . ( $index + 1 ) . PHP_EOL );
        exit( 1 );
    }
}

echo "TaxoCommerce Import-Export batch queue guards passed.\n";

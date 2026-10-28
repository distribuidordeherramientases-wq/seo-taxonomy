<?php
/** Read-only progress math; no WordPress database or CSV imports required. */
if ( 'cli' !== PHP_SAPI ) { exit; }
define( 'ABSPATH', '/' );
require dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-batch.php';
function progress_check( $ok, $label ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
}
$checks = array(
    array( 0, 100, 0, 100 ),
    array( 25, 100, 25, 75 ),
    array( 50, 100, 50, 50 ),
    array( 100, 100, 100, 0 ),
    array( 150, 100, 100, 0 ),
    array( -10, 100, 0, 100 ),
    array( 20, 0, 0, 0 ),
    array( 3200, 6400, 50, 3200 ),
);
foreach ( $checks as $check ) {
    list( $offset, $size, $percent, $remaining ) = $check;
    $progress = TaxoCommerce_Import_Export_Batch::progress_values( $offset, $size );
    progress_check( (float) $percent === (float) $progress['percent'], 'percentage for ' . $offset . '/' . $size );
    progress_check( $remaining === $progress['bytes_remaining'], 'remaining bytes for ' . $offset . '/' . $size );
    progress_check( $progress['bytes_read'] + $progress['bytes_remaining'] === $progress['bytes_total'], 'conservation' );
    progress_check( $progress['percent'] >= 0 && $progress['percent'] <= 100, 'never show invalid progress' );
}
echo "Read-only CSV progress bar: OK (offset, size, remaining, clamp and zero-length file)" . PHP_EOL;

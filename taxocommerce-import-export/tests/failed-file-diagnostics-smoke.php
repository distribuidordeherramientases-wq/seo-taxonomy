<?php
/**
 * Non-destructive regression contract for failed-file diagnostics.
 */
$batch = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-batch.php' );
$checks = array(
    'Authenticated download hook' => strpos( $batch, "admin_post_taxocommerce_ie_batch_download_failed_log" ) !== false,
    'Failed files expose a summary' => strpos( $batch, 'Ver error: %s' ) !== false,
    'Private JSON download link' => strpos( $batch, 'Descargar log JSON' ) !== false,
    'Exact-file nonce' => strpos( $batch, "check_admin_referer( 'taxocommerce_ie_batch_log_' . \$name" ) !== false,
    'File-level failure writes sidecar' => strpos( $batch, "'file_error' => sanitize_text_field( (string) \$message )" ) !== false,
    'Bounded JSON reads' => strpos( $batch, 'size > MB_IN_BYTES' ) !== false,
    'Symlinks rejected' => strpos( $batch, 'is_link( $log_path )' ) !== false,
    'Missing historical log explained' => strpos( $batch, 'Sin log disponible para este archivo.' ) !== false,
);
foreach ( $checks as $label => $passed ) {
    if ( ! $passed ) {
        fwrite( STDERR, "FAILED: " . $label . PHP_EOL );
        exit( 1 );
    }
}
echo "Failed CSV diagnostics contract: OK" . PHP_EOL;

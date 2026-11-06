<?php
/**
 * Offline export background contract. No WordPress installation is touched.
 *
 * Run: php tests/background-export-smoke.php
 */
if ( 'cli' !== PHP_SAPI ) {
    exit;
}
$root = dirname( __DIR__ );
$exporter = file_get_contents( $root . '/includes/class-taxocommerce-import-export-exporter.php' );
$browser = file_get_contents( $root . '/assets/exporter.js' );
$plugin = file_get_contents( $root . '/taxocommerce-import-export.php' );
$readme = file_get_contents( $root . '/readme.txt' );
function tc_ie_background_check( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}
foreach ( array(
    "add_action( self::WORKER_HOOK, array( \$this, 'background_worker' ), 10, 2 )",
    "as_schedule_single_action(",
    "wp_schedule_single_event(",
    "add_action( 'init', array( \$this, 'watchdog' ), 99 )",
    "public function background_worker( \$user_id, \$job )",
    "wp_set_current_user( \$user_id )",
    "wp_set_current_user( \$previous_user )",
    "public function ajax_resume()",
    "public function ajax_cancel()",
    "private function cancel_queued_job( \$job )",
    "private function acquire_lock( \$job )",
    "get_option( \$this->cancel_key(), '' )",
    "'status'      => sanitize_key(",
    "'downloadUrl' => ! empty( \$state['complete'] )",
    "TaxoCommerce_IE_Load::delay( 'export' )",
    "TaxoCommerce_IE_Load::batch_size( 'export' )",
    "ftruncate( \$handle, \$committed_size )",
) as $required ) {
    tc_ie_background_check( false !== strpos( $exporter, $required ), 'Missing export guard: ' . $required );
}
tc_ie_background_check( false === strpos( $browser, "request('taxocommerce_ie_export_step'" ), 'Browser must never execute export steps' );
tc_ie_background_check( false !== strpos( $browser, "request('taxocommerce_ie_export_status')" ), 'Browser must observe state' );
tc_ie_background_check( false !== strpos( $browser, "request('taxocommerce_ie_export_resume')" ), 'Browser must be able to retry failed job' );
tc_ie_background_check( false !== strpos( $browser, 'window.setTimeout(pollStatus, 5000)' ), 'Progress polling needs a bounded interval' );
tc_ie_background_check( false !== strpos( $plugin, 'Version: 0.4.16' ), 'Plugin version updated' );
tc_ie_background_check( false !== strpos( $readme, 'Stable tag: 0.4.16' ), 'Readme version updated' );
echo "Background export contract: OK (server worker, lock, cancellation, progress, recovery, adaptive regulator, version)" . PHP_EOL;

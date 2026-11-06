<?php
/**
 * Runtime regression for legacy export cancellation (no WordPress DB).
 * This test uses real temporary files and simulates the WordPress state APIs.
 */
if ( 'cli' !== PHP_SAPI ) { exit; }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/' ); }
$GLOBALS['tc_ie_test_uid'] = 42;
$GLOBALS['tc_ie_test_options'] = array();
$GLOBALS['tc_ie_test_transients'] = array();
function get_current_user_id() { return $GLOBALS['tc_ie_test_uid']; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['tc_ie_test_options'] ) ? $GLOBALS['tc_ie_test_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['tc_ie_test_options'][ $key ] = $value; return true; }
function add_option( $key, $value, $deprecated = '', $autoload = false ) {
    if ( array_key_exists( $key, $GLOBALS['tc_ie_test_options'] ) ) { return false; }
    $GLOBALS['tc_ie_test_options'][ $key ] = $value; return true;
}
function delete_option( $key ) { unset( $GLOBALS['tc_ie_test_options'][ $key ] ); return true; }
function get_transient( $key ) { return $GLOBALS['tc_ie_test_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['tc_ie_test_transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['tc_ie_test_transients'][ $key ] ); return true; }
function wp_generate_uuid4() { return '123e4567-e89b-12d3-a456-426614174000'; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_delete_file( $path ) { return @unlink( $path ); }
function wp_clear_scheduled_hook( $hook, $args = array() ) { return 0; }
function check_ajax_referer( $action, $field ) { return true; }
function current_user_can( $capability ) { return true; }
function apply_filters( $hook, $default ) { return $default; }
function wp_send_json_success( $data ) { throw new RuntimeException( 'TC_IE_JSON:' . json_encode( $data ) ); }
function wp_set_current_user( $id ) { $GLOBALS['tc_ie_test_uid'] = (int) $id; }
function tc_ie_assert( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, 'FAIL legacy cancellation: ' . $message . PHP_EOL ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-exporter.php';
$exporter = new TaxoCommerce_Import_Export_Exporter( new stdClass() );
$job = '123e4567-e89b-12d3-a456-426614174555';
$key = TaxoCommerce_Import_Export_Exporter::STATE_PREFIX . '42';
$lock = TaxoCommerce_Import_Export_Exporter::LOCK_PREFIX . '42';
$cancel = TaxoCommerce_Import_Export_Exporter::CANCEL_PREFIX . '42';
function tc_ie_job( $job, $key ) {
    $path = tempnam( sys_get_temp_dir(), 'tc-ie-cancel-' );
    file_put_contents( $path, "id,name\n42,test\n" );
    $state = array(
        'id' => $job, 'user_id' => 42, 'path' => $path, 'complete' => false,
        'status' => 'running', 'processed' => 1, 'file_size' => filesize( $path ),
        'updated' => time(), // Deliberately no dispatch_token: pre-0.4.16 job.
    );
    set_transient( $key, $state, 7200 );
    return $path;
}
function tc_ie_cancel_request( $exporter ) {
    try {
        $exporter->ajax_cancel();
    } catch ( RuntimeException $e ) {
        if ( 0 !== strpos( $e->getMessage(), 'TC_IE_JSON:' ) ) { throw $e; }
        return json_decode( substr( $e->getMessage(), 11 ), true );
    }
    return null;
}
function tc_ie_status_request( $exporter ) {
    try {
        $exporter->ajax_status();
    } catch ( RuntimeException $e ) {
        if ( 0 !== strpos( $e->getMessage(), 'TC_IE_JSON:' ) ) { throw $e; }
        return json_decode( substr( $e->getMessage(), 11 ), true );
    }
    return null;
}
// Legacy export: cancel now without any server callback or dispatch_token.
$path = tc_ie_job( $job, $key );
$response = tc_ie_cancel_request( $exporter );
tc_ie_assert( true === $response['cancelled'], 'legacy cancel must complete immediately' );
tc_ie_assert( ! file_exists( $path ) && ! get_transient( $key ), 'legacy CSV and state must be removed' );
tc_ie_assert( ! get_option( $lock ) && ! get_option( $cancel ), 'cancel must release lock and flag' );
// Active writer: file must remain until worker lock releases; AJAX status
// safely finalises the pending cancellation, without a browser worker.
$path = tc_ie_job( $job, $key );
update_option( $lock, array( 'token' => 'held', 'job' => $job, 'created' => time() ), false );
$response = tc_ie_cancel_request( $exporter );
tc_ie_assert( false === $response['cancelled'], 'active writer cannot delete file' );
tc_ie_assert( file_exists( $path ) && 'cancelling' === get_transient( $key )['status'], 'deferred cancel state persists' );
delete_option( $lock );
$response = tc_ie_status_request( $exporter );
tc_ie_assert( false === $response['active'] && ! file_exists( $path ), 'polling finishes abandoned cancellation' );
// Stale writer lock must not block a legacy job indefinitely.
$path = tc_ie_job( $job, $key );
update_option( $lock, array( 'token' => 'stale', 'job' => $job, 'created' => time() - 120 ), false );
$response = tc_ie_cancel_request( $exporter );
tc_ie_assert( true === $response['cancelled'] && ! file_exists( $path ), 'stale lock recovers and cancels' );
// A different WordPress user must never delete this user's export.
$path = tc_ie_job( $job, $key );
wp_set_current_user( 43 );
$response = tc_ie_cancel_request( $exporter );
tc_ie_assert( true === $response['cancelled'] && file_exists( $path ), 'other user cannot cancel user 42 job' );
wp_set_current_user( 42 );
$response = tc_ie_cancel_request( $exporter );
tc_ie_assert( true === $response['cancelled'] && ! file_exists( $path ), 'owner can cancel the remaining job' );
echo "Legacy export cancellation: PASS (immediate, active writer, recovery, stale lock, user isolation)" . PHP_EOL;

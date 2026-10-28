<?php
/**
 * Actual filesystem regression for deleting one queued CSV safely.
 */
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class TaxoCommerce_Import_Export_CSV {}
function __( $message, $domain = '' ) { return $message; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', $value ) ); }
function apply_filters( $hook, $value ) { return $value; }
function current_user_can( $capability ) { return true; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function wp_generate_password( $length = 24 ) { return str_repeat( 'a', $length ); }
function get_transient( $name ) { return $GLOBALS['tc_transients'][ $name ] ?? false; }
function set_transient( $name, $value, $seconds = 0 ) { $GLOBALS['tc_transients'][ $name ] = $value; return true; }
function delete_transient( $name ) { unset( $GLOBALS['tc_transients'][ $name ] ); return true; }
function get_option( $key, $default = false ) { return $GLOBALS['tc_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['tc_options'][ $key ] = $value; return true; }
function current_time( $format ) { return '2026-10-28 14:00:00'; }
function wp_upload_dir( $date = null, $make = true ) {
    return array( 'error' => false, 'basedir' => $GLOBALS['tc_upload_root'] );
}
function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function wp_delete_file( $path ) { return unlink( $path ); }

function check( $condition, $description ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL delete pending: ' . $description . PHP_EOL );
        exit( 1 );
    }
}

require dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-batch.php';
$GLOBALS['tc_options'] = array();
$GLOBALS['tc_transients'] = array();
$GLOBALS['tc_upload_root'] = sys_get_temp_dir() . '/tc_ie_delete_' . str_replace( '.', '_', uniqid( '', true ) );
$queue = $GLOBALS['tc_upload_root'] . '/taxocommerce-import-export/queue';
foreach ( array( 'pending', 'processing', 'imported', 'failed' ) as $bucket ) {
    mkdir( $queue . '/' . $bucket, 0700, true );
}
$pending = $queue . '/pending/';
$processing = $queue . '/processing/';
$imported = $queue . '/imported/';

file_put_contents( $pending . 'duplicate.csv', "id,name\n1,A\n" );
file_put_contents( $pending . 'duplicate-1.csv', "id,name\n1,A\n" );
file_put_contents( $processing . 'active.csv', "id,name\n3,C\n" );
file_put_contents( $imported . 'finished.csv', "id,name\n4,D\n" );

$batch = new TaxoCommerce_Import_Export_Batch( new TaxoCommerce_Import_Export_CSV() );
$GLOBALS['tc_options'][ TaxoCommerce_Import_Export_Batch::INDIVIDUAL_STATE ] = array(
    'file' => 'duplicate-1.csv', 'status' => 'pending', 'events' => array(),
);
check( true === $batch->delete_pending_file( 'duplicate-1.csv' ), 'delete exact duplicate' );
check( ! file_exists( $pending . 'duplicate-1.csv' ), 'duplicate must disappear' );
check( file_exists( $pending . 'duplicate.csv' ), 'original must survive' );
$individual = get_option( TaxoCommerce_Import_Export_Batch::INDIVIDUAL_STATE );
check( 'cancelled' === $individual['status'] && count( $individual['events'] ) === 1, 'individual record cancellation' );

foreach ( array( '../processing/active.csv', 'active.csv', 'finished.csv', 'missing.csv', '..\\processing\\active.csv' ) as $bad ) {
    check( is_wp_error( $batch->delete_pending_file( $bad ) ), 'reject out-of-bucket: ' . $bad );
}
check( file_exists( $processing . 'active.csv' ) && file_exists( $imported . 'finished.csv' ), 'other queue buckets are untouched' );

if ( function_exists( 'symlink' ) && @symlink( $processing . 'active.csv', $pending . 'active-link.csv' ) ) {
    check( is_wp_error( $batch->delete_pending_file( 'active-link.csv' ) ), 'reject symlink' );
    check( file_exists( $processing . 'active.csv' ), 'symlink target intact' );
    unlink( $pending . 'active-link.csv' );
}

$GLOBALS['tc_transients'][ TaxoCommerce_Import_Export_Batch::LOCK_KEY ] = 'active-worker';
// Deleting a PENDING file must work while a DIFFERENT CSV is processing.
check( true === $batch->delete_pending_file( 'duplicate.csv' ), 'delete unrelated pending CSV during busy product worker' );
check( ! file_exists( $pending . 'duplicate.csv' ), 'pending CSV removed while worker lock is held' );
check( file_exists( $processing . 'active.csv' ), 'active processing file survives pending deletion' );
unset( $GLOBALS['tc_transients'][ TaxoCommerce_Import_Export_Batch::LOCK_KEY ] );

// A held pending-only mutex is short-lived, not the product worker lock.
file_put_contents( $pending . 'blocked.csv', "id,name\n7,Z\n" );
$mutex = fopen( $pending . '.pending-mutex.lock', 'c' );
check( is_resource( $mutex ) && flock( $mutex, LOCK_EX | LOCK_NB ), 'test holds pending mutex' );
$blocked = $batch->delete_pending_file( 'blocked.csv' );
check( is_wp_error( $blocked ) && 'tc_ie_delete_busy' === $blocked->get_error_code(), 'race-safe pending mutex rejects concurrent deletion' );
check( file_exists( $pending . 'blocked.csv' ), 'file remains after mutex contention' );
flock( $mutex, LOCK_UN );
fclose( $mutex );
check( true === $batch->delete_pending_file( 'blocked.csv' ), 'deletion resumes after the brief mutex is released' );

$GLOBALS['tc_options'][ TaxoCommerce_Import_Export_Batch::OPTION_STATE ] = array(
    'enabled' => true, 'current_file' => '', 'status' => 'waiting_next',
);
file_put_contents( $pending . 'last.csv', "id,name\n8,L\n" );
check( true === $batch->delete_pending_file( 'last.csv' ), 'delete last pending CSV' );
$state = get_option( TaxoCommerce_Import_Export_Batch::OPTION_STATE );
check( ! empty( $state['enabled'] ), 'do not disable queue while another CSV exists in processing even with a stale state' );
check( file_exists( $processing . 'active.csv' ), 'processing untouched after final pending deletion' );
unlink( $processing . 'active.csv' );
file_put_contents( $pending . 'last2.csv', "id,name\n8,L\n" );
check( true === $batch->delete_pending_file( 'last2.csv' ), 'delete last pending when no worker or processing file' );
$state = get_option( TaxoCommerce_Import_Export_Batch::OPTION_STATE );
check( empty( $state['enabled'] ) && 'idle' === $state['status'], 'now empty queue becomes idle' );

unlink( $imported . 'finished.csv' );
unlink( $pending . '.pending-mutex.lock' );
foreach ( array( 'pending', 'processing', 'imported', 'failed' ) as $bucket ) {
    rmdir( $queue . '/' . $bucket );
}
rmdir( $queue );
rmdir( dirname( $queue ) );
rmdir( $GLOBALS['tc_upload_root'] );
echo "Delete pending CSV: OK (duplicate, traversal, concurrent worker, mutex, symlink and queue state)" . PHP_EOL;

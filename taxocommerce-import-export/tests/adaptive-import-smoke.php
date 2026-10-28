<?php
/**
 * Adaptive import CSV batching without WordPress or external services.
 * Short productive blocks should grow even if the PHP process uses its CPU.
 */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $message;
    public function __construct( $code, $message ) { $this->message = $message; }
}
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function absint( $value ) { return abs( (int) $value ); }
function get_option( $name, $fallback = false ) { return $GLOBALS['ie_options'][ $name ] ?? $fallback; }
function update_option( $name, $value, $autoload = true ) { $GLOBALS['ie_options'][ $name ] = $value; return true; }
function cpu_now() {
    $v = getrusage();
    return (float) $v['ru_utime.tv_sec'] + (float) $v['ru_utime.tv_usec'] / 1000000
        + (float) $v['ru_stime.tv_sec'] + (float) $v['ru_stime.tv_usec'] / 1000000;
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-ie-load.php';
$GLOBALS['ie_options'] = array();

// Import targets and caps are exactly ten times the former values.
$initial = array( 1 => 30, 2 => 60, 3 => 100, 4 => 200, 5 => 350 );
$maximum = array( 1 => 100, 2 => 300, 3 => 750, 4 => 1250, 5 => 2000 );
foreach ( $initial as $priority => $rows ) {
    if ( TaxoCommerce_IE_Load::set_priority( 'import', $priority ) !== $priority
        || TaxoCommerce_IE_Load::batch_size( 'import' ) !== $rows
        || TaxoCommerce_IE_Load::size_limits( 'import' )['maximum'] !== $maximum[ $priority ] ) {
        fwrite( STDERR, 'Import priority rows are not exactly 10x at level ' . $priority . PHP_EOL );
        exit( 1 );
    }
}
if ( TaxoCommerce_IE_Load::set_priority( 'export', 3 ) !== 3
    || TaxoCommerce_IE_Load::batch_size( 'export' ) !== 10
    || TaxoCommerce_IE_Load::size_limits( 'export' )['maximum'] !== 75 ) {
    fwrite( STDERR, 'Export limits must remain unchanged' . PHP_EOL );
    exit( 1 );
}

TaxoCommerce_IE_Load::set_priority( 'import', 5 );
$GLOBALS['ie_options'][ TaxoCommerce_IE_Load::SAMPLE ] = array(
    'import' => array( 'priority' => 5, 'next_batch' => 5 ),
);
// Upgrade an import already running on the old 5-row profile, 5 -> 50.
if ( TaxoCommerce_IE_Load::batch_size( 'import' ) !== 50 ) {
    fwrite( STDERR, 'Old 5-row batch must migrate to 50, without restarting the CSV' . PHP_EOL );
    exit( 1 );
}
// 50 rows completed in 10 seconds, with memory available -> 100 next.
$sample = TaxoCommerce_IE_Load::finish(
    'import',
    array( 'start' => microtime( true ) - 10, 'budget' => 25, 'cpu' => cpu_now() ),
    50
);
if ( $sample['next_batch'] !== 100 || $sample['batch_profile'] !== 2
    || $sample['batch'] !== 50
    || strpos( $sample['reason'], 'duplicado' ) === false ) {
    fwrite( STDERR, 'Fast full batch of 50 must double to 100' . PHP_EOL );
    exit( 1 );
}
$sample = TaxoCommerce_IE_Load::finish(
    'import',
    array( 'start' => microtime( true ) - 10, 'budget' => 25, 'cpu' => cpu_now() ),
    100
);
if ( $sample['next_batch'] !== 200 ) {
    fwrite( STDERR, 'Fast full batch of 100 must double to 200' . PHP_EOL );
    exit( 1 );
}
// The same upgrade works when the existing live queue fell to six rows.
$GLOBALS['ie_options'][ TaxoCommerce_IE_Load::SAMPLE ]['import'] =
    array( 'priority' => 5, 'next_batch' => 6 );
if ( TaxoCommerce_IE_Load::batch_size( 'import' ) !== 60 ) {
    fwrite( STDERR, 'Live six-row queue must resume at a 60-row target' . PHP_EOL );
    exit( 1 );
}

// High memory, high local CPU close to deadline, and PHP timeout reduce.
$decision = TaxoCommerce_IE_Load::import_adjustment( 100, 2000, 100, 8, 85, 25, 20 );
if ( $decision[0] !== 50 || strpos( $decision[1], 'memoria' ) === false ) {
    fwrite( STDERR, 'High memory must reduce the size' . PHP_EOL );
    exit( 1 );
}
$decision = TaxoCommerce_IE_Load::import_adjustment( 100, 2000, 80, 22, 50, 25, 98 );
if ( $decision[0] !== 75 || strpos( $decision[1], 'CPU' ) === false ) {
    fwrite( STDERR, 'High CPU near the deadline must reduce the size' . PHP_EOL );
    exit( 1 );
}
$decision = TaxoCommerce_IE_Load::import_adjustment( 100, 2000, 25, 25, 40, 25, 10 );
if ( $decision[0] !== 20 || strpos( $decision[1], 'tiempo' ) === false ) {
    fwrite( STDERR, 'Slow batch must adapt to actual rows per second' . PHP_EOL );
    exit( 1 );
}
$decision = TaxoCommerce_IE_Load::import_adjustment( 100, 2000, 6, 6.13, 30, 25, 10 );
if ( $decision[0] !== 100 ) {
    fwrite( STDERR, 'Partial batch must not be falsely doubled' . PHP_EOL );
    exit( 1 );
}
$decision = TaxoCommerce_IE_Load::import_adjustment( 2000, 2000, 2000, 8, 40, 25, 10 );
if ( $decision[0] !== 2000 ) {
    fwrite( STDERR, 'Never exceed the max speed cap' . PHP_EOL );
    exit( 1 );
}
$decision = TaxoCommerce_IE_Load::import_adjustment( 100, 2000, 0, 25, 40, 25, 10 );
if ( $decision[0] !== 100 ) {
    fwrite( STDERR, 'Empty blocks should not collapse the speed profile' . PHP_EOL );
    exit( 1 );
}
if ( ! ( TaxoCommerce_IE_Load::set_priority( 'import', 0 ) instanceof WP_Error ) ) {
    fwrite( STDERR, 'Invalid priority must be rejected' . PHP_EOL );
    exit( 1 );
}
echo 'Adaptive CSV speed: OK' . PHP_EOL;

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

if ( TaxoCommerce_IE_Load::batch_size( 'import' ) !== 10 ) {
    fwrite( STDERR, 'Import should start at 10 rows in normal priority' . PHP_EOL );
    exit( 1 );
}
$start = array( 'start' => microtime( true ) - 0.6, 'cpu' => cpu_now() - 0.5 );
$sample = TaxoCommerce_IE_Load::finish( 'import', $start, 10 );
if ( $sample['next_batch'] <= 10 || strpos( $sample['reason'], 'ampliado' ) === false ) {
    fwrite( STDERR, 'Short import with high PHP CPU must grow, not shrink' . PHP_EOL );
    exit( 1 );
}
$current = TaxoCommerce_IE_Load::batch_size( 'import' );
$sample = TaxoCommerce_IE_Load::finish( 'import', array( 'start' => microtime( true ) - 9.2, 'cpu' => cpu_now() ), $current );
if ( $sample['next_batch'] >= $current || strpos( $sample['reason'], 'reducido' ) === false ) {
    fwrite( STDERR, 'Slow import must decrease the next block' . PHP_EOL );
    exit( 1 );
}
if ( TaxoCommerce_IE_Load::set_priority( 'import', 5 ) !== 5 || TaxoCommerce_IE_Load::batch_size( 'import' ) !== 35 ) {
    fwrite( STDERR, 'Priority five batch initialization failed' . PHP_EOL );
    exit( 1 );
}
if ( TaxoCommerce_IE_Load::set_priority( 'import', 0 ) instanceof WP_Error === false ) {
    fwrite( STDERR, 'Invalid priority must be rejected' . PHP_EOL );
    exit( 1 );
}
echo 'Adaptive CSV speed: OK' . PHP_EOL;

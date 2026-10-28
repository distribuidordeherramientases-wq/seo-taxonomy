<?php
/**
 * Standalone smoke test of the maximum-speed workload regulator.
 * Run: php taxocommerce-dependiente/tests/load-boost-smoke.php
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['tcd_test_options'] = array();
function absint( $value ) { return abs( (int) $value ); }
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['tcd_test_options'] ) ? $GLOBALS['tcd_test_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = false ) {
    $GLOBALS['tcd_test_options'][ $name ] = $value;
    return true;
}
function tcd_assert( $condition, $description ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: " . $description . "\n" );
        exit( 1 );
    }
}
require dirname( __DIR__ ) . '/includes/class-tcd-load.php';
tcd_assert( 5 === TCD_Load::set_priority( '5' ), 'priority 5 accepted' );
tcd_assert( 47 === TCD_Load::batch_size(), 'new initial max-speed batch' );
tcd_assert( 600 === TCD_Load::max_batch(), 'new max-speed ceiling' );
$start = TCD_Load::begin();
$sample = TCD_Load::finish( $start, TCD_Load::batch_size() );
tcd_assert( $sample['next_batch'] > 47, 'a full fast batch grows immediately' );
tcd_assert( $sample['next_batch'] <= 600, 'growth capped by ceiling' );
tcd_assert( isset( $sample['items_per_second'], $sample['reason'] ), 'diagnostic fields included' );
foreach ( array( 1 => 5, 2 => 13, 3 => 29, 4 => 47 ) as $priority => $ceiling ) {
    TCD_Load::set_priority( (string) $priority );
    tcd_assert( $ceiling === TCD_Load::max_batch(), 'lower-priority ceiling unchanged ' . $priority );
}
echo "Dependiente adaptive workload smoke: OK\n";

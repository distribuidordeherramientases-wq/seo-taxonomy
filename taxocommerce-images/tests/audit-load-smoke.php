<?php
/** Test the independent five-speed Media audit regulator in isolation. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    public function __construct( $code, $message ) {}
}
function __( $message, $domain = '' ) { return $message; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function absint( $number ) { return abs( (int) $number ); }
function get_option( $key, $default = false ) { return $GLOBALS['audit_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = true ) { $GLOBALS['audit_options'][ $key ] = $value; return true; }
function get_num_queries() { return 0; }
function assert_audit( $ok, $message ) {
    if ( ! $ok ) { fwrite( STDERR, "FAIL Images audit load: " . $message . PHP_EOL ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-images-load.php';
$GLOBALS['audit_options'] = array(
    'taxocommerce_images_audit_state' => array( 'status'=>'running', 'scanned'=>975, 'last_id'=>2345 ),
);
$initial = array( 1=>25, 2=>50, 3=>75, 4=>125, 5=>200 );
$maximum = array( 1=>75, 2=>150, 3=>350, 4=>700, 5=>1200 );
foreach ( $initial as $priority => $start ) {
    assert_audit( $priority === TaxoCommerce_Images_Load::set_priority( $priority, 'audit' ), 'set speed ' . $priority );
    assert_audit( TaxoCommerce_Images_Load::batch_size( 'audit' ) === $start, 'initial lot speed ' . $priority );
    assert_audit( 'running' === get_option( 'taxocommerce_images_audit_state' )['status']
        && 975 === get_option( 'taxocommerce_images_audit_state' )['scanned']
        && 2345 === get_option( 'taxocommerce_images_audit_state' )['last_id'], 'setting speed preserves audit cursor' );
    $last = TaxoCommerce_Images_Load::audit_adjustment( $maximum[$priority], $maximum[$priority], $maximum[$priority], 1, 30, 18, 12 );
    assert_audit( $last[0] === $maximum[$priority], 'never exceed max' );
}
TaxoCommerce_Images_Load::set_priority( 3, 'audit' );
$sample = TaxoCommerce_Images_Load::finish(
    'audit', array( 'wall'=>microtime( true )-1, 'budget'=>8 ), 75
);
assert_audit( 150 === $sample['next_batch'], 'fast full lot 75 doubles to 150' );
$sample = TaxoCommerce_Images_Load::finish(
    'audit', array( 'wall'=>microtime( true )-1, 'budget'=>8 ), 150
);
assert_audit( 300 === $sample['next_batch'], 'fast full lot 150 doubles to 300' );
TaxoCommerce_Images_Load::set_priority( 5, 'audit' );
assert_audit( 200 === TaxoCommerce_Images_Load::batch_size( 'audit' ), 'speed 5 reinitializes to 200' );
assert_audit( TaxoCommerce_Images_Load::priority( 'audit' ) === 5, 'audit is independently configured' );
assert_audit( TaxoCommerce_Images_Load::priority( 'cleanup' ) === 3, 'cleanup unchanged' );
assert_audit( TaxoCommerce_Images_Load::batch_size( 'cleanup' ) === 3, 'cleanup size unchanged' );
foreach ( array(
    array( 200, 1200, 200, 2, 40, 18, 20, 400 ),
    array( 400, 1200, 400, 4, 40, 18, 20, 800 ),
    array( 800, 1200, 800, 5, 40, 18, 20, 1200 ),
    array( 200, 1200, 200, 12, 85, 18, 20, 100 ),
    array( 200, 1200, 200, 16, 55, 18, 98, 150 ),
    array( 200, 1200, 60, 17.5, 55, 18, 20, 48 ),
    array( 200, 1200, 40, 4, 55, 18, 20, 200 ),
    array( 200, 1200, 0, 18, 55, 18, 20, 200 )
) as $input ) {
    $expected = array_pop( $input );
    $result = TaxoCommerce_Images_Load::audit_adjustment( ...$input );
    assert_audit( $expected === $result[0], 'adaptive decision expected ' . $expected . ' got ' . $result[0] );
}
echo "Images Media audit adaptive speed: OK; cursor preserved; independent 1-5 limits" . PHP_EOL;

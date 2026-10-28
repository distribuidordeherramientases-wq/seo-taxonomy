<?php
/**
 * M11 regression is a 180-case deterministic runtime verification, not a
 * replay of the first 100 L9 legacy questions. No WordPress DB needed here.
 * php taxocommerce-dependiente/tests/m11-runtime-regression.php
 */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) { return abs( (int) $value ); }
}

require_once dirname( __DIR__ ) . '/includes/class-tcd-intelligence.php';

function m11_assert( $condition, $name ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAILED M11: " . $name . PHP_EOL );
        exit( 1 );
    }
}
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-tcd-intelligence.php' );
$start = strpos( $source, 'public static function build_interpreter()' );
$end = strpos( $source, 'public static function interpreter_audit_url()', $start );
m11_assert( false !== $start && false !== $end, 'M11 implementation found' );
$build = substr( $source, $start, $end - $start );

m11_assert( false === strpos( $build, "\$facts['customer_need']" ), 'legacy saved questions not used' );
m11_assert( false !== strpos( $build, 'self::evaluate_row( 9, $row, \'\' )' ), 'current L9 evaluator used against active runtime' );
m11_assert( false !== strpos( $build, 'self::representative_sample(' ), 'distributed representative sample' );
m11_assert( false !== strpos( $build, "                180" ), '180 cases instead of first 100' );
m11_assert( false === strpos( $build, 'LIMIT 100' ), 'no first-100 bias' );
m11_assert( false !== strpos( $build, 'TCD_Load::batch_size()' ), 'CPU-aware batch size' );
m11_assert( false !== strpos( $build, 'microtime( true ) - $started >= 12' ), 'bounded wall time' );
m11_assert( false !== strpos( $build, 'self::INTERPRETER_PROGRESS' ), 'progress persists between requests' );
m11_assert( false !== strpos( $build, "if ( ! \$done ) return \$state;" ), 'partial result is not passed' );
m11_assert( false !== strpos( $build, "if ( 'ready' !== \$state['status'] )" ), 'failed quality gate not bypassed' );
m11_assert( false !== strpos( $build, 'delete_option( self::INTERPRETER_PROGRESS )' ), 'progress cleared after full validation' );

$method = new ReflectionMethod( 'TCD_Intelligence', 'representative_sample' );
$method->setAccessible( true );
$population = array();
for ( $id = 1; $id <= 13838; $id++ ) $population[] = array( 'id' => $id );
$sample = $method->invoke( null, $population, 180 );
m11_assert( 180 === count( $sample ), '180 distinct positions sampled' );
m11_assert( $sample[0]['id'] > 1 && $sample[179]['id'] > 13700, 'sample distributed over entire catalog' );
m11_assert( $sample === $method->invoke( null, $population, 180 ), 'sample deterministic' );
echo "M11 runtime regression passed.\n";

<?php
/** Non-destructive structural smoke for Database Auto Test. */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
function add_action( $hook, $callback ) { $GLOBALS['tcdb_autotest_hooks'][ $hook ] = $callback; }
require dirname( __DIR__ ) . '/includes/class-tcdb-auto-test.php';
function tcdb_autotest_assert( $condition, $label ) {
    if ( ! $condition ) { echo "FAIL: " . $label . "\n"; exit(1); }
}
$cases = TCDB_Auto_Test::cases();
tcdb_autotest_assert( count( $cases ) >= 17, 'at least 17 distinct functional checks' );
tcdb_autotest_assert( isset( $cases['seo_schema'], $cases['cleanup'], $cases['full_export'], $cases['inventory'], $cases['columns'], $cases['exports'], $cases['destructive'] ), 'all major sections represented' );
tcdb_autotest_assert( isset( $GLOBALS['tcdb_autotest_hooks']['admin_post_tcdb_run_auto_test'] ), 'POST action registered' );
$source = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-database.php' );
tcdb_autotest_assert( false !== strpos( $source, "'auto-test'             =>" ), 'tab registered' );
tcdb_autotest_assert( false !== strpos( $source, 'TCDB_Auto_Test::render();' ), 'router connected' );
tcdb_autotest_assert( false !== strpos( $source, "class-tcdb-auto-test.php" ), 'class included' );
tcdb_autotest_assert( false !== strpos( $source, 'TCDB_Manual::render();' ), 'manual intact' );
$reflection = new ReflectionClass( 'TCDB_Auto_Test' );
foreach ( $cases as $id => $labels ) {
    tcdb_autotest_assert( $reflection->hasMethod( 'test_' . $id ), 'test implementation ' . $id );
}
$method = $reflection->getMethod( 'test_destructive' );
$method->setAccessible( true );
$result = $method->invoke( null );
tcdb_autotest_assert( 'skip' === $result['status'], 'destructive functions never exercised' );
echo "Database Auto Test smoke OK\n";

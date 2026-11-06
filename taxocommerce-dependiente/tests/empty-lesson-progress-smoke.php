<?php
/**
 * Regression: a sealed empty lesson completes its workflow at 100% without
 * claiming that any material was learned or any quality test was passed.
 *
 * Run: php taxocommerce-dependiente/tests/empty-lesson-progress-smoke.php
 */
define( 'ABSPATH', __DIR__ . '/' );
function absint( $value ) { return max( 0, (int) $value ); }
require_once dirname( __DIR__ ) . '/includes/class-tcd-admin.php';

function assert_lesson_percent( $expected, $status, $done, $total ) {
    $actual = TCD_Admin::lesson_progress_percent( $status, $done, $total );
    if ( $expected !== $actual ) {
        fwrite( STDERR, 'FAIL ' . $status . ' ' . $done . '/' . $total . ': expected ' . $expected . ', got ' . $actual . PHP_EOL );
        exit( 1 );
    }
}

assert_lesson_percent( 100, 'skipped_empty', 0, 0 );
assert_lesson_percent( 100, 'skipped_empty', 0, 5 );
assert_lesson_percent( 0, 'building', 0, 0 );
assert_lesson_percent( 0, 'ready', 0, 0 );
assert_lesson_percent( 0, 'error', 0, 0 );
assert_lesson_percent( 0, 'pending', 0, 0 );
assert_lesson_percent( 0, 'completed', 0, 0 );
assert_lesson_percent( 50, 'learning', 2, 4 );
assert_lesson_percent( 75, 'learning', 3, 4 );
assert_lesson_percent( 100, 'completed', 4, 4 );
assert_lesson_percent( 100, 'learning', 5, 4 );
echo "PASS: empty lessons 100%; pending/error 0%; other progress unchanged.\n";

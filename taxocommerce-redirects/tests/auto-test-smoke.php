<?php
/**
 * Pure CLI regression for Redirects Auto Test comparison and no-write UAT.
 * No live WordPress, MySQL, HTTP or filesystem writes required.
 */
namespace TaxoCommerce\Redirects;
define( 'ABSPATH', __DIR__ );
final class Plugin { const VERSION = '0.1.6'; }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function get_current_blog_id() { return 1; }
require dirname( __DIR__ ) . '/includes/class-auto-test.php';
function assertion( $ok, $label ) {
    if ( ! $ok ) {
        echo 'FAIL Auto Test: ' . $label . PHP_EOL;
        exit( 1 );
    }
}
$cases = Auto_Test::cases();
$groups = Auto_Test::groups();
assertion( count( $groups ) === 8, 'Eight test groups' );
assertion( count( $cases ) === 26, 'Twenty-six checks' );
foreach ( $cases as $id => $case ) {
    $method = new \ReflectionMethod( Auto_Test::class, 'check_' . $id );
    assertion( $method->isStatic() && isset( $groups[ $case[0] ] ), 'Check defined and grouped: ' . $id );
}
$report = Auto_Test::suite( 'uat' );
assertion( 4 === $report['totals']['skip'] && 22 === $report['totals']['not_run'], 'External test remains unverified' );
assertion( 0 === $report['totals']['pass'] && 0 === $report['totals']['fail'], 'No invented success' );
assertion( Plugin::VERSION === $report['version'], 'Recorded version' );
assertion( hash( 'sha256', 'https://example.test/' ) === $report['site_hash'], 'Bound to site' );
$before = array(
    'site_hash' => 'site', 'phase' => 'before', 'group' => 'all',
    'rows' => array(
        array( 'id' => 'engine', 'status' => 'pass' ),
        array( 'id' => 'schema', 'status' => 'fail' ),
        array( 'id' => 'old', 'status' => 'pass' ),
        array( 'id' => 'unverified', 'status' => 'pass' ),
    ),
);
$after = array(
    'site_hash' => 'site', 'phase' => 'after', 'group' => 'all',
    'rows' => array(
        array( 'id' => 'engine', 'status' => 'fail' ),
        array( 'id' => 'schema', 'status' => 'pass' ),
        array( 'id' => 'unverified', 'status' => 'skip' ),
        array( 'id' => 'new', 'status' => 'fail' ),
    ),
);
$comparison = Auto_Test::compare( $before, $after );
assertion( 2 === count( $comparison['new_failures'] ), 'New regression detected' );
assertion( 1 === count( $comparison['resolved'] ), 'Resolved issue detected' );
assertion( 2 === count( $comparison['lost_coverage'] ), 'Lost and skipped coverage detected' );
assertion( 1 === count( $comparison['new_checks'] ), 'New tests tracked' );
$after['site_hash'] = 'other';
assertion( null === Auto_Test::compare( $before, $after ), 'Reject cross-site comparison' );
$after['site_hash'] = 'site';
$after['phase'] = 'check';
assertion( null === Auto_Test::compare( $before, $after ), 'Only AFTER compares' );
$after['phase'] = 'after';
$before['group'] = 'engine';
assertion( null === Auto_Test::compare( $before, $after ), 'Reject partial baselines' );
echo 'Redirects Auto Test smoke passed.' . PHP_EOL;

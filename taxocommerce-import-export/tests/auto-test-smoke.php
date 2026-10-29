<?php
/**
 * Auto Test contract regression without WordPress, database or IO writes.
 * Run: php taxocommerce-import-export/tests/auto-test-smoke.php
 */
if ( 'cli' !== PHP_SAPI ) { exit; }
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_IMPORT_EXPORT_VERSION', '0.4.9' );
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function get_current_blog_id() { return 1; }
function current_time( $format ) { return '2026-10-29 15:30:00'; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) ); }
function __( $value, $domain = '' ) { return $value; }
function get_option( $key, $default = false ) { return $default; }
function get_user_meta( $user, $key, $single = true ) { return false; }
function update_user_meta( $user, $key, $value ) { throw new RuntimeException( 'Unexpected write in read-only check' ); }
function wp_remote_get() { throw new RuntimeException( 'Unexpected external GET in read-only check' ); }
function wp_remote_post() { throw new RuntimeException( 'Unexpected external POST in read-only check' ); }
require dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-auto-test.php';
function verify( $value, $label ) {
    if ( ! $value ) { echo 'FAIL Auto Test: ' . $label . PHP_EOL; exit( 1 ); }
}
$groups = TaxoCommerce_Import_Export_Auto_Test::groups();
$cases = TaxoCommerce_Import_Export_Auto_Test::cases();
verify( 8 === count( $groups ), 'Eight groups registered' );
verify( 31 === count( $cases ), 'Thirty-one checks registered' );
foreach ( array_keys( $cases ) as $id ) {
    $method = new ReflectionMethod( 'TaxoCommerce_Import_Export_Auto_Test', 'check_' . $id );
    verify( $method->isStatic(), 'Missing check implementation ' . $id );
}
verify( isset( $cases['country_alias'], $cases['safe_queue_upgrade'], $cases['real_media'] ), 'Critical checks registered' );
$report = TaxoCommerce_Import_Export_Auto_Test::execute( 'manual' );
verify( $report['totals']['skip'] === 5 && $report['totals']['not_run'] === 26, 'Manual tests remain unverified' );
verify( 0 === $report['totals']['pass'] && 0 === $report['totals']['fail'], 'No invented success' );
verify( $report['plugin_version'] === TAXOCOMMERCE_IMPORT_EXPORT_VERSION, 'Plugin version recorded' );
verify( $report['site_hash'] === hash( 'sha256', 'https://example.test/' ), 'Site identity recorded' );
$before = array(
    'site_hash' => 'same', 'phase' => 'before', 'group' => 'all',
    'rows' => array(
        array( 'id' => 'csv', 'status' => 'pass' ),
        array( 'id' => 'queue', 'status' => 'fail' ),
        array( 'id' => 'media', 'status' => 'pass' ),
        array( 'id' => 'removed', 'status' => 'pass' ),
    ),
);
$after = array(
    'site_hash' => 'same', 'phase' => 'after', 'group' => 'all',
    'rows' => array(
        array( 'id' => 'csv', 'status' => 'fail' ),
        array( 'id' => 'queue', 'status' => 'pass' ),
        array( 'id' => 'media', 'status' => 'skip' ),
        array( 'id' => 'fresh', 'status' => 'fail' ),
    ),
);
$diff = TaxoCommerce_Import_Export_Auto_Test::compare( $before, $after );
verify( count( $diff['new_errors'] ) === 2, 'New regressions detected' );
verify( count( $diff['resolved'] ) === 1, 'Resolved regressions detected' );
verify( count( $diff['lost_coverage'] ) === 2, 'Dropped and skipped checks reported' );
verify( count( $diff['new_checks'] ) === 1, 'New checks tracked' );
$after['site_hash'] = 'other';
verify( null === TaxoCommerce_Import_Export_Auto_Test::compare( $before, $after ), 'Cross-site comparisons rejected' );
$after['site_hash'] = 'same';
$after['phase'] = 'check';
verify( null === TaxoCommerce_Import_Export_Auto_Test::compare( $before, $after ), 'Non-deployment comparisons rejected' );
$after['phase'] = 'after';
$before['group'] = 'csv';
verify( null === TaxoCommerce_Import_Export_Auto_Test::compare( $before, $after ), 'Partial baseline rejected' );
echo "Import-Export Auto Test smoke passed.\n";

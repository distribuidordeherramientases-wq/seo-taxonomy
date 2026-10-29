<?php
/** Standalone Auto Test comparison regressions; no WordPress or imports. */
define( 'ABSPATH', '/' );
define( 'TAXOCOMMERCE_IMPORT_EXPORT_VERSION', '0.4.9' );
function home_url( $path = '/' ) { return 'https://site.test' . $path; }
require dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-auto-test.php';
function verify( $good, $label ) { if ( ! $good ) { echo 'FAIL: ' . $label . PHP_EOL; exit( 1 ); } }
$cases = TaxoCommerce_Import_Export_Auto_Test::cases();
verify( count( $cases ) >= 30, 'minimum coverage' );
verify( isset( $cases['country_alias'], $cases['safe_queue_upgrade'], $cases['real_media'] ), 'critical checks' );
$report = TaxoCommerce_Import_Export_Auto_Test::execute( 'manual', array( 'sample' => array( 'system', 'Example' ) ) );
verify( 1 === $report['totals']['not_run'] && 0 === $report['totals']['pass'], 'not-run is not pass' );
$before = array( 'site_hash' => 'same', 'phase' => 'before', 'group' => 'all', 'rows' => array( array( 'id' => 'a', 'status' => 'pass' ), array( 'id' => 'b', 'status' => 'fail' ), array( 'id' => 'c', 'status' => 'pass' ) ) );
$after = array( 'site_hash' => 'same', 'phase' => 'after', 'group' => 'all', 'rows' => array( array( 'id' => 'a', 'status' => 'fail' ), array( 'id' => 'b', 'status' => 'pass' ), array( 'id' => 'c', 'status' => 'skip' ) ) );
$diff = TaxoCommerce_Import_Export_Auto_Test::compare( $before, $after );
verify( 1 === count( $diff['new_errors'] ) && 1 === count( $diff['resolved'] ) && 1 === count( $diff['lost_coverage'] ), 'regression classification' );
$after['site_hash'] = 'other';
verify( null === TaxoCommerce_Import_Export_Auto_Test::compare( $before, $after ), 'site isolation' );
echo 'Import-Export Auto Test smoke passed.' . PHP_EOL;

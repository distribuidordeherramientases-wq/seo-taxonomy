<?php
/**
 * Offline regression for Import-Export's manual, two-action Autocheck.
 * No imports, exports, database writes or cron dispatch.
 */
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_IMPORT_EXPORT_VERSION', '0.4.15' );

$GLOBALS['tcie_site'] = 'https://example.test';
$GLOBALS['tcie_report'] = array();
function __( $text, $domain = '' ) { return $text; }
function home_url( $p = '/' ) { return $GLOBALS['tcie_site'] . $p; }
function current_time( $format ) { return '2026-11-04 08:00:00'; }
function get_current_blog_id() { return 1; }
function get_current_user_id() { return 42; }
function absint( $v ) { return abs( (int) $v ); }
function get_user_meta( $uid, $key, $single = false ) {
    return $GLOBALS['tcie_report'][ $key ] ?? array();
}
function esc_url( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $x, $domain = '' ) { echo esc_attr( $x ); }
function esc_html_e( $x, $domain = '' ) { echo esc_html( $x ); }
function admin_url( $x = '' ) { return '/wp-admin/' . $x; }
function current_user_can( $x ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function wp_nonce_field( $name, $key ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '">'; }
function disabled( $is_disabled, $current = true, $echo = true ) {
    $html = $is_disabled === $current ? 'disabled="disabled"' : '';
    if ( $echo ) { echo $html; }
    return $html;
}
function assert_ie( $ok, $name ) {
    if ( ! $ok ) { echo 'FAIL ' . $name . PHP_EOL; exit( 1 ); }
}

require dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-auto-test.php';

$cases = TaxoCommerce_Import_Export_Auto_Test::cases();
assert_ie( count( $cases ) >= 35, 'all 35 existing check cases remain' );
assert_ie( isset( $cases['safe_queue_upgrade'], $cases['safe_export_upgrade'], $cases['real_media'] ), 'safety and manual UAT cases remain' );

$report = TaxoCommerce_Import_Export_Auto_Test::run_suite( 'all', array(
    'real_import' => $cases['real_import'],
) );
assert_ie( 'check' === $report['phase'] && 'all' === $report['group'], 'single full-suite schema' );
assert_ie( isset( $report['date'], $report['timestamp'], $report['executed_at'] ), 'timestamp and date in JSON' );
assert_ie( 'skip' === $report['rows'][0]['status'], 'real import never reported as PASS' );

$main = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-import-export.php' );
$admin = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export.php' );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-import-export-auto-test.php' );
assert_ie( false !== strpos( $main, 'class-taxocommerce-import-export-auto-test.php' ), 'class loaded' );
assert_ie( false !== strpos( $admin, 'TaxoCommerce_Import_Export_Auto_Test::render()' ), 'Autocheck tab accessible' );
assert_ie( false === strpos( $admin, '<header class="tcc-page-header">' ), 'duplicate plugin header removed' );
assert_ie( false === strpos( $source, 'test_phase' ) && false === strpos( $source, 'test_group' ), 'before/after and group selectors removed' );
assert_ie( false === strpos( $source, 'BASELINE' ) && false === strpos( $source, 'function compare(' ), 'unused comparison storage and code removed' );

ob_start();
TaxoCommerce_Import_Export_Auto_Test::render();
$empty = ob_get_clean();
assert_ie( 2 === substr_count( $empty, 'type="submit"' ), 'exactly 2 action buttons without prior report' );
assert_ie( false !== strpos( $empty, 'Ejecutar Autocheck' ), 'manual Autocheck action' );
assert_ie( false !== strpos( $empty, 'Descargar resultados (JSON)' ), 'JSON download action' );
assert_ie( false !== strpos( $empty, 'disabled="disabled"' ), 'download disabled until a report exists' );
assert_ie( false === strpos( $empty, 'ANTES' ) && false === strpos( $empty, 'DESPUÉS' ), 'no comparison UI' );
assert_ie( false === strpos( $empty, 'Ejecutar este grupo' ), 'no group buttons' );

$report['rows'] = array( array(
    'id' => 'real_import', 'status' => 'skip',
    'group_id' => 'uat', 'group' => 'Operaciones reales (validación manual)',
    'message' => 'Validación manual pendiente.', 'ms' => 0,
) );
$report['totals'] = array( 'pass' => 0, 'fail' => 0, 'skip' => 1, 'not_run' => 34 );
$GLOBALS['tcie_report']['taxocommerce_ie_at_latest_1'] = $report;
ob_start();
TaxoCommerce_Import_Export_Auto_Test::render();
$shown = ob_get_clean();
assert_ie( 2 === substr_count( $shown, 'type="submit"' ), 'exactly 2 action buttons with report' );
assert_ie( false === strpos( $shown, 'disabled="disabled"' ), 'download enabled when report exists' );
assert_ie( false !== strpos( $shown, 'Fecha de ejecución:' ), 'execution time visible' );
assert_ie( false !== strpos( $shown, '2026-11-04 08:00:00' ), 'execution date comes from report' );
$GLOBALS['tcie_site'] = 'https://different.test';
ob_start();
TaxoCommerce_Import_Export_Auto_Test::render();
$other = ob_get_clean();
assert_ie( false !== strpos( $other, 'disabled="disabled"' ), 'a different site cannot download the report' );

/* Ensure deployment diagnostics remain read-only and detect active work. */
class TaxoCommerce_Import_Export_Batch { const OPTION_STATE = 'tcie_test_queue'; }
class TaxoCommerce_Import_Export_Exporter { const STATE_PREFIX = 'tcie_test_export_'; }
function get_option( $key, $default = array() ) { return $GLOBALS['tcie_test_queue'] ?? $default; }
function get_transient( $key ) { return $GLOBALS['tcie_test_export'] ?? false; }
$queue = new ReflectionMethod( 'TaxoCommerce_Import_Export_Auto_Test', 'check_safe_queue_upgrade' );
$export = new ReflectionMethod( 'TaxoCommerce_Import_Export_Auto_Test', 'check_safe_export_upgrade' );
$GLOBALS['tcie_test_queue'] = array( 'status' => 'idle', 'enabled' => 0 );
$GLOBALS['tcie_test_export'] = false;
assert_ie( 'pass' === $queue->invoke( null )['status'], 'idle queue accepts deployment' );
assert_ie( 'pass' === $export->invoke( null )['status'], 'idle export accepts deployment' );
$GLOBALS['tcie_test_queue'] = array( 'status' => 'waiting_next', 'enabled' => 1 );
$GLOBALS['tcie_test_export'] = array( 'status' => 'processing' );
assert_ie( 'fail' === $queue->invoke( null )['status'], 'active queue blocks deployment' );
assert_ie( 'fail' === $export->invoke( null )['status'], 'active export blocks deployment' );
echo "Import-Export: two Autocheck buttons, one latest JSON, 35 checks and safe site-scoped rendering verified." . PHP_EOL;

// Include the server-side export contract in existing CI Autocheck coverage.
require __DIR__ . '/background-export-smoke.php';

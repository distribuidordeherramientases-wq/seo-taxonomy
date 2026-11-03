<?php
/** Manual AutoCheck functional regression: no WordPress installation or writes. */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
define( 'TAXOCOMMERCE_CORE_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );
class TaxoCommerce_Core_User_Guide { const PAGE_SLUG = 'taxocommerce-core-user-guide'; }
function apply_filters( $hook, $value ) { return $value; }
function current_user_can( $permission ) { return true; }
function get_current_screen() { return (object) array( 'id' => $GLOBALS['screen_id'] ); }
function get_plugin_page_hook( $slug, $parent ) {
    return isset( $GLOBALS['hooks'][ $slug ] ) ? 'hook_' . $slug : null;
}
function has_action( $hook ) { return false !== array_search( substr( $hook, 5 ), array_keys( $GLOBALS['hooks'] ), true ) ? 1 : false; }
function current_time( $format ) { return '2026-11-03 14:00:00'; }
function get_locale() { return 'es_ES'; }
function home_url( $path = '/' ) { return 'https://shop.example.test' . $path; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return (string) $value; }
function post_type_exists( $type ) { return true; }
function wp_count_posts( $type ) {
    if ( 'page' === $type && ! empty( $GLOBALS['emit_notice'] ) ) {
        trigger_error( 'Example PHP warning during category operation', E_USER_WARNING );
    }
    return (object) array( 'publish' => 4 );
}
function wp_die( $msg ) { throw new RuntimeException( $msg ); }
function check_admin_referer( $nonce, $field ) { throw new RuntimeException( 'invalid-nonce' ); }
function get_current_user_id() { return 7; }
function check( $condition, $label ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
}
require dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-auto-test.php';
$slugs = array( 'taxocommerce-core-content', 'taxocommerce-core-hierarchy', 'taxocommerce-core-menu', 'taxocommerce-core-user-guide' );
$GLOBALS['screen_id'] = 'toplevel_page_taxocommerce-core';
$GLOBALS['submenu']['taxocommerce-core'] = array();
$GLOBALS['hooks'] = array();
foreach ( $slugs as $slug ) {
    $GLOBALS['submenu']['taxocommerce-core'][] = array( $slug, 'manage_options', $slug );
    $GLOBALS['hooks'][ $slug ] = true;
}
$cases = TaxoCommerce_Core_Auto_Test::cases();
check( isset( $cases['admin_routes'], $cases['plugin_files'], $cases['category_fields'], $cases['native_roles'] ), 'New coverage exists' );
$all = TaxoCommerce_Core_Auto_Test::run_suite( array( 'admin_routes' => $cases['admin_routes'] ) );
check( $all['totals']['pass'] === 1, 'Registered routes work' );
array_pop( $GLOBALS['submenu']['taxocommerce-core'] );
$broken = TaxoCommerce_Core_Auto_Test::run_suite( array( 'admin_routes' => $cases['admin_routes'] ) );
check( $broken['totals']['fail'] === 1, 'Missing WordPress submenu is detected' );
check( false !== strpos( $broken['rows'][0]['message'], 'taxocommerce-core-user-guide' ), 'Failed route identified' );
$GLOBALS['screen_id'] = 'unrelated';
$unsupported = TaxoCommerce_Core_Auto_Test::run_suite( array( 'admin_routes' => $cases['admin_routes'] ) );
check( $unsupported['totals']['skip'] === 1, 'Unsupported contexts are not green' );
$GLOBALS['emit_notice'] = true;
$warnings = TaxoCommerce_Core_Auto_Test::run_suite( array( 'content' => $cases['content'] ) );
check( $warnings['totals']['fail'] === 1, 'PHP warning makes a test fail' );
check( isset( $warnings['rows'][0]['php_errors'][0]['severity'] ), 'PHP warning details captured' );
$payload = TaxoCommerce_Core_Auto_Test::export_payload( $warnings );
check( count( $payload['autocheck']['errors'] ) === 1, 'Structured errors in JSON' );
check( $payload['autocheck']['errors'][0]['php_errors'] === $warnings['rows'][0]['php_errors'], 'JSON preserves PHP warning evidence' );
$payload = TaxoCommerce_Core_Auto_Test::export_payload( $unsupported );
check( $payload['autocheck']['overall_status'] === 'incomplete', 'Skipped checks do not count as pass' );
check( count( $payload['autocheck']['not_executed'] ) === 1, 'Skipped checks in JSON' );
$_SERVER['REQUEST_METHOD'] = 'GET';
TaxoCommerce_Core_Auto_Test::handle_dashboard_run(); // Must not run on a read-only page visit.
$_SERVER['REQUEST_METHOD'] = 'POST';
unset( $_POST['tc_autotest_manual_run'] );
TaxoCommerce_Core_Auto_Test::handle_dashboard_run(); // Other dashboard POSTs never run suite.
$_POST['tc_autotest_manual_run'] = '1';
try {
    TaxoCommerce_Core_Auto_Test::handle_dashboard_run();
    check( false, 'Manual POST must verify nonce' );
} catch ( RuntimeException $e ) {
    check( 'invalid-nonce' === $e->getMessage(), 'Manual run requires nonce' );
}
echo "PASS: manual Core AutoCheck routes, PHP errors, structured JSON and trigger\n";

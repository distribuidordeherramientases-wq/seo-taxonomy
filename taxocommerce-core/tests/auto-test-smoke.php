<?php
/** Offline smoke test for Core Auto Test. No WordPress, DB or network needed. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'TAXOCOMMERCE_CORE_VERSION', '0.4.34' );

class TaxoCommerce_Core_Country {
    public static function normalize( $value ) {
        $value = strtoupper( $value );
        return in_array( $value, array( 'ES', 'US', 'FR', 'DE', 'NL' ), true ) ? $value : '';
    }
}
class Fake_Core {
    public function content() { return $this; }
    public function hierarchy() { return $this; }
    public function menu() { return $this; }
    public function get_registered_modules() { return array( 'core' => array( 'status' => 'active' ) ); }
}
function taxocommerce_core() { return new Fake_Core(); }
function current_time( $format ) { return '2026-10-29 12:00:00'; }
function has_action( $name ) { return false !== strpos( $name, 'admin_post_' ) ? 1 : false; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['allowed'] ); }
function apply_filters( $hook, $value ) { return $value; }
function esc_html__( $s, $d ) { return $s; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function check_admin_referer( $action, $field ) { throw new RuntimeException( 'Invalid nonce' ); }
function absint( $i ) { return abs( (int) $i ); }
function get_option( $key, $fallback ) { return isset( $GLOBALS['options'][ $key ] ) ? $GLOBALS['options'][ $key ] : $fallback; }
function is_wp_error( $value ) { return false; }

function verify( $pass, $label ) {
    if ( ! $pass ) {
        fwrite( STDERR, 'FAIL: ' . $label . "\n" );
        exit( 1 );
    }
    $GLOBALS['count'] = ( $GLOBALS['count'] ?? 0 ) + 1;
}

require dirname( __DIR__ ) . '/includes/class-taxocommerce-core-auto-test.php';
$cases = TaxoCommerce_Core_Auto_Test::cases();
verify( count( $cases ) >= 15, 'Suite covers principal Core areas' );
verify( isset( $cases['menus'], $cases['nodes'], $cases['relations'], $cases['post_country'] ), 'All major Core paths present' );
$report = TaxoCommerce_Core_Auto_Test::run_suite( array(
    'runtime' => $cases['runtime'],
    'countries' => $cases['countries'],
    'actions' => $cases['actions'],
    'write_uat' => $cases['write_uat'],
    'unknown_case' => array( 'Tests', 'Missing test' ),
    'content' => $cases['content'], // No WP functions: caught as Throwable, not fatal for suite.
) );
verify( count( $report['rows'] ) === 6, 'Every test runs after failure' );
verify( $report['totals']['pass'] === 3, 'Three positive checks' );
verify( $report['totals']['fail'] === 2, 'Missing test and exception are red' );
verify( $report['totals']['skip'] === 1, 'Write UAT is neutral, never falsely green' );
verify( $report['rows'][4]['status'] === 'fail' && strpos( $report['rows'][4]['message'], 'unknown_case' ) !== false, 'Missing implementation has error message' );
verify( $report['rows'][5]['status'] === 'fail' && strpos( $report['rows'][5]['message'], 'Error:' ) !== false, 'Thrown error is displayed and isolated' );
verify( $report['rows'][3]['status'] === 'skip', 'Unexecuted writes are marked skipped' );
verify( $report['version'] === TAXOCOMMERCE_CORE_VERSION, 'Version included in report' );

$GLOBALS['allowed'] = false;
try {
    TaxoCommerce_Core_Auto_Test::handle_run();
    verify( false, 'Unauthorized request must fail' );
} catch ( RuntimeException $e ) {
    verify( strpos( $e->getMessage(), 'Sin permisos' ) !== false, 'Permission gate enforced' );
}
$GLOBALS['allowed'] = true;
try {
    TaxoCommerce_Core_Auto_Test::handle_run();
    verify( false, 'Missing nonce must fail' );
} catch ( RuntimeException $e ) {
    verify( $e->getMessage() === 'Invalid nonce', 'Nonce gate enforced' );
}
echo 'PASS: ' . $GLOBALS['count'] . " Core Auto Test smoke assertions.\n";

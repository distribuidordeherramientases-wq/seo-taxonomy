<?php
/**
 * Auditor Auto Test and Manual offline smoke. It runs the existing native parity
 * fixtures first, then checks admin contracts without WordPress or the filesystem.
 * Kept in tests/; excluded from all installed plugin packages.
 */
require __DIR__ . '/smoke.php';

define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['tca_hooks'] = array();
$GLOBALS['tca_options'] = array();
$GLOBALS['tca_written'] = 0;

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
    $GLOBALS['tca_hooks'][ $hook ][] = $callback;
}
function has_action( $hook ) { return ! empty( $GLOBALS['tca_hooks'][ $hook ] ); }
function register_deactivation_hook( $file, $callback ) {}
function current_user_can( $permission ) { return 'manage_options' === $permission; }
function post_type_exists( $type ) { return 'product' === $type; }
function get_option( $name, $default = false ) { return $GLOBALS['tca_options'][ $name ] ?? $default; }
function update_option( $name, $value ) {
    ++$GLOBALS['tca_written'];
    return false;
}
function current_time( $format, $gmt = false ) { return '2026-10-31 22:30:00'; }
function get_transient( $name ) { return false; }
function get_current_user_id() { return 1; }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_html__( $message, $domain = '' ) { return $message; }
function wp_nonce_field( $action, $name = '_wpnonce' ) {
    echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="test">';
}
function submit_button( $label, $type = 'primary', $name = 'submit', $wrap = true ) {
    echo '<button type="submit">' . esc_html( $label ) . '</button>';
}
function wp_die( $message ) { throw new \RuntimeException( (string) $message ); }

require dirname( __DIR__ ) . '/taxocommerce-auditor.php';
\TaxoCommerce\Auditor\Plugin::boot();

use TaxoCommerce\Auditor\Auto_Test;
use TaxoCommerce\Auditor\Manual;
use TaxoCommerce\Auditor\Plugin;

verify( Plugin::VERSION === '0.3.2', 'Current tested version is 0.3.2' );
verify( has_action( 'admin_post_' . Auto_Test::ACTION ), 'Auto Test action registered' );
verify( has_action( \TaxoCommerce\Auditor\Runner::HOOK ), 'Existing cron hook remains registered' );
verify( count( Auto_Test::cases() ) === 16, '16 functional checks' );

$report = Auto_Test::run_suite();
verify( $report['totals']['pass'] === 15, 'All 15 safe checks pass with healthy fixtures' );
verify( $report['totals']['fail'] === 0 && $report['totals']['skip'] === 1, 'Destructive actions skipped, not passed' );
verify( count( $report['rows'] ) === 16, 'Every check emits one result' );
verify( $report['version'] === Plugin::VERSION && $report['ms'] >= 0, 'Timestamp/version/time reported' );
verify( $GLOBALS['tca_written'] === 0, 'No WordPress option mutated by suite' );

ob_start();
Auto_Test::render();
$auto = ob_get_clean();
verify( strpos( $auto, 'Ejecutar Auto Test' ) !== false, 'Auto Test renders run button' );
verify( strpos( $auto, 'taxocommerce_auditor_auto_nonce' ) !== false, 'Run form nonce present' );

ob_start();
Manual::render();
$manual = ob_get_clean();
foreach ( array(
    'Manual de usuario', 'Manual técnico', 'Auditoría completa',
    'Productos', 'FAQs', 'JSON Lines', 'WP-Cron', 'manage_options', 'Auto Test',
) as $word ) {
    verify( strpos( $manual, $word ) !== false, 'Manual covers ' . $word );
}

$admin = file_get_contents( dirname( __DIR__ ) . '/includes/class-admin.php' );
verify( strpos( $admin, "'auto-test' => 'Auto Test'" ) !== false, 'Auto Test tab wired' );
verify( strpos( $admin, "'manual' => 'Manual'" ) !== false, 'Manual tab wired' );
verify( strpos( $admin, 'self::render_scope_controls( $selected )' ) !== false, 'Five legacy scope controls unchanged' );
verify( strpos( $admin, "'categories' === $scope" ) !== false, 'Category-only report view still available' );

echo "Auditor Auto Test and Manual offline smoke passed.\n";

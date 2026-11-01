<?php
/**
 * Standalone read-only Auto Test and User Guide regression contract.
 *
 * Run: php taxocommerce-system-check/tests/auto-test-guide-smoke.php
 */
if ( 'cli' !== PHP_SAPI ) {
    exit;
}
define( 'ABSPATH', '/' );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_VERSION', '0.2.12' );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_PATH', dirname( __DIR__ ) . '/' );
$GLOBALS['tcs_hooks'] = array();
$GLOBALS['tcs_nonce_calls'] = 0;
$GLOBALS['tcs_mutations'] = 0;
$GLOBALS['tcs_allowed'] = true;

function add_action( $hook, $callback ) { $GLOBALS['tcs_hooks'][ $hook ] = $callback; }
function add_filter( $hook, $callback ) { $GLOBALS['tcs_hooks'][ $hook ] = $callback; }
function has_action( $hook, $callback = false ) {
    if ( ! isset( $GLOBALS['tcs_hooks'][ $hook ] ) ) { return false; }
    return false === $callback || $callback === $GLOBALS['tcs_hooks'][ $hook ];
}
function current_user_can( $capability ) { return $GLOBALS['tcs_allowed'] && 'manage_options' === $capability; }
function wp_die( $message ) { throw new RuntimeException( (string) $message ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=test'; }
function check_admin_referer( $action ) {
    ++$GLOBALS['tcs_nonce_calls'];
    if ( 'tcs_auto_test_run' !== $action || ( $_GET['_wpnonce'] ?? '' ) !== 'test' ) { throw new RuntimeException( 'Missing nonce.' ); }
}
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function taxocommerce_system_check_home() {}
function taxocommerce_system_check_active_tab() { return 'auto-test'; }
function taxocommerce_system_check_register_menu() {}
function taxocommerce_system_check_modules_json() {}
function taxocommerce_system_check_module_results() { return array( 'system_check' => array( 'version' => '0.2.12' ) ); }
function taxocommerce_system_check_tab_url( $tab, $args = array() ) {
    return add_query_arg( array_merge( array( 'page' => 'taxocommerce-system-check', 'tcs_tab' => $tab ), $args ), admin_url( 'admin.php' ) );
}
function wp_remote_get() { ++$GLOBALS['tcs_mutations']; throw new RuntimeException( 'Remote calls prohibited.' ); }
function wp_mail() { ++$GLOBALS['tcs_mutations']; throw new RuntimeException( 'Emails prohibited.' ); }
function update_option() { ++$GLOBALS['tcs_mutations']; throw new RuntimeException( 'DB writes prohibited.' ); }
function set_transient() { ++$GLOBALS['tcs_mutations']; throw new RuntimeException( 'Transient writes prohibited.' ); }
function wp_schedule_event() { ++$GLOBALS['tcs_mutations']; throw new RuntimeException( 'Cron writes prohibited.' ); }
function tcs_smoke_expect( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
require_once dirname( __DIR__ ) . '/includes/class-taxocommerce-system-check-php.php';
require_once dirname( __DIR__ ) . '/includes/class-taxocommerce-system-check-url-audit.php';
require_once dirname( __DIR__ ) . '/includes/class-taxocommerce-system-check-auto-test.php';
require_once dirname( __DIR__ ) . '/includes/class-taxocommerce-system-check-user-guide.php';

TaxoCommerce_System_Check_URL_Audit::register_hooks();
add_action( 'admin_menu', 'taxocommerce_system_check_register_menu' );
add_action( 'admin_post_taxocommerce_system_check_modules_json', 'taxocommerce_system_check_modules_json' );
$rows = TaxoCommerce_System_Check_Auto_Test::run();
tcs_smoke_expect( count( $rows['rows'] ) === count( TaxoCommerce_System_Check_Auto_Test::cases() ), 'All self-tests must appear.' );
tcs_smoke_expect( $rows['totals']['fail'] === 0 && $rows['totals']['skip'] >= 3, 'Optional and destructive tests must not appear green.' );
tcs_smoke_expect( $GLOBALS['tcs_mutations'] === 0, 'Self-test must not modify data, schedule jobs or call external services.' );

unset( $_GET['tcs_run'], $_GET['_wpnonce'] );
ob_start();
TaxoCommerce_System_Check_Auto_Test::render();
$initial = ob_get_clean();
tcs_smoke_expect( strpos( $initial, 'Ejecutar Auto Test' ) !== false && $GLOBALS['tcs_nonce_calls'] === 0, 'Opening tab must not run self-test.' );
$_GET['tcs_run'] = '1';
$_GET['_wpnonce'] = 'test';
ob_start();
TaxoCommerce_System_Check_Auto_Test::render();
$html = ob_get_clean();
tcs_smoke_expect( $GLOBALS['tcs_nonce_calls'] === 1 && strpos( $html, 'No ejecutado / N/A' ) !== false, 'Explicit signed test must render skip evidence.' );
tcs_smoke_expect( $GLOBALS['tcs_mutations'] === 0, 'Rendering must not trigger mutations.' );

unset( $_GET['tcs_run'], $_GET['_wpnonce'] );
foreach ( array( 'user' => 'Manual de usuario', 'technical' => 'Manual técnico' ) as $kind => $title ) {
    $_GET['tcs_manual'] = $kind;
    ob_start();
    TaxoCommerce_System_Check_User_Guide::render();
    $guide = ob_get_clean();
    tcs_smoke_expect( strpos( $guide, $title ) !== false && substr_count( $guide, '<h2>' ) >= 7 && substr_count( $guide, '<h3>' ) >= 7, 'Both manuals need indexed H2/H3 content.' );
    tcs_smoke_expect( strpos( $guide, 'tcs-guide-' . $kind . '-' ) !== false, 'Manual index and anchor missing.' );
}
$GLOBALS['tcs_allowed'] = false;
try {
    TaxoCommerce_System_Check_Auto_Test::render();
    tcs_smoke_expect( false, 'Unauthorised Auto Test must be blocked.' );
} catch ( RuntimeException $error ) {
    tcs_smoke_expect( strpos( $error->getMessage(), 'Acceso' ) !== false, 'Unexpected permissions error.' );
}
tcs_smoke_expect( $GLOBALS['tcs_mutations'] === 0, 'No test may send mail, modify options or schedule tasks.' );
echo "System Check Auto Test and User/Technical Guide: OK\n";

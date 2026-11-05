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
define( 'TAXOCOMMERCE_SYSTEM_CHECK_VERSION', '0.2.17' );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_PATH', dirname( __DIR__ ) . '/' );
$GLOBALS['tcs_hooks'] = array();
$GLOBALS['tcs_nonce_calls'] = 0;
$GLOBALS['tcs_mutations'] = 0;
$GLOBALS['tcs_allowed'] = true;
$GLOBALS['tcs_reports'] = array();

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
function wp_nonce_field( $action, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . $name . '" value="test">'; }
function submit_button( $label, $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button type="submit">' . esc_html( $label ) . '</button>'; }
function check_admin_referer( $action ) {
    ++$GLOBALS['tcs_nonce_calls'];
    if ( 'tcs_auto_test_run' !== $action || ( $_GET['_wpnonce'] ?? '' ) !== 'test' ) { throw new RuntimeException( 'Missing nonce.' ); }
}
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function __( $value, $domain = '' ) { return $value; }
function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function taxocommerce_system_check_home() {}
function taxocommerce_system_check_active_tab() { return 'auto-test'; }
function taxocommerce_system_check_register_menu() {}
function taxocommerce_system_check_modules_json() {}
function taxocommerce_system_check_module_results() { return array( 'system_check' => array( 'version' => '0.2.13' ) ); }
function taxocommerce_system_check_tab_url( $tab, $args = array() ) {
    return add_query_arg( array_merge( array( 'page' => 'taxocommerce-system-check', 'tcs_tab' => $tab ), $args ), admin_url( 'admin.php' ) );
}
function wp_remote_get() { ++$GLOBALS['tcs_mutations']; throw new RuntimeException( 'Remote calls prohibited.' ); }
function wp_mail() { ++$GLOBALS['tcs_mutations']; throw new RuntimeException( 'Emails prohibited.' ); }
function update_option() { ++$GLOBALS['tcs_mutations']; throw new RuntimeException( 'DB writes prohibited.' ); }
function get_current_blog_id() { return 5; }
function get_current_user_id() { return 12; }
function get_transient( $key ) { return $GLOBALS['tcs_reports'][ $key ] ?? false; }
function set_transient( $key, $value, $expiration ) {
    tcs_smoke_expect( $expiration === HOUR_IN_SECONDS, 'Stored report must expire.' );
    $GLOBALS['tcs_reports'][ $key ] = $value;
    return true;
}
define( 'HOUR_IN_SECONDS', 3600 );
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
tcs_smoke_expect( strpos( $initial, 'Ejecutar Autocheck' ) !== false && $GLOBALS['tcs_nonce_calls'] === 0, 'Opening tab must not run self-test.' );
tcs_smoke_expect( strpos( $initial, 'taxocommerce_system_check_autocheck_json' ) === false, 'No JSON download must appear before the first execution.' );
$_GET['tcs_run'] = '1';
$_GET['_wpnonce'] = 'test';
ob_start();
TaxoCommerce_System_Check_Auto_Test::render();
$html = ob_get_clean();
tcs_smoke_expect( $GLOBALS['tcs_nonce_calls'] === 1 && strpos( $html, 'No ejecutado / N/A' ) !== false && strpos( $html, 'taxocommerce_system_check_autocheck_json' ) !== false, 'Explicit signed test must render skip evidence.' );
tcs_smoke_expect( $GLOBALS['tcs_mutations'] === 0, 'Rendering must not trigger mutations.' );
tcs_smoke_expect( isset( $GLOBALS['tcs_reports']['taxocommerce_system_check_autocheck_latest_5_12'] ), 'Completed run must save the real report for the current site and user.' );
$nonce_count = $GLOBALS['tcs_nonce_calls'];
unset( $_GET['tcs_run'], $_GET['_wpnonce'] );
ob_start();
TaxoCommerce_System_Check_Auto_Test::render();
$return_visit = ob_get_clean();
tcs_smoke_expect( strpos( $return_visit, 'taxocommerce_system_check_autocheck_json' ) !== false, 'Returning administrator must still see JSON download.' );
tcs_smoke_expect( strpos( $return_visit, 'Fecha de ejecución:' ) !== false, 'The stored report execution timestamp must be shown.' );
tcs_smoke_expect( $nonce_count === $GLOBALS['tcs_nonce_calls'] && $GLOBALS['tcs_mutations'] === 0, 'Returning to Autocheck must not execute tests or external actions.' );
$GLOBALS['tcs_reports'] = array();
ob_start();
TaxoCommerce_System_Check_Auto_Test::render();
$expired_visit = ob_get_clean();
tcs_smoke_expect( strpos( $expired_visit, 'taxocommerce_system_check_autocheck_json' ) === false, 'Expired report must not have a download button.' );

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

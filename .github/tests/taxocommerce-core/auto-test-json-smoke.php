<?php
/**
 * Core Autocheck JSON download integration smoke, with isolated WP stubs.
 *
 * Run normally for access/UI tests. Pipe "download" to "assert-json"
 * to validate the real handler output (including the exit path).
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'TAXOCOMMERCE_CORE_VERSION', '0.4.53' );

class TaxoCommerce_Core_User_Guide { const PAGE_SLUG = 'taxocommerce-core-manual'; }
$GLOBALS['uid'] = 42;
$GLOBALS['allowed'] = true;
$GLOBALS['nonce_valid'] = true;
$GLOBALS['registered'] = array();
$GLOBALS['saved_reports'] = array();

function add_action( $name, $callable ) { $GLOBALS['registered'][ $name ] = $callable; }
function current_user_can( $cap ) { return $GLOBALS['allowed']; }
function apply_filters( $hook, $value ) { return $value; }
function get_current_user_id() { return $GLOBALS['uid']; }
function get_transient( $key ) { return $GLOBALS['saved_reports'][ $key ] ?? false; }
function check_admin_referer( $action, $name ) {
    $GLOBALS['checked_nonce'] = array( $action, $name );
    if ( ! $GLOBALS['nonce_valid'] ) { throw new RuntimeException( 'Nonce verification failed' ); }
}
function wp_die( $message ) { throw new RuntimeException( $message ); }
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html_e( $text, $domain = '' ) { echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $text, $domain = '' ) { echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $text ) { return esc_html( $text ); }
function absint( $value ) { return abs( (int) $value ); }
function home_url( $path = '' ) { return 'https://focazul.example/' . ltrim( $path, '/' ); }
function admin_url( $path = '' ) { return home_url( 'wp-admin/' . $path ); }
function get_bloginfo( $field ) { return 'Asociación Focazul'; }
function get_locale() { return 'es_ES'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function sanitize_file_name( $name ) { return preg_replace( '/[^a-zA-Z0-9._-]+/', '-', $name ); }
function wp_json_encode( $value, $options = 0 ) { return json_encode( $value, $options ); }
function nocache_headers() { $GLOBALS['no_cache'] = true; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_nonce_field( $action, $name ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">'; }
function submit_button( $text, $class, $name, $wrap ) {
    echo '<button type="submit" class="button button-' . esc_attr( $class ) . '">' . esc_html( $text ) . '</button>';
}
function assert_true( $condition, $description ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $description . "\n" );
        exit( 1 );
    }
    $GLOBALS['assertions'] = ( $GLOBALS['assertions'] ?? 0 ) + 1;
}

require dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-auto-test.php';
$report = array(
    'version' => '0.4.53',
    'date'    => '2026-11-03 12:34:56',
    'ms'      => 184,
    'totals'  => array( 'pass' => 1, 'fail' => 1, 'skip' => 1 ),
    'rows'    => array(
        array( 'id' => 'runtime', 'group' => 'Core', 'label' => 'Arranque',
            'status' => 'pass', 'message' => 'OK', 'ms' => 3 ),
        array( 'id' => 'menus', 'group' => 'Menús', 'label' => 'Cargar el menú',
            'status' => 'fail', 'message' => 'No se ha podido cargar', 'ms' => 15 ),
        array( 'id' => 'write_uat', 'group' => 'Operaciones', 'label' => 'Prueba de escritura',
            'status' => 'skip', 'message' => 'Sin ejecutar', 'ms' => 0 ),
    ),
);
$key = TaxoCommerce_Core_Auto_Test::REPORT_PREFIX . $GLOBALS['uid'];

if ( isset( $argv[1] ) && 'assert-json' === $argv[1] ) {
    $data = json_decode( stream_get_contents( STDIN ), true );
    assert_true( is_array( $data ), 'Actual handler outputs valid UTF-8 JSON' );
    assert_true( 1 === $data['schema_version'] && 'taxocommerce-core' === $data['plugin'], 'Stable schema and plugin identity' );
    assert_true( 'https://focazul.example/' === $data['site']['url'] && 'es_ES' === $data['site']['locale'], 'Real installation identity in JSON' );
    assert_true( 'Asociación Focazul' === $data['site']['name'], 'UTF-8 site title is preserved' );
    assert_true( $report === $data['report'], 'Downloaded report must be exactly the visible snapshot' );
    assert_true( 1 === $data['report']['totals']['fail'] && 1 === $data['report']['totals']['skip'], 'Fail and skipped checks are never hidden' );
    echo 'PASS: ' . $GLOBALS['assertions'] . " real JSON export assertions.\n";
    exit;
}
if ( isset( $argv[1] ) && 'download' === $argv[1] ) {
    $GLOBALS['saved_reports'][ $key ] = $report;
    TaxoCommerce_Core_Auto_Test::handle_export();
    assert_true( false, 'Handler should exit after download' );
    exit;
}

TaxoCommerce_Core_Auto_Test::register_hooks();
assert_true( isset( $GLOBALS['registered']['admin_post_' . TaxoCommerce_Core_Auto_Test::EXPORT_ACTION] ), 'JSON handler hooked only to authenticated admin-post' );
assert_true( ! isset( $GLOBALS['registered']['admin_post_nopriv_' . TaxoCommerce_Core_Auto_Test::EXPORT_ACTION] ), 'No unauthenticated export route' );

// Unauthorized users cannot download another user's report.
$GLOBALS['allowed'] = false;
try {
    TaxoCommerce_Core_Auto_Test::handle_export();
    assert_true( false, 'Unauthorised download must fail' );
} catch ( RuntimeException $e ) {
    assert_true( strpos( $e->getMessage(), 'Sin permisos' ) !== false, 'Capability gate enforced' );
}
$GLOBALS['allowed'] = true;
$GLOBALS['nonce_valid'] = false;
try {
    TaxoCommerce_Core_Auto_Test::handle_export();
    assert_true( false, 'Wrong nonce must fail' );
} catch ( RuntimeException $e ) {
    assert_true( 'Nonce verification failed' === $e->getMessage(), 'Export requires a valid nonce' );
}
assert_true( $GLOBALS['checked_nonce'] === array( TaxoCommerce_Core_Auto_Test::EXPORT_NONCE, 'tc_autotest_export_nonce' ), 'Nonce action tied to export' );
$GLOBALS['nonce_valid'] = true;
try {
    TaxoCommerce_Core_Auto_Test::handle_export();
    assert_true( false, 'Export without prior report must fail' );
} catch ( RuntimeException $e ) {
    assert_true( strpos( $e->getMessage(), 'No hay resultados' ) !== false, 'No fabricated empty report' );
}

// UI hides the export action until this user has run Autocheck.
ob_start();
TaxoCommerce_Core_Auto_Test::render();
$empty_html = ob_get_clean();
assert_true( false === strpos( $empty_html, 'Descargar JSON' ), 'Button absent without previous results' );
$GLOBALS['saved_reports'][ $key ] = $report;
ob_start();
TaxoCommerce_Core_Auto_Test::render();
$html = ob_get_clean();
assert_true( false !== strpos( $html, '>Descargar JSON<' ), 'Button shown with real results' );
assert_true( false !== strpos( $html, 'value="' . TaxoCommerce_Core_Auto_Test::EXPORT_ACTION . '"' ), 'Form posts the export action' );
assert_true( false !== strpos( $html, 'name="tc_autotest_export_nonce"' ), 'Export form has CSRF nonce' );
assert_true( false !== strpos( $html, 'No se ha podido cargar' ), 'UI displays the same failing result being exported' );
assert_true( false !== strpos( $html, 'Sin ejecutar' ), 'UI displays skipped results' );
$GLOBALS['uid'] = 84;
ob_start();
TaxoCommerce_Core_Auto_Test::render();
$other_user_html = ob_get_clean();
assert_true( false === strpos( $other_user_html, 'Descargar JSON' ), 'Reports are segregated per user' );
try {
    TaxoCommerce_Core_Auto_Test::handle_export();
    assert_true( false, 'Another user may not download prior user report' );
} catch ( RuntimeException $e ) {
    assert_true( strpos( $e->getMessage(), 'No hay resultados' ) !== false, 'Export ownership tied to current user' );
}
echo 'PASS: ' . $GLOBALS['assertions'] . " Core Autocheck JSON auth and UI assertions.\n";

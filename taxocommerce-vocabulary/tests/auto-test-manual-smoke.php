<?php
/** Offline smoke for Vocabulary Auto Test/Manual; never contacts WordPress or MySQL. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'TAXOCOMMERCE_VOCABULARY_VERSION', '0.3.6' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );
$tcv_hooks = array();
$tcv_transients = array();

function add_action( $name, $callback ) { global $tcv_hooks; $tcv_hooks[$name][] = $callback; }
function has_action( $name ) { global $tcv_hooks; return ! empty( $tcv_hooks[$name] ); }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function post_type_exists( $value ) { return 'product' === $value; }
function taxonomy_exists( $value ) { return 'product_cat' === $value; }
function current_time( $fmt ) { return '2026-10-31 22:30:00'; }
function get_current_user_id() { return 9; }
function get_transient( $key ) { global $tcv_transients; return $tcv_transients[$key] ?? false; }
function set_transient( $key, $data, $ttl ) { global $tcv_transients; $tcv_transients[$key] = $data; return true; }
function admin_url( $value ) { return '/wp-admin/' . $value; }
function remove_query_arg( $key, $url = '/wp-admin/admin.php?page=taxocommerce-vocabulary' ) { return $url; }
function add_query_arg( $key, $value = null, $url = '' ) { return ( $url ?: admin_url( 'admin.php?page=taxocommerce-vocabulary' ) ) . '&' . rawurlencode( $key ) . '=' . rawurlencode( (string) $value ); }
function esc_attr__( $value, $domain = '' ) { return esc_attr( $value ); }
function wp_unslash( $value ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_html__( $value, $domain ) { return $value; }
function esc_html_e( $value, $domain ) { echo esc_html( $value ); }
function wp_nonce_field( $action, $field = '_wpnonce' ) { echo '<input type="hidden" name="' . esc_attr($field) . '" value="nonce">'; }
function submit_button( $label, $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button type="submit">' . esc_html( $label ) . '</button>'; }
function wp_die( $message ) { throw new RuntimeException( $message ); }

// Portable content relationships are resolved by site-local slug/path, never source ID.
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
class WP_Error {
    private $code;
    public function __construct( $code ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_page_by_path( $path, $output = OBJECT, $type = 'page' ) {
    if ( 'page' === $type && 'seguridad/bicicleta' === $path ) {
        return (object) array( 'ID' => 77, 'post_type' => 'page', 'post_status' => 'publish' );
    }
    if ( 'post' === $type && 'guia-herramientas' === $path ) {
        return (object) array( 'ID' => 78, 'post_type' => 'post', 'post_status' => 'publish' );
    }
    if ( 'page' === $type && 'pagina-eliminada' === $path ) {
        return (object) array( 'ID' => 79, 'post_type' => 'page', 'post_status' => 'trash' );
    }
    return null;
}
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_title( $title ) { return trim( preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( (string) $title ) ), '-' ); }
function absint( $value ) { return abs( (int) $value ); }

function tcv_test( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: " . $message . "\n" );
        exit( 1 );
    }
}

class TCV_Test_DB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $missing = '';
    public $missing_column = '';
    public $writes = 0;
    public $queries = array();

    public function esc_like( $value ) { return $value; }
    public function prepare( $sql ) {
        $args = array_slice( func_get_args(), 1 );
        $sql = str_replace( '%i', '%s', $sql );
        return vsprintf( $sql, $args );
    }
    public function get_var( $sql ) {
        $this->queries[] = $sql;
        if ( 'SELECT 1' === $sql ) return 1;
        if ( preg_match( "/SHOW TABLES LIKE (wp_[A-Za-z0-9_]+)/", $sql, $parts ) ) {
            return $parts[1] === $this->missing ? null : $parts[1];
        }
        return null;
    }
    public function get_col( $sql ) {
        $this->queries[] = $sql;
        $this->last_error = '';
        if ( 0 === strpos( $sql, 'SHOW COLUMNS FROM ' ) ) {
            if ( preg_match( '/SHOW COLUMNS FROM ([a-z0-9_]+)/i', $sql, $part ) && $part[1] === $this->missing_column ) return array( 'id' );
            return array( 'id', 'semantic_group', 'slug', 'label', 'active', 'object_id', 'object_type',
                'vocabulary_id', 'status', 'type_vocabulary_id', 'role_vocabulary_id',
                'nombre', 'activo', 'atributo_id', 'alias', 'product_id', 'state', 'term_id' );
        }
        if ( 0 === strpos( $sql, 'SELECT ' ) ) return array();
        $this->last_error = 'unexpected SQL';
        return false;
    }
}
$wpdb = new TCV_Test_DB();

require dirname( __DIR__ ) . '/includes/class-tcv-schema.php';
require dirname( __DIR__ ) . '/includes/class-tcv-classifier.php';
require dirname( __DIR__ ) . '/includes/class-tcv-io.php';
require dirname( __DIR__ ) . '/includes/class-tcv-portable.php';
require dirname( __DIR__ ) . '/includes/class-tcv-admin.php';
require dirname( __DIR__ ) . '/includes/class-tcv-manual.php';
require dirname( __DIR__ ) . '/includes/class-taxocommerce-autotest-code.php';
require dirname( __DIR__ ) . '/includes/class-tcv-auto-test.php';

TCV_IO::boot();
TCV_Portable::boot();
TCV_Admin::boot();

// Native posts and pages (including SEO landings) must be portable without Core.
$portable_scopes = TCV_Portable::allowed_scopes();
tcv_test( in_array( 'post_semantic', $portable_scopes, true ), 'post Vocabulary is exportable' );
tcv_test( in_array( 'page_semantic', $portable_scopes, true ), 'page and landing Vocabulary is exportable' );
$resolve = new ReflectionMethod( 'TCV_Portable', 'resolve_content' );
$resolve->setAccessible( true );
tcv_test( 77 === $resolve->invoke( null, array( 'post_type' => 'page', 'path' => 'seguridad/bicicleta', 'source_id' => 999 ) ), 'page uses site-local path, not external numeric ID' );
tcv_test( 78 === $resolve->invoke( null, array( 'post_type' => 'post', 'path' => 'guia-herramientas', 'source_id' => 999 ) ), 'post resolves by site-local slug' );
tcv_test( is_wp_error( $resolve->invoke( null, array( 'post_type' => 'page', 'path' => 'pagina-eliminada' ) ) ), 'trashed page cannot be modified' );
tcv_test( is_wp_error( $resolve->invoke( null, array( 'post_type' => 'product', 'path' => 'guia-herramientas' ) ) ), 'no arbitrary content types' );
tcv_test( is_wp_error( $resolve->invoke( null, array( 'post_type' => 'post', 'path' => 'seguridad/bicicleta' ) ) ), 'post cannot resolve hierarchical page path' );

$cases = TCV_Auto_Test::cases();
tcv_test( count( $cases ) === 16, 'exactly 16 checks' );
tcv_test( has_action( 'admin_post_tcv_run_auto_test' ), 'autotest registered' );
$report = TCV_Auto_Test::run_suite();
tcv_test( $report['totals']['pass'] === 15 && $report['totals']['fail'] === 0 && $report['totals']['skip'] === 1, 'healthy run 15 pass, 1 skip' );
tcv_test( $report['version'] === TAXOCOMMERCE_VOCABULARY_VERSION, 'plugin version' );
tcv_test( count( $report['rows'] ) === count( $cases ), 'all checks reported' );
foreach ( $wpdb->queries as $sql ) {
    tcv_test( preg_match( '/^(SELECT|SHOW) /', $sql ) === 1, 'read-only SQL: ' . $sql );
    if ( strpos( $sql, ' LIMIT ' ) !== false ) {
        tcv_test( substr( $sql, -7 ) === 'LIMIT 1', 'bounded sample size' );
    }
}
tcv_test( 0 === $wpdb->writes, 'no SQL writes' );

$wpdb->missing = 'wp_seo_vocabulary';
$missing = TCV_Auto_Test::run_suite();
tcv_test( $missing['totals']['fail'] > 0, 'missing table must fail' );
tcv_test( $missing['totals']['skip'] > 1, 'missing table skips its dependent reads' );
$wpdb->missing = '';

$wpdb->missing_column = 'wp_sql_atributos';
$invalid = TCV_Auto_Test::run_suite();
$column_result = array_values( array_filter( $invalid['rows'], static function( $x ) { return $x['id'] === 'columns'; } ) )[0];
tcv_test( $column_result['status'] === 'fail', 'missing column must fail' );
$wpdb->missing_column = '';

ob_start();
TCV_Manual::render();
$manual = ob_get_clean();
foreach ( array( 'Manual de usuario', 'Manual técnico', 'MERGE', 'MIRROR', 'Auto Test', 'TCV_Schema::tables()' ) as $needle ) {
    tcv_test( strpos( $manual, $needle ) !== false, 'manual documents ' . $needle );
}
ob_start();
TCV_Auto_Test::render();
$auto = ob_get_clean();
tcv_test( strpos( $auto, 'Ejecutar Autocheck' ) !== false, 'autotest form displayed' );
tcv_test( strpos( $auto, 'tcv_auto_nonce' ) !== false, 'autotest form includes nonce' );

$admin = file_get_contents( dirname( __DIR__ ) . '/includes/class-tcv-admin.php' );
tcv_test( strpos( $admin, "'auto-test'=>__( 'Autocheck', 'taxocommerce-vocabulary' )" ) !== false && strpos( $admin, "'manual'=>__( 'Manual', 'taxocommerce-vocabulary' )" ) !== false, 'tabs wired and translated' );
tcv_test( strpos( $admin, "'control' === \$tab" ) !== false, 'original control route preserved' );
echo "Vocabulary Auto Test and Manual smoke passed.\n";

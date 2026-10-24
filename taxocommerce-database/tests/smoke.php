<?php
/**
 * Run with PHP CLI 7.4+:
 * php taxocommerce-database/tests/smoke.php
 */
if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'TAXOCOMMERCE_DATABASE_VERSION', '0.1.0' );
class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
class TCDB_Test_WPDB {
    public $prefix = 'wp_';
    public $base_prefix = 'wp_';
    public $blogid = 1;
    public function prepare( $sql, $value ) {
        return sprintf( $sql, "'" . addslashes( (string) $value ) . "'" );
    }
}
$wpdb = new TCDB_Test_WPDB();

require dirname( __DIR__ ) . '/includes/class-tcdb-storage.php';
require dirname( __DIR__ ) . '/includes/class-tcdb-export.php';
require dirname( __DIR__ ) . '/includes/class-tcdb-import.php';
require dirname( __DIR__ ) . '/includes/class-tcdb-cleanup.php';
require dirname( __DIR__ ) . '/includes/class-tcdb-admin.php';

function assert_tcdb( $ok, $message ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}

assert_tcdb( TCDB_Storage::identifier( 'wp_tcd_knowledge' ), 'valid identifiers' );
assert_tcdb( ! TCDB_Storage::identifier( 'wp_posts;DROP_TABLE' ), 'SQL identifier injection blocked' );
assert_tcdb( ! TCDB_Storage::identifier( "wp_posts\`" ), 'quoted SQL identifier blocked' );
assert_tcdb( TCDB_Storage::family_table_name( 'wp_seo_relations' ), 'legacy family tables included' );
assert_tcdb( TCDB_Storage::family_table_name( 'wp_tcd_knowledge' ), 'modern family tables included' );
assert_tcdb( TCDB_Storage::family_table_name( 'wp_taxocommerce_data' ), 'new family tables included' );
assert_tcdb( ! TCDB_Storage::family_table_name( 'wp_posts' ), 'WordPress tables not editable' );
assert_tcdb( ! TCDB_Storage::family_table_name( 'wp_users' ), 'user table not editable' );
assert_tcdb( ! TCDB_Storage::family_table_name( 'wp_2_seo_nodes' ), 'cross-blog table not editable' );
assert_tcdb( ! TCDB_Storage::family_table_name( 'foo_seo_nodes' ), 'foreign prefix forbidden' );

$columns = array(
    array( 'Field' => 'id', 'Extra' => 'auto_increment' ),
    array( 'Field' => 'title', 'Extra' => '' ),
    array( 'Field' => 'computed', 'Extra' => 'VIRTUAL GENERATED' ),
);
assert_tcdb( true === TCDB_Import::validate_column_set( array( 'id', 'title' ), $columns ), 'valid columns' );
assert_tcdb( is_wp_error( TCDB_Import::validate_column_set( array( 'id', 'missing' ), $columns ) ), 'missing column rejected' );
assert_tcdb( is_wp_error( TCDB_Import::validate_column_set( array( 'title', 'title' ), $columns ) ), 'duplicate column rejected' );
assert_tcdb( is_wp_error( TCDB_Import::validate_column_set( array( 'computed' ), $columns ) ), 'generated column rejected' );
assert_tcdb( 'NULL' === TCDB_Export::sql_literal( null ), 'SQL null preserved' );
assert_tcdb( '[oculto: campo sensible]' === TCDB_Admin::safe_display_cell( 'access_token', 'secret' ), 'token hidden in data viewer' );
assert_tcdb( '[oculto: contiene credenciales]' === TCDB_Admin::safe_display_cell( 'context_json', '{"access_token":"secret"}' ), 'nested credential hidden' );
assert_tcdb( 'categoría' === TCDB_Admin::safe_display_cell( 'title', 'categoría' ), 'normal title visible' );

$csv = tempnam( sys_get_temp_dir(), 'tcdb_' );
file_put_contents( $csv, "\xEF\xBB\xBFid,title\n1,\"Taladro, percutor\"\n2,Amoladora\n" );
$parsed = TCDB_Import::read_csv( $csv );
assert_tcdb( ! is_wp_error( $parsed ), 'valid CSV parses' );
assert_tcdb( 2 === count( $parsed['rows'] ), 'rows counted' );
assert_tcdb( 'Taladro, percutor' === $parsed['rows'][0]['title'], 'quotes and delimiters preserved' );
assert_tcdb( 'id' === $parsed['headers'][0], 'UTF-8 BOM stripped' );
file_put_contents( $csv, "id,title\n1,test\n2\n" );
assert_tcdb( is_wp_error( TCDB_Import::read_csv( $csv ) ), 'inconsistent CSV width rejected' );
file_put_contents( $csv, "id,title\n" . str_repeat( "1,example\n", 251 ) );
assert_tcdb( is_wp_error( TCDB_Import::read_csv( $csv ) ), 'CSV max rows enforced' );
file_put_contents( $csv, "id,title\n" . str_repeat( 'x', 9000 ) . ",y\n" );
assert_tcdb( is_wp_error( TCDB_Import::read_csv( $csv ) ), 'cell size bounded' );
unlink( $csv );

$root = dirname( __DIR__ );
$php_files = glob( $root . '/includes/*.php' );
$php_files[] = $root . '/taxocommerce-database.php';
foreach ( $php_files as $path ) {
    $source = file_get_contents( $path );
    assert_tcdb( false === strpos( $source, 'SEO_Data_Rollback::' ), 'legacy rollback engine never loaded' );
    assert_tcdb( false === strpos( $source, 'seo_data_handle_rollback_operation' ), 'Data Table rollback never copied' );
    assert_tcdb( false === strpos( $source, 'seo_clean_db_handle_rollback_action' ), 'Clean DB rollback never copied' );
    assert_tcdb( false === strpos( $source, 'eval(' ), 'no dynamic code execution' );
}
$entry = file_get_contents( $root . '/taxocommerce-database.php' );
assert_tcdb( false === strpos( $entry, "require_once TAXOCOMMERCE_CORE" ), 'Core is optional' );
assert_tcdb( false !== strpos( $entry, "add_submenu_page(" ), 'nested menu with Core' );
assert_tcdb( false !== strpos( $entry, "add_menu_page(" ), 'standalone menu without Core' );
assert_tcdb( false === strpos( $entry, "register_activation_hook" ), 'no new data tables created or deleted' );

echo "TaxoCommerce Database smoke OK: CSV, identifiers, family independence, rollback separation.\n";

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
define( 'TAXOCOMMERCE_DATABASE_VERSION', '0.3.0' );
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
require dirname( __DIR__ ) . '/includes/class-tcdb-maintenance.php';
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
assert_tcdb( ! TCDB_Storage::family_table_name( 'wp_tcdb_operations' ), 'retired journal table excluded from editable family' );
assert_tcdb( ! TCDB_Storage::family_table_name( 'wp_tcdb_changes' ), 'retired journal changes excluded from editable family' );
assert_tcdb( ! TCDB_Storage::family_table_name( 'wp_seo_operations' ), 'legacy operations never considered importable' );
assert_tcdb( ! TCDB_Storage::family_table_name( 'wp_seo_operation_changes' ), 'legacy change journal never considered importable' );
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
    assert_tcdb( false === strpos( $source, 'TCDB_Journal' ), 'no journal loader or database cross-plugin adapter' );
    assert_tcdb( false === strpos( $source, 'tcdb_rollback_supported_tables' ), 'no optional rollback filter' );
    assert_tcdb( false === strpos( $source, 'tcdb_reversible_csv_import' ), 'no cross-plugin CSV contract' );
    assert_tcdb( false === strpos( $source, 'admin_post_tcdb_rollback' ), 'no rollback endpoint' );
    assert_tcdb( false === strpos( $source, 'eval(' ), 'no dynamic code execution' );
}
assert_tcdb( ! file_exists( $root . '/includes/class-tcdb-journal.php' ), 'historical journal implementation removed' );
$entry = file_get_contents( $root . '/taxocommerce-database.php' );
assert_tcdb( false === strpos( $entry, "require_once TAXOCOMMERCE_CORE" ), 'Core is optional' );
assert_tcdb( false !== strpos( $entry, "add_submenu_page(" ), 'nested menu with Core' );
assert_tcdb( false !== strpos( $entry, "add_menu_page(" ), 'standalone menu without Core' );
assert_tcdb( false === strpos( $entry, "register_activation_hook" ), 'plugin no longer creates journal tables' );

$import = file_get_contents( $root . '/includes/class-tcdb-import.php' );
assert_tcdb( false !== strpos( $import, "'START TRANSACTION'" ), 'CSV import starts atomic transaction' );
assert_tcdb( false !== strpos( $import, "'COMMIT'" ), 'CSV import commits on success' );
assert_tcdb( false !== strpos( $import, "'ROLLBACK'" ), 'CSV import aborts failed transaction' );


/**
 * Mock the SQL exporter to cover both paginators without a live database.
 * Use exactly 100 rows on page one and a final row on page two.
 */
define( 'ARRAY_A', 'ARRAY_A' );
class TCDB_Test_Export_WPDB extends TCDB_Test_WPDB {
    public $last_error = '';
    public $queries = array();

    public function prepare( $sql, ...$values ) {
        $i = 0;
        return preg_replace_callback(
            '/%[ids]/',
            static function ( $match ) use ( &$i, $values ) {
                $value = $values[ $i++ ];
                if ( '%i' === $match[0] ) {
                    $tick = chr( 96 );
                    return $tick . str_replace( $tick, $tick . $tick, (string) $value ) . $tick;
                }
                if ( '%d' === $match[0] ) {
                    return (string) (int) $value;
                }
                return "'" . addslashes( (string) $value ) . "'";
            },
            $sql
        );
    }

    public function get_results( $sql, $format = ARRAY_A ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, 'wp_seo_export_test' ) ) {
            return false !== strpos( $sql, ' WHERE ' )
                ? array( array( 'id' => 101, 'title' => 'Final' ) )
                : array_map(
                    static function ( $id ) { return array( 'id' => $id, 'title' => 'Product' ); },
                    range( 1, 100 )
                );
        }
        if ( false !== strpos( $sql, 'wp_tcd_export_nopk' ) ) {
            return false !== strpos( $sql, ' OFFSET 100' )
                ? array( array( 'title' => 'Final' ) )
                : array_fill( 0, 100, array( 'title' => 'Product' ) );
        }
        return array();
    }
}

$export_db = new TCDB_Test_Export_WPDB();
$wpdb = $export_db;
$storage_class = new ReflectionClass( 'TCDB_Storage' );
$table_property = $storage_class->getProperty( 'table_cache' );
$table_property->setAccessible( true );
$table_property->setValue( null, array(
    'wp_seo_export_test' => array( 'family' => true ),
    'wp_tcd_export_nopk' => array( 'family' => true ),
) );
$column_property = $storage_class->getProperty( 'column_cache' );
$column_property->setAccessible( true );
$column_property->setValue( null, array(
    'wp_seo_export_test' => array(
        array( 'Field' => 'id', 'Key' => 'PRI' ),
        array( 'Field' => 'title', 'Key' => '' ),
    ),
    'wp_tcd_export_nopk' => array( array( 'Field' => 'title', 'Key' => '' ) ),
) );

$export_method = new ReflectionMethod( 'TCDB_Export', 'rows' );
$export_method->setAccessible( true );
$exported = array();
$ok = $export_method->invoke( null, 'wp_seo_export_test', static function ( $row, $columns ) use ( &$exported ) {
    $exported[] = $row;
} );
assert_tcdb( true === $ok && 101 === count( $exported ), '100+1 rows exported via primary-key cursor' );
assert_tcdb( 2 === count( $export_db->queries ), 'keyset export does not duplicate or over-query' );
assert_tcdb( false !== strpos( $export_db->queries[0], 'ORDER BY ' . chr(96) . 'id' . chr(96) . ' ASC LIMIT 100' ), 'keyset query prepared with correct sort' );
assert_tcdb( false !== strpos( $export_db->queries[1], ' WHERE ' . chr(96) . 'id' . chr(96) . " > '100'" ), 'keyset cursor is prepared' );

$exported = array();
$export_db->queries = array();
$ok = $export_method->invoke( null, 'wp_tcd_export_nopk', static function ( $row, $columns ) use ( &$exported ) {
    $exported[] = $row;
} );
assert_tcdb( true === $ok && 101 === count( $exported ), '100+1 rows exported without a primary key' );
assert_tcdb( 2 === count( $export_db->queries ), 'non-PK export uses two bounded queries' );
assert_tcdb( false !== strpos( $export_db->queries[1], 'LIMIT 100 OFFSET 100' ), 'non-PK offset prepared' );

$export_db->queries = array();
assert_tcdb( false === $export_method->invoke( null, 'wp_users', static function ( $row ) {} ), 'unapproved export table rejected' );
assert_tcdb( ! $export_db->queries, 'unapproved export table never queried' );

$storage_source = file_get_contents( $root . '/includes/class-tcdb-storage.php' );
$export_source = file_get_contents( $root . '/includes/class-tcdb-export.php' );
assert_tcdb( false !== strpos( $storage_source, "'SHOW COLUMNS FROM %i'" ), 'schema column query prepared' );
assert_tcdb( false !== strpos( $export_source, "'SHOW CREATE TABLE %i'" ), 'DDL query prepared' );

$legacy_clean = file_get_contents( $root . '/includes/legacy/seo-database-clean.php' );
assert_tcdb( false !== strpos( $legacy_clean, 'namespace TCDB_Legacy;' ), 'legacy diagnostics isolated from monolith' );
assert_tcdb( false !== strpos( $legacy_clean, "'data_errors' => 'Errores en datos'" ), 'full SEO integrity screen retained' );
assert_tcdb( false !== strpos( $legacy_clean, "'database_cleanup' => 'Limpieza BBDD'" ), 'full WordPress/WooCommerce cleanup retained' );
assert_tcdb( false !== strpos( $legacy_clean, "'export' => 'Exportar BBDD'" ), 'original backup tab retained' );
assert_tcdb( false !== strpos( $legacy_clean, "case 'clean_wc_orphan_order_itemmeta':" ), 'all old cleanup actions retained' );
assert_tcdb( false === strpos( $legacy_clean, 'SEO_Data_Layer' ), 'no legacy cross-plugin Data Layer dependency' );
assert_tcdb( false === strpos( $legacy_clean, 'SEO_Data_Rollback' ), 'no historical rollback dependency' );
assert_tcdb( false === strpos( $legacy_clean, 'Escribe LIMPIAR' ), 'no typed cleanup words' );
assert_tcdb( false !== strpos( $legacy_clean, 'array_chunk($ids, 500)' ), 'SQL chunks are not total deletion limits' );
assert_tcdb( false !== strpos( $entry, 'includes/legacy/seo-database-clean.php' ), 'new module loads original complete cleanup' );

echo "TaxoCommerce Database smoke OK: MySQL, CSV atomicity, prepared export pagination and family independence.\n";

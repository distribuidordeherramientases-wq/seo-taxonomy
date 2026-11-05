<?php
/**
 * Offline regression: schema installation must not seed commercial knowledge.
 *
 * Run: php taxocommerce-dependiente/tests/clean-install-smoke.php
 * The WordPress/SQL stubs deliberately forbid data writes, but allow schema DDL.
 */

$tmp = sys_get_temp_dir() . '/tcd-blank-install-' . bin2hex(random_bytes(6));
$upgrade_dir = $tmp . '/wp-admin/includes';
if ( ! mkdir( $upgrade_dir, 0700, true ) ) {
    fwrite( STDERR, "FAIL: cannot create isolated WordPress stub\n" );
    exit( 1 );
}
file_put_contents( $upgrade_dir . '/upgrade.php', '<?php function dbDelta( $sql ) { $GLOBALS["tcd_ddl"][] = $sql; return array(); }' );

define( 'ABSPATH', $tmp . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['tcd_ddl'] = array();
$GLOBALS['tcd_schema_options'] = array();
$GLOBALS['tcd_data_writes'] = array();
$GLOBALS['tcd_local_records'] = array();
function esc_sql( $value ) { return (string) $value; }
function update_option( $name, $value, $autoload = null ) {
    $GLOBALS['tcd_schema_options'][ $name ] = $value;
    return true;
}
class TCD_Blank_Install_DB {
    public $prefix = 'local_';
    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
    public function esc_like( $value ) { return (string) $value; }
    public function prepare( $sql, ...$args ) { return $sql . ' ' . implode( ' ', $args ); }
    public function get_var( $sql ) { return null; } // Vocabulary is optional on a clean site.
    public function insert( $table, $data ) {
        $GLOBALS['tcd_data_writes'][] = array( 'insert', $table, $data );
        return false;
    }
    public function update( $table, $data, $where ) {
        $GLOBALS['tcd_data_writes'][] = array( 'update', $table, $data );
        return false;
    }
    public function delete( $table, $where ) {
        $GLOBALS['tcd_data_writes'][] = array( 'delete', $table, $where );
        return false;
    }
}
$GLOBALS['wpdb'] = new TCD_Blank_Install_DB();
function tcd_blank_assert( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

$root = dirname( __DIR__ );
try {
    require_once $root . '/includes/interpreter/class-tcd-interpreter-lexicon.php';
    $source = file_get_contents( $root . '/includes/interpreter/class-tcd-interpreter-lexicon.php' );
    tcd_blank_assert( false === strpos( $source, 'seed_i1_subject_action' ), 'Legacy tool seed remains in shipped code' );
    tcd_blank_assert( false === strpos( $source, "'interpreter_seed'" ), 'Commercial seed still present' );

    TCD_Original_Interprete_DB::install();
    tcd_blank_assert( 2 === count( $GLOBALS['tcd_ddl'] ), 'Installer must reconcile exactly lexicon and evidence schemas' );
    tcd_blank_assert( false !== strpos( $GLOBALS['tcd_ddl'][0], 'CREATE TABLE local_seo_interprete_lexicon (' ), 'Lexicon schema missing' );
    tcd_blank_assert( false !== strpos( $GLOBALS['tcd_ddl'][1], 'CREATE TABLE local_seo_interprete_lexicon_evidence (' ), 'Evidence schema missing' );
    tcd_blank_assert( array() === $GLOBALS['tcd_data_writes'], 'Installer inserted or modified business data' );
    tcd_blank_assert( array() === $GLOBALS['tcd_local_records'], 'Fresh install must start with zero stored knowledge' );
    tcd_blank_assert(
        TCD_Original_Interprete_DB::SCHEMA_VERSION === ( $GLOBALS['tcd_schema_options'][ TCD_Original_Interprete_DB::SCHEMA_OPTION ] ?? '' ),
        'Schema version must be recorded'
    );

    // An upgrade or explicit Linguist setup must not reset a customer's learning.
    $GLOBALS['tcd_local_records'] = array(
        array( 'expression' => 'consulta original del cliente', 'source' => 'product', 'validated' => 1 ),
    );
    $previous = $GLOBALS['tcd_local_records'];
    TCD_Original_Interprete_DB::install();
    tcd_blank_assert( $previous === $GLOBALS['tcd_local_records'], 'Schema reconciliation must preserve customer learning' );
    tcd_blank_assert( array() === $GLOBALS['tcd_data_writes'], 'Reinstall must not seed or alter knowledge' );

    // The Linguist must still learn local terms from products/categories when started.
    $linguist = file_get_contents( $root . '/includes/interpreter/class-tcd-linguist.php' );
    tcd_blank_assert( false !== strpos( $linguist, "post_type='product'" ), 'Local product source missing' );
    tcd_blank_assert( false !== strpos( $linguist, "'product_cat'" ), 'Local category source missing' );
    tcd_blank_assert( false !== strpos( $linguist, "'product_tag'" ), 'Local tag source missing' );
    tcd_blank_assert( false !== strpos( $linguist, "TCD_Original_Interprete_DB::add_evidence(" ), 'Source evidence missing' );

    echo "PASS: isolated clean installation, zero business seeds, safe schema reconciliation, local product learning retained.\n";
} catch ( Throwable $error ) {
    fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
    exit( 1 );
} finally {
    @unlink( $upgrade_dir . '/upgrade.php' );
    @rmdir( $upgrade_dir );
    @rmdir( dirname( $upgrade_dir ) );
    @rmdir( $tmp );
}

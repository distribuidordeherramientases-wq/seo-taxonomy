<?php
/**
 * Verify the original standalone SEO Data Table migration without WordPress.
 * Intended for PHP 7.4+.
 */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ );
$GLOBALS['tcdb_hooks'] = array();
function add_action( $hook, $callback ) { $GLOBALS['tcdb_hooks'][$hook] = $callback; }
function tcdb_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . "\n" ); exit( 1 ); }
}
class TCDB_Fake_DB {
    public $prefix = 'wp_';
    public function esc_like( $text ) { return str_replace( '_', '\\_', $text ); }
    public function prepare( $sql, $arg ) { return str_replace( '%s', "'" . addslashes( $arg ) . "'", $sql ); }
    public function get_col( $sql ) { return array( 'wp_seo_nodes', 'wp_seo_relations' ); }
    public function get_row( $sql, $format = null ) {
        return array( 'TABLE_ROWS' => 50, 'DATA_LENGTH' => 256, 'INDEX_LENGTH' => 128, 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci' );
    }
}
define( 'ARRAY_A', 'ARRAY_A' );
$GLOBALS['wpdb'] = new TCDB_Fake_DB();
require dirname( __DIR__ ) . '/includes/legacy/seo-database-report.php';
$src = file_get_contents( dirname( __DIR__ ) . '/includes/legacy/seo-database-report.php' );
foreach ( array(
    'seo_data_table_page', 'seo_data_get_tables', 'seo_data_render_overview',
    'seo_data_render_explorer', 'seo_data_render_export',
    'seo_data_export_table_csv', 'seo_data_export_table_sql',
    'seo_data_export_all', 'seo_data_write_table_csv', 'seo_data_write_table_sql',
) as $name ) {
    tcdb_assert( function_exists( 'TCDB_Legacy\\' . $name ), 'Original function retained: ' . $name );
}
tcdb_assert( ! function_exists( 'TCDB_Legacy\\seo_data_handle_rollback_operation' ), 'Rollback excluded' );
tcdb_assert( false === strpos( $src, 'SEO_Data_Rollback' ), 'No rollback class dependency' );
tcdb_assert( false === strpos( $src, 'SEO_Data_Layer' ), 'No Data Layer class dependency' );
tcdb_assert( false !== strpos( $src, "'csv' => 'Todas en CSV'" ), 'Original ZIP CSV option' );
tcdb_assert( false !== strpos( $src, "'sql' => 'Todas en SQL'" ), 'Original ZIP SQL option' );
tcdb_assert( false !== strpos( $src, "'both' => 'CSV + SQL'" ), 'Original ZIP combined option' );
tcdb_assert( false !== strpos( $src, "'per_page', 100" ), 'Original paginated explorer' );
tcdb_assert( false !== strpos( $src, "filter_column" ), 'Original column filters' );
$tables = \TCDB_Legacy\seo_data_get_tables();
tcdb_assert( 2 === count( $tables ), 'Discovers SEO tables dynamically' );
tcdb_assert( 'Nodos SEO' === $tables['wp_seo_nodes']['label'], 'Original table labels' );
foreach ( array(
    'admin_post_tcdb_legacy_data_export_table_csv',
    'admin_post_tcdb_legacy_data_export_table_sql',
    'admin_post_tcdb_legacy_data_export_all',
) as $hook ) {
    tcdb_assert( isset( $GLOBALS['tcdb_hooks'][$hook] ), 'Original export hook mapped: ' . $hook );
}
tcdb_assert( 3 === count( $GLOBALS['tcdb_hooks'] ), 'No duplicate or rollback hooks' );
echo "SEO Data Table legacy tests OK: original inventory, explorer and CSV/SQL/ZIP export; no Data Layer.\n";

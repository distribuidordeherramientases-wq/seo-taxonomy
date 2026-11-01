<?php
/**
 * Run standalone and Core-integrated menu registration in separate processes:
 * php taxocommerce-database/tests/menu-smoke.php alone
 * php taxocommerce-database/tests/menu-smoke.php core
 */
if ( PHP_SAPI !== 'cli' ) {
    exit( 1 );
}
define( 'ABSPATH', __DIR__ );
function __( $message, $domain = '' ) { return $message; }
function plugin_dir_path( $path ) { return dirname( $path ) . '/'; }
function plugin_dir_url( $path ) { return 'https://example.test/wp-content/plugins/taxocommerce-database/'; }
function add_action( $event, $callback, $priority = 10 ) { return true; }
function register_activation_hook( $file, $callback ) { return true; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
$GLOBALS['tcdb_menu_calls'] = array();
function add_menu_page( $title, $label, $capability, $slug, $callback, $icon = '', $position = null ) {
    $GLOBALS['tcdb_menu_calls'][] = array( 'menu', $slug, $label );
}
function add_submenu_page( $parent, $title, $label, $capability, $slug, $callback ) {
    $GLOBALS['tcdb_menu_calls'][] = array( 'submenu', $parent, $slug, $label );
}
if ( isset( $argv[1] ) && 'core' === $argv[1] ) {
    function taxocommerce_core() { return new stdClass(); }
}
require dirname( __DIR__ ) . '/taxocommerce-database.php';
TaxoCommerce_Database::menu();
$calls = $GLOBALS['tcdb_menu_calls'];
$expected = function_exists( 'taxocommerce_core' )
    ? array( 'submenu', 'taxocommerce-core', 'taxocommerce-database', 'Database' )
    : array( 'menu', 'taxocommerce-database', 'TaxoCommerce DB' );
if ( 1 !== count( $calls ) || $calls[0] !== $expected ) {
    fwrite( STDERR, 'FAIL: wrong standalone/Core menu registration: ' . json_encode( $calls ) . "\n" );
    exit( 1 );
}
class TCDB_Fake_Core {
    public $registered = array();
    public function register_module( $key, $settings ) { $this->registered[ $key ] = $settings; }
}
$core = new TCDB_Fake_Core();
TaxoCommerce_Database::register_core( $core );
if ( ! isset( $core->registered['database'] ) ) {
    fwrite( STDERR, "FAIL: Core module registration missing\n" );
    exit( 1 );
}

$source = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-database.php' );
$clean_source = file_get_contents( dirname( __DIR__ ) . '/includes/legacy/seo-database-clean.php' );
$table_source = file_get_contents( dirname( __DIR__ ) . '/includes/legacy/seo-database-report.php' );
foreach ( array( 'Errores en datos', 'Limpieza BBDD', 'Exportar BBDD', 'Resumen tablas', 'Explorar tablas', 'Exportar tablas', 'Manual' ) as $tab_label ) {
    if ( false === strpos( $source, $tab_label ) ) {
        fwrite( STDERR, "FAIL: missing flat tab: " . $tab_label . "\n" ); exit( 1 );
    }
}
if ( false === strpos( $source, 'tcdb-flat-tabs' ) || false === strpos( $source, 'overflow-x:auto' ) ) {
    fwrite( STDERR, "FAIL: horizontal scroll tabs missing\n" ); exit( 1 );
}
if ( false !== strpos( $clean_source, '    seo_clean_db_render_tabs($active_tab);' )
    || false !== strpos( $table_source, '    echo \'<nav class="nav-tab-wrapper" style="margin-bottom:20px;">\';' ) ) {
    fwrite( STDERR, "FAIL: old nested tabs still rendered\n" ); exit( 1 );
}
if ( false === strpos( $source, "seo_clean_db_tab" ) || false === strpos( $source, "section" )
    || false === strpos( $source, "tcdb_screen" ) ) {
    fwrite( STDERR, "FAIL: legacy screen URL parameters missing\n" ); exit( 1 );
}
if ( false === strpos( $source, 'TCDB_Manual::render()' ) ) { fwrite( STDERR, "FAIL: manual routing missing\n" ); exit( 1 ); }
$manual_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-tcdb-manual.php' );
foreach ( array( 'Manual de usuario final', 'Manual técnico para desarrolladores', 'Eliminar revisiones', 'Eliminar meta huérfano', 'Descargar CSV', 'ZIP', 'Data Layer', 'manage_options' ) as $required ) {
    if ( false === strpos( $manual_src, $required ) ) { fwrite( STDERR, 'FAIL manual: ' . $required . "\n" ); exit( 1 ); }
}
echo "Database menu OK (" . ( function_exists( 'taxocommerce_core' ) ? 'Core' : 'standalone' ) . ")\n";

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
function plugin_dir_path( $path ) { return dirname( $path ) . '/'; }
function plugin_dir_url( $path ) { return 'https://example.test/wp-content/plugins/taxocommerce-database/'; }
function add_action( $event, $callback, $priority = 10 ) { return true; }
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
    ? array( 'submenu', 'taxocommerce-core', 'taxocommerce-database', 'Base de datos' )
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
echo "Database menu OK (" . ( function_exists( 'taxocommerce_core' ) ? 'Core' : 'standalone' ) . ")\n";

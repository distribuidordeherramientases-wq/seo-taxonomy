<?php
/**
 * Standalone, deliberately minimal boot test for CI.
 * WordPress is not loaded; the ABSPATH guard must prevent registration.
 */
$source = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-tasks.php' );
$required = array( 'defined( \'ABSPATH\' ) || exit;', 'dbDelta(', 'admin_post_tctasks_save_task', 'admin_post_tctasks_delete_task', 'admin_post_tctasks_save_category', 'admin_post_tctasks_delete_category', 'admin_post_tctasks_autocheck_download', 'check_admin_referer', 'current_user_can', 'wp_json_encode', "'executed_at'", "'rows'", "'totals'" );
foreach ( $required as $needle ) {
    if ( false === strpos( $source, $needle ) ) {
        fwrite( STDERR, "FAIL: $needle\n" );
        exit( 1 );
    }
}
if ( preg_match( '/register_post_type|register_taxonomy|add_shortcode|register_rest_route/', $source ) ) {
    fwrite( STDERR, "FAIL: public or post-based storage detected\n" );
    exit( 1 );
}
echo "PASS: standalone agenda boot/static safety contract.\n";


/**
 * Execute the navigation callbacks without a WordPress installation.
 * Verify both real registration branches: Core absent and Core present.
 */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
function register_activation_hook( $file, $callback ) {}
function add_action( $hook, $callback, $priority = 10 ) {}
function add_filter( $hook, $callback, $priority = 10 ) {}
function determine_locale() { return 'es_ES'; }
function __( $text, $domain ) { return $text; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function add_menu_page( ...$args ) { $GLOBALS['tctasks_menus'][] = $args; }
function add_submenu_page( ...$args ) { $GLOBALS['tctasks_submenus'][] = $args; }

require_once dirname( __DIR__ ) . '/taxocommerce-tasks.php';

$GLOBALS['tctasks_menus']    = array();
$GLOBALS['tctasks_submenus'] = array();
tctasks_admin_menu();
if (
    1 !== count( $GLOBALS['tctasks_menus'] ) ||
    ! empty( $GLOBALS['tctasks_submenus'] ) ||
    'taxocommerce-tasks' !== $GLOBALS['tctasks_menus'][0][3] ||
    'tctasks_admin' !== $GLOBALS['tctasks_menus'][0][4]
) {
    fwrite( STDERR, "FAIL: standalone menu registration was changed\n" );
    exit( 1 );
}

// Real Core provides this constant before registering the admin menu.
define( 'TAXOCOMMERCE_CORE_VERSION', '0.4.0' );
$GLOBALS['tctasks_menus']    = array();
$GLOBALS['tctasks_submenus'] = array();
tctasks_admin_menu();
if (
    ! empty( $GLOBALS['tctasks_menus'] ) ||
    1 !== count( $GLOBALS['tctasks_submenus'] ) ||
    'taxocommerce-core' !== $GLOBALS['tctasks_submenus'][0][0] ||
    'taxocommerce-tasks' !== $GLOBALS['tctasks_submenus'][0][4] ||
    'tctasks_admin' !== $GLOBALS['tctasks_submenus'][0][5]
) {
    fwrite( STDERR, "FAIL: Tasks must be nested under Core without changing its route\n" );
    exit( 1 );
}

$mock_core = new class() {
    public $calls = array();
    public function register_module( $id, $data ) { $this->calls[ $id ] = $data; }
};
tctasks_register_core_module( $mock_core );
if (
    ! isset( $mock_core->calls['tasks'] ) ||
    TAXOCOMMERCE_TASKS_VERSION !== $mock_core->calls['tasks']['version'] ||
    false === strpos( $mock_core->calls['tasks']['admin_url'], 'page=taxocommerce-tasks' )
) {
    fwrite( STDERR, "FAIL: Core module registration was not preserved\n" );
    exit( 1 );
}
$extensions = tctasks_register_family_extension( array( 'existing' => array( 'id' => 'existing' ) ) );
if (
    ! isset( $extensions['existing'], $extensions['tasks'] ) ||
    'taxocommerce-tasks/taxocommerce-tasks.php' !== $extensions['tasks']['plugin_file']
) {
    fwrite( STDERR, "FAIL: family extension catalog registration failed\n" );
    exit( 1 );
}
$catalog_prefix = dirname( __DIR__ ) . '/languages/taxocommerce-tasks-';
foreach ( array( 'es_ES', 'en_US', 'fr_FR', 'de_DE', 'nl_NL' ) as $locale ) {
    $po = file_get_contents( $catalog_prefix . $locale . '.po' );
    if ( ! is_string( $po ) || 72 !== preg_match_all( '/^msgid /m', $po ) || 1 !== preg_match_all( '/^msgstr ""$/m', $po ) ) {
        fwrite( STDERR, "FAIL: incomplete Tasks PO $locale\\n" );
        exit( 1 );
    }
    $raw = file_get_contents( $catalog_prefix . $locale . '.mo', false, null, 0, 28 );
    if ( ! is_string( $raw ) || 28 !== strlen( $raw ) ) {
        fwrite( STDERR, "FAIL: missing Tasks MO $locale\\n" );
        exit( 1 );
    }
    $header = unpack( 'Vmagic/Vrevision/Vcount', $raw );
    if ( 0x950412de !== $header['magic'] || 72 !== $header['count'] ) {
        fwrite( STDERR, "FAIL: invalid Tasks MO $locale\\n" );
        exit( 1 );
    }
}
echo "PASS: standalone and Core menu routes; optional family module and extension catalog.\n";

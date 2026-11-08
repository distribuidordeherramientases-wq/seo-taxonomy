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
function determine_locale() { return $GLOBALS['tctasks_test_locale'] ?? 'es_ES'; }
function __( $text, $domain ) { return $text; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
// The standard family header registers an admin-post action at plugin load.
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
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
echo "PASS: standalone and Core menu routes; optional family module and extension catalog.\n";

/**
 * Runtime safety checks for category renaming and both embedded manuals.
 * These tests use an in-memory implementation of the two private tables:
 * no real WordPress, database, network or customer data is modified.
 */
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function submit_button( $label, $type = 'primary', $name = 'submit', $wrap = true ) {
    echo '<button type="submit">' . esc_html( $label ) . '</button>';
}
function add_query_arg( $params, $url ) {
    return $url . ( false !== strpos( $url, '?' ) ? '&' : '?' ) . http_build_query( $params );
}

/** Simulate unique category constraint and preservation of task foreign keys. */
class TCTasks_Test_DB {
    public $prefix = 'wp_';
    public $categories = array( 1 => 'General', 2 => 'Compras' );
    public $tasks = array( array( 'id' => 15, 'category_id' => 1 ) );
    public $last_error = '';

    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function get_var( $query ) {
        $id = isset( $query[1][1] ) ? (int) $query[1][1] : 0;
        return isset( $this->categories[ $id ] ) ? $id : null;
    }
    public function get_results( $query ) {
        $rows = array();
        foreach ( $this->categories as $id => $name ) {
            $rows[] = (object) array( 'id' => $id, 'name' => $name );
        }
        return $rows;
    }
    public function update( $table, $data, $where, $format, $where_format ) {
        $id = (int) $where['id'];
        if ( ! isset( $this->categories[ $id ] ) ) {
            return false;
        }
        foreach ( $this->categories as $existing_id => $name ) {
            if ( $existing_id !== $id && $name === $data['name'] ) {
                return false;
            }
        }
        $old = $this->categories[ $id ];
        $this->categories[ $id ] = $data['name'];
        return $old === $data['name'] ? 0 : 1;
    }
    public function insert( $table, $data, $format ) {
        if ( in_array( $data['name'], $this->categories, true ) ) {
            return false;
        }
        $this->categories[ max( array_keys( $this->categories ) ) + 1 ] = $data['name'];
        return 1;
    }
}
$wpdb = new TCTasks_Test_DB();

if (
    'category-updated' !== tctasks_upsert_category( 1, 'Organización' ) ||
    1 !== $wpdb->tasks[0]['category_id'] ||
    'Organización' !== $wpdb->categories[1]
) {
    fwrite( STDERR, "FAIL: category rename changed task references\n" );
    exit( 1 );
}
if (
    'category-updated' !== tctasks_upsert_category( 1, 'Organización' ) ||
    'category-error' !== tctasks_upsert_category( 1, 'Compras' ) ||
    'category-error' !== tctasks_upsert_category( 0, 'Compras' ) ||
    'category-missing' !== tctasks_upsert_category( 999, 'Desconocida' ) ||
    'category-saved' !== tctasks_upsert_category( 0, 'Urgentes' ) ||
    3 !== count( $wpdb->categories )
) {
    fwrite( STDERR, "FAIL: category create/update/duplicate/missing-ID contract\n" );
    exit( 1 );
}
$_GET['edit_category'] = '1';
ob_start();
tctasks_view_categories();
$html = ob_get_clean();
if (
    false === strpos( $html, 'name="id" value="1"' ) ||
    false === strpos( $html, 'value="Organización"' ) ||
    false === strpos( $html, 'edit_category=1' ) ||
    false === strpos( $html, 'Cancelar edición' )
) {
    fwrite( STDERR, "FAIL: category editing form and cancel link\n" );
    exit( 1 );
}
unset( $_GET['edit_category'] );
ob_start();
tctasks_view_categories();
$new_html = ob_get_clean();
if ( false === strpos( $new_html, 'name="id" value="0"' ) ) {
    fwrite( STDERR, "FAIL: new category form leaked the last edited ID\n" );
    exit( 1 );
}

foreach ( array( 'es_ES', 'en_US', 'fr_FR', 'de_DE', 'nl_NL' ) as $locale ) {
    $GLOBALS['tctasks_test_locale'] = $locale;
    foreach ( array( 'user' => 6, 'technical' => 7 ) as $kind => $count ) {
        $sections = TCTasks_Manuals::sections( $kind );
        if ( count( $sections ) !== $count ) {
            fwrite( STDERR, "FAIL: missing manual sections for " . $locale . " / " . $kind . "\n" );
            exit( 1 );
        }
        foreach ( $sections as $section ) {
            if ( empty( $section['title'] ) || empty( $section['details'] ) ) {
                fwrite( STDERR, "FAIL: empty manual section for " . $locale . "\n" );
                exit( 1 );
            }
        }
        ob_start();
        TCTasks_Manuals::render( $kind );
        $output = ob_get_clean();
        if ( false === strpos( $output, '<section' ) ) {
            fwrite( STDERR, "FAIL: manual not rendered for " . $locale . "\n" );
            exit( 1 );
        }
    }
}
echo "PASS: category create/rename/duplicate rules and manuals in five locales.\n";

// Check the five gettext packages as part of the standalone smoke test.
$catalog_prefix = dirname( __DIR__ ) . '/languages/taxocommerce-tasks-';
foreach ( array( 'es_ES', 'en_US', 'fr_FR', 'de_DE', 'nl_NL' ) as $locale ) {
    $po = file_get_contents( $catalog_prefix . $locale . '.po' );
    if ( ! is_string( $po ) || 133 !== preg_match_all( '/^msgid /m', $po ) || 1 !== preg_match_all( '/^msgstr ""$/m', $po ) ) {
        fwrite( STDERR, "FAIL: incomplete Tasks PO $locale\\n" );
        exit( 1 );
    }
    foreach ( array( 'User manual', 'Technical manual', 'New category', 'Edit category', 'Access and privacy', 'Requirements and independence' ) as $required_label ) {
        if ( false === strpos( $po, 'msgid "' . $required_label . '"' ) ) {
            fwrite( STDERR, "FAIL: untranslated manual/category source string in $locale: $required_label\\n" );
            exit( 1 );
        }
    }
    $raw = file_get_contents( $catalog_prefix . $locale . '.mo', false, null, 0, 28 );
    if ( ! is_string( $raw ) || 28 !== strlen( $raw ) ) {
        fwrite( STDERR, "FAIL: missing Tasks MO $locale\\n" );
        exit( 1 );
    }
    $header = unpack( 'Vmagic/Vrevision/Vcount', $raw );
    if ( 0x950412de !== $header['magic'] || 133 !== $header['count'] ) {
        fwrite( STDERR, "FAIL: invalid Tasks MO $locale\\n" );
        exit( 1 );
    }
}
$pot = file_get_contents( dirname( __DIR__ ) . '/languages/taxocommerce-tasks.pot' );
if ( ! is_string( $pot ) || 133 !== preg_match_all( '/^msgid /m', $pot ) ) {
    fwrite( STDERR, "FAIL: incomplete POT source catalog\\n" );
    exit( 1 );
}
$manual_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-tctasks-manuals.php' );
if ( ! is_string( $manual_source ) || false === strpos( $manual_source, "__( 'Access and privacy', 'taxocommerce-tasks' )" ) ) {
    fwrite( STDERR, "FAIL: manuals must use literal WordPress gettext strings\\n" );
    exit( 1 );
}
echo "PASS: complete POT, PO and MO catalogs in ES/EN/FR/DE/NL.\n";

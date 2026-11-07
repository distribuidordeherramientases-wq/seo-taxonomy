<?php
/**
 * Standalone admin branding regression tests: no WordPress runtime required.
 * Example: php .github/tests/family-admin-header-smoke.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
$tc_test_actions = array();
$tc_test_filters = array();
$tc_test_styles = array();

function add_action( $hook, $callback, $priority = 10 ) {
    global $tc_test_actions;
    $tc_test_actions[ $hook ][] = $callback;
    return true;
}
function add_filter( $hook, $callback, $priority = 10 ) {
    global $tc_test_filters;
    $tc_test_filters[ $hook ][] = $callback;
    return true;
}
function sanitize_key( $value ) {
    return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
}
function wp_unslash( $value ) { return $value; }
function __( $text, $domain = '' ) { return $text; }
function translate( $text, $domain = 'default' ) { return $text; }
function esc_html( $value ) {
    return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}
function esc_html_e( $value, $domain = '' ) {
    echo esc_html( $value );
}
function wp_register_style( $handle, $src, $deps, $version ) {
    global $tc_test_styles;
    $tc_test_styles[ $handle ] = array( 'src' => $src, 'version' => $version, 'css' => '' );
}
function wp_enqueue_style( $handle ) {
    global $tc_test_styles;
    if ( ! isset( $tc_test_styles[ $handle ] ) ) {
        throw new RuntimeException( 'Nonregistered stylesheet: ' . $handle );
    }
}
function wp_add_inline_style( $handle, $css ) {
    global $tc_test_styles;
    $tc_test_styles[ $handle ]['css'] = $css;
}
function get_file_data( $file, $fields ) {
    $source = file_get_contents( $file );
    $result = array();
    foreach ( $fields as $field => $name ) {
        $value = '';
        if ( preg_match( '/^\s*\*\s*' . preg_quote( $name, '/' ) . ':\s*(.+)$/mi', $source, $matches ) ) {
            $value = trim( $matches[1] );
        }
        $result[ $field ] = $value;
    }
    return $result;
}

function tc_test_assert( $passed, $message ) {
    if ( ! $passed ) {
        fwrite( STDERR, "FAIL: " . $message . PHP_EOL );
        exit( 1 );
    }
}

$slugs = array(
    'auditor', 'redirects', 'faqs', 'images', 'social', 'database',
    'campaigns', 'suppliers', 'scrappers', 'logistica', 'vocabulary',
    'facturacion', 'dependiente', 'google-bing', 'distribution',
    'import-export', 'system-check', 'templates',
);
$root = dirname( __DIR__, 2 );
foreach ( $slugs as $slug ) {
    $path = $root . '/taxocommerce-' . $slug . '/includes/admin-branding.php';
    tc_test_assert( is_file( $path ), 'Missing self-contained header: ' . $slug );
    require $path;
}
tc_test_assert( count( $tc_test_actions['admin_notices'] ?? array() ) === count( $slugs ), 'One header registration per standalone plugin.' );
tc_test_assert( count( $tc_test_actions['admin_enqueue_scripts'] ?? array() ) === count( $slugs ), 'One style registration per standalone plugin.' );
foreach ( $slugs as $slug ) {
    $_GET['page'] = 'taxocommerce-' . $slug;
    ob_start();
    foreach ( $tc_test_actions['admin_notices'] as $callback ) {
        $callback();
    }
    $html = ob_get_clean();
    tc_test_assert( 1 === substr_count( $html, '<header class="tc-family-hero">' ), 'Exactly one hero on ' . $slug );
    tc_test_assert( 1 === substr_count( $html, 'tc-family-hero__version' ), 'Exactly one version on ' . $slug );
    tc_test_assert( 1 === substr_count( $html, 'tc-family-hero__updated' ), 'Exactly one release date on ' . $slug );
    tc_test_assert( strpos( $html, 'tc-family-hero__copy' ) !== false, 'Title and description on ' . $slug );
    tc_test_assert( strpos( $html, 'dashicons-' ) !== false, 'Native WordPress icon on ' . $slug );
    $version = get_file_data( $root . '/taxocommerce-' . $slug . '/taxocommerce-' . $slug . '.php', array( 'version' => 'Version', 'released' => 'Release Date' ) );
    tc_test_assert( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $version['released'] ), 'Valid release date on ' . $slug );
    tc_test_assert( false !== strpos( $html, $version['released'] ), 'Visible release date matches plugin header on ' . $slug );
    tc_test_assert( false !== strpos( $html, $version['version'] ), 'Visible version follows current plugin header: ' . $slug );
    $class = 'wp-admin';
    foreach ( $tc_test_filters['admin_body_class'] as $callback ) {
        $class = $callback( $class );
    }
    tc_test_assert( 1 === substr_count( $class, 'tc-family-screen' ), 'Only owning plugin marks admin body on ' . $slug );
    $tc_test_styles = array();
    foreach ( $tc_test_actions['admin_enqueue_scripts'] as $callback ) {
        $callback();
    }
    tc_test_assert( 1 === count( $tc_test_styles ), 'No cross-plugin styles enqueued on ' . $slug );
    $styles = reset( $tc_test_styles );
    tc_test_assert( false !== strpos( $styles['css'], '@media(max-width:600px)' ), 'Responsive header on ' . $slug );
    tc_test_assert( false !== strpos( $styles['css'], '.nav-tab-wrapper' ), 'Existing tabs retained on ' . $slug );
}
unset( $_GET['page'] );
ob_start();
foreach ( $tc_test_actions['admin_notices'] as $callback ) {
    $callback();
}
tc_test_assert( '' === ob_get_clean(), 'No branding outside plugin admin pages.' );
// Core, Tasks and the compatibility bridge have independent admin rendering.
foreach ( array( 'core', 'tasks', 'connections' ) as $other ) {
    $source = $root . '/taxocommerce-' . $other . '/taxocommerce-' . $other . '.php';
    $data = get_file_data( $source, array( 'version' => 'Version', 'released' => 'Release Date' ) );
    tc_test_assert( 1 === preg_match( '/^\\d+\\.\\d+\\.\\d+$/', $data['version'] ), 'Valid plugin version: ' . $other );
    tc_test_assert( '2026-11-07' === $data['released'], 'Release date present: ' . $other );
    $readme = file_get_contents( $root . '/taxocommerce-' . $other . '/readme.txt' );
    tc_test_assert( false !== strpos( $readme, 'Stable tag: ' . $data['version'] ), 'Stable tag current: ' . $other );
    $main = file_get_contents( $source );
    tc_test_assert( false !== strpos( $main, 'TAXOCOMMERCE_' . strtoupper( $other ) . '_RELEASE_DATE' ), 'Release-date constant: ' . $other );
}
$core_ui = file_get_contents( $root . '/taxocommerce-core/includes/class-taxocommerce-core-dashboard.php' );
$tasks_ui = file_get_contents( $root . '/taxocommerce-tasks/taxocommerce-tasks.php' );
$bridge_ui = file_get_contents( $root . '/taxocommerce-connections/includes/class-taxocommerce-connections-admin.php' );
tc_test_assert( false !== strpos( $core_ui, 'tcc-hero__metadata' ) && false !== strpos( $core_ui, 'TAXOCOMMERCE_CORE_RELEASE_DATE' ), 'Core hero version and date' );
tc_test_assert( false !== strpos( $tasks_ui, 'TAXOCOMMERCE_TASKS_RELEASE_DATE' ), 'Tasks header uses the release date' );
tc_test_assert( false !== strpos( $bridge_ui, 'TAXOCOMMERCE_CONNECTIONS_RELEASE_DATE' ), 'Connections fallback header uses the release date' );
echo "PASS: 18 independent family heroes + Core, Tasks, Connections title/version/release-date contracts." . PHP_EOL;

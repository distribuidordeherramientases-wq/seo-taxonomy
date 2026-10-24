<?php
/**
 * Regression for the TaxoCommerce Core extensions row: active Redirects must
 * show the same "Abrir" action as every other active module.
 *
 * php taxocommerce-redirects/tests/core-extension-menu-smoke.php standalone
 * php taxocommerce-redirects/tests/core-extension-menu-smoke.php core-before
 * php taxocommerce-redirects/tests/core-extension-menu-smoke.php core-after
 */
if ( PHP_SAPI !== 'cli' ) {
    exit( 1 );
}

define( 'ABSPATH', __DIR__ );
$mode = isset( $argv[1] ) ? (string) $argv[1] : 'standalone';

$GLOBALS['tc_redirects_actions'] = array();
$GLOBALS['tc_redirects_did_actions'] = array();
$GLOBALS['tc_redirects_menu'] = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['tc_redirects_actions'][ $hook ][] = $callback;
}
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
function register_activation_hook( $file, $callback ) {}
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function did_action( $hook ) {
    return isset( $GLOBALS['tc_redirects_did_actions'][ $hook ] ) ? $GLOBALS['tc_redirects_did_actions'][ $hook ] : 0;
}
function do_action( $hook ) {
    $args = array_slice( func_get_args(), 1 );
    $GLOBALS['tc_redirects_did_actions'][ $hook ] = did_action( $hook ) + 1;
    foreach ( (array) ( $GLOBALS['tc_redirects_actions'][ $hook ] ?? array() ) as $callback ) {
        call_user_func_array( $callback, $args );
    }
}
function add_menu_page( $title, $menu_title, $capability, $slug, $callback, $icon = '', $position = null ) {
    $GLOBALS['tc_redirects_menu'][] = array( 'top', $slug );
}
function add_submenu_page( $parent, $title, $menu_title, $capability, $slug, $callback ) {
    $GLOBALS['tc_redirects_menu'][] = array( 'sub', $parent, $slug );
}

class Redirects_Test_Core {
    public $calls = array();
    public function register_module( $id, $data ) {
        $this->calls[] = array( 'id' => $id, 'data' => $data );
    }
}

if ( 'standalone' !== $mode ) {
    $GLOBALS['tc_redirects_core'] = new Redirects_Test_Core();
    function taxocommerce_core() { return $GLOBALS['tc_redirects_core']; }
}

// Simulate Core priority 20 dispatch before Redirects boots at priority 30.
if ( 'core-before' === $mode ) {
    do_action( 'taxocommerce_core_register_modules', taxocommerce_core() );
}

require dirname( __DIR__ ) . '/taxocommerce-redirects.php';
\TaxoCommerce\Redirects\Plugin::boot();

// Also validate Core boot after Redirects in case another plugin changes timing.
if ( 'core-after' === $mode ) {
    do_action( 'taxocommerce_core_register_modules', taxocommerce_core() );
}

\TaxoCommerce\Redirects\Admin::menu();

function require_true( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: " . $message . "\n" );
        exit( 1 );
    }
}

if ( 'standalone' === $mode ) {
    require_true(
        $GLOBALS['tc_redirects_menu'] === array( array( 'top', 'taxocommerce-redirects' ) ),
        'Redirects without Core must provide its own WordPress menu'
    );
} else {
    require_true(
        $GLOBALS['tc_redirects_menu'] === array( array( 'sub', 'taxocommerce-core', 'taxocommerce-redirects' ) ),
        'Redirects with Core must appear under the family menu'
    );
    $calls = $GLOBALS['tc_redirects_core']->calls;
    require_true( 1 === count( $calls ), 'Redirects must register in Core exactly once' );
    require_true( 'redirects' === $calls[0]['id'], 'Correct family extension ID' );
    require_true(
        $calls[0]['data']['admin_url'] === admin_url( 'admin.php?page=taxocommerce-redirects' ),
        'Redirects must supply the correct Open URL'
    );
}

// The permanent Core catalogue URL also provides the "Abrir" fallback.
$core_file = dirname( __DIR__, 2 ) . '/taxocommerce-core/includes/class-taxocommerce-core.php';
$core_source = file_get_contents( $core_file );
require_true( is_string( $core_source ), 'Core catalogue source found' );
$start = strpos( $core_source, "'redirects' => array(" );
$stop = strpos( $core_source, "'auditor' => array(", $start );
require_true( false !== $start && false !== $stop && $start < $stop, 'Redirects catalogue entry found' );
$entry = substr( $core_source, $start, $stop - $start );
require_true(
    false !== strpos( $entry, "'admin_url'     => admin_url( 'admin.php?page=taxocommerce-redirects' )" ),
    'Redirects active row always has a valid Open URL in Core'
);

echo 'Redirects Core integration OK (' . $mode . ')' . "\n";

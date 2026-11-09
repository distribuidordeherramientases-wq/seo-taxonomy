<?php
/**
 * Regression for the shared redirects contract with OPNV, without WordPress.
 * TaxoCommerce Redirects claims `opnv_redirects_owner` only when its storage is
 * ready, so OPNV keeps serving redirects until this engine can take over.
 */
namespace TaxoCommerce\Redirects {
    final class Store {
        public static function table_exists() { return $GLOBALS['table']; }
        public static function save( $origin, $target, $code = 301, $id = 0 ) { $GLOBALS['saved'][] = array( $origin, $target, $code ); return 1; }
    }
}

namespace {
    define( 'ABSPATH', __DIR__ );
    $GLOBALS['hooks']   = array();
    $GLOBALS['options'] = array();
    $GLOBALS['table']   = false;
    function add_action( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $name ][] = $callback; }
    function add_filter( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $name ][] = $callback; }
    function get_option( $name, $default = false ) { return $GLOBALS['options'][ $name ] ?? $default; }
    function owner_now() {
        $owner = 'opnv';
        foreach ( $GLOBALS['hooks']['opnv_redirects_owner'] ?? array() as $cb ) { $owner = call_user_func( $cb, $owner ); }
        return $owner;
    }
    function check_redirect_owner( $test, $label ) {
        if ( ! $test ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
    }

    require dirname( __DIR__ ) . '/includes/class-engine.php';
    \TaxoCommerce\Redirects\Engine::register();

    check_redirect_owner( ! empty( $GLOBALS['hooks']['opnv_redirects_owner'] ), 'Engine registers the OPNV contract' );
    $GLOBALS['saved'] = array();
    $slug = static function () { foreach ( $GLOBALS['hooks']['opnv_redirect_slug_changed'] ?? array() as $cb ) { call_user_func( $cb, '/viejo/', '/nuevo/', 301 ); } };

    check_redirect_owner( 'opnv' === owner_now(), 'No claim before the schema exists' );
    $slug();
    check_redirect_owner( array() === $GLOBALS['saved'], 'Slug changes stay with OPNV before the claim' );
    $GLOBALS['options']['tc_redirects_schema_ready'] = '1';
    check_redirect_owner( 'opnv' === owner_now(), 'No claim while the table is missing' );
    $GLOBALS['table'] = true;
    check_redirect_owner( 'taxocommerce' === owner_now(), 'Claims redirects once storage is ready' );
    $slug();
    check_redirect_owner( array( array( '/viejo/', '/nuevo/', 301 ) ) === $GLOBALS['saved'], 'Slug changes from OPNV are stored here once claimed' );

    echo "OPNV redirects owner: PASS\n";
}

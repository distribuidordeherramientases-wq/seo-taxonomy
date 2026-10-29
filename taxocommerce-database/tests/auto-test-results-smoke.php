<?php
/**
 * Non-destructive Auto Test regression checks.
 * Test-only WordPress and TCDB_Legacy stubs are not bundled in the plugin ZIP.
 */
namespace TCDB_Legacy {
    function seo_clean_db_get_export_tables( $type ) {
        return $GLOBALS['tcdb_test_exports'][ $type ];
    }
    function seo_data_get_tables() {
        return $GLOBALS['tcdb_test_inventory'];
    }
    function seo_data_is_valid_table_name( $name ) {
        return 1 === preg_match( '/^[A-Za-z0-9_]+$/', $name );
    }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    function add_action( $hook, $callback ) {}
    require dirname( __DIR__ ) . '/includes/class-tcdb-auto-test.php';

    function tcdb_assert_status( $method, $expected, $label ) {
        $reflection = new \ReflectionMethod( 'TCDB_Auto_Test', $method );
        $reflection->setAccessible( true );
        $result = $reflection->invoke( null );
        if ( $expected !== $result['status'] ) {
            fwrite( STDERR, "FAIL: {$label} expected {$expected}, got {$result['status']}\n" );
            exit( 1 );
        }
    }
    $GLOBALS['tcdb_test_exports'] = array( 'full' => array(), 'seo' => array() );
    tcdb_assert_status( 'test_full_export', 'fail', 'empty full backup must not pass' );
    $GLOBALS['tcdb_test_exports'] = array( 'full' => array( 'wp_posts' ), 'seo' => array() );
    tcdb_assert_status( 'test_full_export', 'skip', 'SEO export cannot be verified without SEO tables' );
    $GLOBALS['tcdb_test_exports'] = array( 'full' => array( 'wp_posts' ), 'seo' => array( 'wp_seo_nodes' ) );
    tcdb_assert_status( 'test_full_export', 'fail', 'SEO backup must be subset of complete backup' );
    $GLOBALS['tcdb_test_exports']['full'][] = 'wp_seo_nodes';
    tcdb_assert_status( 'test_full_export', 'pass', 'valid full and SEO backup selections' );

    $GLOBALS['tcdb_test_inventory'] = array();
    tcdb_assert_status( 'test_inventory', 'skip', 'empty inventory is not a successful inventory check' );
    $GLOBALS['tcdb_test_inventory'] = array( 'wp_seo_nodes' => array( 'name' => 'wp_seo_nodes', 'rows' => 2, 'engine' => 'InnoDB' ) );
    tcdb_assert_status( 'test_inventory', 'pass', 'valid inventory' );
    $GLOBALS['tcdb_test_inventory']['wp_seo_nodes']['engine'] = null;
    tcdb_assert_status( 'test_inventory', 'fail', 'missing inventory metadata' );

    $clean = file_get_contents( dirname( __DIR__ ) . '/includes/legacy/seo-database-clean.php' );
    preg_match( '/function seo_clean_db_render_database_cleanup_tab\(\).*?(?=\nfunction )/s', $clean, $render );
    preg_match( '/function seo_clean_db_handle_post_actions\(\).*?(?=\nfunction )/s', $clean, $route );
    preg_match( '/function seo_clean_db_handle_database_cleanup_action\(\$action\).*?(?=\nfunction )/s', $clean, $handler );
    foreach ( array( 'render' => $render, 'route' => $route, 'handler' => $handler ) as $label => $part ) {
        if ( ! isset( $part[0] ) ) { fwrite( STDERR, "FAIL: missing cleanup {$label}\n" ); exit( 1 ); }
    }
    preg_match_all( "/seo_clean_db_cleanup_row\([^\n]*'((?:clean_)[a-z_]+)'/", $render[0], $ui_matches );
    preg_match_all( "/case '((?:clean_)[a-z_]+)':/", $route[0], $route_matches );
    preg_match_all( "/case '((?:clean_)[a-z_]+)':/", $handler[0], $handler_matches );
    $ui_actions = array_unique( $ui_matches[1] );
    // The POST router also handles four SEO repairs named clean_*; they are not database cleanup buttons.
    $seo_repairs = array( 'clean_invalid_relations', 'clean_stale_nodes', 'clean_duplicate_relations', 'clean_duplicate_node_rows' );
    $route_actions = array_values( array_diff( array_unique( $route_matches[1] ), $seo_repairs ) );
    $handler_actions = array_unique( $handler_matches[1] );
    sort( $ui_actions ); sort( $route_actions ); sort( $handler_actions );
    if ( 17 !== count( $ui_actions ) || $ui_actions !== $route_actions || $route_actions !== $handler_actions ) {
        fwrite( STDERR, 'FAIL: 17 cleanup buttons, router cases and handler cases must match' . "\n" );
        exit( 1 );
    }
    echo 'Database Auto Test contract OK: empty table cases and 17 cleanup routes.' . "\n";
}

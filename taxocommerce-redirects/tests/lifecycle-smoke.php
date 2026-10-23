<?php
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/taxocommerce-redirects.php' );
$store = file_get_contents( $root . '/includes/class-store.php' );
$life = file_get_contents( $root . '/includes/class-lifecycle.php' );
$uninstall = file_get_contents( $root . '/uninstall.php' );

$checks = array(
    false !== strpos( $main, "register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Lifecycle', 'activate' ) )" ),
    false !== strpos( $store, "return new \\WP_Error( 'tc_redirects_install'" ),
    false !== strpos( $life, "DROP TABLE IF EXISTS %i" ),
    false !== strpos( $life, "_tc_redirects_assisted_rule" ),
    false !== strpos( $life, "delete_option( 'tc_redirects_last_assisted_action' )" ),
    false !== strpos( $life, "const READY   = 'tc_redirects_schema_ready'" ),
    false !== strpos( $uninstall, "WP_UNINSTALL_PLUGIN" ),
    false !== strpos( $uninstall, "Lifecycle::uninstall()" ),
    false === strpos( $main, 'register_deactivation_hook' ),
);
foreach ( $checks as $i => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL lifecycle check ' . ( $i + 1 ) . PHP_EOL );
        exit( 1 );
    }
}
echo "TaxoCommerce Redirects lifecycle guards passed.\n";

<?php
$root = dirname( __DIR__, 3 );
$images = file_get_contents( $root . '/taxocommerce-images/includes/class-taxocommerce-images.php' );
$vocabulary = file_get_contents( $root . '/taxocommerce-vocabulary/includes/class-tcv-admin.php' );
$core = file_get_contents( $root . '/taxocommerce-core/includes/class-taxocommerce-core.php' );
$dashboard = file_get_contents( $root . '/taxocommerce-core/includes/class-taxocommerce-core-dashboard.php' );
$system_check = file_get_contents( $root . '/taxocommerce-system-check/taxocommerce-system-check.php' );
$url_audit = file_get_contents( $root . '/taxocommerce-system-check/includes/class-taxocommerce-system-check-url-audit.php' );

$checks = array(
    false === strpos( $images, 'register_processes_with_core' ),
    false === strpos( $images, 'taxocommerce_core_register_processes' ),
    false !== strpos( $vocabulary, 'add_submenu_page' ),
    false !== strpos( $vocabulary, "'taxocommerce-core'" ),
    false !== strpos( $vocabulary, 'add_menu_page' ),
    false === strpos( $core, "do_action( 'taxocommerce_core_register_processes'" ),
    false === strpos( $core, "'taxocommerce-core-processes'," ),
    false === strpos( $dashboard, "'slug'        => 'taxocommerce-core-processes'" ),
    false === strpos( $system_check, 'taxocommerce_core_register_processes' ),
    false === strpos( $url_audit, 'admin.php?page=taxocommerce-core-processes' ),
);

foreach ( $checks as $index => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL modular architecture smoke ' . ( $index + 1 ) . PHP_EOL );
        exit( 1 );
    }
}
echo "Modular architecture smoke passed.\n";

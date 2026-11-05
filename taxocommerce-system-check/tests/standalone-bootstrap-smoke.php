<?php
/**
 * Regression for System Check's standalone boot and single family banner.
 *
 * Run: php taxocommerce-system-check/tests/standalone-bootstrap-smoke.php
 * Static structural preflight: runtime UAT still requires WordPress.
 */
if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}
$base = dirname( __DIR__ );
$entry = file_get_contents( $base . '/taxocommerce-system-check.php' );
$branding = file_get_contents( $base . '/includes/admin-branding.php' );
if ( false === $entry || false === $branding ) {
    fwrite( STDERR, "Cannot read System Check sources.\n" );
    exit( 1 );
}
$checks = array(
    'The engine must not depend on the legacy plugin folder existing' =>
        false === strpos( $entry, '$legacy_installed' )
        && false === strpos( $entry, "WP_PLUGIN_DIR . '/seo-taxonomy/seo-taxonomy.php'" ),
    'An active legacy runtime, not an installed folder, owns seo_* functions' =>
        false !== strpos( $entry, "function_exists( 'seo_server_status' )" ),
    'The standalone bundled engine loads when no legacy runtime is active' =>
        false !== strpos( $entry, 'if ( ! $legacy_active ) {' )
        && false !== strpos( $entry, "'seo-system-server-status.php'" )
        && false !== strpos( $entry, 'require_once $path;' ),
    'The family banner is rendered exactly once by shared branding' =>
        1 === substr_count( $branding, '<header class="tc-family-hero">' )
        && false === strpos( $entry, 'tcc-page-header__eyebrow' )
        && false === strpos( $entry, '<header class="tcc-page-header">' ),
    'System diagnostics and existing tabs remain reachable' =>
        false !== strpos( $entry, 'taxocommerce_system_check_server_screen();' )
        && false !== strpos( $entry, "taxocommerce_system_check_render_tabs( $tab );" )
        && false !== strpos( $entry, "seo_server_status();" ),
    'Plugin version and readme are aligned' =>
        false !== strpos( $entry, "* Version: 0.2.16" )
        && false !== strpos( $entry, "'0.2.16'" )
        && false !== strpos( file_get_contents( $base . '/readme.txt' ), 'Stable tag: 0.2.16' ),
);
foreach ( $checks as $name => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL: ' . $name . "\n" );
        exit( 1 );
    }
    echo 'PASS: ' . $name . "\n";
}
echo "System Check standalone/bootstrap regression passed.\n";

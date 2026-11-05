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
    'When legacy SEO is active but omits Server Status, load only its missing server module' =>
        false !== strpos( $entry, "if ( \$legacy_active && ! function_exists( 'seo_server_status' ) ) {" )
        && false !== strpos( $entry, 'require_once $server_module;' )
        && false !== strpos( $entry, 'seo-system-server-status.php' ),
    'The family banner is rendered exactly once by shared branding' =>
        1 === substr_count( $branding, '<header class="tc-family-hero">' )
        && false === strpos( $entry, 'tcc-page-header__eyebrow' )
        && false === strpos( $entry, '<header class="tcc-page-header">' ),
    'System diagnostics and existing tabs remain reachable' =>
        false !== strpos( $entry, 'taxocommerce_system_check_server_screen();' )
        && false !== strpos( $entry, 'taxocommerce_system_check_render_tabs( $tab );' )
        && false !== strpos( $entry, "seo_server_status();" ),
    'Plugin version and readme are aligned' =>
        preg_match( '/\\* Version: ([0-9]+\\.[0-9]+\\.[0-9]+)/', $entry, $header_version )
        && preg_match( "/define\\( 'TAXOCOMMERCE_SYSTEM_CHECK_VERSION', '([0-9]+\\.[0-9]+\\.[0-9]+)' \\);/", $entry, $constant_version )
        && preg_match( '/Stable tag: ([0-9]+\\.[0-9]+\\.[0-9]+)/', file_get_contents( $base . '/readme.txt' ), $stable_version )
        && $header_version[1] === $constant_version[1]
        && $header_version[1] === $stable_version[1],
);
foreach ( $checks as $name => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL: ' . $name . "\n" );
        exit( 1 );
    }
    echo 'PASS: ' . $name . "\n";
}
echo "System Check standalone/bootstrap regression passed.\n";

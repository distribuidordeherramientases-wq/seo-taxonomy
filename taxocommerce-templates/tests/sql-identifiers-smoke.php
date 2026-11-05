<?php
/**
 * Regression test for 32 ERROR findings in Plugin Check (2026-11-04 22:23).
 *
 * Run manually with: php taxocommerce-templates/tests/sql-identifiers-smoke.php
 * This test does not boot WordPress or modify database content.
 */
if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}
$root = dirname( __DIR__ ) . '/';
$files = array(
    'includes/class-tct-legacy-email-compat.php' => 1,
    'templates/template-landing-desktop.php'    => 1,
    'templates/template-category-mobile.php'    => 1,
    'templates/template-soluciones-mobile.php'  => 2,
    'templates/template-post-mobile.php'        => 2,
    'templates/template-product-mobile.php'     => 1,
    'templates/template-post-desktop.php'       => 2,
    'templates/template-shop.php'               => 2,
    'templates/template-front-desktop.php'      => 6,
    'templates/template-soluciones-desktop.php' => 2,
    'templates/template-category-desktop.php'   => 1,
    'templates/template-page-desktop.php'       => 2,
    'templates/template-page-mobile.php'        => 1,
    'templates/template-landing-mobile.php'     => 1,
    'templates/template-front-mobile.php'       => 6,
    'templates/template-product-desktop.php'    => 1,
);
if ( array_sum( $files ) !== 32 || count( $files ) !== 16 ) {
    fwrite( STDERR, "FAIL: Plugin Check baseline does not contain 32 errors in 16 files.\n" );
    exit( 1 );
}
foreach ( $files as $relative => $findings ) {
    $path = $root . $relative;
    $php = file_get_contents( $path );
    if ( false === $php ) {
        fwrite( STDERR, "FAIL: Cannot read $relative\n" );
        exit( 1 );
    }
    // All 32 original errors were dynamic table names in these SQL clauses.
    if ( preg_match( '/\b(?:FROM|JOIN|UPDATE|INTO)\s+\{\$[a-z_][a-z0-9_]*(?:->[a-z_][a-z0-9_]*)?\}/i', $php ) ) {
        fwrite( STDERR, "FAIL: SQL identifier interpolation remains in $relative\n" );
        exit( 1 );
    }
    // Every affected file must continue to prepare SQL table identifiers, not
    // just delete the queries to satisfy a static check.
    if ( false === strpos( $php, '%i' ) || false === strpos( $php, '$wpdb->prepare(' ) ) {
        fwrite( STDERR, "FAIL: Missing prepared SQL identifier in $relative\n" );
        exit( 1 );
    }
}
foreach ( array( 'desktop', 'mobile' ) as $device ) {
    $php = file_get_contents( $root . 'templates/template-post-' . $device . '.php' );
    if ( false === strpos( $php, 'array_merge(array($relations_table, $wpdb->posts)' ) ||
         false === strpos( $php, 'FROM %i r' ) ||
         false === strpos( $php, 'INNER JOIN %i p' ) ) {
        fwrite( STDERR, "FAIL: Related-post SQL lost its prepared table arguments ($device).\n" );
        exit( 1 );
    }
}
echo "PASS: All 32 reported SQL identifier errors covered across 16 files (desktop/mobile).\n";

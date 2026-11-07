<?php
/**
 * Contract regression tests for old-but-supported TaxoCommerce SEO entry paths.
 *
 * @package TaxoCommerceSEO
 */
$root = dirname( __DIR__, 2 );
$routes = array(
    'bootstrap.php' => array( 'includes/seo-includes-bootstrap.php', "'/includes/seo-includes-bootstrap.php'" ),
    'seo-marketing.php' => array( 'includes/seo-marketing.php', "'/includes/seo-marketing.php'" ),
    'includes/header.php' => array( 'seo-system/templates/header.php', "'/seo-system/templates/header.php'" ),
    'includes/dependiente/clonador/clonador-bootstrap.php' => array( 'includes/clonador/clonador-bootstrap.php', "'/clonador/clonador-bootstrap.php'" ),
);
foreach ( $routes as $legacy => $contract ) {
    $old = $root . '/' . $legacy;
    $canonical = $root . '/' . $contract[0];
    if ( ! is_file( $old ) || ! is_file( $canonical ) ) {
        fwrite( STDERR, "Missing loader: {$legacy} -> {$contract[0]}\n" );
        exit( 1 );
    }
    $code = file_get_contents( $old );
    if ( ! is_string( $code ) || 1 !== substr_count( $code, 'require_once' ) || false === strpos( $code, $contract[1] ) ) {
        fwrite( STDERR, "Broken or duplicated compatibility include: {$legacy}\n" );
        exit( 1 );
    }
    if ( 'includes/dependiente/clonador/clonador-bootstrap.php' === $legacy
        && ( false !== strpos( $code, "define( 'SEO_CLONADOR_LOADED'" )
            || false !== strpos( $code, "define( 'SEO_CLONADOR_VERSION'" ) ) ) {
        fwrite( STDERR, "Legacy Clonador defines canonical state before load\n" );
        exit( 1 );
    }
}
echo "Legacy entry points: PASS (4 canonical paths)\n";

<?php
/**
 * Legacy bootstraps must not reinitialize Scrappers classes loaded first.
 *
 * @package TaxoCommerceSEO
 */
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
class SEO_Ojeador_Admin {}
class SEO_Ingeniero {}
$root = dirname( __DIR__, 2 );
require $root . '/includes/ojeador/ojeador-bootstrap.php';
require $root . '/includes/ingeniero/ingeniero-bootstrap.php';
if ( defined( 'SEO_OJEADOR_PATH' ) || defined( 'SEO_INGENIERO_PATH' ) ) {
    fwrite( STDERR, "Legacy engine initialized despite standalone Scrappers classes.\n" );
    exit( 1 );
}
echo "Legacy Scrappers coexistence: PASS (Ojeador and Ingeniero)\n";

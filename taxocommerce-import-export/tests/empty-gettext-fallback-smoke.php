<?php
/** Read-only regression for empty translations from old MO/language pack. */
if ( 'cli' !== PHP_SAPI ) { exit; }
define( 'ABSPATH', __DIR__ );
$GLOBALS['ie_filters'] = array();
function add_filter( $hook, $callback, $priority, $accepted_args ) {
    $GLOBALS['ie_filters'][ $hook ] = array( $callback, $priority, $accepted_args );
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-ie-gettext-fallback.php';

function ie_check( $condition, $description ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $description . PHP_EOL );
        exit( 1 );
    }
}
TaxoCommerce_IE_Gettext_Fallback::register();
foreach ( array( 'gettext' => 3, 'gettext_with_context' => 4, 'ngettext' => 5, 'ngettext_with_context' => 6 ) as $hook => $argc ) {
    ie_check( isset( $GLOBALS['ie_filters'][ $hook ] ), 'Missing filter ' . $hook );
    ie_check( PHP_INT_MAX === $GLOBALS['ie_filters'][ $hook ][1] && $argc === $GLOBALS['ie_filters'][ $hook ][2], 'Late filter priority/arity ' . $hook );
}
$class = 'TaxoCommerce_IE_Gettext_Fallback';
ie_check( 'Páginas' === $class::singular( '', 'Páginas', 'taxocommerce-import-export' ), 'Recover blank page option' );
ie_check( 'Crear CSV por bloques' === $class::singular( '  ', 'Crear CSV por bloques', 'taxocommerce-import-export' ), 'Recover empty button' );
ie_check( 'Status' === $class::with_context( '', 'Status', 'form', 'taxocommerce-import-export' ), 'Context fallback' );
ie_check( 'Pages' === $class::singular( 'Pages', 'Páginas', 'taxocommerce-import-export' ), 'Keep valid translation' );
ie_check( '' === $class::singular( '', 'Páginas', 'other-domain' ), 'Never affect other plugins' );
ie_check( 'Entrada' === $class::plural( '', 'Entrada', 'Entradas', 1, 'taxocommerce-import-export' ), 'Plural one' );
ie_check( 'Entradas' === $class::plural( '', 'Entrada', 'Entradas', 4, 'taxocommerce-import-export' ), 'Plural many' );
ie_check( 'Páginas' === $class::plural_with_context( '', 'Página', 'Páginas', 2, 'form', 'taxocommerce-import-export' ), 'Plural with context' );
ie_check( '' === $class::plural( '', 'Página', 'Páginas', 2, 'other-domain' ), 'Do not affect other domains' );
$plugin = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-import-export.php' );
ie_check( false !== strpos( $plugin, 'TaxoCommerce_IE_Gettext_Fallback::register();' ), 'Bootstrap should install guard' );
ie_check( false !== strpos( $plugin, "Version: 0.4.21" ), 'Plugin version' );
echo "Import-Export gettext fallback: PASS" . PHP_EOL;

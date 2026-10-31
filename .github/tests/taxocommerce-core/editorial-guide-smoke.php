<?php
/**
 * Standalone sanity check for the four TaxoCommerce Core editorial guides.
 *
 * Run: php .github/tests/taxocommerce-core/editorial-guide-smoke.php
 * No WordPress database, network or plugin activation is required.
 *
 * @package TaxoCommerceCore
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', '/' );

/** Minimal WordPress escaping and i18n stubs for read-only HTML validation. */
function __( $message, $domain = '' ) {
	unset( $domain );
	return $message;
}

function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

function esc_html_e( $value, $domain = '' ) {
	echo esc_html( __( $value, $domain ) );
}

function esc_attr_e( $value, $domain = '' ) {
	echo esc_html( __( $value, $domain ) );
}

function esc_url( $value ) {
	return esc_html( $value );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

require_once ( dirname( __DIR__, 3 ) . '/taxocommerce-core' ) . '/includes/class-taxocommerce-core-editorial-guide.php';

$cases = array(
	'products'   => 'Productos',
	'categories' => 'Categorías',
	'posts'      => 'Entradas',
	'pages'      => 'Páginas',
);

foreach ( $cases as $type => $title ) {
	foreach ( array( 'overview', 'editor' ) as $context ) {
		ob_start();
		TaxoCommerce_Core_Editorial_Guide::render( $type, $context );
		$html = ob_get_clean();
		$checks = array(
			false !== strpos( $html, 'Guía editorial y SEO' ),
			false !== strpos( $html, $title ),
			false !== strpos( $html, 'Google Search Central' ),
			false !== strpos( $html, 'developers.google.com/search/docs/' ),
			false !== strpos( $html, 'Comprobación antes de guardar' ),
			substr_count( $html, '<li>' ) >= 15,
			false === strpos( $html, '<form' ),
			'overview' === $context ? false !== strpos( $html, ' open>' ) : false === strpos( $html, ' open>' ),
		);
		if ( in_array( false, $checks, true ) ) {
			fwrite( STDERR, sprintf( "Editorial guide failed: %s (%s)\n", $type, $context ) );
			exit( 1 );
		}
	}
}

ob_start();
TaxoCommerce_Core_Editorial_Guide::render( 'not-a-valid-type' );
$invalid = ob_get_clean();
if ( '' !== $invalid ) {
	fwrite( STDERR, "Invalid content types must never render guidance.\n" );
	exit( 1 );
}

echo "Editorial guides OK: 4 content types x 2 contexts; no mutations.\n";

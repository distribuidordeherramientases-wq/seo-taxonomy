<?php
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
require_once dirname( __DIR__ ) . '/includes/class-tcsup-url-sync.php';
function tcsup_url_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL URL sync: $message\n" );
		exit( 1 );
	}
}
$row = array( 'sku' => 'ABC-1', 'nombre' => 'Edited title' );
$html = '<script type="application/ld+json">{"@graph":[{"@type":"Product","sku":"ABC-1","name":"Different title","offers":{"price":"19.95","priceCurrency":"EUR","availability":"https://schema.org/InStock"}}]}</script>';
$found = TCSUP_URL_Sync::observation( $html, $row );
tcsup_url_assert( 'matched' === $found['identity'] && '19.95' === $found['price'] && 'EUR' === $found['currency'] && 'InStock' === $found['stock'], 'structured product independent of title' );
$wrong = TCSUP_URL_Sync::observation( $html, array( 'sku' => 'OTHER' ) );
tcsup_url_assert( 'unverified' === $wrong['identity'], 'different reference never verified' );
$empty = TCSUP_URL_Sync::observation( 'Access denied', $row );
tcsup_url_assert( '' === $empty['stock'] && '' === $empty['price'], 'blocked or missing data is not out of stock' );
$multiple = '<script type="application/ld+json">[{"@type":"Product","sku":"ABC-1","offers":{"price":1}},{"@type":"Product","sku":"ABC-1","offers":{"price":2}}]</script>';
tcsup_url_assert( 'unverified' === TCSUP_URL_Sync::observation( $multiple, $row )['identity'], 'ambiguous identity' );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-tcsup-url-sync.php' );
tcsup_url_assert( false !== strpos( $source, 'wp_safe_remote_get' ) && false !== strpos( $source, 'check_ajax_referer' ), 'safe transport and nonce' );
tcsup_url_assert( false === strpos( $source, 'set_regular_price(' ) && false === strpos( $source, 'set_stock_status(' ) && false === strpos( $source, 'set_name(' ), 'observation does not modify WooCommerce commerce or SEO' );
echo "Supplier URL observation smoke passed\n";

<?php
$root = dirname( __DIR__ );

function tcsup_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$main = file_get_contents( $root . '/taxocommerce-suppliers.php' );
$csv  = file_get_contents( $root . '/includes/class-tcsup-csv.php' );
$admin = file_get_contents( $root . '/includes/class-tcsup-admin.php' );
$products = file_get_contents( $root . '/includes/class-tcsup-products.php' );

tcsup_assert( false !== strpos( $main, "Plugin Name: TaxoCommerce Suppliers" ), 'plugin header' );
tcsup_assert( false !== strpos( $main, "taxocommerce_suppliers_get_sources" ), 'supplier source service' );
tcsup_assert( false !== strpos( $csv, "Only CSV" ) || false !== strpos( $admin, "Only CSV" ), 'CSV-only scope' );
tcsup_assert( false === stripos( $root . $main . $csv . $admin . $products, 'crawler-queue.php' ), 'no crawler queue dependency' );
tcsup_assert( false === stripos( $main . $csv . $admin . $products, 'github-python-runner' ), 'no GitHub runner dependency' );
tcsup_assert( false === stripos( $main . $csv . $admin . $products, 'google-python-runner' ), 'no Cloud runner dependency' );
tcsup_assert( false !== strpos( $admin, "External Images" ), 'external image administration' );
tcsup_assert( false !== strpos( $products, "estado_seleccion" ), 'intermediate selection states' );
tcsup_assert( false !== strpos( $products, "precio_con_iva" ), 'supplier price support' );

echo "OK\n";

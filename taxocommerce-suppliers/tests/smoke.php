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
$purchasing = file_get_contents( $root . '/includes/class-tcsup-purchasing.php' );
$manual = file_get_contents( $root . '/includes/class-tcsup-manual.php' );
$autotest = file_get_contents( $root . '/includes/class-tcsup-autotest.php' );

tcsup_assert( false !== strpos( $main, "Plugin Name: TaxoCommerce Suppliers" ), 'plugin header' );
tcsup_assert( false !== strpos( $main, "taxocommerce_suppliers_get_sources" ), 'supplier source service' );
tcsup_assert( false !== strpos( $csv, "Only CSV" ) || false !== strpos( $admin, "Only CSV" ), 'CSV-only scope' );
tcsup_assert( false === stripos( $root . $main . $csv . $admin . $products, 'crawler-queue.php' ), 'no crawler queue dependency' );
tcsup_assert( false === stripos( $main . $csv . $admin . $products, 'github-python-runner' ), 'no GitHub runner dependency' );
tcsup_assert( false === stripos( $main . $csv . $admin . $products, 'google-python-runner' ), 'no Cloud runner dependency' );
tcsup_assert( false !== strpos( $admin, "External Images" ), 'external image administration' );
tcsup_assert( false !== strpos( $products, "estado_seleccion" ), 'intermediate selection states' );
tcsup_assert( false !== strpos( $products, "precio_con_iva" ), 'supplier price support' );
tcsup_assert( false !== strpos( $main, "class-tcsup-purchasing.php" ), 'purchasing bootstrap' );
tcsup_assert( false !== strpos( $purchasing, "wc_get_orders" ), 'WooCommerce sales intake' );
tcsup_assert( false !== strpos( $purchasing, "sources_for_product" ), 'supplier source resolution' );
tcsup_assert( false !== strpos( $purchasing, "Purchase" ), 'supplier purchasing action' );

tcsup_assert( false !== strpos( $main, 'class-tcsup-manual.php' ), 'manual loaded' );
tcsup_assert( false !== strpos( $main, 'class-tcsup-autotest.php' ), 'autotest loaded' );
tcsup_assert( false !== strpos( $admin, "'manual' => 'Manual'" ), 'manual tab' );
tcsup_assert( false !== strpos( $admin, "'autotest' => 'Autotest'" ), 'autotest tab' );
tcsup_assert( false !== strpos( $manual, '<h1 id="tcsup-user">Manual de usuario</h1>' ), 'full user manual heading' );
tcsup_assert( false !== strpos( $manual, '<h1 id="tcsup-dev">Manual técnico para futuros desarrolladores</h1>' ), 'full developer manual heading' );
tcsup_assert( substr_count( $manual, 'class="tcsup-manual-section"' ) >= 16, 'comprehensive indexed manual sections' );
tcsup_assert( false !== strpos( $manual, 'tcsup-manual-index' ) && false !== strpos( $manual, 'tcsup-manual-scroll' ), 'manual navigation and tables' );
tcsup_assert( false !== strpos( $manual, 'class-tcsup-schema.php' ) && false !== strpos( $manual, 'taxocommerce_suppliers_get_sources' ), 'developer architecture and public contracts' );
tcsup_assert( false !== strpos( $manual, 'borrador' ) && false !== strpos( $manual, 'Apply Woo prices' ), 'user side effects documented' );
tcsup_assert( false !== strpos( $autotest, "check_admin_referer( 'tcsup_autotest' )" ), 'autotest nonce' );
tcsup_assert( false !== strpos( $autotest, 'SHOW TABLES LIKE' ), 'read-only schema check' );
echo "OK\n";

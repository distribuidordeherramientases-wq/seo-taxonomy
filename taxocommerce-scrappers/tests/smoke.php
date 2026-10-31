<?php
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/taxocommerce-scrappers.php' );
$ojeador = file_get_contents( $root . '/includes/ojeador/class-ojeador-admin.php' );
$engineer = file_get_contents( $root . '/includes/ingeniero/class-seo-ingeniero-admin.php' );
$shopping = file_get_contents( $root . '/includes/ojeador/class-ojeador-shopping.php' );
$files = array(
    'includes/ingeniero/class-seo-ingeniero-admin.php',
    'includes/ingeniero/class-seo-ingeniero-exchange.php',
    'includes/ojeador/class-ojeador-admin.php',
    'includes/ojeador/class-ojeador-novedades.php',
);
foreach ( $files as $path ) {
    if ( strpos( file_get_contents( $root . '/' . $path ), "'taxocommerce-seo'" ) !== false ) {
        fwrite( STDERR, 'Legacy translation domain in ' . $path . PHP_EOL );
        exit( 1 );
    }
}
$db = file_get_contents( $root . '/includes/ingeniero/class-seo-ingeniero-db.php' );
$checks = array(
    strpos( $main, "Plugin Name: TaxoCommerce Scrappers" ) !== false,
    strpos( $main, "'connections' => 'Connections'" ) !== false,
    strpos( $main, "'ojeador'     => 'Ojeador'" ) !== false,
    strpos( $main, "'ingeniero'   => 'Ingeniero'" ) !== false,
    strpos( $main, "SEO_Ojeador_Admin::render_shared_google_connections()" ) !== false,
    strpos( $main, "SEO_Ojeador_Admin::render()" ) !== false,
    strpos( $main, "SEO_Ingeniero_Admin::render()" ) !== false,
    strpos( $shopping, "https://serpapi.com/search.json" ) !== false,
    strpos( $shopping, "https://api.scraperapi.com/structured/google/shopping" ) !== false,
    strpos( $shopping, "https://api.scraperapi.com/structured/google/search" ) !== false,
    strpos( $ojeador, "const PAGE = 'taxocommerce-scrappers'" ) !== false,
    strpos( $ojeador, "'tcs_tab'=>'ojeador'" ) !== false,
    strpos( $engineer, "'tcs_tab' => 'ingeniero'" ) !== false,
    strpos( $db, 'SELECT id FROM %i WHERE term_id=%d' ) !== false,
    strpos( $ojeador, 'SELECT * FROM %i WHERE' ) !== false,
);
foreach ( $checks as $index => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL Scrappers smoke ' . ( $index + 1 ) . PHP_EOL );
        exit( 1 );
    }
}
echo "TaxoCommerce Scrappers smoke passed.\n";

<?php
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/taxocommerce-connections.php' );
$amazon = file_get_contents( $root . '/includes/class-taxocommerce-connections-amazon.php' );
$pinterest = file_get_contents( $root . '/includes/class-taxocommerce-connections-pinterest.php' );
$google_bing = file_get_contents( $root . '/includes/class-taxocommerce-connections-google-bing.php' );
$admin = file_get_contents( $root . '/includes/class-taxocommerce-connections-admin.php' );

$checks = array(
    strpos( $main, 'Version: 0.3.0' ) !== false,
    strpos( $main, 'Compatibility Bridge' ) !== false,
    strpos( $main, 'taxocommerce_connections_insights_url' ) !== false,
    strpos( $main, "defined( 'TAXOCOMMERCE_GOOGLE_BING_VERSION' )" ) !== false,
    strpos( $main, 'taxocommerce_connections_boot_fallback' ) !== false,
    strpos( $main, 'taxocommerce_core_family_extensions' ) === false,
    strpos( $main, "'page'    => 'taxocommerce-google-bing'" ) !== false,
    strpos( $main, "'tgb_tab' => 'connections'" ) !== false,
    strpos( $amazon, "const LEGACY_OPTION = 'seo_amazon_creators_recipe_settings'" ) !== false,
    strpos( $pinterest, "const LEGACY_OPTION = 'seo_social_network_settings_v1'" ) !== false,
    strpos( $google_bing, "const GOOGLE_OPTION           = 'taxocommerce_google_bing_google_settings'" ) !== false,
    strpos( $google_bing, "const BING_OPTION             = 'taxocommerce_google_bing_bing_settings'" ) !== false,
    strpos( $admin, 'Pinterest Business' ) !== false,
    strpos( $admin, 'Amazon Affiliates' ) !== false,
);

foreach ( $checks as $i => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL Connections compatibility guard ' . ( $i + 1 ) . PHP_EOL );
        exit( 1 );
    }
}
echo "TaxoCommerce Connections compatibility bridge guards passed.\n";

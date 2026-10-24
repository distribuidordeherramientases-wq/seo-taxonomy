<?php
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/taxocommerce-connections.php' );
$amazon = file_get_contents( $root . '/includes/class-taxocommerce-connections-amazon.php' );
$pinterest = file_get_contents( $root . '/includes/class-taxocommerce-connections-pinterest.php' );
$google_bing = file_get_contents( $root . '/includes/class-taxocommerce-connections-google-bing.php' );
$admin = file_get_contents( $root . '/includes/class-taxocommerce-connections-admin.php' );

$checks = array(
    strpos( $main, 'Version: 0.2.0' ) !== false,
    strpos( $main, 'TaxoCommerce Connections' ) !== false,
    strpos( $main, 'TaxoCommerce_Connections_Amazon::boot' ) !== false,
    strpos( $main, 'TaxoCommerce_Connections_Pinterest::boot' ) !== false,
    strpos( $main, 'TaxoCommerce_Connections_Google_Bing::boot' ) !== false,
    strpos( $amazon, "const LEGACY_OPTION = 'seo_amazon_creators_recipe_settings'" ) !== false,
    strpos( $amazon, 'seo_supplier_recipe_amazon_settings' ) !== false,
    strpos( $amazon, 'seo_supplier_recipe_amazon_search_items' ) !== false,
    strpos( $amazon, 'seo_supplier_recipe_amazon_normalize_preview' ) !== false,
    strpos( $pinterest, "const LEGACY_OPTION = 'seo_social_network_settings_v1'" ) !== false,
    strpos( $pinterest, "return rest_url( 'seo-system/v1/pinterest/callback' )" ) !== false,
    strpos( $pinterest, 'taxocommerce_connections_pinterest_oauth_start' ) !== false,
    strpos( $pinterest, 'seo_social_pinterest_domain_verify_head' ) !== false,
    strpos( $pinterest, 'seo_social_pinterest_merchant_settings' ) !== false,
    strpos( $admin, 'Pinterest Business' ) !== false,
    strpos( $admin, 'Amazon Affiliates' ) !== false,
    strpos( $admin, 'TaxoCommerce Insights' ) !== false,
    strpos( $admin, 'Google · Search Console / Analytics / Merchant' ) !== false,
    strpos( $admin, 'Microsoft Bing Webmaster' ) !== false,
    strpos( $google_bing, "const GOOGLE_OPTION           = 'taxocommerce_google_bing_google_settings'" ) !== false,
    strpos( $google_bing, "const BING_OPTION             = 'taxocommerce_google_bing_bing_settings'" ) !== false,
    strpos( $google_bing, 'taxocommerce_connections_test_google' ) !== false,
    strpos( $google_bing, 'taxocommerce_connections_test_bing' ) !== false,
    strpos( $admin, 'Cloudflare' ) !== false,
);
foreach ( $checks as $i => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL Connections guard ' . ( $i + 1 ) . PHP_EOL );
        exit( 1 );
    }
}
echo "TaxoCommerce Connections guards passed.\n";

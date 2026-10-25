<?php
$root = dirname( __DIR__ );
$repo = dirname( $root );

$main = file_get_contents( $root . '/taxocommerce-google-bing.php' );
$admin = file_get_contents( $root . '/includes/class-taxocommerce-connections-admin.php' );
$amazon = file_get_contents( $root . '/includes/class-taxocommerce-connections-amazon.php' );
$pinterest = file_get_contents( $root . '/includes/class-taxocommerce-connections-pinterest.php' );
$google_bing = file_get_contents( $root . '/includes/class-taxocommerce-connections-google-bing.php' );
$oauth = file_get_contents( $root . '/includes/class-taxocommerce-google-bing-oauth.php' );
$bridge = file_get_contents( $repo . '/taxocommerce-connections/taxocommerce-connections.php' );
$core = file_get_contents( $repo . '/taxocommerce-core/includes/class-taxocommerce-core.php' );

$checks = array(
    strpos( $main, 'Version: 1.0.0' ) !== false,
    strpos( $main, "'connections'      => 'Connections'" ) !== false,
    strpos( $main, "TaxoCommerce_Connections_Admin::render()" ) !== false,
    strpos( $main, "class-taxocommerce-connections-amazon.php" ) !== false,
    strpos( $main, "class-taxocommerce-connections-pinterest.php" ) !== false,
    strpos( $main, "TaxoCommerce_Connections_Amazon::boot()" ) !== false,
    strpos( $main, "TaxoCommerce_Connections_Pinterest::boot()" ) !== false,
    strpos( $admin, "'page'    => 'taxocommerce-google-bing'" ) !== false,
    strpos( $admin, "'tgb_tab' => 'connections'" ) !== false,
    strpos( $admin, 'Market Providers · YouTube / SerpApi' ) !== false,
    strpos( $amazon, "const OPTION        = 'taxocommerce_connections_amazon'" ) !== false,
    strpos( $amazon, "const LEGACY_OPTION = 'seo_amazon_creators_recipe_settings'" ) !== false,
    strpos( $pinterest, "const OPTION        = 'taxocommerce_connections_pinterest'" ) !== false,
    strpos( $pinterest, "const LEGACY_OPTION = 'seo_social_network_settings_v1'" ) !== false,
    strpos( $google_bing, "const GOOGLE_OPTION           = 'taxocommerce_google_bing_google_settings'" ) !== false,
    strpos( $google_bing, "const BING_OPTION             = 'taxocommerce_google_bing_bing_settings'" ) !== false,
    strpos( $google_bing, "const MERCHANT_ACCOUNT_OPTION = 'taxocommerce_google_bing_merchant_account'" ) !== false,
    strpos( $oauth, "'tgb_tab'        => 'connections'" ) !== false,
    strpos( $bridge, 'Compatibility Bridge' ) !== false,
    strpos( $bridge, 'taxocommerce_connections_insights_url' ) !== false,
    strpos( $bridge, "defined( 'TAXOCOMMERCE_GOOGLE_BING_VERSION' )" ) !== false,
    strpos( $bridge, 'taxocommerce_core_family_extensions' ) === false,
    strpos( $core, "'connections' => array(" ) === false,
    strpos( $core, "'taxocommerce-connections'   => 'Connections'" ) === false,
);

foreach ( $checks as $index => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL merged Connections guard ' . ( $index + 1 ) . PHP_EOL );
        exit( 1 );
    }
}

echo "Insights merged-connections guards passed.\n";

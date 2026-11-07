<?php
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/taxocommerce-connections.php' );
$amazon = file_get_contents( $root . '/includes/class-taxocommerce-connections-amazon.php' );
$pinterest = file_get_contents( $root . '/includes/class-taxocommerce-connections-pinterest.php' );
$google_bing = file_get_contents( $root . '/includes/class-taxocommerce-connections-google-bing.php' );
$admin = file_get_contents( $root . '/includes/class-taxocommerce-connections-admin.php' );
$autocheck = file_get_contents( $root . '/includes/class-taxocommerce-connections-autocheck.php' );

$checks = array(
    preg_match( '/^ \\* Version: (\\d+\\.\\d+\\.\\d+)$/m', $main, $matches ) === 1 && strpos( $main, "define( 'TAXOCOMMERCE_CONNECTIONS_VERSION', '" . ( $matches[1] ?? '' ) . "' );" ) !== false,
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
    strpos( $main, 'TaxoCommerce_Connections_Autocheck::boot()' ) !== false,
    strpos( $autocheck, 'admin_post_' ) !== false,
    strpos( $autocheck, 'wp_json_encode' ) !== false,
    strpos( $autocheck, 'check_admin_referer' ) !== false,
);

foreach ( $checks as $i => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL Connections compatibility guard ' . ( $i + 1 ) . PHP_EOL );
        exit( 1 );
    }
}
echo "TaxoCommerce Connections compatibility bridge guards passed.\n";

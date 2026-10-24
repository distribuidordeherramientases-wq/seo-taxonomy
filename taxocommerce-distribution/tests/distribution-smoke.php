<?php
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/taxocommerce-distribution.php' );
$worker = file_get_contents( $root . '/includes/worker.php' );
$core = file_get_contents( $root . '/includes/feeds/core.php' );
$channels = file_get_contents( $root . '/includes/feeds/channels.php' );
$admin = file_get_contents( $root . '/includes/admin.php' );
$sitemaps = file_get_contents( $root . '/includes/sitemaps/engine.php' );

$checks = array(
    strpos( $main, 'Version: 0.1.0' ) !== false,
    strpos( $main, "TCDIST_LEGACY_FEED_ACTIVE" ) !== false,
    strpos( $main, "TCDIST_LEGACY_SITEMAP_ACTIVE" ) !== false,
    strpos( $main, "taxocommerce_distribution_migrate_legacy_settings" ) !== false,
    strpos( $worker, "TCDIST_WORKER_HOOK" ) !== false,
    strpos( $worker, "tcdist_feed_run_manager_slice" ) !== false,
    strpos( $worker, "distribution_worker" ) !== false,
    strpos( $core, "seo_process_supervisor_" ) === false,
    strpos( $core, "seo_ie_cf_" ) === false,
    strpos( $core, "tcdist_feed_run_manager_slice" ) !== false,
    strpos( $core, "seo-system-feeds" ) !== false,
    strpos( $channels, "google-merchant.xml" ) !== false,
    strpos( $channels, "microsoft-merchant.txt" ) !== false,
    strpos( $channels, "pinterest-catalog.csv" ) !== false,
    strpos( $channels, "catalogo-universal.csv" ) !== false,
    strpos( $channels, "catalogo-universal.json" ) !== false,
    strpos( $sitemaps, "seo-sitemaps/" ) !== false,
    strpos( $sitemaps, "sitemap.xml" ) !== false,
    strpos( $sitemaps, "TCDIST_SITEMAP_BATCH_SIZE" ) !== false,
    strpos( $sitemaps, "tcdist_sitemap_create_sitemaps" ) !== false,
    strpos( $sitemaps, "tcdist_sitemap_get_sitemap_report" ) !== false,
    strpos( $sitemaps, "tcdist_sitemap_publish_sitemap_file" ) !== false,
    strpos( $sitemaps, "TCDIST_LEGACY_SITEMAP_ACTIVE" ) !== false,
    strpos( $admin, "Commercial Feeds" ) !== false,
    strpos( $admin, "Sitemaps" ) !== false,
    strpos( $admin, "Automation" ) !== false,
    strpos( file_get_contents( $root . '/includes/feeds/admin.php' ), "TCDIST_LEGACY_FEED_ACTIVE" ) !== false,
    strpos( file_get_contents( $root . '/includes/feeds/admin.php' ), "seo_ie_cf_state" ) !== false,
    strpos( file_get_contents( $root . '/includes/feeds/admin.php' ), "legacy inventory generator is running" ) !== false,
);

foreach ( $checks as $index => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL Distribution guard ' . ( $index + 1 ) . PHP_EOL );
        exit( 1 );
    }
}

echo "TaxoCommerce Distribution guards passed.\n";

<?php
/** Verify Insights owns the AJAX action without modifying the legacy engine. */
$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/taxocommerce-google-bing.php' );
$bridge = file_get_contents( $root . '/includes/analysis/analista-geo-bridge.php' );
$client = file_get_contents( $root . '/includes/analysis/assets/analista-lazy.js' );
$checks = array(
    strpos( $plugin, "require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/analysis/analista-geo-bridge.php'" ) !== false,
    strpos( $plugin, "TAXOCOMMERCE_GOOGLE_BING_URL . 'includes/analysis/assets/analista-lazy.js'" ) !== false,
    strpos( $plugin, "wp_create_nonce( 'taxocommerce_insights_analysis' )" ) !== false,
    strpos( $client, "action: 'taxocommerce_insights_analysis'" ) !== false,
    strpos( $bridge, "add_action( 'wp_ajax_taxocommerce_insights_analysis'" ) !== false,
    strpos( $bridge, "seo_analista_lazy_render_partial( \$report, \$days )" ) !== false,
    strpos( $bridge, "seo_analista_geo_render_summary( \$days )" ) !== false,
    strpos( $bridge, "check_ajax_referer( 'taxocommerce_insights_analysis'" ) !== false,
);
foreach ( $checks as $i => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'Insights GEO bridge failed check ' . ( $i + 1 ) . PHP_EOL );
        exit( 1 );
    }
}
echo "Insights GEO bridge smoke: OK\n";

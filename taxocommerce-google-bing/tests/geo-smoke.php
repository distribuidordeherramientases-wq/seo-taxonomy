<?php
/** Local-only SEO/GEO recommendation regressions. */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
function absint( $x ) { return abs( (int) $x ); }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function is_wp_error( $x ) { return false; }
function get_post_status( $id ) { return 'publish'; }
function get_permalink( $id ) { return 'https://example.test/product/' . $id; }
function get_term_link( $id, $tax ) { return 'https://example.test/category/' . $id; }
function number_format_i18n( $value, $precision = 0 ) { return number_format( $value, $precision ); }
function esc_html( $x ) { return htmlspecialchars( (string) $x, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $x ) { return esc_html( $x ); }
function seo_analista_task_bucket( $row ) { return $row['work_bucket'] ?? ''; }
function seo_analista_decision_plan( $days, $limit ) { return $GLOBALS['plan'] ?? array(); }
class Fake_Product {
    public function get_status() { return 'publish'; }
    public function get_name() { return 'Taladro de prueba'; }
}
function wc_get_product( $id ) { return $id === 42 ? new Fake_Product() : false; }
require dirname( __DIR__ ) . '/includes/analysis/analista-geo.php';
function check( $value, $message ) {
    if ( ! $value ) { fwrite( STDERR, $message . "\n" ); exit( 1 ); }
}
$ok = seo_analista_geo_schema_product(
    '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"A"},{"@type":"Product","name":"Taladro","sku":"T1","image":"https://example.test/1.jpg","offers":{"price":"50","priceCurrency":"EUR","availability":"https://schema.org/InStock"}}]}</script>'
);
check( 'checked' === $ok['status'] && $ok['product_found'] && !$ok['missing'], 'Complete JSON-LD should pass' );
$missing = seo_analista_geo_schema_product(
    '<script type="application/ld+json">{"@type":["Product"],"name":"Taladro","offers":{"price":"30"}}</script>'
);
check( in_array( 'offers.availability', $missing['missing'], true ), 'Availability field must be checked' );
check( 'unknown' === seo_analista_geo_schema_product( '<html></html>' )['status'], 'No script must be unknown' );
$now = gmdate( 'c' );
$GLOBALS['options']['seo_ojeador_merchant_snapshots_v1'] = array( 'prices' => array(
    'country' => 'ES', 'updated_at' => $now, 'rows' => array(
        array( 'product_id' => 42, 'currency' => 'EUR', 'price' => 160, 'benchmark' => 100 ),
    ),
) );
check( 1 === count( seo_analista_geo_merchant_candidates( 2 ) ), 'Recent benchmark should produce review' );
$GLOBALS['options']['seo_ojeador_merchant_snapshots_v1']['prices']['updated_at'] = '2024-01-01';
check( !seo_analista_geo_merchant_candidates(), 'Stale Merchant must be ignored' );
$entry = static function ( $url, $bucket, $exec ) {
    return array(
        'target_url' => $url,
        'topic' => 'Herramientas',
        'work_bucket' => $bucket,
        'primary_action' => array( 'instruction' => 'Corregir ficha', 'executable' => $exec ),
        'reason' => 'Datos de Search Console',
        'sources' => array( 'Search Console' ),
        'priority' => 80,
    );
};
$plan = array( $entry( 'https://example.test/a', 'HACER_AHORA', true ),
    $entry( 'https://example.test/a', 'HACER_AHORA', true ),
    $entry( 'https://example.test/b', 'HACER_AHORA', false ),
    $entry( 'https://example.test/c', 'HACER_DESPUES', false ) );
$out = seo_analista_geo_recommendations( $plan );
check( count( $out ) === 2 && $out[0]['url'] === 'https://example.test/a', 'Actions must be validated and deduplicated' );
check( count( seo_analista_geo_recommendations( array(), 5 ) ) === 0, 'No fabricated recommendations' );
echo "Geo smoke: OK\n";

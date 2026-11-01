<?php
/**
 * Deterministic, no-database regression for SEO architecture distribution.
 *
 * Run: php .github/tests/taxocommerce-core/architecture-report-smoke.php
 */
if ( 'cli' !== PHP_SAPI ) {
    exit;
}
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'TAXOCOMMERCE_CORE_VERSION', 'smoke-test' );
define( 'TAXOCOMMERCE_CORE_FILE', __FILE__ );
function absint( $number ) { return abs( (int) $number ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $domain = '' ) { return esc_html( $s ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function number_format_i18n( $n, $dec = 0 ) { return number_format( $n, $dec, ',', '.' ); }
function wp_date( $format, $ts ) { return gmdate( $format, $ts ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function plugins_url( $path, $file ) { return 'https://example.test/wp-content/plugins/taxocommerce-core/' . $path; }
function wp_enqueue_style( $handle, $url, $deps, $version ) { $GLOBALS['tc_test_styles'][] = $handle; }
function wp_nonce_field( $action, $name = '_wpnonce' ) { echo '<input name="' . esc_attr( $name ) . '" value="test">'; }
function get_transient( $k ) { global $fixture; return $fixture; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class WP_Error { private $message; public function __construct( $code, $message ) { $this->message = $message; } public function get_error_message() { return $this->message; } }

// Standalone smoke: supply the Content route without booting WordPress or Core.
class TaxoCommerce_Core_Content_Manager { const PAGE_SLUG = 'taxocommerce-core-content'; }

require ( dirname( __DIR__, 3 ) . '/taxocommerce-core' ) . '/includes/class-taxocommerce-core-architecture-report.php';

$roles = array(
    array( 'id' => 1, 'role' => 'cluster' ),
    array( 'id' => 1, 'role' => 'cluster' ), // duplicate SEO node must not inflate the count.
    array( 'id' => 2, 'role' => 'cluster' ),
    array( 'id' => 11, 'role' => 'hub_primary' ),
    array( 'id' => 12, 'role' => 'hub_primary' ),
    array( 'id' => 13, 'role' => 'hub_primary' ),
    array( 'id' => 21, 'role' => 'hub_secondary' ),
    array( 'id' => 22, 'role' => 'hub_secondary' ),
    array( 'id' => 23, 'role' => 'hub_secondary' ),
);
$relations = array(
    array( 'relation_type' => 'cluster_to_primary', 'source_id' => 1, 'target_id' => 11 ),
    array( 'relation_type' => 'cluster_to_primary', 'source_id' => 1, 'target_id' => 12 ),
    array( 'relation_type' => 'cluster_to_primary', 'source_id' => 2, 'target_id' => 13 ),
    array( 'relation_type' => 'hub_primary_to_hub_secondary', 'source_id' => 11, 'target_id' => 21 ),
    array( 'relation_type' => 'hub_primary_to_hub_secondary', 'source_id' => 12, 'target_id' => 22 ),
    array( 'relation_type' => 'hub_primary_to_hub_secondary', 'source_id' => 13, 'target_id' => 23 ),
    array( 'relation_type' => 'hub_secondary_to_category', 'source_id' => 21, 'target_id' => 31 ),
    array( 'relation_type' => 'hub_secondary_to_category', 'source_id' => 21, 'target_id' => 32 ),
    array( 'relation_type' => 'hub_secondary_to_category', 'source_id' => 22, 'target_id' => 33 ),
    array( 'relation_type' => 'hub_secondary_to_category', 'source_id' => 23, 'target_id' => 34 ),
    array( 'relation_type' => 'hub_secondary_to_category', 'source_id' => 23, 'target_id' => 34 ), // duplicate link.
    array( 'relation_type' => 'hub_secondary_to_category', 'source_id' => 999, 'target_id' => 99 ), // unrelated.
);
$products = array(
    31 => array( 100 => true, 101 => true ),
    32 => array( 100 => true, 102 => true ),
    33 => array( 100 => true, 103 => true ),
    34 => array( 103 => true, 104 => true ),
    99 => array( 999 => true ),
);
$titles = array( 1 => 'Equipamiento', 2 => 'Bricolaje', 11 => 'Carpintería', 12 => 'Herramientas', 13 => 'Electricidad' );
$linked = TaxoCommerce_Core_Architecture_Report::linked_category_ids( $roles, $relations );
sort( $linked );
if ( array( 31, 32, 33, 34 ) !== $linked ) {
    fwrite( STDERR, "FAIL linked categories from published clusters only\n" );
    exit( 1 );
}
$report = TaxoCommerce_Core_Architecture_Report::summarize( $roles, $relations, $products, $titles, 2 );
$checks = array(
    2 === $report['clusters_count'],
    3 === $report['primary_count'],
    3 === $report['secondary_count'],
    2 === $report['landings_count'],
    10 === $report['seo_pages_count'],
    4 === $report['categories_count'],
    5 === $report['products_count'],
    'Equipamiento' === $report['clusters'][0]['title'],
    4 === $report['clusters'][0]['products'],
    3 === $report['clusters'][0]['categories'],
    3 === $report['clusters'][0]['primaryRows'][0]['products'],
    2 === $report['clusters'][0]['primaryRows'][1]['products'],
    2 === $report['clusters'][1]['products'],
);
foreach ( $checks as $i => $ok ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL architecture summary ' . ( $i + 1 ) . "\n" );
        exit( 1 );
    }
}
$fixture = $report;
$fixture['generated_at'] = 1792850400;
ob_start();
TaxoCommerce_Core_Architecture_Report::render();
$html = ob_get_clean();
foreach ( array( 'Distribución de la arquitectura SEO', 'Distribución de la jerarquía SEO', 'Productos publicados por cluster', 'Resumen por cluster', 'Detalle por cluster', 'Carpintería', 'Reparto por hub primario', 'Actualizar informe' ) as $needle ) {
    if ( false === strpos( $html, $needle ) ) {
        fwrite( STDERR, "FAIL missing report content: $needle\n" );
        exit( 1 );
    }
}
if ( false !== strpos( $html, 'seo_dashboard_page' ) || false !== strpos( $html, 'wp_remote_get' ) ) {
    fwrite( STDERR, "FAIL unexpected legacy or external dependency\n" );
    exit( 1 );
}
$fixture = array_merge( $report, array( 'clusters' => array(), 'generated_at' => 1792850400 ) );
ob_start(); TaxoCommerce_Core_Architecture_Report::render(); $empty = ob_get_clean();
if ( false === strpos( $empty, 'No existen clusters publicados' ) ) {
    fwrite( STDERR, "FAIL empty state\n" ); exit( 1 );
}
$GLOBALS['tc_test_styles'] = array();
TaxoCommerce_Core_Architecture_Report::enqueue_styles( 'taxocommerce-core_page_taxocommerce-core-content' );
if ( ! in_array( 'taxocommerce-core-architecture', $GLOBALS['tc_test_styles'], true ) ) {
    fwrite( STDERR, "FAIL Content summary did not enqueue architecture CSS\n" );
    exit( 1 );
}
$GLOBALS['tc_test_styles'] = array();
TaxoCommerce_Core_Architecture_Report::enqueue_styles( 'tools_page_unrelated' );
if ( ! empty( $GLOBALS['tc_test_styles'] ) ) {
    fwrite( STDERR, "FAIL architecture CSS leaks onto unrelated screen\n" );
    exit( 1 );
}
$architecture_source = file_get_contents( ( dirname( __DIR__, 3 ) . '/taxocommerce-core' ) . '/includes/class-taxocommerce-core-architecture-report.php' );
if ( false === strpos( $architecture_source, "esc_html( self::amount( \$count ) )" )
    || false === strpos( $architecture_source, "esc_html( self::amount( \$cluster['products'] ) )" ) ) {
    fwrite( STDERR, "FAIL hierarchy numeric outputs must be escaped\\n" );
    exit( 1 );
}
echo "Architecture report OK: clusters, hubs, categories, unique products, Reports CSS and empty states.\n";

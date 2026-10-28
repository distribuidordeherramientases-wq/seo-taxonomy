<?php
/** Spain-only Trends hierarchy: canonical relations, foreign filtering, no remote calls. */
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_GOOGLE_BING_URL', 'https://example.test/plugin/' );
define( 'TAXOCOMMERCE_GOOGLE_BING_VERSION', 'test' );

class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function selected( $actual, $expected, $echo = true ) { return $actual === $expected ? ' selected="selected"' : ''; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_enqueue_script() {}
function wp_enqueue_style() {}
function wp_remote_get() { throw new RuntimeException( 'Unexpected remote access' ); }
function wp_safe_remote_get() { throw new RuntimeException( 'Unexpected remote access' ); }

$GLOBALS['tgb_pages'] = array(
    1 => (object) array( 'ID' => 1, 'post_title' => 'Automoción', 'post_type' => 'page', 'post_status' => 'publish' ),
    2 => (object) array( 'ID' => 2, 'post_title' => 'Foreign Cluster', 'post_type' => 'page', 'post_status' => 'publish' ),
    3 => (object) array( 'ID' => 3, 'post_title' => 'Draft Cluster', 'post_type' => 'page', 'post_status' => 'draft' ),
    4 => (object) array( 'ID' => 4, 'post_title' => 'Bricolaje', 'post_type' => 'page', 'post_status' => 'publish' ),
    5 => (object) array( 'ID' => 5, 'post_title' => 'Equipamiento profesional', 'post_type' => 'page', 'post_status' => 'publish' ),
    10 => (object) array( 'ID' => 10, 'post_title' => 'Mecánica', 'post_type' => 'page', 'post_status' => 'publish' ),
    11 => (object) array( 'ID' => 11, 'post_title' => 'Foreign Primary', 'post_type' => 'page', 'post_status' => 'publish' ),
    12 => (object) array( 'ID' => 12, 'post_title' => 'Herramientas de taller', 'post_type' => 'page', 'post_status' => 'publish' ),
    13 => (object) array( 'ID' => 13, 'post_title' => 'Herramientas neumáticas', 'post_type' => 'page', 'post_status' => 'publish' ),
    20 => (object) array( 'ID' => 20, 'post_title' => 'Llaves', 'post_type' => 'page', 'post_status' => 'publish' ),
    21 => (object) array( 'ID' => 21, 'post_title' => 'Foreign Secondary', 'post_type' => 'page', 'post_status' => 'publish' ),
);
$GLOBALS['tgb_terms'] = array(
    30 => (object) array( 'term_id' => 30, 'name' => 'Llaves de impacto' ),
    31 => (object) array( 'term_id' => 31, 'name' => 'Foreign category' ),
);
function get_post( $id ) { return $GLOBALS['tgb_pages'][ $id ] ?? null; }
function get_term( $id, $taxonomy ) { return 'product_cat' === $taxonomy ? ( $GLOBALS['tgb_terms'][ $id ] ?? null ) : null; }
function get_post_meta( $id, $key, $single ) {
    if ( 2 === $id || 11 === $id || 21 === $id ) {
        return '_seo_locale' === $key ? 'en-US' : ( '_seo_pais_origen' === $key ? 'US' : '' );
    }
    return '';
}
function get_term_meta( $id, $key, $single ) {
    return 31 === $id ? ( '_seo_locale' === $key ? 'fr-FR' : 'FR' ) : '';
}

class TaxoCommerce_Core_Hierarchy_Classic {
    public static function available() { return true; }
    public static function role_pages( $role ) {
        return 'cluster' === $role ? array(
            $GLOBALS['tgb_pages'][1], $GLOBALS['tgb_pages'][2], $GLOBALS['tgb_pages'][3],
            $GLOBALS['tgb_pages'][4], $GLOBALS['tgb_pages'][5]
        ) : array();
    }
    public static function map( $type ) {
        $maps = array(
            'cluster_to_primary' => array( 1 => array( 10 => true, 11 => true, 12 => true, 13 => true ), 4 => array( 12 => true ) ),
            'hub_primary_to_hub_secondary' => array( 10 => array( 20 => true, 21 => true ) ),
            'hub_secondary_to_category' => array( 20 => array( 30 => true, 31 => true ) )
        );
        return $maps[ $type ] ?? array();
    }
}
require dirname( __DIR__ ) . '/includes/class-taxocommerce-google-bing-trends-hierarchy.php';

$tree = TaxoCommerce_Google_Bing_Trends_Hierarchy::tree();
if ( count( $tree ) !== 3
    || $tree[0]['name'] !== 'Automoción'
    || count( $tree[0]['primaries'] ) !== 3
    || count( $tree[0]['primaries'][0]['secondaries'] ) !== 1
    || count( $tree[0]['primaries'][0]['secondaries'][0]['categories'] ) !== 1
    || $tree[0]['primaries'][0]['secondaries'][0]['categories'][0]['name'] !== 'Llaves de impacto' ) {
    fwrite( STDERR, 'Spanish-only hierarchy relations or market filtering failed' . PHP_EOL );
    exit( 1 );
}
if ( TaxoCommerce_Google_Bing_Trends_Hierarchy::is_spanish( 'de-DE', '' )
    || TaxoCommerce_Google_Bing_Trends_Hierarchy::is_spanish( '', 'FR' )
    || ! TaxoCommerce_Google_Bing_Trends_Hierarchy::is_spanish( 'es-ES', '' ) ) {
    fwrite( STDERR, 'Locale filter failed' . PHP_EOL );
    exit( 1 );
}
$groups = TaxoCommerce_Google_Bing_Trends_Hierarchy::comparison_groups( $tree );
if ( count( $groups ) !== 4
    || $groups[0]['kind'] !== 'cluster'
    || $groups[0]['terms'] !== array( 'Automoción', 'Bricolaje', 'Equipamiento profesional' )
    || $groups[1]['terms'] !== array( 'Herramientas de taller', 'Herramientas neumáticas', 'Mecánica' )
    || $groups[2]['terms'] !== array( 'Herramientas de taller' )
    || $groups[3]['terms'] !== array() ) {
    fwrite( STDERR, "Automatic comparison grouping failed" . PHP_EOL );
    exit( 1 );
}
$many = array();
for ( $i = 1; $i <= 7; $i++ ) {
    $many[] = array(
        'id' => $i, 'name' => 'Cluster ' . $i,
        'primaries' => 1 === $i ? array_map( static function ( $n ) {
            return array( 'id' => $n, 'name' => 'Primary ' . $n );
        }, range( 10, 15 ) ) : array(),
    );
}
$many_groups = TaxoCommerce_Google_Bing_Trends_Hierarchy::comparison_groups( $many );
if ( count( $many_groups ) !== 10
    || count( $many_groups[0]['terms'] ) !== 5
    || count( $many_groups[1]['terms'] ) !== 2
    || count( $many_groups[2]['terms'] ) !== 5
    || count( $many_groups[3]['terms'] ) !== 1 ) {
    fwrite( STDERR, "Google five-term grouping boundary failed" . PHP_EOL );
    exit( 1 );
}
ob_start();
TaxoCommerce_Google_Bing_Trends_Hierarchy::render();
$html = ob_get_clean();
foreach ( array( 'Automoción', 'Mecánica', 'Llaves', 'Llaves de impacto', 'tgb-h-start', 'tgb-h-speed',
    'tgb-h-pause', 'tgb-h-resume', 'tgb-h-stop', 'tgb-h-cat-check', 'Región: España',
    'tgb-h-comparisons', 'tgb-h-compare-card', 'Bricolaje', 'Equipamiento profesional' ) as $required ) {
    if ( false === strpos( $html, $required ) ) {
        fwrite( STDERR, 'Missing tree/control: ' . $required . PHP_EOL );
        exit( 1 );
    }
}
foreach ( array( 'Foreign Cluster', 'Foreign Primary', 'Foreign Secondary', 'Foreign category', '<iframe' ) as $forbidden ) {
    if ( false !== strpos( $html, $forbidden ) ) {
        fwrite( STDERR, 'Foreign data or automatic iframe rendered: ' . $forbidden . PHP_EOL );
        exit( 1 );
    }
}
if ( substr_count( $html, 'class="tgb-h-compare-card"' ) !== 4
    || false === strpos( $html, 'data-kind="cluster"' )
    || false === strpos( $html, 'data-terms="[' ) ) {
    fwrite( STDERR, "Automatic HTML chart cards missing" . PHP_EOL );
    exit( 1 );
}
echo "Spanish automatic comparison hierarchy: OK, no PHP remote request" . PHP_EOL;

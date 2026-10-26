<?php
define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'TAXOCOMMERCE_GOOGLE_BING_URL', 'https://example.test/plugin/' );
define( 'TAXOCOMMERCE_GOOGLE_BING_VERSION', 'test' );

function add_action() {}
function wp_enqueue_style() {}
function current_user_can() { return true; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function esc_sql( $value ) { return preg_replace( '/[^A-Za-z0-9_]/', '', (string) $value ); }
function esc_url_raw( $value ) { return (string) $value; }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function number_format_i18n( $value, $decimals = 0 ) { return number_format( (float) $value, $decimals, '.', ',' ); }
function remove_accents( $value ) { return (string) $value; }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_date( $format, $timestamp ) { return gmdate( $format, $timestamp ); }
function taxonomy_exists( $taxonomy ) { return 'product_cat' === $taxonomy; }
function post_type_exists( $post_type ) { return 'product' === $post_type; }
function wp_count_terms() { return 982; }
function wp_count_posts( $post_type ) { return (object) array( 'publish' => 'product' === $post_type ? 28046 : 0 ); }
function get_posts( $args = array() ) {
    $role = (string) ( $args['meta_value'] ?? '' );
    $counts = array( 'cluster'=>3, 'hub_primary'=>19, 'hub_secondary'=>65 );
    return isset( $counts[ $role ] ) ? range( 1, $counts[ $role ] ) : array();
}
function is_wp_error( $value ) { return false; }
function get_term_link( $term ) { return 'https://example.test/category/' . $term->slug . '/'; }
function get_term( $id, $taxonomy ) {
    foreach ( $GLOBALS['tgb_terms'] as $term ) {
        if ( (int) $term->term_id === (int) $id ) return $term;
    }
    return null;
}
function get_terms() { return $GLOBALS['tgb_terms']; }
function get_post_meta( $id, $key, $single = true ) { return 20 === (int) $id ? 'landing' : ''; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url, $part = -1 ) { return parse_url( $url, $part ); }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=test'; }
function wp_remote_get() { throw new RuntimeException( 'Unexpected HTTP GET from Analyst' ); }
function wp_remote_post() { throw new RuntimeException( 'Unexpected HTTP POST from Analyst' ); }

$GLOBALS['tgb_terms'] = array(
    (object) array( 'term_id' => 101, 'name' => 'Taladros profesionales', 'slug' => 'taladros-profesionales', 'count' => 4 ),
    (object) array( 'term_id' => 102, 'name' => 'Soldadura TIG', 'slug' => 'soldadura-tig', 'count' => 12 ),
);

class TaxoCommerce_Google_Bing_Reports {
    public static function saved_reports( $source ) {
        if ( 'search' === $source ) {
            return array(
                'latest' => array(
                    'downloaded_at' => 1792800000,
                    'current' => array( 'start' => '2026-10-01', 'end' => '2026-10-30', 'label' => 'October' ),
                    'previous' => array( 'start' => '2026-09-01', 'end' => '2026-09-30', 'label' => 'September' ),
                    'data' => array(
                        'summary' => array(
                            'current' => array( 'clicks'=>100, 'impressions'=>5000, 'ctr'=>0.02, 'position'=>11.0 ),
                            'previous' => array( 'clicks'=>80, 'impressions'=>4200, 'ctr'=>0.019, 'position'=>13.0 ),
                        ),
                        'daily' => array(
                            'labels'=>array('01/10','02/10','03/10'),
                            'current_clicks'=>array(2,4,6),
                            'previous_clicks'=>array(1,3,4),
                            'current_impressions'=>array(100,130,160),
                            'previous_impressions'=>array(90,100,120),
                            'current_position'=>array(18.0,14.0,11.0),
                            'previous_position'=>array(20.0,18.0,16.0),
                        ),
                        'queries' => array(
                            array( 'name'=>'taladros profesionales', 'clicks'=>20, 'impressions'=>500, 'ctr'=>0.04, 'position'=>8.0 ),
                            array( 'name'=>'comparativa taladro profesional', 'clicks'=>4, 'impressions'=>160, 'ctr'=>0.025, 'position'=>14.0 ),
                            array( 'name'=>'taladro industrial', 'clicks'=>1, 'impressions'=>70, 'ctr'=>0.014, 'position'=>48.0 ),
                        ),
                        'previous_queries' => array(
                            array( 'name'=>'taladros profesionales', 'clicks'=>14, 'impressions'=>420, 'ctr'=>0.033, 'position'=>12.0 ),
                            array( 'name'=>'comparativa taladro profesional', 'clicks'=>3, 'impressions'=>130, 'ctr'=>0.023, 'position'=>18.0 ),
                            array( 'name'=>'taladro antiguo', 'clicks'=>1, 'impressions'=>50, 'ctr'=>0.02, 'position'=>70.0 ),
                        ),
                        'pages' => array(
                            array( 'name'=>'https://example.test/categoria-producto/taladros/', 'clicks'=>12, 'impressions'=>600, 'ctr'=>0.02, 'position'=>9.0 ),
                            array( 'name'=>'https://example.test/producto/taladro-pro/', 'clicks'=>8, 'impressions'=>400, 'ctr'=>0.02, 'position'=>12.0 ),
                        ),
                        'previous_pages' => array(
                            array( 'name'=>'https://example.test/categoria-producto/taladros/', 'clicks'=>8, 'impressions'=>450, 'ctr'=>0.017, 'position'=>13.0 ),
                        ),
                    ),
                ),
            );
        }
        if ( 'analytics' === $source ) {
            return array(
                'latest' => array(
                    'downloaded_at'=>1792800100,
                    'current'=>array('start'=>'2026-10-01','end'=>'2026-10-30','label'=>'October'),
                    'previous'=>array('start'=>'2026-09-01','end'=>'2026-09-30','label'=>'September'),
                    'data'=>array(
                        'summary'=>array(
                            'current'=>array('activeUsers'=>328,'sessions'=>359,'ecommercePurchases'=>1),
                            'previous'=>array('activeUsers'=>300,'sessions'=>330,'ecommercePurchases'=>0),
                        ),
                        'funnel'=>array(
                            'current'=>array('view_item'=>152,'add_to_cart'=>11,'view_cart'=>7,'begin_checkout'=>5,'purchase'=>1),
                            'previous'=>array('view_item'=>140,'add_to_cart'=>9,'view_cart'=>6,'begin_checkout'=>4,'purchase'=>0),
                        ),
                        'countries'=>array(
                            'labels'=>array('Spain','France'),
                            'current'=>array(127,40),
                            'previous'=>array(110,35),
                        ),
                    ),
                ),
            );
        }
        return array();
    }
}

class TaxoCommerce_Google_Bing_Executive {
    public static function report() {
        return array(
            'overview' => array(
                'google_search' => array(
                    'available' => true, 'clicks' => 100, 'impressions' => 5000, 'ctr' => 0.02, 'position' => 11.0,
                    'previous' => array( 'clicks' => 80, 'impressions' => 4200, 'ctr' => 0.019, 'position' => 13.0 ),
                ),
                'analytics' => array(
                    'available' => true, 'sessions' => 900, 'purchases' => 18,
                    'previous' => array( 'sessions' => 800, 'purchases' => 15 ),
                ),
                'bing' => array(
                    'available' => true, 'clicks' => 30, 'previous' => array( 'clicks' => 25 ),
                ),
            ),
            'content' => array(),
            'opportunities' => array(
                'summary' => array( 'pages' => 1, 'high_priority' => 1 ),
                'rows' => array(
                    array(
                        'url' => 'https://example.test/landing/taladros/',
                        'title' => 'Landing taladros',
                        'object_id' => 20,
                        'object_type' => 'page',
                        'impressions' => 800,
                        'clicks' => 20,
                        'ctr' => 0.025,
                        'position' => 9.0,
                        'views_ga4' => 120,
                        'priority' => 82,
                        'preparation' => 61,
                        'action' => 'Expand the answer: more detail is needed to better cover intent.',
                    ),
                ),
            ),
            'demand' => array(
                'trends' => array(
                    'items' => array(
                        array( 'title' => 'taladro profesional nueva normativa', 'traffic_label' => '2K+' ),
                    ),
                ),
            ),
        );
    }
}

class TaxoCommerce_Google_Bing_Market {
    public static function tables() {
        return array(
            'categories' => 'wp_tgb_market_categories',
            'sources' => 'wp_tgb_market_sources',
            'runs' => 'wp_tgb_market_runs',
        );
    }
}

class TGB_Analyst_WPDB {
    public $prefix = 'wp_';
    public function esc_like( $value ) { return $value; }
    public function prepare( $query ) {
        $args = func_get_args();
        array_shift( $args );
        foreach ( $args as $arg ) {
            $query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
        }
        return $query;
    }
    public function get_var( $query ) {
        if ( false !== strpos( $query, 'SHOW TABLES LIKE' ) ) {
            if ( false !== strpos( $query, 'wp_tgb_market_categories' ) ) return 'wp_tgb_market_categories';
            if ( false !== strpos( $query, 'wp_tgb_market_sources' ) ) return 'wp_tgb_market_sources';
        }
        if ( false !== strpos( $query, 'COUNT(*) FROM wp_tgb_market_categories' ) ) return 2;
        return 0;
    }
    public function get_row( $query, $format = null ) {
        if ( false !== strpos( $query, "SUM(CASE WHEN scan_status='complete'" ) ) {
            return array( 'total' => 4, 'complete' => 2, 'partial' => 0, 'pending' => 1, 'errors' => 1 );
        }
        if ( false !== strpos( $query, 'merchant_signals' ) ) {
            return array( 'external_evidence' => 6, 'merchant_signals' => 3 );
        }
        return array();
    }
    public function get_results( $query, $format = null ) {
        if ( false !== strpos( $query, "s.source_type IN ('offer','market_signal')" ) && false !== strpos( $query, 'metadata_json' ) ) {
            return array(
                array(
                    'term_id' => 101, 'category_name' => 'Taladros profesionales', 'source_type' => 'market_signal',
                    'provider' => 'merchant', 'title' => 'Taladro Pro X', 'updated_at' => '2026-10-25 10:00:00',
                    'metadata_json' => json_encode( array( 'brand' => 'Marca A', 'rank' => 2, 'relative_demand' => 'HIGH' ) ),
                ),
                array(
                    'term_id' => 101, 'category_name' => 'Taladros profesionales', 'source_type' => 'offer',
                    'provider' => 'serpapi', 'title' => 'Taladro Pro X', 'updated_at' => '2026-10-25 10:00:00',
                    'metadata_json' => json_encode( array( 'seller' => 'Seller One', 'price_value' => 99.0 ) ),
                ),
                array(
                    'term_id' => 102, 'category_name' => 'Soldadura TIG', 'source_type' => 'market_signal',
                    'provider' => 'merchant', 'title' => 'Soldador TIG 200', 'updated_at' => '2026-10-25 09:00:00',
                    'metadata_json' => json_encode( array( 'brand' => 'Marca B', 'rank' => 1, 'relative_demand' => 'VERY_HIGH' ) ),
                ),
            );
        }
        if ( false !== strpos( $query, 'HAVING product_signals>0' ) ) {
            return array(
                array(
                    'term_id' => 101, 'name' => 'Taladros profesionales', 'country' => 'ES',
                    'product_signals' => 8, 'merchant_signals' => 4, 'offers' => 4,
                ),
                array(
                    'term_id' => 102, 'name' => 'Soldadura TIG', 'country' => 'ES',
                    'product_signals' => 3, 'merchant_signals' => 2, 'offers' => 1,
                ),
            );
        }
        if ( false !== strpos( $query, 'GROUP_CONCAT' ) ) {
            return array(
                array(
                    'id' => 1, 'term_id' => 101, 'name' => 'Taladros profesionales', 'country' => 'ES',
                    'scan_status' => 'complete', 'last_scanned_at' => '2026-10-25 10:00:00',
                    'evidence' => 8, 'own_search' => 2, 'merchant' => 3, 'offers' => 2,
                    'external_evidence' => 6, 'providers' => 'merchant,serpapi,google_search',
                ),
            );
        }
        return array();
    }
}
$GLOBALS['wpdb'] = new TGB_Analyst_WPDB();

require dirname( __DIR__ ) . '/includes/class-taxocommerce-google-bing-analyst.php';

$report = TaxoCommerce_Google_Bing_Analyst::report();
if ( 'taxocommerce-insights-analyst' !== $report['schema']['name'] ) {
    fwrite( STDERR, "Unexpected Analyst schema\n" );
    exit( 1 );
}
if ( 'improving' !== $report['where_we_are']['momentum'] ) {
    fwrite( STDERR, "Where-we-are momentum failed\n" );
    exit( 1 );
}
if ( empty( $report['diagnostic']['available'] )
    || 1 !== (int) $report['diagnostic']['movement']['net_top10']
    || 3 !== (int) $report['diagnostic']['architecture']['clusters']
    || 127 !== (int) $report['diagnostic']['metrics']['spain'][0] ) {
    fwrite( STDERR, "Recovered diagnostic dashboard failed\n" );
    exit( 1 );
}
if ( empty( $report['market']['available'] ) || 6 !== (int) $report['market']['categories'][0]['external_evidence'] ) {
    fwrite( STDERR, "Market comparison failed\n" );
    exit( 1 );
}
if ( 50.0 !== (float) $report['market']['global_rollup']['coverage']['coverage_pct'] ) {
    fwrite( STDERR, "Global Market coverage rollup failed\n" );
    exit( 1 );
}
if ( 3 !== (int) $report['market']['global_rollup']['product_intelligence']['signals']
    || 2 !== (int) $report['market']['global_rollup']['product_intelligence']['unique_products'] ) {
    fwrite( STDERR, "Product intelligence rollup failed\n" );
    exit( 1 );
}
if ( empty( $report['market']['global_rollup']['catalog_gaps'] )
    || 'Taladros profesionales' !== $report['market']['global_rollup']['catalog_gaps'][0]['category'] ) {
    fwrite( STDERR, "Catalog gap rollup failed\n" );
    exit( 1 );
}
if ( empty( $report['direction']['summary']['now'] ) ) {
    fwrite( STDERR, "Analyst NOW bucket failed\n" );
    exit( 1 );
}
if ( empty( $report['editor_contract']['rows'] ) ) {
    fwrite( STDERR, "Editor contract failed\n" );
    exit( 1 );
}

ob_start();
TaxoCommerce_Google_Bing_Analyst::render();
$html = ob_get_clean();
foreach ( array( 'Resumen', 'Cómo avanzan nuestras posiciones', 'Movimiento de keywords', 'Arquitectura que Analista tiene en cuenta', 'How does the market look?', 'Global Market Summary', 'Products / clusters repeating across the research', 'Where should we go?' ) as $needle ) {
    if ( false === strpos( $html, $needle ) ) {
        fwrite( STDERR, "Analyst render missing: $needle\n" );
        exit( 1 );
    }
}

echo "Analyst smoke test: OK (saved data only, no network requests)\n";

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
function taxonomy_exists( $taxonomy ) { return 'product_cat' === $taxonomy; }
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
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=test'; }
function wp_remote_get() { throw new RuntimeException( 'Unexpected HTTP GET from Analyst' ); }
function wp_remote_post() { throw new RuntimeException( 'Unexpected HTTP POST from Analyst' ); }

$GLOBALS['tgb_terms'] = array(
    (object) array( 'term_id' => 101, 'name' => 'Taladros profesionales', 'slug' => 'taladros-profesionales', 'count' => 4 ),
    (object) array( 'term_id' => 102, 'name' => 'Soldadura TIG', 'slug' => 'soldadura-tig', 'count' => 12 ),
);

class TaxoCommerce_Google_Bing_Reports {
    public static function saved_reports( $source ) {
        if ( 'search' !== $source ) return array();
        return array(
            'latest' => array(
                'downloaded_at' => 1792800000,
                'current' => array( 'start' => '2026-10-01', 'end' => '2026-10-30', 'label' => 'October' ),
                'previous' => array( 'start' => '2026-09-01', 'end' => '2026-09-30', 'label' => 'September' ),
                'data' => array(
                    'queries' => array(
                        array( 'name' => 'taladros profesionales', 'clicks' => 20, 'impressions' => 500, 'ctr' => 0.04, 'position' => 8.0 ),
                        array( 'name' => 'comparativa taladro profesional', 'clicks' => 4, 'impressions' => 160, 'ctr' => 0.025, 'position' => 14.0 ),
                    ),
                ),
            ),
        );
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
    public function get_results( $query, $format = null ) {
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
if ( empty( $report['market']['available'] ) || 6 !== (int) $report['market']['categories'][0]['external_evidence'] ) {
    fwrite( STDERR, "Market comparison failed\n" );
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
foreach ( array( 'Where are we?', 'How does the market look?', 'Where should we go?' ) as $needle ) {
    if ( false === strpos( $html, $needle ) ) {
        fwrite( STDERR, "Analyst render missing: $needle\n" );
        exit( 1 );
    }
}

echo "Analyst smoke test: OK (saved data only, no network requests)\n";

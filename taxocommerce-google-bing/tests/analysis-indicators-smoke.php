<?php
/**
 * Standalone calendar indicator regression, no WordPress or provider account.
 */
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
function indicator_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL indicators: ' . $message . PHP_EOL );
        exit( 1 );
    }
}
function absint( $value ) { return abs( (int) $value ); }
function wp_timezone() { return new DateTimeZone( 'Europe/Madrid' ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {}
function number_format_i18n( $n, $decimals = 0 ) { return number_format( (float) $n, $decimals, ',', '.' ); }
function __( $value, $domain ) { return $value; }
function esc_html__( $value, $domain ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function sanitize_title( $title ) { return strtolower( trim( $title ) ); }
function get_term_by( $field, $value, $taxonomy ) {
    return 'product_cat' === $taxonomy && 'limpiadores-ultrasonicos' === $value
        ? (object) array( 'term_id' => 456, 'name' => 'Limpiadores ultrasónicos', 'count' => 4 ) : false;
}
function get_term_link( $term ) { return 'https://example.test/tienda/limpiadores-ultrasonicos/'; }
$GLOBALS['ga4_calls'] = array();
$GLOBALS['ga4_unavailable'] = false;
$GLOBALS['indicator_cache'] = array();
function get_transient( $key ) { return $GLOBALS['indicator_cache'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['indicator_cache'][ $key ] = $value; return true; }
function seo_google_analytics_run_report( $args ) {
    if ( ! empty( $GLOBALS['ga4_unavailable'] ) ) {
        return new WP_Error();
    }
    $from = $args['dateRanges'][0]['startDate'];
    $to = $args['dateRanges'][0]['endDate'];
    $GLOBALS['ga4_calls'][] = array( $from, $to );
    if ( isset( $args['dimensions'] ) ) {
        return array( 'rows' => array(
            array( 'dimensionValues' => array( array( 'value' => 'view_item' ) ), 'metricValues' => array( array( 'value' => '40' ) ) ),
            array( 'dimensionValues' => array( array( 'value' => 'add_to_cart' ) ), 'metricValues' => array( array( 'value' => '4' ) ) ),
        ) );
    }
    return array( 'rows' => array( array( 'metricValues' => array_map(
        static function( $v ) { return array( 'value' => (string) $v ); },
        array( 60, 50, 90, 0, 0 )
    ) ) ) );
}
function seo_analista_resolve_property_id() { return 'sc-domain:example.test'; }
function seo_google_latest_data_date( $property ) { return '2026-11-08'; }
function seo_analista_period_metrics( $property, $from, $to ) {
    $days = ( new DateTimeImmutable( $from ) )->diff( new DateTimeImmutable( $to ) )->days + 1;
    return array(
        'source' => 'gsc_property_web_final',
        'days_available' => '2026-11-15' === $to ? $days - 1 : $days,
        'days_expected' => $days,
        'impressions' => '2026-11-02' === $from ? 153 : 21,
        'clicks' => '2026-11-02' === $from ? 2 : 1,
        'position' => 19.4,
    );
}
function seo_analista_query_rows( $property, $from, $to, $limit ) {
    return array( array(
        'query_text' => 'limpiador ultrasonicos',
        'impressions' => '2026-11-02' === $from ? 100 : 10,
        'clicks' => 0,
        'position' => 15.0,
    ) );
}
function seo_google_pages_period_complete( $property, $from, $to ) { return true; }
function seo_analista_page_rows( $property, $from, $to, $limit ) {
    return array( array(
        'page_url' => 'https://example.test/tienda/limpiadores-ultrasonicos/',
        'impressions' => '2026-11-02' === $from ? 80 : 10,
        'clicks' => 2,
        'position' => 12.0,
    ) );
}

require dirname( __DIR__ ) . '/includes/analysis/analista-indicadores.php';

$weeks = taxocommerce_insights_indicators_periods( 'week', '2026-11-08', '2026-11-15', 4 );
indicator_assert( count( $weeks ) === 4, 'Four complete weeks' );
indicator_assert( $weeks[0]['date_from'] === '2026-11-02' && $weeks[0]['date_to'] === '2026-11-08', 'Shared last Monday-to-Sunday week' );
indicator_assert( $weeks[1]['date_from'] === '2026-10-26' && $weeks[1]['date_to'] === '2026-11-01', 'Previous week shares calendar boundaries' );
$months = taxocommerce_insights_indicators_periods( 'month', '2026-11-08', '2026-11-15', 3 );
indicator_assert( count( $months ) === 3, 'Three complete months' );
indicator_assert( '2026-10-01' === $months[0]['date_from'] && '2026-10-31' === $months[0]['date_to'], 'October is last complete month' );
indicator_assert( '2026-09-01' === $months[1]['date_from'] && '2026-09-30' === $months[1]['date_to'], 'September has 30 days' );
indicator_assert( '2026-08-01' === $months[2]['date_from'] && '2026-08-31' === $months[2]['date_to'], 'August has 31 days' );
$march = taxocommerce_insights_indicators_periods( 'month', '2026-03-30', '2026-03-30', 3 );
indicator_assert( '2026-02-01' === $march[0]['date_from'] && '2026-02-28' === $march[0]['date_to'], 'Month end never overflows into March' );
$leap = taxocommerce_insights_indicators_periods( 'month', '2024-03-02', '2024-03-02', 1 );
indicator_assert( '2024-02-29' === $leap[0]['date_to'], 'Leap-year February is complete' );
indicator_assert( taxocommerce_insights_indicators_delta( 10, 0 )['growth_pct'] === null, 'Zero baseline is not an infinite percentage' );
indicator_assert( taxocommerce_insights_indicators_delta( null, 0 ) === null, 'Unavailable source is not a measured zero' );

$report = taxocommerce_insights_indicators_build( 'week' );
indicator_assert( 'taxocommerce-insights-indicadores' === $report['schema']['name'], 'Evidence-only JSON schema' );
indicator_assert( 'week' === $report['period_mode'], 'Calendar mode in JSON' );
indicator_assert( count( $report['periods'] ) === 4, 'Four historical periods exported' );
indicator_assert( '2026-11-02' === $report['periods'][0]['date_from'], 'Same current window in GSC and GA4' );
indicator_assert( 'available' === $report['periods'][0]['gsc']['state'] && 'available' === $report['periods'][0]['ga4']['state'], 'Both sources available' );
indicator_assert( 153.0 === $report['periods'][0]['gsc']['metrics']['impressions'], 'GSC figures are sourced' );
indicator_assert( 60 === $report['periods'][0]['ga4']['metrics']['sessions'], 'GA4 figures are sourced' );
indicator_assert( 132.0 === $report['changes']['gsc_impressions']['delta'], 'Comparable GSC impression change' );
indicator_assert( ! empty( $report['signals']['categories'][0] ), 'Real category observation, linked to verified WooCommerce term URL' );
indicator_assert( in_array( 'nicho_pequeno_con_clics', $report['signals']['categories'][0]['signals'], true ), 'Small niche with organic clicks is an observation' );
indicator_assert( ! empty( $report['signals']['queries'][0] ), 'Search terms with rising impressions included' );
indicator_assert( ! isset( $report['plan_accion'] ) && ! isset( $report['recommended_changes'] ), 'No fabricated strategic actions' );
indicator_assert( $GLOBALS['ga4_calls'][0] === array( '2026-11-02', '2026-11-08' ), 'GA4 first call uses exact GSC dates' );
indicator_assert( $GLOBALS['ga4_calls'][2] === array( '2026-10-26', '2026-11-01' ), 'GA4 previous week dates are aligned' );

ob_start();
taxocommerce_insights_indicators_render( $report );
$html = ob_get_clean();
indicator_assert( false !== strpos( $html, 'Indicadores para la acción' ), 'New report title displayed' );
indicator_assert( false === strpos( $html, 'HACER AHORA' ), 'No execution queue in indicator report' );

$monthly = taxocommerce_insights_indicators_build( 'month' );
indicator_assert( '2026-10-01' === $monthly['periods'][0]['date_from'], 'Monthly indicators anchored to last complete month' );
indicator_assert( '2026-10-31' === $monthly['periods'][0]['date_to'], 'Monthly GA4 aligned with GSC' );

$GLOBALS['ga4_unavailable'] = true;
$missing_ga4 = taxocommerce_insights_indicators_ga4( array( 'date_from' => '2026-01-01', 'date_to' => '2026-01-31' ) );
indicator_assert( 'unavailable' === $missing_ga4['state'] && null === $missing_ga4['metrics'], 'Unavailable GA4 never reported as zero sessions/sales' );
$partial_gsc = taxocommerce_insights_indicators_gsc( 'sc-domain:example.test', array( 'date_from' => '2026-11-02', 'date_to' => '2026-11-15', 'days' => 14 ) );
indicator_assert( 'partial' === $partial_gsc['state'] && null === $partial_gsc['metrics'], 'Incomplete GSC interval never treated as complete' );
echo "Insights calendar indicators: OK\n";

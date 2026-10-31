<?php
/**
 * Standalone regression: the Insights AJAX action unifies commercial advice,
 * evidence-based GEO and the historical roadmap, with one decision-plan call.
 * No WordPress installation or network access is required.
 */
define( 'ABSPATH', __DIR__ );
define( 'SEO_ANALISTA_VERSION', '3.9.0' );
class Insights_Stop_Response extends Exception {}
$GLOBALS['invocations'] = array(
    'decision_plan' => 0, 'directrices' => 0, 'roadmap' => 0,
    'geo' => 0, 'google_snapshot' => 0, 'other' => 0,
);
function insights_check( $condition, $description ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL unified Analysis: ' . $description . PHP_EOL );
        exit( 1 );
    }
}
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function check_ajax_referer( $action, $query_arg ) {
    insights_check( 'taxocommerce_insights_analysis' === $action, 'Insights nonce' );
    insights_check( 'nonce' === $query_arg, 'nonce field' );
}
function sanitize_key( $value ) { return preg_replace( '/[^a-z_]/', '', strtolower( $value ) ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return strip_tags( (string) $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function number_format_i18n( $value ) { return number_format( (float) $value ); }
function wp_send_json_success( $payload ) {
    $GLOBALS['last_response'] = array( 'success' => true, 'data' => $payload );
    throw new Insights_Stop_Response();
}
function wp_send_json_error( $payload, $status ) {
    $GLOBALS['last_response'] = array( 'success' => false, 'status' => $status, 'data' => $payload );
    throw new Insights_Stop_Response();
}
function wp_remote_get() { throw new RuntimeException( 'Unexpected remote request' ); }
function wp_remote_post() { throw new RuntimeException( 'Unexpected remote request' ); }
function seo_analista_lazy_reports() {
    return array(
        'directrices' => array( 'label' => 'Qué hacer', 'description' => 'Comercial' ),
        'trabajo' => array( 'label' => 'Plan de acción', 'description' => 'Cartera' ),
        'resumen' => array( 'label' => 'Resumen', 'description' => 'Fuentes' ),
    );
}
function seo_analista_lazy_render_partial( $report, $days ) {
    if ( 'directrices' === $report ) {
        $GLOBALS['invocations']['directrices']++;
        echo '<section><h2>Qué hacer para vender más</h2><p>Diagnóstico comercial histórico</p></section>';
    } else {
        $GLOBALS['invocations']['other']++;
        echo '<section>Informe especializado ' . esc_html( $report ) . '</section>';
    }
}
function seo_analista_decision_plan( $days, $limit ) {
    $GLOBALS['invocations']['decision_plan']++;
    insights_check( 40 === $limit, 'full portfolio limit preserved' );
    return array( array( 'work_bucket' => 'INVESTIGAR', 'topic' => 'Prueba' ) );
}
function seo_analista_google_snapshot( $days, $refresh ) {
    $GLOBALS['invocations']['google_snapshot']++;
    insights_check( false === $refresh, 'read-only GA4 snapshot' );
    return array( 'ga4' => array( 'available' => true, 'traffic_quality' => array( 'ai_sessions' => 3 ) ) );
}
function taxocommerce_google_bing_geo_render_summary( $days, array $ga4 = array(), $plan = null ) {
    $GLOBALS['invocations']['geo']++;
    insights_check( is_array( $plan ) && 1 === count( $plan ), 'GEO receives same decision portfolio' );
    insights_check( 3 === $ga4['traffic_quality']['ai_sessions'], 'GA4 snapshot reused' );
    echo '<section>Hasta cinco mejoras prioritarias verificables</section>';
}
function seo_analista_plan_consistency_report( $plan ) {
    return array( 'ok' => false, 'issues' => array( array( 'code' => 'TEST' ) ) );
}
function seo_analista_plan_summary( $plan ) {
    return array( 'investigar' => count( $plan ), 'hacer_ahora' => 0 );
}
function seo_analista_supplier_snapshot( $limit ) { return array( 'issues' => array() ); }
function seo_analista_internal_search_snapshot( $days, $limit ) { return array( 'gaps' => array() ); }
function seo_analista_render_roadmap( $plan, $summary, $suppliers, $search ) {
    $GLOBALS['invocations']['roadmap']++;
    insights_check( 0 === $summary['hacer_ahora'], 'do not fabricate executable tasks' );
    echo '<section>Plan conservado: ' . (int) $summary['investigar'] . ' para investigar</section>';
}
function add_action( $tag, $handler ) { return true; }

require dirname( __DIR__ ) . '/includes/analysis/analista-geo-bridge.php';

$reports = taxocommerce_google_bing_analysis_reports();
insights_check( 2 === count( $reports ), 'one fewer report in Insights' );
insights_check( ! isset( $reports['trabajo'] ), 'separate work button removed' );
insights_check( isset( seo_analista_lazy_reports()['trabajo'] ), 'legacy report catalog untouched' );
insights_check( false !== strpos( $reports['directrices']['label'], 'plan de acción' ), 'merged button is clear' );

$_POST = array( 'report' => 'directrices', 'days' => '28' );
try {
    taxocommerce_insights_analysis_ajax();
} catch ( Insights_Stop_Response $stop ) {
}
$result = $GLOBALS['last_response'];
insights_check( true === $result['success'], 'combined report response succeeds' );
$html = $result['data']['html'];
insights_check( false !== strpos( $html, 'Qué hacer para vender más' ), 'commercial advice retained' );
insights_check( false !== strpos( $html, 'Hasta cinco mejoras prioritarias verificables' ), 'GEO included even if tasks are not executable' );
insights_check( false !== strpos( $html, 'Plan de acción' ), 'roadmap included' );
insights_check( false !== strpos( $html, 'Plan de acción incoherente' ), 'roadmap consistency warning retained' );
insights_check( 1 === $GLOBALS['invocations']['decision_plan'], 'decision plan computed exactly once' );
insights_check( 1 === $GLOBALS['invocations']['directrices'], 'commercial report rendered once' );
insights_check( 1 === $GLOBALS['invocations']['geo'], 'GEO rendered once' );
insights_check( 1 === $GLOBALS['invocations']['roadmap'], 'roadmap rendered once' );

$_POST = array( 'report' => 'resumen', 'days' => '60' );
try {
    taxocommerce_insights_analysis_ajax();
} catch ( Insights_Stop_Response $stop ) {
}
insights_check( true === $GLOBALS['last_response']['success'], 'specialist report still available' );
insights_check( 1 === $GLOBALS['invocations']['decision_plan'], 'specialist report does not compute executive plan' );
insights_check( 1 === $GLOBALS['invocations']['geo'], 'specialist report does not append GEO' );

$_POST = array( 'report' => 'trabajo', 'days' => '28' );
try {
    taxocommerce_insights_analysis_ajax();
} catch ( Insights_Stop_Response $stop ) {
}
insights_check( false === $GLOBALS['last_response']['success'] &&
    400 === $GLOBALS['last_response']['status'], 'separate work action is rejected in Insights' );
echo "Insights unified Analysis regression: OK\n";

<?php
/**
 * Insights-owned Analysis endpoint. Legacy renderers remain unchanged and are
 * reused for evidence and presentation, without calling the old AJAX action.
 */
defined( 'ABSPATH' ) || exit;

/**
 * The executive action and the detailed roadmap are one report in Insights.
 * Do not mutate the historical list used by the old SEO plugin.
 *
 * @return array
 */
function taxocommerce_google_bing_analysis_reports() {
    if ( ! function_exists( 'seo_analista_lazy_reports' ) ) {
        return array();
    }
    $reports = (array) seo_analista_lazy_reports();
    unset( $reports['trabajo'] );
    if ( isset( $reports['directrices'] ) ) {
        $reports['directrices']['label'] = 'Qué hacer y plan de acción';
        $reports['directrices']['description'] = 'Ventas, mejoras SEO/GEO verificables y tareas priorizadas en un solo informe.';
    }
    return $reports;
}

/**
 * Preserve the original roadmap, validations, supplier indicators and search
 * gaps. $plan is computed once per combined AJAX request.
 *
 * @param int   $days Reporting period.
 * @param array $plan Already computed, validated decision plan.
 * @return void
 */
function taxocommerce_google_bing_analysis_render_work( $days, array $plan ) {
    echo '<section class="seo-analista-section"><h2>Plan de acción</h2><p>Cola validada: hacer ahora, hacer después e investigar cuando falta evidencia.</p></section>';
    $consistency = function_exists( 'seo_analista_plan_consistency_report' )
        ? seo_analista_plan_consistency_report( $plan )
        : array( 'ok' => true, 'issues' => array() );
    if ( empty( $consistency['ok'] ) ) {
        echo '<div class="notice notice-error inline"><p><strong>Plan de acción incoherente:</strong> '
            . esc_html( number_format_i18n( count( (array) ( $consistency['issues'] ?? array() ) ) ) )
            . ' discrepancias entre bucket, decisión o acción ejecutable. No debe darse por válido hasta resolverlas.</p></div>';
    }
    seo_analista_render_roadmap(
        $plan,
        seo_analista_plan_summary( $plan ),
        seo_analista_supplier_snapshot( 40 ),
        seo_analista_internal_search_snapshot( $days, 40 )
    );
}

/**
 * This AJAX handler renders the merged Insights view only on click. All other
 * specialist reports retain their original renderer. Never create work when
 * the legacy plan has no tasks with executable/validated destinations.
 *
 * @return void
 */
function taxocommerce_insights_analysis_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'No tienes permisos suficientes.' ), 403 );
        return;
    }
    check_ajax_referer( 'taxocommerce_insights_analysis', 'nonce' );
    $report = isset( $_POST['report'] ) ? sanitize_key( wp_unslash( $_POST['report'] ) ) : '';
    $days = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 28;
    $days = in_array( $days, array( 28, 60, 90 ), true ) ? $days : 28;
    $reports = taxocommerce_google_bing_analysis_reports();
    if ( ! function_exists( 'seo_analista_lazy_render_partial' ) ||
        ! isset( $reports[ $report ] ) ) {
        wp_send_json_error( array( 'message' => 'Informe no disponible.' ), 400 );
        return;
    }
    $started = microtime( true );
    ob_start();
    try {
        // Original commercial and specialist reports, without changing SEO.
        seo_analista_lazy_render_partial( $report, $days );

        if ( 'directrices' === $report ) {
            // Shared decision portfolio: do not run it once for GEO and again
            // for the action-plan dashboard.
            $plan = seo_analista_decision_plan( $days, 40 );
            if ( function_exists( 'taxocommerce_google_bing_geo_render_summary' ) ) {
                // This reads the existing snapshot; it never refreshes GA4.
                $google = seo_analista_google_snapshot( $days, false );
                $ga4 = (array) ( $google['ga4'] ?? array() );
                taxocommerce_google_bing_geo_render_summary( $days, $ga4, $plan );
            }
            taxocommerce_google_bing_analysis_render_work( $days, $plan );
        }
        $html = ob_get_clean();
    } catch ( Throwable $error ) {
        ob_end_clean();
        wp_send_json_error( array(
            'message' => 'No se pudo generar el informe: ' . sanitize_text_field( $error->getMessage() ),
        ), 500 );
        return;
    }
    wp_send_json_success( array(
        'report' => $report,
        'html' => $html,
        'seconds' => round( microtime( true ) - $started, 2 ),
        'version' => defined( 'SEO_ANALISTA_VERSION' ) ? SEO_ANALISTA_VERSION : '',
    ) );
}
add_action( 'wp_ajax_taxocommerce_insights_analysis', 'taxocommerce_insights_analysis_ajax' );

<?php
/**
 * Insights-owned AJAX route, independent of legacy SEO's global AJAX handler.
 * Historical Analysis reports are delegated unchanged; the GEO summary belongs
 * to Insights and is appended when the historical Directrices renderer runs.
 */
defined( 'ABSPATH' ) || exit;

function taxocommerce_insights_analysis_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'No tienes permisos suficientes.' ), 403 );
    }
    check_ajax_referer( 'taxocommerce_insights_analysis', 'nonce' );
    $report = isset( $_POST['report'] ) ? sanitize_key( wp_unslash( $_POST['report'] ) ) : '';
    $days = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 28;
    $days = in_array( $days, array( 28, 60, 90 ), true ) ? $days : 28;
    if ( ! function_exists( 'seo_analista_lazy_reports' ) ||
        ! function_exists( 'seo_analista_lazy_render_partial' ) ||
        ! isset( seo_analista_lazy_reports()[ $report ] ) ) {
        wp_send_json_error( array( 'message' => 'Informe no disponible.' ), 400 );
    }
    $started = microtime( true );
    ob_start();
    try {
        seo_analista_lazy_render_partial( $report, $days );
        $html = ob_get_clean();
        // The legacy callback does not render the Insights GEO section. If the
        // newer renderer is active, do not duplicate its existing section.
        if ( 'directrices' === $report && function_exists( 'taxocommerce_google_bing_geo_render_summary' ) &&
            false === strpos( $html, 'seo-analista-geo-priorities' ) &&
            false === strpos( $html, 'Hasta cinco mejoras prioritarias verificables' ) ) {
            ob_start();
            taxocommerce_google_bing_geo_render_summary( $days );
            $html .= ob_get_clean();
        }
    } catch ( Throwable $error ) {
        ob_end_clean();
        wp_send_json_error( array( 'message' => 'No se pudo generar el informe: ' . sanitize_text_field( $error->getMessage() ) ), 500 );
    }
    wp_send_json_success( array(
        'report' => $report,
        'html' => $html,
        'seconds' => round( microtime( true ) - $started, 2 ),
        'version' => defined( 'SEO_ANALISTA_VERSION' ) ? SEO_ANALISTA_VERSION : '',
    ) );
}
add_action( 'wp_ajax_taxocommerce_insights_analysis', 'taxocommerce_insights_analysis_ajax' );

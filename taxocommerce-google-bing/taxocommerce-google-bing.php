<?php
/**
 * Plugin Name: TaxoCommerce Insights
 * Description: External connections, provider data downloads, and the restored legacy Analysis dashboard.
 * Version: 1.1.9
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * Text Domain: taxocommerce-google-bing
 */

defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_GOOGLE_BING_VERSION', '1.1.9' );
define( 'TAXOCOMMERCE_GOOGLE_BING_FILE', __FILE__ );
define( 'TAXOCOMMERCE_GOOGLE_BING_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_GOOGLE_BING_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'TAXOCOMMERCE_CONNECTIONS_VERSION' ) ) {
    define( 'TAXOCOMMERCE_CONNECTIONS_VERSION', TAXOCOMMERCE_GOOGLE_BING_VERSION );
}

// Keep the historical class names and option keys so other TaxoCommerce modules
// continue to work after the standalone Connections plugin is disabled.
if ( ! class_exists( 'TaxoCommerce_Connections_Amazon', false ) ) {
    require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-connections-amazon.php';
}
if ( ! class_exists( 'TaxoCommerce_Connections_Pinterest', false ) ) {
    require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-connections-pinterest.php';
}
if ( ! class_exists( 'TaxoCommerce_Connections_Google_Bing', false ) ) {
    require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-connections-google-bing.php';
}
if ( ! class_exists( 'TaxoCommerce_Connections_Admin', false ) ) {
    require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-connections-admin.php';
}

require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-connections-guide.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-connections.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-oauth.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-gsc.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-reports.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-trends.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-trends-hierarchy.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-connections-status.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-user-guide.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/analysis/analista-bootstrap.php';

function taxocommerce_google_bing_boot() {
    TaxoCommerce_Google_Bing_Connections::register();
    TaxoCommerce_Google_Bing_OAuth::register();

    // Connections now belongs to Insights. These boot methods preserve the
    // historical public helpers and admin-post contracts for Amazon/Pinterest.
    TaxoCommerce_Connections_Amazon::boot();
    TaxoCommerce_Connections_Pinterest::boot();
    TaxoCommerce_Connections_Google_Bing::boot();
    TaxoCommerce_Connections_Admin::boot();
    TaxoCommerce_Connections_Status::register();
    TaxoCommerce_Google_Bing_Reports::register();
    TaxoCommerce_Google_Bing_Trends::register();
    TaxoCommerce_Google_Bing_User_Guide::register();

    add_action( 'taxocommerce_core_register_modules', 'taxocommerce_google_bing_register_with_core' );
    if ( function_exists( 'taxocommerce_core' ) && did_action( 'taxocommerce_core_loaded' ) ) {
        taxocommerce_google_bing_register_with_core( taxocommerce_core() );
    }

    add_action( 'admin_menu', 'taxocommerce_google_bing_register_menu', 65 );
}
add_action( 'plugins_loaded', 'taxocommerce_google_bing_boot', 100 );

function taxocommerce_google_bing_register_with_core( $core ) {
    if ( ! is_object( $core ) || ! method_exists( $core, 'register_module' ) ) {
        return;
    }

    $core->register_module(
        'google_bing',
        array(
            'name'        => 'TaxoCommerce Insights',
            'version'     => TAXOCOMMERCE_GOOGLE_BING_VERSION,
            'status'      => 'active',
            'description' => 'External connections, provider data downloads, and the restored legacy Analysis dashboard.',
            'admin_url'   => admin_url( 'admin.php?page=taxocommerce-google-bing' ),
        )
    );
}

function taxocommerce_google_bing_register_menu() {
    $in_core = function_exists( 'taxocommerce_core' ) || defined( 'TAXOCOMMERCE_CORE_VERSION' );

    if ( $in_core ) {
        add_submenu_page(
            'taxocommerce-core',
            'TaxoCommerce Insights',
            'Insights',
            'manage_options',
            'taxocommerce-google-bing',
            'taxocommerce_google_bing_screen'
        );
        return;
    }

    add_menu_page(
        'TaxoCommerce Insights',
        'Insights',
        'manage_options',
        'taxocommerce-google-bing',
        'taxocommerce_google_bing_screen',
        'dashicons-chart-area',
        63
    );
}

function taxocommerce_google_bing_screen() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Unauthorized access.', 'taxocommerce-google-bing' ) );
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector.
    $tab = isset( $_GET['tgb_tab'] ) ? sanitize_key( wp_unslash( $_GET['tgb_tab'] ) ) : 'connections';
    if ( 'reports' === $tab || 'analytics' === $tab ) {
        $tab = 'google_analytics';
    } elseif ( 'search' === $tab ) {
        $tab = 'google_search';
    }
    if ( ! in_array( $tab, array( 'connections', 'analysis', 'google_search', 'google_analytics', 'bing', 'google_trends', 'user_guide' ), true ) ) {
        $tab = 'connections';
    }

    echo '<div class="wrap tgb-wrap">';
    echo '<h1>TaxoCommerce Insights</h1>';
    echo '<nav class="nav-tab-wrapper" aria-label="TaxoCommerce Insights">';
    foreach (
        array(
            'connections'      => 'Connections',
            'analysis'         => 'Analysis',
            'google_search'    => 'Google Search',
            'google_analytics' => 'Google Analytics',
            'bing'             => 'Bing',
            'google_trends'    => 'Google Trends',
            'user_guide'       => 'User Guide',
        ) as $key => $label
    ) {
        $url = add_query_arg(
            array(
                'page'    => 'taxocommerce-google-bing',
                'tgb_tab' => $key,
            ),
            admin_url( 'admin.php' )
        );
        echo '<a class="nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    }
    echo '</nav>';

    if ( 'connections' === $tab ) {
        wp_enqueue_style( 'tgb-connections-guide', TAXOCOMMERCE_GOOGLE_BING_URL . 'assets/connections-guide.css', array(), TAXOCOMMERCE_GOOGLE_BING_VERSION );
        TaxoCommerce_Connections_Admin::render();
    } elseif ( 'user_guide' === $tab ) {
        TaxoCommerce_Google_Bing_User_Guide::render();
    } elseif ( 'analysis' === $tab ) {
        seo_analista_render_report();
    } elseif ( 'google_trends' === $tab ) {
        TaxoCommerce_Google_Bing_Trends::render();
    } else {
        $source = 'google_search' === $tab ? 'search' : ( 'google_analytics' === $tab ? 'analytics' : 'bing' );
        TaxoCommerce_Google_Bing_Reports::render( $source );
    }

    echo '</div>';
}


/**
 * Reuse the established historical report engine while the SEO monolith is
 * installed. Do not copy hundreds of global seo_analista_* functions into the
 * modular plugin: loading both would fatal on function redeclaration.
 *
 * This is an explicitly transitional bridge, not an independent rewrite.
 * No provider APIs or heavy analyses run until a report is clicked.
 */
/**
 * Load original CSS in the admin header; scripts are printed in the footer.
 * The original admin_enqueue_scripts guard only targets seo-reports/analista.
 */
function taxocommerce_google_bing_analysis_assets() {
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    $tab = isset( $_GET['tgb_tab'] ) ? sanitize_key( wp_unslash( $_GET['tgb_tab'] ) ) : '';
    if ( 'taxocommerce-google-bing' !== $page || 'analysis' !== $tab ) return;
    if ( ! function_exists( 'seo_analista_lazy_reports' ) || ! defined( 'SEO_SYSTEM_URL' ) ) return;
    $version = defined( 'SEO_SYSTEM_VERSION' ) ? SEO_SYSTEM_VERSION : TAXOCOMMERCE_GOOGLE_BING_VERSION;
    wp_enqueue_style(
        'tgb-legacy-analysis-css',
        SEO_SYSTEM_URL . 'includes/analista/assets/analista-informe.css',
        array(),
        $version
    );
}
add_action( 'admin_enqueue_scripts', 'taxocommerce_google_bing_analysis_assets' );

function taxocommerce_google_bing_analysis_screen() {
    $legacy_available = function_exists( 'seo_analista_lazy_reports' )
        && function_exists( 'seo_analista_lazy_render_partial' )
        && has_action( 'wp_ajax_seo_analista_partial_report', 'seo_analista_ajax_partial_report' );

    if ( ! $legacy_available ) {
        echo '<div class="notice notice-warning inline"><p>'
            . esc_html__( 'Analysis requiere el motor de informes del antiguo TaxoCommerce SEO, que no está cargado. Las conexiones de Insights siguen funcionando; todavía no hay un motor independiente que reproduzca estos once informes.', 'taxocommerce-google-bing' )
            . '</p></div>';
        return;
    }

    $days = isset( $_GET['analysis_days'] )
        ? absint( wp_unslash( $_GET['analysis_days'] ) )
        : 28;
    $days = in_array( $days, array( 28, 60, 90 ), true ) ? $days : 28;

    // Use the original CSS and AJAX client (the same renderer, same reports).
    // Re-enqueue regardless of the legacy page-specific admin enqueue guard.
    if ( defined( 'SEO_SYSTEM_URL' ) ) {
        $version = defined( 'SEO_SYSTEM_VERSION' ) ? SEO_SYSTEM_VERSION : TAXOCOMMERCE_GOOGLE_BING_VERSION;
        wp_enqueue_script(
            'tgb-legacy-analysis-js',
            SEO_SYSTEM_URL . 'includes/analista/assets/analista-lazy.js',
            array( 'jquery' ),
            $version,
            true
        );
        wp_add_inline_script(
            'tgb-legacy-analysis-js',
            'window.seoAnalistaLazyConfig = ' . wp_json_encode( array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'seo_analista_partial_report' ),
                'days'    => $days,
            ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
            'before'
        );
    }

    echo '<div class="seo-analista-wrap">';
    echo '<div class="seo-analista-toolbar">';
    echo '<div><h2 style="margin:0 0 4px">Analysis</h2>';
    echo '<p style="margin:0;color:#646970">Los once informes originales de Analista, bajo demanda. No se ejecutan al abrir la página.</p></div>';
    echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
    echo '<input type="hidden" name="page" value="taxocommerce-google-bing">';
    echo '<input type="hidden" name="tgb_tab" value="analysis">';
    echo '<label for="tgb-analysis-days"><strong>Periodo</strong></label>';
    echo '<select id="tgb-analysis-days" name="analysis_days">';
    foreach ( array( 28, 60, 90 ) as $option ) {
        echo '<option value="' . esc_attr( (string) $option ) . '" ' . selected( $days, $option, false ) . '>'
            . esc_html( (string) $option ) . ' días</option>';
    }
    echo '</select><button type="submit" class="button">Cambiar periodo</button>';
    if ( function_exists( 'seo_analista_export_json_url' ) ) {
        echo ' <a class="button" href="' . esc_url( seo_analista_export_json_url( $days ) ) . '">Generar JSON completo</a>';
    }
    echo '</form></div>';

    // Do not call seo_analista_lazy_render_shell: it would enqueue the same
    // click handler again and issue two AJAX requests per button click.
    $reports = seo_analista_lazy_reports();
    echo '<div class="seo-analista-lazy-intro"><strong>Analista '
        . esc_html( defined( 'SEO_ANALISTA_VERSION' ) ? SEO_ANALISTA_VERSION : '' )
        . '</strong> · carga bajo demanda<p>Ejecuta únicamente el informe elegido.</p></div>';
    echo '<div class="seo-analista-lazy-controls">';
    foreach ( $reports as $key => $report ) {
        echo '<button type="button" class="button seo-analista-lazy-button" data-report="' . esc_attr( (string) $key ) . '">';
        echo '<strong>' . esc_html( (string) ( $report['label'] ?? '' ) ) . '</strong>';
        echo '<span>' . esc_html( (string) ( $report['description'] ?? '' ) ) . '</span>';
        echo '</button>';
    }
    echo '</div>';
    echo '<div id="seo-analista-lazy-status" class="seo-analista-lazy-status" aria-live="polite"></div>';
    echo '<div id="seo-analista-lazy-result" class="seo-analista-lazy-result">';
    echo '<div class="seo-analista-lazy-empty"><strong>Ningún informe cargado.</strong><p>Pulsa uno de los botones anteriores para ejecutar solo ese análisis.</p></div>';
    echo '</div></div>';
}

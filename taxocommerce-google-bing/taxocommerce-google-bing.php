<?php
/**
 * Plugin Name: TaxoCommerce Insights
 * Description: External connections, Search, Analytics, Bing, Trends, Market, Analyst, opportunity, and executive reports.
 * Version: 1.1.3
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * Text Domain: taxocommerce-google-bing
 */

defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_GOOGLE_BING_VERSION', '1.1.3' );
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
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-executive.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-prepared.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-commercial.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-analyst.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-trends.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-market-sources.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-market.php';

register_activation_hook( __FILE__, array( 'TaxoCommerce_Google_Bing_Market', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TaxoCommerce_Google_Bing_Market', 'deactivate' ) );

function taxocommerce_google_bing_boot() {
    TaxoCommerce_Google_Bing_Connections::register();
    TaxoCommerce_Google_Bing_OAuth::register();

    // Connections now belongs to Insights. These boot methods preserve the
    // historical public helpers and admin-post contracts for Amazon/Pinterest.
    TaxoCommerce_Connections_Amazon::boot();
    TaxoCommerce_Connections_Pinterest::boot();
    TaxoCommerce_Connections_Google_Bing::boot();
    TaxoCommerce_Connections_Admin::boot();
    TaxoCommerce_Google_Bing_Reports::register();
    TaxoCommerce_Google_Bing_Executive::register();
    TaxoCommerce_Google_Bing_Prepared::register();
    TaxoCommerce_Google_Bing_Analyst::register();
    TaxoCommerce_Google_Bing_Trends::register();
    TaxoCommerce_Google_Bing_Market::register();

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
            'description' => 'External connections, Search, Analytics, Bing, Trends, Market, Analyst, opportunities, and executive reports.',
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
    $tab = isset( $_GET['tgb_tab'] ) ? sanitize_key( wp_unslash( $_GET['tgb_tab'] ) ) : 'informe';
    if ( 'reports' === $tab || 'analytics' === $tab ) {
        $tab = 'google_analytics';
    } elseif ( 'search' === $tab ) {
        $tab = 'google_search';
    }
    if ( ! in_array( $tab, array( 'connections', 'analyst', 'informe', 'google_search', 'google_analytics', 'bing', 'google_trends', 'mercado' ), true ) ) {
        $tab = 'informe';
    }

    echo '<div class="wrap tgb-wrap">';
    echo '<h1>TaxoCommerce Insights</h1>';
    echo '<nav class="nav-tab-wrapper" aria-label="TaxoCommerce Insights">';
    foreach (
        array(
            'connections'      => 'Connections',
            'analyst'          => 'Analyst',
            'informe'          => 'Executive Report',
            'google_search'    => 'Google Search',
            'google_analytics' => 'Google Analytics',
            'bing'             => 'Bing',
            'google_trends'    => 'Google Trends',
            'mercado'          => 'Market',
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
    } elseif ( 'analyst' === $tab ) {
        TaxoCommerce_Google_Bing_Analyst::render();
    } elseif ( 'informe' === $tab ) {
        TaxoCommerce_Google_Bing_Executive::render();
    } elseif ( 'google_trends' === $tab ) {
        TaxoCommerce_Google_Bing_Trends::render();
    } elseif ( 'mercado' === $tab ) {
        TaxoCommerce_Google_Bing_Market::render();
    } else {
        $source = 'google_search' === $tab ? 'search' : ( 'google_analytics' === $tab ? 'analytics' : 'bing' );
        TaxoCommerce_Google_Bing_Reports::render( $source );
    }

    echo '</div>';
}

<?php
/**
 * Plugin Name: TaxoCommerce Google/Bing
 * Description: Conexiones centralizadas con Google y Microsoft Bing para los modulos de la familia TaxoCommerce.
 * Version: 0.2.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * Text Domain: taxocommerce-google-bing
 */

defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_GOOGLE_BING_VERSION', '0.2.0' );
define( 'TAXOCOMMERCE_GOOGLE_BING_FILE', __FILE__ );
define( 'TAXOCOMMERCE_GOOGLE_BING_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_GOOGLE_BING_URL', plugin_dir_url( __FILE__ ) );

require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-connections.php';
require_once TAXOCOMMERCE_GOOGLE_BING_PATH . 'includes/class-taxocommerce-google-bing-reports.php';

function taxocommerce_google_bing_boot() {
    TaxoCommerce_Google_Bing_Connections::register();

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
            'name'        => 'TaxoCommerce Google/Bing',
            'version'     => TAXOCOMMERCE_GOOGLE_BING_VERSION,
            'status'      => 'active',
            'description' => 'Conexiones y fuentes de datos de Google y Microsoft Bing.',
            'admin_url'   => admin_url( 'admin.php?page=taxocommerce-google-bing' ),
        )
    );
}

function taxocommerce_google_bing_register_menu() {
    $in_core = function_exists( 'taxocommerce_core' ) || defined( 'TAXOCOMMERCE_CORE_VERSION' );

    if ( $in_core ) {
        add_submenu_page(
            'taxocommerce-core',
            'Google / Bing',
            'Google / Bing',
            'manage_options',
            'taxocommerce-google-bing',
            'taxocommerce_google_bing_screen'
        );
        return;
    }

    add_menu_page(
        'TaxoCommerce Google / Bing',
        'Google / Bing',
        'manage_options',
        'taxocommerce-google-bing',
        'taxocommerce_google_bing_screen',
        'dashicons-chart-area',
        63
    );
}

function taxocommerce_google_bing_screen() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-google-bing' ) );
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector.
    $tab = isset( $_GET['tgb_tab'] ) ? sanitize_key( wp_unslash( $_GET['tgb_tab'] ) ) : 'connections';
    if ( ! in_array( $tab, array( 'connections', 'reports' ), true ) ) {
        $tab = 'connections';
    }

    echo '<div class="wrap tgb-wrap">';
    echo '<h1>TaxoCommerce Google / Bing</h1>';
    echo '<p>Fuente central de conexiones externas para Analista, Ojeador, Ingeniero, Mercado e informes.</p>';
    echo '<nav class="nav-tab-wrapper" aria-label="Google / Bing">';
    $tabs = array(
        'connections' => 'Conexiones',
        'reports'     => 'Informes',
    );
    foreach ( $tabs as $key => $label ) {
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

    if ( 'reports' === $tab ) {
        TaxoCommerce_Google_Bing_Reports::render();
    } else {
        TaxoCommerce_Google_Bing_Connections::render();
    }

    echo '</div>';
}

<?php
/**
 * Plugin Name: TaxoCommerce System Check
 * Description: Chequeos independientes del servidor, WordPress y plugins, con informes y correo opcional.
 * Version: 0.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-system-check
 */

defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_SYSTEM_CHECK_VERSION', '0.1.0' );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_FILE', __FILE__ );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_SYSTEM_CHECK_URL', plugin_dir_url( __FILE__ ) );

/**
 * Reuse the legacy engine if TaxoCommerce SEO is active. Its functions are
 * global; loading the original modules twice would cause PHP fatal errors.
 */
function taxocommerce_system_check_boot() {
    $legacy_active = defined( 'SEO_SYSTEM_FILE' )
        && wp_normalize_path( (string) SEO_SYSTEM_FILE ) !== wp_normalize_path( TAXOCOMMERCE_SYSTEM_CHECK_FILE );

    if ( ! $legacy_active ) {
        if ( ! defined( 'SEO_SYSTEM_FILE' ) ) {
            define( 'SEO_SYSTEM_FILE', TAXOCOMMERCE_SYSTEM_CHECK_FILE );
        }
        if ( ! defined( 'SEO_SYSTEM_PATH' ) ) {
            define( 'SEO_SYSTEM_PATH', TAXOCOMMERCE_SYSTEM_CHECK_PATH );
        }
        if ( ! defined( 'SEO_SYSTEM_URL' ) ) {
            define( 'SEO_SYSTEM_URL', TAXOCOMMERCE_SYSTEM_CHECK_URL );
        }
        if ( ! defined( 'SEO_SYSTEM_VERSION' ) ) {
            define( 'SEO_SYSTEM_VERSION', TAXOCOMMERCE_SYSTEM_CHECK_VERSION );
        }
        if ( ! defined( 'SEO_SYSTEM_BASENAME' ) ) {
            define( 'SEO_SYSTEM_BASENAME', plugin_basename( TAXOCOMMERCE_SYSTEM_CHECK_FILE ) );
        }
        // Never send a customer's site diagnostics to a developer by default.
        if ( ! defined( 'SEO_SYSTEM_DIAGNOSTICS_RECIPIENT' ) ) {
            define( 'SEO_SYSTEM_DIAGNOSTICS_RECIPIENT', sanitize_email( (string) get_option( 'admin_email', '' ) ) );
        }

        foreach ( array(
            'seo-system-server-status.php',
            'seo-core-validation.php',
            'seo-system-diagnostics-reporting.php',
        ) as $module ) {
            $path = TAXOCOMMERCE_SYSTEM_CHECK_PATH . 'includes/system-check/' . $module;
            if ( is_readable( $path ) ) {
                require_once $path;
            }
        }
    }

    add_action( 'admin_menu', 'taxocommerce_system_check_register_menu', 35 );
    add_action( 'admin_enqueue_scripts', 'taxocommerce_system_check_enqueue_assets' );
    if ( ! $legacy_active ) {
        add_action( 'admin_menu', 'taxocommerce_system_check_register_legacy_routes', 100 );
    }
}
add_action( 'plugins_loaded', 'taxocommerce_system_check_boot', 100 );

function taxocommerce_system_check_register_menu() {
    add_menu_page( 'TaxoCommerce System Check', 'System Check', 'manage_options', 'taxocommerce-system-check', 'taxocommerce_system_check_home', 'dashicons-shield-alt', 62 );
    add_submenu_page( 'taxocommerce-system-check', 'Resumen', 'Resumen', 'manage_options', 'taxocommerce-system-check', 'taxocommerce_system_check_home' );
    add_submenu_page( 'taxocommerce-system-check', 'Estado del sistema', 'Estado del sistema', 'manage_options', 'tcs-system-status', 'taxocommerce_system_check_server_screen' );
    add_submenu_page( 'taxocommerce-system-check', 'Plugin Validation', 'Plugin Validation', 'manage_options', 'tcs-plugin-validation', 'taxocommerce_system_check_validation_screen' );
}

/** The copied module still uses these historic links in several locations. */
function taxocommerce_system_check_register_legacy_routes() {
    add_submenu_page( null, 'Plugin Validation', 'Plugin Validation', 'manage_options', 'seo-core-system-test', 'taxocommerce_system_check_validation_screen' );
    add_submenu_page( null, 'Estado del sistema', 'Estado del sistema', 'manage_options', 'seo_server_status', 'taxocommerce_system_check_server_screen' );
}

function taxocommerce_system_check_enqueue_assets() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing.
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( in_array( $page, array( 'tcs-plugin-validation', 'seo-core-system-test' ), true ) ) {
        wp_enqueue_style(
            'taxocommerce-system-check-validation',
            TAXOCOMMERCE_SYSTEM_CHECK_URL . 'includes/system-check/assets/seo-core-validation.css',
            array(),
            TAXOCOMMERCE_SYSTEM_CHECK_VERSION
        );
    }
}

function taxocommerce_system_check_home() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-system-check' ) );
    }
    echo '<div class="wrap"><h1>TaxoCommerce System Check</h1>';
    echo '<p>Panel independiente de salud del sistema y validacion de plugins.</p>';
    echo '<div class="card" style="max-width:780px;padding:16px">';
    echo '<h2>Estado del sistema</h2><p>PHP, MySQL, WordPress, servidor, seguridad, rendimiento, WooCommerce y logs.</p>';
    echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=tcs-system-status' ) ) . '">Abrir estado del sistema</a></p>';
    echo '<h2>Plugin Validation</h2><p>Chequeos funcionales, integridad del codigo, informes y notificaciones por correo opcionales.</p>';
    echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=tcs-plugin-validation' ) ) . '">Abrir Plugin Validation</a></p>';
    echo '</div>';
    if ( defined( 'SEO_SYSTEM_FILE' ) && wp_normalize_path( (string) SEO_SYSTEM_FILE ) !== wp_normalize_path( TAXOCOMMERCE_SYSTEM_CHECK_FILE ) ) {
        echo '<p class="description">Se reutiliza el motor existente de TaxoCommerce SEO para evitar funciones duplicadas.</p>';
    }
    echo '<p class="description">La revision individual seleccionable de cada plugin se incorporara en una fase posterior. Los correos requieren activacion expresa.</p></div>';
}

function taxocommerce_system_check_server_screen() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-system-check' ) );
    }
    if ( function_exists( 'seo_server_status' ) ) {
        seo_server_status();
        return;
    }
    echo '<div class="wrap"><h1>Estado del sistema</h1><div class="notice notice-error"><p>Motor original no disponible.</p></div></div>';
}

function taxocommerce_system_check_validation_screen() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-system-check' ) );
    }
    if ( function_exists( 'seo_core_system_test' ) ) {
        seo_core_system_test();
        return;
    }
    echo '<div class="wrap"><h1>Plugin Validation</h1><div class="notice notice-error"><p>Motor original no disponible.</p></div></div>';
}

function taxocommerce_system_check_deactivate() {
    if ( defined( 'SEO_SYSTEM_FILE' ) && wp_normalize_path( (string) SEO_SYSTEM_FILE ) !== wp_normalize_path( TAXOCOMMERCE_SYSTEM_CHECK_FILE ) ) {
        return;
    }
    wp_clear_scheduled_hook( 'seo_system_diagnostics_scheduled_report' );
    wp_clear_scheduled_hook( 'seo_system_diagnostics_after_update_report' );
}
register_deactivation_hook( __FILE__, 'taxocommerce_system_check_deactivate' );

<?php
/**
 * Plugin Name: TaxoCommerce Billing
 * Description: WooCommerce invoices, proformas, and PDF quotations migrated 1:1 from TaxoCommerce SEO.
 * Version: 0.1.1
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: David Pérez
 * License: MIT
 * Text Domain: taxocommerce-facturacion
 */

defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_FACTURACION_VERSION', '0.1.1' );
define( 'TAXOCOMMERCE_FACTURACION_FILE', __FILE__ );
define( 'TAXOCOMMERCE_FACTURACION_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_FACTURACION_URL', plugin_dir_url( __FILE__ ) );

require_once TAXOCOMMERCE_FACTURACION_DIR . 'includes/class-taxocommerce-billing-manual.php';
require_once TAXOCOMMERCE_FACTURACION_DIR . 'includes/class-taxocommerce-billing-autotest.php';

function taxocommerce_facturacion_boot_module() {
    if ( class_exists( 'SEO_Facturas_Admin', false ) ) {
        return;
    }

    if ( ! defined( 'SEO_FACTURAS_PATH' ) ) {
        define( 'SEO_FACTURAS_PATH', TAXOCOMMERCE_FACTURACION_DIR . 'includes/' );
    }
    if ( ! defined( 'SEO_FACTURAS_URL' ) ) {
        define( 'SEO_FACTURAS_URL', TAXOCOMMERCE_FACTURACION_URL . 'includes/' );
    }

    require_once TAXOCOMMERCE_FACTURACION_DIR . 'includes/seo-facturas-bootstrap.php';
}
add_action( 'plugins_loaded', 'taxocommerce_facturacion_boot_module', 5 );

/**
 * Keep the legacy Billing controller while exposing Manual and Autotest even
 * when the monolith has loaded SEO_Facturas_Admin first.
 */
function taxocommerce_facturacion_render_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Sin permisos para acceder a Billing.', 'taxocommerce-facturacion' ) );
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation.
    $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
    if ( 'manual' === $tab || 'autotest' === $tab ) {
        $tabs = array(
            'company'   => 'Datos empresa',
            'tax'       => 'Fiscalidad',
            'invoices'  => 'Facturas',
            'proformas' => 'Proformas',
            'quotes'    => 'Presupuestos',
            'documents' => 'Documentos',
            'manual'    => 'Manual',
            'autotest'  => 'Autotest',
        );
        echo '<div class="wrap seo-facturas-wrap"><h1>Facturas y presupuestos</h1>';
        echo '<nav class="nav-tab-wrapper" aria-label="Secciones de Billing">';
        foreach ( $tabs as $key => $label ) {
            $url = add_query_arg( array( 'page' => 'seo-facturas', 'tab' => $key ), admin_url( 'admin.php' ) );
            echo '<a class="nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
        if ( 'manual' === $tab ) {
            TaxoCommerce_Billing_Manual::render();
        } else {
            TaxoCommerce_Billing_Autotest::render();
        }
        echo '</div>';
        return;
    }

    // Preserve existing emission settings, actions, access and legacy UI.
    SEO_Facturas_Admin::render_page();
}

function taxocommerce_facturacion_legacy_admin_links() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page selector.
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( 'seo-facturas' !== $page || ! current_user_can( 'manage_options' ) || ! class_exists( 'SEO_Facturas_Admin' ) ) {
        return;
    }
    $owner = new ReflectionMethod( 'SEO_Facturas_Admin', 'render_page' );
    $original = wp_normalize_path( TAXOCOMMERCE_FACTURACION_DIR . 'includes/class-seo-facturas-admin.php' );
    if ( wp_normalize_path( $owner->getFileName() ) === $original ) {
        return;
    }
    // The monolith screen does not know our two new tab labels; show links here.
    $manual = add_query_arg( array( 'page' => 'seo-facturas', 'tab' => 'manual' ), admin_url( 'admin.php' ) );
    $autotest = add_query_arg( array( 'page' => 'seo-facturas', 'tab' => 'autotest' ), admin_url( 'admin.php' ) );
    echo '<div class="notice notice-info inline"><p>Herramientas de Billing: <a href="' . esc_url( $manual ) . '">Manual de usuario y técnico</a> · <a href="' . esc_url( $autotest ) . '">Autotest</a>.</p></div>';
}
add_action( 'admin_notices', 'taxocommerce_facturacion_legacy_admin_links' );

function taxocommerce_facturacion_register_menu() {
    if ( ! class_exists( 'SEO_Facturas_Admin' ) ) {
        return;
    }

    if ( function_exists( 'taxocommerce_core' ) ) {
        add_submenu_page(
            'taxocommerce-core',
            'Facturas y presupuestos',
            'Billing',
            'manage_options',
            'seo-facturas',
            'taxocommerce_facturacion_render_page'
        );
        return;
    }

    add_menu_page(
        'Facturas y presupuestos',
        'TaxoCommerce Billing',
        'manage_options',
        'seo-facturas',
        'taxocommerce_facturacion_render_page',
        'dashicons-media-spreadsheet',
        60
    );
}
add_action( 'admin_menu', 'taxocommerce_facturacion_register_menu', 80 );

function taxocommerce_facturacion_register_core( $core ) {
    if ( ! is_object( $core ) || ! method_exists( $core, 'register_module' ) ) {
        return;
    }

    $core->register_module(
        'facturacion',
        array(
            'name'        => 'TaxoCommerce Billing',
            'version'     => TAXOCOMMERCE_FACTURACION_VERSION,
            'status'      => 'active',
            'description' => 'Facturas, proformas y presupuestos PDF conectados a WooCommerce.',
            'admin_url'   => admin_url( 'admin.php?page=seo-facturas' ),
        )
    );
}
add_action( 'taxocommerce_core_register_modules', 'taxocommerce_facturacion_register_core' );
if ( function_exists( 'taxocommerce_core' ) && did_action( 'taxocommerce_core_loaded' ) ) {
    taxocommerce_facturacion_register_core( taxocommerce_core() );
}

function taxocommerce_facturacion_family_catalog( $extensions ) {
    $extensions = is_array( $extensions ) ? $extensions : array();
    $extensions['facturacion'] = array(
        'id'            => 'facturacion',
        'name'          => 'TaxoCommerce Billing',
        'plugin_file'   => 'taxocommerce-facturacion/taxocommerce-facturacion.php',
        'org_slug'      => 'taxocommerce-facturacion',
        'search_term'   => 'TaxoCommerce Billing',
        'wordpress_org' => false,
        'admin_url'     => admin_url( 'admin.php?page=seo-facturas' ),
        'description'   => 'Facturas, proformas y presupuestos PDF conectados a WooCommerce.',
    );
    return $extensions;
}
add_filter( 'taxocommerce_core_family_extensions', 'taxocommerce_facturacion_family_catalog' );

function taxocommerce_facturacion_force_core_parent( $parent_file ) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( function_exists( 'taxocommerce_core' ) && 'seo-facturas' === $page ) {
        return 'taxocommerce-core';
    }
    return $parent_file;
}
add_filter( 'parent_file', 'taxocommerce_facturacion_force_core_parent', 999 );

function taxocommerce_facturacion_force_core_submenu( $submenu_file ) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( function_exists( 'taxocommerce_core' ) && 'seo-facturas' === $page ) {
        return 'seo-facturas';
    }
    return $submenu_file;
}
add_filter( 'submenu_file', 'taxocommerce_facturacion_force_core_submenu', 999 );

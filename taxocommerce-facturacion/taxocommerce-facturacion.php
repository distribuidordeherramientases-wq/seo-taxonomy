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
            array( 'SEO_Facturas_Admin', 'render_page' )
        );
        return;
    }

    add_menu_page(
        'Facturas y presupuestos',
        'TaxoCommerce Billing',
        'manage_options',
        'seo-facturas',
        array( 'SEO_Facturas_Admin', 'render_page' ),
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

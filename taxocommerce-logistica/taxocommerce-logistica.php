<?php
/**
 * Plugin Name: TaxoCommerce Logística
 * Description: Gestión de pedidos, proveedores y transporte para WooCommerce, migrada 1:1 desde TaxoCommerce SEO.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: David Pérez
 * License: MIT
 * Text Domain: taxocommerce-logistica
 */

defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_LOGISTICA_VERSION', '0.1.0' );
define( 'TAXOCOMMERCE_LOGISTICA_FILE', __FILE__ );
define( 'TAXOCOMMERCE_LOGISTICA_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_LOGISTICA_URL', plugin_dir_url( __FILE__ ) );

require_once TAXOCOMMERCE_LOGISTICA_DIR . 'includes/seo-logistica.php';
require_once TAXOCOMMERCE_LOGISTICA_DIR . 'includes/seo-transporte.php';

function taxocommerce_logistica_register_menu() {
    $capability = class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';

    if ( function_exists( 'taxocommerce_core' ) ) {
        add_submenu_page(
            'taxocommerce-core',
            'Logística',
            'Logística',
            $capability,
            'seo-logistica',
            'seo_logistica_page'
        );
        return;
    }

    add_menu_page(
        'TaxoCommerce Logística',
        'TaxoCommerce Logística',
        $capability,
        'seo-logistica',
        'seo_logistica_page',
        'dashicons-cart',
        59
    );
}
add_action( 'admin_menu', 'taxocommerce_logistica_register_menu', 80 );

function taxocommerce_logistica_register_core( $core ) {
    if ( ! is_object( $core ) || ! method_exists( $core, 'register_module' ) ) {
        return;
    }

    $core->register_module(
        'logistica',
        array(
            'name'        => 'TaxoCommerce Logística',
            'version'     => TAXOCOMMERCE_LOGISTICA_VERSION,
            'status'      => 'active',
            'description' => 'Pedidos, proveedores y transporte WooCommerce.',
            'admin_url'   => admin_url( 'admin.php?page=seo-logistica' ),
        )
    );
}
add_action( 'taxocommerce_core_register_modules', 'taxocommerce_logistica_register_core' );
if ( function_exists( 'taxocommerce_core' ) && did_action( 'taxocommerce_core_loaded' ) ) {
    taxocommerce_logistica_register_core( taxocommerce_core() );
}

function taxocommerce_logistica_family_catalog( $extensions ) {
    $extensions = is_array( $extensions ) ? $extensions : array();
    $extensions['logistica'] = array(
        'id'            => 'logistica',
        'name'          => 'TaxoCommerce Logística',
        'plugin_file'   => 'taxocommerce-logistica/taxocommerce-logistica.php',
        'org_slug'      => 'taxocommerce-logistica',
        'search_term'   => 'TaxoCommerce Logística',
        'wordpress_org' => false,
        'admin_url'     => admin_url( 'admin.php?page=seo-logistica' ),
        'description'   => 'Pedidos, proveedores y transporte WooCommerce.',
    );
    return $extensions;
}
add_filter( 'taxocommerce_core_family_extensions', 'taxocommerce_logistica_family_catalog' );

function taxocommerce_logistica_force_core_parent( $parent_file ) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( function_exists( 'taxocommerce_core' ) && in_array( $page, array( 'seo-logistica', 'seo-transporte' ), true ) ) {
        return 'taxocommerce-core';
    }
    return $parent_file;
}
add_filter( 'parent_file', 'taxocommerce_logistica_force_core_parent', 999 );

function taxocommerce_logistica_force_core_submenu( $submenu_file ) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( function_exists( 'taxocommerce_core' ) && in_array( $page, array( 'seo-logistica', 'seo-transporte' ), true ) ) {
        return 'seo-logistica';
    }
    return $submenu_file;
}
add_filter( 'submenu_file', 'taxocommerce_logistica_force_core_submenu', 999 );

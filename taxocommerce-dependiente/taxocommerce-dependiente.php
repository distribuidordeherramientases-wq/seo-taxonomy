<?php
/**
 * Plugin Name: TaxoCommerce Dependiente
 * Description: Dependiente para WooCommerce con diez lecciones auditables, foto fija previa, aprendizaje semántico y exportación JSON.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * Text Domain: taxocommerce-dependiente
 */
defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_DEPENDIENTE_VERSION', '0.2.0' );
define( 'TAXOCOMMERCE_DEPENDIENTE_FILE', __FILE__ );
define( 'TAXOCOMMERCE_DEPENDIENTE_PATH', plugin_dir_path( __FILE__ ) );

require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-cpu.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-sources.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-academy.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-search.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-admin.php';

add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    TCD_Academy::boot();
    TCD_Search::boot();
    TCD_Admin::boot();
}, 30 );

add_action( 'taxocommerce_core_register_modules', static function ( $core ) {
    if ( method_exists( $core, 'register_module' ) ) {
        $core->register_module( 'dependiente', array(
            'name' => 'TaxoCommerce Dependiente',
            'version' => TAXOCOMMERCE_DEPENDIENTE_VERSION,
            'status' => 'active',
            'description' => 'Dependiente con diez lecciones auditables, foto fija previa y aprendizaje semántico.',
            'admin_url' => admin_url( 'admin.php?page=taxocommerce-dependiente' ),
        ) );
    }
}, 10, 1 );

register_activation_hook( __FILE__, static function () {
    TCD_Academy::install();
} );
register_deactivation_hook( __FILE__, static function () {
    wp_clear_scheduled_hook( TCD_Academy::CRON_HOOK );
} );

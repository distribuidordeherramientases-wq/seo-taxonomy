<?php
/**
 * Plugin Name: TaxoCommerce Assistant
 * Description: Intelligent WooCommerce assistant with auditable L1-L10 lessons, semantic interpretation, L9 memory, and continuous updates.
 * Version: 0.3.7
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: David Perez
 * License: GPLv2 or later
 * Text Domain: taxocommerce-dependiente
 */
defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_DEPENDIENTE_VERSION', '0.3.7' );
define( 'TAXOCOMMERCE_DEPENDIENTE_FILE', __FILE__ );
define( 'TAXOCOMMERCE_DEPENDIENTE_PATH', plugin_dir_path( __FILE__ ) );

require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-load.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-learning-log.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-sources.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-academy.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-knowledge-transfer.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-feedback.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-intelligence.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-maintenance.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-backup.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-search.php';
require_once TAXOCOMMERCE_DEPENDIENTE_PATH . 'includes/class-tcd-admin.php';

add_action( 'plugins_loaded', static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    TCD_Learning_Log::boot();
    TCD_Academy::boot();
    TCD_Knowledge_Transfer::boot();
    TCD_Feedback::boot();
    TCD_Intelligence::boot();
    TCD_Maintenance::boot();
    TCD_Backup::boot();
    TCD_Search::boot();
    TCD_Admin::boot();
}, 30 );

add_action( 'taxocommerce_core_register_modules', static function ( $core ) {
    if ( method_exists( $core, 'register_module' ) ) {
        $core->register_module( 'dependiente', array(
            'name' => 'TaxoCommerce Assistant',
            'version' => TAXOCOMMERCE_DEPENDIENTE_VERSION,
            'status' => 'active',
            'description' => 'Catalog Assistant with L1-L10, semantic interpretation, L9 memory, and continuous updates.',
            'admin_url' => admin_url( 'admin.php?page=taxocommerce-dependiente' ),
        ) );
    }
}, 10, 1 );

register_activation_hook( __FILE__, static function () {
    TCD_Academy::install();
    TCD_Knowledge_Transfer::install();
    TCD_Feedback::install();
    TCD_Intelligence::install();
} );
register_deactivation_hook( __FILE__, static function () {
    wp_clear_scheduled_hook( TCD_Academy::CRON_HOOK );
    TCD_Maintenance::deactivate();
} );

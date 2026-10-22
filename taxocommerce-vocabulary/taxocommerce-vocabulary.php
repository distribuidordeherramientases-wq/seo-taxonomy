<?php
/**
 * Plugin Name: TaxoCommerce Vocabulary
 * Description: Vocabulario semántico, atributos técnicos, clasificación asistida e importación/exportación para TaxoCommerce.
 * Version: 0.3.0
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Text Domain: taxocommerce-vocabulary
 */

defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_VOCABULARY_VERSION', '0.3.0' );
define( 'TAXOCOMMERCE_VOCABULARY_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_VOCABULARY_URL', plugin_dir_url( __FILE__ ) );

require_once TAXOCOMMERCE_VOCABULARY_PATH . 'includes/class-tcv-schema.php';
require_once TAXOCOMMERCE_VOCABULARY_PATH . 'includes/class-tcv-classifier.php';
require_once TAXOCOMMERCE_VOCABULARY_PATH . 'includes/class-tcv-io.php';
require_once TAXOCOMMERCE_VOCABULARY_PATH . 'includes/class-tcv-admin.php';

register_activation_hook( __FILE__, array( 'TCV_Schema', 'install' ) );

add_action(
    'plugins_loaded',
    static function () {
        TCV_Schema::maybe_upgrade();
        TCV_IO::boot();
        if ( is_admin() ) {
            TCV_Admin::boot();
        }
    }
);

<?php
/**
 * Plugin Name: TaxoCommerce FAQs
 * Description: Gestión de preguntas frecuentes, CSV, KPIs y etiquetas semánticas para la familia TaxoCommerce. Eliminar el plugin borra todas las FAQs.
 * Version: 0.1.3
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * Text Domain: taxocommerce-faqs
 */
defined( 'ABSPATH' ) || exit;
define( 'TAXOCOMMERCE_FAQS_VERSION', '0.1.3' );
define( 'TAXOCOMMERCE_FAQS_PATH', plugin_dir_path( __FILE__ ) );
require_once TAXOCOMMERCE_FAQS_PATH . 'includes/class-taxocommerce-faqs.php';
require_once TAXOCOMMERCE_FAQS_PATH . 'includes/class-taxocommerce-faqs-lifecycle.php';
register_activation_hook( __FILE__, array( 'TaxoCommerce_FAQs_Lifecycle', 'activate' ) );
add_action( 'admin_notices', array( 'TaxoCommerce_FAQs_Lifecycle', 'removal_notice' ) );
add_action( 'plugins_loaded', array( 'TaxoCommerce_FAQs', 'boot' ), 25 );
add_action( 'taxocommerce_core_register_modules', array( 'TaxoCommerce_FAQs', 'register_core' ) );

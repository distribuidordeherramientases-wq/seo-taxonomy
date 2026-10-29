<?php
/**
 * Plugin Name: TaxoCommerce FAQs
 * Description: Manage WordPress and WooCommerce FAQs with CSV exchange, KPIs and semantic labels. Uninstalling deletes stored FAQ data.
 * Version: 0.1.5
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-faqs
 */
defined( 'ABSPATH' ) || exit;
define( 'TAXOCOMMERCE_FAQS_VERSION', '0.1.5' );
define( 'TAXOCOMMERCE_FAQS_PATH', plugin_dir_path( __FILE__ ) );
require_once TAXOCOMMERCE_FAQS_PATH . 'includes/class-taxocommerce-faqs.php';
require_once TAXOCOMMERCE_FAQS_PATH . 'includes/class-taxocommerce-faqs-lifecycle.php';
require_once TAXOCOMMERCE_FAQS_PATH . 'includes/class-taxocommerce-faqs-auto-test.php';
register_activation_hook( __FILE__, array( 'TaxoCommerce_FAQs_Lifecycle', 'activate' ) );
add_action( 'admin_notices', array( 'TaxoCommerce_FAQs_Lifecycle', 'removal_notice' ) );
add_action( 'plugins_loaded', array( 'TaxoCommerce_FAQs', 'boot' ), 25 );
add_action( 'taxocommerce_core_register_modules', array( 'TaxoCommerce_FAQs', 'register_core' ) );
TaxoCommerce_FAQs_Auto_Test::register();

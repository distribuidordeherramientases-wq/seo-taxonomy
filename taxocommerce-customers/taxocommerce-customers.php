<?php
/**
 * Plugin Name: TaxoCommerce Customers
 * Plugin URI: https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/staging/taxocommerce-customers
 * Description: Private customer directory and CRM for WooCommerce buyers, guests and manually added contacts, with batch CSV import/export.
 * Version: 0.1.0
 * Release Date: 2026-11-13
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-customers
 * Domain Path: /languages
 */
defined( 'ABSPATH' ) || exit;

define( 'TCCUST_VERSION', '0.1.0' );
define( 'TCCUST_DIR', plugin_dir_path( __FILE__ ) );
define( 'TCCUST_URL', plugin_dir_url( __FILE__ ) );

require_once TCCUST_DIR . 'includes/class-tccust-db.php';
require_once TCCUST_DIR . 'includes/class-tccust-sync.php';
require_once TCCUST_DIR . 'includes/class-tccust-admin.php';
require_once TCCUST_DIR . 'includes/class-tccust-csv.php';
require_once TCCUST_DIR . 'includes/class-tccust-manual.php';
require_once TCCUST_DIR . 'includes/class-tccust-autotest.php';
require_once TCCUST_DIR . 'includes/class-tccust-privacy.php';
require_once TCCUST_DIR . 'includes/admin-branding.php';
require_once TCCUST_DIR . 'includes/class-taxocommerce-autotest-code.php';

register_activation_hook( __FILE__, array( 'TCCUST_DB', 'install' ) );

add_action( 'init', static function () {
	load_plugin_textdomain( 'taxocommerce-customers', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

add_action( 'plugins_loaded', static function () {
	TCCUST_Admin::boot();
	TCCUST_Sync::boot();
	TCCUST_CSV::boot();
	TCCUST_Privacy::boot();
	TCCUST_Autotest::boot();
	TaxoCommerce_Customers_Autotest_Code::boot();
} );

<?php
/**
 * Plugin Name: TaxoCommerce Core - Catalog & Content Management for WooCommerce
 * Description: Modular core for catalog hierarchy, unified content editing, logs, and extensions in WooCommerce stores.
 * Version: 0.4.17
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TAXOCOMMERCE_CORE_VERSION', '0.4.17' );
define( 'TAXOCOMMERCE_CORE_FILE', __FILE__ );
define( 'TAXOCOMMERCE_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_CORE_BASENAME', plugin_basename( __FILE__ ) );

require_once TAXOCOMMERCE_CORE_PATH . 'includes/class-taxocommerce-core-logger.php';
require_once TAXOCOMMERCE_CORE_PATH . 'includes/class-taxocommerce-core-hierarchy.php';
require_once TAXOCOMMERCE_CORE_PATH . 'includes/class-taxocommerce-core-hierarchy-classic.php';
require_once TAXOCOMMERCE_CORE_PATH . 'includes/class-taxocommerce-core-menu.php';
require_once TAXOCOMMERCE_CORE_PATH . 'includes/class-taxocommerce-core-vocabulary.php';
require_once TAXOCOMMERCE_CORE_PATH . 'includes/class-taxocommerce-core-editorial-guide.php';
require_once TAXOCOMMERCE_CORE_PATH . 'includes/class-taxocommerce-core-editor-ui.php';
require_once TAXOCOMMERCE_CORE_PATH . 'includes/class-taxocommerce-core-content-manager.php';
require_once TAXOCOMMERCE_CORE_PATH . 'includes/class-taxocommerce-core-anomalies.php';
require_once TAXOCOMMERCE_CORE_PATH . 'includes/class-taxocommerce-core-anomalies-json.php';
require_once TAXOCOMMERCE_CORE_PATH . 'includes/class-taxocommerce-core.php';
require_once TAXOCOMMERCE_CORE_PATH . 'includes/class-taxocommerce-core-dashboard.php';

/**
 * Returns the TaxoCommerce Core singleton.
 *
 * @return TaxoCommerce_Core
 */
function taxocommerce_core() {
	return TaxoCommerce_Core::instance();
}

/**
 * Boots TaxoCommerce Core after all plugins are loaded.
 *
 * @return void
 */
function taxocommerce_core_boot() {
	taxocommerce_core()->boot();
}
add_action( 'plugins_loaded', 'taxocommerce_core_boot', 20 );

/**
 * Activation tasks.
 *
 * @return void
 */
function taxocommerce_core_activate() {
	TaxoCommerce_Core_Hierarchy_Classic::install();
	update_option( 'taxocommerce_core_version', TAXOCOMMERCE_CORE_VERSION, false );

	if ( false === get_option( 'taxocommerce_core_log_limit', false ) ) {
		add_option( 'taxocommerce_core_log_limit', 100, '', false );
	}

	$logger = new TaxoCommerce_Core_Logger();
	$logger->info(
		'TaxoCommerce Core activated.',
		array( 'version' => TAXOCOMMERCE_CORE_VERSION )
	);
}
register_activation_hook( __FILE__, 'taxocommerce_core_activate' );

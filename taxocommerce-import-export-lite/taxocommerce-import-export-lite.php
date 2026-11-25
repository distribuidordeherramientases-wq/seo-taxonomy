<?php
/**
 * Plugin Name: TaxoCommerce Import-Export Lite
 * Plugin URI: https://asociacionfocazul.com/
 * Description: Independent CSV import and export for native WordPress content and WooCommerce catalog data. No other TaxoCommerce plugins required.
 * Version: 0.1.0
 * Release Date: 2026-11-25
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-import-export-lite
 * Domain Path: /languages
 */

namespace TaxoCommerce\ImportExportLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';
const SLUG = 'taxocommerce-import-export-lite';
const DOMAIN = 'taxocommerce-import-export-lite';
const RELEASE_DATE = '2026-11-25';

require_once __DIR__ . '/includes/class-csv.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-autocheck.php';
require_once __DIR__ . '/includes/class-manual.php';

add_action( 'plugins_loaded', static function () {
	Admin::register();
	AutoCheck::register();
}, 30 );

<?php
/**
 * Plugin Name: TaxoCommerce Import-Export Pro
 * Plugin URI: https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/staging/taxocommerce-import-export-pro
 * Description: Independent multi-file CSV imports and resumable exports for WordPress and WooCommerce, with workload regulation, history and code checks.
 * Version: 0.1.0
 * Release Date: 2026-11-26
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-import-export-pro
 * Domain Path: /languages
 */

namespace TaxoCommerce\ImportExportPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';
const SLUG = 'taxocommerce-import-export-pro';
const DOMAIN = 'taxocommerce-import-export-pro';
const RELEASE_DATE = '2026-11-26';

require_once __DIR__ . '/includes/class-header.php';
require_once __DIR__ . '/includes/class-native-fields.php';
require_once __DIR__ . '/includes/class-csv.php';
require_once __DIR__ . '/includes/class-load.php';
require_once __DIR__ . '/includes/class-batch.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-taxocommerce-autotest-code.php';
require_once __DIR__ . '/includes/class-autocheck.php';
require_once __DIR__ . '/includes/class-manual.php';

add_action( 'plugins_loaded', static function () {
	Admin::register();
	AutoCheck::register();
	Batch::register();
	\TaxoCommerce_Import_Export_Pro_Autotest_Code::boot();
}, 30 );

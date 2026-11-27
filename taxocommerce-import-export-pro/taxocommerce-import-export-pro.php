<?php
/**
 * Plugin Name: TaxoCommerce Import-Export Pro
 * Plugin URI: https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/staging/taxocommerce-import-export-pro
 * Description: Independent multi-file CSV imports and resumable exports for WordPress and WooCommerce, with workload regulation, history and code checks.
 * Version: 0.1.2
 * Release Date: 2026-11-27
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

const VERSION = '0.1.2';
const SLUG = 'taxocommerce-import-export-pro';
// Distinct admin route also avoids prefix matching by older Import-Export releases.
const ADMIN_SLUG = 'taxocommerce-ie-pro';
const DOMAIN = 'taxocommerce-import-export-pro';
const RELEASE_DATE = '2026-11-27';

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

register_deactivation_hook( __FILE__, static function () {
	wp_unschedule_hook( Batch::HOOK );
} );

/** Optional Core integration. Pro remains fully usable without Core. */
add_filter( 'taxocommerce_core_family_extensions', static function ( $extensions ) {
	$extensions = is_array( $extensions ) ? $extensions : array();
	$extensions['import_export_pro'] = array(
		'id'            => 'import_export_pro',
		'name'          => 'TaxoCommerce Import-Export Pro',
		'plugin_file'   => 'taxocommerce-import-export-pro/taxocommerce-import-export-pro.php',
		'org_slug'      => SLUG,
		'search_term'   => 'TaxoCommerce Import-Export Pro',
		'wordpress_org' => false,
		'admin_url'     => Admin::url(),
		'description'   => __( 'CSV import and export by content type, multi-file batches and workload regulation.', 'taxocommerce-import-export-pro' ),
	);
	return $extensions;
} );

add_action( 'taxocommerce_core_register_modules', static function ( $core ) {
	if ( is_object( $core ) && is_callable( array( $core, 'register_module' ) ) ) {
		$core->register_module( 'import_export_pro', array(
			'name'        => 'TaxoCommerce Import-Export Pro',
			'version'     => VERSION,
			'status'      => 'active',
			'description' => __( 'CSV import and export by content type, multi-file batches and workload regulation.', 'taxocommerce-import-export-pro' ),
			'admin_url'   => Admin::url(),
		) );
	}
} );

<?php
/**
 * Plugin Name: TaxoCommerce Import-Export Lite
 * Plugin URI: https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/staging/taxocommerce-import-export-lite
 * Description: Independent CSV import and export for native WordPress content and WooCommerce catalog data. No other TaxoCommerce plugins required.
 * Version: 0.1.8
 * Release Date: 2026-11-27
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

const VERSION = '0.1.8';
const SLUG = 'taxocommerce-import-export-lite';
const DOMAIN = 'taxocommerce-import-export-lite';
const RELEASE_DATE = '2026-11-27';

require_once __DIR__ . '/includes/class-header.php';
require_once __DIR__ . '/includes/class-native-fields.php';
require_once __DIR__ . '/includes/class-csv.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-autocheck.php';
require_once __DIR__ . '/includes/class-manual.php';

add_action( 'plugins_loaded', static function () {
	Admin::register();
	AutoCheck::register();
}, 30 );

// Prefer official language packs; use bundled catalogs in standalone installations.
add_action( 'init', static function () {
	if ( is_textdomain_loaded( 'taxocommerce-import-export-lite' ) ) return;
	$locale = sanitize_file_name( determine_locale() );
	$official = WP_LANG_DIR . '/plugins/taxocommerce-import-export-lite-' . $locale;
	if ( is_readable( $official . '.mo' ) || is_readable( $official . '.l10n.php' ) ) return;
	$fallbacks = array( 'es' => 'es_ES', 'en' => 'en_US', 'fr' => 'fr_FR', 'de' => 'de_DE', 'nl' => 'nl_NL' );
	$language = substr( strtolower( $locale ), 0, 2 );
	$catalog = __DIR__ . '/languages/taxocommerce-import-export-lite-' . ( $fallbacks[ $language ] ?? $locale ) . '.mo';
	if ( is_readable( $catalog ) ) load_textdomain( 'taxocommerce-import-export-lite', $catalog, $locale );
}, 0 );

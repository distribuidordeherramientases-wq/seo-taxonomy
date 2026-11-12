<?php
$root = dirname( __DIR__ );

function tcsup_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$main = file_get_contents( $root . '/taxocommerce-suppliers.php' );
$csv  = file_get_contents( $root . '/includes/class-tcsup-csv.php' );
$admin = file_get_contents( $root . '/includes/class-tcsup-admin.php' );
$products = file_get_contents( $root . '/includes/class-tcsup-products.php' );
$history = file_get_contents( $root . '/includes/class-tcsup-price-history.php' );
$schema = file_get_contents( $root . '/includes/class-tcsup-schema.php' );
$purchasing = file_get_contents( $root . '/includes/class-tcsup-purchasing.php' );
$manual = file_get_contents( $root . '/includes/class-tcsup-manual.php' );
$autotest = file_get_contents( $root . '/includes/class-tcsup-autotest.php' );

tcsup_assert( false !== strpos( $main, "Plugin Name: TaxoCommerce Suppliers" ), 'plugin header' );
tcsup_assert( false !== strpos( $main, "taxocommerce_suppliers_get_sources" ), 'supplier source service' );
tcsup_assert( false !== strpos( $csv, "Only CSV" ) || false !== strpos( $admin, "Only CSV" ), 'CSV-only scope' );
tcsup_assert( false === stripos( $root . $main . $csv . $admin . $products, 'crawler-queue.php' ), 'no crawler queue dependency' );
tcsup_assert( false === stripos( $main . $csv . $admin . $products, 'github-python-runner' ), 'no GitHub runner dependency' );
tcsup_assert( false === stripos( $main . $csv . $admin . $products, 'google-python-runner' ), 'no Cloud runner dependency' );
tcsup_assert( false !== strpos( $admin, "External Images" ), 'external image administration' );
tcsup_assert( false !== strpos( $products, "estado_seleccion" ), 'intermediate selection states' );
tcsup_assert( false !== strpos( $products, "precio_con_iva" ), 'supplier price support' );
tcsup_assert( false !== strpos( $schema, 'seo_proveedores_productos_historico' ), 'history table is installed' );
tcsup_assert( false !== strpos( $history, 'woocommerce_after_product_object_save' ) || false !== strpos( $main, 'woocommerce_after_product_object_save' ), 'WooCommerce price capture hook' );
tcsup_assert( false !== strpos( $csv, 'TCSUP_Price_History::capture' ), 'CSV price history' );
tcsup_assert( false !== strpos( $admin, 'TCSUP_Price_History::recent' ), 'read-only inventory history view' );
tcsup_assert( false !== strpos( $main, "class-tcsup-purchasing.php" ), 'purchasing bootstrap' );
tcsup_assert( false !== strpos( $purchasing, "wc_get_orders" ), 'WooCommerce sales intake' );
tcsup_assert( false !== strpos( $purchasing, "sources_for_product" ), 'supplier source resolution' );
tcsup_assert( false !== strpos( $purchasing, "Purchase" ), 'supplier purchasing action' );

tcsup_assert( false !== strpos( $main, 'class-tcsup-manual.php' ), 'manual loaded' );
tcsup_assert( false !== strpos( $main, 'class-tcsup-autotest.php' ), 'autotest loaded' );
tcsup_assert( false !== strpos( $admin, "'manual' => __( 'Manual', 'taxocommerce-suppliers' )" ), 'manual tab' );
tcsup_assert( false !== strpos( $admin, "'autotest' => __( 'Autocheck', 'taxocommerce-suppliers' )" ), 'autotest tab' );
tcsup_assert( false !== strpos( $manual, "esc_html_e( 'Manual de usuario', 'taxocommerce-suppliers' )" ), 'full user manual heading' );
tcsup_assert( false !== strpos( $manual, "esc_html_e( 'Manual técnico para futuros desarrolladores', 'taxocommerce-suppliers' )" ), 'full developer manual heading' );
tcsup_assert( substr_count( $manual, 'class="tcsup-manual-section"' ) >= 16, 'comprehensive indexed manual sections' );
tcsup_assert( false !== strpos( $manual, 'tcsup-manual-index' ) && false !== strpos( $manual, 'tcsup-manual-scroll' ), 'manual navigation and tables' );
tcsup_assert( false !== strpos( $manual, 'class-tcsup-schema.php' ) && false !== strpos( $manual, 'taxocommerce_suppliers_get_sources' ), 'developer architecture and public contracts' );
tcsup_assert( false !== strpos( $manual, 'borrador' ) && false !== strpos( $manual, 'Apply Woo prices' ), 'user side effects documented' );
tcsup_assert( false !== strpos( $autotest, "check_admin_referer( 'tcsup_autotest' )" ), 'autotest nonce' );
tcsup_assert( false !== strpos( $autotest, 'SHOW TABLES LIKE' ), 'read-only schema check' );
tcsup_assert( false !== strpos( $main, " FROM %i" ), 'supplier image identifiers must be prepared' );
tcsup_assert( false === strpos( $main, 'FROM {$table}' ), 'no unprepared image table names' );
tcsup_assert( false !== strpos( $csv, "check_admin_referer( 'tcsup_import_csv' )" ), 'CSV import must verify nonce in handler' );
tcsup_assert( false !== strpos( $products, "check_admin_referer( 'tcsup_price_update' )" ), 'price edits must verify nonce in handler' );
tcsup_assert( false !== strpos( $products, "sanitize_textarea_field( wp_unslash( \$_POST['image_urls'] ) )" ), 'image URLs must be sanitized' );
tcsup_assert( false !== strpos( $csv, "map_deep( wp_unslash( \$_POST['mapping'] ), 'sanitize_text_field' )" ), 'CSV mapping must be sanitized' );
tcsup_assert( false !== strpos( $admin, 'tcsup_create_template' ), 'standalone template creation form' );
tcsup_assert( false !== strpos( $admin, 'tcsup-template-create' ), 'collapsible template creation UI' );
tcsup_assert( false !== strpos( $csv, 'parse_template_definition' ), 'safe mapping definition parser' );
tcsup_assert( false !== strpos( $csv, 'handle_export_template' ), 'JSON export control' );
tcsup_assert( false !== strpos( $admin, "'import'    => __( 'CSV Import', 'taxocommerce-suppliers' )" ), 'native gettext admin tab' );

// Regression: a clean WordPress installation has zero supplier templates.
// The canonical and price-only choices are generic import formats, not presets.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
if ( ! function_exists( '__' ) ) {
	function __( $message, $domain = 'default' ) {
		return $message;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return isset( $GLOBALS['tcsup_smoke_options'][ $key ] )
			? $GLOBALS['tcsup_smoke_options'][ $key ] : $default;
	}
}
require_once $root . '/includes/class-tcsup-csv.php';
$GLOBALS['tcsup_smoke_options'] = array();
$generic_formats = TCSUP_CSV::builtin_templates();
tcsup_assert( array( 'canonical', 'price_update' ) === array_keys( $generic_formats ), 'only generic import formats ship with the plugin' );
tcsup_assert( array() === TCSUP_CSV::templates(), 'fresh store has zero supplier templates' );
tcsup_assert( count( TCSUP_CSV::import_templates() ) === 2, 'canonical and price update remain available to new stores' );
$GLOBALS['tcsup_smoke_options'][ TCSUP_CSV::TEMPLATE_OPTION ] = array(
	'custom_local' => array( 'id' => 'custom_local', 'name' => 'My store mapping', 'builtin' => false, 'mode' => 'mapping' ),
	'vevor' => array( 'id' => 'vevor', 'name' => 'Legacy provider preset', 'builtin' => true ),
);
tcsup_assert( array( 'custom_local' ) === array_keys( TCSUP_CSV::templates() ), 'only locally saved custom templates are listed' );
tcsup_assert( count( TCSUP_CSV::import_templates() ) === 3, 'custom mapping remains importable with generic formats' );
$regional_header = array( 'Name', 'URL to product', 'categoryPath', 'URL to image', 'Currency', 'Sales' );
tcsup_assert( 'vevor' === TCSUP_CSV::effective_mode( $generic_formats['canonical'], $regional_header, 'VEVOR' ), 'existing regional CSV parser is preserved without a preset' );
tcsup_assert( 'canonical' === TCSUP_CSV::effective_mode( $generic_formats['canonical'], $regional_header, 'Another supplier' ), 'regional parser is never imposed on unrelated suppliers' );
tcsup_assert( 'canonical' === TCSUP_CSV::effective_mode( $generic_formats['canonical'], array( 'id', 'name' ), 'VEVOR' ), 'regional parser requires official header' );
tcsup_assert( false === strpos( $admin, 'placeholder="e.g. SATKIT"' ), 'no foreign supplier example is prefilled in new stores' );
tcsup_assert( false !== strpos( $admin, 'No templates have been created on this site.' ), 'empty template list has its own message' );

require_once __DIR__ . '/i18n-smoke.php';
echo "OK\n";

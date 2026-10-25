<?php
/**
 * Plugin Name: TaxoCommerce Suppliers
 * Description: Supplier catalogue intake, purchasing, product decisions, supplier prices, WooCommerce linking, and external image references.
 * Version: 0.2.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-suppliers
 */

defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_SUPPLIERS_VERSION', '0.2.0' );
define( 'TAXOCOMMERCE_SUPPLIERS_FILE', __FILE__ );
define( 'TAXOCOMMERCE_SUPPLIERS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_SUPPLIERS_URL', plugin_dir_url( __FILE__ ) );

require_once TAXOCOMMERCE_SUPPLIERS_DIR . 'includes/class-tcsup-schema.php';
require_once TAXOCOMMERCE_SUPPLIERS_DIR . 'includes/class-tcsup-csv.php';
require_once TAXOCOMMERCE_SUPPLIERS_DIR . 'includes/class-tcsup-products.php';
require_once TAXOCOMMERCE_SUPPLIERS_DIR . 'includes/class-tcsup-purchasing.php';
require_once TAXOCOMMERCE_SUPPLIERS_DIR . 'includes/class-tcsup-admin.php';

function taxocommerce_suppliers_activate() {
	TCSUP_Schema::install();
	if ( false === get_option( 'taxocommerce_suppliers_sale_margin', false ) ) {
		add_option( 'taxocommerce_suppliers_sale_margin', 20, '', false );
	}
	if ( false === get_option( 'taxocommerce_suppliers_regular_margin', false ) ) {
		add_option( 'taxocommerce_suppliers_regular_margin', 45, '', false );
	}
}
register_activation_hook( __FILE__, 'taxocommerce_suppliers_activate' );

function taxocommerce_suppliers_boot() {
	TCSUP_Schema::maybe_install();
	TCSUP_Admin::boot();
}
add_action( 'plugins_loaded', 'taxocommerce_suppliers_boot', 30 );

function taxocommerce_suppliers_register_core( $core ) {
	if ( ! is_object( $core ) || ! method_exists( $core, 'register_module' ) ) {
		return;
	}
	$core->register_module(
		'suppliers',
		array(
			'name'        => 'TaxoCommerce Suppliers',
			'version'     => TAXOCOMMERCE_SUPPLIERS_VERSION,
			'status'      => 'active',
			'description' => 'Supplier catalogue, purchasing, prices, and external images.',
			'admin_url'   => admin_url( 'admin.php?page=taxocommerce-suppliers' ),
		)
	);
}
add_action( 'taxocommerce_core_register_modules', 'taxocommerce_suppliers_register_core' );
if ( function_exists( 'taxocommerce_core' ) && did_action( 'taxocommerce_core_loaded' ) ) {
	taxocommerce_suppliers_register_core( taxocommerce_core() );
}

function taxocommerce_suppliers_family_catalog( $extensions ) {
	$extensions = is_array( $extensions ) ? $extensions : array();
	$extensions['suppliers'] = array(
		'id'            => 'suppliers',
		'name'          => 'TaxoCommerce Suppliers',
		'plugin_file'   => 'taxocommerce-suppliers/taxocommerce-suppliers.php',
		'org_slug'      => 'taxocommerce-suppliers',
		'search_term'   => 'TaxoCommerce Suppliers',
		'wordpress_org' => false,
		'admin_url'     => admin_url( 'admin.php?page=taxocommerce-suppliers' ),
		'description'   => 'Supplier catalogue, purchasing, prices, and external images.',
	);
	return $extensions;
}
add_filter( 'taxocommerce_core_family_extensions', 'taxocommerce_suppliers_family_catalog' );

function taxocommerce_suppliers_get_sources( $product_id ) {
	return TCSUP_Products::sources_for_product( absint( $product_id ) );
}

function taxocommerce_suppliers_get_external_images( $product_id ) {
	global $wpdb;
	$table = TCSUP_Schema::images_table();
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT supplier,supplier_product_id,supplier_sku,product_id,catalog_row_id,image_url,position,is_primary
			 FROM {$table}
			 WHERE product_id=%d AND status='active'
			 ORDER BY is_primary DESC,position ASC,id ASC",
			absint( $product_id )
		),
		ARRAY_A
	);
}

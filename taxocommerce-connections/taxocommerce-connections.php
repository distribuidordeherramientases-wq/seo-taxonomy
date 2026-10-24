<?php
/**
 * Plugin Name: TaxoCommerce Connections
 * Description: Shared connection layer for Google, Bing, Amazon, Pinterest, and future TaxoCommerce integrations.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-connections
 */

defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_CONNECTIONS_VERSION', '0.1.0' );
define( 'TAXOCOMMERCE_CONNECTIONS_FILE', __FILE__ );
define( 'TAXOCOMMERCE_CONNECTIONS_PATH', plugin_dir_path( __FILE__ ) );

require_once TAXOCOMMERCE_CONNECTIONS_PATH . 'includes/class-taxocommerce-connections-amazon.php';
require_once TAXOCOMMERCE_CONNECTIONS_PATH . 'includes/class-taxocommerce-connections-pinterest.php';
require_once TAXOCOMMERCE_CONNECTIONS_PATH . 'includes/class-taxocommerce-connections-google-bing.php';
require_once TAXOCOMMERCE_CONNECTIONS_PATH . 'includes/class-taxocommerce-connections-admin.php';

add_action(
	'plugins_loaded',
	static function () {
		TaxoCommerce_Connections_Amazon::boot();
		TaxoCommerce_Connections_Pinterest::boot();
		TaxoCommerce_Connections_Google_Bing::boot();
		TaxoCommerce_Connections_Admin::boot();
	},
	60
);

add_filter(
	'taxocommerce_core_family_extensions',
	static function ( $extensions ) {
		if ( ! is_array( $extensions ) ) {
			$extensions = array();
		}
		$extensions['connections'] = array(
			'id'            => 'connections',
			'name'          => 'TaxoCommerce Connections',
			'plugin_file'   => 'taxocommerce-connections/taxocommerce-connections.php',
			'org_slug'      => 'taxocommerce-connections',
			'search_term'   => 'TaxoCommerce Connections',
			'wordpress_org' => false,
			'admin_url'     => admin_url( 'admin.php?page=taxocommerce-connections' ),
			'description'   => __( 'Shared TaxoCommerce connection layer for Google, Bing, Amazon, Pinterest, and future integrations.', 'taxocommerce-connections' ),
		);
		return $extensions;
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		TaxoCommerce_Connections_Amazon::migrate_legacy_settings();
		TaxoCommerce_Connections_Pinterest::migrate_legacy_settings();
	}
);

<?php
/**
 * Plugin Name: TaxoCommerce Images - Image Management for WooCommerce
 * Description: Gestiona, audita y optimiza imágenes de WordPress/WooCommerce manteniendo Media como fuente de verdad.
 * Version: 0.2.3
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-images
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TAXOCOMMERCE_IMAGES_VERSION', '0.2.3' );
define( 'TAXOCOMMERCE_IMAGES_FILE', __FILE__ );
define( 'TAXOCOMMERCE_IMAGES_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_IMAGES_BASENAME', plugin_basename( __FILE__ ) );

require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/legacy-reference-audit.php';
require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/legacy-optimizer-engine.php';
require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/legacy-webp-engine.php';
require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/class-taxocommerce-images-inventory.php';
require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/legacy-optimizer-ajax.php';
require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/class-taxocommerce-images-audit.php';
require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/class-taxocommerce-images-actions.php';
require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/class-taxocommerce-images-thumbnails.php';
require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/class-taxocommerce-images-assignment.php';
require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/class-taxocommerce-images.php';
require_once TAXOCOMMERCE_IMAGES_PATH . 'includes/class-taxocommerce-images-maintenance.php';

TaxoCommerce_Images_Thumbnails::boot();
TaxoCommerce_Images_Assignment::boot();
TaxoCommerce_Images_Maintenance::boot();
TaxoCommerce_Images::instance()->boot();

register_activation_hook(
	__FILE__,
	static function () {
		update_option( 'taxocommerce_images_version', TAXOCOMMERCE_IMAGES_VERSION, false );
		if ( false === get_option( TaxoCommerce_Images_Actions::SETTINGS_OPTION, false ) ) {
			add_option( TaxoCommerce_Images_Actions::SETTINGS_OPTION, TaxoCommerce_Images_Actions::default_settings(), '', false );
		}
	}
);

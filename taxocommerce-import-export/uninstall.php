<?php
/**
 * TaxoCommerce Import-Export uninstall.
 *
 * @package TaxoCommerceImportExport
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * The plugin stores no canonical catalog/content data of its own.
 * WordPress/WooCommerce records and Media are never removed on uninstall.
 */

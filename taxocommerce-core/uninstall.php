<?php
/**
 * TaxoCommerce Core uninstall.
 *
 * @package TaxoCommerceCore
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Only Core-owned pilot options are removed.
 * Canonical WordPress/WooCommerce content is never deleted.
 */
delete_option( 'taxocommerce_core_version' );
delete_option( 'taxocommerce_core_log_limit' );
delete_option( 'taxocommerce_core_log_entries' );
delete_option( 'taxocommerce_core_menu_id' );
delete_option( 'taxocommerce_core_menu_location_backup' );

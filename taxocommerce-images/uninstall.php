<?php
/**
 * TaxoCommerce Images uninstall.
 *
 * @package TaxoCommerceImages
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * Only plugin-owned configuration and derived audit state are removed.
 * WordPress Media attachments and files are never deleted on uninstall.
 */
delete_option( 'taxocommerce_images_version' );
delete_option( 'taxocommerce_images_settings' );
delete_option( 'taxocommerce_images_audit_state' );
delete_option( 'taxocommerce_images_audit_lock' );

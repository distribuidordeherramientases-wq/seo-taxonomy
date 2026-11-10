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


/*
 * Clean only Core's developer-excerpt cache. Never touch another TaxoCommerce
 * extension's URL or the store's posts, orders or navigation.
 */
$tc_core_headers = get_file_data(
    __DIR__ . '/taxocommerce-core.php',
    array( 'uri' => 'Plugin URI' ),
    'plugin'
);
$tc_core_uri = isset( $tc_core_headers['uri'] ) ? esc_url_raw( $tc_core_headers['uri'], array( 'https' ) ) : '';
if ( $tc_core_uri ) {
    $tc_core_hash = md5( $tc_core_uri );
    delete_option( 'tc_official_excerpt_good_' . $tc_core_hash );
    delete_transient( 'tc_official_excerpt_v2_' . $tc_core_hash );
    delete_transient( 'tc_official_excerpt_' . $tc_core_hash );
}

<?php
/**
 * Remove only this plugin's own lightweight local snapshots and event history.
 * Never delete WordPress/WooCommerce/TaxoCommerce data tables or legacy data.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'tcdb_last_audit_v1' );
delete_option( 'tcdb_audit_history_v1' );
delete_option( 'tcdb_operations_v1' );

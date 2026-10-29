<?php
/**
 * Remove data exclusively owned by TaxoCommerce Google/Bing on uninstall.
 *
 * Deactivation does not invoke this file.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

wp_clear_scheduled_hook( 'tgb_market_queue_tick' );

foreach ( array( 'tgb_market_sources', 'tgb_market_runs', 'tgb_market_categories' ) as $taxocommerce_google_bing_suffix ) {
    $taxocommerce_google_bing_table = $wpdb->prefix . $taxocommerce_google_bing_suffix;
    // Table identifiers cannot be bound as SQL values; only a fixed allowlist is used.
    $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $taxocommerce_google_bing_table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
}

foreach ( array(
    'tgb_market_schema_version',
    'tgb_market_category_cursor',
    'tgb_market_bootstrap',
    'tgb_market_queue_active',
    'tgb_market_daily_budget',
    'tgb_market_youtube_key',
    'tgb_market_serpapi_key',
    'taxocommerce_google_bing_google_settings',
    'taxocommerce_google_bing_bing_settings',
    'taxocommerce_google_bing_merchant_account',
    'taxocommerce_google_bing_cloud_run_settings',
    'taxocommerce_google_bing_connections_migration_v1',
    'taxocommerce_google_bing_oauth_settings',
    'taxocommerce_google_bing_oauth_tokens',
    'taxocommerce_google_bing_oauth_migration_v1',
) as $taxocommerce_google_bing_option ) {
    delete_option( $taxocommerce_google_bing_option );
}

// Only this plugin's prefixed options/transients. Never remove legacy settings
// copied from other plugins, nor WooCommerce/WordPress core data.
$taxocommerce_google_bing_patterns = array(
    $wpdb->esc_like( 'tgb_' ) . '%',
    $wpdb->esc_like( 'taxocommerce_google_bing_' ) . '%',
    $wpdb->esc_like( '_transient_tgb_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_tgb_' ) . '%',
);
foreach ( $taxocommerce_google_bing_patterns as $taxocommerce_google_bing_pattern ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Native live read/write of plugin-owned tables, retaining immediate reporting consistency; query values are prepared.
    $taxocommerce_google_bing_names = $wpdb->get_col( $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
        $taxocommerce_google_bing_pattern
    ) );
    foreach ( (array) $taxocommerce_google_bing_names as $taxocommerce_google_bing_name ) {
        delete_option( $taxocommerce_google_bing_name );
    }
}

<?php
/**
 * Standalone lifecycle test. Does not require WordPress or contact a provider.
 *
 * @package TaxoCommerceScrappers
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['scrappers_hooks'] = array();
$GLOBALS['scrappers_actions'] = array();
$GLOBALS['scrappers_legacy_active'] = false;
$GLOBALS['scrappers_deactivate'] = null;

function plugin_dir_path( $path ) { return dirname( $path ) . '/'; }
// Native WordPress function required by the standalone admin header at load.
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function plugin_dir_url( $path ) { return 'https://example.test/wp-content/plugins/' . basename( dirname( $path ) ) . '/'; }
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function register_deactivation_hook( $path, $callback ) { $GLOBALS['scrappers_deactivate'] = $callback; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['scrappers_hooks'][] = $hook; return true; }
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
    $GLOBALS['scrappers_actions'][] = array( $hook, $args, $group );
}
function is_plugin_active( $slug ) {
    if ( 'seo-taxonomy/seo-taxonomy.php' !== $slug ) {
        throw new RuntimeException( 'Unrelated plugin queried on deactivation' );
    }
    return $GLOBALS['scrappers_legacy_active'];
}
// No DB writes or deletions are permitted during deactivation.
function delete_option() { throw new RuntimeException( 'Store options must be preserved' ); }
function delete_site_option() { throw new RuntimeException( 'Site options must be preserved' ); }
function wpdb_delete() { throw new RuntimeException( 'Store tables must be preserved' ); }
class SEO_Ojeador_Admin {}
class SEO_Ingeniero {}

require dirname( __DIR__ ) . '/taxocommerce-scrappers.php';

if ( ! is_callable( $GLOBALS['scrappers_deactivate'] ) ) {
    fwrite( STDERR, "Scrappers did not register its deactivation hook.\n" );
    exit( 1 );
}
call_user_func( $GLOBALS['scrappers_deactivate'] );
$expected = array(
    'seo_ojeador_market_tick',
    'seo_ingeniero_worker_tick',
    'seo_ingeniero_editorial_refresh',
    'seo_ingeniero_editorial_refresh_step',
    'seo_ingeniero_external_radar_tick',
);
if ( $expected !== $GLOBALS['scrappers_hooks'] ) {
    fwrite( STDERR, "Wrong WP-Cron cleanup scope.\n" );
    exit( 1 );
}
if ( array( array( 'seo_ingeniero_worker_tick', array(), 'seo-ingeniero' ) ) !== $GLOBALS['scrappers_actions'] ) {
    fwrite( STDERR, "Action Scheduler cleanup must only target Ingeniero's group.\n" );
    exit( 1 );
}

// A temporarily active legacy implementation may share the existing hooks.
// Do not cancel its work merely because the standalone plugin is disabled.
$GLOBALS['scrappers_legacy_active'] = true;
call_user_func( $GLOBALS['scrappers_deactivate'] );
if ( count( $GLOBALS['scrappers_hooks'] ) !== 5 || count( $GLOBALS['scrappers_actions'] ) !== 1 ) {
    fwrite( STDERR, "A shared background job was modified during coexistence.\n" );
    exit( 1 );
}
echo "TaxoCommerce Scrappers lifecycle cleanup: PASS\n";

<?php
/**
 * Regression for the daily sitemap refresh without WordPress or site writes.
 * Run normally and with "legacy" to verify safe coexistence.
 */
define( 'ABSPATH', __DIR__ );
define( 'TCDIST_LEGACY_SITEMAP_ACTIVE', in_array( 'legacy', $argv ?? array(), true ) );
$GLOBALS['events'] = array();
$GLOBALS['scheduled_count'] = 0;
$GLOBALS['builds'] = 0;
$GLOBALS['job_status'] = '';
function add_action( $name, $callback, $priority = 10 ) {}
function wp_next_scheduled( $name ) { return $GLOBALS['events'][ $name ] ?? false; }
function wp_schedule_event( $time, $recurrence, $name ) {
    if ( 'daily' !== $recurrence ) { throw new RuntimeException( 'Not daily' ); }
    $GLOBALS['events'][ $name ] = $time;
    ++$GLOBALS['scheduled_count'];
    return true;
}
function wp_clear_scheduled_hook( $name ) { unset( $GLOBALS['events'][ $name ] ); }
function tcdist_sitemap_job_state() { return array( 'status' => $GLOBALS['job_status'] ); }
function tcdist_sitemap_start_background_build() {
    ++$GLOBALS['builds'];
    return array( 'type' => 'success' );
}
function check_daily( $test, $label ) {
    if ( ! $test ) { fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL ); exit( 1 ); }
}
require dirname( __DIR__ ) . '/includes/sitemaps/daily.php';
tcdist_sitemap_ensure_daily_refresh();
$hook = TCDIST_SITEMAP_DAILY_HOOK;
if ( TCDIST_LEGACY_SITEMAP_ACTIVE ) {
    check_daily( 0 === $GLOBALS['scheduled_count'], 'Legacy owner prevents duplicate cron' );
    $GLOBALS['events'][ $hook ] = time() + 1;
    tcdist_sitemap_ensure_daily_refresh();
    check_daily( ! isset( $GLOBALS['events'][ $hook ] ), 'Legacy owner removes previously scheduled cron' );
    tcdist_sitemap_daily_refresh();
    check_daily( 0 === $GLOBALS['builds'], 'Legacy owner prevents concurrent rebuild' );
    echo "Sitemap daily legacy coexistence: PASS\n";
    exit;
}
check_daily( isset( $GLOBALS['events'][ $hook ] ), 'Daily refresh scheduled' );
tcdist_sitemap_ensure_daily_refresh();
check_daily( 1 === $GLOBALS['scheduled_count'], 'No duplicate recurring events' );
$GLOBALS['job_status'] = 'running';
tcdist_sitemap_daily_refresh();
check_daily( 0 === $GLOBALS['builds'], 'Existing worker is not duplicated' );
$GLOBALS['job_status'] = 'completed';
$GLOBALS['events']['tcdist_sitemap_regenerate_sitemaps_event'] = time() + 10;
tcdist_sitemap_daily_refresh();
check_daily( 0 === $GLOBALS['builds'], 'Pending content refresh is not duplicated' );
unset( $GLOBALS['events']['tcdist_sitemap_regenerate_sitemaps_event'] );
tcdist_sitemap_daily_refresh();
check_daily( 1 === $GLOBALS['builds'], 'Idle worker starts a bounded sitemap build' );
tcdist_sitemap_clear_daily_refresh();
check_daily( ! isset( $GLOBALS['events'][ $hook ] ), 'Deactivate cleans daily recurrence' );
echo "Sitemap daily refresh: PASS\n";

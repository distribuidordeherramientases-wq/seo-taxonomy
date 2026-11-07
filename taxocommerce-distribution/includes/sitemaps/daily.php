<?php
/**
 * Daily XML sitemap refresh through the existing bounded worker.
 *
 * @package TaxoCommerce_Distribution
 */

defined( 'ABSPATH' ) || exit;

const TCDIST_SITEMAP_DAILY_HOOK = 'tcdist_sitemap_daily_refresh';

/**
 * Schedule one daily refresh when Distribution owns the sitemap endpoint.
 * WordPress-Cron depends on site traffic or an external cron runner.
 *
 * @return void
 */
function tcdist_sitemap_ensure_daily_refresh() {
    $next = wp_next_scheduled( TCDIST_SITEMAP_DAILY_HOOK );

    // Legacy plugin owns this endpoint in coexistence mode.
    if ( defined( 'TCDIST_LEGACY_SITEMAP_ACTIVE' ) && TCDIST_LEGACY_SITEMAP_ACTIVE ) {
        if ( $next ) {
            wp_clear_scheduled_hook( TCDIST_SITEMAP_DAILY_HOOK );
        }
        return;
    }

    if ( ! $next ) {
        wp_schedule_event( time() + 600, 'daily', TCDIST_SITEMAP_DAILY_HOOK );
    }
}
add_action( 'init', 'tcdist_sitemap_ensure_daily_refresh', 35 );

/**
 * Queue a rebuild without holding PHP open or racing an existing worker.
 * Never trigger a second build while a previous run or content-change
 * regeneration is pending.
 *
 * @return void
 */
function tcdist_sitemap_daily_refresh() {
    if ( defined( 'TCDIST_LEGACY_SITEMAP_ACTIVE' ) && TCDIST_LEGACY_SITEMAP_ACTIVE ) {
        return;
    }

    $state = tcdist_sitemap_job_state();
    if ( 'running' === ( $state['status'] ?? '' ) ||
        wp_next_scheduled( 'tcdist_sitemap_regenerate_sitemaps_event' ) ) {
        return;
    }

    tcdist_sitemap_start_background_build();
}
add_action( TCDIST_SITEMAP_DAILY_HOOK, 'tcdist_sitemap_daily_refresh' );

/**
 * Remove only the recurrence owned by this module on deactivation.
 *
 * @return void
 */
function tcdist_sitemap_clear_daily_refresh() {
    wp_clear_scheduled_hook( TCDIST_SITEMAP_DAILY_HOOK );
}

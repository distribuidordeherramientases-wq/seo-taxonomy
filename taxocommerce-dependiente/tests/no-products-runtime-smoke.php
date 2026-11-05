<?php
/**
 * Offline regression for an empty WooCommerce store and a frozen snapshot.
 * No WordPress installation, product, database or live background job changes.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['tcd_opts'] = array();
$GLOBALS['tcd_products'] = array();
$GLOBALS['tcd_scheduled'] = array();
$GLOBALS['tcd_has_product_type'] = true;
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['tcd_opts'] ) ? $GLOBALS['tcd_opts'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
    $GLOBALS['tcd_opts'][ $name ] = $value;
    return true;
}
function delete_option( $name ) {
    unset( $GLOBALS['tcd_opts'][ $name ] );
    return true;
}
function wp_parse_args( $args, $defaults = array() ) {
    return array_merge( (array) $defaults, (array) $args );
}
function absint( $v ) { return max( 0, (int) $v ); }
function current_time( $format ) { return '2026-11-05 20:00:00'; }
function post_type_exists( $type ) {
    return 'product' === $type && $GLOBALS['tcd_has_product_type'];
}
function get_posts( $args ) {
    $GLOBALS['tcd_product_query'] = $args;
    return $GLOBALS['tcd_products'];
}
function sanitize_key( $value ) {
    return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
}
function sanitize_text_field( $value ) { return (string) $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_next_scheduled( $hook ) {
    return $GLOBALS['tcd_scheduled'][ $hook ] ?? false;
}
function wp_schedule_single_event( $when, $hook ) {
    $GLOBALS['tcd_scheduled'][ $hook ] = $when;
    return true;
}
function wp_clear_scheduled_hook( $hook ) {
    unset( $GLOBALS['tcd_scheduled'][ $hook ] );
}
function check_tcd( $value, $message ) {
    if ( ! $value ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
}
require_once dirname( __DIR__ ) . '/includes/class-tcd-academy.php';
$state = array(
    'run_id' => 'empty-site', 'lesson' => 1, 'status' => 'running',
    'phase' => 'snapshot', 'snapshot_id' => 0, 'snapshot_cursor' => 0,
    'learning_cursor' => 0, 'processed' => 0, 'learned' => 0,
);
$GLOBALS['tcd_opts'][ TCD_Academy::STATE ] = $state;
$GLOBALS['tcd_scheduled'][ TCD_Academy::CRON_HOOK ] = time() + 30;
check_tcd( ! TCD_Academy::has_published_catalog_products(), 'Empty WooCommerce site must be detected.' );
TCD_Academy::watchdog_running_process();
$after = TCD_Academy::state();
check_tcd( 'running' === $after['status'], 'L1 must be allowed to learn categories in an empty product catalogue.' );
check_tcd( '' === $after['last_error'], 'An empty product catalogue is not a technical error for L1.' );
check_tcd( wp_next_scheduled( TCD_Academy::CRON_HOOK ), 'L1 should retain its scheduled worker.' );
check_tcd( 'empty-site' === $after['run_id'] && 0 === $after['snapshot_id'], 'Run and snapshots must be preserved.' );
check_tcd( count( TCD_Academy::logs() ) === 0, 'A shop without products must not record a fake failure.' );

// A real published product leaves a new run eligible for the worker.
$GLOBALS['tcd_products'] = array( 123 );
delete_option( TCD_Academy::WATCHDOG );
$GLOBALS['tcd_opts'][ TCD_Academy::STATE ] = $state;
check_tcd( TCD_Academy::has_published_catalog_products(), 'One published product must be sufficient.' );
check_tcd( 1 === $GLOBALS['tcd_product_query']['posts_per_page'], 'Preflight must be bounded to one product.' );
check_tcd( 'publish' === $GLOBALS['tcd_product_query']['post_status'], 'Drafts must not count.' );
TCD_Academy::watchdog_running_process();
check_tcd( 'running' === TCD_Academy::state()['status'], 'A populated site must remain running.' );
check_tcd( wp_next_scheduled( TCD_Academy::CRON_HOOK ), 'Worker should be scheduled when products exist.' );

// An existing frozen snapshot must not be destroyed, even if the catalogue
// became empty after the snapshot was created.
$GLOBALS['tcd_products'] = array();
delete_option( TCD_Academy::WATCHDOG );
$GLOBALS['tcd_opts'][ TCD_Academy::STATE ] = array_merge(
    $state,
    array( 'lesson' => 2, 'snapshot_id' => 18, 'snapshot_cursor' => 27 )
);
TCD_Academy::watchdog_running_process();
check_tcd( 'running' === TCD_Academy::state()['status'], 'A frozen snapshot must not be stopped.' );
check_tcd( 18 === TCD_Academy::state()['snapshot_id'], 'Frozen snapshot must be preserved.' );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-tcd-academy.php' );
check_tcd(
    strpos( $source, 'requires_catalog_guard' ) === false,
    'The obsolete product gate must not prevent L1 categories or empty-lesson skips.'
);
check_tcd(
    strpos( $source, "'lesson_skipped_empty'" ) !== false &&
    strpos( $source, "'restart'===$action" ) !== false,
    'Empty lessons must be recorded as skipped, and explicit restart remains available.'
);
echo "PASS: empty catalogue permits L1, existing snapshot preserved, worker with products scheduled.\n";

<?php
/**
 * Independent bounded worker for TaxoCommerce Distribution.
 */

defined( 'ABSPATH' ) || exit;

const TCDIST_WORKER_HOOK = 'taxocommerce_distribution_feed_worker';

function tcdist_process_settings() {
    return array(
        'enabled'          => 1,
        'commercial_feeds' => 1,
    );
}

function tcdist_process_state() {
    $state = get_option( 'tcdist_worker_state', array() );
    return is_array( $state ) ? $state : array();
}

function tcdist_process_save_state( $patch ) {
    $state = array_merge( tcdist_process_state(), is_array( $patch ) ? $patch : array() );
    update_option( 'tcdist_worker_state', $state, false );
    return $state;
}

function tcdist_process_managed_update( $key, $patch ) {
    $all = get_option( 'tcdist_worker_components', array() );
    $all = is_array( $all ) ? $all : array();
    $all[ sanitize_key( (string) $key ) ] = is_array( $patch ) ? $patch : array();
    update_option( 'tcdist_worker_components', $all, false );
}

function tcdist_process_log( $level, $code, $message, $component = '', $context = array() ) {
    tcdist_process_save_state(
        array(
            'last_event' => array(
                'level'     => sanitize_key( (string) $level ),
                'code'      => sanitize_key( (string) $code ),
                'message'   => sanitize_text_field( (string) $message ),
                'component' => sanitize_text_field( (string) $component ),
                'at'        => time(),
            ),
        )
    );
}

function tcdist_processes_state( $code, $label, $tone ) {
    return array(
        'code'  => sanitize_key( (string) $code ),
        'label' => sanitize_text_field( (string) $label ),
        'tone'  => sanitize_key( (string) $tone ),
    );
}

function tcdist_processes_format_age( $seconds ) {
    $seconds = max( 0, absint( $seconds ) );
    if ( $seconds < 60 ) {
        return $seconds . ' s';
    }
    if ( $seconds < HOUR_IN_SECONDS ) {
        return floor( $seconds / MINUTE_IN_SECONDS ) . ' min';
    }
    return floor( $seconds / HOUR_IN_SECONDS ) . ' h';
}

/**
 * Schedule the next bounded window.
 */
function tcdist_process_nudge( $delay = 0, $source = '' ) {
    $delay = max( 0, absint( $delay ) );
    $timestamp = time() + max( 1, $delay );

    if ( ! wp_next_scheduled( TCDIST_WORKER_HOOK ) ) {
        wp_schedule_single_event( $timestamp, TCDIST_WORKER_HOOK );
    }

    tcdist_process_save_state(
        array(
            'next_run_at' => $timestamp,
            'source'      => sanitize_key( (string) $source ),
        )
    );
}

function tcdist_process_start( $force = false, $source = '' ) {
    tcdist_process_nudge( 0, $source );
    return true;
}

/**
 * One worker window. The migrated feed engine keeps its adaptive batch sizing,
 * lock, cursor, atomic final publication and failure semantics.
 */
function tcdist_distribution_feed_worker_tick() {
    if ( ! function_exists( 'tcdist_feed_run_manager_slice' ) ) {
        return;
    }

    $state = tcdist_feed_state();
    if ( 'running' !== sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
        return;
    }

    $due = absint( $state['not_before'] ?? 0 );
    if ( $due > time() ) {
        tcdist_process_nudge( $due - time(), 'adaptive_wait' );
        return;
    }

    tcdist_feed_run_manager_slice( 45, 'distribution_worker' );

    $after = tcdist_feed_state();
    if ( 'running' === sanitize_key( (string) ( $after['status'] ?? '' ) ) ) {
        $next_due = absint( $after['not_before'] ?? 0 );
        tcdist_process_nudge( $next_due > time() ? $next_due - time() : 1, 'distribution_worker' );
    }
}
add_action( TCDIST_WORKER_HOOK, 'tcdist_distribution_feed_worker_tick' );

/**
 * Browser/admin pulse compatibility.
 */
add_action(
    'admin_init',
    static function () {
        if ( ! function_exists( 'tcdist_feed_state' ) ) {
            return;
        }
        $state = tcdist_feed_state();
        if ( 'running' === sanitize_key( (string) ( $state['status'] ?? '' ) ) && ! wp_next_scheduled( TCDIST_WORKER_HOOK ) ) {
            tcdist_process_nudge( 1, 'admin_recovery' );
        }
    },
    50
);

<?php
/**
 * Read-only background-queue health signals for System Check.
 *
 * These snapshots measure scheduled application work, NOT operating-system
 * processes, CPU execution time, or proof that a queue runner is stopped.
 *
 * @package TaxoCommerceSystemCheck
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_System_Check_Queue_Health {
    /** @var array|null */
    private static $cron = null;

    /** @var array|null */
    private static $actions = null;

    /**
     * Read WP-Cron's existing schedule; never trigger or cancel an event.
     *
     * @param int|null $now Override time only for isolated regression tests.
     * @return array
     */
    public static function cron( $now = null ) {
        if ( null === $now && null !== self::$cron ) {
            return self::$cron;
        }

        $now = null === $now ? time() : absint( $now );
        $events = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
        $result = array(
            'available' => is_array( $events ),
            'total' => 0,
            'overdue' => 0,
            'oldest_hook' => '',
            'oldest_due' => 0,
            'oldest_delay_seconds' => 0,
            'external_cron_required' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
        );

        foreach ( (array) $events as $timestamp => $hooks ) {
            if ( ! is_numeric( $timestamp ) ) {
                continue;
            }
            $when = (int) $timestamp;
            foreach ( (array) $hooks as $hook => $instances ) {
                foreach ( (array) $instances as $unused ) {
                    ++$result['total'];
                    if ( $when < $now - 900 ) {
                        ++$result['overdue'];
                        if ( ! $result['oldest_due'] || $when < $result['oldest_due'] ) {
                            $result['oldest_due'] = $when;
                            $result['oldest_hook'] = (string) $hook;
                            $result['oldest_delay_seconds'] = max( 0, $now - $when );
                        }
                    }
                }
            }
        }

        if ( null === func_get_arg( 0 ) ) { // The first argument is normally omitted.
            self::$cron = $result;
        }
        return $result;
    }

    /**
     * Evaluate an Action Scheduler queue without deleting or claiming jobs.
     *
     * @param int|null $now Override time in tests.
     * @return array
     */
    public static function scheduler( $now = null ) {
        if ( null === $now && null !== self::$actions ) {
            return self::$actions;
        }
        global $wpdb;
        $now = null === $now ? time() : absint( $now );
        $result = array(
            'available' => false,
            'oldest_hook' => '',
            'oldest_date_gmt' => '',
            'oldest_delay_seconds' => 0,
            'last_failed_hook' => '',
            'last_failed_date_gmt' => '',
            'recent_completed' => 0,
            'recent_failed' => 0,
            'assessment' => 'unavailable',
        );
        $table = $wpdb->prefix . 'actionscheduler_actions';
        if ( ! function_exists( 'seo_server_status_table_exists' ) || ! seo_server_status_table_exists( $table ) ) {
            if ( null === func_get_arg( 0 ) ) {
                self::$actions = $result;
            }
            return $result;
        }

        $result['available'] = true;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Latest indexed status/date of the existing Action Scheduler table is required; this is not a persistent cached report.
        $pending = $wpdb->get_row( $wpdb->prepare(
            'SELECT hook, scheduled_date_gmt FROM %i WHERE status = %s ORDER BY scheduled_date_gmt ASC LIMIT 1',
            $table, 'pending'
        ) );
        if ( is_object( $pending ) ) {
            $date = (string) $pending->scheduled_date_gmt;
            $timestamp = strtotime( $date . ' UTC' );
            if ( false !== $timestamp && $timestamp > 0 ) {
                $result['oldest_hook'] = (string) $pending->hook;
                $result['oldest_date_gmt'] = $date;
                $result['oldest_delay_seconds'] = max( 0, $now - $timestamp );
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Failed-action status/last-attempt lookup is read-only, bounded to one item.
        $failed = $wpdb->get_row( $wpdb->prepare(
            'SELECT hook, last_attempt_gmt FROM %i WHERE status = %s ORDER BY last_attempt_gmt DESC LIMIT 1',
            $table, 'failed'
        ) );
        if ( is_object( $failed ) && ! empty( $failed->last_attempt_gmt ) && '0000-00-00 00:00:00' !== $failed->last_attempt_gmt ) {
            $result['last_failed_hook'] = (string) $failed->hook;
            $result['last_failed_date_gmt'] = (string) $failed->last_attempt_gmt;
        }

        $since = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded aggregate for the recent-hour activity of Action Scheduler.
        $counts = $wpdb->get_results( $wpdb->prepare(
            'SELECT status, COUNT(*) AS count_actions FROM %i WHERE last_attempt_gmt >= %s AND status IN (%s, %s) GROUP BY status',
            $table, $since, 'complete', 'failed'
        ) );
        foreach ( (array) $counts as $count ) {
            if ( 'complete' === $count->status ) {
                $result['recent_completed'] = max( 0, (int) $count->count_actions );
            } elseif ( 'failed' === $count->status ) {
                $result['recent_failed'] = max( 0, (int) $count->count_actions );
            }
        }

        $result['assessment'] = self::assess(
            $result['oldest_delay_seconds'],
            $result['recent_completed'],
            $result['recent_failed']
        );
        if ( null === func_get_arg( 0 ) ) {
            self::$actions = $result;
        }
        return $result;
    }

    /**
     * Signal only what observed work supports. No fake proof of a stalled runner.
     *
     * @param int $delay Age in seconds of oldest pending job.
     * @param int $complete Completed actions with last attempt in last hour.
     * @param int $failed Failed actions with last attempt in last hour.
     * @return string
     */
    public static function assess( $delay, $complete, $failed ) {
        if ( (int) $delay <= 900 ) {
            return 'no_old_backlog';
        }
        if ( (int) $failed > 0 ) {
            return 'failures_recent';
        }
        if ( (int) $complete > 0 ) {
            return 'backlog_with_activity';
        }
        return 'runner_unconfirmed';
    }

    /**
     * Read-only diagnostic rows on Sistema > WordPress.
     *
     * @return void
     */
    public static function render_cron_rows() {
        $state = self::cron();
        if ( ! $state['available'] ) {
            seo_server_status_row(
                __( 'Oldest overdue WP-Cron event', 'taxocommerce-system-check' ),
                __( 'Unavailable', 'taxocommerce-system-check' ),
                'info',
                __( 'The scheduled-event list is unavailable; no status is inferred.', 'taxocommerce-system-check' )
            );
            return;
        }
        $details = $state['oldest_hook'] ? $state['oldest_hook'] . ' · ' . gmdate( 'Y-m-d H:i:s', $state['oldest_due'] ) . ' UTC' : '';
        seo_server_status_row(
            __( 'Oldest overdue WP-Cron event', 'taxocommerce-system-check' ),
            $state['oldest_due'] ? esc_html( number_format_i18n( floor( $state['oldest_delay_seconds'] / 60 ) ) . ' min' ) : esc_html__( 'None', 'taxocommerce-system-check' ),
            $state['oldest_due'] ? 'warning' : 'ok',
            $state['oldest_due'] ? esc_html( $details ) : __( 'No event is more than 15 minutes overdue.', 'taxocommerce-system-check' )
        );
        seo_server_status_row(
            __( 'WP-Cron execution mechanism', 'taxocommerce-system-check' ),
            $state['external_cron_required'] ? __( 'External scheduler required', 'taxocommerce-system-check' ) : __( 'WordPress visitor-triggered cron enabled', 'taxocommerce-system-check' ),
            'info',
            __( 'Overdue events alone do not prove that the runner is stopped. Check the hosting cron when WP-Cron is disabled.', 'taxocommerce-system-check' )
        );
    }

    /**
     * Read-only diagnostic rows on Sistema > WooCommerce.
     *
     * @return void
     */
    public static function render_scheduler_rows() {
        $state = self::scheduler();
        if ( ! $state['available'] ) {
            seo_server_status_row(
                __( 'Action Scheduler activity', 'taxocommerce-system-check' ),
                __( 'Unavailable', 'taxocommerce-system-check' ),
                'info',
                __( 'Action Scheduler has no accessible action table.', 'taxocommerce-system-check' )
            );
            return;
        }
        seo_server_status_row(
            __( 'Oldest pending Action Scheduler job', 'taxocommerce-system-check' ),
            $state['oldest_hook'] ? esc_html( number_format_i18n( floor( $state['oldest_delay_seconds'] / 60 ) ) . ' min' ) : esc_html__( 'None', 'taxocommerce-system-check' ),
            $state['oldest_delay_seconds'] > 900 ? 'warning' : 'ok',
            $state['oldest_hook'] ? esc_html( $state['oldest_hook'] . ' · ' . $state['oldest_date_gmt'] . ' UTC' ) : __( 'No pending job found.', 'taxocommerce-system-check' )
        );
        seo_server_status_row(
            __( 'Most recent failed Action Scheduler attempt', 'taxocommerce-system-check' ),
            $state['last_failed_hook'] ? esc_html( $state['last_failed_hook'] ) : esc_html__( 'None', 'taxocommerce-system-check' ),
            'info',
            $state['last_failed_date_gmt'] ? esc_html( $state['last_failed_date_gmt'] . ' UTC' ) : __( 'No failed attempt is available.', 'taxocommerce-system-check' )
        );
        seo_server_status_row(
            __( 'Action Scheduler attempts in the last hour', 'taxocommerce-system-check' ),
            esc_html( number_format_i18n( $state['recent_completed'] ) . ' / ' . number_format_i18n( $state['recent_failed'] ) ),
            $state['recent_failed'] ? 'warning' : 'info',
            __( 'Completed / failed, according to the last-attempt timestamp; not callback execution time.', 'taxocommerce-system-check' )
        );

        $messages = array(
            'no_old_backlog' => __( 'No overdue backlog was detected in the sampled queue.', 'taxocommerce-system-check' ),
            'failures_recent' => __( 'Recent failures exist; inspect the failing hooks. This does not prove the runner is stopped.', 'taxocommerce-system-check' ),
            'backlog_with_activity' => __( 'Recent completions exist, but an older task remains pending. Check the affected hook.', 'taxocommerce-system-check' ),
            'runner_unconfirmed' => __( 'Old pending tasks with no recent completions or failures: a runner issue is possible, but cannot be confirmed from one snapshot.', 'taxocommerce-system-check' ),
        );
        seo_server_status_row(
            __( 'Action Scheduler diagnostic', 'taxocommerce-system-check' ),
            esc_html( $messages[ $state['assessment'] ] ),
            'info',
            __( 'Read-only snapshot. Actual PHP process duration and runner heartbeat are not exposed by this check.', 'taxocommerce-system-check' )
        );
    }
}

<?php
/**
 * Bounded expiry cleanup. Nothing runs without an explicit admin POST.
 *
 * Historic SEO orphan repair and both legacy rollback engines are deliberately
 * outside this module until a separate recovery design is approved.
 */
defined( 'ABSPATH' ) || exit;

final class TCDB_Cleanup {
    const LIMIT = 100;

    public static function candidates() {
        global $wpdb;
        $counts = array(
            'expired_transients' => 0,
            'expired_wc_sessions' => 0,
            'as_overdue_pending' => null,
        );
        $pattern = $wpdb->esc_like( '_transient_timeout_' ) . '%';
        $counts['expired_transients'] = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d',
                $wpdb->options,
                $pattern,
                time()
            )
        );
        $tables = TCDB_Storage::tables();
        $wc_table = $wpdb->prefix . 'woocommerce_sessions';
        if ( isset( $tables[ $wc_table ] ) ) {
            $counts['expired_wc_sessions'] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE session_expiry < %d',
                    $wc_table,
                    time()
                )
            );
        }
        $as_table = $wpdb->prefix . 'actionscheduler_actions';
        if ( isset( $tables[ $as_table ] ) ) {
            $counts['as_overdue_pending'] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE status='pending' AND scheduled_date_gmt < UTC_TIMESTAMP()",
                    $as_table
                )
            );
        }
        return $counts;
    }

    public static function handle() {
        TCDB_Storage::authorize( 'tcdb_cleanup' );
        $action = isset( $_POST['cleanup'] ) && is_string( $_POST['cleanup'] )
            ? sanitize_key( wp_unslash( $_POST['cleanup'] ) ) : '';
        global $wpdb;
        $deleted = 0;
        if ( 'expired_transients' === $action ) {
            $prefix = '_transient_timeout_';
            $options = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT option_name FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT %d',
                    $wpdb->options,
                    $wpdb->esc_like( $prefix ) . '%',
                    time(),
                    self::LIMIT
                )
            );
            foreach ( (array) $options as $option_name ) {
                if ( 0 === strpos( (string) $option_name, $prefix ) ) {
                    $transient = substr( (string) $option_name, strlen( $prefix ) );
                    if ( '' !== $transient && delete_transient( $transient ) ) {
                        ++$deleted;
                    }
                }
            }
        } elseif ( 'expired_wc_sessions' === $action ) {
            $table = $wpdb->prefix . 'woocommerce_sessions';
            if ( ! TCDB_Storage::table_allowed( $table, false ) ) {
                wp_die( 'La tabla de sesiones WooCommerce no está disponible.' );
            }
            $result = $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE session_expiry < %d LIMIT %d',
                    $table,
                    time(),
                    self::LIMIT
                )
            );
            if ( false === $result ) {
                wp_die( 'No se pudo limpiar las sesiones caducadas.' );
            }
            $deleted = (int) $result;
        } else {
            wp_die( 'Acción de limpieza no permitida.' );
        }
        TCDB_Storage::log( 'cleanup', array( 'kind' => $action, 'deleted' => $deleted ) );
        TCDB_Storage::redirect( 'cleanup', 'cleanup_ok' );
    }
}

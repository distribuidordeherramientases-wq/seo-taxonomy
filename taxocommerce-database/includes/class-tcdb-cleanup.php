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
        $counts['expired_transients'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
              WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
            $pattern,
            time()
        ) );
        $tables = TCDB_Storage::tables();
        $wc_table = $wpdb->prefix . 'woocommerce_sessions';
        if ( isset( $tables[ $wc_table ] ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Existing WooCommerce table for this site.
            $counts['expired_wc_sessions'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM \`{$wc_table}\` WHERE session_expiry < %d",
                time()
            ) );
        }
        $as_table = $wpdb->prefix . 'actionscheduler_actions';
        if ( isset( $tables[ $as_table ] ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Existing Action Scheduler table for this site.
            $counts['as_overdue_pending'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM \`{$as_table}\` WHERE status='pending' AND scheduled_date_gmt < UTC_TIMESTAMP()"
            );
        }
        return $counts;
    }

    public static function handle() {
        TCDB_Storage::authorize( 'tcdb_cleanup' );
        $action = isset( $_POST['cleanup'] ) && is_string( $_POST['cleanup'] )
            ? sanitize_key( wp_unslash( $_POST['cleanup'] ) ) : '';
        $confirm = isset( $_POST['tcdb_confirm'] ) && is_string( $_POST['tcdb_confirm'] )
            ? sanitize_text_field( wp_unslash( $_POST['tcdb_confirm'] ) ) : '';
        if ( 'LIMPIAR' !== $confirm ) {
            wp_die( 'Introduce LIMPIAR para confirmar la operación.' );
        }
        global $wpdb;
        $deleted = 0;
        if ( 'expired_transients' === $action ) {
            $prefix = '_transient_timeout_';
            $options = $wpdb->get_col( $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options}
                  WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT %d",
                $wpdb->esc_like( $prefix ) . '%',
                time(),
                self::LIMIT
            ) );
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
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Existing native WooCommerce table; bounded by LIMIT.
            $result = $wpdb->query( $wpdb->prepare(
                "DELETE FROM \`{$table}\` WHERE session_expiry < %d LIMIT %d",
                time(),
                self::LIMIT
            ) );
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

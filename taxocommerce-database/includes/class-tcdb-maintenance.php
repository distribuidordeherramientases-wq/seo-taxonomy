<?php
/**
 * Explicit, bounded MySQL table maintenance. No cron and no automatic DDL.
 *
 * OPTIMIZE TABLE may rebuild an InnoDB table and require a maintenance window.
 * Only small, own TaxoCommerce tables are accepted. This is not a repair tool.
 */
defined( 'ABSPATH' ) || exit;

final class TCDB_Maintenance {
    const MAX_OPTIMIZE_BYTES = 8388608;  // 8 MiB
    const MAX_ANALYZE_BYTES  = 33554432; // 32 MiB
    const MAX_ROWS           = 50000;
    const LAST_OPTION        = 'tcdb_last_table_maintenance_v1';

    public static function eligibility( $table, $mode ) {
        $tables = TCDB_Storage::tables();
        if ( ! is_string( $table ) || ! isset( $tables[ $table ] ) || ! $tables[ $table ]['family']
            || 'InnoDB' !== $tables[ $table ]['engine'] ) {
            return new WP_Error( 'tcdb_maintenance_table', 'Solo se admiten tablas InnoDB propias de TaxoCommerce.' );
        }
        $bytes = $tables[ $table ]['data'] + $tables[ $table ]['indexes'];
        if ( 'analyze' === $mode ) {
            if ( $bytes > self::MAX_ANALYZE_BYTES ) {
                return new WP_Error( 'tcdb_analyze_large', 'Tabla superior a 32 MB: utiliza el mantenimiento del hosting.' );
            }
            return true;
        }
        if ( 'optimize' === $mode ) {
            if ( $bytes > self::MAX_OPTIMIZE_BYTES || $tables[ $table ]['rows'] > self::MAX_ROWS ) {
                return new WP_Error( 'tcdb_optimize_large', 'La tabla supera 8 MB o 50.000 filas: no se reconstruirá desde PHP.' );
            }
            return true;
        }
        return new WP_Error( 'tcdb_maintenance_mode', 'Tipo de mantenimiento no admitido.' );
    }

    public static function handle() {
        TCDB_Storage::authorize( 'tcdb_maintenance' );
        $table = isset( $_POST['table'] ) && is_string( $_POST['table'] )
            ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';
        $mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] )
            ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
        $confirmation = isset( $_POST['confirmation'] ) && is_string( $_POST['confirmation'] )
            ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '';
        $confirmed_backup = isset( $_POST['backup_ack'] ) && '1' === (string) $_POST['backup_ack'];
        $word = 'analyze' === $mode ? 'ANALIZAR' : 'OPTIMIZAR';
        if ( $confirmation !== $word || ! $confirmed_backup ) {
            wp_die( 'Confirma el mantenimiento y la existencia de una copia de seguridad reciente.' );
        }
        $eligibility = self::eligibility( $table, $mode );
        if ( is_wp_error( $eligibility ) ) {
            wp_die( esc_html( $eligibility->get_error_message() ) );
        }

        global $wpdb;
        // Set a short metadata-lock wait just for this one request/connection.
        $old_lock_timeout = (int) $wpdb->get_var( 'SELECT @@SESSION.lock_wait_timeout' );
        $changed_timeout = false;
        if ( $old_lock_timeout > 0 ) {
            $changed_timeout = false !== $wpdb->query( 'SET SESSION lock_wait_timeout = 5' );
        }
        $notice = 'maintenance_error';
        try {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Verified physical table name in live inventory.
            $result = 'analyze' === $mode
                ? $wpdb->get_results( $wpdb->prepare( 'ANALYZE TABLE %i', $table ), ARRAY_A )
                : $wpdb->get_results( $wpdb->prepare( 'OPTIMIZE TABLE %i', $table ), ARRAY_A );
            if ( ! is_array( $result ) || ! $result ) {
                throw new RuntimeException( 'MySQL no ha devuelto un resultado de mantenimiento.' );
            }
            $failed = false;
            $messages = array();
            foreach ( $result as $line ) {
                $type = (string) ( $line['Msg_type'] ?? '' );
                $message = (string) ( $line['Msg_text'] ?? '' );
                $messages[] = array(
                    'level' => $type,
                    'message' => sanitize_text_field( $message ),
                );
                if ( 'error' === strtolower( $type ) ) {
                    $failed = true;
                }
            }
            $record = array(
                'at' => current_time( 'mysql' ),
                'table' => $table,
                'mode' => $mode,
                'success' => ! $failed,
                'messages' => array_slice( $messages, 0, 8 ),
            );
            update_option( self::LAST_OPTION, $record, false );
            TCDB_Storage::log( 'maintenance', array( 'table' => $table, 'mode' => $mode, 'success' => ! $failed ) );
            $notice = $failed ? 'maintenance_error' : 'maintenance_ok';
        } catch ( Throwable $error ) {
            update_option( self::LAST_OPTION, array(
                'at' => current_time( 'mysql' ),
                'table' => $table,
                'mode' => $mode,
                'success' => false,
                'messages' => array( array( 'level' => 'error', 'message' => sanitize_text_field( $error->getMessage() ) ) ),
            ), false );
            $notice = 'maintenance_error';
        } finally {
            if ( $changed_timeout ) {
                $wpdb->query( $wpdb->prepare( 'SET SESSION lock_wait_timeout = %d', $old_lock_timeout ) );
            }
        }
        TCDB_Storage::redirect( 'optimize', $notice );
    }
}

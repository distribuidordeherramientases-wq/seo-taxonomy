<?php
/**
 * Former Clean DB coverage without the old unbounded destructive operations.
 *
 * Advanced counts only run after a manual admin POST. The optional cleanup
 * actions are limited to old disposable logs/claims and expired stock holds.
 * Orphans in WordPress content and orders remain diagnosis-only: deleting
 * them raw could bypass hooks, HPOS and WooCommerce order lifecycle logic.
 */
defined( 'ABSPATH' ) || exit;

final class TCDB_Diagnostics {
    const OPTION = 'tcdb_advanced_clean_db_v1';
    const BATCH = 100;

    public static function definitions() {
        return array(
            'revisions' => array( 'label' => 'Revisiones de entradas', 'risk' => 'Solo diagnóstico' ),
            'auto_drafts' => array( 'label' => 'Autoborradores de WordPress', 'risk' => 'Solo diagnóstico' ),
            'trashed_posts' => array( 'label' => 'Entradas y páginas en papelera', 'risk' => 'Solo diagnóstico' ),
            'spam_comments' => array( 'label' => 'Comentarios spam', 'risk' => 'Solo diagnóstico' ),
            'trashed_comments' => array( 'label' => 'Comentarios en papelera', 'risk' => 'Solo diagnóstico' ),
            'orphan_postmeta' => array( 'label' => 'Metadatos de contenidos sin entrada', 'risk' => 'Solo diagnóstico' ),
            'orphan_commentmeta' => array( 'label' => 'Metadatos de comentarios huérfanos', 'risk' => 'Solo diagnóstico' ),
            'orphan_termmeta' => array( 'label' => 'Metadatos de términos huérfanos', 'risk' => 'Solo diagnóstico' ),
            'orphan_term_relationships' => array( 'label' => 'Relaciones con contenidos inexistentes', 'risk' => 'Solo diagnóstico' ),
            'orphan_usermeta' => array( 'label' => 'Metadatos de usuarios inexistentes', 'risk' => 'Solo diagnóstico' ),
            'as_orphan_logs' => array( 'label' => 'Logs de Action Scheduler sin acción (>24 h)', 'risk' => 'Limpieza controlada' ),
            'as_old_completed_logs' => array( 'label' => 'Logs completos/cancelados con más de 30 días', 'risk' => 'Limpieza controlada' ),
            'as_orphan_claims' => array( 'label' => 'Claims de Action Scheduler sin acciones (>24 h)', 'risk' => 'Limpieza controlada' ),
            'wc_expired_reserved_stock' => array( 'label' => 'Reservas de stock WooCommerce caducadas', 'risk' => 'Limpieza controlada' ),
            'wc_orphan_order_itemmeta' => array( 'label' => 'Metadatos de líneas de pedido huérfanos', 'risk' => 'Solo diagnóstico' ),
        );
    }

    public static function cleanable() {
        return array( 'as_orphan_logs', 'as_old_completed_logs', 'as_orphan_claims', 'wc_expired_reserved_stock' );
    }

    private static function has( $table, $tables ) {
        return isset( $tables[ $table ] );
    }

    public static function audit() {
        global $wpdb;
        $tables = TCDB_Storage::tables();
        $metrics = array();

        $queries = array(
            'revisions' => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='revision'",
            'auto_drafts' => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='auto-draft'",
            'trashed_posts' => "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='trash'",
            'spam_comments' => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved='spam'",
            'trashed_comments' => "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved='trash'",
            'orphan_postmeta' => "SELECT COUNT(*) FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.ID IS NULL",
            'orphan_commentmeta' => "SELECT COUNT(*) FROM {$wpdb->commentmeta} m LEFT JOIN {$wpdb->comments} c ON c.comment_ID=m.comment_id WHERE c.comment_ID IS NULL",
            'orphan_termmeta' => "SELECT COUNT(*) FROM {$wpdb->termmeta} m LEFT JOIN {$wpdb->terms} t ON t.term_id=m.term_id WHERE t.term_id IS NULL",
            'orphan_term_relationships' => "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON p.ID=tr.object_id WHERE p.ID IS NULL",
            'orphan_usermeta' => "SELECT COUNT(*) FROM {$wpdb->usermeta} m LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE u.ID IS NULL",
        );
        $as_actions = $wpdb->prefix . 'actionscheduler_actions';
        $as_logs = $wpdb->prefix . 'actionscheduler_logs';
        $as_claims = $wpdb->prefix . 'actionscheduler_claims';
        $stock = $wpdb->prefix . 'wc_reserved_stock';
        $items = $wpdb->prefix . 'woocommerce_order_items';
        $itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        if ( self::has( $as_actions, $tables ) && self::has( $as_logs, $tables ) ) {
            $queries['as_orphan_logs'] = "SELECT COUNT(*) FROM {$as_logs} l LEFT JOIN {$as_actions} a ON a.action_id=l.action_id WHERE a.action_id IS NULL AND l.log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)";
            $queries['as_old_completed_logs'] = "SELECT COUNT(*) FROM {$as_logs} l INNER JOIN {$as_actions} a ON a.action_id=l.action_id WHERE a.status IN ('complete','canceled') AND l.log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)";
        }
        if ( self::has( $as_actions, $tables ) && self::has( $as_claims, $tables ) ) {
            $queries['as_orphan_claims'] = "SELECT COUNT(*) FROM {$as_claims} c LEFT JOIN {$as_actions} a ON a.claim_id=c.claim_id WHERE a.action_id IS NULL AND c.date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)";
        }
        if ( self::has( $stock, $tables ) ) {
            $queries['wc_expired_reserved_stock'] = "SELECT COUNT(*) FROM {$stock} WHERE expires < UTC_TIMESTAMP()";
        }
        if ( self::has( $items, $tables ) && self::has( $itemmeta, $tables ) ) {
            $queries['wc_orphan_order_itemmeta'] = "SELECT COUNT(*) FROM {$itemmeta} m LEFT JOIN {$items} i ON i.order_item_id=m.order_item_id WHERE i.order_item_id IS NULL";
        }
        foreach ( self::definitions() as $key => $info ) {
            $metrics[ $key ] = isset( $queries[ $key ] ) ? (int) $wpdb->get_var( $queries[ $key ] ) : null;
        }
        return array(
            'at' => current_time( 'mysql' ),
            'metrics' => $metrics,
            'notice' => 'Estos recuentos son una fotografía. No se eliminan contenidos, revisiones, pedidos ni metadatos por este chequeo.',
        );
    }

    public static function handle_audit() {
        TCDB_Storage::authorize( 'tcdb_advanced_audit' );
        $result = self::audit();
        update_option( self::OPTION, $result, false );
        TCDB_Storage::log( 'clean_db_audit', array( 'checks' => count( $result['metrics'] ) ) );
        TCDB_Storage::redirect( 'cleanup', 'audit_ok' );
    }

    /**
     * Select a bounded list of candidate primary keys; revalidate eligibility
     * during the deletion. MySQL does not allow LIMIT on multi-table DELETE.
     */
    public static function cleanup( $kind ) {
        global $wpdb;
        $tables = TCDB_Storage::tables();
        $actions = $wpdb->prefix . 'actionscheduler_actions';
        $logs = $wpdb->prefix . 'actionscheduler_logs';
        $claims = $wpdb->prefix . 'actionscheduler_claims';
        $stock = $wpdb->prefix . 'wc_reserved_stock';
        $query = '';
        $target = '';
        $key = '';

        if ( 'as_orphan_logs' === $kind && isset( $tables[ $logs ], $tables[ $actions ] ) ) {
            $query = "SELECT l.log_id FROM {$logs} l LEFT JOIN {$actions} a ON a.action_id=l.action_id
                       WHERE a.action_id IS NULL AND l.log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) LIMIT " . self::BATCH;
            $target = $logs; $key = 'log_id';
        } elseif ( 'as_old_completed_logs' === $kind && isset( $tables[ $logs ], $tables[ $actions ] ) ) {
            $query = "SELECT l.log_id FROM {$logs} l INNER JOIN {$actions} a ON a.action_id=l.action_id
                       WHERE a.status IN ('complete','canceled') AND l.log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) LIMIT " . self::BATCH;
            $target = $logs; $key = 'log_id';
        } elseif ( 'as_orphan_claims' === $kind && isset( $tables[ $claims ], $tables[ $actions ] ) ) {
            $query = "SELECT c.claim_id FROM {$claims} c LEFT JOIN {$actions} a ON a.claim_id=c.claim_id
                       WHERE a.action_id IS NULL AND c.date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) LIMIT " . self::BATCH;
            $target = $claims; $key = 'claim_id';
        } elseif ( 'wc_expired_reserved_stock' === $kind && isset( $tables[ $stock ] ) ) {
            // No race-sensitive order edits: check that the hold remains expired at DELETE.
            $query = "DELETE FROM {$stock} WHERE expires < UTC_TIMESTAMP() LIMIT " . self::BATCH;
            $deleted = $wpdb->query( $query );
            return false === $deleted ? new WP_Error( 'tcdb_cleanup_sql', 'MySQL no pudo limpiar el stock caducado.' ) : (int) $deleted;
        } else {
            return new WP_Error( 'tcdb_cleanup_not_supported', 'Esta limpieza no está habilitada o faltan sus tablas.' );
        }

        $ids = (array) $wpdb->get_col( $query );
        $ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
        if ( ! $ids ) {
            return 0;
        }
        $holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // Every ID is integer derived from read-only candidate SELECT; final
        // DELETE rechecks the relationship and age to avoid stale eligibility.
        if ( 'as_orphan_logs' === $kind ) {
            $sql = "DELETE FROM {$target} WHERE {$key} IN ({$holders})
                    AND log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
                    AND NOT EXISTS (SELECT 1 FROM {$actions} a WHERE a.action_id={$target}.action_id)";
        } elseif ( 'as_old_completed_logs' === $kind ) {
            $sql = "DELETE FROM {$target} WHERE {$key} IN ({$holders})
                    AND log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
                    AND EXISTS (SELECT 1 FROM {$actions} a WHERE a.action_id={$target}.action_id AND a.status IN ('complete','canceled'))";
        } else {
            $sql = "DELETE FROM {$target} WHERE {$key} IN ({$holders})
                    AND date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
                    AND NOT EXISTS (SELECT 1 FROM {$actions} a WHERE a.claim_id={$target}.claim_id)";
        }
        $deleted = $wpdb->query( $wpdb->prepare( $sql, $ids ) );
        return false === $deleted ? new WP_Error( 'tcdb_cleanup_sql', 'MySQL no pudo eliminar registros caducados.' ) : (int) $deleted;
    }

    public static function handle_cleanup() {
        TCDB_Storage::authorize( 'tcdb_advanced_cleanup' );
        $kind = isset( $_POST['kind'] ) && is_string( $_POST['kind'] )
            ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
        $confirm = isset( $_POST['confirmation'] ) && is_string( $_POST['confirmation'] )
            ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '';
        if ( ! in_array( $kind, self::cleanable(), true ) || 'ELIMINAR' !== $confirm ) {
            wp_die( 'Acción o confirmación de limpieza inválida.' );
        }
        $result = self::cleanup( $kind );
        $status = is_wp_error( $result ) ? 'cleanup_error' : 'cleanup_ok';
        TCDB_Storage::log( 'clean_db_cleanup', array(
            'kind' => $kind,
            'removed' => is_wp_error( $result ) ? 0 : $result,
            'success' => ! is_wp_error( $result ),
        ) );
        delete_option( self::OPTION );
        TCDB_Storage::redirect( 'cleanup', $status );
    }
}

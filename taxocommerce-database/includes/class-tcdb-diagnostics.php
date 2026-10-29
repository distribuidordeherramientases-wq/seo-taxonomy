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
        $tables  = TCDB_Storage::tables();
        $metrics = array_fill_keys( array_keys( self::definitions() ), null );

        // Every read-only count uses a fixed SQL template and a quoted table identifier.
        $metrics['revisions'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type='revision'", $wpdb->posts ) );
        $metrics['auto_drafts'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_status='auto-draft'", $wpdb->posts ) );
        $metrics['trashed_posts'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_status='trash'", $wpdb->posts ) );
        $metrics['spam_comments'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE comment_approved='spam'", $wpdb->comments ) );
        $metrics['trashed_comments'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE comment_approved='trash'", $wpdb->comments ) );

        $metrics['orphan_postmeta'] = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i m LEFT JOIN %i p ON p.ID=m.post_id WHERE p.ID IS NULL',
            $wpdb->postmeta, $wpdb->posts
        ) );
        $metrics['orphan_commentmeta'] = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i m LEFT JOIN %i c ON c.comment_ID=m.comment_id WHERE c.comment_ID IS NULL',
            $wpdb->commentmeta, $wpdb->comments
        ) );
        $metrics['orphan_termmeta'] = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i m LEFT JOIN %i t ON t.term_id=m.term_id WHERE t.term_id IS NULL',
            $wpdb->termmeta, $wpdb->terms
        ) );
        $metrics['orphan_term_relationships'] = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i tr LEFT JOIN %i p ON p.ID=tr.object_id WHERE p.ID IS NULL',
            $wpdb->term_relationships, $wpdb->posts
        ) );
        $metrics['orphan_usermeta'] = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i m LEFT JOIN %i u ON u.ID=m.user_id WHERE u.ID IS NULL',
            $wpdb->usermeta, $wpdb->users
        ) );

        $as_actions = $wpdb->prefix . 'actionscheduler_actions';
        $as_logs = $wpdb->prefix . 'actionscheduler_logs';
        $as_claims = $wpdb->prefix . 'actionscheduler_claims';
        $stock = $wpdb->prefix . 'wc_reserved_stock';
        $items = $wpdb->prefix . 'woocommerce_order_items';
        $itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';

        if ( self::has( $as_actions, $tables ) && self::has( $as_logs, $tables ) ) {
            $metrics['as_orphan_logs'] = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM %i l LEFT JOIN %i a ON a.action_id=l.action_id WHERE a.action_id IS NULL AND l.log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)',
                $as_logs, $as_actions
            ) );
            $metrics['as_old_completed_logs'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM %i l INNER JOIN %i a ON a.action_id=l.action_id WHERE a.status IN ('complete','canceled') AND l.log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)",
                $as_logs, $as_actions
            ) );
        }
        if ( self::has( $as_actions, $tables ) && self::has( $as_claims, $tables ) ) {
            $metrics['as_orphan_claims'] = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM %i c LEFT JOIN %i a ON a.claim_id=c.claim_id WHERE a.action_id IS NULL AND c.date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)',
                $as_claims, $as_actions
            ) );
        }
        if ( self::has( $stock, $tables ) ) {
            $metrics['wc_expired_reserved_stock'] = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE expires < UTC_TIMESTAMP()', $stock
            ) );
        }
        if ( self::has( $items, $tables ) && self::has( $itemmeta, $tables ) ) {
            $metrics['wc_orphan_order_itemmeta'] = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM %i m LEFT JOIN %i i ON i.order_item_id=m.order_item_id WHERE i.order_item_id IS NULL',
                $itemmeta, $items
            ) );
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

        if ( 'wc_expired_reserved_stock' === $kind && isset( $tables[ $stock ] ) ) {
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE expires < UTC_TIMESTAMP() LIMIT %d',
                    $stock,
                    self::BATCH
                )
            );
            return false === $deleted
                ? new WP_Error( 'tcdb_cleanup_sql', 'MySQL no pudo limpiar el stock caducado.' )
                : (int) $deleted;
        }

        if ( 'as_orphan_logs' === $kind && isset( $tables[ $logs ], $tables[ $actions ] ) ) {
            $ids = (array) $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT l.log_id FROM %i l LEFT JOIN %i a ON a.action_id=l.action_id
                     WHERE a.action_id IS NULL AND l.log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
                     LIMIT %d',
                    $logs,
                    $actions,
                    self::BATCH
                )
            );
        } elseif ( 'as_old_completed_logs' === $kind && isset( $tables[ $logs ], $tables[ $actions ] ) ) {
            $ids = (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT l.log_id FROM %i l INNER JOIN %i a ON a.action_id=l.action_id
                     WHERE a.status IN ('complete','canceled')
                       AND l.log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
                     LIMIT %d",
                    $logs,
                    $actions,
                    self::BATCH
                )
            );
        } elseif ( 'as_orphan_claims' === $kind && isset( $tables[ $claims ], $tables[ $actions ] ) ) {
            $ids = (array) $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT c.claim_id FROM %i c LEFT JOIN %i a ON a.claim_id=c.claim_id
                     WHERE a.action_id IS NULL AND c.date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
                     LIMIT %d',
                    $claims,
                    $actions,
                    self::BATCH
                )
            );
        } else {
            return new WP_Error( 'tcdb_cleanup_not_supported', 'Esta limpieza no está habilitada o faltan sus tablas.' );
        }

        $ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
        if ( ! $ids ) {
            return 0;
        }

        $removed = 0;
        foreach ( $ids as $id ) {
            if ( 'as_orphan_logs' === $kind ) {
                $deleted = $wpdb->query(
                    $wpdb->prepare(
                        'DELETE l FROM %i l
                         WHERE l.log_id=%d
                           AND l.log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
                           AND NOT EXISTS (SELECT 1 FROM %i a WHERE a.action_id=l.action_id)',
                        $logs,
                        $id,
                        $actions
                    )
                );
            } elseif ( 'as_old_completed_logs' === $kind ) {
                $deleted = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE l FROM %i l
                         WHERE l.log_id=%d
                           AND l.log_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
                           AND EXISTS (
                               SELECT 1 FROM %i a
                               WHERE a.action_id=l.action_id
                                 AND a.status IN ('complete','canceled')
                           )",
                        $logs,
                        $id,
                        $actions
                    )
                );
            } else {
                $deleted = $wpdb->query(
                    $wpdb->prepare(
                        'DELETE c FROM %i c
                         WHERE c.claim_id=%d
                           AND c.date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
                           AND NOT EXISTS (SELECT 1 FROM %i a WHERE a.claim_id=c.claim_id)',
                        $claims,
                        $id,
                        $actions
                    )
                );
            }

            if ( false === $deleted ) {
                return new WP_Error( 'tcdb_cleanup_sql', 'MySQL no pudo eliminar registros caducados.' );
            }
            $removed += (int) $deleted;
        }

        return $removed;
    }

    public static function handle_cleanup() {
        TCDB_Storage::authorize( 'tcdb_advanced_cleanup' );
        $kind = isset( $_POST['kind'] ) && is_string( $_POST['kind'] )
            ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
        if ( ! in_array( $kind, self::cleanable(), true ) ) {
            wp_die( 'Acción de limpieza inválida.' );
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

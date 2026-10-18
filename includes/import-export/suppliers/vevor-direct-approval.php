<?php
/**
 * VEVOR: commercial approval requires a saved WooCommerce draft.
 * This operates inside the requested administrator action, not a cron job.
 */
defined( 'ABSPATH' ) || exit;

function seo_vevor_approval_restore_unlinked_pending() {
    if ( ! is_admin() || ! current_user_can( 'manage_options' )
        || get_option( 'seo_vevor_approval_pending_migrated' ) ) {
        return;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        return;
    }
    $sql = $wpdb->prepare(
        "UPDATE {$table} s LEFT JOIN {$wpdb->posts} p
         ON p.ID = s.object_id AND p.post_type = %s AND p.post_status <> %s
         SET s.estado_seleccion = %s, s.estado_sincronizacion = %s,
             s.object_id = NULL, s.actualizado = %s
         WHERE s.proveedor = %s AND s.pais_origen IN ('US','DE','NL','FR')
           AND s.estado_seleccion = %s AND p.ID IS NULL",
        'product', 'trash', 'pendiente', 'nuevo', current_time( 'mysql' ),
        'VEVOR', 'aceptado'
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared SQL with WordPress-owned table identifiers.
    $updated = $wpdb->query( $sql );
    if ( false !== $updated ) {
        update_option( 'seo_vevor_approval_pending_migrated', 1, false );
        update_option( 'seo_vevor_approval_restored_count', (int) $updated, false );
    }
}
add_action( 'admin_init', 'seo_vevor_approval_restore_unlinked_pending', 12 );

/**
 * Aprobar todos los productos seleccionados en la misma petición HTTP.
 * LIMIT 100 solo limita memoria por consulta; no limita el número total.
 */
function seo_vevor_accept_all_directly( $where, $params, $action = 'aceptar', $sale = 20, $regular = 45 ) {
    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    $cursor = 0;
    $done = 0;
    $created = 0;
    $errors = 0;
    $last_error = '';
    if ( function_exists( 'set_time_limit' ) ) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Acción masiva administrativa solicitada sin límite PHP voluntario.
        @set_time_limit( 0 );
    }
    do {
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where}
             AND proveedor = %s AND pais_origen IN ('US','DE','NL','FR')
             AND id > %d ORDER BY id ASC LIMIT 100",
            array_merge( (array) $params, array( 'VEVOR', $cursor ) )
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Consulta preparada.
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $rows ) || $wpdb->last_error ) {
            ++$errors;
            $last_error = 'Error de base de datos: ' . $wpdb->last_error;
            break;
        }
        foreach ( $rows as $row ) {
            $cursor = absint( $row['id'] );
            $out = seo_supplier_sync_apply_action( $row, $action, $sale, $regular, true );
            $id = absint( $out['product_id'] ?? 0 );
            if ( 'error' === ( $out['result'] ?? 'error' ) || ! $id || 'product' !== get_post_type( $id ) ) {
                ++$errors;
                $last_error = '#' . $cursor . ': ' . sanitize_text_field( (string) ( $out['message'] ?? 'No se ha creado la ficha WordPress.' ) );
                if ( ! absint( $row['object_id'] ?? 0 ) ) {
                    $wpdb->update(
                        $table,
                        array(
                            'estado_seleccion' => 'pendiente',
                            'ultimo_error_sync' => $last_error,
                            'actualizado' => current_time( 'mysql' ),
                        ),
                        array( 'id' => $cursor ),
                        array( '%s', '%s', '%s' ),
                        array( '%d' )
                    );
                }
            } else {
                ++$done;
                if ( 'created' === ( $out['result'] ?? '' ) ) {
                    ++$created;
                }
            }
        }
    } while ( 100 === count( $rows ) );
    return array( 'processed' => $done, 'created' => $created, 'errors' => $errors, 'last_error' => $last_error );
}

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
    // Cancel the retired recovery callback before changing supplier states.
    wp_clear_scheduled_hook( 'seo_vevor_normal_import_tick' );
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( 'seo_vevor_normal_import_tick', array(), 'seo-supplier-import' );
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

/**
 * Process the two existing approval forms BEFORE their former SQL-only paths.
 * This is the normal approval request, not a background recovery mechanism.
 */
function seo_vevor_direct_approval_request() {
    $is_legacy = isset( $_POST['seo_prov_actualizar_estado_masivo'] );
    $is_sync = isset( $_POST['seo_supplier_sync_bulk_action'] );
    if ( ! $is_legacy && ! $is_sync ) {
        return;
    }
    $provider = isset( $_POST['f_proveedor'] ) && is_string( $_POST['f_proveedor'] )
        ? sanitize_text_field( wp_unslash( $_POST['f_proveedor'] ) ) : '';
    if ( 0 !== strcasecmp( $provider, 'VEVOR' ) ) {
        return;
    }
    $action = $is_legacy
        ? ( isset( $_POST['estado_masivo'] ) && is_string( $_POST['estado_masivo'] )
            ? sanitize_key( wp_unslash( $_POST['estado_masivo'] ) ) : '' )
        : ( isset( $_POST['accion_masiva'] ) && is_string( $_POST['accion_masiva'] )
            ? sanitize_key( wp_unslash( $_POST['accion_masiva'] ) ) : '' );
    if ( ! in_array( $action, array( 'aceptado', 'aceptar' ), true ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permiso para aprobar productos.' );
    }
    if ( $is_legacy ) {
        check_admin_referer( 'seo_prov_actualizar_estado_masivo', 'seo_prov_estado_masivo_nonce' );
    } else {
        check_admin_referer( 'seo_supplier_sync_bulk_action', 'seo_supplier_sync_bulk_nonce' );
    }

    // Prior versions marked supplier rows accepted before creating WC drafts.
    // Restore only the rows with no real linked product before selecting work.
    seo_vevor_approval_restore_unlinked_pending();

    global $wpdb;
    $where = array( "estado_seleccion IN ('pendiente','revisar')" );
    $params = array();
    $read = static function ( $key ) {
        return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] )
            ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
    };

    $selected = sanitize_key( $read( 'f_estado' ) );
    if ( in_array( $selected, array( 'pendiente', 'revisar' ), true ) ) {
        $where[] = 'estado_seleccion = %s';
        $params[] = $selected;
    } elseif ( '' !== $selected ) {
        wp_die( 'Para crear borradores VEVOR debes seleccionar Pendiente o Todos en el filtro Seleccion.' );
    }
    $market = strtoupper( sanitize_key( $read( 'f_pais' ) ) );
    if ( in_array( $market, array( 'US', 'DE', 'NL', 'FR' ), true ) ) {
        $where[] = 'pais_origen = %s';
        $params[] = $market;
    }
    $situation = sanitize_key( $read( 'f_situacion' ) );
    if ( '' !== $situation ) {
        $where[] = 'estado_sincronizacion = %s';
        $params[] = $situation;
    }
    foreach ( array( 'f_buscar', 'f_categoria', 'f_sku' ) as $field ) {
        $needle = trim( $read( $field ) );
        if ( '' === $needle ) {
            continue;
        }
        $like = '%' . $wpdb->esc_like( $needle ) . '%';
        if ( 'f_buscar' === $field ) {
            $where[] = '(nombre LIKE %s OR descripcion LIKE %s OR marca LIKE %s)';
            array_push( $params, $like, $like, $like );
        } elseif ( 'f_categoria' === $field ) {
            $where[] = 'categoria_proveedor LIKE %s';
            $params[] = $like;
        } else {
            $where[] = '(sku LIKE %s OR mpn LIKE %s OR proveedor_id_externo LIKE %s)';
            array_push( $params, $like, $like, $like );
        }
    }
    foreach ( array( 'f_precio_min' => '>=', 'f_precio_max' => '<=' ) as $field => $operator ) {
        $value = trim( str_replace( ',', '.', $read( $field ) ) );
        if ( '' !== $value && is_numeric( $value ) ) {
            $where[] = 'COALESCE(precio_con_iva, precio_sin_iva) ' . $operator . ' %f';
            $params[] = (float) $value;
        }
    }
    // Do not approve cancelled feeds or unresolved duplicate identities.
    $where[] = "estado_sincronizacion NOT IN ('conflicto','baja_pendiente','baja_aplicada')";

    $lock_name = 'seo_vevor_bulk_' . md5( $wpdb->prefix );
    $locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
    if ( '1' !== (string) $locked ) {
        wp_die( 'Ya existe una aprobacion VEVOR en curso.' );
    }
    try {
        $out = seo_vevor_accept_all_directly( implode( ' AND ', $where ), $params );
    } finally {
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
    }

    $redirect = $read( 'return_url' );
    if ( ! $redirect ) {
        $redirect = add_query_arg(
            array( 'page' => 'seo-import-export', 'seo_ie_tab' => 'catalogo-proveedores', 'f_proveedor' => 'VEVOR' ),
            admin_url( 'admin.php' )
        );
    }
    $notice = $is_legacy
        ? array(
            'seo_bulk_updated' => absint( $out['processed'] ),
            'seo_bulk_estado' => 'aceptado',
            'seo_bulk_created' => absint( $out['created'] ),
            'seo_bulk_errors' => absint( $out['errors'] ),
        )
        : array(
            'seo_sync_bulk_action' => 'aceptar',
            'seo_sync_bulk_processed' => absint( $out['processed'] ),
            'seo_sync_bulk_errors' => absint( $out['errors'] ),
        );
    wp_safe_redirect( add_query_arg( $notice, $redirect ) );
    exit;
}
add_action( 'admin_init', 'seo_vevor_direct_approval_request', 7 );

/**
 * Completa fichas VEVOR ya aprobadas, exclusivamente drafts enlazados.
 * Usa el mismo aplicador de catálogo -> WooCommerce; no hay worker ni alta.
 */
function seo_vevor_completar_drafts_vinculados() {
    if ( ! isset( $_POST['seo_vevor_completar_drafts'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permiso para actualizar borradores VEVOR.' );
    }
    check_admin_referer( 'seo_vevor_completar_drafts', 'seo_vevor_completar_nonce' );
    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    $cursor = 0;
    $updated = 0;
    $errors = 0;
    $skipped = 0;
    if ( function_exists( 'set_time_limit' ) ) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Explicit administrator bulk action.
        @set_time_limit( 0 );
    }

    do {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.* FROM {$table} s
                 INNER JOIN {$wpdb->posts} p ON p.ID = s.object_id
                 WHERE s.proveedor = %s
                   AND s.pais_origen IN ('US','DE','NL','FR')
                   AND s.estado_seleccion = %s
                   AND p.post_type = %s AND p.post_status = %s
                   AND s.id > %d ORDER BY s.id ASC LIMIT 100",
                'VEVOR', 'aceptado', 'product', 'draft', $cursor
            ),
            ARRAY_A
        );
        if ( ! is_array( $rows ) || $wpdb->last_error ) {
            ++$errors;
            break;
        }
        foreach ( $rows as $row ) {
            $cursor = absint( $row['id'] );
            // The service rechecks the fresh catalogue row, backlink, exact
            // product ID, market, SKU-independent identity and current status.
            $result = seo_vevor_region_apply_offer(
                $row,
                strtoupper( (string) $row['pais_origen'] ),
                true
            );
            if ( is_wp_error( $result ) ) {
                ++$errors;
                seo_supplier_sync_mark_error( $cursor, $result->get_error_message() );
            } elseif ( empty( $result['product_id'] ) || ! empty( $result['created'] ) ) {
                ++$skipped;
            } else {
                ++$updated;
            }
        }
    } while ( 100 === count( $rows ) );

    $redirect = add_query_arg(
        array(
            'page' => 'seo-import-export',
            'seo_ie_tab' => 'catalogo-proveedores',
            'f_proveedor' => 'VEVOR',
            'seo_vevor_completed' => $updated,
            'seo_vevor_errors' => $errors,
            'seo_vevor_skipped' => $skipped,
        ),
        admin_url( 'admin.php' )
    );
    wp_safe_redirect( $redirect );
    exit;
}
add_action( 'admin_init', 'seo_vevor_completar_drafts_vinculados', 6 );

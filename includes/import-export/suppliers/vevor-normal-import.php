<?php
/**
 * Regular VEVOR market approval -> WooCommerce import.
 *
 * This replaces temporary recovery panels and applies existing approvals
 * without a second CSV upload. Imported products stay in draft for review.
 *
 * @package SEOSystem
 */
defined( 'ABSPATH' ) || exit;

function seo_vevor_normal_import_schedule( $delay = 0, $from_worker = false ) {
    $hook  = 'seo_vevor_normal_import_tick';
    $group = 'seo-supplier-import';

    if ( function_exists( 'as_schedule_single_action' ) ) {
        if ( ! $from_worker && function_exists( 'as_has_scheduled_action' ) &&
             as_has_scheduled_action( $hook, array(), $group ) ) {
            return true;
        }
        if ( ! $delay && function_exists( 'as_enqueue_async_action' ) ) {
            return (bool) as_enqueue_async_action( $hook, array(), $group, false );
        }
        return (bool) as_schedule_single_action(
            time() + max( 1, absint( $delay ) ),
            $hook,
            array(),
            $group,
            false
        );
    }

    if ( wp_next_scheduled( $hook ) ) {
        return true;
    }
    return (bool) wp_schedule_single_event( time() + max( 1, absint( $delay ) ), $hook );
}

/**
 * Only supplier-approved international offers without a product are eligible.
 * Previously discarded VEVOR records never enter this selection.
 */
function seo_vevor_normal_import_eligible_count() {
    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE proveedor = %s AND pais_origen IN ('US','DE','NL','FR')
               AND estado_seleccion = %s
               AND estado_sincronizacion NOT IN ('error','conflicto','baja_pendiente','baja_aplicada')
               AND (object_id IS NULL OR object_id = 0)",
            'VEVOR',
            'aceptado'
        )
    );
}

/**
 * One-time state correction for legacy "accepted but not applied" offers.
 * No product status is fabricated; a real WooCommerce object must be saved.
 */
function seo_vevor_normal_import_install() {
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( '1' === (string) get_option( 'seo_vevor_normal_import_installed', '' ) ) {
        return;
    }

    // Deactivate the former queue and cancel scheduled legacy callbacks.
    $old = get_option( 'seo_vevor_recovery_queue_state', array() );
    if ( is_array( $old ) ) {
        $old['active'] = false;
        $old['status'] = 'retired';
        update_option( 'seo_vevor_recovery_queue_state', $old, false );
    }
    wp_clear_scheduled_hook( 'seo_vevor_recovery_queue_tick' );
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( 'seo_vevor_recovery_queue_tick', array(), 'seo-vevor-recovery' );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table}
             SET estado_sincronizacion = %s,
                 hash_aplicado = NULL,
                 snapshot_aplicado = NULL,
                 actualizado = %s
             WHERE proveedor = %s AND pais_origen IN ('US','DE','NL','FR')
               AND estado_seleccion = %s AND estado_sincronizacion = %s
               AND (object_id IS NULL OR object_id = 0)",
            'nuevo',
            current_time( 'mysql' ),
            'VEVOR',
            'aceptado',
            'sin_cambios'
        )
    );
    if ( false === $updated ) {
        if ( function_exists( 'seo_system_private_log' ) ) {
            seo_system_private_log( 'error', 'VEVOR: no se pudo corregir el estado intermedio: ' . $wpdb->last_error );
        }
        return;
    }

    update_option( 'seo_vevor_normal_import_installed', '1', false );
    if ( seo_vevor_normal_import_eligible_count() > 0 ) {
        seo_vevor_normal_import_schedule();
    }
}
add_action( 'admin_init', 'seo_vevor_normal_import_install', 30 );

/**
 * An earlier bulk approval may have saved offers without starting the worker.
 * Reconcile accepted/unlinked rows on subsequent administrator requests; do
 * not require a second approval or the one-time install flag to change.
 */
function seo_vevor_normal_import_maybe_resume() {
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( get_transient( 'seo_vevor_normal_resume_check' ) ) {
        return;
    }
    set_transient( 'seo_vevor_normal_resume_check', 1, 5 * MINUTE_IN_SECONDS );
    if ( seo_vevor_normal_import_eligible_count() > 0 ) {
        seo_vevor_normal_import_schedule();
    }
}
add_action( 'admin_init', 'seo_vevor_normal_import_maybe_resume', 40 );

/**
 * Small, isolated product batches from the standard supplier acceptance path.
 * The cursor is persisted; no browser polling or recovery button is required.
 */
function seo_vevor_normal_import_tick() {
    global $wpdb;
    $lock = 'seo_vevor_normal_' . md5( $wpdb->prefix );
    $acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
    if ( '1' !== (string) $acquired ) {
        return;
    }

    $key = 'seo_vevor_normal_import_state';
    try {
        $state = get_option( $key, array() );
        if ( ! is_array( $state ) ) {
            $state = array();
        }
        $cursor = absint( $state['cursor'] ?? 0 );
        $table  = $wpdb->prefix . 'seo_proveedores_productos';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE proveedor = %s AND pais_origen IN ('US','DE','NL','FR')
                   AND estado_seleccion = %s
                   AND estado_sincronizacion NOT IN ('error','conflicto','baja_pendiente','baja_aplicada')
                   AND (object_id IS NULL OR object_id = 0)
                   AND id > %d ORDER BY id ASC LIMIT 5",
                'VEVOR', 'aceptado', $cursor
            ),
            ARRAY_A
        );
        if ( null === $rows || $wpdb->last_error ) {
            throw new RuntimeException( 'Error al leer ofertas aprobadas VEVOR.' );
        }
        $started = microtime( true );
        foreach ( $rows as $row ) {
            if ( microtime( true ) - $started >= 10 && absint( $state['processed'] ?? 0 ) > 0 ) {
                break;
            }
            $cursor = absint( $row['id'] );
            $market = strtoupper( trim( (string) ( $row['pais_origen'] ?? '' ) ) );
            try {
                // New WC drafts remain in the WooCommerce default category.
                $result = seo_vevor_region_apply_offer( $row, $market );
            } catch ( Throwable $exception ) {
                $result = new WP_Error( 'vevor_import_exception', $exception->getMessage() );
            }
            if ( is_wp_error( $result ) ) {
                seo_supplier_sync_mark_error( $cursor, $result->get_error_message() );
                $state['errors'] = absint( $state['errors'] ?? 0 ) + 1;
                $state['last_error'] = '#' . $cursor . ': ' . substr( $result->get_error_message(), 0, 200 );
            } else {
                $state['created'] = absint( $state['created'] ?? 0 ) + ( ! empty( $result['created'] ) ? 1 : 0 );
                $state['linked'] = absint( $state['linked'] ?? 0 ) + ( empty( $result['created'] ) ? 1 : 0 );
            }
            $state['processed'] = absint( $state['processed'] ?? 0 ) + 1;
            $state['cursor'] = $cursor;
            $state['updated_at'] = time();
            update_option( $key, $state, false );
        }

        if ( count( $rows ) < 5 ) {
            // Finish a scan, or wrap if new approvals arrived before cursor.
            if ( seo_vevor_normal_import_eligible_count() > 0 ) {
                $state['cursor'] = 0;
                $state['status'] = 'running';
                update_option( $key, $state, false );
                seo_vevor_normal_import_schedule( 2, true );
            } else {
                $state['status'] = 'complete';
                $state['updated_at'] = time();
                update_option( $key, $state, false );
            }
        } else {
            $state['status'] = 'running';
            update_option( $key, $state, false );
            seo_vevor_normal_import_schedule( 2, true );
        }
    } catch ( Throwable $error ) {
        $state = get_option( $key, array() );
        if ( ! is_array( $state ) ) {
            $state = array();
        }
        $state['status'] = 'error';
        $state['last_error'] = substr( $error->getMessage(), 0, 200 );
        update_option( $key, $state, false );
        if ( function_exists( 'seo_system_private_log' ) ) {
            seo_system_private_log( 'error', 'VEVOR import: ' . $error->getMessage() );
        }
    } finally {
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
    }
}
add_action( 'seo_vevor_normal_import_tick', 'seo_vevor_normal_import_tick' );


/**
 * Merchant-facing counters for each approved VEVOR market. Approval alone is
 * NOT creation: a linked WooCommerce object_id is the actual completion sign.
 */
function seo_vevor_normal_import_market_progress() {
    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT pais_origen,
           SUM(CASE WHEN estado_seleccion = 'aceptado'
               AND (object_id IS NULL OR object_id = 0)
               AND estado_sincronizacion NOT IN ('error','conflicto','baja_pendiente','baja_aplicada')
               THEN 1 ELSE 0 END) AS pending,
           SUM(CASE WHEN estado_seleccion = 'aceptado'
               AND (object_id IS NULL OR object_id = 0)
               AND estado_sincronizacion = 'error'
               THEN 1 ELSE 0 END) AS failed,
           SUM(CASE WHEN estado_seleccion = 'aceptado'
               AND object_id > 0 THEN 1 ELSE 0 END) AS linked
         FROM {$table}
         WHERE proveedor = %s AND pais_origen IN ('US','DE','NL','FR')
         GROUP BY pais_origen",
        'VEVOR'
    ), ARRAY_A );
    return is_array( $rows ) ? $rows : array();
}

/** Operational status and one safe manual batch when Action Scheduler stalls. */
function seo_vevor_normal_import_render_dashboard() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $markets = seo_vevor_normal_import_market_progress();
    if ( ! $markets ) {
        return;
    }
    $state = get_option( 'seo_vevor_normal_import_state', array() );
    if ( ! is_array( $state ) ) {
        $state = array();
    }
    echo '<div class="card" style="max-width:none;padding:16px;margin:16px 0;">';
    echo '<h3>VEVOR internacional: aprobacion y borradores WooCommerce</h3>';
    echo '<p>Aprobado en el catalogo no significa creado en WooCommerce. '
        . 'Cada ficha se crea como producto externo en <code>draft</code> con la categoria predeterminada y enlace de afiliacion regional.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Pais</th><th>Aprobados sin ficha</th>'
        . '<th>Errores sin ficha</th><th>Vinculados a WooCommerce</th></tr></thead><tbody>';
    $pending = 0;
    $failed = 0;
    foreach ( $markets as $item ) {
        $pending += absint( $item['pending'] ?? 0 );
        $failed += absint( $item['failed'] ?? 0 );
        echo '<tr><td>' . esc_html( (string) ( $item['pais_origen'] ?? '' ) ) . '</td><td>'
            . esc_html( number_format_i18n( absint( $item['pending'] ?? 0 ) ) ) . '</td><td>'
            . esc_html( number_format_i18n( absint( $item['failed'] ?? 0 ) ) ) . '</td><td>'
            . esc_html( number_format_i18n( absint( $item['linked'] ?? 0 ) ) ) . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<p>Trabajador: <strong>' . esc_html( (string) ( $state['status'] ?? 'sin iniciar' ) )
        . '</strong> · Procesados: ' . esc_html( number_format_i18n( absint( $state['processed'] ?? 0 ) ) )
        . ' · Creados: ' . esc_html( number_format_i18n( absint( $state['created'] ?? 0 ) ) )
        . ' · Ultima actividad: '
        . esc_html( ! empty( $state['updated_at'] ) ? wp_date( 'Y-m-d H:i:s', absint( $state['updated_at'] ) ) : 'ninguna' )
        . '</p>';
    if ( ! empty( $state['last_error'] ) ) {
        echo '<p><strong>Ultimo fallo:</strong> ' . esc_html( (string) $state['last_error'] ) . '</p>';
    }
    if ( $failed ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_proveedores_productos';
        $sample = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, pais_origen, ultimo_error_sync FROM {$table}
             WHERE proveedor = %s AND estado_seleccion = 'aceptado'
             AND estado_sincronizacion = 'error' AND (object_id IS NULL OR object_id = 0)
             ORDER BY id ASC LIMIT 3",
            'VEVOR'
        ), ARRAY_A );
        foreach ( (array) $sample as $issue ) {
            echo '<p><code>#' . esc_html( (string) ( $issue['id'] ?? '' ) ) . '</code> '
                . esc_html( (string) ( $issue['pais_origen'] ?? '' ) ) . ': '
                . esc_html( (string) ( $issue['ultimo_error_sync'] ?? '' ) ) . '</p>';
        }
    }
    if ( $pending ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'seo_vevor_resume_import' );
        echo '<input type="hidden" name="action" value="seo_vevor_resume_import">';
        echo '<button class="button button-primary" type="submit">Procesar 5 borradores ahora y reanudar cola</button>';
        echo '</form>';
    }
    if ( $failed ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"'
            . ' onsubmit="return confirm(\'Se reintentaran hasta 5 registros VEVOR ya aceptados y con error. No se modificaran productos existentes. ¿Continuar?\');">';
        wp_nonce_field( 'seo_vevor_retry_failed' );
        echo '<input type="hidden" name="action" value="seo_vevor_retry_failed">';
        echo '<button class="button button-secondary" type="submit">Reintentar 5 ofertas fallidas</button>';
        echo '</form>';
    }
    echo '</div>';
}
add_action( 'seo_vevor_region_batch_card', 'seo_vevor_normal_import_render_dashboard', 10 );

/** Explicit, nonce-protected recovery of a small batch; no repeated approval. */
function seo_vevor_normal_import_admin_resume() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Permisos insuficientes.' );
    }
    check_admin_referer( 'seo_vevor_resume_import' );
    seo_vevor_normal_import_tick();
    if ( seo_vevor_normal_import_eligible_count() > 0 ) {
        seo_vevor_normal_import_schedule( 2, true );
    }
    wp_safe_redirect( add_query_arg(
        array( 'page' => 'seo-import-export', 'seo_ie_tab' => 'catalogo-proveedores', 'f_proveedor' => 'VEVOR' ),
        admin_url( 'admin.php' )
    ) );
    exit;
}
add_action( 'admin_post_seo_vevor_resume_import', 'seo_vevor_normal_import_admin_resume' );

/**
 * Retry only failed and previously approved offers, five at a time. Errors
 * stay available for diagnosis until the merchant explicitly retries them.
 */
function seo_vevor_normal_import_admin_retry_failed() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Permisos insuficientes.' );
    }
    check_admin_referer( 'seo_vevor_retry_failed' );
    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT id FROM {$table}
         WHERE proveedor = %s AND pais_origen IN ('US','DE','NL','FR')
           AND estado_seleccion = 'aceptado'
           AND estado_sincronizacion = 'error'
           AND (object_id IS NULL OR object_id = 0)
         ORDER BY id ASC LIMIT 5",
        'VEVOR'
    ) );
    foreach ( (array) $ids as $id ) {
        $wpdb->update( $table,
            array( 'estado_sincronizacion' => 'nuevo', 'ultimo_error_sync' => null ),
            array( 'id' => absint( $id ), 'estado_seleccion' => 'aceptado', 'estado_sincronizacion' => 'error' ),
            array( '%s', '%s' ),
            array( '%d', '%s', '%s' )
        );
    }
    if ( $ids ) {
        seo_vevor_normal_import_tick();
        if ( seo_vevor_normal_import_eligible_count() > 0 ) {
            seo_vevor_normal_import_schedule( 2, true );
        }
    }
    wp_safe_redirect( add_query_arg(
        array( 'page' => 'seo-import-export', 'seo_ie_tab' => 'catalogo-proveedores', 'f_proveedor' => 'VEVOR' ),
        admin_url( 'admin.php' )
    ) );
    exit;
}
add_action( 'admin_post_seo_vevor_retry_failed', 'seo_vevor_normal_import_admin_retry_failed' );

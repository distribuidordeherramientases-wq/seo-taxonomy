<?php
/**
 * Resumable server-side recovery for accepted VEVOR market offers.
 *
 * Action Scheduler (when present) or WP-Cron performs bounded work. The
 * administrator's browser only starts, pauses and observes the job.
 *
 * @package SEOSystem
 */
defined( 'ABSPATH' ) || exit;

// Supersede the original browser-driven loop, including any stale open tabs.
remove_action( 'wp_ajax_seo_vevor_recover', 'seo_vevor_recover_ajax' );
remove_action( 'seo_vevor_region_batch_card', 'seo_vevor_recover_panel', 20 );

function seo_vevor_queue_state_key() {
    return 'seo_vevor_recovery_queue_state';
}

function seo_vevor_queue_pending_count() {
    global $wpdb;
    $table = $wpdb->prefix . 'seo_proveedores_productos';
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE proveedor = %s AND pais_origen IN ('US','DE','NL')
               AND estado_seleccion = %s AND (object_id IS NULL OR object_id = 0)",
            'VEVOR',
            'aceptado'
        )
    );
}

/**
 * Schedule one next tick. A worker must always enqueue the next tick even
 * while its own Action Scheduler action is still in progress.
 */
function seo_vevor_queue_schedule( $delay = 3, $from_worker = false ) {
    $hook  = 'seo_vevor_recovery_queue_tick';
    $group = 'seo-vevor-recovery';

    if ( function_exists( 'as_schedule_single_action' ) ) {
        if (
            ! $from_worker &&
            function_exists( 'as_has_scheduled_action' ) &&
            as_has_scheduled_action( $hook, array(), $group )
        ) {
            return true;
        }
        return (bool) as_schedule_single_action( time() + max( 1, absint( $delay ) ), $hook, array(), $group, false );
    }

    if ( ! wp_next_scheduled( $hook ) ) {
        return (bool) wp_schedule_single_event( time() + max( 5, absint( $delay ) ), $hook );
    }
    return true;
}

/**
 * The DB option lock prevents parallel workers and duplicate creation.
 * Progress remains in wp_options across page reloads and network failures.
 */
function seo_vevor_queue_tick() {
    $lock_key = 'seo_vevor_recovery_queue_lock';
    $acquired = add_option( $lock_key, time(), '', false );
    if ( ! $acquired && time() - absint( get_option( $lock_key ) ) > 120 ) {
        delete_option( $lock_key );
        $acquired = add_option( $lock_key, time(), '', false );
    }
    if ( ! $acquired ) {
        return;
    }

    try {
        $key   = seo_vevor_queue_state_key();
        $state = get_option( $key, array() );
        if ( ! is_array( $state ) || empty( $state['active'] ) ) {
            return;
        }

        global $wpdb;
        $table  = $wpdb->prefix . 'seo_proveedores_productos';
        $cursor = absint( $state['cursor'] ?? 0 );
        $rows   = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE proveedor = %s AND pais_origen IN ('US','DE','NL')
                   AND estado_seleccion = %s AND (object_id IS NULL OR object_id = 0)
                   AND id > %d ORDER BY id ASC LIMIT 12",
                'VEVOR',
                'aceptado',
                $cursor
            ),
            ARRAY_A
        );
        if ( null === $rows || $wpdb->last_error ) {
            throw new RuntimeException( 'No se pudo recuperar el siguiente lote del catalogo.' );
        }

        $created = 0;
        $linked  = 0;
        $errors  = 0;
        $tried   = 0;
        $messages = array();
        $started  = microtime( true );
        foreach ( $rows as $row ) {
            // Limit work per background request on shared hosting.
            if ( $tried > 0 && microtime( true ) - $started >= 10 ) {
                break;
            }
            $latest = get_option( $key, array() );
            if ( empty( $latest['active'] ) ) {
                break;
            }

            $cursor = absint( $row['id'] );
            ++$tried;
            try {
                $result = seo_vevor_recover_one( $row );
            } catch ( Throwable $exception ) {
                $result = new WP_Error( 'vevor_recovery_exception', $exception->getMessage() );
            }
            if ( is_wp_error( $result ) ) {
                ++$errors;
                $messages[] = sprintf( '#%d: %s', $cursor, substr( $result->get_error_message(), 0, 180 ) );
            } elseif ( ! empty( $result['created'] ) ) {
                ++$created;
            } else {
                ++$linked;
            }
        }

        // Merge counters into latest state, never undo a user-requested pause.
        $latest = get_option( $key, array() );
        if ( ! is_array( $latest ) ) {
            $latest = array();
        }
        $latest['cursor']    = $cursor;
        $latest['attempted'] = absint( $latest['attempted'] ?? 0 ) + $tried;
        $latest['created']   = absint( $latest['created'] ?? 0 ) + $created;
        $latest['linked']    = absint( $latest['linked'] ?? 0 ) + $linked;
        $latest['errors']    = absint( $latest['errors'] ?? 0 ) + $errors;
        $latest['messages']  = array_slice(
            array_merge( (array) ( $latest['messages'] ?? array() ), $messages ),
            -8
        );
        $latest['remaining']  = seo_vevor_queue_pending_count();
        $latest['updated_at'] = time();

        $scan_end = $tried === count( $rows ) && count( $rows ) < 12;
        if ( ! empty( $latest['active'] ) && ( 0 === $latest['remaining'] || $scan_end ) ) {
            $latest['active'] = false;
            $latest['status'] = $latest['remaining'] ? 'review' : 'complete';
        }
        update_option( $key, $latest, false );

        if ( ! empty( $latest['active'] ) ) {
            if ( ! seo_vevor_queue_schedule( 3, true ) ) {
                $latest['active'] = false;
                $latest['status'] = 'error';
                $latest['messages'][] = 'No se pudo programar el siguiente lote; pulsa Reanudar.';
                update_option( $key, $latest, false );
            }
        }
    } catch ( Throwable $error ) {
        $key = seo_vevor_queue_state_key();
        $state = get_option( $key, array() );
        if ( ! is_array( $state ) ) {
            $state = array();
        }
        $state['active'] = false;
        $state['status'] = 'error';
        $state['messages'] = array_slice(
            array_merge( (array) ( $state['messages'] ?? array() ), array( substr( $error->getMessage(), 0, 180 ) ) ),
            -8
        );
        $state['updated_at'] = time();
        update_option( $key, $state, false );
    } finally {
        delete_option( $lock_key );
    }
}
add_action( 'seo_vevor_recovery_queue_tick', 'seo_vevor_queue_tick' );

function seo_vevor_queue_control_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'No autorizado.' ), 403 );
    }
    check_ajax_referer( 'seo_vevor_recovery_queue', 'nonce' );

    $operation = sanitize_key( wp_unslash( (string) ( $_POST['operation'] ?? 'status' ) ) );
    if ( ! in_array( $operation, array( 'start', 'pause', 'status' ), true ) ) {
        wp_send_json_error( array( 'message' => 'Operacion desconocida.' ), 400 );
    }

    $key = seo_vevor_queue_state_key();
    $state = get_option( $key, array() );
    if ( ! is_array( $state ) ) {
        $state = array();
    }

    if ( 'pause' === $operation && ! empty( $state['active'] ) ) {
        $state['active'] = false;
        $state['status'] = 'paused';
        $state['updated_at'] = time();
        update_option( $key, $state, false );
    }

    if ( 'start' === $operation ) {
        if ( empty( $state['active'] ) ) {
            $resume = 'paused' === ( $state['status'] ?? '' );
            if ( ! $resume ) {
                $state = array(
                    'cursor' => 0,
                    'attempted' => 0,
                    'created' => 0,
                    'linked' => 0,
                    'errors' => 0,
                    'messages' => array(),
                );
            }
            $state['active'] = true;
            $state['status'] = 'running';
            $state['remaining'] = seo_vevor_queue_pending_count();
            $state['updated_at'] = time();
            update_option( $key, $state, false );
        }
        if ( ! seo_vevor_queue_schedule() ) {
            $state['active'] = false;
            $state['status'] = 'error';
            $state['messages'][] = 'No se ha podido iniciar la cola. Comprueba Action Scheduler / WP-Cron.';
            update_option( $key, $state, false );
        }
    }

    if ( empty( $state ) ) {
        $state = array(
            'status' => 'idle',
            'active' => false,
            'attempted' => 0,
            'created' => 0,
            'linked' => 0,
            'errors' => 0,
            'remaining' => seo_vevor_queue_pending_count(),
            'messages' => array(),
            'updated_at' => 0,
        );
    }
    wp_send_json_success( get_option( $key, $state ) );
}
add_action( 'wp_ajax_seo_vevor_recovery_queue', 'seo_vevor_queue_control_ajax' );

function seo_vevor_queue_panel() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    wp_enqueue_script(
        'seo-vevor-recovery-queue',
        plugin_dir_url( __FILE__ ) . 'vevor-accepted-queue.js',
        array(),
        '2.4.0.2',
        true
    );
    ?>
    <div class="card" id="seo-vevor-queue-panel"
         data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
         data-nonce="<?php echo esc_attr( wp_create_nonce( 'seo_vevor_recovery_queue' ) ); ?>"
         style="max-width:none;padding:16px">
        <h3>VEVOR: recuperación de ofertas internacionales en el servidor</h3>
        <p>Los lotes continúan en Action Scheduler (o WP-Cron) aunque se cierre el navegador. Solo se crean productos externos en borrador, nunca se publica automáticamente. No afecta a productos españoles.</p>
        <button type="button" class="button button-primary" data-action="start">Iniciar / reanudar</button>
        <button type="button" class="button" data-action="pause">Pausar</button>
        <p data-role="status" aria-live="polite">Consultando estado...</p>
        <p data-role="detail"></p>
        <p data-role="errors"></p>
    </div>
    <?php
}
add_action( 'seo_vevor_region_batch_card', 'seo_vevor_queue_panel', 20 );

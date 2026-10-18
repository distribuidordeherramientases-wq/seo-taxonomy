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

/**
 * Fallback classification is a market-local child, never a Spanish category.
 */
function seo_vevor_normal_import_category( array $row ) {
    static $cache = array();

    $market = strtoupper( trim( (string) ( $row['pais_origen'] ?? '' ) ) );
    $path   = (string) ( $row['categoria_proveedor'] ?? '' );
    $key    = $market . ':' . md5( $path );
    if ( isset( $cache[ $key ] ) ) {
        return $cache[ $key ];
    }

    $category = seo_vevor_region_category_for_offer( $row );
    if ( ! is_wp_error( $category ) ) {
        $cache[ $key ] = absint( $category );
        return $cache[ $key ];
    }
    if ( ! in_array( $category->get_error_code(), array( 'vevor_category_missing', 'vevor_category_review' ), true ) ) {
        return $category;
    }

    $slugs = array( 'US' => 'vevor-us', 'DE' => 'vevor-de', 'NL' => 'vevor-nl' );
    $names = array(
        'US' => 'Pending classification',
        'DE' => 'Noch einzuordnen',
        'NL' => 'Nog te categoriseren',
    );
    if ( ! isset( $slugs[ $market ] ) ) {
        return new WP_Error( 'vevor_market_invalid', 'Pais VEVOR no soportado.' );
    }

    $root = get_term_by( 'slug', $slugs[ $market ], 'product_cat' );
    if ( ! $root instanceof WP_Term ) {
        return new WP_Error( 'vevor_market_root_missing', 'Falta la categoria raiz VEVOR ' . $market . '.' );
    }
    $slug = 'vevor-' . strtolower( $market ) . '-clasificar';
    $term = get_term_by( 'slug', $slug, 'product_cat' );
    if ( $term instanceof WP_Term && absint( $term->parent ) !== absint( $root->term_id ) ) {
        return new WP_Error( 'vevor_category_conflict', 'La categoria de revision esta en otro mercado.' );
    }
    if ( ! $term ) {
        $inserted = wp_insert_term(
            $names[ $market ],
            'product_cat',
            array( 'parent' => absint( $root->term_id ), 'slug' => $slug )
        );
        if ( is_wp_error( $inserted ) ) {
            if ( 'term_exists' !== $inserted->get_error_code() ) {
                return $inserted;
            }
            $term = get_term_by( 'slug', $slug, 'product_cat' );
        } else {
            $term = get_term( absint( $inserted['term_id'] ), 'product_cat' );
        }
    }
    if ( ! $term instanceof WP_Term || absint( $term->parent ) !== absint( $root->term_id ) ) {
        return new WP_Error( 'vevor_category_conflict', 'No se puede asignar la categoria de revision del pais.' );
    }
    $cache[ $key ] = absint( $term->term_id );
    return $cache[ $key ];
}

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
             WHERE proveedor = %s AND pais_origen IN ('US','DE','NL')
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
             WHERE proveedor = %s AND pais_origen IN ('US','DE','NL')
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
                 WHERE proveedor = %s AND pais_origen IN ('US','DE','NL')
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
                $category = seo_vevor_normal_import_category( $row );
                $result = is_wp_error( $category ) ? $category :
                    seo_vevor_region_apply_offer( $row, $market, absint( $category ) );
            } catch ( Throwable $exception ) {
                $result = new WP_Error( 'vevor_import_exception', $exception->getMessage() );
            }
            if ( is_wp_error( $result ) ) {
                seo_supplier_sync_mark_error( $cursor, $result->get_error_message() );
                $state['errors'] = absint( $state['errors'] ?? 0 ) + 1;
                $state['last_error'] = '#' . $cursor . ': ' . substr( $result->get_error_message(), 0, 200 );
            } else {
                $product_id = absint( $result['product_id'] ?? 0 );
                if ( $product_id && get_post_status( $product_id ) === 'draft' ) {
                    $assigned = get_post_meta( $product_id, '_seo_vevor_source_category', true );
                    $fallback = get_term_by( 'slug', 'vevor-' . strtolower( $market ) . '-clasificar', 'product_cat' );
                    if ( $fallback instanceof WP_Term && has_term( $fallback->term_id, 'product_cat', $product_id ) ) {
                        update_post_meta( $product_id, '_seo_vevor_category_review', '1' );
                    }
                }
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

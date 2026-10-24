<?php
/**
 * TaxoCommerce SEO - Motor de catalogos comerciales.
 *
 * Maintains a canonical product record and generates public feeds in batches
 * to avoid loading 14k+ products in a single PHP request.
 *
 * @package SEOSystem
 * @subpackage ImportExport\CommercialFeeds
 * @since 2.3.7
 */

defined( 'ABSPATH' ) || exit;

const TCDIST_FEED_SETTINGS_OPTION = 'tcdist_commercial_feeds_settings_v1';
const TCDIST_FEED_STATE_OPTION    = 'tcdist_commercial_feeds_state_v1';
const TCDIST_FEED_HISTORY_OPTION  = 'tcdist_commercial_feeds_history_v1';
const TCDIST_FEED_BATCH_SIZE      = 180;
const TCDIST_FEED_DAILY_HOOK      = 'tcdist_feed_daily_refresh';
const TCDIST_FEED_BATCH_HOOK      = 'tcdist_feed_build_batch';
const TCDIST_FEED_QUEUED_HOOK     = 'tcdist_feed_queued_refresh';

/**
 * Registra hooks y planificacion.
 */
function tcdist_feed_register_runtime() {
    add_action( 'init', 'tcdist_feed_maybe_schedule_daily', 30 );
    add_action( TCDIST_FEED_DAILY_HOOK, 'tcdist_feed_daily_refresh' );
    add_action( TCDIST_FEED_BATCH_HOOK, 'tcdist_feed_process_batch', 10, 2 ); // Compatibilidad: solo despierta el gestor central.
    add_action( TCDIST_FEED_QUEUED_HOOK, 'tcdist_feed_run_queued_refresh', 10, 1 );
    add_action( 'seo_supplier_sync_products_changed', 'tcdist_feed_on_supplier_sync_products_changed', 10, 1 );

    // Any real WooCommerce create/update/delete can change price,
    // sale price, stock, title, or feed availability. Regeneration is
    // agrupa mediante la cola diferida, por lo que una importacion masiva no
    // creates one action per product.
    add_action( 'woocommerce_new_product', 'tcdist_feed_on_woocommerce_product_changed', 20, 1 );
    add_action( 'woocommerce_update_product', 'tcdist_feed_on_woocommerce_product_changed', 20, 1 );
    add_action( 'woocommerce_delete_product', 'tcdist_feed_on_woocommerce_product_changed', 20, 1 );
    add_action( 'woocommerce_new_product_variation', 'tcdist_feed_on_woocommerce_product_changed', 20, 1 );
    add_action( 'woocommerce_update_product_variation', 'tcdist_feed_on_woocommerce_product_changed', 20, 1 );
    add_action( 'woocommerce_delete_product_variation', 'tcdist_feed_on_woocommerce_product_changed', 20, 1 );

    add_filter( 'tcdist_process_has_pending_work', 'tcdist_feed_supervisor_has_pending_work', 20, 1 );
    add_filter( 'tcdist_process_manager_targets', 'tcdist_feed_supervisor_manager_targets', 20, 3 );
    add_filter( 'tcdist_processes_monitor_items', 'tcdist_feed_processes_monitor_items', 20, 1 );
}

/**
 * Ajustes por defecto.
 */
function tcdist_feed_default_settings() {
    $country = 'ES';
    if ( function_exists( 'WC' ) && WC() && WC()->countries ) {
        $base = (string) WC()->countries->get_base_country();
        if ( '' !== $base ) {
            $country = strtoupper( $base );
        }
    }

    // Do not use determine_locale(): in wp-admin it may return the administrator's
    // personal locale instead of the store's commercial locale.
    // Para la tienda espanola, ES implica por defecto catalogo en castellano.
    if ( 'ES' === $country ) {
        $language = 'es';
    } else {
        $site_locale = (string) get_option( 'WPLANG', '' );
        if ( '' === $site_locale ) {
            $site_locale = (string) get_locale();
        }
        $language = strtolower( substr( $site_locale, 0, 2 ) );
        if ( ! preg_match( '/^[a-z]{2}$/', $language ) ) {
            $language = 'en';
        }
    }

    return [
        'enabled_channels'            => [ 'google', 'microsoft', 'pinterest', 'universal', 'json' ],
        'country'                     => $country,
        'language'                    => $language,
        'auto_refresh'                => 1,
        'daily_time'                  => '03:30',
        'refresh_after_supplier_sync' => 1,
        'batch_size'                  => TCDIST_FEED_BATCH_SIZE,
    ];
}

/**
 * Ajustes efectivos.
 */
function tcdist_feed_settings() {
    $saved = get_option( TCDIST_FEED_SETTINGS_OPTION, [] );
    $saved = is_array( $saved ) ? $saved : [];
    $settings = wp_parse_args( $saved, tcdist_feed_default_settings() );

    $known = array_keys( tcdist_feed_channels() );
    $settings['enabled_channels'] = array_values(
        array_intersect(
            $known,
            array_map( 'sanitize_key', (array) $settings['enabled_channels'] )
        )
    );
    if ( empty( $settings['enabled_channels'] ) ) {
        $settings['enabled_channels'] = [ 'google' ];
    }

    $settings['country'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $settings['country'] ), 0, 2 ) );
    if ( '' === $settings['country'] ) {
        $settings['country'] = 'ES';
    }

    $settings['language'] = strtolower( substr( preg_replace( '/[^A-Za-z]/', '', (string) $settings['language'] ), 0, 2 ) );
    if ( '' === $settings['language'] ) {
        $settings['language'] = 'es';
    }

    $settings['auto_refresh'] = empty( $settings['auto_refresh'] ) ? 0 : 1;
    $settings['refresh_after_supplier_sync'] = empty( $settings['refresh_after_supplier_sync'] ) ? 0 : 1;
    $settings['daily_time'] = tcdist_feed_sanitize_daily_time( $settings['daily_time'] ?? '03:30' );
    $settings['batch_size'] = max( 50, min( 500, absint( $settings['batch_size'] ?? TCDIST_FEED_BATCH_SIZE ) ) );

    return $settings;
}

/**
 * Normaliza una hora HH:MM para la regeneracion diaria de seguridad.
 */
function tcdist_feed_sanitize_daily_time( $value ) {
    $value = trim( (string) $value );
    if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
        return '03:30';
    }
    return $value;
}

/**
 * Resuelve el entorno comercial a partir del entorno informado por WordPress
 * y del hostname real. La resolucion es deliberadamente conservadora:
 *
 * - un host con segmentos de staging/dev/test/local siempre queda protegido;
 * - los hosts canonicos de produccion prevalecen sobre WP_ENVIRONMENT_TYPE;
 * - any other domain respects the value reported by WordPress.
 *
 * @param string $reported Entorno informado por wp_get_environment_type().
 * @param string $host     Host real de home_url().
 * @return array{effective:string,reported:string,host:string,source:string,mismatch:bool}
 */
function tcdist_feed_resolve_environment( $reported, $host ) {
    $reported = sanitize_key( (string) $reported );
    if ( '' === $reported ) {
        $reported = 'production';
    }

    $host = strtolower( trim( (string) $host ) );
    $host = rtrim( $host, '.' );

    $production_hosts = (array) apply_filters(
        'tcdist_feed_production_hosts',
        [
            'distribuidordeherramientas.es',
            'www.distribuidordeherramientas.es',
        ]
    );
    $production_hosts = array_values(
        array_unique(
            array_filter(
                array_map(
                    static function ( $production_host ) {
                        return strtolower( rtrim( trim( (string) $production_host ), '.' ) );
                    },
                    $production_hosts
                )
            )
        )
    );

    $effective = $reported;
    $source    = 'wp_environment';

    if (
        '' !== $host
        && preg_match( '/(^|[.\-])(staging|stage|dev|test|local)([.\-]|$)/i', $host )
    ) {
        $effective = 'staging';
        $source    = 'host_safety';
    } elseif ( '' !== $host && in_array( $host, $production_hosts, true ) ) {
        $effective = 'production';
        $source    = 'canonical_production_host';
    }

    return [
        'effective' => sanitize_key( $effective ),
        'reported'  => $reported,
        'host'      => $host,
        'source'    => $source,
        'mismatch'  => $reported !== $effective,
    ];
}

/**
 * Informacion efectiva del entorno.
 */
function tcdist_feed_environment_info() {
    $reported = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production';
    $host     = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

    return tcdist_feed_resolve_environment( $reported, $host );
}

/**
 * Devuelve true solo para produccion comercial efectiva.
 */
function tcdist_feed_is_production() {
    $environment = tcdist_feed_environment_info();
    return 'production' === ( $environment['effective'] ?? '' );
}

/**
 * Directorio y URL publicos de feeds.
 */
function tcdist_feed_storage() {
    $upload = wp_upload_dir( null, false );
    if ( ! empty( $upload['error'] ) ) {
        return new WP_Error( 'tcdist_feed_uploads', (string) $upload['error'] );
    }

    $dir = trailingslashit( $upload['basedir'] ) . 'seo-system-feeds';

    // Tras un clon PRO -> STAGING puede quedar una baseurl de uploads del
    // entorno de origen. El feed siempre debe anunciar el host del entorno
    // que lo esta generando.
    $baseurl       = untrailingslashit( (string) $upload['baseurl'] );
    $home_parts    = wp_parse_url( home_url( '/' ) );
    $upload_parts  = wp_parse_url( $baseurl );
    $url_corrected = false;
    if (
        ! empty( $home_parts['host'] )
        && ! empty( $upload_parts['host'] )
        && 0 !== strcasecmp( (string) $home_parts['host'], (string) $upload_parts['host'] )
    ) {
        $scheme = ! empty( $home_parts['scheme'] ) ? $home_parts['scheme'] : 'https';
        $port   = ! empty( $home_parts['port'] ) ? ':' . absint( $home_parts['port'] ) : '';
        $path   = ! empty( $upload_parts['path'] ) ? '/' . ltrim( (string) $upload_parts['path'], '/' ) : '/wp-content/uploads';
        $baseurl = $scheme . '://' . $home_parts['host'] . $port . $path;
        $url_corrected = true;
    }

    $url = trailingslashit( $baseurl ) . 'seo-system-feeds';

    if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
        return new WP_Error( 'tcdist_feed_mkdir', 'No se pudo crear wp-content/uploads/seo-system-feeds.' );
    }

    $index = trailingslashit( $dir ) . 'index.html';
    if ( ! file_exists( $index ) ) {
        @file_put_contents( $index, '' );
    }

    return [
        'dir'           => wp_normalize_path( $dir ),
        'url'           => untrailingslashit( $url ),
        'url_corrected' => $url_corrected,
    ];
}

/**
 * Estado persistido de la ultima generacion.
 */
function tcdist_feed_state() {
    $state = get_option( TCDIST_FEED_STATE_OPTION, [] );
    return is_array( $state ) ? $state : [];
}

function tcdist_feed_save_state( array $state ) {
    $state['updated_at'] = current_time( 'mysql', true );
    update_option( TCDIST_FEED_STATE_OPTION, $state, false );
    return $state;
}


/**
 * Lock ligero por ejecucion. El Gestor de workers es el unico motor de lotes,
 * pero el lock evita solapes entre dos pulsos concurrentes del propio gestor.
 */
function tcdist_feed_batch_lock_name( $run_id ) {
    return 'tcdist_feed_batch_lock_' . md5( (string) $run_id );
}

function tcdist_feed_batch_is_locked( $run_id ) {
    $key = tcdist_feed_batch_lock_name( $run_id );
    $started = absint( get_option( $key, 0 ) );
    if ( 0 === $started ) {
        return false;
    }
    if ( ( time() - $started ) > 10 * MINUTE_IN_SECONDS ) {
        delete_option( $key );
        return false;
    }
    return true;
}

function tcdist_feed_acquire_batch_lock( $run_id ) {
    $key = tcdist_feed_batch_lock_name( $run_id );
    if ( tcdist_feed_batch_is_locked( $run_id ) ) {
        return false;
    }
    return (bool) add_option( $key, time(), '', 'no' );
}

function tcdist_feed_release_batch_lock( $run_id ) {
    delete_option( tcdist_feed_batch_lock_name( $run_id ) );
}

/**
 * Retira la accion pendiente del lote actual. Las acciones antiguas que ya
 * hayan sido reclamadas tambien quedan invalidadas por status/run_id/cursor.
 */
function tcdist_feed_unschedule_batch( $run_id, $cursor ) {
    $args = [ (string) $run_id, absint( $cursor ) ];

    if ( function_exists( 'as_unschedule_action' ) ) {
        for ( $i = 0; $i < 10; $i++ ) {
            $removed = as_unschedule_action( TCDIST_FEED_BATCH_HOOK, $args, TCDIST_FEED_GROUP );
            if ( false === $removed || null === $removed ) {
                break;
            }
        }
    }

    wp_clear_scheduled_hook( TCDIST_FEED_BATCH_HOOK, $args );
}

/**
 * Detiene una generacion sin publicar archivos parciales. Los temporales se
 * eliminan al iniciar una ejecucion nueva, evitando carreras con un worker que
 * pudiera estar terminando su lote en otra peticion.
 */
function tcdist_feed_stop_build( $reason = 'manual' ) {
    $state = tcdist_feed_state();
    if ( 'running' !== ( $state['status'] ?? '' ) ) {
        return $state;
    }

    $run_id = (string) ( $state['run_id'] ?? '' );
    $cursor = absint( $state['cursor'] ?? 0 );

    $state['status']          = 'stopped';
    $state['stop_reason']     = sanitize_key( (string) $reason );
    $state['completed_at']    = current_time( 'mysql', true );
    $state['pending_refresh'] = '';
    tcdist_feed_save_state( $state );

    tcdist_feed_unschedule_batch( $run_id, $cursor );
    tcdist_feed_record_history( $state );
    if ( function_exists( 'tcdist_process_managed_update' ) ) {
        tcdist_process_managed_update(
            'commercial-feeds',
            [
                'name'         => 'Inventarios comerciales',
                'pending'      => 0,
                'healthy'      => 1,
                'last_checked' => time(),
                'last_result'  => 'stopped',
                'last_error'   => '',
                'detail'       => 'Generacion detenida por el usuario.',
            ]
        );
    }

    return $state;
}

/**
 * Historial compacto de las ultimas generaciones terminadas.
 */
function tcdist_feed_history() {
    $history = get_option( TCDIST_FEED_HISTORY_OPTION, [] );
    return is_array( $history ) ? $history : [];
}

function tcdist_feed_record_history( array $state ) {
    $run_id = sanitize_text_field( (string) ( $state['run_id'] ?? '' ) );
    if ( '' === $run_id ) {
        return;
    }

    $files = [];
    foreach ( (array) ( $state['files'] ?? [] ) as $channel => $file ) {
        $files[ sanitize_key( $channel ) ] = [
            'filename' => sanitize_file_name( (string) ( $file['filename'] ?? '' ) ),
            'size'     => absint( $file['size'] ?? 0 ),
        ];
    }

    $entry = [
        'run_id'          => $run_id,
        'status'          => sanitize_key( (string) ( $state['status'] ?? '' ) ),
        'origin'          => sanitize_key( (string) ( $state['origin'] ?? '' ) ),
        'started_at'      => sanitize_text_field( (string) ( $state['started_at'] ?? '' ) ),
        'completed_at'    => sanitize_text_field( (string) ( $state['completed_at'] ?? '' ) ),
        'candidate_total' => absint( $state['candidate_total'] ?? 0 ),
        'processed'       => absint( $state['processed'] ?? 0 ),
        'written'         => absint( $state['written'] ?? 0 ),
        'excluded'        => absint( $state['excluded'] ?? 0 ),
        'errors'          => array_slice( array_map( 'sanitize_text_field', (array) ( $state['errors'] ?? [] ) ), -5 ),
        'files'           => $files,
        'environment'     => sanitize_key( (string) ( $state['environment'] ?? '' ) ),
    ];

    $history = array_values(
        array_filter(
            tcdist_feed_history(),
            static function ( $item ) use ( $run_id ) {
                return ! is_array( $item ) || (string) ( $item['run_id'] ?? '' ) !== $run_id;
            }
        )
    );
    array_unshift( $history, $entry );
    update_option( TCDIST_FEED_HISTORY_OPTION, array_slice( $history, 0, 20 ), false );
}

/**
 * Finaliza un estado fallido sin tocar el ultimo feed valido publicado.
 */
function tcdist_feed_mark_failed( array $state, $message = '' ) {
    $state['status'] = 'failed';
    if ( '' !== trim( (string) $message ) ) {
        $state['errors'][] = sanitize_text_field( (string) $message );
        $state['errors'] = array_slice( (array) $state['errors'], -20 );
    }
    $state['completed_at'] = current_time( 'mysql', true );
    tcdist_feed_save_state( $state );
    tcdist_feed_cleanup_temp_files( $state );
    tcdist_feed_record_history( $state );
    return $state;
}

/**
 * Calcula la siguiente hora diaria en la zona horaria configurada en WordPress.
 */
function tcdist_feed_next_daily_timestamp( $daily_time = '03:30' ) {
    $daily_time = tcdist_feed_sanitize_daily_time( $daily_time );
    [ $hour, $minute ] = array_map( 'intval', explode( ':', $daily_time ) );
    $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
    $now = new DateTimeImmutable( 'now', $timezone );
    $next = $now->setTime( $hour, $minute, 0 );
    if ( $next <= $now ) {
        $next = $next->modify( '+1 day' );
    }
    return $next->getTimestamp();
}

/**
 * Devuelve el timestamp de la proxima regeneracion diaria programada.
 */
function tcdist_feed_next_daily_scheduled() {
    if ( function_exists( 'as_next_scheduled_action' ) ) {
        $next = as_next_scheduled_action( TCDIST_FEED_DAILY_HOOK, [], TCDIST_FEED_GROUP );
        if ( is_numeric( $next ) && (int) $next > 0 ) {
            return (int) $next;
        }
    }
    $next = wp_next_scheduled( TCDIST_FEED_DAILY_HOOK );
    return $next ? (int) $next : 0;
}

/**
 * Elimina cualquier regeneracion diaria pendiente.
 */
function tcdist_feed_unschedule_daily() {
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( TCDIST_FEED_DAILY_HOOK, [], TCDIST_FEED_GROUP );
    }
    wp_clear_scheduled_hook( TCDIST_FEED_DAILY_HOOK );
}

/**
 * Agenda una ejecucion diaria a una hora visible y estable en hora local.
 * Se usa una accion unica y, al ejecutarse, se agenda la del dia siguiente;
 * asi no deriva una hora por los cambios de horario de verano/invierno.
 */
function tcdist_feed_maybe_schedule_daily( $force = false ) {
    $settings = tcdist_feed_settings();
    $enabled  = ! empty( $settings['auto_refresh'] ) && tcdist_feed_is_production();

    if ( ! $enabled ) {
        tcdist_feed_unschedule_daily();
        return;
    }

    if ( $force ) {
        tcdist_feed_unschedule_daily();
    }

    if ( tcdist_feed_next_daily_scheduled() > 0 ) {
        return;
    }

    $timestamp = tcdist_feed_next_daily_timestamp( $settings['daily_time'] );
    if ( function_exists( 'as_schedule_single_action' ) ) {
        as_schedule_single_action( $timestamp, TCDIST_FEED_DAILY_HOOK, [], TCDIST_FEED_GROUP, false );
        return;
    }

    wp_schedule_single_event( $timestamp, TCDIST_FEED_DAILY_HOOK );
}

/**
 * Refresco automatico.
 */
function tcdist_feed_daily_refresh() {
    if ( ! tcdist_feed_is_production() ) {
        tcdist_feed_unschedule_daily();
        return;
    }

    tcdist_feed_start_build( 'daily_safety' );
    // La accion diaria es unica: programa la siguiente respetando la hora local.
    tcdist_feed_maybe_schedule_daily( true );
}

/**
 * Programa una regeneracion diferida, evitando duplicados.
 */
function tcdist_feed_schedule_refresh( $origin = 'supplier_sync', $delay = 120 ) {
    if ( ! tcdist_feed_is_production() ) {
        return false;
    }

    $origin = sanitize_key( (string) $origin );
    $args   = [ $origin ];
    $delay  = max( 30, absint( $delay ) );

    if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( TCDIST_FEED_QUEUED_HOOK, $args, TCDIST_FEED_GROUP ) ) {
        return true;
    }
    if ( false !== wp_next_scheduled( TCDIST_FEED_QUEUED_HOOK, $args ) ) {
        return true;
    }

    if ( function_exists( 'as_schedule_single_action' ) ) {
        return (int) as_schedule_single_action( time() + $delay, TCDIST_FEED_QUEUED_HOOK, $args, TCDIST_FEED_GROUP, true ) > 0;
    }

    $scheduled = wp_schedule_single_event( time() + $delay, TCDIST_FEED_QUEUED_HOOK, $args, true );
    return ! is_wp_error( $scheduled ) && true === $scheduled;
}

/**
 * Solicita una regeneracion. Si ya hay una en curso se recuerda la peticion y
 * se lanza otra al terminar, para no perder cambios aplicados a IDs ya leidos.
 */
function tcdist_feed_request_refresh( $origin = 'supplier_sync' ) {
    if ( ! tcdist_feed_is_production() ) {
        return false;
    }

    $state = tcdist_feed_state();
    if ( 'running' === ( $state['status'] ?? '' ) ) {
        $state['pending_refresh'] = sanitize_key( (string) $origin );
        $state['pending_refresh_at'] = current_time( 'mysql', true );
        tcdist_feed_save_state( $state );
        return true;
    }

    return tcdist_feed_schedule_refresh( $origin, 120 );
}

/**
 * Disparo emitido por Sincronizacion V2 cuando ya ha terminado de aplicar
 * real changes to WooCommerce products.
 */
function tcdist_feed_on_supplier_sync_products_changed( $context = [] ) {
    $settings = tcdist_feed_settings();
    if ( empty( $settings['refresh_after_supplier_sync'] ) ) {
        return;
    }
    tcdist_feed_request_refresh( 'supplier_sync' );
}

/**
 * Refresca los feeds tras cambios persistidos en WooCommerce.
 *
 * This covers both Marketing campaigns (which write sale_price and dates
 * to the product) and manually created WooCommerce sale prices.
 * En STAGING no arranca nada automaticamente; se mantiene la politica de
 * seguridad del generador comercial.
 *
 * @param int $product_id
 */
function tcdist_feed_on_woocommerce_product_changed( $product_id = 0 ) {
    if ( ! absint( $product_id ) ) {
        return;
    }
    tcdist_feed_request_refresh( 'woocommerce_change' );
}

/**
 * Ejecuta una regeneracion diferida.
 */
function tcdist_feed_run_queued_refresh( $origin = 'supplier_sync' ) {
    if ( ! tcdist_feed_is_production() ) {
        return;
    }

    $result = tcdist_feed_start_build( sanitize_key( (string) $origin ) );
    if ( is_wp_error( $result ) && 'tcdist_feed_running' === $result->get_error_code() ) {
        tcdist_feed_request_refresh( $origin );
    }
}

/**
 * Total de ofertas candidatas antes de aplicar reglas editoriales.
 */
function tcdist_feed_candidate_count() {
    global $wpdb;

    return (int) $wpdb->get_var(
        "SELECT COUNT(*)
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
         WHERE (
             (p.post_type = 'product' AND p.post_status = 'publish')
             OR
             (p.post_type = 'product_variation' AND p.post_status = 'publish' AND parent.post_status = 'publish')
         )"
    );
}

/**
 * Inicia una generacion completa y la entrega al Gestor de workers.
 *
 * This function prepares temporary files and state, but does NOT process products:
 * el reparto de ventanas, el ritmo y la continuidad pertenecen al supervisor
 * central del plugin.
 *
 * @param string $origin Origen funcional de la generacion.
 * @return array|WP_Error
 */
function tcdist_feed_start_build( $origin = 'manual' ) {
    $current = tcdist_feed_state();
    $last_ts = ! empty( $current['updated_at'] ) ? strtotime( (string) $current['updated_at'] . ' UTC' ) : 0;

    if (
        'running' === ( $current['status'] ?? '' )
        && $last_ts
        && ( time() - $last_ts ) < 2 * HOUR_IN_SECONDS
    ) {
        return new WP_Error( 'tcdist_feed_running', 'Ya hay una generacion de inventario en curso. Pulsa Parar antes de iniciar otra.' );
    }

    if ( ! empty( $current['files'] ) ) {
        tcdist_feed_cleanup_temp_files( $current );
    }

    $storage = tcdist_feed_storage();
    if ( is_wp_error( $storage ) ) {
        return $storage;
    }

    $settings = tcdist_feed_settings();
    $channels = tcdist_feed_channels();
    $run_id   = gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
    $files    = [];

    foreach ( $settings['enabled_channels'] as $channel ) {
        if ( empty( $channels[ $channel ] ) ) {
            continue;
        }
        $final_name = sanitize_file_name( (string) $channels[ $channel ]['filename'] );
        $final_path = trailingslashit( $storage['dir'] ) . $final_name;
        $temp_path  = $final_path . '.tmp-' . sanitize_file_name( $run_id );
        $header = tcdist_feed_channel_write_header( $channel, $temp_path );
        if ( is_wp_error( $header ) ) {
            return $header;
        }
        $files[ $channel ] = [
            'temp'     => wp_normalize_path( $temp_path ),
            'final'    => wp_normalize_path( $final_path ),
            'url'      => trailingslashit( $storage['url'] ) . rawurlencode( $final_name ),
            'filename' => $final_name,
        ];
    }

    if ( empty( $files ) ) {
        return new WP_Error( 'tcdist_feed_no_channels', 'No hay canales comerciales activos.' );
    }

    $adaptive = tcdist_feed_adaptive_config();
    $environment = tcdist_feed_environment_info();
    $state = [
        'run_id'                         => $run_id,
        'status'                         => 'running',
        'origin'                         => sanitize_key( (string) $origin ),
        'started_at'                     => current_time( 'mysql', true ),
        'updated_at'                     => current_time( 'mysql', true ),
        'completed_at'                   => '',
        'cursor'                         => 0,
        'candidate_total'                => tcdist_feed_candidate_count(),
        'processed'                      => 0,
        'written'                        => 0,
        'excluded'                       => 0,
        'excluded_reasons'               => [],
        'errors'                         => [],
        'files'                          => $files,
        'environment'                    => (string) ( $environment['effective'] ?? 'production' ),
        'host'                           => (string) ( $environment['host'] ?? '' ),
        'pending_refresh'                => '',
        'last_batch_rows'                => 0,
        'last_batch_target_rows'         => absint( $adaptive['initial_rows'] ),
        'last_batch_duration'            => 0.0,
        'last_batch_seconds_per_row'     => 0.0,
        'last_batch_memory_ratio'        => 0.0,
        'last_batch_time_budget_reached' => 0,
        'adaptive_next_batch_size'       => absint( $adaptive['initial_rows'] ),
        'adaptive_next_delay'            => 0,
        'adaptive_pressure'              => 'baja',
        'adaptive_reason'                => 'arranque conservador',
        'not_before'                     => 0,
        'last_activity_at'               => time(),
        'last_worker_backend'            => 'process_manager',
    ];
    tcdist_feed_save_state( $state );

    if ( function_exists( 'tcdist_process_managed_update' ) ) {
        tcdist_process_managed_update(
            'commercial-feeds',
            [
                'name'         => 'Inventarios comerciales',
                'pending'      => 1,
                'healthy'      => 1,
                'last_checked' => time(),
                'last_result'  => 'waiting',
                'last_error'   => '',
                'detail'       => 'Generacion iniciada; pendiente de la primera ventana del Gestor de workers.',
            ]
        );
    }
    if ( function_exists( 'tcdist_process_log' ) ) {
        tcdist_process_log( 'info', 'commercial_feeds_started', 'Inventarios comerciales entregado al Gestor de workers.', 'Inventarios comerciales', [ 'run_id' => $run_id ] );
    }
    if ( function_exists( 'tcdist_process_nudge' ) ) {
        tcdist_process_nudge( 0, 'commercial_feeds' );
    }
    if ( function_exists( 'tcdist_process_start' ) ) {
        tcdist_process_start( false, 'commercial_feeds' );
    }

    return tcdist_feed_state();
}


/**
 * Configuracion adaptativa de Inventarios comerciales.
 *
 * Usa el mismo regulador temporal/memoria que Import / Export: el panel
 * Processes may override these values through tcdist_feed_adaptive_config.
 */
function tcdist_feed_adaptive_config() {
    $config = [
        'min_rows'               => 50,
        'initial_rows'           => TCDIST_FEED_BATCH_SIZE,
        'max_rows'               => 500,
        'target_seconds'         => 35.0,
        'hard_seconds'           => 100.0,
        'min_rows_before_cutoff' => 1,
        'memory_soft_ratio'      => 0.72,
        'memory_hard_ratio'      => 0.84,
        'growth_factor'          => 1.60,
        'heavy_delay_seconds'    => 5,
        'critical_delay_seconds' => 15,
    ];

    $filtered = apply_filters( 'tcdist_feed_adaptive_config', $config );
    if ( is_array( $filtered ) ) {
        $config = array_merge( $config, $filtered );
    }

    $config['min_rows']               = max( 1, absint( $config['min_rows'] ) );
    $config['initial_rows']           = max( $config['min_rows'], absint( $config['initial_rows'] ) );
    $config['max_rows']               = max( $config['initial_rows'], min( 5000, absint( $config['max_rows'] ) ) );
    $config['target_seconds']         = max( 5.0, (float) $config['target_seconds'] );
    $config['hard_seconds']           = max( $config['target_seconds'] + 5.0, (float) $config['hard_seconds'] );
    $config['min_rows_before_cutoff'] = max( 1, absint( $config['min_rows_before_cutoff'] ) );
    $config['memory_soft_ratio']      = min( 0.95, max( 0.20, (float) $config['memory_soft_ratio'] ) );
    $config['memory_hard_ratio']      = min( 0.98, max( $config['memory_soft_ratio'] + 0.05, (float) $config['memory_hard_ratio'] ) );
    $config['growth_factor']          = min( 2.0, max( 1.10, (float) $config['growth_factor'] ) );
    $config['heavy_delay_seconds']    = max( 0, absint( $config['heavy_delay_seconds'] ) );
    $config['critical_delay_seconds'] = max( $config['heavy_delay_seconds'], absint( $config['critical_delay_seconds'] ) );

    return $config;
}

function tcdist_feed_memory_ratio( $peak = false ) {
    if ( function_exists( 'seo_ie_product_import_memory_ratio' ) ) {
        return (float) seo_ie_product_import_memory_ratio( $peak );
    }

    $raw = trim( (string) ini_get( 'memory_limit' ) );
    if ( '' === $raw || '-1' === $raw ) {
        return 0.0;
    }
    $limit = function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( $raw ) : 0;
    if ( $limit <= 0 ) {
        return 0.0;
    }
    $usage = $peak && function_exists( 'memory_get_peak_usage' ) ? memory_get_peak_usage( true ) : memory_get_usage( true );
    return max( 0.0, (float) $usage / (float) $limit );
}

function tcdist_feed_adaptive_plan( $state ) {
    $state  = is_array( $state ) ? $state : [];
    $config = tcdist_feed_adaptive_config();

    $previous_target = absint( $state['last_batch_target_rows'] ?? 0 );
    if ( 0 === $previous_target ) {
        $previous_target = $config['initial_rows'];
    }

    $previous_rows   = absint( $state['last_batch_rows'] ?? 0 );
    $duration        = max( 0.0, (float) ( $state['last_batch_duration'] ?? 0 ) );
    $memory_ratio    = max( 0.0, (float) ( $state['last_batch_memory_ratio'] ?? 0 ) );
    $budget_reached  = ! empty( $state['last_batch_time_budget_reached'] );
    $seconds_per_row = 0.0;
    $next            = $config['initial_rows'];
    $reason          = 'arranque conservador';
    $pressure        = 'baja';

    if ( 0 < $previous_rows && 0.0 < $duration ) {
        $seconds_per_row = $duration / max( 1, $previous_rows );
        $ideal            = (int) floor( $config['target_seconds'] / max( 0.001, $seconds_per_row ) );
        $ideal            = max( $config['min_rows'], min( $config['max_rows'], $ideal ) );
        $next             = $previous_target;

        if ( $memory_ratio >= $config['memory_hard_ratio'] ) {
            $next     = max( $config['min_rows'], min( $ideal, (int) floor( $previous_target * 0.50 ) ) );
            $reason   = 'memoria alta: se reduce el lote';
            $pressure = 'alta';
        } elseif ( $budget_reached || $duration >= $config['hard_seconds'] ) {
            $next     = max( $config['min_rows'], min( $ideal, (int) floor( $previous_target * 0.70 ) ) );
            $reason   = 'lote largo: se reduce al coste observado';
            $pressure = 'alta';
        } elseif ( $memory_ratio >= $config['memory_soft_ratio'] ) {
            $next     = max( $config['min_rows'], min( $previous_target, $ideal ) );
            $reason   = 'memoria en zona preventiva: no se acelera';
            $pressure = 'media';
        } elseif ( $duration > ( $config['target_seconds'] * 1.25 ) ) {
            $next     = max( $config['min_rows'], min( $previous_target, $ideal ) );
            $reason   = 'el lote supera el objetivo: se ajusta a la baja';
            $pressure = 'media';
        } elseif ( $ideal > $previous_target ) {
            $growth_cap = max( $previous_target + 2, (int) ceil( $previous_target * $config['growth_factor'] ) );
            $next       = min( $config['max_rows'], $ideal, $growth_cap );
            $reason     = 'servidor respondiendo bien: se amplia el lote';
        } elseif ( $ideal < $previous_target ) {
            $next     = max( $config['min_rows'], $ideal );
            $reason   = 'batch size adjusted to actual processing time per product';
            $pressure = 'media';
        } else {
            $next   = $previous_target;
            $reason = 'ritmo estable';
        }
    }

    return [
        'batch_size'      => max( $config['min_rows'], min( $config['max_rows'], absint( $next ) ) ),
        'time_budget'     => (float) $config['hard_seconds'],
        'reason'          => $reason,
        'pressure'        => $pressure,
        'seconds_per_row' => round( $seconds_per_row, 4 ),
    ];
}

function tcdist_feed_adaptive_delay( $state ) {
    $config       = tcdist_feed_adaptive_config();
    $duration     = max( 0.0, (float) ( $state['last_batch_duration'] ?? 0 ) );
    $memory_ratio = max( 0.0, (float) ( $state['last_batch_memory_ratio'] ?? 0 ) );

    if ( $memory_ratio >= $config['memory_hard_ratio'] || $duration >= ( $config['hard_seconds'] * 1.15 ) ) {
        return $config['critical_delay_seconds'];
    }
    if (
        ! empty( $state['last_batch_time_budget_reached'] )
        || $memory_ratio >= $config['memory_soft_ratio']
        || $duration > ( $config['target_seconds'] * 1.35 )
    ) {
        return $config['heavy_delay_seconds'];
    }
    return 0;
}

function tcdist_feed_supervisor_enabled() {
    if ( ! function_exists( 'tcdist_process_settings' ) ) {
        return true;
    }
    $settings = tcdist_process_settings();
    return ! empty( $settings['enabled'] ) && ! empty( $settings['commercial_feeds'] );
}

function tcdist_feed_supervisor_has_pending_work( $pending ) {
    if ( $pending || ! tcdist_feed_supervisor_enabled() ) {
        return (bool) $pending;
    }
    $state = tcdist_feed_state();
    return 'running' === sanitize_key( (string) ( $state['status'] ?? '' ) );
}

function tcdist_feed_supervisor_manager_targets( $targets, $settings, $source ) {
    $targets = is_array( $targets ) ? $targets : [];
    if ( empty( $settings['commercial_feeds'] ) ) {
        return $targets;
    }

    $state = tcdist_feed_state();
    if ( 'running' !== sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
        return $targets;
    }

    $due = absint( $state['not_before'] ?? 0 );
    if ( $due && $due > time() ) {
        if ( function_exists( 'tcdist_process_managed_update' ) ) {
            tcdist_process_managed_update(
                'commercial-feeds',
                [
                    'name'         => 'Inventarios comerciales',
                    'pending'      => 1,
                    'healthy'      => 1,
                    'last_checked' => time(),
                    'last_result'  => 'waiting',
                    'last_error'   => '',
                    'detail'       => 'En pausa adaptativa antes del siguiente lote.',
                ]
            );
        }
        if ( function_exists( 'tcdist_process_nudge' ) ) {
            tcdist_process_nudge( max( 1, $due - time() ), 'commercial_feeds' );
        }
        return $targets;
    }

    $targets[] = [
        'type'     => 'commercial_feeds',
        'data'     => [ 'run_id' => (string) ( $state['run_id'] ?? '' ) ],
        'callback' => 'tcdist_feed_supervisor_run_target',
    ];
    return $targets;
}

function tcdist_feed_supervisor_run_target( $budget, $source, $target ) {
    $state = tcdist_feed_state();
    if ( 'running' !== sanitize_key( (string) ( $state['status'] ?? '' ) ) ) {
        return false;
    }

    if ( function_exists( 'tcdist_process_managed_update' ) ) {
        tcdist_process_managed_update(
            'commercial-feeds',
            [
                'name'            => 'Inventarios comerciales',
                'pending'         => 1,
                'healthy'         => 1,
                'last_checked'    => time(),
                'last_attempt_at' => time(),
                'last_result'     => 'running',
                'last_error'      => '',
                'detail'          => 'El gestor esta ejecutando una ventana de generacion de feeds.',
            ]
        );
    }
    if ( function_exists( 'tcdist_process_log' ) ) {
        tcdist_process_log( 'info', 'process_window_started', 'Inventarios comerciales entra en una ventana del gestor.', 'Inventarios comerciales', [ 'seconds' => absint( $budget ) ] );
    }

    $ok = tcdist_feed_run_manager_slice( max( 5, absint( $budget ) ), sanitize_key( (string) $source ) );
    $after = tcdist_feed_state();
    $pending = 'running' === sanitize_key( (string) ( $after['status'] ?? '' ) );
    $healthy = ! in_array( sanitize_key( (string) ( $after['status'] ?? '' ) ), [ 'failed' ], true );
    $detail = $pending
        ? 'Ventana completada; continuara en el siguiente ciclo del gestor.'
        : ( 'completed' === ( $after['status'] ?? '' ) ? 'Generacion finalizada.' : 'Generacion parada o sin trabajo pendiente.' );
    $after_errors = array_values( (array) ( $after['errors'] ?? [] ) );
    $last_error = $after_errors ? sanitize_text_field( (string) $after_errors[ count( $after_errors ) - 1 ] ) : '';

    if ( function_exists( 'tcdist_process_managed_update' ) ) {
        tcdist_process_managed_update(
            'commercial-feeds',
            [
                'name'         => 'Inventarios comerciales',
                'pending'      => $pending ? 1 : 0,
                'healthy'      => $healthy ? 1 : 0,
                'last_checked' => time(),
                'last_result'  => $pending ? ( $ok ? 'processed' : 'waiting' ) : sanitize_key( (string) ( $after['status'] ?? 'idle' ) ),
                'last_error'   => $last_error,
                'detail'       => $detail,
            ]
        );
    }

    if ( $pending && function_exists( 'tcdist_process_nudge' ) ) {
        $due = absint( $after['not_before'] ?? 0 );
        tcdist_process_nudge( $due > time() ? ( $due - time() ) : 0, 'commercial_feeds' );
    }

    if ( $ok && function_exists( 'tcdist_process_state' ) && function_exists( 'tcdist_process_save_state' ) ) {
        $supervisor = tcdist_process_state();
        tcdist_process_save_state( [
            'launch_count'   => absint( $supervisor['launch_count'] ?? 0 ) + 1,
            'last_launch_at' => time(),
        ] );
    }
    return $ok;
}

/**
 * Returns the next set of product/variation IDs.
 */
function tcdist_feed_next_ids( $cursor, $limit ) {
    global $wpdb;

    $cursor = absint( $cursor );
    $limit  = max( 1, min( 5000, absint( $limit ) ) );

    return array_map(
        'absint',
        (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent
                 WHERE p.ID > %d
                   AND (
                     (p.post_type = 'product' AND p.post_status = 'publish')
                     OR
                     (p.post_type = 'product_variation' AND p.post_status = 'publish' AND parent.post_status = 'publish')
                   )
                 ORDER BY p.ID ASC
                 LIMIT %d",
                $cursor,
                $limit
            )
        )
    );
}

/**
 * Compatibilidad con acciones v1.3 ya encoladas.
 *
 * Action Scheduler deja de ser motor de los lotes. Una accion heredada solo
 * wakes the worker manager; work executes in its shared window.
 */
function tcdist_feed_process_batch( $run_id, $cursor = 0 ) {
    $state = tcdist_feed_state();
    if (
        'running' !== ( $state['status'] ?? '' )
        || ! hash_equals( (string) ( $state['run_id'] ?? '' ), (string) $run_id )
    ) {
        return;
    }
    tcdist_feed_unschedule_batch( (string) $run_id, absint( $cursor ) );
    if ( function_exists( 'tcdist_process_nudge' ) ) {
        tcdist_process_nudge( 0, 'commercial_feeds_legacy' );
    }
}


/**
 * Ejecuta una ventana de Inventarios comerciales desde el Gestor de workers.
 *
 * @param int    $budget Segundos asignados por el supervisor.
 * @param string $source Backend del gestor.
 * @return bool True si se proceso trabajo en esta ventana.
 */
function tcdist_feed_run_manager_slice( $budget = 45, $source = 'process_manager' ) {
    $state = tcdist_feed_state();
    if ( 'running' !== ( $state['status'] ?? '' ) ) {
        return false;
    }

    $due = absint( $state['not_before'] ?? 0 );
    if ( $due && $due > time() ) {
        return false;
    }

    $run_id = (string) ( $state['run_id'] ?? '' );
    if ( '' === $run_id || ! tcdist_feed_acquire_batch_lock( $run_id ) ) {
        return false;
    }

    $worked = false;
    try {
        $state = tcdist_feed_state();
        if ( 'running' !== ( $state['status'] ?? '' ) || ! hash_equals( $run_id, (string) ( $state['run_id'] ?? '' ) ) ) {
            return false;
        }

        $plan   = tcdist_feed_adaptive_plan( $state );
        $config = tcdist_feed_adaptive_config();
        $target = max( 1, absint( $plan['batch_size'] ) );
        $cursor = absint( $state['cursor'] ?? 0 );
        $ids    = tcdist_feed_next_ids( $cursor, $target );

        if ( empty( $ids ) ) {
            tcdist_feed_finish_build( $state );
            return true;
        }

        $window_started = microtime( true );
        $manager_budget = max( 5, min( 55, absint( $budget ) ) );
        $time_budget    = min( (float) $plan['time_budget'], max( 3.0, (float) $manager_budget - 2.0 ) );
        $rows           = 0;
        $last_id        = $cursor;
        $cutoff         = false;

        foreach ( $ids as $product_id ) {
            if (
                $rows >= $config['min_rows_before_cutoff']
                && ( microtime( true ) - $window_started ) >= $time_budget
            ) {
                $cutoff = true;
                break;
            }

            $state['processed']++;
            $rows++;
            $last_id = (int) $product_id;
            $result = tcdist_feed_product_record( $product_id );

            if ( is_wp_error( $result ) ) {
                $state['excluded']++;
                $reason = sanitize_key( $result->get_error_code() );
                if ( '' === $reason ) {
                    $reason = 'unknown';
                }
                $state['excluded_reasons'][ $reason ] = 1 + absint( $state['excluded_reasons'][ $reason ] ?? 0 );
                continue;
            }

            $written_ok = true;
            foreach ( (array) $state['files'] as $channel => $file ) {
                if ( empty( $file['temp'] ) ) {
                    continue;
                }
                $write = tcdist_feed_channel_append_record( $channel, (string) $file['temp'], $result );
                if ( is_wp_error( $write ) ) {
                    $written_ok = false;
                    $state['errors'][] = $channel . ': ' . $write->get_error_message();
                    if ( count( $state['errors'] ) > 20 ) {
                        $state['errors'] = array_slice( $state['errors'], -20 );
                    }
                }
            }

            if ( $written_ok ) {
                $state['written']++;
            } else {
                $state['cursor'] = $last_id;
                tcdist_feed_mark_failed( $state );
                return true;
            }
        }

        $latest = tcdist_feed_state();
        if ( 'running' !== ( $latest['status'] ?? '' ) || ! hash_equals( $run_id, (string) ( $latest['run_id'] ?? '' ) ) ) {
            return $rows > 0;
        }

        $duration = max( 0.001, microtime( true ) - $window_started );
        $state['cursor']                         = $last_id;
        $state['last_batch_rows']                = $rows;
        $state['last_batch_target_rows']         = $target;
        $state['last_batch_duration']            = round( $duration, 4 );
        $state['last_batch_seconds_per_row']     = round( $duration / max( 1, $rows ), 4 );
        $state['last_batch_memory_ratio']        = round( tcdist_feed_memory_ratio( true ), 4 );
        $state['last_batch_time_budget_reached'] = $cutoff ? 1 : 0;
        $state['last_worker_backend']            = sanitize_key( (string) $source );
        $state['last_activity_at']               = time();

        $next_plan = tcdist_feed_adaptive_plan( $state );
        $delay     = tcdist_feed_adaptive_delay( $state );
        $state['adaptive_next_batch_size'] = absint( $next_plan['batch_size'] );
        $state['adaptive_next_delay']      = absint( $delay );
        $state['adaptive_pressure']        = sanitize_key( (string) $next_plan['pressure'] );
        $state['adaptive_reason']          = sanitize_text_field( (string) $next_plan['reason'] );
        $state['not_before']               = $delay > 0 ? time() + $delay : 0;
        tcdist_feed_save_state( $state );
        $worked = $rows > 0;

        // Si SQL devolvio menos IDs que el objetivo y se procesaron todos, no
        // queda ninguna oferta por detras: finaliza sin esperar otro ciclo.
        if ( ! $cutoff && $rows === count( $ids ) && count( $ids ) < $target ) {
            tcdist_feed_finish_build( $state );
            return true;
        }

        if ( function_exists( 'tcdist_process_nudge' ) ) {
            tcdist_process_nudge( $delay, 'commercial_feeds' );
        }
    } finally {
        tcdist_feed_release_batch_lock( $run_id );
    }

    return $worked;
}

/**
 * Compatibilidad con la UI v1.3: ya no ejecuta lotes desde AJAX.
 * Solo entrega un pulso al Gestor de workers central.
 */
function tcdist_feed_browser_continue( $minimum_idle = 8 ) {
    $state = tcdist_feed_state();
    if ( 'running' !== ( $state['status'] ?? '' ) ) {
        return [ 'ran' => false, 'message' => 'La generacion no esta en ejecucion.' ];
    }

    if ( function_exists( 'tcdist_process_nudge' ) ) {
        tcdist_process_nudge( 0, 'commercial_feeds_admin' );
    }
    if ( function_exists( 'tcdist_process_start' ) ) {
        tcdist_process_start( false, 'commercial_feeds_admin' );
    }

    return [ 'ran' => false, 'message' => 'Pulso entregado al Gestor de workers.' ];
}


/**
 * Elimina temporales de una generacion fallida. Los feeds finales anteriores
 * permanecen intactos, de modo que un fallo nunca deja un inventario parcial
 * publicado en una URL estable.
 */
function tcdist_feed_cleanup_temp_files( array $state ) {
    foreach ( (array) ( $state['files'] ?? [] ) as $file ) {
        $temp = (string) ( $file['temp'] ?? '' );
        if ( '' !== $temp && file_exists( $temp ) ) {
            wp_delete_file( $temp );
        }
    }
}

/**
 * Cierra y publica los archivos temporales.
 */
function tcdist_feed_finish_build( array $state ) {
    foreach ( (array) $state['files'] as $channel => $file ) {
        $temp  = (string) ( $file['temp'] ?? '' );
        $final = (string) ( $file['final'] ?? '' );
        if ( '' === $temp || '' === $final || ! file_exists( $temp ) ) {
            $state['status'] = 'failed';
            $state['errors'][] = $channel . ': falta el archivo temporal al finalizar.';
            continue;
        }

        $close = tcdist_feed_channel_finalize( $channel, $temp );
        if ( is_wp_error( $close ) ) {
            $state['status'] = 'failed';
            $state['errors'][] = $channel . ': ' . $close->get_error_message();
            continue;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic feed publication inside the managed directory; current semantics are preserved.
        if ( ! @rename( $temp, $final ) ) {
            $state['status'] = 'failed';
            $state['errors'][] = $channel . ': no se pudo publicar el archivo final.';
            continue;
        }

        $state['files'][ $channel ]['size'] = (int) @filesize( $final );
    }

    if ( 'failed' !== ( $state['status'] ?? '' ) ) {
        $state['status'] = 'completed';
    }
    $state['completed_at'] = current_time( 'mysql', true );

    $pending_refresh = sanitize_key( (string) ( $state['pending_refresh'] ?? '' ) );
    $state['pending_refresh'] = '';
    tcdist_feed_save_state( $state );
    tcdist_feed_record_history( $state );

    if ( '' !== $pending_refresh && tcdist_feed_is_production() ) {
        tcdist_feed_start_build( $pending_refresh );
    }
}

/**
 * Adds commercial feeds to the real-time Processes monitor.
 */
function tcdist_feed_processes_monitor_items( $items ) {
    $items = is_array( $items ) ? $items : [];
    $state = tcdist_feed_state();
    $status = sanitize_key( (string) ( $state['status'] ?? 'never' ) );
    $due = absint( $state['not_before'] ?? 0 );
    $due_in = $due > time() ? $due - time() : 0;

    if ( 'running' === $status && $due_in > 0 ) {
        $view_state = function_exists( 'tcdist_processes_state' ) ? tcdist_processes_state( 'waiting', 'En espera controlada', 'waiting' ) : [ 'code' => 'waiting', 'label' => 'En espera controlada', 'tone' => 'waiting' ];
    } elseif ( 'running' === $status ) {
        $view_state = function_exists( 'tcdist_processes_state' ) ? tcdist_processes_state( 'running', 'En ejecucion', 'running' ) : [ 'code' => 'running', 'label' => 'En ejecucion', 'tone' => 'running' ];
    } elseif ( 'completed' === $status ) {
        $view_state = function_exists( 'tcdist_processes_state' ) ? tcdist_processes_state( 'completed', 'Completado', 'completed' ) : [ 'code' => 'completed', 'label' => 'Completado', 'tone' => 'completed' ];
    } elseif ( 'failed' === $status ) {
        $view_state = function_exists( 'tcdist_processes_state' ) ? tcdist_processes_state( 'error', 'Con error', 'error' ) : [ 'code' => 'error', 'label' => 'Con error', 'tone' => 'error' ];
    } else {
        $view_state = function_exists( 'tcdist_processes_state' ) ? tcdist_processes_state( 'stopped', 'Parado', 'stopped' ) : [ 'code' => 'stopped', 'label' => 'Parado', 'tone' => 'stopped' ];
    }

    $rows = absint( $state['last_batch_rows'] ?? 0 );
    $duration = max( 0.0, (float) ( $state['last_batch_duration'] ?? 0 ) );
    $rate = $duration > 0.0 && $rows > 0 ? ( $rows / $duration ) * 60.0 : 0.0;
    $total = absint( $state['candidate_total'] ?? 0 );
    $processed = absint( $state['processed'] ?? 0 );
    $progress = $total > 0 ? min( 100, round( ( $processed / $total ) * 100, 1 ) ) : null;
    $next_batch = absint( $state['adaptive_next_batch_size'] ?? 0 );
    $delay = absint( $state['adaptive_next_delay'] ?? 0 );
    $pressure = sanitize_key( (string) ( $state['adaptive_pressure'] ?? 'baja' ) );
    $activity_ts = absint( $state['last_activity_at'] ?? 0 );
    if ( ! $activity_ts && ! empty( $state['updated_at'] ) ) {
        $activity_ts = strtotime( (string) $state['updated_at'] . ' UTC' );
    }
    $age = $activity_ts ? max( 0, time() - $activity_ts ) : null;
    $activity = null !== $age && function_exists( 'tcdist_processes_format_age' ) ? tcdist_processes_format_age( $age ) : ( $activity_ts ? wp_date( 'd/m H:i:s', $activity_ts ) : 'Sin actividad' );

    $load = 'Motor: gestor periodico';
    if ( $next_batch > 0 ) {
        $load .= ' · siguiente lote ' . number_format_i18n( $next_batch );
    }
    if ( $delay > 0 ) {
        $load .= ' · pausa propia ' . number_format_i18n( $delay ) . ' s';
    }
    $load .= ' · presion ' . ( $pressure ?: 'baja' );

    $response = $duration > 0.0
        ? number_format_i18n( $duration, 2 ) . ' s last batch · ' . number_format_i18n( $rows ) . ' products'
        : 'Sin lote medido';
    $detail = number_format_i18n( absint( $state['written'] ?? 0 ) ) . ' publicados · ' . number_format_i18n( absint( $state['excluded'] ?? 0 ) ) . ' excluidos.';
    if ( ! empty( $state['adaptive_reason'] ) ) {
        $detail .= ' ' . sanitize_text_field( (string) $state['adaptive_reason'] ) . '.';
    }

    $items[] = [
        'id'            => 'commercial-feeds',
        'name'          => 'Inventarios comerciales',
        'kind'          => 'Feeds Google, Microsoft, Pinterest y universales',
        'state'         => $view_state,
        'speed'         => $rate > 0 ? number_format_i18n( $rate, 1 ) . ' products/min' : 'No measurable rate',
        'response'      => $response,
        'load'          => $load,
        'activity'      => $activity,
        'activity_age'  => $age,
        'progress'      => $progress,
        'progress_text' => number_format_i18n( $processed ) . ( $total ? ' / ' . number_format_i18n( $total ) : '' ),
        'detail'        => $detail,
        'url'           => add_query_arg( [ 'page' => 'taxocommerce-distribution', 'tcdist_tab' => 'feeds' ], admin_url( 'admin.php' ) ),
        'can_start'     => 'running' !== $status,
        'start_label'   => 'Iniciar',
    ];

    return $items;
}

/**
 * Escapado XML.
 */
function tcdist_feed_xml( $value ) {
    return htmlspecialchars( (string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
}

/**
 * Texto plano compacto para feeds.
 */
function tcdist_feed_plain_text( $value, $max = 5000 ) {
    $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $value = preg_replace( '/\s+/u', ' ', $value );
    $value = trim( (string) $value );

    if ( $max > 0 && function_exists( 'mb_substr' ) ) {
        return mb_substr( $value, 0, $max );
    }
    return $max > 0 ? substr( $value, 0, $max ) : $value;
}

/**
 * Deepest leaf category and the product's own path.
 *
 * @return array{term_id:int,path:string}
 */
function tcdist_feed_product_category( $product_id ) {
    $terms = get_the_terms( absint( $product_id ), 'product_cat' );
    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return [ 'term_id' => 0, 'path' => '' ];
    }

    $best = null;
    $best_ancestors = [];
    foreach ( $terms as $term ) {
        $ancestors = array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );
        if ( null === $best || count( $ancestors ) > count( $best_ancestors ) ) {
            $best = $term;
            $best_ancestors = $ancestors;
        }
    }

    if ( ! $best ) {
        return [ 'term_id' => 0, 'path' => '' ];
    }

    $ids = $best_ancestors;
    $ids[] = (int) $best->term_id;
    $names = [];
    foreach ( array_slice( $ids, -5 ) as $term_id ) {
        $term = get_term( $term_id, 'product_cat' );
        if ( $term && ! is_wp_error( $term ) ) {
            $names[] = tcdist_feed_plain_text( $term->name, 180 );
        }
    }

    return [
        'term_id' => (int) $best->term_id,
        'path'    => implode( ' > ', array_filter( $names ) ),
    ];
}

/**
 * Categoria Google aprobada para una product_cat.
 */
function tcdist_feed_google_category( $term_id ) {
    $term_id = absint( $term_id );
    if ( $term_id < 1 ) {
        return '';
    }

    if ( function_exists( 'seo_classifier_google_schema_get_mapping' ) ) {
        $mapping = seo_classifier_google_schema_get_mapping( 'product_cat', $term_id );
        if ( is_array( $mapping ) && 'approved' === (string) ( $mapping['status'] ?? '' ) ) {
            $id = trim( (string) ( $mapping['google_taxonomy_id'] ?? '' ) );
            if ( '' !== $id ) {
                return $id;
            }
            $path = trim( (string) ( $mapping['google_path_en'] ?? '' ) );
            if ( '' !== $path ) {
                return $path;
            }
        }
    }

    return '';
}

/**
 * Imagenes locales/externas. Variaciones heredan las imagenes del padre.
 */
function tcdist_feed_product_images( $product, $limit = 6 ) {
    $limit = max( 1, min( 10, absint( $limit ) ) );

    /*
     * Single source for Merchant/feeds and structured data.
     * The image core prioritizes Media and supplier images and only allows
     * logo/site icon when the product has no real image.
     */
    if ( function_exists( 'seo_images_get_public_product_urls' ) ) {
        return array_values(
            array_filter(
                array_map(
                    'esc_url_raw',
                    (array) seo_images_get_public_product_urls( $product, $limit, true )
                )
            )
        );
    }

    // Compatibilidad defensiva para instalaciones incompletas/antiguas.
    $ids = [ absint( $product->get_id() ) ];
    if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
        $ids[] = absint( $product->get_parent_id() );
    }
    $ids  = array_values( array_unique( array_filter( $ids ) ) );
    $urls = [];

    foreach ( $ids as $product_id ) {
        $featured = absint( get_post_thumbnail_id( $product_id ) );
        $gallery  = (string) get_post_meta( $product_id, '_product_image_gallery', true );
        $attachment_ids = [ $featured ];

        if ( '' !== $gallery ) {
            $attachment_ids = array_merge( $attachment_ids, array_map( 'absint', explode( ',', $gallery ) ) );
        }

        foreach ( array_values( array_unique( array_filter( $attachment_ids ) ) ) as $attachment_id ) {
            $url = esc_url_raw( (string) wp_get_attachment_image_url( $attachment_id, 'full' ) );
            if ( $url && ! isset( $urls[ $url ] ) ) {
                $urls[ $url ] = $url;
            }
            if ( count( $urls ) >= $limit ) {
                break 2;
            }
        }

        if ( function_exists( 'seo_images_get_external_product_images' ) ) {
            foreach ( (array) seo_images_get_external_product_images( $product_id, $limit ) as $row ) {
                $url = esc_url_raw( (string) ( $row['image_url'] ?? '' ) );
                if ( $url && preg_match( '#^https?://#i', $url ) && ! isset( $urls[ $url ] ) ) {
                    $urls[ $url ] = $url;
                }
                if ( count( $urls ) >= $limit ) {
                    break 2;
                }
            }
        }
    }

    return array_slice( array_values( $urls ), 0, $limit );
}

/**
 * Identidad comercial canonica.
 */
function tcdist_feed_identity( $product ) {
    $product_id = absint( $product->get_id() );
    $base_id = $product_id;
    if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
        $base_id = absint( $product->get_parent_id() );
    }

    $identity = [
        'sku'   => trim( (string) $product->get_sku() ),
        'gtin'  => '',
        'mpn'   => '',
        'brand' => '',
    ];

    if ( class_exists( 'SEO_Ojeador_Identity' ) ) {
        $candidate = SEO_Ojeador_Identity::from_product( $product_id );
        if ( ! is_wp_error( $candidate ) ) {
            foreach ( [ 'sku', 'gtin', 'mpn', 'brand' ] as $key ) {
                if ( ! empty( $candidate[ $key ] ) ) {
                    $identity[ $key ] = tcdist_feed_plain_text( $candidate[ $key ], 190 );
                }
            }
        }
    }

    if ( '' === $identity['gtin'] && method_exists( $product, 'get_global_unique_id' ) ) {
        $identity['gtin'] = preg_replace( '/\D+/', '', (string) $product->get_global_unique_id() );
    }

    $meta_candidates = [
        'gtin' => [ '_global_unique_id', '_gtin', 'gtin', '_ean', 'ean', 'ean13', 'gtin13', '_barcode', 'barcode' ],
        'mpn'  => [ '_seo_proveedor_mpn', '_mpn', 'mpn', '_manufacturer_part_number', 'manufacturer_part_number' ],
    ];
    foreach ( $meta_candidates as $field => $keys ) {
        if ( '' !== $identity[ $field ] ) {
            continue;
        }
        foreach ( [ $product_id, $base_id ] as $id ) {
            foreach ( $keys as $key ) {
                $value = trim( (string) get_post_meta( $id, $key, true ) );
                if ( '' !== $value ) {
                    $identity[ $field ] = 'gtin' === $field ? preg_replace( '/\D+/', '', $value ) : tcdist_feed_plain_text( $value, 190 );
                    break 2;
                }
            }
        }
    }

    if ( '' === $identity['brand'] ) {
        foreach ( [ 'product_brand', 'pa_marca', 'pa_brand', 'pa_fabricante' ] as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $terms = get_the_terms( $base_id, $taxonomy );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $identity['brand'] = tcdist_feed_plain_text( reset( $terms )->name, 100 );
                break;
            }
        }
    }

    if ( '' === $identity['brand'] ) {
        foreach ( [ '_brand', 'brand', 'marca', 'Brand', 'Marca' ] as $key ) {
            $value = trim( (string) get_post_meta( $base_id, $key, true ) );
            if ( '' !== $value ) {
                $identity['brand'] = tcdist_feed_plain_text( $value, 100 );
                break;
            }
        }
    }

    if ( strlen( $identity['gtin'] ) < 8 || strlen( $identity['gtin'] ) > 14 ) {
        $identity['gtin'] = '';
    }

    return $identity;
}

/**
 * Source supplier when available.
 */
function tcdist_feed_supplier( $product_id ) {
    foreach ( [ '_seo_proveedor', '_seo_supplier', 'supplier', 'proveedor' ] as $key ) {
        $value = trim( (string) get_post_meta( absint( $product_id ), $key, true ) );
        if ( '' !== $value ) {
            return tcdist_feed_plain_text( $value, 120 );
        }
    }
    return '';
}

/**
 * Peso con unidad WooCommerce.
 */
function tcdist_feed_shipping_weight( $product ) {
    $weight = trim( (string) $product->get_weight() );
    if ( '' === $weight || ! is_numeric( $weight ) || (float) $weight <= 0 ) {
        return '';
    }

    $unit = sanitize_key( (string) get_option( 'woocommerce_weight_unit', 'kg' ) );
    if ( ! in_array( $unit, [ 'kg', 'g', 'lbs', 'oz' ], true ) ) {
        return '';
    }
    if ( 'lbs' === $unit ) {
        $unit = 'lb';
    }

    return wc_format_decimal( $weight, 3 ) . ' ' . $unit;
}

/**
 * Periodo ISO 8601 UTC de una oferta WooCommerce.
 *
 * Solo se devuelve cuando existen inicio y fin validos. Al usar UTC con Z el
 * mismo valor sirve para Google, Microsoft y Pinterest sin depender del huso
 * horario del consumidor del feed.
 *
 * @param WC_Product $product
 * @return string
 */
function tcdist_feed_sale_price_effective_date( $product ) {
    if ( ! is_object( $product ) ) {
        return '';
    }

    $from = method_exists( $product, 'get_date_on_sale_from' ) ? $product->get_date_on_sale_from( 'edit' ) : null;
    $to   = method_exists( $product, 'get_date_on_sale_to' ) ? $product->get_date_on_sale_to( 'edit' ) : null;

    if ( ! $from || ! $to || ! method_exists( $from, 'getTimestamp' ) || ! method_exists( $to, 'getTimestamp' ) ) {
        return '';
    }

    $from_ts = (int) $from->getTimestamp();
    $to_ts   = (int) $to->getTimestamp();
    if ( $from_ts <= 0 || $to_ts <= $from_ts ) {
        return '';
    }

    return gmdate( 'Y-m-d\TH:i:s\Z', $from_ts ) . '/' . gmdate( 'Y-m-d\TH:i:s\Z', $to_ts );
}

/**
 * Decides whether a WooCommerce sale price can be advertised in feeds.
 *
 * Permite ofertas activas y tambien ofertas futuras si tienen un periodo
 * completo. Evita publicar antes de tiempo un sale_price futuro sin fecha de
 * finalizacion y evita mantener ofertas ya vencidas.
 *
 * @param WC_Product $product
 * @param float      $regular_price
 * @param float      $sale_price
 * @return bool
 */
function tcdist_feed_sale_price_is_publishable( $product, $regular_price, $sale_price ) {
    if ( $sale_price <= 0 || $regular_price <= 0 || $sale_price >= $regular_price ) {
        return false;
    }

    $from = method_exists( $product, 'get_date_on_sale_from' ) ? $product->get_date_on_sale_from( 'edit' ) : null;
    $to   = method_exists( $product, 'get_date_on_sale_to' ) ? $product->get_date_on_sale_to( 'edit' ) : null;
    $now  = time();

    $from_ts = ( $from && method_exists( $from, 'getTimestamp' ) ) ? (int) $from->getTimestamp() : 0;
    $to_ts   = ( $to && method_exists( $to, 'getTimestamp' ) ) ? (int) $to->getTimestamp() : 0;

    if ( $to_ts > 0 && $to_ts < $now ) {
        return false;
    }

    // Si aun no ha empezado, solo se anuncia anticipadamente cuando existe
    // tambien una fecha de fin valida: asi el receptor no aplica la oferta ya.
    if ( $from_ts > $now && ( $to_ts <= $from_ts ) ) {
        return false;
    }

    return true;
}

/**
 * Registro canonico de una oferta comercial.
 *
 * @return array<string,mixed>|WP_Error
 */
function tcdist_feed_product_record( $product_id ) {
    if ( ! function_exists( 'wc_get_product' ) ) {
        return new WP_Error( 'woocommerce_unavailable', 'WooCommerce no esta disponible.' );
    }

    $product = wc_get_product( absint( $product_id ) );
    if ( ! $product ) {
        return new WP_Error( 'invalid_product', 'WooCommerce product could not be resolved.' );
    }

    if ( $product->is_type( 'variable' ) ) {
        return new WP_Error( 'variable_parent', 'Variable products are represented by their variations.' );
    }

    if ( method_exists( $product, 'get_catalog_visibility' ) && 'hidden' === $product->get_catalog_visibility() ) {
        return new WP_Error( 'catalog_hidden', 'Product is hidden from the catalog.' );
    }

    $base_id = absint( $product->get_id() );
    if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
        $base_id = absint( $product->get_parent_id() );
        if ( 'publish' !== get_post_status( $base_id ) ) {
            return new WP_Error( 'parent_not_published', 'La variacion no tiene padre publicado.' );
        }
    }

    $title = tcdist_feed_plain_text( $product->get_name(), 150 );
    if ( '' === $title ) {
        return new WP_Error( 'missing_title', 'Titulo vacio.' );
    }

    $description = tcdist_feed_plain_text( $product->get_short_description(), 5000 );
    if ( '' === $description ) {
        $description = tcdist_feed_plain_text( $product->get_description(), 5000 );
    }
    if ( '' === $description && $base_id !== $product->get_id() ) {
        $parent = wc_get_product( $base_id );
        if ( $parent ) {
            $description = tcdist_feed_plain_text( $parent->get_short_description(), 5000 );
            if ( '' === $description ) {
                $description = tcdist_feed_plain_text( $parent->get_description(), 5000 );
            }
        }
    }
    if ( '' === $description ) {
        return new WP_Error( 'missing_description', 'Descripcion vacia.' );
    }

    $link = esc_url_raw( (string) $product->get_permalink() );
    if ( '' === $link ) {
        return new WP_Error( 'missing_link', 'Product URL is empty.' );
    }

    $images = tcdist_feed_product_images( $product, 6 );
    if ( empty( $images ) ) {
        return new WP_Error( 'missing_image', 'No local or external image.' );
    }

    $current_price = function_exists( 'wc_get_price_to_display' )
        ? (float) wc_get_price_to_display( $product )
        : (float) $product->get_price();
    if ( $current_price <= 0 ) {
        return new WP_Error( 'missing_price', 'Invalid price.' );
    }

    $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
    $currency = strtoupper( sanitize_text_field( (string) $currency ) );

    // Leemos los precios base en contexto edit para no perder una oferta futura
    // programada. Luego convertimos ambos al mismo criterio fiscal/visual que
    // uses the price displayed by WooCommerce.
    $regular_raw = (float) $product->get_regular_price( 'edit' );
    $sale_raw    = (float) $product->get_sale_price( 'edit' );

    $regular_price = $regular_raw > 0 && function_exists( 'wc_get_price_to_display' )
        ? (float) wc_get_price_to_display( $product, [ 'price' => $regular_raw ] )
        : $regular_raw;
    $sale_display = $sale_raw > 0 && function_exists( 'wc_get_price_to_display' )
        ? (float) wc_get_price_to_display( $product, [ 'price' => $sale_raw ] )
        : $sale_raw;

    $price                     = $current_price;
    $sale_price                = '';
    $sale_price_effective_date = '';

    if ( tcdist_feed_sale_price_is_publishable( $product, $regular_price, $sale_display ) ) {
        // For every receiver, price means regular price and sale_price means
        // discounted price. This also allows advertising a future sale
        // antes de que WooCommerce la active en la ficha.
        $price      = $regular_price;
        $sale_price = wc_format_decimal( $sale_display, wc_get_price_decimals() ) . ' ' . $currency;
        $sale_price_effective_date = tcdist_feed_sale_price_effective_date( $product );
    }

    $stock_status = sanitize_key( (string) $product->get_stock_status() );
    if ( 'instock' === $stock_status ) {
        $availability = 'in_stock';
    } elseif ( 'onbackorder' === $stock_status ) {
        $availability = 'backorder';
    } else {
        $availability = 'out_of_stock';
    }

    $identity = tcdist_feed_identity( $product );
    $category = tcdist_feed_product_category( $base_id );
    $google_category = tcdist_feed_google_category( $category['term_id'] );

    $item_group_id = '';
    if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
        $item_group_id = 'wc-' . absint( $product->get_parent_id() );
    }

    $identifier_exists = ( '' !== $identity['gtin'] || ( '' !== $identity['brand'] && '' !== $identity['mpn'] ) ) ? 'yes' : 'no';

    return [
        'id'                      => 'wc-' . absint( $product->get_id() ),
        'title'                   => $title,
        'description'             => $description,
        'link'                    => $link,
        'image_link'              => (string) $images[0],
        'additional_image_link'   => implode( ', ', array_slice( $images, 1, 5 ) ),
        'additional_images'        => array_slice( $images, 1, 5 ),
        'price'                     => wc_format_decimal( $price, wc_get_price_decimals() ) . ' ' . $currency,
        'sale_price'                => $sale_price,
        'sale_price_effective_date' => $sale_price_effective_date,
        'availability'              => $availability,
        'condition'               => 'new',
        'brand'                   => tcdist_feed_plain_text( $identity['brand'], 100 ),
        'gtin'                    => preg_replace( '/\D+/', '', (string) $identity['gtin'] ),
        'mpn'                     => tcdist_feed_plain_text( $identity['mpn'], 70 ),
        'identifier_exists'       => $identifier_exists,
        'google_product_category' => tcdist_feed_plain_text( $google_category, 255 ),
        'product_type'            => tcdist_feed_plain_text( $category['path'], 1000 ),
        'item_group_id'           => $item_group_id,
        'sku'                     => tcdist_feed_plain_text( $identity['sku'], 127 ),
        'supplier'                => tcdist_feed_supplier( $base_id ),
        'shipping_weight'         => tcdist_feed_shipping_weight( $product ),
    ];
}

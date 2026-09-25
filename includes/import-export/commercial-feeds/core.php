<?php
/**
 * SEO System - Motor de catalogos comerciales.
 *
 * Mantiene un registro canonico de producto y genera feeds publicos por lotes
 * para evitar cargar 14k+ productos en una sola peticion PHP.
 *
 * @package SEOSystem
 * @subpackage ImportExport\CommercialFeeds
 * @since 2.3.7
 */

defined( 'ABSPATH' ) || exit;

const SEO_IE_CF_SETTINGS_OPTION = 'seo_ie_commercial_feeds_settings_v1';
const SEO_IE_CF_STATE_OPTION    = 'seo_ie_commercial_feeds_state_v1';
const SEO_IE_CF_HISTORY_OPTION  = 'seo_ie_commercial_feeds_history_v1';
const SEO_IE_CF_BATCH_SIZE      = 180;
const SEO_IE_CF_DAILY_HOOK      = 'seo_ie_cf_daily_refresh';
const SEO_IE_CF_BATCH_HOOK      = 'seo_ie_cf_build_batch';
const SEO_IE_CF_QUEUED_HOOK     = 'seo_ie_cf_queued_refresh';

/**
 * Registra hooks y planificacion.
 */
function seo_ie_cf_register_runtime() {
    add_action( 'init', 'seo_ie_cf_maybe_schedule_daily', 30 );
    add_action( SEO_IE_CF_DAILY_HOOK, 'seo_ie_cf_daily_refresh' );
    add_action( SEO_IE_CF_BATCH_HOOK, 'seo_ie_cf_process_batch', 10, 2 );
    add_action( SEO_IE_CF_QUEUED_HOOK, 'seo_ie_cf_run_queued_refresh', 10, 1 );
    add_action( 'seo_supplier_sync_products_changed', 'seo_ie_cf_on_supplier_sync_products_changed', 10, 1 );
}

/**
 * Ajustes por defecto.
 */
function seo_ie_cf_default_settings() {
    $country = 'ES';
    if ( function_exists( 'WC' ) && WC() && WC()->countries ) {
        $base = (string) WC()->countries->get_base_country();
        if ( '' !== $base ) {
            $country = strtoupper( $base );
        }
    }

    // No uses determine_locale(): en wp-admin puede devolver el idioma
    // personal del administrador y no el idioma comercial de la tienda.
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
        'batch_size'                  => SEO_IE_CF_BATCH_SIZE,
    ];
}

/**
 * Ajustes efectivos.
 */
function seo_ie_cf_settings() {
    $saved = get_option( SEO_IE_CF_SETTINGS_OPTION, [] );
    $saved = is_array( $saved ) ? $saved : [];
    $settings = wp_parse_args( $saved, seo_ie_cf_default_settings() );

    $known = array_keys( seo_ie_cf_channels() );
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
    $settings['daily_time'] = seo_ie_cf_sanitize_daily_time( $settings['daily_time'] ?? '03:30' );
    $settings['batch_size'] = max( 50, min( 500, absint( $settings['batch_size'] ?? SEO_IE_CF_BATCH_SIZE ) ) );

    return $settings;
}

/**
 * Normaliza una hora HH:MM para la regeneracion diaria de seguridad.
 */
function seo_ie_cf_sanitize_daily_time( $value ) {
    $value = trim( (string) $value );
    if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
        return '03:30';
    }
    return $value;
}

/**
 * Informacion efectiva del entorno. WordPress considera production si no se
 * define WP_ENVIRONMENT_TYPE; por seguridad un host que contiene staging,
 * stage, dev, test o local nunca se trata como produccion comercial.
 */
function seo_ie_cf_environment_info() {
    $reported = function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production';
    $host     = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
    $effective = $reported ?: 'production';
    $source    = 'wp_environment';

    if (
        'production' === $effective
        && '' !== $host
        && preg_match( '/(^|[.\-])(staging|stage|dev|test|local)([.\-]|$)/i', $host )
    ) {
        $effective = 'staging';
        $source    = 'host_safety';
    }

    return [
        'effective' => sanitize_key( $effective ),
        'reported'  => sanitize_key( $reported ),
        'host'      => $host,
        'source'    => $source,
    ];
}

/**
 * Devuelve true solo para produccion comercial efectiva.
 */
function seo_ie_cf_is_production() {
    $environment = seo_ie_cf_environment_info();
    return 'production' === ( $environment['effective'] ?? '' );
}

/**
 * Directorio y URL publicos de feeds.
 */
function seo_ie_cf_storage() {
    $upload = wp_upload_dir( null, false );
    if ( ! empty( $upload['error'] ) ) {
        return new WP_Error( 'seo_ie_cf_uploads', (string) $upload['error'] );
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
        return new WP_Error( 'seo_ie_cf_mkdir', 'No se pudo crear wp-content/uploads/seo-system-feeds.' );
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
function seo_ie_cf_state() {
    $state = get_option( SEO_IE_CF_STATE_OPTION, [] );
    return is_array( $state ) ? $state : [];
}

function seo_ie_cf_save_state( array $state ) {
    $state['updated_at'] = current_time( 'mysql', true );
    update_option( SEO_IE_CF_STATE_OPTION, $state, false );
    return $state;
}


/**
 * Lock ligero por ejecucion para impedir que Action Scheduler y el watchdog
 * del navegador procesen el mismo lote a la vez.
 */
function seo_ie_cf_batch_lock_name( $run_id ) {
    return 'seo_ie_cf_batch_lock_' . md5( (string) $run_id );
}

function seo_ie_cf_batch_is_locked( $run_id ) {
    $key = seo_ie_cf_batch_lock_name( $run_id );
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

function seo_ie_cf_acquire_batch_lock( $run_id ) {
    $key = seo_ie_cf_batch_lock_name( $run_id );
    if ( seo_ie_cf_batch_is_locked( $run_id ) ) {
        return false;
    }
    return (bool) add_option( $key, time(), '', 'no' );
}

function seo_ie_cf_release_batch_lock( $run_id ) {
    delete_option( seo_ie_cf_batch_lock_name( $run_id ) );
}

/**
 * Retira la accion pendiente del lote actual. Las acciones antiguas que ya
 * hayan sido reclamadas tambien quedan invalidadas por status/run_id/cursor.
 */
function seo_ie_cf_unschedule_batch( $run_id, $cursor ) {
    $args = [ (string) $run_id, absint( $cursor ) ];

    if ( function_exists( 'as_unschedule_action' ) ) {
        for ( $i = 0; $i < 10; $i++ ) {
            $removed = as_unschedule_action( SEO_IE_CF_BATCH_HOOK, $args, SEO_IE_CF_GROUP );
            if ( false === $removed || null === $removed ) {
                break;
            }
        }
    }

    wp_clear_scheduled_hook( SEO_IE_CF_BATCH_HOOK, $args );
}

/**
 * Detiene una generacion sin publicar archivos parciales. Los temporales se
 * eliminan al iniciar una ejecucion nueva, evitando carreras con un worker que
 * pudiera estar terminando su lote en otra peticion.
 */
function seo_ie_cf_stop_build( $reason = 'manual' ) {
    $state = seo_ie_cf_state();
    if ( 'running' !== ( $state['status'] ?? '' ) ) {
        return $state;
    }

    $run_id = (string) ( $state['run_id'] ?? '' );
    $cursor = absint( $state['cursor'] ?? 0 );

    $state['status']          = 'stopped';
    $state['stop_reason']     = sanitize_key( (string) $reason );
    $state['completed_at']    = current_time( 'mysql', true );
    $state['pending_refresh'] = '';
    seo_ie_cf_save_state( $state );

    seo_ie_cf_unschedule_batch( $run_id, $cursor );
    seo_ie_cf_record_history( $state );

    return $state;
}

/**
 * Historial compacto de las ultimas generaciones terminadas.
 */
function seo_ie_cf_history() {
    $history = get_option( SEO_IE_CF_HISTORY_OPTION, [] );
    return is_array( $history ) ? $history : [];
}

function seo_ie_cf_record_history( array $state ) {
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
            seo_ie_cf_history(),
            static function ( $item ) use ( $run_id ) {
                return ! is_array( $item ) || (string) ( $item['run_id'] ?? '' ) !== $run_id;
            }
        )
    );
    array_unshift( $history, $entry );
    update_option( SEO_IE_CF_HISTORY_OPTION, array_slice( $history, 0, 20 ), false );
}

/**
 * Finaliza un estado fallido sin tocar el ultimo feed valido publicado.
 */
function seo_ie_cf_mark_failed( array $state, $message = '' ) {
    $state['status'] = 'failed';
    if ( '' !== trim( (string) $message ) ) {
        $state['errors'][] = sanitize_text_field( (string) $message );
        $state['errors'] = array_slice( (array) $state['errors'], -20 );
    }
    $state['completed_at'] = current_time( 'mysql', true );
    seo_ie_cf_save_state( $state );
    seo_ie_cf_cleanup_temp_files( $state );
    seo_ie_cf_record_history( $state );
    return $state;
}

/**
 * Calcula la siguiente hora diaria en la zona horaria configurada en WordPress.
 */
function seo_ie_cf_next_daily_timestamp( $daily_time = '03:30' ) {
    $daily_time = seo_ie_cf_sanitize_daily_time( $daily_time );
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
function seo_ie_cf_next_daily_scheduled() {
    if ( function_exists( 'as_next_scheduled_action' ) ) {
        $next = as_next_scheduled_action( SEO_IE_CF_DAILY_HOOK, [], SEO_IE_CF_GROUP );
        if ( is_numeric( $next ) && (int) $next > 0 ) {
            return (int) $next;
        }
    }
    $next = wp_next_scheduled( SEO_IE_CF_DAILY_HOOK );
    return $next ? (int) $next : 0;
}

/**
 * Elimina cualquier regeneracion diaria pendiente.
 */
function seo_ie_cf_unschedule_daily() {
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( SEO_IE_CF_DAILY_HOOK, [], SEO_IE_CF_GROUP );
    }
    wp_clear_scheduled_hook( SEO_IE_CF_DAILY_HOOK );
}

/**
 * Agenda una ejecucion diaria a una hora visible y estable en hora local.
 * Se usa una accion unica y, al ejecutarse, se agenda la del dia siguiente;
 * asi no deriva una hora por los cambios de horario de verano/invierno.
 */
function seo_ie_cf_maybe_schedule_daily( $force = false ) {
    $settings = seo_ie_cf_settings();
    $enabled  = ! empty( $settings['auto_refresh'] ) && seo_ie_cf_is_production();

    if ( ! $enabled ) {
        seo_ie_cf_unschedule_daily();
        return;
    }

    if ( $force ) {
        seo_ie_cf_unschedule_daily();
    }

    if ( seo_ie_cf_next_daily_scheduled() > 0 ) {
        return;
    }

    $timestamp = seo_ie_cf_next_daily_timestamp( $settings['daily_time'] );
    if ( function_exists( 'as_schedule_single_action' ) ) {
        as_schedule_single_action( $timestamp, SEO_IE_CF_DAILY_HOOK, [], SEO_IE_CF_GROUP, false );
        return;
    }

    wp_schedule_single_event( $timestamp, SEO_IE_CF_DAILY_HOOK );
}

/**
 * Refresco automatico.
 */
function seo_ie_cf_daily_refresh() {
    if ( ! seo_ie_cf_is_production() ) {
        seo_ie_cf_unschedule_daily();
        return;
    }

    seo_ie_cf_start_build( 'daily_safety' );
    // La accion diaria es unica: programa la siguiente respetando la hora local.
    seo_ie_cf_maybe_schedule_daily( true );
}

/**
 * Programa una regeneracion diferida, evitando duplicados.
 */
function seo_ie_cf_schedule_refresh( $origin = 'supplier_sync', $delay = 120 ) {
    if ( ! seo_ie_cf_is_production() ) {
        return false;
    }

    $origin = sanitize_key( (string) $origin );
    $args   = [ $origin ];
    $delay  = max( 30, absint( $delay ) );

    if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( SEO_IE_CF_QUEUED_HOOK, $args, SEO_IE_CF_GROUP ) ) {
        return true;
    }
    if ( false !== wp_next_scheduled( SEO_IE_CF_QUEUED_HOOK, $args ) ) {
        return true;
    }

    if ( function_exists( 'as_schedule_single_action' ) ) {
        return (int) as_schedule_single_action( time() + $delay, SEO_IE_CF_QUEUED_HOOK, $args, SEO_IE_CF_GROUP, true ) > 0;
    }

    $scheduled = wp_schedule_single_event( time() + $delay, SEO_IE_CF_QUEUED_HOOK, $args, true );
    return ! is_wp_error( $scheduled ) && true === $scheduled;
}

/**
 * Solicita una regeneracion. Si ya hay una en curso se recuerda la peticion y
 * se lanza otra al terminar, para no perder cambios aplicados a IDs ya leidos.
 */
function seo_ie_cf_request_refresh( $origin = 'supplier_sync' ) {
    if ( ! seo_ie_cf_is_production() ) {
        return false;
    }

    $state = seo_ie_cf_state();
    if ( 'running' === ( $state['status'] ?? '' ) ) {
        $state['pending_refresh'] = sanitize_key( (string) $origin );
        $state['pending_refresh_at'] = current_time( 'mysql', true );
        seo_ie_cf_save_state( $state );
        return true;
    }

    return seo_ie_cf_schedule_refresh( $origin, 120 );
}

/**
 * Disparo emitido por Sincronizacion V2 cuando ya ha terminado de aplicar
 * cambios reales sobre productos WooCommerce.
 */
function seo_ie_cf_on_supplier_sync_products_changed( $context = [] ) {
    $settings = seo_ie_cf_settings();
    if ( empty( $settings['refresh_after_supplier_sync'] ) ) {
        return;
    }
    seo_ie_cf_request_refresh( 'supplier_sync' );
}

/**
 * Ejecuta una regeneracion diferida.
 */
function seo_ie_cf_run_queued_refresh( $origin = 'supplier_sync' ) {
    if ( ! seo_ie_cf_is_production() ) {
        return;
    }

    $result = seo_ie_cf_start_build( sanitize_key( (string) $origin ) );
    if ( is_wp_error( $result ) && 'seo_ie_cf_running' === $result->get_error_code() ) {
        seo_ie_cf_request_refresh( $origin );
    }
}

/**
 * Encola una tanda de trabajo.
 */
function seo_ie_cf_enqueue_batch( $run_id, $cursor ) {
    $args = [ (string) $run_id, absint( $cursor ) ];

    if ( function_exists( 'as_enqueue_async_action' ) ) {
        return (int) as_enqueue_async_action( SEO_IE_CF_BATCH_HOOK, $args, SEO_IE_CF_GROUP ) > 0;
    }

    $scheduled = wp_schedule_single_event( time() + 5, SEO_IE_CF_BATCH_HOOK, $args, true );
    return ! is_wp_error( $scheduled ) && true === $scheduled;
}

/**
 * Total de ofertas candidatas antes de aplicar reglas editoriales.
 */
function seo_ie_cf_candidate_count() {
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
 * Inicia una generacion completa. Devuelve el estado o WP_Error.
 */
function seo_ie_cf_start_build( $origin = 'manual' ) {
    $current = seo_ie_cf_state();
    $last_ts = ! empty( $current['updated_at'] ) ? strtotime( (string) $current['updated_at'] . ' UTC' ) : 0;

    if (
        'running' === ( $current['status'] ?? '' )
        && $last_ts
        && ( time() - $last_ts ) < 2 * HOUR_IN_SECONDS
    ) {
        return new WP_Error( 'seo_ie_cf_running', 'Ya hay una generacion de inventario en curso. Pulsa Parar antes de iniciar otra.' );
    }

    // Limpia temporales abandonados de una ejecucion anterior detenida,
    // fallida o considerada obsoleta. Los feeds finales validos no se tocan.
    if ( ! empty( $current['files'] ) ) {
        seo_ie_cf_cleanup_temp_files( $current );
    }

    $storage = seo_ie_cf_storage();
    if ( is_wp_error( $storage ) ) {
        return $storage;
    }

    $settings = seo_ie_cf_settings();
    $channels = seo_ie_cf_channels();
    $run_id   = gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );
    $files    = [];

    foreach ( $settings['enabled_channels'] as $channel ) {
        if ( empty( $channels[ $channel ] ) ) {
            continue;
        }
        $final_name = sanitize_file_name( (string) $channels[ $channel ]['filename'] );
        $final_path = trailingslashit( $storage['dir'] ) . $final_name;
        $temp_path  = $final_path . '.tmp-' . sanitize_file_name( $run_id );
        $header = seo_ie_cf_channel_write_header( $channel, $temp_path );
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
        return new WP_Error( 'seo_ie_cf_no_channels', 'No hay canales comerciales activos.' );
    }

    $state = [
        'run_id'           => $run_id,
        'status'           => 'running',
        'origin'           => sanitize_key( (string) $origin ),
        'started_at'       => current_time( 'mysql', true ),
        'updated_at'       => current_time( 'mysql', true ),
        'completed_at'     => '',
        'cursor'           => 0,
        'candidate_total'  => seo_ie_cf_candidate_count(),
        'processed'        => 0,
        'written'          => 0,
        'excluded'         => 0,
        'excluded_reasons' => [],
        'errors'           => [],
        'files'            => $files,
        'environment'      => (string) ( seo_ie_cf_environment_info()['effective'] ?? 'production' ),
        'host'             => (string) ( seo_ie_cf_environment_info()['host'] ?? '' ),
        'pending_refresh'  => '',
    ];
    seo_ie_cf_save_state( $state );

    if ( ! seo_ie_cf_enqueue_batch( $run_id, 0 ) ) {
        seo_ie_cf_mark_failed( $state, 'No se pudo programar el primer lote.' );
        return new WP_Error( 'seo_ie_cf_enqueue', 'No se pudo programar el primer lote.' );
    }

    // Ejecuta el primer lote en la propia peticion. Asi el arranque no depende
    // de que Action Scheduler o WP-Cron despierten inmediatamente. La accion
    // ya encolada queda como respaldo y sera descartada por cursor si llega tarde.
    seo_ie_cf_process_batch( $run_id, 0 );

    return seo_ie_cf_state();
}

/**
 * Devuelve el siguiente conjunto de IDs de producto/variacion.
 */
function seo_ie_cf_next_ids( $cursor, $limit ) {
    global $wpdb;

    $cursor = absint( $cursor );
    $limit  = max( 1, min( 500, absint( $limit ) ) );

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
 * Ejecuta una tanda de generacion.
 */
function seo_ie_cf_process_batch( $run_id, $cursor = 0 ) {
    $run_id = (string) $run_id;
    $cursor = absint( $cursor );

    $state = seo_ie_cf_state();
    if (
        'running' !== ( $state['status'] ?? '' )
        || ! hash_equals( (string) ( $state['run_id'] ?? '' ), $run_id )
        || absint( $state['cursor'] ?? 0 ) !== $cursor
    ) {
        return;
    }

    if ( ! seo_ie_cf_acquire_batch_lock( $run_id ) ) {
        return;
    }

    try {
        // Revalida tras adquirir el lock: otra peticion pudo detener o avanzar
        // la ejecucion mientras esperabamos.
        $state = seo_ie_cf_state();
        if (
            'running' !== ( $state['status'] ?? '' )
            || ! hash_equals( (string) ( $state['run_id'] ?? '' ), $run_id )
            || absint( $state['cursor'] ?? 0 ) !== $cursor
        ) {
            return;
        }

        $settings = seo_ie_cf_settings();
        $ids = seo_ie_cf_next_ids( $cursor, $settings['batch_size'] );

        if ( empty( $ids ) ) {
            seo_ie_cf_finish_build( $state );
            return;
        }

        foreach ( $ids as $product_id ) {
            $state['processed']++;
            $result = seo_ie_cf_product_record( $product_id );

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
                $write = seo_ie_cf_channel_append_record( $channel, (string) $file['temp'], $result );
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
                $state['cursor'] = (int) $product_id;
                seo_ie_cf_mark_failed( $state );
                return;
            }
        }

        // Si el usuario pulso Parar durante este lote, no sobrescribas ese
        // estado con una copia antigua marcada como running.
        $latest = seo_ie_cf_state();
        if (
            'running' !== ( $latest['status'] ?? '' )
            || ! hash_equals( (string) ( $latest['run_id'] ?? '' ), $run_id )
        ) {
            return;
        }

        $state['cursor'] = (int) end( $ids );
        seo_ie_cf_save_state( $state );

        if ( ! seo_ie_cf_enqueue_batch( $run_id, $state['cursor'] ) ) {
            seo_ie_cf_mark_failed( $state, 'No se pudo programar el siguiente lote.' );
        }
    } finally {
        seo_ie_cf_release_batch_lock( $run_id );
    }
}

/**
 * Watchdog asistido por navegador. Action Scheduler sigue siendo la via
 * principal; si no hay actividad durante unos segundos y la pestana permanece
 * abierta, ejecuta exactamente un lote protegido por lock.
 */
function seo_ie_cf_browser_continue( $minimum_idle = 8 ) {
    $state = seo_ie_cf_state();
    if ( 'running' !== ( $state['status'] ?? '' ) ) {
        return [ 'ran' => false, 'message' => 'La generacion no esta en ejecucion.' ];
    }

    $run_id = (string) ( $state['run_id'] ?? '' );
    $cursor = absint( $state['cursor'] ?? 0 );
    if ( '' === $run_id ) {
        return [ 'ran' => false, 'message' => 'No hay una ejecucion recuperable.' ];
    }

    $last_ts = ! empty( $state['updated_at'] ) ? strtotime( (string) $state['updated_at'] . ' UTC' ) : 0;
    $idle = $last_ts ? max( 0, time() - $last_ts ) : PHP_INT_MAX;
    if ( $idle < max( 5, absint( $minimum_idle ) ) ) {
        return [ 'ran' => false, 'message' => 'El ultimo lote es reciente.' ];
    }

    if ( seo_ie_cf_batch_is_locked( $run_id ) ) {
        return [ 'ran' => false, 'message' => 'Hay un lote activo.' ];
    }

    // Retira el lote pendiente equivalente para no acumular duplicados y
    // procesa uno directamente desde la peticion AJAX del administrador.
    seo_ie_cf_unschedule_batch( $run_id, $cursor );
    seo_ie_cf_process_batch( $run_id, $cursor );

    $after = seo_ie_cf_state();
    return [
        'ran'     => absint( $after['processed'] ?? 0 ) > absint( $state['processed'] ?? 0 ) || ( $after['status'] ?? '' ) !== 'running',
        'message' => 'Watchdog ejecutado.',
    ];
}

/**
 * Elimina temporales de una generacion fallida. Los feeds finales anteriores
 * permanecen intactos, de modo que un fallo nunca deja un inventario parcial
 * publicado en una URL estable.
 */
function seo_ie_cf_cleanup_temp_files( array $state ) {
    foreach ( (array) ( $state['files'] ?? [] ) as $file ) {
        $temp = (string) ( $file['temp'] ?? '' );
        if ( '' !== $temp && file_exists( $temp ) ) {
            @unlink( $temp );
        }
    }
}

/**
 * Cierra y publica los archivos temporales.
 */
function seo_ie_cf_finish_build( array $state ) {
    foreach ( (array) $state['files'] as $channel => $file ) {
        $temp  = (string) ( $file['temp'] ?? '' );
        $final = (string) ( $file['final'] ?? '' );
        if ( '' === $temp || '' === $final || ! file_exists( $temp ) ) {
            $state['status'] = 'failed';
            $state['errors'][] = $channel . ': falta el archivo temporal al finalizar.';
            continue;
        }

        $close = seo_ie_cf_channel_finalize( $channel, $temp );
        if ( is_wp_error( $close ) ) {
            $state['status'] = 'failed';
            $state['errors'][] = $channel . ': ' . $close->get_error_message();
            continue;
        }

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
    seo_ie_cf_save_state( $state );
    seo_ie_cf_record_history( $state );

    if ( '' !== $pending_refresh && seo_ie_cf_is_production() ) {
        seo_ie_cf_schedule_refresh( $pending_refresh, 60 );
    }
}

/**
 * Escapado XML.
 */
function seo_ie_cf_xml( $value ) {
    return htmlspecialchars( (string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
}

/**
 * Texto plano compacto para feeds.
 */
function seo_ie_cf_plain_text( $value, $max = 5000 ) {
    $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $value = preg_replace( '/\s+/u', ' ', $value );
    $value = trim( (string) $value );

    if ( $max > 0 && function_exists( 'mb_substr' ) ) {
        return mb_substr( $value, 0, $max );
    }
    return $max > 0 ? substr( $value, 0, $max ) : $value;
}

/**
 * Categoria hoja mas profunda y ruta propia del producto.
 *
 * @return array{term_id:int,path:string}
 */
function seo_ie_cf_product_category( $product_id ) {
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
            $names[] = seo_ie_cf_plain_text( $term->name, 180 );
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
function seo_ie_cf_google_category( $term_id ) {
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
function seo_ie_cf_product_images( $product, $limit = 6 ) {
    $ids = [ absint( $product->get_id() ) ];
    if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
        $ids[] = absint( $product->get_parent_id() );
    }
    $ids = array_values( array_unique( array_filter( $ids ) ) );
    $urls = [];

    foreach ( $ids as $product_id ) {
        $wc = wc_get_product( $product_id );
        if ( $wc ) {
            $attachment_ids = array_merge( [ absint( $wc->get_image_id() ) ], array_map( 'absint', (array) $wc->get_gallery_image_ids() ) );
            foreach ( $attachment_ids as $attachment_id ) {
                if ( $attachment_id < 1 ) {
                    continue;
                }
                $url = esc_url_raw( (string) wp_get_attachment_image_url( $attachment_id, 'full' ) );
                if ( $url && ! isset( $urls[ $url ] ) ) {
                    $urls[ $url ] = $url;
                }
                if ( count( $urls ) >= $limit ) {
                    break 2;
                }
            }
        }

        if ( function_exists( 'seo_images_get_external_product_images' ) ) {
            $rows = (array) seo_images_get_external_product_images( $product_id, $limit );
            foreach ( $rows as $row ) {
                $stored_http = absint( $row['http_status'] ?? 0 );
                $last_checked = trim( (string) ( $row['last_checked'] ?? '' ) );
                if ( in_array( $stored_http, [ 404, 410 ], true ) && '' !== $last_checked ) {
                    continue;
                }

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
function seo_ie_cf_identity( $product ) {
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
                    $identity[ $key ] = seo_ie_cf_plain_text( $candidate[ $key ], 190 );
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
                    $identity[ $field ] = 'gtin' === $field ? preg_replace( '/\D+/', '', $value ) : seo_ie_cf_plain_text( $value, 190 );
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
                $identity['brand'] = seo_ie_cf_plain_text( reset( $terms )->name, 100 );
                break;
            }
        }
    }

    if ( '' === $identity['brand'] ) {
        foreach ( [ '_brand', 'brand', 'marca', 'Brand', 'Marca' ] as $key ) {
            $value = trim( (string) get_post_meta( $base_id, $key, true ) );
            if ( '' !== $value ) {
                $identity['brand'] = seo_ie_cf_plain_text( $value, 100 );
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
 * Proveedor de origen si esta disponible.
 */
function seo_ie_cf_supplier( $product_id ) {
    foreach ( [ '_seo_proveedor', '_seo_supplier', 'supplier', 'proveedor' ] as $key ) {
        $value = trim( (string) get_post_meta( absint( $product_id ), $key, true ) );
        if ( '' !== $value ) {
            return seo_ie_cf_plain_text( $value, 120 );
        }
    }
    return '';
}

/**
 * Peso con unidad WooCommerce.
 */
function seo_ie_cf_shipping_weight( $product ) {
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
 * Registro canonico de una oferta comercial.
 *
 * @return array<string,mixed>|WP_Error
 */
function seo_ie_cf_product_record( $product_id ) {
    if ( ! function_exists( 'wc_get_product' ) ) {
        return new WP_Error( 'woocommerce_unavailable', 'WooCommerce no esta disponible.' );
    }

    $product = wc_get_product( absint( $product_id ) );
    if ( ! $product ) {
        return new WP_Error( 'invalid_product', 'Producto WooCommerce no resoluble.' );
    }

    if ( $product->is_type( 'variable' ) ) {
        return new WP_Error( 'variable_parent', 'El producto variable se representa mediante sus variaciones.' );
    }

    if ( method_exists( $product, 'get_catalog_visibility' ) && 'hidden' === $product->get_catalog_visibility() ) {
        return new WP_Error( 'catalog_hidden', 'Producto oculto del catalogo.' );
    }

    $base_id = absint( $product->get_id() );
    if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
        $base_id = absint( $product->get_parent_id() );
        if ( 'publish' !== get_post_status( $base_id ) ) {
            return new WP_Error( 'parent_not_published', 'La variacion no tiene padre publicado.' );
        }
    }

    $title = seo_ie_cf_plain_text( $product->get_name(), 150 );
    if ( '' === $title ) {
        return new WP_Error( 'missing_title', 'Titulo vacio.' );
    }

    $description = seo_ie_cf_plain_text( $product->get_short_description(), 5000 );
    if ( '' === $description ) {
        $description = seo_ie_cf_plain_text( $product->get_description(), 5000 );
    }
    if ( '' === $description && $base_id !== $product->get_id() ) {
        $parent = wc_get_product( $base_id );
        if ( $parent ) {
            $description = seo_ie_cf_plain_text( $parent->get_short_description(), 5000 );
            if ( '' === $description ) {
                $description = seo_ie_cf_plain_text( $parent->get_description(), 5000 );
            }
        }
    }
    if ( '' === $description ) {
        return new WP_Error( 'missing_description', 'Descripcion vacia.' );
    }

    $link = esc_url_raw( (string) $product->get_permalink() );
    if ( '' === $link ) {
        return new WP_Error( 'missing_link', 'URL de producto vacia.' );
    }

    $images = seo_ie_cf_product_images( $product, 6 );
    if ( empty( $images ) ) {
        return new WP_Error( 'missing_image', 'Sin imagen local ni externa.' );
    }

    $current_price = function_exists( 'wc_get_price_to_display' )
        ? (float) wc_get_price_to_display( $product )
        : (float) $product->get_price();
    if ( $current_price <= 0 ) {
        return new WP_Error( 'missing_price', 'Precio no valido.' );
    }

    $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
    $currency = strtoupper( sanitize_text_field( (string) $currency ) );

    $regular_raw = (float) $product->get_regular_price();
    $regular_price = $regular_raw > 0 && function_exists( 'wc_get_price_to_display' )
        ? (float) wc_get_price_to_display( $product, [ 'price' => $regular_raw ] )
        : $regular_raw;

    $price = $current_price;
    $sale_price = '';
    if ( $product->is_on_sale() && $regular_price > $current_price ) {
        $price = $regular_price;
        $sale_price = wc_format_decimal( $current_price, wc_get_price_decimals() ) . ' ' . $currency;
    }

    $stock_status = sanitize_key( (string) $product->get_stock_status() );
    if ( 'instock' === $stock_status ) {
        $availability = 'in_stock';
    } elseif ( 'onbackorder' === $stock_status ) {
        $availability = 'backorder';
    } else {
        $availability = 'out_of_stock';
    }

    $identity = seo_ie_cf_identity( $product );
    $category = seo_ie_cf_product_category( $base_id );
    $google_category = seo_ie_cf_google_category( $category['term_id'] );

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
        'price'                   => wc_format_decimal( $price, wc_get_price_decimals() ) . ' ' . $currency,
        'sale_price'              => $sale_price,
        'availability'            => $availability,
        'condition'               => 'new',
        'brand'                   => seo_ie_cf_plain_text( $identity['brand'], 100 ),
        'gtin'                    => preg_replace( '/\D+/', '', (string) $identity['gtin'] ),
        'mpn'                     => seo_ie_cf_plain_text( $identity['mpn'], 70 ),
        'identifier_exists'       => $identifier_exists,
        'google_product_category' => seo_ie_cf_plain_text( $google_category, 255 ),
        'product_type'            => seo_ie_cf_plain_text( $category['path'], 1000 ),
        'item_group_id'           => $item_group_id,
        'sku'                     => seo_ie_cf_plain_text( $identity['sku'], 127 ),
        'supplier'                => seo_ie_cf_supplier( $base_id ),
        'shipping_weight'         => seo_ie_cf_shipping_weight( $product ),
    ];
}

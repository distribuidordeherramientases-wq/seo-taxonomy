<?php
/**
 * SEO System - Administracion de catalogos comerciales.
 *
 * @package SEOSystem
 * @subpackage ImportExport\CommercialFeeds
 * @since 2.3.7
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_seo_ie_cf_save_settings', 'seo_ie_cf_admin_save_settings' );
add_action( 'admin_post_seo_ie_cf_regenerate', 'seo_ie_cf_admin_regenerate' );
add_action( 'admin_post_seo_ie_cf_stop', 'seo_ie_cf_admin_stop' );
add_action( 'wp_ajax_seo_ie_cf_tick', 'seo_ie_cf_admin_ajax_tick' );

function seo_ie_cf_admin_redirect_url( array $args = [] ) {
    $base = add_query_arg(
        [
            'page'       => 'seo-import-export',
            'seo_ie_tab' => 'inventarios-comerciales',
        ],
        admin_url( 'admin.php' )
    );

    return add_query_arg( $args, $base );
}

function seo_ie_cf_admin_save_settings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para configurar los inventarios comerciales.', 'seo-system' ) );
    }
    check_admin_referer( 'seo_ie_cf_save_settings' );

    $channels = array_keys( seo_ie_cf_channels() );
    $enabled  = array_values(
        array_intersect(
            $channels,
            array_map( 'sanitize_key', (array) ( $_POST['enabled_channels'] ?? [] ) )
        )
    );

    if ( empty( $enabled ) ) {
        $enabled = [ 'google' ];
    }

    $country = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $_POST['country'] ?? 'ES' ) ), 0, 2 ) );
    $language = strtolower( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $_POST['language'] ?? 'es' ) ), 0, 2 ) );

    $settings = [
        'enabled_channels'            => $enabled,
        'country'                     => $country ?: 'ES',
        'language'                    => $language ?: 'es',
        'auto_refresh'                => empty( $_POST['auto_refresh'] ) ? 0 : 1,
        'daily_time'                  => seo_ie_cf_sanitize_daily_time( $_POST['daily_time'] ?? '03:30' ),
        'refresh_after_supplier_sync' => empty( $_POST['refresh_after_supplier_sync'] ) ? 0 : 1,
        'batch_size'                  => absint( seo_ie_cf_settings()['batch_size'] ?? SEO_IE_CF_BATCH_SIZE ), // Legacy: el lote real lo regula Procesos.
    ];

    update_option( SEO_IE_CF_SETTINGS_OPTION, $settings, false );

    // Si se desactiva un receptor, elimina su archivo estable para evitar que
    // una plataforma siga leyendo indefinidamente un inventario obsoleto.
    $storage = seo_ie_cf_storage();
    if ( ! is_wp_error( $storage ) ) {
        foreach ( seo_ie_cf_channels() as $channel => $config ) {
            if ( in_array( $channel, $enabled, true ) ) {
                continue;
            }
            $filename = sanitize_file_name( (string) ( $config['filename'] ?? '' ) );
            if ( '' === $filename ) {
                continue;
            }
            $path = trailingslashit( $storage['dir'] ) . $filename;
            if ( file_exists( $path ) ) {
                @unlink( $path );
            }
        }
    }

    seo_ie_cf_maybe_schedule_daily( true );

    wp_safe_redirect( seo_ie_cf_admin_redirect_url( [ 'cf_saved' => '1' ] ) );
    exit;
}

function seo_ie_cf_admin_regenerate() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para regenerar los inventarios comerciales.', 'seo-system' ) );
    }
    check_admin_referer( 'seo_ie_cf_regenerate' );

    $result = seo_ie_cf_start_build( 'manual' );
    if ( is_wp_error( $result ) ) {
        wp_safe_redirect(
            seo_ie_cf_admin_redirect_url(
                [
                    'cf_error' => rawurlencode( $result->get_error_message() ),
                ]
            )
        );
        exit;
    }

    wp_safe_redirect( seo_ie_cf_admin_redirect_url( [ 'cf_started' => '1' ] ) );
    exit;
}


/**
 * Detiene la ejecucion actual sin publicar archivos parciales.
 */
function seo_ie_cf_admin_stop() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para detener los inventarios comerciales.', 'seo-system' ) );
    }
    check_admin_referer( 'seo_ie_cf_stop' );

    seo_ie_cf_stop_build( 'manual' );
    wp_safe_redirect( seo_ie_cf_admin_redirect_url( [ 'cf_stopped' => '1' ] ) );
    exit;
}

/**
 * Pulso administrativo. No ejecuta productos: solo despierta el Gestor de
 * workers central para que este reparta la siguiente ventana de trabajo.
 */
function seo_ie_cf_admin_ajax_tick() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
    }

    check_ajax_referer( 'seo_ie_cf_tick', 'nonce' );
    $continuation = seo_ie_cf_browser_continue( 8 );
    $state = seo_ie_cf_state();

    wp_send_json_success(
        [
            'continuation' => $continuation,
            'status'       => sanitize_key( (string) ( $state['status'] ?? '' ) ),
            'processed'    => absint( $state['processed'] ?? 0 ),
            'written'      => absint( $state['written'] ?? 0 ),
            'excluded'     => absint( $state['excluded'] ?? 0 ),
            'cursor'       => absint( $state['cursor'] ?? 0 ),
            'updated_at'   => sanitize_text_field( (string) ( $state['updated_at'] ?? '' ) ),
        ]
    );
}

/**
 * Formatea bytes para la tabla administrativa.
 */
function seo_ie_cf_admin_bytes( $bytes ) {
    $bytes = max( 0, (int) $bytes );
    if ( function_exists( 'size_format' ) ) {
        return size_format( $bytes, 2 );
    }
    return number_format_i18n( $bytes ) . ' B';
}

/**
 * Presenta una fecha UTC almacenada por el motor en hora local de WordPress.
 */
function seo_ie_cf_admin_local_datetime( $utc_mysql ) {
    $utc_mysql = trim( (string) $utc_mysql );
    if ( '' === $utc_mysql ) {
        return '—';
    }
    $timestamp = strtotime( $utc_mysql . ' UTC' );
    return $timestamp ? wp_date( 'd/m/Y H:i:s', $timestamp, wp_timezone() ) : $utc_mysql;
}

/**
 * Etiqueta legible del origen de una generacion.
 */
function seo_ie_cf_admin_origin_label( $origin ) {
    $labels = [
        'manual'          => 'Manual',
        'manual_processes'=> 'Manual desde Procesos',
        'supplier_sync' => 'Fin de sincronizacion de proveedores',
        'daily_safety'  => 'Regeneracion diaria de seguridad',
        'scheduled'     => 'Programada',
    ];
    $origin = sanitize_key( (string) $origin );
    return $labels[ $origin ] ?? ( $origin ? $origin : '—' );
}

/**
 * Render principal de la pestana.
 */
function seo_ie_cf_render_admin() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $settings    = seo_ie_cf_settings();
    $channels    = seo_ie_cf_channels();
    $state       = seo_ie_cf_state();
    $history     = seo_ie_cf_history();
    $storage     = seo_ie_cf_storage();
    $environment = seo_ie_cf_environment_info();
    $is_staging  = ! seo_ie_cf_is_production();
    $next_daily  = seo_ie_cf_next_daily_scheduled();
    $supervisor_settings = function_exists( 'seo_process_supervisor_settings' ) ? seo_process_supervisor_settings() : [];
    $manager_enabled = ! empty( $supervisor_settings['enabled'] ) && ! empty( $supervisor_settings['commercial_feeds'] );

    if ( isset( $_GET['cf_saved'] ) ) {
        echo '<div class="notice notice-success inline"><p>Configuracion guardada.</p></div>';
    }
    if ( isset( $_GET['cf_started'] ) ) {
        echo '<div class="notice notice-info inline"><p>Generacion iniciada y entregada al Gestor de workers. El lote se adapta automaticamente al tiempo real de respuesta y a la carga observada.</p></div>';
    }
    if ( isset( $_GET['cf_stopped'] ) ) {
        echo '<div class="notice notice-warning inline"><p>Generacion detenida por el usuario. No se ha publicado ningun archivo parcial.</p></div>';
    }
    if ( isset( $_GET['cf_error'] ) ) {
        echo '<div class="notice notice-error inline"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['cf_error'] ) ) ) ) . '</p></div>';
    }

    if ( $is_staging ) {
        echo '<div class="notice notice-warning inline"><p><strong>STAGING:</strong> esta generacion usa los productos y URLs de este entorno y sirve solo para pruebas. No se programan actualizaciones comerciales ni deben registrarse estas URLs en Google, Microsoft o Pinterest.</p></div>';
    } else {
        echo '<div class="notice notice-success inline"><p><strong>PRODUCCION:</strong> este entorno genera los inventarios comerciales reales. Las plataformas descargan desde estas URLs; no existe un envio directo desde WordPress.</p></div>';
    }

    if ( ! $manager_enabled ) {
        echo '<div class="notice notice-error inline"><p><strong>Gestor de workers:</strong> Inventarios comerciales no esta habilitado en Procesos &gt; Gestor de workers. Puedes iniciar una generacion, pero no avanzara hasta habilitar ese proceso.</p></div>';
    }

    $status = (string) ( $state['status'] ?? 'never' );
    $processed = absint( $state['processed'] ?? 0 );
    $total = absint( $state['candidate_total'] ?? 0 );
    $progress = $total > 0 ? min( 100, round( 100 * $processed / $total, 1 ) ) : 0;
    $updated_ts = ! empty( $state['updated_at'] ) ? strtotime( (string) $state['updated_at'] . ' UTC' ) : 0;
    $idle_seconds = $updated_ts ? max( 0, time() - $updated_ts ) : 0;
    ?>
    <div style="max-width:1220px;">
        <h2>Inventarios comerciales</h2>
        <p>
            Genera fuentes publicas de productos para plataformas que descargan el catalogo por URL.
            No sustituye los sitemaps: aqui se publica inventario comercial con precio, stock, imagen,
            GTIN/MPN, marca y categoria.
        </p>

        <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;">
            <strong>Entorno detectado:</strong>
            <code><?php echo esc_html( strtoupper( (string) ( $environment['effective'] ?? '' ) ) ); ?></code>
            &nbsp;·&nbsp; <strong>Host:</strong> <code><?php echo esc_html( (string) ( $environment['host'] ?? '' ) ); ?></code>
            <?php if ( ! is_wp_error( $storage ) ) : ?>
                &nbsp;·&nbsp; <strong>Host de feeds:</strong> <code><?php echo esc_html( (string) wp_parse_url( $storage['url'], PHP_URL_HOST ) ); ?></code>
            <?php endif; ?>
            <?php if ( ! is_wp_error( $storage ) && ! empty( $storage['url_corrected'] ) ) : ?>
                <p class="description" style="margin-bottom:0;">Se ha corregido automaticamente una URL de uploads heredada de otro entorno para evitar mezclar PRO y STAGING.</p>
            <?php endif; ?>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px;margin-top:18px;">
            <div class="card" style="max-width:none;padding:20px;">
                <h3 style="margin-top:0;">Configuracion</h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="seo_ie_cf_save_settings">
                    <?php wp_nonce_field( 'seo_ie_cf_save_settings' ); ?>

                    <p><strong>Canales activos</strong></p>
                    <?php foreach ( $channels as $channel => $config ) : ?>
                        <label style="display:block;margin:0 0 8px;">
                            <input
                                type="checkbox"
                                name="enabled_channels[]"
                                value="<?php echo esc_attr( $channel ); ?>"
                                <?php checked( in_array( $channel, $settings['enabled_channels'], true ) ); ?>
                            >
                            <?php echo esc_html( $config['label'] ); ?>
                        </label>
                    <?php endforeach; ?>

                    <p style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-end;">
                        <label>
                            <strong>Pais</strong><br>
                            <input type="text" name="country" value="<?php echo esc_attr( $settings['country'] ); ?>" maxlength="2" size="5">
                        </label>
                        <label>
                            <strong>Idioma</strong><br>
                            <input type="text" name="language" value="<?php echo esc_attr( $settings['language'] ); ?>" maxlength="2" size="5">
                        </label>
                        <span>
                            <strong>Lote</strong><br>
                            <span class="description">Adaptativo · <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'seo-processes' ], admin_url( 'admin.php' ) ) ); ?>">configurar en Procesos</a></span>
                        </span>
                    </p>

                    <label style="display:block;margin:16px 0 8px;">
                        <input type="checkbox" name="refresh_after_supplier_sync" value="1" <?php checked( ! empty( $settings['refresh_after_supplier_sync'] ) ); ?>>
                        Regenerar al terminar una sincronizacion de proveedores que haya modificado productos
                    </label>
                    <p class="description">En PRODUCCION se programa una regeneracion aproximadamente 2 minutos despues de terminar la sincronizacion. En STAGING no se agenda.</p>

                    <label style="display:block;margin:16px 0 8px;">
                        <input type="checkbox" name="auto_refresh" value="1" <?php checked( ! empty( $settings['auto_refresh'] ) ); ?>>
                        Regeneracion diaria de seguridad en PRODUCCION
                    </label>
                    <label style="display:block;margin:0 0 8px;">
                        <strong>Hora diaria</strong><br>
                        <input type="time" name="daily_time" value="<?php echo esc_attr( $settings['daily_time'] ); ?>" step="60">
                    </label>
                    <p class="description">Hora local de WordPress. Sirve como respaldo aunque no haya habido sincronizacion de proveedores.</p>

                    <p><button type="submit" class="button button-primary">Guardar configuracion</button></p>
                </form>
            </div>

            <div class="card" style="max-width:none;padding:20px;">
                <h3 style="margin-top:0;">Generacion</h3>
                <p><strong>Estado:</strong> <?php echo esc_html( $status ); ?></p>
                <p><strong>Origen:</strong> <?php echo esc_html( seo_ie_cf_admin_origin_label( $state['origin'] ?? '' ) ); ?></p>
                <p><strong>Candidatos:</strong> <?php echo esc_html( number_format_i18n( $total ) ); ?></p>
                <p><strong>Procesados:</strong> <?php echo esc_html( number_format_i18n( $processed ) ); ?> · <strong>publicados:</strong> <?php echo esc_html( number_format_i18n( absint( $state['written'] ?? 0 ) ) ); ?> · <strong>excluidos:</strong> <?php echo esc_html( number_format_i18n( absint( $state['excluded'] ?? 0 ) ) ); ?></p>

                <p><strong>Regulador:</strong>
                    siguiente lote <?php echo esc_html( number_format_i18n( absint( $state['adaptive_next_batch_size'] ?? SEO_IE_CF_BATCH_SIZE ) ) ); ?>
                    · presion <?php echo esc_html( (string) ( $state['adaptive_pressure'] ?? 'baja' ) ); ?>
                    <?php if ( ! empty( $state['adaptive_next_delay'] ) ) : ?>
                        · pausa <?php echo esc_html( number_format_i18n( absint( $state['adaptive_next_delay'] ) ) ); ?> s
                    <?php endif; ?>
                </p>
                <?php if ( ! empty( $state['last_batch_rows'] ) ) : ?>
                    <p class="description"><strong>Ultimo lote:</strong> <?php echo esc_html( number_format_i18n( absint( $state['last_batch_rows'] ) ) ); ?> productos en <?php echo esc_html( number_format_i18n( (float) ( $state['last_batch_duration'] ?? 0 ), 2 ) ); ?> s · <?php echo esc_html( (string) ( $state['adaptive_reason'] ?? '' ) ); ?></p>
                <?php else : ?>
                    <p class="description">Pendiente de la primera ventana del Gestor de workers.</p>
                <?php endif; ?>

                <?php if ( 'running' === $status ) : ?>
                    <div style="height:14px;background:#dcdcde;border-radius:7px;overflow:hidden;max-width:620px;">
                        <div style="height:100%;width:<?php echo esc_attr( (string) $progress ); ?>%;background:#2271b1;"></div>
                    </div>
                    <p class="description"><?php echo esc_html( (string) $progress ); ?> % completado.</p>
                <?php endif; ?>

                <?php if ( ! empty( $state['started_at'] ) ) : ?>
                    <p class="description">Inicio: <?php echo esc_html( seo_ie_cf_admin_local_datetime( $state['started_at'] ) ); ?><?php if ( ! empty( $state['completed_at'] ) ) : ?> · Fin: <?php echo esc_html( seo_ie_cf_admin_local_datetime( $state['completed_at'] ) ); ?><?php endif; ?></p>
                <?php endif; ?>

                <?php if ( seo_ie_cf_is_production() && ! empty( $settings['auto_refresh'] ) ) : ?>
                    <p><strong>Proxima regeneracion diaria:</strong> <?php echo $next_daily ? esc_html( wp_date( 'd/m/Y H:i', $next_daily, wp_timezone() ) ) : '<span style="color:#b32d2e;">pendiente de programar</span>'; ?></p>
                <?php else : ?>
                    <p class="description">La regeneracion automatica no esta activa en este entorno.</p>
                <?php endif; ?>

                <?php if ( ! empty( $state['updated_at'] ) ) : ?>
                    <p class="description"><strong>Ultima actividad:</strong> <?php echo esc_html( seo_ie_cf_admin_local_datetime( $state['updated_at'] ) ); ?><?php if ( 'running' === $status ) : ?> · <?php echo esc_html( number_format_i18n( $idle_seconds ) ); ?> s sin cambios<?php endif; ?></p>
                <?php endif; ?>

                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px;">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                        <input type="hidden" name="action" value="seo_ie_cf_regenerate">
                        <?php wp_nonce_field( 'seo_ie_cf_regenerate' ); ?>
                        <button type="submit" class="button button-primary" <?php disabled( 'running' === $status ); ?>>Iniciar generacion</button>
                    </form>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                        <input type="hidden" name="action" value="seo_ie_cf_stop">
                        <?php wp_nonce_field( 'seo_ie_cf_stop' ); ?>
                        <button type="submit" class="button" <?php disabled( 'running' !== $status ); ?> onclick="return confirm('¿Parar la generacion actual? Los archivos parciales no se publicaran.');">Parar generacion</button>
                    </form>
                </div>
                <p class="description">Iniciar crea una generacion nueva y la entrega al <strong>Gestor de workers</strong>. Parar invalida la ejecucion actual sin sustituir el ultimo feed valido. El tamaño del lote, la pausa, la presión y la recuperacion se gobiernan desde Procesos; este modulo no mantiene un worker paralelo.</p>
                <p class="description">Google, Microsoft y Pinterest descargan despues los archivos desde sus URLs; no existe un envio directo.</p>

                <?php if ( ! empty( $state['excluded_reasons'] ) ) : ?>
                    <details>
                        <summary>Motivos de exclusion</summary>
                        <ul style="margin-left:20px;">
                            <?php foreach ( (array) $state['excluded_reasons'] as $reason => $count ) : ?>
                                <li><code><?php echo esc_html( (string) $reason ); ?></code>: <?php echo esc_html( number_format_i18n( absint( $count ) ) ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>

                <?php if ( ! empty( $state['errors'] ) ) : ?>
                    <details open>
                        <summary>Errores tecnicos</summary>
                        <ul style="margin-left:20px;">
                            <?php foreach ( array_slice( (array) $state['errors'], -10 ) as $message ) : ?>
                                <li><?php echo esc_html( (string) $message ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" style="max-width:none;padding:20px;margin-top:20px;">
            <h3 style="margin-top:0;">URLs de inventario</h3>
            <p>Estas son las URLs estables que se registran una sola vez en cada plataforma. Despues la plataforma vuelve a descargarlas segun su propia programacion.</p>
            <p class="description">El plugin registra la generacion del feed, no cada descarga remota: estos archivos se sirven directamente por el servidor web.</p>

            <table class="widefat striped" style="max-width:1180px;">
                <thead>
                    <tr>
                        <th>Canal</th>
                        <th>Archivo</th>
                        <th>Estado</th>
                        <th>URL</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $channels as $channel => $config ) : ?>
                    <?php
                    $enabled = in_array( $channel, $settings['enabled_channels'], true );
                    $filename = sanitize_file_name( (string) $config['filename'] );
                    $path = ! is_wp_error( $storage ) ? trailingslashit( $storage['dir'] ) . $filename : '';
                    $url  = ! is_wp_error( $storage ) ? trailingslashit( $storage['url'] ) . rawurlencode( $filename ) : '';
                    $exists = $path && file_exists( $path );
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $config['label'] ); ?></strong><br><span class="description"><?php echo esc_html( $config['description'] ); ?></span></td>
                        <td><code><?php echo esc_html( $filename ); ?></code><?php if ( $exists ) : ?><br><span class="description"><?php echo esc_html( seo_ie_cf_admin_bytes( filesize( $path ) ) ); ?></span><?php endif; ?></td>
                        <td><?php echo $enabled ? ( $exists ? '<span style="color:#1d6b43;">Listo</span>' : '<span style="color:#996800;">Pendiente de generar</span>' ) : '<span style="color:#646970;">Desactivado</span>'; ?></td>
                        <td>
                            <?php if ( $enabled && $url ) : ?>
                                <code style="word-break:break-all;"><?php echo esc_html( $url ); ?></code>
                                <?php if ( $exists ) : ?><br><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">Abrir feed</a><?php endif; ?>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="card" style="max-width:none;padding:20px;margin-top:20px;">
            <h3 style="margin-top:0;">Historial de generaciones</h3>
            <p class="description">Se conservan las ultimas 20 ejecuciones completadas o fallidas en este entorno.</p>
            <?php if ( empty( $history ) ) : ?>
                <p>Aun no hay ejecuciones finalizadas registradas.</p>
            <?php else : ?>
                <div style="overflow:auto;">
                    <table class="widefat striped">
                        <thead><tr><th>Fin</th><th>Origen</th><th>Estado</th><th>Candidatos</th><th>Publicados</th><th>Excluidos</th><th>Archivos</th></tr></thead>
                        <tbody>
                        <?php foreach ( array_slice( $history, 0, 20 ) as $entry ) : ?>
                            <?php
                            $file_labels = [];
                            foreach ( (array) ( $entry['files'] ?? [] ) as $file ) {
                                $name = sanitize_file_name( (string) ( $file['filename'] ?? '' ) );
                                if ( '' !== $name ) {
                                    $file_labels[] = $name . ( ! empty( $file['size'] ) ? ' (' . seo_ie_cf_admin_bytes( $file['size'] ) . ')' : '' );
                                }
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html( seo_ie_cf_admin_local_datetime( $entry['completed_at'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( seo_ie_cf_admin_origin_label( $entry['origin'] ?? '' ) ); ?></td>
                                <td><code><?php echo esc_html( (string) ( $entry['status'] ?? '' ) ); ?></code></td>
                                <td><?php echo esc_html( number_format_i18n( absint( $entry['candidate_total'] ?? 0 ) ) ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( absint( $entry['written'] ?? 0 ) ) ); ?></td>
                                <td><?php echo esc_html( number_format_i18n( absint( $entry['excluded'] ?? 0 ) ) ); ?></td>
                                <td><?php echo esc_html( implode( ' · ', $file_labels ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card" style="max-width:none;padding:20px;margin-top:20px;">
            <h3 style="margin-top:0;">Alta inicial en las plataformas</h3>
            <p><strong>Google Merchant Center:</strong> crea una fuente de productos desde archivo y registra la URL de <code>google-merchant.xml</code>.</p>
            <p><strong>Microsoft Merchant Center:</strong> crea un feed con descarga automatica desde URL y registra <code>microsoft-merchant.txt</code>.</p>
            <p><strong>Pinterest Catalogs:</strong> agrega una fuente de datos alojada por URL y registra <code>pinterest-catalog.csv</code>.</p>
            <p><strong>Otros receptores:</strong> usa <code>catalogo-universal.csv</code> o <code>catalogo-universal.json</code>. El JSON conserva la estructura completa del registro canonico, incluidas las imagenes adicionales como array. Tambien puedes registrar un nuevo canal mediante el filtro <code>seo_ie_commercial_feed_channels</code>.</p>
            <p class="description">Esta version usa el modelo mas estable para el catalogo completo: la tienda publica el feed y cada plataforma lo descarga. Las APIs quedan reservadas para actualizaciones incrementales casi en tiempo real si mas adelante hacen falta.</p>
        </div>
    </div>

    <?php if ( 'running' === $status ) : ?>
        <script>
        (function () {
            var tickUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce = <?php echo wp_json_encode( wp_create_nonce( 'seo_ie_cf_tick' ) ); ?>;

            // Este pulso no procesa lotes: solo mantiene visible y elegible el gestor central.
            window.setTimeout(function () {
                var body = new URLSearchParams();
                body.append('action', 'seo_ie_cf_tick');
                body.append('nonce', nonce);

                fetch(tickUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                }).catch(function () {
                    // La recarga permite volver a intentar sin bloquear la UI.
                }).finally(function () {
                    window.setTimeout(function () { window.location.reload(); }, 1200);
                });
            }, 7000);
        }());
        </script>
    <?php endif; ?>
    <?php
}

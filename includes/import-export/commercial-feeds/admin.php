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
        'enabled_channels' => $enabled,
        'country'          => $country ?: 'ES',
        'language'         => $language ?: 'es',
        'auto_refresh'     => empty( $_POST['auto_refresh'] ) ? 0 : 1,
        'batch_size'       => max( 50, min( 500, absint( $_POST['batch_size'] ?? SEO_IE_CF_BATCH_SIZE ) ) ),
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

    seo_ie_cf_maybe_schedule_daily();

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
 * Render principal de la pestana.
 */
function seo_ie_cf_render_admin() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $settings = seo_ie_cf_settings();
    $channels = seo_ie_cf_channels();
    $state    = seo_ie_cf_state();
    $storage  = seo_ie_cf_storage();
    $is_staging = ! seo_ie_cf_is_production();

    if ( isset( $_GET['cf_saved'] ) ) {
        echo '<div class="notice notice-success inline"><p>Configuracion guardada.</p></div>';
    }
    if ( isset( $_GET['cf_started'] ) ) {
        echo '<div class="notice notice-info inline"><p>Generacion iniciada. El trabajo continua por lotes en Action Scheduler.</p></div>';
    }
    if ( isset( $_GET['cf_error'] ) ) {
        echo '<div class="notice notice-error inline"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['cf_error'] ) ) ) ) . '</p></div>';
    }

    if ( $is_staging ) {
        echo '<div class="notice notice-warning inline"><p><strong>STAGING:</strong> puedes generar y probar los feeds, pero la regeneracion automatica queda desactivada y no conviene registrar estas URLs en las cuentas comerciales reales.</p></div>';
    }

    $status = (string) ( $state['status'] ?? 'never' );
    $processed = absint( $state['processed'] ?? 0 );
    $total = absint( $state['candidate_total'] ?? 0 );
    $progress = $total > 0 ? min( 100, round( 100 * $processed / $total, 1 ) ) : 0;
    ?>
    <div style="max-width:1220px;">
        <h2>Inventarios comerciales</h2>
        <p>
            Genera fuentes publicas de productos para plataformas que descargan el catalogo por URL.
            No sustituye los sitemaps: aqui se publica inventario comercial con precio, stock, imagen,
            GTIN/MPN, marca y categoria.
        </p>

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
                        <label>
                            <strong>Lote</strong><br>
                            <input type="number" name="batch_size" value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>" min="50" max="500" step="10" style="width:90px;">
                        </label>
                    </p>

                    <label style="display:block;margin:16px 0;">
                        <input type="checkbox" name="auto_refresh" value="1" <?php checked( ! empty( $settings['auto_refresh'] ) ); ?>>
                        Regenerar automaticamente una vez al dia en PRODUCCION
                    </label>
                    <p class="description">En STAGING esta opcion se conserva, pero no se agenda automaticamente.</p>

                    <p><button type="submit" class="button button-primary">Guardar configuracion</button></p>
                </form>
            </div>

            <div class="card" style="max-width:none;padding:20px;">
                <h3 style="margin-top:0;">Generacion</h3>
                <p><strong>Estado:</strong> <?php echo esc_html( $status ); ?></p>
                <p><strong>Candidatos:</strong> <?php echo esc_html( number_format_i18n( $total ) ); ?></p>
                <p><strong>Procesados:</strong> <?php echo esc_html( number_format_i18n( $processed ) ); ?> · <strong>publicados:</strong> <?php echo esc_html( number_format_i18n( absint( $state['written'] ?? 0 ) ) ); ?> · <strong>excluidos:</strong> <?php echo esc_html( number_format_i18n( absint( $state['excluded'] ?? 0 ) ) ); ?></p>

                <?php if ( 'running' === $status ) : ?>
                    <div style="height:14px;background:#dcdcde;border-radius:7px;overflow:hidden;max-width:620px;">
                        <div style="height:100%;width:<?php echo esc_attr( (string) $progress ); ?>%;background:#2271b1;"></div>
                    </div>
                    <p class="description"><?php echo esc_html( (string) $progress ); ?> % completado.</p>
                <?php endif; ?>

                <?php if ( ! empty( $state['started_at'] ) ) : ?>
                    <p class="description">Inicio UTC: <?php echo esc_html( (string) $state['started_at'] ); ?><?php if ( ! empty( $state['completed_at'] ) ) : ?> · Fin UTC: <?php echo esc_html( (string) $state['completed_at'] ); ?><?php endif; ?></p>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="seo_ie_cf_regenerate">
                    <?php wp_nonce_field( 'seo_ie_cf_regenerate' ); ?>
                    <p><button type="submit" class="button button-primary" <?php disabled( 'running' === $status ); ?>>Regenerar todos los inventarios</button></p>
                </form>

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
            <p>Estas son las URLs estables que se registran una sola vez en cada plataforma. Despues la plataforma vuelve a descargarlas segun su programacion.</p>

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
            <h3 style="margin-top:0;">Alta inicial en las plataformas</h3>
            <p><strong>Google Merchant Center:</strong> crea una fuente de productos desde archivo y registra la URL de <code>google-merchant.xml</code>.</p>
            <p><strong>Microsoft Merchant Center:</strong> crea un feed con descarga automatica desde URL y registra <code>microsoft-merchant.txt</code>.</p>
            <p><strong>Pinterest Catalogs:</strong> agrega una fuente de datos alojada por URL y registra <code>pinterest-catalog.csv</code>.</p>
            <p><strong>Otros receptores:</strong> usa <code>catalogo-universal.csv</code> o registra un nuevo canal mediante el filtro <code>seo_ie_commercial_feed_channels</code>.</p>
            <p class="description">Esta version usa el modelo mas estable para el catalogo completo: la tienda publica el feed y cada plataforma lo descarga. Las APIs quedan reservadas para actualizaciones incrementales casi en tiempo real si mas adelante hacen falta.</p>
        </div>
    </div>

    <?php if ( 'running' === $status ) : ?>
        <script>
        window.setTimeout(function () { window.location.reload(); }, 8000);
        </script>
    <?php endif; ?>
    <?php
}

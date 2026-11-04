<?php
/**
 * TaxoCommerce Distribution - Commercial feed administration.
 *
 * @package SEOSystem
 * @subpackage ImportExport\CommercialFeeds
 * @since 2.3.7
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_post_tcdist_feed_save_settings', 'tcdist_feed_admin_save_settings' );
add_action( 'admin_post_tcdist_feed_regenerate', 'tcdist_feed_admin_regenerate' );
add_action( 'admin_post_tcdist_feed_stop', 'tcdist_feed_admin_stop' );
add_action( 'wp_ajax_tcdist_feed_tick', 'tcdist_feed_admin_ajax_tick' );

function tcdist_feed_admin_redirect_url( array $args = [] ) {
    $base = add_query_arg(
        [
            'page'       => 'taxocommerce-distribution',
            'tcdist_tab' => 'feeds',
        ],
        admin_url( 'admin.php' )
    );

    return add_query_arg( $args, $base );
}

function tcdist_feed_admin_save_settings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to configure commercial feeds.', 'taxocommerce-distribution' ) );
    }
    check_admin_referer( 'tcdist_feed_save_settings' );

    $channels = array_keys( tcdist_feed_channels() );
    // Only accept string channel identifiers from the submitted checkbox list.
    $submitted_channels = isset( $_POST['enabled_channels'] ) && is_array( $_POST['enabled_channels'] )
        ? array_map( 'sanitize_key', array_filter( map_deep( wp_unslash( $_POST['enabled_channels'] ), 'sanitize_text_field' ), 'is_string' ) )
        : [];
    $enabled = array_values(
        array_intersect( $channels, array_map( 'sanitize_key', $submitted_channels ) )
    );

    if ( empty( $enabled ) ) {
        $enabled = [ 'google' ];
    }

    // Validar entrada escalar antes de transformar los codigos ISO:
    // arrays enviados por POST no deben convertirse a la cadena "Array".
    $country_input = isset( $_POST['country'] ) && is_scalar( $_POST['country'] )
        ? sanitize_text_field( wp_unslash( (string) $_POST['country'] ) )
        : 'ES';
    $language_input = isset( $_POST['language'] ) && is_scalar( $_POST['language'] )
        ? sanitize_text_field( wp_unslash( (string) $_POST['language'] ) )
        : 'es';
    $country  = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $country_input ), 0, 2 ) );
    $language = strtolower( substr( preg_replace( '/[^A-Za-z]/', '', $language_input ), 0, 2 ) );

    $daily_time = isset( $_POST['daily_time'] ) && is_string( $_POST['daily_time'] )
        ? sanitize_text_field( wp_unslash( $_POST['daily_time'] ) )
        : '03:30';

    $settings = [
        'enabled_channels'            => $enabled,
        'country'                     => $country ?: 'ES',
        'language'                    => $language ?: 'es',
        'auto_refresh'                => empty( $_POST['auto_refresh'] ) ? 0 : 1,
        'daily_time'                  => tcdist_feed_sanitize_daily_time( $daily_time ),
        'refresh_after_supplier_sync' => empty( $_POST['refresh_after_supplier_sync'] ) ? 0 : 1,
        'batch_size'                  => absint( tcdist_feed_settings()['batch_size'] ?? TCDIST_FEED_BATCH_SIZE ), // Legacy: actual batch size is regulated by Processes.
    ];

    update_option( TCDIST_FEED_SETTINGS_OPTION, $settings, false );

    // If a receiver is disabled, remove its stable file so
    // una plataforma siga leyendo indefinidamente un inventario obsoleto.
    $storage = tcdist_feed_storage();
    if ( ! is_wp_error( $storage ) ) {
        foreach ( tcdist_feed_channels() as $channel => $config ) {
            if ( in_array( $channel, $enabled, true ) ) {
                continue;
            }
            $filename = sanitize_file_name( (string) ( $config['filename'] ?? '' ) );
            if ( '' === $filename ) {
                continue;
            }
            $path = trailingslashit( $storage['dir'] ) . $filename;
            if ( file_exists( $path ) ) {
                wp_delete_file( $path );
            }
        }
    }

    tcdist_feed_maybe_schedule_daily( true );

    wp_safe_redirect( tcdist_feed_admin_redirect_url( [ 'cf_saved' => '1' ] ) );
    exit;
}

function tcdist_feed_admin_regenerate() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to regenerate commercial feeds.', 'taxocommerce-distribution' ) );
    }
    check_admin_referer( 'tcdist_feed_regenerate' );

    // During migration both plugins can point to the same stable public feed
    // filenames. Never let a manual Distribution build overlap an active
    // legacy build, otherwise two independent locks could publish to the same
    // final path.
    if ( defined( 'TCDIST_LEGACY_FEED_ACTIVE' ) && TCDIST_LEGACY_FEED_ACTIVE && function_exists( 'seo_ie_cf_state' ) ) {
        $legacy_state = seo_ie_cf_state();
        if ( 'running' === sanitize_key( (string) ( $legacy_state['status'] ?? '' ) ) ) {
            wp_safe_redirect(
                tcdist_feed_admin_redirect_url(
                    array(
                        'cf_error' => rawurlencode( 'The legacy inventory generator is running. Wait for it to finish or stop it before starting a Distribution test.' ),
                    )
                )
            );
            exit;
        }
    }

    $result = tcdist_feed_start_build( 'manual' );
    if ( is_wp_error( $result ) ) {
        wp_safe_redirect(
            tcdist_feed_admin_redirect_url(
                [
                    'cf_error' => rawurlencode( $result->get_error_message() ),
                ]
            )
        );
        exit;
    }

    wp_safe_redirect( tcdist_feed_admin_redirect_url( [ 'cf_started' => '1' ] ) );
    exit;
}


/**
 * Stops the current run without publishing partial files.
 */
function tcdist_feed_admin_stop() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to stop commercial feeds.', 'taxocommerce-distribution' ) );
    }
    check_admin_referer( 'tcdist_feed_stop' );

    tcdist_feed_stop_build( 'manual' );
    wp_safe_redirect( tcdist_feed_admin_redirect_url( [ 'cf_stopped' => '1' ] ) );
    exit;
}

/**
 * Admin pulse. It does not process products; it only wakes the worker
 * central worker so it can schedule the next work window.
 */
function tcdist_feed_admin_ajax_tick() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
    }

    check_ajax_referer( 'tcdist_feed_tick', 'nonce' );
    $continuation = tcdist_feed_browser_continue( 8 );
    $state = tcdist_feed_state();

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
function tcdist_feed_admin_bytes( $bytes ) {
    $bytes = max( 0, (int) $bytes );
    if ( function_exists( 'size_format' ) ) {
        return size_format( $bytes, 2 );
    }
    return number_format_i18n( $bytes ) . ' B';
}

/**
 * Presenta una fecha UTC almacenada por el motor en hora local de WordPress.
 */
function tcdist_feed_admin_local_datetime( $utc_mysql ) {
    $utc_mysql = trim( (string) $utc_mysql );
    if ( '' === $utc_mysql ) {
        return '—';
    }
    $timestamp = strtotime( $utc_mysql . ' UTC' );
    return $timestamp ? wp_date( 'd/m/Y H:i:s', $timestamp, wp_timezone() ) : $utc_mysql;
}

/**
 * Human-readable generation source label.
 */
function tcdist_feed_admin_origin_label( $origin ) {
    $labels = [
        'manual'          => __( 'Manual', 'taxocommerce-distribution' ),
        'manual_processes'=> __( 'Manual from Processes', 'taxocommerce-distribution' ),
        'supplier_sync' => __( 'Supplier synchronization completed', 'taxocommerce-distribution' ),
        'daily_safety'  => __( 'Daily safety regeneration', 'taxocommerce-distribution' ),
        'scheduled'     => __( 'Programada', 'taxocommerce-distribution' ),
    ];
    $origin = sanitize_key( (string) $origin );
    return $labels[ $origin ] ?? ( $origin ? $origin : '—' );
}

/**
 * Render principal de la pestana.
 */
function tcdist_feed_render_admin() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $settings    = tcdist_feed_settings();
    $channels    = tcdist_feed_channels();
    $state       = tcdist_feed_state();
    $history     = tcdist_feed_history();
    $storage     = tcdist_feed_storage();
    $environment = tcdist_feed_environment_info();
    $is_staging  = 'production' !== (string) ( $environment['effective'] ?? '' );
    $next_daily  = tcdist_feed_next_daily_scheduled();
    $supervisor_settings = function_exists( 'tcdist_process_settings' ) ? tcdist_process_settings() : [];
    $manager_enabled = ! empty( $supervisor_settings['enabled'] ) && ! empty( $supervisor_settings['commercial_feeds'] );

    if ( isset( $_GET['cf_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status notice after nonce-protected action.
        echo '<div class="notice notice-success inline">' . '<p>' . esc_html__( 'Settings saved.', 'taxocommerce-distribution' ) . '</p>' . '</div>';
    }
    if ( isset( $_GET['cf_started'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status notice after nonce-protected action.
        echo '<div class="notice notice-info inline">' . '<p>' . esc_html__( 'Generation started and handed to the Distribution worker. Batch size adapts automatically to actual response time and observed load.', 'taxocommerce-distribution' ) . '</p>' . '</div>';
    }
    if ( isset( $_GET['cf_stopped'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status notice after nonce-protected action.
        echo '<div class="notice notice-warning inline">' . '<p>' . esc_html__( 'Generation stopped by the user. No partial file was published.', 'taxocommerce-distribution' ) . '</p>' . '</div>';
    }
    if ( isset( $_GET['cf_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only message displayed after nonce-protected action.
        $feed_error = sanitize_text_field( wp_unslash( $_GET['cf_error'] ) );
        echo '<div class="notice notice-error inline"><p>' . esc_html( rawurldecode( $feed_error ) ) . '</p></div>';
    }

    if ( $is_staging ) {
        echo '<div class="notice notice-warning inline">' . '<p>' . '<strong>' . esc_html__( 'STAGING:', 'taxocommerce-distribution' ) . '</strong>' . ' ' . esc_html__( 'this generation uses products and URLs from this environment and is for testing only. No commercial refresh is scheduled and these URLs must not be registered with Google, Microsoft, or Pinterest.', 'taxocommerce-distribution' ) . '</p>' . '</div>';
    } else {
        echo '<div class="notice notice-success inline">' . '<p>' . '<strong>' . esc_html__( 'PRODUCTION:', 'taxocommerce-distribution' ) . '</strong>' . ' ' . esc_html__( 'this environment generates the live commercial feeds. Platforms download from these URLs; WordPress does not push the feed directly.', 'taxocommerce-distribution' ) . '</p>' . '</div>';
    }

    if ( ! empty( $environment['mismatch'] ) ) {
        echo '<div class="notice notice-warning inline"><p><strong>Entorno discrepante:</strong> WordPress informa <code>'
            . esc_html( strtoupper( (string) ( $environment['reported'] ?? '' ) ) )
            . '</code>, pero la deteccion efectiva por hostname es <code>'
            . esc_html( strtoupper( (string) ( $environment['effective'] ?? '' ) ) )
            . '</code>. Review <code>WP_ENVIRONMENT_TYPE</code> in the environment configuration.</p></div>';
    }

    if ( ! $manager_enabled ) {
        echo '<div class="notice notice-error inline">' . '<p>' . '<strong>' . esc_html__( 'Distribution worker:', 'taxocommerce-distribution' ) . '</strong> ' . esc_html__( 'Commercial feeds are not enabled in Processes > Distribution worker. You can start a generation, but it will not progress until that process is enabled.', 'taxocommerce-distribution' ) . '</p>' . '</div';
    }

    $status = (string) ( $state['status'] ?? 'never' );
    $processed = absint( $state['processed'] ?? 0 );
    $total = absint( $state['candidate_total'] ?? 0 );
    $progress = $total > 0 ? min( 100, round( 100 * $processed / $total, 1 ) ) : 0;
    $updated_ts = ! empty( $state['updated_at'] ) ? strtotime( (string) $state['updated_at'] . ' UTC' ) : 0;
    $idle_seconds = $updated_ts ? max( 0, time() - $updated_ts ) : 0;
    ?>
    <div style="max-width:1220px;">
        <h2><?php esc_html_e( 'Commercial Feeds', 'taxocommerce-distribution' ); ?></h2>
        <p>
            <?php esc_html_e( 'Generates public product feeds for platforms that download the catalog by URL. It does not replace sitemaps: this publishes commercial inventory with price, stock, image, GTIN/MPN, brand, and category.', 'taxocommerce-distribution' ); ?>
        </p>

        <div class="card" style="max-width:none;padding:16px 20px;margin-top:16px;">
            <strong><?php esc_html_e( 'Entorno informado por WordPress:', 'taxocommerce-distribution' ); ?></strong>
            <code><?php echo esc_html( strtoupper( (string) ( $environment['reported'] ?? '' ) ) ); ?></code>
            &nbsp;·&nbsp; <strong><?php esc_html_e( 'Entorno efectivo:', 'taxocommerce-distribution' ); ?></strong>
            <code><?php echo esc_html( strtoupper( (string) ( $environment['effective'] ?? '' ) ) ); ?></code>
            <br>
            <strong><?php esc_html_e( 'Host:', 'taxocommerce-distribution' ); ?></strong> <code><?php echo esc_html( (string) ( $environment['host'] ?? '' ) ); ?></code>
            <?php if ( ! is_wp_error( $storage ) ) : ?>
                &nbsp;·&nbsp; <strong><?php esc_html_e( 'Host de feeds:', 'taxocommerce-distribution' ); ?></strong> <code><?php echo esc_html( (string) wp_parse_url( $storage['url'], PHP_URL_HOST ) ); ?></code>
            <?php endif; ?>
            &nbsp;·&nbsp; <strong><?php esc_html_e( 'Source de la deteccion:', 'taxocommerce-distribution' ); ?></strong>
            <code><?php echo esc_html( (string) ( $environment['source'] ?? '' ) ); ?></code>
            <?php if ( ! is_wp_error( $storage ) && ! empty( $storage['url_corrected'] ) ) : ?>
                <p class="description" style="margin-bottom:0;"><?php esc_html_e( 'Se ha corregido automaticamente una URL de uploads heredada de otro entorno para evitar mezclar PRO y STAGING.', 'taxocommerce-distribution' ); ?></p>
            <?php endif; ?>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px;margin-top:18px;">
            <div class="card" style="max-width:none;padding:20px;">
                <h3 style="margin-top:0;"><?php esc_html_e( 'Settings', 'taxocommerce-distribution' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="tcdist_feed_save_settings">
                    <?php wp_nonce_field( 'tcdist_feed_save_settings' ); ?>

                    <p><strong><?php esc_html_e( 'Canales activos', 'taxocommerce-distribution' ); ?></strong></p>
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
                            <strong><?php esc_html_e( 'Country', 'taxocommerce-distribution' ); ?></strong><br>
                            <input type="text" name="country" value="<?php echo esc_attr( $settings['country'] ); ?>" maxlength="2" size="5">
                        </label>
                        <label>
                            <strong><?php esc_html_e( 'Language', 'taxocommerce-distribution' ); ?></strong><br>
                            <input type="text" name="language" value="<?php echo esc_attr( $settings['language'] ); ?>" maxlength="2" size="5">
                        </label>
                        <span>
                            <strong><?php esc_html_e( 'Lote', 'taxocommerce-distribution' ); ?></strong><br>
                            <span class="description">Adaptive · <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'taxocommerce-distribution', 'tcdist_tab' => 'automation' ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'view Automation', 'taxocommerce-distribution' ); ?></a></span>
                        </span>
                    </p>

                    <label style="display:block;margin:16px 0 8px;">
                        <input type="checkbox" name="refresh_after_supplier_sync" value="1" <?php checked( ! empty( $settings['refresh_after_supplier_sync'] ) ); ?>>
                        <?php esc_html_e( 'Regenerate after a supplier synchronization that changed products', 'taxocommerce-distribution' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'In PRODUCTION, regeneration is scheduled about 2 minutes after synchronization finishes. STAGING does not schedule it.', 'taxocommerce-distribution' ); ?></p>

                    <label style="display:block;margin:16px 0 8px;">
                        <input type="checkbox" name="auto_refresh" value="1" <?php checked( ! empty( $settings['auto_refresh'] ) ); ?>>
                        <?php esc_html_e( 'Daily safety regeneration en PRODUCTION', 'taxocommerce-distribution' ); ?>
                    </label>
                    <label style="display:block;margin:0 0 8px;">
                        <strong><?php esc_html_e( 'Hora diaria', 'taxocommerce-distribution' ); ?></strong><br>
                        <input type="time" name="daily_time" value="<?php echo esc_attr( $settings['daily_time'] ); ?>" step="60">
                    </label>
                    <p class="description"><?php esc_html_e( 'WordPress local time. This acts as a fallback even when no supplier synchronization has occurred.', 'taxocommerce-distribution' ); ?></p>

                    <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'taxocommerce-distribution' ); ?></button></p>
                </form>
            </div>

            <div class="card" style="max-width:none;padding:20px;">
                <h3 style="margin-top:0;"><?php esc_html_e( 'Generation', 'taxocommerce-distribution' ); ?></h3>
                <p><strong><?php esc_html_e( 'Status:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( $status ); ?></p>
                <p><strong><?php esc_html_e( 'Source:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( tcdist_feed_admin_origin_label( $state['origin'] ?? '' ) ); ?></p>
                <p><strong><?php esc_html_e( 'Candidates:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( number_format_i18n( $total ) ); ?></p>
                <p><strong><?php esc_html_e( 'Processed:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( number_format_i18n( $processed ) ); ?> · <strong><?php esc_html_e( 'published:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( number_format_i18n( absint( $state['written'] ?? 0 ) ) ); ?> · <strong><?php esc_html_e( 'excluded:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( number_format_i18n( absint( $state['excluded'] ?? 0 ) ) ); ?></p>

                <p><strong><?php esc_html_e( 'Regulador:', 'taxocommerce-distribution' ); ?></strong>
                    next batch <?php echo esc_html( number_format_i18n( absint( $state['adaptive_next_batch_size'] ?? TCDIST_FEED_BATCH_SIZE ) ) ); ?>
                    · presion <?php echo esc_html( (string) ( $state['adaptive_pressure'] ?? 'baja' ) ); ?>
                    <?php if ( ! empty( $state['adaptive_next_delay'] ) ) : ?>
                        · pausa <?php echo esc_html( number_format_i18n( absint( $state['adaptive_next_delay'] ) ) ); ?> s
                    <?php endif; ?>
                </p>
                <?php if ( ! empty( $state['last_batch_rows'] ) ) : ?>
                    <p class="description"><strong><?php esc_html_e( 'Last batch:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( number_format_i18n( absint( $state['last_batch_rows'] ) ) ); ?> products in <?php echo esc_html( number_format_i18n( (float) ( $state['last_batch_duration'] ?? 0 ), 2 ) ); ?> s · <?php echo esc_html( (string) ( $state['adaptive_reason'] ?? '' ) ); ?></p>
                <?php else : ?>
                    <p class="description"><?php esc_html_e( 'Waiting for the first Distribution worker window.', 'taxocommerce-distribution' ); ?></p>
                <?php endif; ?>

                <?php if ( 'running' === $status ) : ?>
                    <div style="height:14px;background:#dcdcde;border-radius:7px;overflow:hidden;max-width:620px;">
                        <div style="height:100%;width:<?php echo esc_attr( (string) $progress ); ?>%;background:#2271b1;"></div>
                    </div>
                    <p class="description"><?php echo esc_html( (string) $progress ); ?> % completado.</p>
                <?php endif; ?>

                <?php if ( ! empty( $state['started_at'] ) ) : ?>
                    <p class="description">Inicio: <?php echo esc_html( tcdist_feed_admin_local_datetime( $state['started_at'] ) ); ?><?php if ( ! empty( $state['completed_at'] ) ) : ?> · End: <?php echo esc_html( tcdist_feed_admin_local_datetime( $state['completed_at'] ) ); ?><?php endif; ?></p>
                <?php endif; ?>

                <?php if ( tcdist_feed_is_production() && ! empty( $settings['auto_refresh'] ) ) : ?>
                    <p><strong><?php esc_html_e( 'Next daily regeneration:', 'taxocommerce-distribution' ); ?></strong> <?php echo $next_daily ? esc_html( wp_date( 'd/m/Y H:i', $next_daily, wp_timezone() ) ) : '<span style="color:#b32d2e;">' . esc_html__( 'pending scheduling', 'taxocommerce-distribution' ) . '</span>'; ?></p>
                <?php else : ?>
                    <p class="description"><?php esc_html_e( 'Automatic regeneration is not active in this environment.', 'taxocommerce-distribution' ); ?></p>
                <?php endif; ?>

                <?php if ( ! empty( $state['updated_at'] ) ) : ?>
                    <p class="description"><strong><?php esc_html_e( 'Ultima actividad:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( tcdist_feed_admin_local_datetime( $state['updated_at'] ) ); ?><?php if ( 'running' === $status ) : ?> · <?php echo esc_html( number_format_i18n( $idle_seconds ) ); ?> s sin cambios<?php endif; ?></p>
                <?php endif; ?>

                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px;">
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                        <input type="hidden" name="action" value="tcdist_feed_regenerate">
                        <?php wp_nonce_field( 'tcdist_feed_regenerate' ); ?>
                        <button type="submit" class="button button-primary" <?php disabled( 'running' === $status ); ?>><?php esc_html_e( 'Start generation', 'taxocommerce-distribution' ); ?></button>
                    </form>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
                        <input type="hidden" name="action" value="tcdist_feed_stop">
                        <?php wp_nonce_field( 'tcdist_feed_stop' ); ?>
                        <button type="submit" class="button" <?php disabled( 'running' !== $status ); ?> onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Stop the current generation? Partial files will not be published.', 'taxocommerce-distribution' ) ) ); ?>);" ><?php esc_html_e( 'Stop generation', 'taxocommerce-distribution' ); ?></button>
                    </form>
                </div>
                <p class="description">Start creates a new generation and hands it to the <strong>Distribution worker</strong>. Stop invalidates the current run without replacing the last valid feed. Batch size, pause, pressure, and recovery are controlled by Processes; this plugin keeps a single bounded worker.</p>
                <p class="description"><?php esc_html_e( 'Google, Microsoft y Pinterest descargan despues los archivos desde sus URLs; no existe un envio directo.', 'taxocommerce-distribution' ); ?></p>

                <?php if ( ! empty( $state['excluded_reasons'] ) ) : ?>
                    <details>
                        <summary><?php esc_html_e( 'Motivos de exclusion', 'taxocommerce-distribution' ); ?></summary>
                        <ul style="margin-left:20px;">
                            <?php foreach ( (array) $state['excluded_reasons'] as $reason => $count ) : ?>
                                <li><code><?php echo esc_html( (string) $reason ); ?></code>: <?php echo esc_html( number_format_i18n( absint( $count ) ) ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>

                <?php if ( ! empty( $state['errors'] ) ) : ?>
                    <details open>
                        <summary><?php esc_html_e( 'Technical errors', 'taxocommerce-distribution' ); ?></summary>
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
            <h3 style="margin-top:0;"><?php esc_html_e( 'URLs de inventario', 'taxocommerce-distribution' ); ?></h3>
            <p><?php esc_html_e( 'These are stable URLs registered once with each platform. The platform then downloads them again according to its own schedule.', 'taxocommerce-distribution' ); ?></p>
            <p class="description"><?php esc_html_e( 'The plugin records feed generation, not each remote download: these files are served directly by the web server.', 'taxocommerce-distribution' ); ?></p>

            <table class="widefat striped" style="max-width:1180px;">
                <thead>
                    <tr>
                        <th>Canal</th>
                        <th>Archivo</th>
                        <th><?php esc_html_e( 'Status', 'taxocommerce-distribution' ); ?></th>
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
                        <td><code><?php echo esc_html( $filename ); ?></code><?php if ( $exists ) : ?><br><span class="description"><?php echo esc_html( tcdist_feed_admin_bytes( filesize( $path ) ) ); ?></span><?php endif; ?></td>
                        <td><?php echo $enabled ? ( $exists ? '<span style="color:#1d6b43;">' . esc_html__( 'Ready', 'taxocommerce-distribution' ) . '</span>' : '<span style="color:#996800;">' . esc_html__( 'Pending generation', 'taxocommerce-distribution' ) . '</span>' ) : '<span style="color:#646970;">' . esc_html__( 'Disabled', 'taxocommerce-distribution' ) . '</span>'; ?></td>
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
            <h3 style="margin-top:0;"><?php esc_html_e( 'Generation history', 'taxocommerce-distribution' ); ?></h3>
            <p class="description"><?php esc_html_e( 'Se conservan las ultimas 20 ejecuciones completadas o fallidas en este entorno.', 'taxocommerce-distribution' ); ?></p>
            <?php if ( empty( $history ) ) : ?>
                <p><?php esc_html_e( 'Aun no hay ejecuciones finalizadas registradas.', 'taxocommerce-distribution' ); ?></p>
            <?php else : ?>
                <div style="overflow:auto;">
                    <table class="widefat striped">
                        <thead><tr><th><?php esc_html_e( 'End', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Source', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Status', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Candidates', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Published', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Excluded', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Files', 'taxocommerce-distribution' ); ?></th></tr></thead>
                        <tbody>
                        <?php foreach ( array_slice( $history, 0, 20 ) as $entry ) : ?>
                            <?php
                            $file_labels = [];
                            foreach ( (array) ( $entry['files'] ?? [] ) as $file ) {
                                $name = sanitize_file_name( (string) ( $file['filename'] ?? '' ) );
                                if ( '' !== $name ) {
                                    $file_labels[] = $name . ( ! empty( $file['size'] ) ? ' (' . tcdist_feed_admin_bytes( $file['size'] ) . ')' : '' );
                                }
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html( tcdist_feed_admin_local_datetime( $entry['completed_at'] ?? '' ) ); ?></td>
                                <td><?php echo esc_html( tcdist_feed_admin_origin_label( $entry['origin'] ?? '' ) ); ?></td>
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
            <h3 style="margin-top:0;"><?php esc_html_e( 'Alta inicial en las plataformas', 'taxocommerce-distribution' ); ?></h3>
            <p><strong>Google Merchant Center:</strong> creates a product data source from a file and registers the URL of <code>google-merchant.xml</code>.</p>
            <p><strong>Microsoft Merchant Center:</strong> crea un feed con descarga automatica desde URL y registra <code>microsoft-merchant.txt</code>.</p>
            <p><strong>Pinterest Catalogs:</strong> adds a hosted data source by URL and registers <code>pinterest-catalog.csv</code>.</p>
            <p><strong>Other receivers:</strong> use <code>catalogo-universal.csv</code> or <code>catalogo-universal.json</code>. JSON preserves the full canonical record structure, including additional images as an array. New channels can also be registered through the <code>tcdist_commercial_feed_channels</code> filter.</p>
            <p class="description">This version uses the most stable full-catalog model: the store publishes the feed and each platform downloads it. APIs remain reserved for near-real-time incremental updates if needed later.</p>
        </div>
    </div>

    <?php if ( 'running' === $status ) : ?>
        <script>
        (function () {
            var tickUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            var nonce = <?php echo wp_json_encode( wp_create_nonce( 'tcdist_feed_tick' ) ); ?>;

            // Este pulso no procesa lotes: solo mantiene visible y elegible el worker propio.
            window.setTimeout(function () {
                var body = new URLSearchParams();
                body.append('action', 'tcdist_feed_tick');
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

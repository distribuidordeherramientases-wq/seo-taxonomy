<?php
/**
 * TaxoCommerce Distribution administration.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'taxocommerce_distribution_register_menu', 42 );
add_action( 'admin_post_tcdist_sitemap_regenerate', 'taxocommerce_distribution_handle_sitemap_regenerate' );

function taxocommerce_distribution_capability() {
    return (string) apply_filters( 'taxocommerce_distribution_capability', 'manage_woocommerce' );
}

function taxocommerce_distribution_guard() {
    if ( ! current_user_can( taxocommerce_distribution_capability() ) && ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para gestionar TaxoCommerce Distribution.', 'taxocommerce-distribution' ) );
    }
}

function taxocommerce_distribution_register_menu() {
    $capability = taxocommerce_distribution_capability();

    if ( function_exists( 'taxocommerce_core' ) ) {
        add_submenu_page(
            'taxocommerce-core',
            __( 'Distribución', 'taxocommerce-distribution' ),
            __( 'Distribución', 'taxocommerce-distribution' ),
            $capability,
            'taxocommerce-distribution',
            'taxocommerce_distribution_render_page'
        );
        return;
    }

    add_menu_page(
        __( 'TaxoCommerce Distribution', 'taxocommerce-distribution' ),
        __( 'Distribution', 'taxocommerce-distribution' ),
        $capability,
        'taxocommerce-distribution',
        'taxocommerce_distribution_render_page',
        'dashicons-rss',
        59
    );
}

function taxocommerce_distribution_url( $tab = 'overview', $args = array() ) {
    return add_query_arg(
        array_merge(
            array(
                'page'       => 'taxocommerce-distribution',
                'tcdist_tab' => sanitize_key( $tab ),
            ),
            $args
        ),
        admin_url( 'admin.php' )
    );
}

function taxocommerce_distribution_handle_sitemap_regenerate() {
    taxocommerce_distribution_guard();
    check_admin_referer( 'tcdist_sitemap_regenerate' );

    $result = tcdist_sitemap_create_sitemaps();
    $args = array();
    if ( is_array( $result ) && 'success' === (string) ( $result['type'] ?? '' ) ) {
        $args['tcdist_sitemap_result'] = 'ok';
        $args['tcdist_sitemap_message'] = rawurlencode( wp_strip_all_tags( (string) ( $result['message'] ?? '' ) ) );
    } else {
        $args['tcdist_sitemap_result'] = 'error';
        $message = is_array( $result ) ? (string) ( $result['message'] ?? '' ) : __( 'Resultado desconocido.', 'taxocommerce-distribution' );
        $args['tcdist_sitemap_message'] = rawurlencode( wp_strip_all_tags( $message ) );
    }

    wp_safe_redirect( taxocommerce_distribution_url( 'sitemaps', $args ) );
    exit;
}

function taxocommerce_distribution_render_page() {
    taxocommerce_distribution_guard();
    $tab = isset( $_GET['tcdist_tab'] ) ? sanitize_key( wp_unslash( $_GET['tcdist_tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! in_array( $tab, array( 'overview', 'feeds', 'sitemaps', 'automation' ), true ) ) {
        $tab = 'overview';
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'TaxoCommerce Distribution', 'taxocommerce-distribution' ); ?></h1>
        <p><?php esc_html_e( 'Publicación técnica del catálogo: feeds comerciales y sitemaps XML mediante procesos seguros por bloques.', 'taxocommerce-distribution' ); ?></p>

        <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Secciones de Distribution', 'taxocommerce-distribution' ); ?>">
            <?php
            foreach (
                array(
                    'overview'   => __( 'Resumen', 'taxocommerce-distribution' ),
                    'feeds'      => __( 'Feeds comerciales', 'taxocommerce-distribution' ),
                    'sitemaps'   => __( 'Sitemaps', 'taxocommerce-distribution' ),
                    'automation' => __( 'Automatización', 'taxocommerce-distribution' ),
                ) as $key => $label
            ) :
                ?>
                <a class="nav-tab <?php echo esc_attr( $tab === $key ? 'nav-tab-active' : '' ); ?>" href="<?php echo esc_url( taxocommerce_distribution_url( $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php taxocommerce_distribution_render_compatibility_notice(); ?>

        <div style="margin-top:18px;">
            <?php
            if ( 'feeds' === $tab ) {
                tcdist_feed_render_admin();
            } elseif ( 'sitemaps' === $tab ) {
                taxocommerce_distribution_render_sitemaps();
            } elseif ( 'automation' === $tab ) {
                taxocommerce_distribution_render_automation();
            } else {
                taxocommerce_distribution_render_overview();
            }
            ?>
        </div>
    </div>
    <?php
}

function taxocommerce_distribution_render_compatibility_notice() {
    $legacy_feed = defined( 'TCDIST_LEGACY_FEED_ACTIVE' ) && TCDIST_LEGACY_FEED_ACTIVE;
    $legacy_sitemap = defined( 'TCDIST_LEGACY_SITEMAP_ACTIVE' ) && TCDIST_LEGACY_SITEMAP_ACTIVE;

    if ( $legacy_feed || $legacy_sitemap ) {
        echo '<div class="notice notice-info inline" style="margin-top:18px"><p><strong>Migración segura:</strong> se ha detectado el plugin antiguo. Distribution utiliza sus propios estados y worker, pero conserva los mismos archivos públicos. Mientras conviven, la automatización periódica del componente antiguo sigue teniendo prioridad para evitar dobles ejecuciones; puedes probar las regeneraciones manuales desde este plugin.</p></div>';
    }
}

function taxocommerce_distribution_render_overview() {
    $feed_state = function_exists( 'tcdist_feed_state' ) ? tcdist_feed_state() : array();
    $sitemap = function_exists( 'tcdist_sitemap_get_sitemap_report' ) ? tcdist_sitemap_get_sitemap_report() : array();
    $feed_settings = function_exists( 'tcdist_feed_settings' ) ? tcdist_feed_settings() : array();
    $channels = function_exists( 'tcdist_feed_channels' ) ? tcdist_feed_channels() : array();
    $enabled = (array) ( $feed_settings['enabled_channels'] ?? array() );
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;max-width:1200px;">
        <div class="card" style="padding:18px;max-width:none;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Feeds comerciales', 'taxocommerce-distribution' ); ?></h2>
            <p><strong><?php esc_html_e( 'Estado:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( (string) ( $feed_state['status'] ?? 'never' ) ); ?></p>
            <p><strong><?php esc_html_e( 'Procesados:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( number_format_i18n( absint( $feed_state['processed'] ?? 0 ) ) ); ?></p>
            <p><strong><?php esc_html_e( 'Publicados:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( number_format_i18n( absint( $feed_state['written'] ?? 0 ) ) ); ?></p>
            <p><a class="button" href="<?php echo esc_url( taxocommerce_distribution_url( 'feeds' ) ); ?>"><?php esc_html_e( 'Abrir feeds', 'taxocommerce-distribution' ); ?></a></p>
        </div>

        <div class="card" style="padding:18px;max-width:none;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Canales activos', 'taxocommerce-distribution' ); ?></h2>
            <p style="font-size:28px;margin:8px 0;"><strong><?php echo esc_html( number_format_i18n( count( $enabled ) ) ); ?></strong></p>
            <p><?php
            $labels = array();
            foreach ( $enabled as $key ) {
                if ( isset( $channels[ $key ]['label'] ) ) {
                    $labels[] = $channels[ $key ]['label'];
                }
            }
            echo esc_html( implode( ' · ', $labels ) );
            ?></p>
        </div>

        <div class="card" style="padding:18px;max-width:none;">
            <h2 style="margin-top:0;"><?php esc_html_e( 'Sitemaps', 'taxocommerce-distribution' ); ?></h2>
            <p><strong><?php esc_html_e( 'Índice:', 'taxocommerce-distribution' ); ?></strong> <?php echo ! empty( $sitemap['main_valid'] ) ? '<span style="color:#1d6b43">Válido</span>' : '<span style="color:#b32d2e">Revisar</span>'; ?></p>
            <p><strong><?php esc_html_e( 'URLs publicadas:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( number_format_i18n( absint( $sitemap['total_urls'] ?? 0 ) ) ); ?></p>
            <p><strong><?php esc_html_e( 'Archivos:', 'taxocommerce-distribution' ); ?></strong> <?php echo esc_html( number_format_i18n( count( (array) ( $sitemap['files'] ?? array() ) ) ) ); ?></p>
            <p><a class="button" href="<?php echo esc_url( taxocommerce_distribution_url( 'sitemaps' ) ); ?>"><?php esc_html_e( 'Abrir sitemaps', 'taxocommerce-distribution' ); ?></a></p>
        </div>
    </div>

    <div class="card" style="padding:20px;max-width:1200px;margin-top:18px;">
        <h2 style="margin-top:0;"><?php esc_html_e( 'Responsabilidad del plugin', 'taxocommerce-distribution' ); ?></h2>
        <p><?php esc_html_e( 'Distribution publica el catálogo hacia sistemas externos. No edita plantillas, campañas, redes sociales ni contenido editorial.', 'taxocommerce-distribution' ); ?></p>
        <p><strong><?php esc_html_e( 'Feeds:', 'taxocommerce-distribution' ); ?></strong> Google Merchant Center, Microsoft/Bing Shopping, Pinterest y formatos universales.</p>
        <p><strong><?php esc_html_e( 'Sitemaps:', 'taxocommerce-distribution' ); ?></strong> productos, categorías, entradas, páginas, clusters y hubs con índice XML estable.</p>
    </div>
    <?php
}

function taxocommerce_distribution_sitemap_audit_stats() {
    if ( function_exists( 'seo_marketing_scan_stats_by_sitemap' ) ) {
        return (array) seo_marketing_scan_stats_by_sitemap();
    }

    global $wpdb;
    $table = $wpdb->prefix . 'seo_sitemap_inventory';
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        return array();
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT sitemap_url,
                COUNT(*) AS total,
                SUM(last_checked_at IS NOT NULL) AS checked,
                SUM(status_bucket='ok') AS ok_count,
                SUM(status_bucket IN ('3xx','seo_warning')) AS warnings,
                SUM(status_bucket IN ('404','403','4xx','5xx','network','seo_error')) AS errors
             FROM %i
             WHERE active_in_sitemap=1
             GROUP BY sitemap_url",
            $table
        ),
        ARRAY_A
    );

    $out = array();
    foreach ( (array) $rows as $row ) {
        $url = (string) ( $row['sitemap_url'] ?? '' );
        if ( '' === $url ) {
            continue;
        }
        $out[ $url ] = array(
            'total'    => absint( $row['total'] ?? 0 ),
            'checked'  => absint( $row['checked'] ?? 0 ),
            'ok'       => absint( $row['ok_count'] ?? 0 ),
            'warnings' => absint( $row['warnings'] ?? 0 ),
            'errors'   => absint( $row['errors'] ?? 0 ),
        );
    }
    return $out;
}

function taxocommerce_distribution_render_sitemaps() {
    $report = tcdist_sitemap_get_sitemap_report();
    $main_url = tcdist_sitemap_sitemap_public_url( 'sitemap.xml' );
    $audit = taxocommerce_distribution_sitemap_audit_stats();

    $result = isset( $_GET['tcdist_sitemap_result'] ) ? sanitize_key( wp_unslash( $_GET['tcdist_sitemap_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $message = isset( $_GET['tcdist_sitemap_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['tcdist_sitemap_message'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    ?>
    <div class="card" style="max-width:1220px;padding:20px;">
        <h2 style="margin-top:0;"><?php esc_html_e( 'Sitemaps XML', 'taxocommerce-distribution' ); ?></h2>
        <p><?php esc_html_e( 'Se conserva el mismo motor probado: publicación atómica, validación XML previa, lotes de productos y URLs estables en la raíz del dominio.', 'taxocommerce-distribution' ); ?></p>
        <p><?php esc_html_e( 'Índice profesional:', 'taxocommerce-distribution' ); ?> <a href="<?php echo esc_url( $main_url ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( $main_url ); ?></code></a></p>

        <?php if ( $message ) : ?>
            <div class="notice <?php echo esc_attr( 'ok' === $result ? 'notice-success' : 'notice-error' ); ?> inline"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <?php if ( ! empty( $report['main_valid'] ) ) : ?>
            <div class="notice notice-success inline"><p><strong><?php esc_html_e( 'Índice principal válido.', 'taxocommerce-distribution' ); ?></strong> <?php
            /* translators: %s: number of URLs published in the sitemap. */
            echo esc_html( sprintf( __( '%s URLs publicadas.', 'taxocommerce-distribution' ), number_format_i18n( absint( $report['total_urls'] ?? 0 ) ) ) );
            ?></p></div>
        <?php else : ?>
            <div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'El índice principal no existe o no es válido.', 'taxocommerce-distribution' ); ?></strong></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:18px 0;">
            <input type="hidden" name="action" value="tcdist_sitemap_regenerate">
            <?php wp_nonce_field( 'tcdist_sitemap_regenerate' ); ?>
            <?php submit_button( __( 'Regenerar y validar sitemaps', 'taxocommerce-distribution' ), 'primary', 'submit', false ); ?>
        </form>

        <details style="margin:16px 0;">
            <summary><strong><?php esc_html_e( 'Qué hace esta generación', 'taxocommerce-distribution' ); ?></strong></summary>
            <p><?php esc_html_e( 'Separa productos, categorías, entradas, páginas, clusters y hubs. Solo incluye contenido publicado y sin contraseña; excluye carrito, checkout y cuenta. Cada XML se valida antes de sustituir el archivo público anterior.', 'taxocommerce-distribution' ); ?></p>
            <p><?php esc_html_e( 'Los productos se dividen en bloques para evitar XML excesivamente grandes. La generación utiliza un lock y publicación atómica para no exponer archivos parciales.', 'taxocommerce-distribution' ); ?></p>
        </details>

        <table class="widefat striped">
            <thead><tr><th><?php esc_html_e( 'Archivo', 'taxocommerce-distribution' ); ?></th><th>XML</th><th>URLs</th><th><?php esc_html_e( 'Auditadas', 'taxocommerce-distribution' ); ?></th><th>OK</th><th><?php esc_html_e( 'Avisos', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Errores', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Cobertura', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Tamaño', 'taxocommerce-distribution' ); ?></th><th><?php esc_html_e( 'Modificado', 'taxocommerce-distribution' ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( (array) ( $report['files'] ?? array() ) as $file ) : ?>
                <?php
                $is_index = 'sitemap.xml' === (string) ( $file['filename'] ?? '' );
                $stats = ! $is_index && isset( $audit[ $file['url'] ] ) ? $audit[ $file['url'] ] : array( 'checked' => 0, 'ok' => 0, 'warnings' => 0, 'errors' => 0 );
                $count = absint( $file['count'] ?? 0 );
                $coverage = ! $is_index && $count > 0 ? min( 100, round( 100 * absint( $stats['checked'] ?? 0 ) / $count, 1 ) ) : 0;
                ?>
                <tr>
                    <td><a href="<?php echo esc_url( (string) $file['url'] ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( (string) $file['filename'] ); ?></code></a></td>
                    <td><?php echo ! empty( $file['valid'] ) ? '<span style="color:#1d6b43;font-weight:700">Válido</span>' : '<span style="color:#b32d2e;font-weight:700">Inválido</span>'; ?></td>
                    <td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
                    <?php if ( $is_index ) : ?>
                        <td>—</td><td>—</td><td>—</td><td>—</td><td>—</td>
                    <?php else : ?>
                        <td><?php echo esc_html( number_format_i18n( absint( $stats['checked'] ?? 0 ) ) ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( absint( $stats['ok'] ?? 0 ) ) ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( absint( $stats['warnings'] ?? 0 ) ) ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( absint( $stats['errors'] ?? 0 ) ) ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( $coverage, 1 ) ); ?>%</td>
                    <?php endif; ?>
                    <td><?php echo esc_html( size_format( absint( $file['bytes'] ?? 0 ), 1 ) ); ?></td>
                    <td><?php echo ! empty( $file['modified'] ) ? esc_html( wp_date( 'd/m/Y H:i', absint( $file['modified'] ) ) ) : '—'; ?></td>
                </tr>
                <?php if ( empty( $file['valid'] ) && ! empty( $file['error'] ) ) : ?>
                    <tr><td colspan="10" style="color:#b32d2e;"><?php echo esc_html( (string) $file['error'] ); ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function taxocommerce_distribution_render_automation() {
    $feed = tcdist_feed_state();
    $settings = tcdist_feed_settings();
    $legacy_feed = defined( 'TCDIST_LEGACY_FEED_ACTIVE' ) && TCDIST_LEGACY_FEED_ACTIVE;
    $legacy_sitemap = defined( 'TCDIST_LEGACY_SITEMAP_ACTIVE' ) && TCDIST_LEGACY_SITEMAP_ACTIVE;
    ?>
    <div class="card" style="max-width:1000px;padding:20px;">
        <h2 style="margin-top:0;"><?php esc_html_e( 'Automatización y carga', 'taxocommerce-distribution' ); ?></h2>
        <p><?php esc_html_e( 'Los feeds conservan el regulador adaptativo del sistema anterior, pero ahora el trabajo lo ejecuta un worker propio de Distribution. Cada ventana usa cursor, lock y límite temporal; no existe dependencia del antiguo gestor central.', 'taxocommerce-distribution' ); ?></p>

        <table class="widefat striped">
            <tbody>
                <tr><th><?php esc_html_e( 'Worker de feeds', 'taxocommerce-distribution' ); ?></th><td><?php echo $legacy_feed ? esc_html__( 'Automatización antigua prioritaria durante convivencia; manual disponible aquí.', 'taxocommerce-distribution' ) : esc_html__( 'Propio de Distribution', 'taxocommerce-distribution' ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Regeneración diaria', 'taxocommerce-distribution' ); ?></th><td><?php echo ! empty( $settings['auto_refresh'] ) ? esc_html( (string) ( $settings['daily_time'] ?? '03:30' ) ) : esc_html__( 'Desactivada', 'taxocommerce-distribution' ); ?></td></tr>
                <tr><th><?php esc_html_e( 'Último lote', 'taxocommerce-distribution' ); ?></th><td><?php echo esc_html( number_format_i18n( absint( $feed['last_batch_rows'] ?? 0 ) ) ); ?> <?php esc_html_e( 'productos', 'taxocommerce-distribution' ); ?> · <?php echo esc_html( number_format_i18n( (float) ( $feed['last_batch_duration'] ?? 0 ), 2 ) ); ?> s</td></tr>
                <tr><th><?php esc_html_e( 'Sitemaps automáticos', 'taxocommerce-distribution' ); ?></th><td><?php echo $legacy_sitemap ? esc_html__( 'Gestionados temporalmente por el plugin antiguo', 'taxocommerce-distribution' ) : esc_html__( 'Distribution regenera tras cambios de productos, páginas, posts y categorías', 'taxocommerce-distribution' ); ?></td></tr>
            </tbody>
        </table>

        <p class="description"><?php esc_html_e( 'Durante la migración evitamos dos cron paralelos escribiendo sobre los mismos archivos públicos. Cuando se retire el módulo antiguo, Distribution asumirá automáticamente la programación.', 'taxocommerce-distribution' ); ?></p>
    </div>
    <?php
}

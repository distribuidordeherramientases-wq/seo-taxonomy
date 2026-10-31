<?php
/**
 * Read-only availability diagnostics for the standalone Distribution plugin.
 *
 * Important: never call tcdist_feed_storage() here: it creates files on first use.
 */
defined( 'ABSPATH' ) || exit;

function taxocommerce_distribution_autocheck_add( &$checks, $group, $name, $status, $detail ) {
    $checks[] = array(
        'group'  => $group,
        'name'   => $name,
        'status' => $status,
        'detail' => $detail,
    );
}

/**
 * Check registered components, stored configuration, and file metadata.
 * No HTTP, generation, scheduling, or database/file writes.
 *
 * @return array[]
 */
function taxocommerce_distribution_autocheck_run() {
    $checks = array();

    taxocommerce_distribution_autocheck_add(
        $checks,
        'Sistema',
        'Carga y versión',
        defined( 'TAXOCOMMERCE_DISTRIBUTION_VERSION' ) ? 'ok' : 'error',
        defined( 'TAXOCOMMERCE_DISTRIBUTION_VERSION' )
            ? 'Distribution ' . TAXOCOMMERCE_DISTRIBUTION_VERSION . ' está cargado.'
            : 'No se ha encontrado la versión de Distribution.'
    );
    $woocommerce = class_exists( 'WooCommerce', false ) && function_exists( 'wc_get_product' );
    taxocommerce_distribution_autocheck_add(
        $checks,
        'Sistema',
        'WooCommerce',
        $woocommerce ? 'ok' : 'warning',
        $woocommerce ? 'La API de productos está disponible.' : 'No se detecta la API de productos de WooCommerce.'
    );
    $feed_functions = array(
        'tcdist_feed_channels',
        'tcdist_feed_state',
        'tcdist_feed_run_manager_slice',
        'tcdist_feed_channel_finalize',
        'tcdist_feed_settings',
    );
    $missing = array_filter( $feed_functions, static function ( $name ) { return ! function_exists( $name ); } );
    taxocommerce_distribution_autocheck_add(
        $checks,
        'Feeds',
        'Componentes de publicación',
        empty( $missing ) ? 'ok' : 'error',
        empty( $missing ) ? 'Motor, canales, estado y publicación disponibles.' : 'Faltan funciones: ' . implode( ', ', $missing ) . '.'
    );

    $channels = function_exists( 'tcdist_feed_channels' ) ? tcdist_feed_channels() : array();
    $expected = array( 'google', 'microsoft', 'pinterest', 'universal', 'json' );
    $missing_channels = array_diff( $expected, array_keys( (array) $channels ) );
    taxocommerce_distribution_autocheck_add(
        $checks,
        'Feeds',
        'Cinco formatos comerciales',
        empty( $missing_channels ) ? 'ok' : 'error',
        empty( $missing_channels )
            ? 'Google XML, Microsoft TXT, Pinterest CSV, catálogo CSV y JSON registrados.'
            : 'Faltan formatos: ' . implode( ', ', $missing_channels ) . '.'
    );

    $worker_registered = defined( 'TCDIST_WORKER_HOOK' )
        && function_exists( 'tcdist_distribution_feed_worker_tick' )
        && false !== has_action( TCDIST_WORKER_HOOK, 'tcdist_distribution_feed_worker_tick' );
    taxocommerce_distribution_autocheck_add(
        $checks,
        'Automatización',
        'Worker de lotes',
        $worker_registered ? 'ok' : 'error',
        $worker_registered ? 'Callback del worker registrado; no se ejecuta.' : 'No está registrado el worker de Distribution.'
    );
    $legacy_feed = defined( 'TCDIST_LEGACY_FEED_ACTIVE' ) && TCDIST_LEGACY_FEED_ACTIVE;
    $legacy_sitemap = defined( 'TCDIST_LEGACY_SITEMAP_ACTIVE' ) && TCDIST_LEGACY_SITEMAP_ACTIVE;
    taxocommerce_distribution_autocheck_add(
        $checks,
        'Automatización',
        'Propiedad de feeds',
        'ok',
        $legacy_feed ? 'El monolito conserva la automatización durante la coexistencia.' : 'Distribution puede registrar su automatización de feeds.'
    );
    taxocommerce_distribution_autocheck_add(
        $checks,
        'Automatización',
        'Propiedad de sitemaps',
        'ok',
        $legacy_sitemap ? 'El monolito conserva la automatización durante la coexistencia.' : 'Distribution es responsable de su automatización de sitemaps.'
    );

    $feed_hooks = array(
        'admin_post_tcdist_feed_save_settings' => 'tcdist_feed_admin_save_settings',
        'admin_post_tcdist_feed_regenerate'    => 'tcdist_feed_admin_regenerate',
        'admin_post_tcdist_feed_stop'          => 'tcdist_feed_admin_stop',
    );
    $missing_hooks = array();
    foreach ( $feed_hooks as $hook => $callback ) {
        if ( false === has_action( $hook, $callback ) ) {
            $missing_hooks[] = $hook;
        }
    }
    taxocommerce_distribution_autocheck_add(
        $checks,
        'Feeds',
        'Controladores administrativos',
        empty( $missing_hooks ) ? 'ok' : 'error',
        empty( $missing_hooks ) ? 'Guardar, iniciar y detener están registrados; no se ejecutan.' : 'Faltan acciones: ' . implode( ', ', $missing_hooks ) . '.'
    );

    $settings = get_option( 'tcdist_commercial_feeds_settings_v1', false );
    taxocommerce_distribution_autocheck_add(
        $checks,
        'Configuración',
        'Ajustes comerciales',
        false === $settings ? 'warning' : ( is_array( $settings ) ? 'ok' : 'error' ),
        false === $settings
            ? 'Aún no se han guardado ajustes propios: pueden aplicarse los valores predeterminados.'
            : ( is_array( $settings ) ? 'El formato de opciones es válido; no se modifica.' : 'La opción de configuración no es un array.' )
    );
    $state = get_option( 'tcdist_commercial_feeds_state_v1', false );
    taxocommerce_distribution_autocheck_add(
        $checks,
        'Configuración',
        'Estado de generación',
        false === $state || is_array( $state ) ? 'ok' : 'error',
        false === $state ? 'No hay un estado de generación persistido todavía.' : ( is_array( $state ) ? 'Estado persistido disponible; no se reanuda el proceso.' : 'El estado guardado tiene formato inesperado.' )
    );

    $sitemap_functions = array(
        'tcdist_sitemap_create_sitemaps',
        'tcdist_sitemap_validate_xml_file',
        'tcdist_sitemap_get_sitemap_report',
        'tcdist_sitemap_sitemap_public_url',
    );
    $missing = array_filter( $sitemap_functions, static function ( $name ) { return ! function_exists( $name ); } );
    taxocommerce_distribution_autocheck_add(
        $checks,
        'Sitemaps',
        'Motor XML',
        empty( $missing ) ? 'ok' : 'error',
        empty( $missing ) ? 'Creación, validación, informe y URLs públicas disponibles; no se invocan.' : 'Faltan funciones: ' . implode( ', ', $missing ) . '.'
    );
    $sitemap_admin = false !== has_action( 'admin_post_tcdist_sitemap_regenerate', 'taxocommerce_distribution_handle_sitemap_regenerate' );
    $sitemap_auto = $legacy_sitemap || false !== has_action( 'tcdist_sitemap_regenerate_sitemaps_event', 'tcdist_sitemap_regenerate_sitemaps_silent' );
    taxocommerce_distribution_autocheck_add(
        $checks,
        'Sitemaps',
        'Regeneración manual y automática',
        $sitemap_admin && $sitemap_auto ? 'ok' : 'error',
        $sitemap_admin && $sitemap_auto
            ? ( $legacy_sitemap ? 'Acción manual disponible; automatización delegada al monolito.' : 'Acciones de regeneración registradas; no se ejecutan.' )
            : 'Falta el controlador manual o el callback automático del motor.'
    );

    // Do not call the storage helpers: tcdist_feed_storage() may create a
    // directory/index.html, and other helpers may trigger update operations.
    $uploads = wp_upload_dir( null, false );
    $uploads_ok = is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] );
    taxocommerce_distribution_autocheck_add(
        $checks,
        'Archivos',
        'Ruta uploads de WordPress',
        $uploads_ok ? 'ok' : 'error',
        $uploads_ok ? 'Ruta de almacenamiento accesible para inspección de metadatos.' : 'WordPress no devuelve una ruta uploads válida.'
    );

    if ( $uploads_ok ) {
        $basedir = trailingslashit( (string) $uploads['basedir'] );
        $feed_dir = $basedir . 'seo-system-feeds';
        $sitemap_dir = $basedir . 'seo-sitemaps';
        $feed_dir_exists = is_dir( $feed_dir );
        $sitemap_dir_exists = is_dir( $sitemap_dir );

        taxocommerce_distribution_autocheck_add(
            $checks,
            'Archivos',
            'Carpeta de feeds',
            $feed_dir_exists ? ( is_writable( $feed_dir ) ? 'ok' : 'warning' ) : 'warning',
            ! $feed_dir_exists ? 'Directorio aún no creado; Autocheck no lo crea.' :
                ( is_writable( $feed_dir ) ? 'Directorio existente y escribible.' : 'Directorio existente sin permiso de escritura para el proceso PHP.' )
        );
        $published = array(
            'google-merchant.xml',
            'microsoft-merchant.txt',
            'pinterest-catalog.csv',
            'catalogo-universal.csv',
            'catalogo-universal.json',
        );
        $found = 0;
        foreach ( $published as $filename ) {
            if ( $feed_dir_exists && is_file( trailingslashit( $feed_dir ) . $filename ) ) {
                ++$found;
            }
        }
        taxocommerce_distribution_autocheck_add(
            $checks,
            'Archivos',
            'Ficheros de feeds publicados',
            $found > 0 ? 'ok' : 'warning',
            $found . ' de ' . count( $published ) . ' ficheros conocidos presentes; los canales desactivados pueden no tener fichero.'
        );
        taxocommerce_distribution_autocheck_add(
            $checks,
            'Archivos',
            'Carpeta de sitemaps',
            $sitemap_dir_exists ? ( is_writable( $sitemap_dir ) ? 'ok' : 'warning' ) : 'warning',
            ! $sitemap_dir_exists ? 'Directorio aún no creado; Autocheck no lo crea.' :
                ( is_writable( $sitemap_dir ) ? 'Directorio existente y escribible.' : 'Directorio existente sin permiso de escritura para el proceso PHP.' )
        );
        taxocommerce_distribution_autocheck_add(
            $checks,
            'Archivos',
            'Índice sitemap.xml',
            $sitemap_dir_exists && is_file( trailingslashit( $sitemap_dir ) . 'sitemap.xml' ) ? 'ok' : 'warning',
            $sitemap_dir_exists && is_file( trailingslashit( $sitemap_dir ) . 'sitemap.xml' )
                ? 'Índice físico publicado; no se ha validado el XML ni realizado peticiones web.'
                : 'No se encontró el índice físico; revisar Sitemaps y la propiedad de automatización.'
        );
    }

    return $checks;
}

function taxocommerce_distribution_render_autocheck() {
    taxocommerce_distribution_guard();
    ?>
    <section class="tcdist-autocheck" style="max-width:1100px;">
        <h2><?php esc_html_e( 'Autocheck de Distribution', 'taxocommerce-distribution' ); ?></h2>
        <p><?php esc_html_e( 'Diagnóstico manual y no destructivo: comprueba componentes, hooks, configuración y metadatos de archivos. No genera feeds ni sitemaps, no consulta servicios externos, no inicia trabajos y no escribe datos.', 'taxocommerce-distribution' ); ?></p>
        <form method="post">
            <?php wp_nonce_field( 'taxocommerce_distribution_autocheck' ); ?>
            <input type="hidden" name="tcdist_run_autocheck" value="1">
            <?php submit_button( __( 'Ejecutar autocheck', 'taxocommerce-distribution' ), 'secondary', 'submit', false ); ?>
        </form>
        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified before calling the diagnostic.
        if ( ! isset( $_POST['tcdist_run_autocheck'] ) ) {
            echo '<p class="description">' . esc_html__( 'Aún no se ha ejecutado el autocheck en esta visita.', 'taxocommerce-distribution' ) . '</p>';
            echo '</section>';
            return;
        }
        check_admin_referer( 'taxocommerce_distribution_autocheck' );
        $checks = taxocommerce_distribution_autocheck_run();
        $counts = array( 'ok' => 0, 'warning' => 0, 'error' => 0 );
        foreach ( $checks as $check ) {
            ++$counts[ $check['status'] ];
        }
        ?>
        <h3><?php esc_html_e( 'Resultado', 'taxocommerce-distribution' ); ?></h3>
        <p>
            <strong><?php echo esc_html( (string) count( $checks ) ); ?> <?php esc_html_e( 'comprobaciones', 'taxocommerce-distribution' ); ?>:</strong>
            <?php echo esc_html( (string) $counts['ok'] ); ?> <?php esc_html_e( 'correctas', 'taxocommerce-distribution' ); ?>,
            <?php echo esc_html( (string) $counts['warning'] ); ?> <?php esc_html_e( 'avisos', 'taxocommerce-distribution' ); ?>,
            <?php echo esc_html( (string) $counts['error'] ); ?> <?php esc_html_e( 'errores', 'taxocommerce-distribution' ); ?>.
        </p>
        <?php
        $current_group = '';
        foreach ( $checks as $check ) {
            if ( $current_group !== $check['group'] ) {
                if ( '' !== $current_group ) {
                    echo '</tbody></table>';
                }
                $current_group = $check['group'];
                echo '<h3>' . esc_html( $current_group ) . '</h3>';
                echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Control', 'taxocommerce-distribution' ) .
                    '</th><th scope="col">' . esc_html__( 'Estado', 'taxocommerce-distribution' ) .
                    '</th><th scope="col">' . esc_html__( 'Detalle', 'taxocommerce-distribution' ) . '</th></tr></thead><tbody>';
            }
            $labels = array( 'ok' => __( 'Correcto', 'taxocommerce-distribution' ), 'warning' => __( 'Aviso', 'taxocommerce-distribution' ), 'error' => __( 'Error', 'taxocommerce-distribution' ) );
            echo '<tr><th scope="row">' . esc_html( $check['name'] ) . '</th><td>' .
                esc_html( $labels[ $check['status'] ] ) . '</td><td>' . esc_html( $check['detail'] ) . '</td></tr>';
        }
        if ( '' !== $current_group ) {
            echo '</tbody></table>';
        }
        ?>
        <p class="description"><?php esc_html_e( 'Los resultados no se guardan. La presencia física de un fichero no demuestra su validez XML ni confirma que las plataformas externas lo hayan descargado.', 'taxocommerce-distribution' ); ?></p>
    </section>
    <?php
}

<?php
/**
 * Read-only, local Auto Test for TaxoCommerce Social.
 *
 * Never invokes provider connection tests, token decryption, scheduled jobs,
 * remote APIs, publishing, import or export handlers.
 */
defined( 'ABSPATH' ) || exit;

final class TCSocial_Auto_Test {
    const ACTION = 'tcsocial_run_auto_test';
    const NONCE = 'tcsocial_auto_test_run';
    const PREFIX = 'tcsocial_auto_test_';

    public static function register() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
    }

    private static function result( $status, $message ) {
        return array( 'status' => $status, 'message' => $message );
    }
    private static function pass( $message ) { return self::result( 'pass', $message ); }
    private static function fail( $message ) { return self::result( 'fail', $message ); }
    private static function skip( $message ) { return self::result( 'skip', $message ); }

    public static function cases() {
        return array(
            'runtime'            => array( 'Plugin', 'Arranque y paneles disponibles' ),
            'permissions'        => array( 'Seguridad', 'Rutas de administración registradas' ),
            'providers'          => array( 'Conexiones', 'Cinco proveedores declarados' ),
            'provider_callbacks' => array( 'Conexiones', 'Callbacks locales de cada red' ),
            'settings'           => array( 'Configuración', 'Acceso a ajustes existentes' ),
            'templates'          => array( 'Plantillas', 'Plantillas predeterminadas por red' ),
            'database'           => array( 'MySQL', 'Tablas operativas canónicas' ),
            'table_reads'        => array( 'MySQL', 'Lecturas limitadas de registros' ),
            'calendar'           => array( 'Calendario', 'Vistas y detección de coincidencias' ),
            'scheduler'          => array( 'Programador', 'Gestión de tareas y estados' ),
            'cron'               => array( 'Programador', 'Rutas de WP-Cron disponibles' ),
            'csv'                => array( 'Importar / Exportar', 'Simulación y contratos CSV' ),
            'secrets'            => array( 'Seguridad', 'Contrato de cifrado de credenciales' ),
            'publishing'         => array( 'Publicaciones', 'Motor de publicación disponible' ),
            'manual'             => array( 'Manual', 'Guía de usuario y técnica integradas' ),
            'remote_ops'         => array( 'Seguridad', 'Conexión real, publicación y exportación' ),
        );
    }

    public static function run_suite() {
        $start = microtime( true );
        $totals = array( 'pass' => 0, 'fail' => 0, 'skip' => 0 );
        $rows = array();
        foreach ( self::cases() as $id => $labels ) {
            $begin = microtime( true );
            try {
                $method = 'test_' . $id;
                $result = self::$method();
                if ( ! is_array( $result ) || ! isset( $result['status'], $result['message'] ) || ! array_key_exists( $result['status'], $totals ) ) {
                    $result = self::fail( 'La prueba no ha devuelto un resultado válido.' );
                }
            } catch ( Throwable $error ) {
                $result = self::fail( 'No se pudo comprobar la funcionalidad: ' . $error->getMessage() );
            }
            $rows[] = array(
                'id' => $id, 'group' => $labels[0], 'label' => $labels[1],
                'status' => $result['status'], 'message' => $result['message'],
                'ms' => (int) round( ( microtime( true ) - $begin ) * 1000 ),
            );
            ++$totals[ $result['status'] ];
        }
        return array(
            'version' => TAXOCOMMERCE_SOCIAL_VERSION,
            'date' => current_time( 'mysql' ),
            'ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
            'totals' => $totals,
            'rows' => $rows,
        );
    }

    private static function test_runtime() {
        $required = array(
            'tcsocial_admin_render', 'tcsocial_network_get_settings',
            'tcsocial_network_get_providers', 'tcsocial_programador_render_admin',
            'tcsocial_calendar_render_admin', 'tcsocial_manual_render',
        );
        foreach ( $required as $function ) {
            if ( ! function_exists( $function ) ) return self::fail( 'No existe ' . $function . '.' );
        }
        return self::pass( 'Social ' . TAXOCOMMERCE_SOCIAL_VERSION . ': componentes cargados.' );
    }

    private static function test_permissions() {
        $routes = array(
            self::ACTION,
            'tcsocial_network_save_connection',
            'tcsocial_network_disconnect',
            'tcsocial_network_save_templates',
            'tcsocial_network_publish_now',
            'tcsocial_programador_create',
            'tcsocial_programador_cancel',
            'tcsocial_programador_retry',
        );
        foreach ( $routes as $route ) {
            if ( ! has_action( 'admin_post_' . $route ) ) {
                return self::fail( 'No está registrada la ruta ' . $route . '.' );
            }
        }
        return self::pass( count( $routes ) . ' acciones de administración registradas; no se han ejecutado.' );
    }

    private static function test_providers() {
        $providers = tcsocial_network_get_providers();
        if ( ! is_array( $providers ) ) return self::fail( 'Registro de proveedores inválido.' );
        $missing = array_diff( array( 'facebook', 'instagram', 'linkedin', 'pinterest', 'x' ), array_keys( $providers ) );
        return $missing
            ? self::fail( 'Proveedores sin registrar: ' . implode( ', ', $missing ) . '.' )
            : self::pass( 'Facebook, Instagram, LinkedIn, Pinterest y X registrados (sin conexiones HTTP).' );
    }

    private static function test_provider_callbacks() {
        foreach ( tcsocial_network_get_providers() as $key => $provider ) {
            if ( ! is_array( $provider ) ) return self::fail( 'Definición inválida: ' . $key . '.' );
            foreach ( array( 'sanitize_connection_callback', 'test_callback', 'publish_callback', 'render_connection_callback' ) as $field ) {
                if ( empty( $provider[ $field ] ) || ! is_callable( $provider[ $field ] ) ) {
                    return self::fail( 'Falta ' . $field . ' de ' . $key . '.' );
                }
            }
        }
        return self::pass( 'Callbacks locales registradas sin probar credenciales ni publicar.' );
    }

    private static function test_settings() {
        $settings = tcsocial_network_get_settings();
        return is_array( $settings ) && isset( $settings['providers'], $settings['templates'], $settings['auto_publish'] )
            && is_array( $settings['providers'] ) && is_array( $settings['templates'] )
            ? self::pass( 'Ajustes leídos correctamente; no se muestran ni descifran credenciales.' )
            : self::fail( 'La configuración guardada no presenta la estructura prevista.' );
    }

    private static function test_templates() {
        $defaults = tcsocial_network_default_settings();
        foreach ( array( 'facebook', 'instagram', 'linkedin', 'pinterest', 'x' ) as $network ) {
            if ( empty( $defaults['templates'][ $network ]['post'] ) || empty( $defaults['templates'][ $network ]['page'] ) ) {
                return self::fail( 'Plantilla predeterminada incompleta: ' . $network . '.' );
            }
        }
        return self::pass( 'Plantillas base para entradas y páginas disponibles en cinco redes.' );
    }

    private static function table_exists( $table ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative live schema check, not cached.
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    private static function test_database() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live connectivity check, no writes.
        if ( '1' !== (string) $wpdb->get_var( 'SELECT 1' ) ) return self::fail( 'No responde MySQL a SELECT 1.' );
        $tables = array( tcsocial_network_publications_table(), tcsocial_programador_table() );
        foreach ( $tables as $table ) {
            if ( ! self::table_exists( $table ) ) {
                return self::fail( 'Falta la tabla ' . $table . '. Auto Test no la crea.' );
            }
        }
        return self::pass( 'Conexión MySQL y tablas de publicaciones/programador disponibles.' );
    }

    private static function test_table_reads() {
        global $wpdb;
        foreach ( array( tcsocial_network_publications_table(), tcsocial_programador_table() ) as $table ) {
            if ( ! self::table_exists( $table ) ) return self::skip( 'Falta ' . $table . '; no puede leerse.' );
            $wpdb->last_error = '';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded read-only diagnostic; does not return content or secrets.
            $sample = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i LIMIT %d', $table, 1 ) );
            if ( ! is_array( $sample ) || '' !== (string) $wpdb->last_error ) {
                return self::fail( 'No se puede leer ' . $table . '.' );
            }
        }
        return self::pass( 'Lecturas de hasta una fila por tabla, sin modificar contenidos.' );
    }

    private static function test_calendar() {
        return function_exists( 'tcsocial_calendar_events' )
            && function_exists( 'tcsocial_calendar_group_by_day' )
            && function_exists( 'tcsocial_calendar_has_same_network_collision' )
            && function_exists( 'tcsocial_calendar_render_admin' )
            ? self::pass( 'Calendario y detección de coincidencias disponibles; no se consulta la agenda.' )
            : self::fail( 'Falta alguna función del calendario.' );
    }

    private static function test_scheduler() {
        foreach ( array( 'tcsocial_programador_create_job', 'tcsocial_programador_get_job', 'tcsocial_programador_run_job', 'tcsocial_programador_jobs', 'tcsocial_programador_handle_cancel', 'tcsocial_programador_handle_retry' ) as $function ) {
            if ( ! function_exists( $function ) ) return self::fail( 'Falta ' . $function . '.' );
        }
        return self::pass( 'Programador, consulta, cancelación y reintento disponibles sin crear trabajos.' );
    }

    private static function test_cron() {
        return has_action( 'tcsocial_programador_run_job' )
            && has_action( 'tcsocial_network_publish_scheduled' )
            && has_action( 'tcsocial_network_publish_manual_scheduled' )
            ? self::pass( 'Tres rutas WP-Cron registradas; no se agenda ni ejecuta ninguna.' )
            : self::fail( 'Falta un callback de publicación programada.' );
    }

    private static function test_csv() {
        foreach ( array(
            'tcsocial_network_scheduler_import_columns',
            'tcsocial_network_scheduler_parse_import_file',
            'tcsocial_network_handle_scheduler_import_preview',
            'tcsocial_network_handle_scheduler_import_confirm',
            'tcsocial_network_handle_scheduler_export',
        ) as $function ) {
            if ( ! function_exists( $function ) ) return self::fail( 'Falta ' . $function . '.' );
        }
        return self::pass( 'Importación con vista previa y exportación CSV disponibles; ningún archivo se abre.' );
    }

    private static function test_secrets() {
        return function_exists( 'tcsocial_network_encrypt_secret' ) && function_exists( 'tcsocial_network_decrypt_secret' )
            ? self::pass( 'Capa de cifrado cargada; no se accede ni se imprime ningún token.' )
            : self::fail( 'Falta la capa de protección de credenciales.' );
    }

    private static function test_publishing() {
        return function_exists( 'tcsocial_network_publish_content' )
            && function_exists( 'tcsocial_network_create_pending_publication' )
            && function_exists( 'tcsocial_network_build_tracking_url' )
            ? self::pass( 'Contratos de publicación y seguimiento registrados; sin peticiones a redes.' )
            : self::fail( 'Falta una función de publicación.' );
    }

    private static function test_manual() {
        return function_exists( 'tcsocial_manual_render' )
            ? self::pass( 'Manual de usuario y técnico accesible desde Social.' )
            : self::fail( 'El manual integrado no está disponible.' );
    }

    private static function test_remote_ops() {
        return self::skip( 'No se prueban cuentas reales, peticiones a redes sociales, publicación, WP-Cron, importación/exportación ni cambios en agenda. Validar manualmente en STAGING.' );
    }

    public static function handle() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos para Auto Test.', 'taxocommerce-social' ) );
        }
        check_admin_referer( self::NONCE, 'tcsocial_auto_nonce' );
        $key = self::PREFIX . get_current_user_id();
        $history = get_transient( $key );
        if ( ! is_array( $history ) ) $history = array();
        array_unshift( $history, self::run_suite() );
        set_transient( $key, array_slice( $history, 0, 2 ), 30 * DAY_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-social&tab=auto-test' ) );
        exit;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos para Auto Test.', 'taxocommerce-social' ) );
        }
        $history = get_transient( self::PREFIX . get_current_user_id() );
        $current = is_array( $history ) && isset( $history[0]['rows'] ) ? $history[0] : null;
        $previous = is_array( $history ) && isset( $history[1]['rows'] ) ? $history[1] : null;

        echo '<section class="seo-social-card"><h2>Auto Test de TaxoCommerce Social</h2>';
        echo '<p>Chequeo local de solo lectura de conexiones registradas, plantillas, tablas, calendario y programador. No conecta cuentas ni publica. Se guardan los dos últimos resultados por administrador durante 30 días.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        wp_nonce_field( self::NONCE, 'tcsocial_auto_nonce' );
        submit_button( 'Ejecutar Auto Test', 'primary', 'submit', false );
        echo '</form>';
        if ( ! $current ) {
            echo '<p>No existen resultados todavía. Pulsa Ejecutar Auto Test.</p></section>';
            return;
        }
        $totals = $current['totals'];
        echo '<h3>Último resultado · v' . esc_html( $current['version'] ) . ' · ' . esc_html( $current['date'] ) . '</h3>';
        echo '<p><strong>' . esc_html( (string) $totals['pass'] ) . ' correctas</strong> · <strong>' . esc_html( (string) $totals['fail'] ) . ' fallidas</strong> · ' . esc_html( (string) $totals['skip'] ) . ' omitidas · ' . esc_html( (string) $current['ms'] ) . ' ms</p>';
        if ( $previous ) {
            $old = array();
            foreach ( $previous['rows'] as $row ) $old[ $row['id'] ] = $row['status'];
            $regressions = array();
            foreach ( $current['rows'] as $row ) {
                if ( 'fail' === $row['status'] && isset( $old[ $row['id'] ] ) && 'pass' === $old[ $row['id'] ] ) $regressions[] = $row['label'];
            }
            echo '<p><strong>Comparación:</strong> ' . esc_html( $previous['version'] . ' (' . $previous['date'] . ') → ' . $current['version'] ) . '. ';
            echo $regressions ? '<strong style="color:#b32d2e">Regresiones: ' . esc_html( implode( ', ', $regressions ) ) . '</strong>' : 'Sin regresiones de correcto a fallido.';
            echo '</p>';
        } else {
            echo '<p>No existe una ejecución anterior para comparar.</p>';
        }
        echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Grupo</th><th>Prueba</th><th>Estado</th><th>Diagnóstico</th><th>Tiempo</th></tr></thead><tbody>';
        $names = array( 'pass' => 'Correcto', 'fail' => 'Error', 'skip' => 'No ejecutado' );
        foreach ( $current['rows'] as $row ) {
            echo '<tr><td>' . esc_html( $row['group'] ) . '</td><td>' . esc_html( $row['label'] ) . '</td><td><strong>' . esc_html( $names[ $row['status'] ] ) . '</strong></td><td>' . esc_html( $row['message'] ) . '</td><td>' . esc_html( (string) $row['ms'] ) . ' ms</td></tr>';
        }
        echo '</tbody></table></div><p class="description">Un Auto Test correcto confirma los componentes disponibles; no garantiza la autorización de las cuentas ni una publicación externa satisfactoria.</p></section>';
    }
}
TCSocial_Auto_Test::register();

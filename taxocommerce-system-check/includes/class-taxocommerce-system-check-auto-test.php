<?php
/**
 * Read-only, administrator-triggered self-test for TaxoCommerce System Check.
 *
 * No reports are persisted and no HTTP probes, scans, cron jobs, emails,
 * database changes or repairs are performed. This tests the plugin's own
 * integration points, not every TaxoCommerce extension or the hosting SLA.
 *
 * @package TaxoCommerceSystemCheck
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_System_Check_Auto_Test {

    public static function groups() {
        return array(
            'startup'     => 'Arranque y permisos',
            'diagnostics' => 'Diagnósticos y módulos',
            'http'        => 'Auditor HTTP (sin solicitudes)',
            'reports'     => 'Informes y exportaciones',
            'external'    => 'Pruebas que requieren una ejecución aparte',
        );
    }

    public static function cases() {
        return array(
            'version'        => array( 'startup', 'Versión y rutas del plugin' ),
            'permissions'    => array( 'startup', 'Permisos de administración' ),
            'navigation'     => array( 'startup', 'Pantallas y pestañas' ),
            'native_php'     => array( 'diagnostics', 'Motor PHP independiente' ),
            'server_engine'  => array( 'diagnostics', 'Motor de diagnóstico del servidor' ),
            'modules'        => array( 'diagnostics', 'Registro de módulos activos' ),
            'url_engine'     => array( 'http', 'Motor y controles de auditoría HTTP' ),
            'url_hooks'      => array( 'http', 'Acciones y watchdog del auditor' ),
            'module_export'  => array( 'reports', 'Exportación JSON de módulos' ),
            'legacy_exports'=> array( 'reports', 'Exportaciones del motor histórico' ),
            'network_uat'    => array( 'external', 'Conectividad HTTP real y redirecciones' ),
            'write_uat'      => array( 'external', 'Informes por correo, tareas y reparaciones' ),
        );
    }

    private static function result( $status, $message ) {
        return array( 'status' => $status, 'message' => (string) $message );
    }

    private static function evaluate( $id ) {
        switch ( $id ) {
            case 'version':
                return self::result(
                    defined( 'TAXOCOMMERCE_SYSTEM_CHECK_VERSION' )
                        && defined( 'TAXOCOMMERCE_SYSTEM_CHECK_PATH' )
                        && is_readable( TAXOCOMMERCE_SYSTEM_CHECK_PATH . 'taxocommerce-system-check.php' ) ? 'pass' : 'fail',
                    'Versión y archivo principal comprobados sin modificar el disco.'
                );
            case 'permissions':
                return self::result(
                    current_user_can( 'manage_options' ) ? 'pass' : 'fail',
                    'Se requiere manage_options para ejecutar el autotest y abrir los manuales.'
                );
            case 'navigation':
                return self::result(
                    function_exists( 'taxocommerce_system_check_home' )
                    && function_exists( 'taxocommerce_system_check_active_tab' )
                    && class_exists( 'TaxoCommerce_System_Check_User_Guide', false )
                    && has_action( 'admin_menu', 'taxocommerce_system_check_register_menu' ) ? 'pass' : 'fail',
                    'Página principal, navegación y manuales disponibles.'
                );
            case 'native_php':
                if ( ! class_exists( 'TaxoCommerce_System_Check_PHP', false ) ) {
                    return self::result( 'fail', 'El diagnóstico PHP independiente no está cargado.' );
                }
                $rows = TaxoCommerce_System_Check_PHP::collect();
                if ( ! is_array( $rows ) || count( $rows ) < 10 ) {
                    return self::result( 'fail', 'El diagnóstico PHP no devuelve su inventario completo.' );
                }
                foreach ( $rows as $row ) {
                    if ( ! is_array( $row ) || ! isset( $row['id'], $row['status'], $row['value'] ) ) {
                        return self::result( 'fail', 'El inventario PHP contiene una fila incompleta.' );
                    }
                }
                $warnings = count( array_filter( $rows, static function ( $row ) {
                    return 'warning' === $row['status'];
                } ) );
                return self::result( 'pass', count( $rows ) . ' comprobaciones PHP disponibles; ' . $warnings . ' recomendaciones de entorno, consultables en Sistema > PHP.' );
            case 'server_engine':
                return function_exists( 'seo_server_status' )
                    ? self::result( 'pass', 'Motor de estado del servidor disponible.' )
                    : self::result( 'skip', 'Motor histórico no cargado; comprobar si TaxoCommerce SEO instalado reserva los símbolos globales.' );
            case 'modules':
                if ( ! function_exists( 'taxocommerce_system_check_module_results' ) ) {
                    return self::result( 'fail', 'Falta la lectura de módulos activos.' );
                }
                $modules = taxocommerce_system_check_module_results();
                return is_array( $modules ) && isset( $modules['system_check'] )
                    ? self::result( 'pass', count( $modules ) . ' módulos registrados; esta prueba no certifica su funcionamiento interno.' )
                    : self::result( 'fail', 'System Check no figura en el resultado de sus módulos.' );
            case 'url_engine':
                return self::result(
                    class_exists( 'TaxoCommerce_System_Check_URL_Audit', false )
                    && is_callable( array( 'TaxoCommerce_System_Check_URL_Audit', 'render' ) )
                    && is_callable( array( 'TaxoCommerce_System_Check_URL_Audit', 'process_batch' ) ) ? 'pass' : 'fail',
                    'Motor HTTP y su interfaz disponibles. No se ha solicitado ninguna URL.'
                );
            case 'url_hooks':
                if ( ! class_exists( 'TaxoCommerce_System_Check_URL_Audit', false ) ) {
                    return self::result( 'fail', 'No se puede verificar el auditor HTTP: clase ausente.' );
                }
                $hooks = array(
                    'admin_post_taxocommerce_system_check_http_json',
                    TaxoCommerce_System_Check_URL_Audit::HOOK,
                    TaxoCommerce_System_Check_URL_Audit::RESUME_HOOK,
                );
                foreach ( $hooks as $hook ) {
                    if ( ! has_action( $hook ) ) {
                        return self::result( 'fail', 'No está registrado el hook ' . $hook . '.' );
                    }
                }
                return self::result( 'pass', 'Exportación, procesador de lotes y vigilancia registrados; no se ejecutan.' );
            case 'module_export':
                return self::result(
                    function_exists( 'taxocommerce_system_check_modules_json' )
                    && has_action( 'admin_post_taxocommerce_system_check_modules_json' ) ? 'pass' : 'fail',
                    'Exportación JSON de módulos registrada; no se descarga en esta prueba.'
                );
            case 'legacy_exports':
                return function_exists( 'seo_system_diagnostics_handle_download_json' )
                    && has_action( 'admin_post_seo_system_diagnostics_download_json' )
                    ? self::result( 'pass', 'Exportación histórica JSON disponible.' )
                    : self::result( 'skip', 'Exportación del motor histórico no cargada; no se simula ni se marca como éxito.' );
            case 'network_uat':
                return self::result( 'skip', 'No se realizan solicitudes HTTP ni se comprueba la accesibilidad pública. Utiliza la pestaña HTTP.' );
            case 'write_uat':
                return self::result( 'skip', 'No se envían correos, no se ejecutan trabajos ni se modifican opciones o tablas.' );
        }
        return self::result( 'fail', 'Prueba no implementada.' );
    }

    /** No transient, database write, HTTP request or scheduling side effect. */
    public static function run() {
        $start = microtime( true );
        $rows = array();
        $totals = array( 'pass' => 0, 'fail' => 0, 'skip' => 0 );
        foreach ( self::cases() as $id => $description ) {
            try {
                $result = self::evaluate( $id );
            } catch ( Throwable $error ) {
                $result = self::result( 'fail', 'No se pudo completar la prueba (' . get_class( $error ) . ').' );
            }
            if ( ! isset( $result['status'] ) || ! isset( $totals[ $result['status'] ] ) ) {
                $result = self::result( 'fail', 'Resultado de prueba no válido.' );
            }
            $result['id'] = $id;
            $result['group'] = $description[0];
            $result['label'] = $description[1];
            $rows[] = $result;
            ++$totals[ $result['status'] ];
        }
        return array(
            'version' => TAXOCOMMERCE_SYSTEM_CHECK_VERSION,
            'generated_at' => gmdate( 'c' ),
            'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
            'totals' => $totals,
            'rows' => $rows,
        );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-system-check' ) );
        }
        $url = wp_nonce_url(
            taxocommerce_system_check_tab_url( 'auto-test', array( 'tcs_run' => '1' ) ),
            'tcs_auto_test_run'
        );
        echo '<section class="wrap tcs-auto-test"><h2>Auto Test · System Check</h2>';
        echo '<p>Comprueba las rutas, servicios y contratos del propio plugin. Es una ejecución local, de solo lectura y sin sondas HTTP, escrituras ni correos.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">Ejecutar Auto Test</a></p>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selection only; check_admin_referer validates before running.
        $requested = isset( $_GET['tcs_run'] ) && is_scalar( $_GET['tcs_run'] ) && '1' === (string) wp_unslash( $_GET['tcs_run'] );
        if ( ! $requested ) {
            echo '<p class="description">Las pruebas no se ejecutan al abrir esta pestaña. Pulsa el botón para obtener un resultado actual.</p></section>';
            return;
        }
        check_admin_referer( 'tcs_auto_test_run' );
        $report = self::run();
        echo '<p><strong>Resultados:</strong> ' . esc_html( (string) $report['totals']['pass'] ) . ' correctas, '
            . esc_html( (string) $report['totals']['fail'] ) . ' fallidas y '
            . esc_html( (string) $report['totals']['skip'] ) . ' no ejecutadas / no aplicables. '
            . esc_html( (string) $report['duration_ms'] ) . ' ms.</p>';
        foreach ( self::groups() as $group => $label ) {
            echo '<h3>' . esc_html( $label ) . '</h3>';
            echo '<table class="widefat striped"><thead><tr><th scope="col">Comprobación</th><th scope="col">Estado</th><th scope="col">Evidencia</th></tr></thead><tbody>';
            foreach ( $report['rows'] as $row ) {
                if ( $group !== $row['group'] ) {
                    continue;
                }
                $state = array( 'pass' => 'Correcto', 'fail' => 'Fallo', 'skip' => 'No ejecutado / N/A' );
                echo '<tr><th scope="row">' . esc_html( $row['label'] ) . '</th><td>'
                    . esc_html( $state[ $row['status'] ] ) . '</td><td>'
                    . esc_html( $row['message'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p class="description">Un resultado correcto indica que el componente está accesible, no que un escaneo real haya funcionado. Las pruebas omitidas nunca cuentan como superadas. No equivale al Plugin Check de WordPress.org.</p></section>';
    }
}

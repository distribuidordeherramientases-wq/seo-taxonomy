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
            'server_snapshot'=> array( 'diagnostics', __( 'Último diagnóstico del servidor', 'taxocommerce-system-check' ) ),
            'modules'        => array( 'diagnostics', 'Registro de módulos activos' ),
            'url_engine'     => array( 'http', 'Motor y controles de auditoría HTTP' ),
            'url_hooks'      => array( 'http', 'Acciones y watchdog del auditor' ),
            'module_export'  => array( 'reports', 'Exportación JSON de módulos' ),
            'legacy_exports'=> array( 'reports', 'Exportaciones del motor histórico' ),
            'report_json'    => array( 'reports', __( 'Contrato JSON de Autocheck', 'taxocommerce-system-check' ) ),
            'network_uat'    => array( 'external', 'Conectividad HTTP real y redirecciones' ),
            'write_uat'      => array( 'external', 'Informes por correo, tareas y reparaciones' ),
        );
    }

    private static function result( $status, $message, $evidence = array() ) {
        return array(
            'status'   => $status,
            'message'  => (string) $message,
            'evidence' => is_array( $evidence ) ? $evidence : array(),
        );
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
                    return self::result( 'fail', __( 'El diagnóstico PHP independiente no está cargado.', 'taxocommerce-system-check' ) );
                }
                $rows = TaxoCommerce_System_Check_PHP::collect();
                if ( ! is_array( $rows ) || count( $rows ) < 10 ) {
                    return self::result( 'fail', __( 'El diagnóstico PHP no devuelve su inventario completo.', 'taxocommerce-system-check' ) );
                }
                $seen = array();
                $warnings = 0;
                foreach ( $rows as $row ) {
                    if ( ! is_array( $row ) || ! isset( $row['id'], $row['status'], $row['value'], $row['label'], $row['detail'] )
                        || ! is_string( $row['id'] ) || '' === $row['id']
                        || ! is_string( $row['label'] ) || '' === $row['label']
                        || ! is_scalar( $row['value'] ) || ! is_string( $row['detail'] )
                        || ! in_array( $row['status'], array( 'ok', 'info', 'warning', 'important', 'error' ), true )
                        || isset( $seen[ $row['id'] ] ) ) {
                        return self::result( 'fail', __( 'El inventario PHP contiene datos incompletos, duplicados o estados desconocidos.', 'taxocommerce-system-check' ) );
                    }
                    $seen[ $row['id'] ] = true;
                    if ( in_array( $row['status'], array( 'warning', 'important', 'error' ), true ) ) {
                        ++$warnings;
                    }
                }
                return self::result(
                    'pass',
                    sprintf( __( '%1$d comprobaciones PHP válidas; %2$d avisos del entorno (consultables en Sistema > PHP).', 'taxocommerce-system-check' ), count( $rows ), $warnings ),
                    array( 'checks' => count( $rows ), 'environment_warnings' => $warnings )
                );
            case 'server_engine':
                return is_callable( 'seo_server_status' )
                    ? self::result( 'pass', __( 'La pantalla de diagnóstico del servidor tiene un motor ejecutable.', 'taxocommerce-system-check' ) )
                    : self::result( 'fail', __( 'No hay un motor de diagnóstico del servidor ejecutable. Comprueba la carga del módulo independiente.', 'taxocommerce-system-check' ) );
            case 'server_snapshot':
                // Only inspect previously stored data: never invoke collect_snapshot(),
                // get_current_snapshot() or get_reporting_snapshot(), which can scan or write.
                if ( ! function_exists( 'seo_server_status_load_snapshot' ) ) {
                    return self::result( 'skip', __( 'La lectura del histórico de servidor no está disponible; no se inicia ningún escaneo.', 'taxocommerce-system-check' ) );
                }
                $snapshot = seo_server_status_load_snapshot();
                if ( empty( $snapshot ) ) {
                    return self::result( 'skip', __( 'Aún no hay un diagnóstico del servidor guardado. Ejecuta su comprobación desde Sistema.', 'taxocommerce-system-check' ) );
                }
                if ( ! is_array( $snapshot ) || ! isset( $snapshot['checks'], $snapshot['generated_at'] )
                    || ! is_array( $snapshot['checks'] ) || empty( $snapshot['checks'] )
                    || ! is_numeric( $snapshot['generated_at'] ) || (int) $snapshot['generated_at'] <= 0
                    || (int) $snapshot['generated_at'] > time() + 60 ) {
                    return self::result( 'fail', __( 'El último informe guardado del servidor tiene una estructura o fecha inválida.', 'taxocommerce-system-check' ) );
                }
                $statuses = array( 'ok' => 0, 'info' => 0, 'warning' => 0, 'important' => 0, 'error' => 0 );
                $ids = array();
                foreach ( $snapshot['checks'] as $check ) {
                    if ( ! is_array( $check ) || empty( $check['code'] )
                        || ! is_string( $check['code'] ) || ! isset( $check['status'], $check['label'] )
                        || ! is_string( $check['label'] ) || ! isset( $statuses[ $check['status'] ] )
                        || isset( $ids[ $check['code'] ] ) ) {
                        return self::result( 'fail', __( 'El último diagnóstico contiene comprobaciones incompletas o duplicadas.', 'taxocommerce-system-check' ) );
                    }
                    $ids[ $check['code'] ] = true;
                    ++$statuses[ $check['status'] ];
                }
                $age = max( 0, time() - (int) $snapshot['generated_at'] );
                $evidence = array(
                    'generated_at' => gmdate( 'c', (int) $snapshot['generated_at'] ),
                    'age_seconds'   => $age,
                    'checks'        => count( $snapshot['checks'] ),
                    'statuses'      => $statuses,
                );
                if ( $age > 86400 ) {
                    return self::result( 'skip', __( 'El informe de servidor supera las 24 horas: se conserva como histórico, no como prueba actual.', 'taxocommerce-system-check' ), $evidence );
                }
                return self::result(
                    'pass',
                    sprintf( __( 'Informe guardado válido: %d comprobaciones; su estado de salud se consulta en Sistema.', 'taxocommerce-system-check' ), count( $snapshot['checks'] ) ),
                    $evidence
                );
            case 'modules':
                if ( ! function_exists( 'taxocommerce_system_check_module_results' ) ) {
                    return self::result( 'fail', __( 'Falta la lectura de módulos activos.', 'taxocommerce-system-check' ) );
                }
                $modules = taxocommerce_system_check_module_results();
                if ( ! is_array( $modules ) || ! isset( $modules['system_check'] ) ) {
                    return self::result( 'fail', __( 'System Check no figura en sus módulos registrados.', 'taxocommerce-system-check' ) );
                }
                $invalid = array();
                foreach ( $modules as $id => $module ) {
                    if ( ! is_array( $module ) || empty( $module['version'] )
                        || ! isset( $module['status'], $module['checks'] ) || ! is_array( $module['checks'] )
                        || ! in_array( $module['status'], array( 'ok', 'warning' ), true ) ) {
                        $invalid[] = (string) $id;
                        continue;
                    }
                    $fails = 0;
                    foreach ( $module['checks'] as $check ) {
                        if ( ! is_array( $check ) || ! isset( $check['ok'], $check['label'] )
                            || ! is_bool( $check['ok'] ) || ! is_string( $check['label'] ) ) {
                            $invalid[] = (string) $id;
                            continue 2;
                        }
                        if ( ! $check['ok'] ) {
                            ++$fails;
                        }
                    }
                    if ( ( $fails && 'warning' !== $module['status'] ) || ( ! $fails && 'ok' !== $module['status'] ) || $fails ) {
                        $invalid[] = (string) $id;
                    }
                }
                if ( $invalid ) {
                    return self::result(
                        'fail',
                        __( 'Hay módulos registrados con contratos inválidos o comprobaciones fallidas.', 'taxocommerce-system-check' ),
                        array( 'module_count' => count( $modules ), 'affected_modules' => array_values( array_unique( $invalid ) ) )
                    );
                }
                return self::result(
                    'pass',
                    sprintf( __( '%d módulos comprobados: versiones, estados y verificaciones coherentes.', 'taxocommerce-system-check' ), count( $modules ) ),
                    array( 'module_count' => count( $modules ) )
                );
            case 'url_engine':
                if ( ! class_exists( 'TaxoCommerce_System_Check_URL_Audit', false ) ) {
                    return self::result( 'fail', __( 'No está cargado el auditor HTTP.', 'taxocommerce-system-check' ) );
                }
                $required = array( 'render', 'process_batch', 'start', 'download_json', 'handle_action' );
                foreach ( $required as $method ) {
                    if ( ! is_callable( array( 'TaxoCommerce_System_Check_URL_Audit', $method ) ) ) {
                        return self::result( 'fail', sprintf( __( 'Falta el método HTTP ejecutable: %s.', 'taxocommerce-system-check' ), $method ) );
                    }
                }
                return self::result(
                    'pass',
                    __( 'Interfaz, inicio, proceso por lotes, controles y descarga HTTP disponibles; no se hace ninguna petición.', 'taxocommerce-system-check' ),
                    array( 'methods_checked' => count( $required ) )
                );
            case 'url_hooks':
                if ( ! class_exists( 'TaxoCommerce_System_Check_URL_Audit', false ) ) {
                    return self::result( 'fail', __( 'No se puede verificar el auditor HTTP: clase ausente.', 'taxocommerce-system-check' ) );
                }
                $audit = 'TaxoCommerce_System_Check_URL_Audit';
                $hooks = array(
                    'admin_post_taxocommerce_system_check_http_json' => array( $audit, 'download_json' ),
                    $audit::HOOK => array( $audit, 'process_batch' ),
                    $audit::RESUME_HOOK => array( $audit, 'watchdog' ),
                    'init' => array( $audit, 'maybe_schedule_watchdog' ),
                );
                foreach ( $hooks as $hook => $callback ) {
                    if ( false === has_action( $hook, $callback ) ) {
                        return self::result( 'fail', sprintf( __( 'Falta el callback HTTP del hook %s.', 'taxocommerce-system-check' ), $hook ) );
                    }
                }
                $schedule = $audit::cron_intervals( array() );
                if ( ! is_array( $schedule ) || ! isset( $schedule['tcs_five_minutes']['interval'] )
                    || 300 !== (int) $schedule['tcs_five_minutes']['interval'] ) {
                    return self::result( 'fail', __( 'El intervalo del vigilante HTTP es inválido.', 'taxocommerce-system-check' ) );
                }
                return self::result(
                    'pass',
                    __( 'Callbacks HTTP y vigilancia de cinco minutos registrados y consistentes; no se activan procesos.', 'taxocommerce-system-check' ),
                    array( 'hooks_checked' => count( $hooks ), 'watchdog_interval_seconds' => 300 )
                );
            case 'module_export':
                return function_exists( 'taxocommerce_system_check_modules_json' )
                    && false !== has_action( 'admin_post_taxocommerce_system_check_modules_json', 'taxocommerce_system_check_modules_json' )
                    ? self::result( 'pass', __( 'El exportador de módulos tiene función y acción de descarga registradas.', 'taxocommerce-system-check' ) )
                    : self::result( 'fail', __( 'La exportación JSON de módulos no está correctamente registrada.', 'taxocommerce-system-check' ) );
            case 'legacy_exports':
                return function_exists( 'seo_system_diagnostics_handle_download_json' )
                    && has_action( 'admin_post_seo_system_diagnostics_download_json' )
                    ? self::result( 'pass', __( 'Exportación histórica JSON disponible (no certifica la vigencia del informe).', 'taxocommerce-system-check' ) )
                    : self::result( 'skip', __( 'Exportación del motor histórico no cargada; no es requisito del plugin independiente.', 'taxocommerce-system-check' ) );
            case 'report_json':
                if ( ! is_callable( 'taxocommerce_system_check_autocheck_export_json' )
                    || false === has_action( 'admin_post_taxocommerce_system_check_autocheck_json', 'taxocommerce_system_check_autocheck_export_json' ) ) {
                    return self::result( 'fail', __( 'El endpoint del JSON de Autocheck no está registrado.', 'taxocommerce-system-check' ) );
                }
                $fixture = array(
                    'schema' => 1,
                    'rows' => array( array( 'id' => 'test', 'status' => 'skip', 'message' => 'No ejecutado' ) ),
                    'totals' => array( 'pass' => 0, 'fail' => 0, 'skip' => 1 ),
                );
                $json = wp_json_encode( $fixture, JSON_UNESCAPED_UNICODE );
                $decoded = is_string( $json ) ? json_decode( $json, true ) : null;
                if ( ! is_array( $decoded ) || $fixture !== $decoded ) {
                    return self::result( 'fail', __( 'La serialización JSON pierde estados o estructura.', 'taxocommerce-system-check' ) );
                }
                return self::result( 'pass', __( 'Endpoint disponible y serialización JSON validada, incluido el estado no ejecutado.', 'taxocommerce-system-check' ), array( 'schema' => 1 ) );
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
        $executed = $totals['pass'] + $totals['fail'];
        $generated_at = gmdate( 'c' );
        return array(
            'version' => TAXOCOMMERCE_SYSTEM_CHECK_VERSION,
            'generated_at' => $generated_at, 'executed_at' => $generated_at,
            'coverage' => array(
                'executed' => $executed,
                'total' => count( $rows ),
                'percentage' => count( $rows ) ? (int) round( 100 * $executed / count( $rows ) ) : 0,
            ),
            'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
            'totals' => $totals,
            'rows' => $rows,
        );
    }

    /** Read only the last report for this WordPress site and administrator. */
    private static function latest_report() {
        if ( ! function_exists( 'get_current_blog_id' ) || ! function_exists( 'get_current_user_id' ) ) {
            return false;
        }
        $report = get_transient( 'taxocommerce_system_check_autocheck_latest_' . get_current_blog_id() . '_' . get_current_user_id() );
        return is_array( $report ) && isset( $report['rows'], $report['totals'], $report['executed_at'] ) ? $report : false;
    }

    /** Export only already executed, stored checks; never trigger a scan. */
    private static function render_download( $report ) {
        if ( ! is_array( $report ) ) {
            return;
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="taxocommerce_system_check_autocheck_json">';
        wp_nonce_field( 'taxocommerce_system_check_autocheck_json' );
        submit_button( __( 'Descargar resultados (JSON)', 'taxocommerce-system-check' ), 'secondary', 'submit', false );
        echo '<p><strong>' . esc_html__( 'Fecha de ejecución:', 'taxocommerce-system-check' ) . '</strong> '
            . esc_html( ( function_exists( 'wp_date' ) ? wp_date( 'd/m/Y H:i:s T', strtotime( (string) $report['executed_at'] ) ) : gmdate( 'd/m/Y H:i:s', strtotime( (string) $report['executed_at'] ) ) . ' UTC' ) ) . '</p>';
        echo '</form>';
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-system-check' ) );
        }
        $url = wp_nonce_url(
            taxocommerce_system_check_tab_url( 'auto-test', array( 'tcs_run' => '1' ) ),
            'tcs_auto_test_run'
        );
        echo '<section class="wrap tcs-auto-test">' . '<h2>' . esc_html__( 'Autocheck · System Check', 'taxocommerce-system-check' ) . '</h2>';
        echo '<p>' . esc_html__( 'Comprueba las rutas, servicios y contratos del propio plugin. Es una ejecución local, de solo lectura y sin sondas HTTP, escrituras ni correos.', 'taxocommerce-system-check' ) . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">Ejecutar Autocheck</a></p>';
        $latest_report = self::latest_report();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only selection; the nonce is verified before running any checks.
        $requested = isset( $_GET['tcs_run'] ) && is_scalar( $_GET['tcs_run'] )
            && '1' === sanitize_key( wp_unslash( $_GET['tcs_run'] ) );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( ! $requested ) {
            self::render_download( $latest_report );
            echo '<p class="description">' . esc_html__( 'Las pruebas no se ejecutan al abrir esta pestaña. Pulsa el botón para obtener un resultado actual.', 'taxocommerce-system-check' ) . '</p>' . '</section>';
            return;
        }
        check_admin_referer( 'tcs_auto_test_run' );
        $report = self::run();
        $autocheck_report = $report;
        $autocheck_report['plugin'] = 'taxocommerce-system-check';
        $autocheck_report['schema'] = 1;
        if ( function_exists( 'set_transient' ) && function_exists( 'get_current_blog_id' ) && function_exists( 'get_current_user_id' ) ) {
            set_transient( 'taxocommerce_system_check_autocheck_latest_' . get_current_blog_id() . '_' . get_current_user_id(), $autocheck_report, HOUR_IN_SECONDS );
        }
        self::render_download( $autocheck_report );
        echo '<p><strong>' . esc_html__( 'Resultados:', 'taxocommerce-system-check' ) . '</strong> ' . esc_html( (string) $report['totals']['pass'] ) . ' correctas, '
            . esc_html( (string) $report['totals']['fail'] ) . ' fallidas y '
            . esc_html( (string) $report['totals']['skip'] ) . ' no ejecutadas / no aplicables. '
            . esc_html( (string) $report['duration_ms'] ) . ' ms.</p>';
        echo '<p>' . esc_html( sprintf( __( 'Cobertura del Autocheck: %1$d de %2$d pruebas (%3$d%%). Los chequeos omitidos no se consideran superados.', 'taxocommerce-system-check' ), $report['coverage']['executed'], $report['coverage']['total'], $report['coverage']['percentage'] ) ) . '</p>';
        foreach ( self::groups() as $group => $label ) {
            echo '<h3>' . esc_html( $label ) . '</h3>';
            echo '<table class="widefat striped">' . '<thead>' . '<tr>' . '<th scope="col">' . esc_html__( 'Comprobación', 'taxocommerce-system-check' ) . '</th>' . '<th scope="col">' . esc_html__( 'Estado', 'taxocommerce-system-check' ) . '</th>' . '<th scope="col">' . esc_html__( 'Evidencia', 'taxocommerce-system-check' ) . '</th>' . '</tr>' . '</thead>' . '<tbody>';
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
        echo '<p class="description">' . esc_html__( 'Un resultado correcto indica que el componente está accesible, no que un escaneo real haya funcionado. Las pruebas omitidas nunca cuentan como superadas. No equivale al Plugin Check de WordPress.org.', 'taxocommerce-system-check' ) . '</p>' . '</section>';
    }
}


/**
 * Download the current user's most recent explicit diagnostic run.
 * Never execute an import, export, connection or worker during download.
 */
function taxocommerce_system_check_autocheck_export_json() {
    if ( ! ( current_user_can( 'manage_options' ) ) ) {
        wp_die( esc_html__( 'Sin permisos para descargar el Autocheck.', 'taxocommerce-system-check' ) );
    }
    check_admin_referer( 'taxocommerce_system_check_autocheck_json' );
    $report = get_transient( 'taxocommerce_system_check_autocheck_latest_' . get_current_blog_id() . '_' . get_current_user_id() );
    if ( ! is_array( $report ) || ! isset( $report['rows'], $report['totals'] ) ) {
        wp_die( esc_html__( 'Ejecuta el Autocheck antes de descargar el JSON.', 'taxocommerce-system-check' ) );
    }
    $json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
    if ( ! is_string( $json ) ) {
        wp_die( esc_html__( 'No se pudo generar el informe JSON.', 'taxocommerce-system-check' ) );
    }
    nocache_headers();
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'Content-Disposition: attachment; filename="taxocommerce-system-check-autocheck-' . gmdate( 'Ymd-His' ) . '.json"' );
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The response is a JSON attachment.
    echo $json;
    exit;
}
if ( function_exists( 'add_action' ) ) {
    add_action( 'admin_post_taxocommerce_system_check_autocheck_json', 'taxocommerce_system_check_autocheck_export_json' );
}

<?php
/**
 * Grouped, read-only health checks for TaxoCommerce Import-Export.
 *
 * Never starts a queue, imports a row, opens a CSV, writes a Media ZIP,
 * changes options or acquires a worker lock. Only saves two reports in
 * current-user metadata (before and last), scoped by multisite blog ID.
 *
 * @package TaxoCommerceImportExport
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Import_Export_Auto_Test {
    const RUN = 'taxocommerce_ie_auto_test_run';
    const EXPORT = 'taxocommerce_ie_auto_test_export';
    const NONCE = 'taxocommerce_ie_auto_test';
    const BEFORE = 'taxocommerce_ie_autotest_before_';
    const LAST = 'taxocommerce_ie_autotest_last_';

    public static function boot() {
        add_action( 'admin_post_' . self::RUN, array( __CLASS__, 'run_action' ) );
        add_action( 'admin_post_' . self::EXPORT, array( __CLASS__, 'export_action' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    private static function permitted() {
        $cap = (string) apply_filters( 'taxocommerce_import_export_capability', 'manage_woocommerce' );
        return current_user_can( $cap ) || current_user_can( 'manage_options' );
    }

    public static function enqueue( $hook ) {
        if ( false === strpos( (string) $hook, 'taxocommerce-import-export' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector.
        $tab = isset( $_GET['tc_ie_tab'] ) && is_string( $_GET['tc_ie_tab'] )
            ? sanitize_key( wp_unslash( $_GET['tc_ie_tab'] ) ) : '';
        if ( 'auto-test' === $tab ) {
            wp_enqueue_style( 'taxocommerce-ie-autotest', plugin_dir_url( TAXOCOMMERCE_IMPORT_EXPORT_FILE ) . 'assets/auto-test.css', array(), TAXOCOMMERCE_IMPORT_EXPORT_VERSION );
        }
    }

    private static function key( $before ) {
        return ( $before ? self::BEFORE : self::LAST ) . get_current_blog_id();
    }

    private static function site_id() {
        return hash( 'sha256', home_url( '/' ) );
    }

    public static function groups() {
        return array(
            'system' => 'Arranque y administración',
            'csv' => 'Contratos CSV y países',
            'imports' => 'Importación individual y por lotes',
            'exports' => 'Exportación por bloques',
            'media' => 'Media ZIP',
            'load' => 'Regulador y progreso',
            'deployment' => 'Preparación del despliegue',
            'manual' => 'Pruebas funcionales externas',
        );
    }

    public static function cases() {
        return array(
            'classes' => array( 'system', 'Clases del plugin disponibles' ),
            'woocommerce' => array( 'system', 'Dependencia WooCommerce' ),
            'menu' => array( 'system', 'Menú y pestañas' ),
            'assets' => array( 'system', 'CSS y JavaScript instalados' ),
            'core' => array( 'system', 'Integración opcional con Core' ),
            'handlers' => array( 'system', 'Rutas y permisos de Auto Test' ),
            'csv_types' => array( 'csv', 'Cinco conjuntos de exportación' ),
            'csv_headers' => array( 'csv', 'Cabeceras con identidad y país' ),
            'country_alias' => array( 'csv', 'Alias históricos de país' ),
            'csv_inference' => array( 'csv', 'Inferencia de CSV de corrección' ),
            'portable' => array( 'csv', 'Inventario reducido sin datos comerciales' ),
            'individual_handlers' => array( 'imports', 'Importación individual y monitor AJAX' ),
            'batch_handlers' => array( 'imports', 'Cola, controles y eliminación pendiente' ),
            'worker' => array( 'imports', 'Worker, continuidad y watchdog' ),
            'queue_state' => array( 'imports', 'Estado de cola conservado' ),
            'queue_folders' => array( 'imports', 'Carpetas privadas de cola' ),
            'export_handlers' => array( 'exports', 'Exportación incremental y descarga' ),
            'export_state' => array( 'exports', 'Estado de trabajo de exportación' ),
            'media_handlers' => array( 'media', 'Acciones de importar/exportar imágenes' ),
            'zip' => array( 'media', 'Extensión ZipArchive disponible' ),
            'load_levels' => array( 'load', 'Cinco prioridades de import/export' ),
            'load_adjustment' => array( 'load', 'Regulación adaptativa determinista' ),
            'progress' => array( 'load', 'Porcentaje por offset acotado' ),
            'safe_queue_upgrade' => array( 'deployment', 'Cola sin procesos activos' ),
            'safe_export_upgrade' => array( 'deployment', 'Exportación sin proceso activo' ),
            'report_storage' => array( 'deployment', 'Informes separados por usuario y sitio' ),
            'real_csv' => array( 'manual', 'Importación real de producto/categoría/post/página' ),
            'real_countries' => array( 'manual', 'Persistencia real ES/US/FR/DE/NL' ),
            'real_queue' => array( 'manual', 'Continuación por cron y loopback' ),
            'real_media' => array( 'manual', 'ZIP real y restauración de galerías' ),
            'real_export' => array( 'manual', 'CSV generado, reanudado y descargado' ),
        );
    }

    private static function outcome( $status, $description ) {
        return array( 'status' => $status, 'message' => $description );
    }
    private static function ok( $message ) { return self::outcome( 'pass', $message ); }
    private static function error( $message ) { return self::outcome( 'fail', $message ); }
    private static function skipped( $message ) { return self::outcome( 'skip', $message ); }

    public static function execute( $group = 'all', $override = null ) {
        $groups = self::groups();
        if ( 'all' !== $group && ! isset( $groups[ $group ] ) ) { $group = 'all'; }
        $cases = null === $override ? self::cases() : $override;
        $start = microtime( true );
        $rows = array();
        $counts = array( 'pass' => 0, 'fail' => 0, 'skip' => 0, 'not_run' => 0 );
        foreach ( $cases as $id => $entry ) {
            $at = microtime( true );
            if ( 'all' !== $group && $entry[0] !== $group ) {
                $status = self::outcome( 'not_run', 'Grupo no ejecutado.' );
            } else {
                try {
                    $callback = array( __CLASS__, 'check_' . $id );
                    $status = is_callable( $callback ) ? call_user_func( $callback ) : self::error( 'Prueba no implementada.' );
                    if ( ! is_array( $status ) || ! isset( $status['status'], $status['message'] )
                        || ! in_array( $status['status'], array( 'pass', 'fail', 'skip' ), true ) ) {
                        $status = self::error( 'Respuesta de prueba incorrecta.' );
                    }
                } catch ( Throwable $e ) {
                    // Never persist exception messages: they may expose CSV names or server paths.
                    $status = self::error( 'Excepción en la comprobación (' . get_class( $e ) . '). Consultar el registro privado.' );
                }
            }
            $rows[] = array(
                'id' => $id, 'group_id' => $entry[0], 'label' => $entry[1],
                'status' => $status['status'], 'message' => $status['message'],
                'duration_ms' => (int) round( ( microtime( true ) - $at ) * 1000 ),
            );
            ++$counts[ $status['status'] ];
        }
        global $wp_version;
        return array(
            'schema' => 1, 'phase' => 'check', 'group' => $group,
            'site_hash' => self::site_id(),
            'plugin_version' => TAXOCOMMERCE_IMPORT_EXPORT_VERSION,
            'wordpress_version' => isset( $wp_version ) ? $wp_version : '',
            'php_version' => PHP_VERSION, 'date_utc' => gmdate( 'c' ),
            'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
            'totals' => $counts, 'rows' => $rows,
        );
    }

    /** Only compare full before/after suites from the same installation. */
    public static function compare( $before, $after ) {
        if ( ! is_array( $before ) || ! is_array( $after ) || empty( $before['site_hash'] )
            || ! hash_equals( (string) $before['site_hash'], (string) ( $after['site_hash'] ?? '' ) )
            || 'before' !== ( $before['phase'] ?? '' ) || 'after' !== ( $after['phase'] ?? '' )
            || 'all' !== ( $before['group'] ?? '' ) || 'all' !== ( $after['group'] ?? '' ) ) {
            return null;
        }
        $old = array();
        $new = array();
        foreach ( (array) ( $before['rows'] ?? array() ) as $row ) {
            if ( isset( $row['id'], $row['status'] ) ) { $old[ $row['id'] ] = $row['status']; }
        }
        foreach ( (array) ( $after['rows'] ?? array() ) as $row ) {
            if ( isset( $row['id'], $row['status'] ) ) { $new[ $row['id'] ] = $row['status']; }
        }
        $result = array( 'new_errors' => array(), 'resolved' => array(), 'existing_errors' => array(), 'lost_coverage' => array(), 'new_checks' => array() );
        foreach ( $new as $id => $state ) {
            if ( ! isset( $old[ $id ] ) ) {
                $result['new_checks'][] = $id;
                if ( 'fail' === $state ) { $result['new_errors'][] = $id; }
                continue;
            }
            if ( 'fail' === $state ) {
                $result[ 'fail' === $old[ $id ] ? 'existing_errors' : 'new_errors' ][] = $id;
            } elseif ( 'pass' === $state && 'fail' === $old[ $id ] ) {
                $result['resolved'][] = $id;
            } elseif ( in_array( $old[ $id ], array( 'pass', 'fail' ), true ) && in_array( $state, array( 'skip', 'not_run' ), true ) ) {
                $result['lost_coverage'][] = $id;
            }
        }
        foreach ( $old as $id => $state ) {
            if ( ! array_key_exists( $id, $new ) ) { $result['lost_coverage'][] = $id; }
        }
        return $result;
    }

    private static function missing_hooks( $names ) {
        $missing = array();
        foreach ( $names as $name ) {
            if ( ! has_action( $name ) ) { $missing[] = $name; }
        }
        return $missing;
    }

    private static function check_classes() {
        foreach ( array( 'TaxoCommerce_Import_Export', 'TaxoCommerce_Import_Export_CSV',
            'TaxoCommerce_Import_Export_Batch', 'TaxoCommerce_Import_Export_Exporter',
            'TaxoCommerce_Import_Export_Media', 'TaxoCommerce_IE_Load' ) as $class ) {
            if ( ! class_exists( $class ) ) { return self::error( 'Clase no disponible: ' . $class ); }
        }
        return self::ok( 'Seis servicios de importación/exportación cargados.' );
    }

    private static function check_woocommerce() {
        return function_exists( 'wc_get_product' ) && function_exists( 'wc_get_products' )
            ? self::ok( 'WooCommerce tiene disponible su API de productos.' )
            : self::error( 'WooCommerce no está disponible.' );
    }

    private static function check_menu() {
        return has_action( 'admin_menu', array( taxocommerce_import_export(), 'register_menu' ) )
            ? self::ok( 'Menú y pantalla del plugin registrados.' )
            : self::error( 'Falta el menú de Import-Export.' );
    }

    private static function check_assets() {
        foreach ( array( 'assets/admin.css', 'assets/auto-test.css', 'assets/batch-runner.js', 'assets/exporter.js' ) as $file ) {
            if ( ! is_readable( TAXOCOMMERCE_IMPORT_EXPORT_PATH . $file ) ) {
                return self::error( 'Archivo no disponible: ' . $file );
            }
        }
        return self::ok( 'Recursos del administrador disponibles y legibles.' );
    }

    private static function check_core() {
        if ( ! function_exists( 'taxocommerce_core' ) ) {
            return self::skipped( 'Core es opcional y no está activo.' );
        }
        return has_action( 'taxocommerce_core_register_modules', array( taxocommerce_import_export(), 'register_in_core' ) )
            ? self::ok( 'Registro en Core disponible.' )
            : self::error( 'No se ha registrado la integración con Core.' );
    }

    private static function check_handlers() {
        $missing = self::missing_hooks( array( 'admin_post_' . self::RUN, 'admin_post_' . self::EXPORT ) );
        return $missing || ! self::permitted()
            ? self::error( 'Faltan rutas o permisos de Auto Test.' )
            : self::ok( 'Ejecución y exportación registradas con verificación de capacidad y nonce.' );
    }

    private static function check_csv_types() {
        $types = TaxoCommerce_Import_Export_Exporter::types();
        $expected = array( 'products', 'products_reduced', 'product_categories', 'posts', 'pages' );
        return is_array( $types ) && ! array_diff( $expected, array_keys( $types ) )
            ? self::ok( 'Cinco tipos de exportación CSV registrados.' )
            : self::error( 'Falta algún tipo de exportación.' );
    }

    private static function check_csv_headers() {
        $csv = new TaxoCommerce_Import_Export_CSV();
        foreach ( array_keys( TaxoCommerce_Import_Export_Exporter::types() ) as $type ) {
            $headers = $csv->export_header_for_job( $type );
            if ( ! is_array( $headers ) || ! in_array( 'record_type', $headers, true )
                || ! in_array( 'id', $headers, true ) || ! in_array( 'country_code', $headers, true )
                || count( array_unique( $headers ) ) !== count( $headers ) ) {
                return self::error( 'Cabecera CSV incorrecta: ' . $type );
            }
        }
        return self::ok( 'Los cinco CSV mantienen identidad, país y cabeceras únicas.' );
    }

    private static function check_country_alias() {
        $csv = new TaxoCommerce_Import_Export_CSV();
        $headers = $csv->normalize_headers_for_batch( array( 'country', 'pais', 'pais_origen', '_seo_pais_origen' ) );
        return array( 'country_code', 'country_code', 'country_code', 'country_code' ) === $headers
            ? self::ok( 'Los cuatro nombres históricos de país se normalizan.' )
            : self::error( 'La importación perdió alias de países.' );
    }

    private static function check_csv_inference() {
        $csv = new TaxoCommerce_Import_Export_CSV();
        return 'product' === $csv->infer_record_type_from_headers( array( 'sku', 'sale_price' ) )
            && 'product_category' === $csv->infer_record_type_from_headers( array( 'parent_id' ) )
            && '' === $csv->infer_record_type_from_headers( array( 'id', 'name' ) )
            ? self::ok( 'Detecta CSV inequívocos y rechaza esquemas ambiguos.' )
            : self::error( 'Inferencia de tipo CSV incorrecta.' );
    }

    private static function check_portable() {
        $headers = ( new TaxoCommerce_Import_Export_CSV() )->export_header_for_job( 'products_reduced' );
        return in_array( 'vocabulary_json', $headers, true )
            && in_array( 'architecture_json', $headers, true )
            && ! in_array( 'regular_price', $headers, true )
            && ! in_array( 'description', $headers, true )
            ? self::ok( 'Inventario reducido contiene semántica sin precios ni descripción extensa.' )
            : self::error( 'Cambió el contrato del inventario reducido.' );
    }

    private static function check_individual_handlers() {
        $missing = self::missing_hooks( array(
            'admin_post_taxocommerce_import_export_import', 'wp_ajax_taxocommerce_ie_individual_status',
        ) );
        return $missing ? self::error( 'Faltan rutas de importación individual.' )
            : self::ok( 'Recepción de CSV y monitor AJAX registrados; sin ejecutar filas.' );
    }

    private static function check_batch_handlers() {
        $missing = self::missing_hooks( array(
            'admin_post_taxocommerce_ie_batch_upload', 'admin_post_taxocommerce_ie_batch_control',
            'admin_post_taxocommerce_ie_batch_delete_pending', 'wp_ajax_taxocommerce_ie_batch_step',
        ) );
        return $missing ? self::error( 'Acciones de cola ausentes: ' . implode( ', ', $missing ) )
            : self::ok( 'Subida, control, eliminación pendiente y procesamiento AJAX registrados.' );
    }

    private static function check_worker() {
        $missing = self::missing_hooks( array(
            TaxoCommerce_Import_Export_Batch::WORKER_HOOK,
            'wp_ajax_taxocommerce_ie_batch_continue',
            'wp_ajax_nopriv_taxocommerce_ie_batch_continue',
        ) );
        return $missing || ! has_action( 'init', array( taxocommerce_import_export(), 'watchdog' ) ) && ! has_action( 'init' )
            ? self::error( 'Worker o recuperación no registrados.' )
            : self::ok( 'Worker y continuidad por loopback registrados, sin despachar tareas.' );
    }

    private static function check_queue_state() {
        $state = get_option( TaxoCommerce_Import_Export_Batch::OPTION_STATE, array() );
        if ( ! is_array( $state ) ) { return self::error( 'Estado de cola corrupto.' ); }
        if ( isset( $state['offset'] ) && ( ! is_numeric( $state['offset'] ) || (float) $state['offset'] < 0 ) ) {
            return self::error( 'Offset de cola inválido.' );
        }
        return self::ok( 'Estado de cola legible; no se han tocado sus archivos.' );
    }

    private static function check_queue_folders() {
        $upload = wp_get_upload_dir();
        if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
            return self::error( 'No es posible localizar uploads.' );
        }
        $dir = trailingslashit( $upload['basedir'] ) . 'taxocommerce-import-export/queue';
        if ( ! is_dir( $dir ) ) { return self::skipped( 'Las carpetas privadas se crean con la primera importación.' ); }
        foreach ( array( 'pending', 'processing', 'imported', 'failed' ) as $name ) {
            if ( ! is_dir( trailingslashit( $dir ) . $name ) ) {
                return self::error( 'Falta carpeta privada: ' . $name );
            }
        }
        return self::ok( 'Carpetas privadas presentes sin abrir archivos CSV.' );
    }

    private static function check_export_handlers() {
        $missing = self::missing_hooks( array(
            'wp_ajax_taxocommerce_ie_export_start', 'wp_ajax_taxocommerce_ie_export_step',
            'wp_ajax_taxocommerce_ie_export_status', 'wp_ajax_taxocommerce_ie_export_cancel',
            'admin_post_taxocommerce_ie_export_download',
        ) );
        return $missing ? self::error( 'Controladores de exportación incompletos.' )
            : self::ok( 'Inicio, bloques, monitor, cancelación y descarga registrados.' );
    }

    private static function check_export_state() {
        $state = get_transient( TaxoCommerce_Import_Export_Exporter::STATE_PREFIX . get_current_user_id() );
        return false === $state || is_array( $state )
            ? self::ok( 'Registro de exportación consultable sin crear ni borrar trabajos.' )
            : self::error( 'Estado del exportador con formato incorrecto.' );
    }

    private static function check_media_handlers() {
        $missing = self::missing_hooks( array(
            'admin_post_taxocommerce_import_export_media_export', 'admin_post_taxocommerce_import_export_media_import',
        ) );
        return $missing ? self::error( 'Faltan rutas del paquete Media.' )
            : self::ok( 'Importación y exportación ZIP registradas sin crear adjuntos.' );
    }

    private static function check_zip() {
        return class_exists( 'ZipArchive' )
            ? self::ok( 'ZipArchive está disponible.' )
            : self::error( 'ZipArchive no está instalado: los paquetes Media fallarán.' );
    }

    private static function check_load_levels() {
        $levels = TaxoCommerce_IE_Load::labels();
        if ( ! is_array( $levels ) || 5 !== count( $levels ) ) { return self::error( 'No hay cinco niveles de carga.' ); }
        foreach ( array( 'import', 'export' ) as $type ) {
            $limits = TaxoCommerce_IE_Load::size_limits( $type );
            if ( ! isset( $limits['initial'], $limits['maximum'] ) || $limits['initial'] < 1 || $limits['maximum'] < $limits['initial'] ) {
                return self::error( 'Límites incorrectos en ' . $type );
            }
        }
        return self::ok( 'Importación y exportación mantienen perfiles de carga separados.' );
    }

    private static function check_load_adjustment() {
        $increase = TaxoCommerce_IE_Load::import_adjustment( 60, 120, 60, 2, 50, 25, 20 );
        $decrease = TaxoCommerce_IE_Load::import_adjustment( 60, 120, 60, 2, 85, 25, 20 );
        return 120 === $increase[0] && 30 === $decrease[0]
            ? self::ok( 'Aumento y reducción por memoria correctos.' )
            : self::error( 'Regresión del regulador adaptativo.' );
    }

    private static function check_progress() {
        $half = TaxoCommerce_Import_Export_Batch::progress_values( 60, 120 );
        $over = TaxoCommerce_Import_Export_Batch::progress_values( 500, 120 );
        return 50.0 === (float) $half['percent'] && 100.0 === (float) $over['percent']
            ? self::ok( 'Progreso 0–100 % sin recorrer CSV.' )
            : self::error( 'El cálculo del progreso no está acotado.' );
    }

    private static function check_safe_queue_upgrade() {
        $state = get_option( TaxoCommerce_Import_Export_Batch::OPTION_STATE, array() );
        if ( ! is_array( $state ) ) { return self::error( 'Estado de cola inválido.' ); }
        if ( ! empty( $state['enabled'] ) || ! empty( $state['current_file'] )
            || in_array( (string) ( $state['status'] ?? '' ), array( 'processing', 'waiting_next' ), true ) ) {
            return self::error( 'COLA ACTIVA: no desplegar hasta finalizar o pausar correctamente desde el plugin.' );
        }
        return self::ok( 'No consta cola CSV activa. La cola no ha sido modificada.' );
    }

    private static function check_safe_export_upgrade() {
        $state = get_transient( TaxoCommerce_Import_Export_Exporter::STATE_PREFIX . get_current_user_id() );
        if ( is_array( $state ) && in_array( (string) ( $state['status'] ?? '' ), array( 'running', 'processing', 'pending', 'active' ), true ) ) {
            return self::error( 'Exportación en curso para este usuario. No desplegar todavía.' );
        }
        return self::ok( 'No consta exportación activa del usuario. Revisar otros administradores por separado.' );
    }

    private static function check_report_storage() {
        return self::key( true ) !== self::key( false ) && function_exists( 'update_user_meta' )
            ? self::ok( 'Informes separados por usuario y sitio, sin contenido de productos.' )
            : self::error( 'No está disponible la persistencia de resultados.' );
    }

    private static function check_real_csv() {
        return self::skipped( 'No crea ni modifica contenido: validar CSV real en staging.' );
    }
    private static function check_real_countries() {
        return self::skipped( 'La persistencia de países requiere importar y verificar CSV reales en staging.' );
    }
    private static function check_real_queue() {
        return self::skipped( 'Verificar cron/loopback y cola real manualmente, sin arrancar trabajos desde Auto Test.' );
    }
    private static function check_real_media() {
        return self::skipped( 'Comprobar export/import de ZIP real y relaciones de imágenes en staging.' );
    }
    private static function check_real_export() {
        return self::skipped( 'La reanudación y descarga deben verificarse con una exportación real de prueba.' );
    }

    public static function run_action() {
        if ( ! self::permitted() ) { wp_die( esc_html__( 'Sin permisos para Auto Test.', 'taxocommerce-import-export' ) ); }
        check_admin_referer( self::NONCE, 'tc_ie_auto_test_nonce' );
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $group = isset( $_POST['test_group'] ) && is_string( $_POST['test_group'] )
            ? sanitize_key( wp_unslash( $_POST['test_group'] ) ) : 'all';
        $phase = isset( $_POST['test_phase'] ) && is_string( $_POST['test_phase'] )
            ? sanitize_key( wp_unslash( $_POST['test_phase'] ) ) : 'check';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( 'all' !== $group && ! isset( self::groups()[ $group ] ) ) { $group = 'all'; }
        if ( 'all' !== $group || ! in_array( $phase, array( 'before', 'after', 'check' ), true ) ) { $phase = 'check'; }
        $report = self::execute( $group );
        $report['phase'] = $phase;
        update_user_meta( get_current_user_id(), self::key( false ), $report );
        if ( 'before' === $phase ) {
            update_user_meta( get_current_user_id(), self::key( true ), $report );
        }
        wp_safe_redirect( self::url() );
        exit;
    }

    public static function export_action() {
        if ( ! self::permitted() ) { wp_die( esc_html__( 'Sin permisos para exportar Auto Test.', 'taxocommerce-import-export' ) ); }
        check_admin_referer( self::NONCE, 'tc_ie_auto_test_nonce' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $type = isset( $_POST['report_kind'] ) && is_string( $_POST['report_kind'] )
            ? sanitize_key( wp_unslash( $_POST['report_kind'] ) ) : 'last';
        $report = self::stored( 'before' === $type );
        if ( ! $report ) { wp_die( esc_html__( 'No hay informe para exportar.', 'taxocommerce-import-export' ) ); }
        $json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $json ) { wp_die( esc_html__( 'Error al generar JSON.', 'taxocommerce-import-export' ) ); }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="import-export-auto-test-' . ( 'before' === $type ? 'antes' : 'ultimo' ) . '-' . gmdate( 'Ymd-His' ) . '.json"' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Valid JSON download, not HTML.
        echo $json;
        exit;
    }

    private static function stored( $before ) {
        $report = get_user_meta( get_current_user_id(), self::key( $before ), true );
        return is_array( $report ) && ! empty( $report['rows'] ) && ! empty( $report['site_hash'] )
            && hash_equals( self::site_id(), (string) $report['site_hash'] ) ? $report : array();
    }

    private static function url() {
        return add_query_arg( array( 'page' => 'taxocommerce-import-export', 'tc_ie_tab' => 'auto-test' ), admin_url( 'admin.php' ) );
    }

    private static function run_button( $group, $phase, $label, $primary = false ) {
        ?>
        <form method="post" class="tc-ie-at-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::RUN ); ?>">
            <input type="hidden" name="test_group" value="<?php echo esc_attr( $group ); ?>">
            <input type="hidden" name="test_phase" value="<?php echo esc_attr( $phase ); ?>">
            <?php wp_nonce_field( self::NONCE, 'tc_ie_auto_test_nonce' ); ?>
            <button class="button <?php echo $primary ? 'button-primary' : 'button-secondary'; ?>" type="submit"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    private static function export_button( $before, $label ) {
        ?>
        <form method="post" class="tc-ie-at-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::EXPORT ); ?>">
            <input type="hidden" name="report_kind" value="<?php echo $before ? 'before' : 'last'; ?>">
            <?php wp_nonce_field( self::NONCE, 'tc_ie_auto_test_nonce' ); ?>
            <button class="button button-secondary" type="submit"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    public static function render() {
        if ( ! self::permitted() ) { wp_die( esc_html__( 'No tienes permiso para Auto Test.', 'taxocommerce-import-export' ) ); }
        $before = self::stored( true );
        $last = self::stored( false );
        $groups = self::groups();
        $cases = self::cases();
        $indexed = array();
        foreach ( (array) ( $last['rows'] ?? array() ) as $row ) {
            if ( isset( $row['id'] ) ) { $indexed[ $row['id'] ] = $row; }
        }
        $counts = $last['totals'] ?? array( 'pass' => 0, 'fail' => 0, 'skip' => 0, 'not_run' => count( $cases ) );
        $diff = $before && $last ? self::compare( $before, $last ) : null;
        ?>
        <section class="tc-ie-at" aria-labelledby="tc-ie-at-title">
            <h2 id="tc-ie-at-title"><?php esc_html_e( 'Auto Test — Import / Export', 'taxocommerce-import-export' ); ?></h2>
            <p><?php esc_html_e( 'Comprobaciones de solo lectura por módulo. No se importan CSV ni imágenes, no se ejecutan workers y no se cambian configuraciones. Solo se guardan los resultados del diagnóstico.', 'taxocommerce-import-export' ); ?></p>
            <div class="tc-ie-at-actions">
                <?php self::run_button( 'all', 'before', '1. Guardar estado ANTES', true ); ?>
                <?php self::run_button( 'all', 'after', '2. Comprobar DESPUÉS' ); ?>
                <?php self::run_button( 'all', 'check', 'Chequeo completo' ); ?>
            </div>
            <div class="tc-ie-at-snapshots">
                <div>
                    <h3><?php esc_html_e( 'Estado ANTES', 'taxocommerce-import-export' ); ?></h3>
                    <?php if ( $before ) : ?>
                        <p><?php echo esc_html( $before['date_utc'] . ' · v' . $before['plugin_version'] ); ?></p>
                        <p><?php echo esc_html( absint( $before['totals']['pass'] ) . ' correctas, ' . absint( $before['totals']['fail'] ) . ' errores' ); ?></p>
                        <?php self::export_button( true, 'Descargar ANTES (JSON)' ); ?>
                    <?php else : ?>
                        <p><?php esc_html_e( 'Sin referencia. Ejecútala antes de actualizar.', 'taxocommerce-import-export' ); ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <h3><?php esc_html_e( 'Último chequeo', 'taxocommerce-import-export' ); ?></h3>
                    <?php if ( $last ) : ?>
                        <p><?php echo esc_html( $last['date_utc'] . ' · v' . $last['plugin_version'] . ' · ' . $last['phase'] ); ?></p>
                        <?php self::export_button( false, 'Descargar último (JSON)' ); ?>
                    <?php else : ?>
                        <p><?php esc_html_e( 'No hay chequeo todavía.', 'taxocommerce-import-export' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="tc-ie-at-totals" aria-label="<?php esc_attr_e( 'Resumen del último chequeo', 'taxocommerce-import-export' ); ?>">
                <?php foreach ( array( 'pass' => 'Correctas', 'fail' => 'Errores', 'skip' => 'No verificadas', 'not_run' => 'Sin ejecutar' ) as $key => $label ) : ?>
                    <div><strong><?php echo esc_html( absint( $counts[ $key ] ?? 0 ) ); ?></strong><span><?php echo esc_html( $label ); ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php if ( $diff ) : ?>
                <div class="tc-ie-at-comparison">
                    <h3><?php esc_html_e( 'Comparación ANTES / DESPUÉS', 'taxocommerce-import-export' ); ?></h3>
                    <p><?php echo esc_html( 'Errores nuevos: ' . count( $diff['new_errors'] ) . ' · Resueltos: ' . count( $diff['resolved'] ) . ' · Previos: ' . count( $diff['existing_errors'] ) . ' · Cobertura perdida: ' . count( $diff['lost_coverage'] ) . ' · Nuevas pruebas: ' . count( $diff['new_checks'] ) ); ?></p>
                    <?php if ( $diff['new_errors'] || $diff['lost_coverage'] ) : ?>
                        <p class="tc-ie-at-alert"><?php echo esc_html( 'Revisar: ' . implode( ', ', array_unique( array_merge( $diff['new_errors'], $diff['lost_coverage'] ) ) ) ); ?></p>
                    <?php endif; ?>
                </div>
            <?php elseif ( $before && $last ) : ?>
                <p class="description"><?php esc_html_e( 'Para comparar necesitas una ejecución completa DESPUÉS, en la misma instalación.', 'taxocommerce-import-export' ); ?></p>
            <?php endif; ?>
            <?php foreach ( $groups as $id => $title ) : ?>
                <?php
                $subset = array_filter( $cases, static function ( $entry ) use ( $id ) { return $entry[0] === $id; } );
                $failed = 0;
                foreach ( $subset as $name => $entry ) {
                    if ( 'fail' === ( $indexed[ $name ]['status'] ?? '' ) ) { ++$failed; }
                }
                ?>
                <details class="tc-ie-at-group" <?php echo $failed || ! $last ? 'open' : ''; ?>>
                    <summary><?php echo esc_html( $title . ' (' . count( $subset ) . ')' . ( $failed ? ' · ' . $failed . ' errores' : '' ) ); ?></summary>
                    <div class="tc-ie-at-group-action"><?php self::run_button( $id, 'check', 'Comprobar este grupo' ); ?></div>
                    <?php foreach ( $subset as $name => $entry ) : ?>
                        <?php $row = $indexed[ $name ] ?? array( 'status' => 'not_run', 'message' => 'No ejecutado.' ); ?>
                        <div class="tc-ie-at-row">
                            <span class="tc-ie-at-dot tc-ie-at-dot--<?php echo esc_attr( $row['status'] ); ?>" aria-hidden="true"></span>
                            <div><strong><?php echo esc_html( $entry[1] ); ?></strong> <small><?php echo esc_html( $name ); ?></small>
                                <p><?php echo esc_html( $row['message'] ); ?></p></div>
                        </div>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
            <p class="description"><?php esc_html_e( 'Verde: contrato probado. Rojo: incidencia o despliegue inseguro. Gris: no verificable sin trabajo real. Un Auto Test correcto no garantiza que una importación, un ZIP o el cron funcionen en producción.', 'taxocommerce-import-export' ); ?></p>
        </section>
        <?php
    }
}

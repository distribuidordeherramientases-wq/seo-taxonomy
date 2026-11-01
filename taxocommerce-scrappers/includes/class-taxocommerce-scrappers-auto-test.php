<?php
/**
 * Read-only component availability checks for TaxoCommerce Scrappers.
 *
 * No HTTP calls, process execution, database writes or provider quota use.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Scrappers_Auto_Test {
    private static function add( &$results, $group, $name, $status, $detail ) {
        $results[] = array(
            'group'  => $group,
            'name'   => $name,
            'status' => $status,
            'detail' => $detail,
        );
    }

    private static function tables( $class, $names ) {
        global $wpdb;
        if ( ! class_exists( $class, false ) || ! method_exists( $class, 'table' ) ) {
            return array( 'status' => 'error', 'detail' => 'El gestor de tablas no está disponible.' );
        }
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) ) {
            return array( 'status' => 'error', 'detail' => 'No está disponible la conexión de base de datos de WordPress.' );
        }

        $missing = array();
        foreach ( $names as $name ) {
            $table = $class::table( $name );
            if ( ! is_string( $table ) || '' === $table ) {
                $missing[] = $name;
                continue;
            }
            // Read-only metadata query. Do not call maybe_install() from Autotest.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only schema inspection triggered by the administrator; freshness is required and no cache is appropriate.
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $found !== $table ) {
                $missing[] = $name;
            }
        }
        return empty( $missing )
            ? array( 'status' => 'ok', 'detail' => count( $names ) . ' tablas encontradas; sin escrituras.' )
            : array( 'status' => 'error', 'detail' => 'Faltan tablas: ' . implode( ', ', $missing ) . '. Revisar activación y migración.' );
    }

    public static function run() {
        $results = array();
        self::add(
            $results, 'Sistema', 'Versión y carga',
            defined( 'TAXOCOMMERCE_SCRAPPERS_VERSION' ) ? 'ok' : 'error',
            defined( 'TAXOCOMMERCE_SCRAPPERS_VERSION' ) ? 'Plugin cargado: ' . TAXOCOMMERCE_SCRAPPERS_VERSION : 'La constante de versión no está disponible.'
        );
        self::add(
            $results, 'Sistema', 'Cliente HTTP de WordPress',
            function_exists( 'wp_remote_get' ) && function_exists( 'wp_remote_post' ) ? 'ok' : 'error',
            'Se comprueba la API HTTP, sin realizar conexiones externas.'
        );
        self::add(
            $results, 'Sistema', 'WooCommerce y categorías de producto',
            taxonomy_exists( 'product_cat' ) ? 'ok' : 'warning',
            taxonomy_exists( 'product_cat' ) ? 'Taxonomía product_cat disponible.' : 'La taxonomía product_cat no está registrada; revisar WooCommerce.'
        );

        $ojeador_classes = array( 'SEO_Ojeador_Admin', 'SEO_Ojeador_Shopping', 'SEO_Ojeador_Worker', 'SEO_Ojeador_DB', 'SEO_Ojeador_Analysis', 'SEO_Ojeador_Merchant' );
        $missing = array_filter( $ojeador_classes, static function ( $class ) { return ! class_exists( $class, false ); } );
        self::add(
            $results, 'Ojeador', 'Componentes del servicio',
            empty( $missing ) ? 'ok' : 'error',
            empty( $missing ) ? 'Administración, consultas, proceso, base de datos y análisis disponibles.' : 'Faltan: ' . implode( ', ', $missing ) . '.'
        );
        $ojeador_tables = self::tables( 'SEO_Ojeador_DB', array( 'products', 'offers', 'categories', 'category_results', 'runs', 'query_log' ) );
        self::add( $results, 'Ojeador', 'Tablas de mercado', $ojeador_tables['status'], $ojeador_tables['detail'] );
        $cron_hook = class_exists( 'SEO_Ojeador_Worker', false ) && defined( 'SEO_Ojeador_Worker::CRON_HOOK' ) ? SEO_Ojeador_Worker::CRON_HOOK : '';
        $cron_ok   = '' !== $cron_hook && false !== has_action( $cron_hook, array( 'SEO_Ojeador_Worker', 'cron_tick' ) );
        self::add(
            $results, 'Ojeador', 'Registro del proceso programado',
            $cron_ok ? 'ok' : 'warning',
            $cron_ok ? 'El callback del worker está registrado; no se ejecuta.' : 'No se detecta el callback esperado para el worker.'
        );
        $ojeador_action = false !== has_action( 'admin_post_seo_ojeador_start', array( 'SEO_Ojeador_Admin', 'start' ) )
            && false !== has_action( 'admin_post_seo_ojeador_stop', array( 'SEO_Ojeador_Admin', 'stop' ) );
        self::add( $results, 'Ojeador', 'Controles de inicio y parada', $ojeador_action ? 'ok' : 'warning', 'Solo se comprueba que existen los handlers; no se inicia ni detiene ningún proceso.' );

        $api_available = class_exists( 'SEO_Ojeador_Shopping', false ) && method_exists( 'SEO_Ojeador_Shopping', 'settings' );
        self::add( $results, 'Conexiones', 'Gestión de proveedores', $api_available ? 'ok' : 'error', 'Disponibilidad de configuración compartida SerpApi y ScraperAPI.' );
        if ( $api_available ) {
            // settings() reads wp_options and optional constants; no provider requests.
            $settings = SEO_Ojeador_Shopping::settings();
            $has_serpapi = ! empty( $settings['api_key'] );
            $has_scraper = ! empty( $settings['scraperapi_key'] );
            self::add(
                $results, 'Conexiones', 'Credenciales configuradas',
                $has_serpapi || $has_scraper ? 'ok' : 'warning',
                ( $has_serpapi ? 'SerpApi configurada. ' : 'SerpApi sin configurar. ' )
                . ( $has_scraper ? 'ScraperAPI configurada.' : 'ScraperAPI sin configurar.' )
                . ' Nunca se muestran las claves ni se prueba la red.'
            );
            $valid_limit = isset( $settings['monthly_query_limit'] ) && absint( $settings['monthly_query_limit'] ) >= 1;
            self::add(
                $results, 'Conexiones', 'Límite local de peticiones SerpApi',
                $valid_limit ? 'ok' : 'warning',
                $valid_limit ? 'Existe un límite mensual de seguridad configurable.' : 'No se ha obtenido un límite mensual válido.'
            );
        }

        $ingeniero_classes = array( 'SEO_Ingeniero', 'SEO_Ingeniero_Admin', 'SEO_Ingeniero_DB', 'SEO_Ingeniero_Process', 'SEO_Ingeniero_Posts', 'SEO_Ingeniero_Tests' );
        $missing = array_filter( $ingeniero_classes, static function ( $class ) { return ! class_exists( $class, false ); } );
        self::add(
            $results, 'Ingeniero', 'Componentes del servicio',
            empty( $missing ) ? 'ok' : 'error',
            empty( $missing ) ? 'Investigación, persistencia, proceso, editorial y pruebas disponibles.' : 'Faltan: ' . implode( ', ', $missing ) . '.'
        );
        $ingeniero_tables = self::tables( 'SEO_Ingeniero_DB', array( 'sources', 'knowledge', 'editorial' ) );
        self::add( $results, 'Ingeniero', 'Tablas de conocimiento', $ingeniero_tables['status'], $ingeniero_tables['detail'] );
        $ingeniero_admin = false !== has_action( 'admin_post_seo_ingeniero_prepare', array( 'SEO_Ingeniero_Admin', 'handle_prepare' ) );
        self::add( $results, 'Ingeniero', 'Control administrativo', $ingeniero_admin ? 'ok' : 'warning', 'Se valida el handler de preparación, sin preparar ni modificar categorías.' );
        $ingeniero_cron = class_exists( 'SEO_Ingeniero_Process', false )
            && defined( 'SEO_Ingeniero_Process::EDITORIAL_REFRESH_HOOK' )
            && false !== has_action( SEO_Ingeniero_Process::EDITORIAL_REFRESH_HOOK, array( 'SEO_Ingeniero_Process', 'editorial_refresh' ) );
        self::add(
            $results, 'Ingeniero', 'Actualización editorial programada',
            $ingeniero_cron ? 'ok' : 'warning',
            $ingeniero_cron ? 'El callback editorial está registrado; no se ejecuta.' : 'No se detecta el callback de actualización editorial.'
        );
        $native_tests = class_exists( 'SEO_Ingeniero_Tests', false ) && method_exists( 'SEO_Ingeniero_Tests', 'run' );
        self::add(
            $results, 'Ingeniero', 'Pruebas editoriales existentes',
            $native_tests ? 'ok' : 'warning',
            $native_tests ? 'La batería editorial está disponible en Ingeniero > Pruebas; no se ejecuta en este autotest.' : 'No se ha encontrado la batería editorial.'
        );

        return $results;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para ejecutar el autotest.', 'taxocommerce-scrappers' ) );
        }
        echo '<div class="wrap taxocommerce-scrappers-autotest">';
        echo '<h2>Autotest de Scrappers</h2>';
        echo '<p>Comprueba disponibilidad de componentes, conexiones y tablas. No realiza búsquedas, no consume cuotas, no inicia procesos y no modifica datos. Una credencial sin configurar es un aviso de preparación, no un fallo del programa.</p>';
        echo '<form method="post">';
        wp_nonce_field( 'taxocommerce_scrappers_autotest' );
        echo '<input type="hidden" name="tcs_run_autotest" value="1">';
        submit_button( 'Ejecutar autotest', 'primary', 'submit', false );
        echo '</form>';

        // Intentional POST: read-only diagnostics run only after explicit admin action.
        if ( ! isset( $_POST['tcs_run_autotest'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified below before running checks.
            echo '<p>El autotest aún no se ha ejecutado en esta visita.</p></div>';
            return;
        }
        check_admin_referer( 'taxocommerce_scrappers_autotest' );

        $checks = self::run();
        $counts = array( 'ok' => 0, 'warning' => 0, 'error' => 0 );
        foreach ( $checks as $check ) {
            if ( isset( $counts[ $check['status'] ] ) ) {
                $counts[ $check['status'] ]++;
            }
        }
        echo '<h3>Resultado</h3><p><strong>' . esc_html( (string) count( $checks ) ) . ' comprobaciones:</strong> '
            . esc_html( (string) $counts['ok'] ) . ' correctas, '
            . esc_html( (string) $counts['warning'] ) . ' avisos, '
            . esc_html( (string) $counts['error'] ) . ' errores.</p>';
        $group = '';
        foreach ( $checks as $check ) {
            if ( $check['group'] !== $group ) {
                if ( '' !== $group ) {
                    echo '</tbody></table>';
                }
                $group = $check['group'];
                echo '<h3>' . esc_html( $group ) . '</h3>';
                echo '<table class="widefat striped"><thead><tr><th scope="col">Comprobación</th><th scope="col">Estado</th><th scope="col">Detalle</th></tr></thead><tbody>';
            }
            $states = array( 'ok' => 'Correcto', 'warning' => 'Aviso', 'error' => 'Error' );
            echo '<tr><th scope="row">' . esc_html( $check['name'] ) . '</th><td><strong>' . esc_html( $states[ $check['status'] ] ) . '</strong></td><td>' . esc_html( $check['detail'] ) . '</td></tr>';
        }
        if ( '' !== $group ) {
            echo '</tbody></table>';
        }
        echo '<p class="description">El resultado es puntual, no se guarda. La conexión real de proveedores se prueba por separado desde Connections y puede consumir recursos del servicio externo.</p></div>';
    }
}

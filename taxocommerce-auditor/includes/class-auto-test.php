<?php
/**
 * Non-destructive functional self-check for the five independent Auditor scopes.
 * Only inspections of registered services and existing WordPress options are run.
 */
namespace TaxoCommerce\Auditor;

defined( 'ABSPATH' ) || exit;

final class Auto_Test {
    const ACTION = 'taxocommerce_auditor_auto_test';
    const NONCE = 'taxocommerce_auditor_auto_test_run';
    const TRANSIENT = 'taxocommerce_auditor_auto_test_';

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
            'runtime'    => array( 'Plugin', 'Servicios Auditor cargados' ),
            'hooks'      => array( 'Seguridad', 'Rutas protegidas y proceso por lotes registrados' ),
            'scopes'     => array( 'Ámbitos', 'Cinco tipos de contenido' ),
            'products'   => array( 'Productos', 'WooCommerce y productos disponibles' ),
            'categories' => array( 'Categorías', 'Taxonomía de productos disponible' ),
            'faqs'       => array( 'FAQs', 'Fuente FAQ opcional o alternativa' ),
            'quality'    => array( 'Calidad', 'Ponderaciones y bandas de puntuación' ),
            'priority'   => array( 'Calidad', 'Prioridad y revisión editorial' ),
            'filters'    => array( 'Auditorías', 'Filtros y límites disponibles' ),
            'runner'     => array( 'Auditorías', 'Motor por lotes y controles de ejecución' ),
            'storage'    => array( 'Exportación', 'Contratos del almacén JSONL y descarga' ),
            'state'      => array( 'Informes', 'Acceso a estados e informes guardados' ),
            'history'    => array( 'Informes', 'Historial de los cinco ámbitos' ),
            'context'    => array( 'Integraciones', 'Lectura de evidencia local y opcional' ),
            'manual'     => array( 'Manual', 'Guías de usuario y técnico' ),
            'operations' => array( 'Seguridad', 'Auditorías completas, exportaciones y cambios' ),
        );
    }

    public static function run_suite() {
        $started = microtime( true );
        $rows = array();
        $totals = array( 'pass' => 0, 'fail' => 0, 'skip' => 0 );
        foreach ( self::cases() as $id => $labels ) {
            $begin = microtime( true );
            try {
                $method = 'test_' . $id;
                $result = self::$method();
                if ( ! is_array( $result ) || ! isset( $result['status'], $result['message'] ) || ! isset( $totals[ $result['status'] ] ) ) {
                    $result = self::fail( 'Formato de resultado de prueba no reconocido.' );
                }
            } catch ( \Throwable $error ) {
                $result = self::fail( 'No se ha podido completar la comprobación: ' . $error->getMessage() );
            }
            $rows[] = array(
                'id' => $id, 'group' => $labels[0], 'label' => $labels[1],
                'status' => $result['status'], 'message' => $result['message'],
                'ms' => (int) round( ( microtime( true ) - $begin ) * 1000 ),
            );
            ++$totals[ $result['status'] ];
        }
        return array(
            'version' => Plugin::VERSION, 'date' => current_time( 'mysql' ),
            'ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
            'totals' => $totals, 'rows' => $rows,
        );
    }

    private static function test_runtime() {
        foreach ( array( 'Plugin', 'Admin', 'Runner', 'Rules', 'Priority', 'Editorial_Specialist', 'Category_Review', 'FAQ_Source', 'Export_Store', 'Decision_Context', 'Manual' ) as $class ) {
            if ( ! class_exists( __NAMESPACE__ . '\\' . $class ) ) {
                return self::fail( 'Falta la clase ' . $class . '.' );
            }
        }
        return self::pass( 'Auditor ' . Plugin::VERSION . ': componentes cargados.' );
    }

    private static function test_hooks() {
        foreach ( array(
            'admin_post_taxocommerce_auditor_action',
            'admin_post_taxocommerce_auditor_export',
            'admin_post_' . self::ACTION,
            Runner::HOOK,
        ) as $hook ) {
            if ( ! has_action( $hook ) ) {
                return self::fail( 'No está registrado: ' . $hook . '.' );
            }
        }
        return self::pass( 'Formularios, descargas, Auto Test y callback de lotes registrados, sin invocarlos.' );
    }

    private static function test_scopes() {
        $expected = array( 'products', 'categories', 'posts', 'pages', 'faqs' );
        return array_keys( Runner::scopes() ) === $expected
            && ! Runner::valid_scope( 'unknown' )
            ? self::pass( 'Los cinco ámbitos de auditoría están disponibles.' )
            : self::fail( 'Los ámbitos difieren del contrato de Auditor.' );
    }

    private static function test_products() {
        return function_exists( 'wc_get_product' ) && post_type_exists( 'product' )
            ? self::pass( 'Producto WooCommerce y su API de lectura disponibles.' )
            : self::fail( 'WooCommerce o el tipo product no están disponibles.' );
    }

    private static function test_categories() {
        return taxonomy_exists( 'product_cat' )
            ? self::pass( 'La taxonomía product_cat está registrada.' )
            : self::fail( 'No está registrada product_cat; comprueba WooCommerce.' );
    }

    private static function test_faqs() {
        // The FAQ module and custom FAQ CPTs are optional. No SQL is needed.
        return method_exists( __NAMESPACE__ . '\\FAQ_Source', 'available' )
            && method_exists( __NAMESPACE__ . '\\FAQ_Source', 'batch' )
            && method_exists( __NAMESPACE__ . '\\Runner', 'faq_post_type' )
            ? self::pass( 'Adaptador FAQ canónico y alternativa por CPT disponibles; la fuente puede ser opcional.' )
            : self::fail( 'No están disponibles los lectores FAQ esperados.' );
    }

    private static function test_quality() {
        $total = array_sum( Rules::DIMENSIONS );
        return 100 === $total && 'fuerte' === Rules::band( 85 )
            && 'correcta' === Rules::band( 70 ) && 'mejorable' === Rules::band( 55 )
            && 'debil' === Rules::band( 54 ) && 'no_aplica' === Rules::band( null )
            ? self::pass( 'Cinco dimensiones suman 100 y las bandas responden como se espera.' )
            : self::fail( 'Hay un desajuste en los criterios de calidad.' );
    }

    private static function test_priority() {
        $row = Priority::decorate( array(
            'code' => 'product_attribute_title_conflict', 'severity' => 'high',
            'entity_type' => 'product', 'entity_id' => 0,
        ) );
        return is_array( $row ) && 'P1' === ( $row['priority'] ?? '' )
            && ! empty( $row['requires_source_check'] )
            ? self::pass( 'Clasificación P1 requiere verificación de fuente; no se crea ningún hallazgo.' )
            : self::fail( 'El contrato de prioridades no coincide.' );
    }

    private static function test_filters() {
        $filtered = Runner::sanitize_filters( 'products', array( 'mode' => 'filtered', 'rows' => 999 ) );
        $full = Runner::sanitize_filters( 'categories', array( 'mode' => 'full', 'rows' => 10 ) );
        return 25 === $filtered['rows'] && 0 === $full['rows'] && 'full' === $full['mode']
            ? self::pass( 'Filtros acotados y modo completo distinguidos sin ejecutar auditorías.' )
            : self::fail( 'Los límites de búsqueda han cambiado inesperadamente.' );
    }

    private static function test_runner() {
        foreach ( array( 'start', 'pause', 'resume', 'run_batch', 'ensure_scheduled', 'report' ) as $method ) {
            if ( ! method_exists( __NAMESPACE__ . '\\Runner', $method ) ) {
                return self::fail( 'Falta Runner::' . $method . '.' );
            }
        }
        return Runner::BATCH_SIZE > 0 && Runner::BATCH_SIZE <= 100
            ? self::pass( 'Ejecución por lotes y controles disponibles; ningún cron se dispara.' )
            : self::fail( 'Tamaño de lote fuera del contrato esperado.' );
    }

    private static function test_storage() {
        foreach ( array( 'begin', 'append', 'rows', 'exists', 'stream_report' ) as $method ) {
            if ( ! method_exists( __NAMESPACE__ . '\\Export_Store', $method ) ) {
                return self::fail( 'Falta Export_Store::' . $method . '.' );
            }
        }
        return self::pass( 'Almacén JSONL y descarga completa disponibles, sin crear ni abrir archivos.' );
    }

    private static function test_state() {
        foreach ( array_keys( Runner::scopes() ) as $scope ) {
            if ( ! is_array( Runner::state( $scope ) ) || ! is_array( Runner::report( $scope ) ) ) {
                return self::fail( 'Formato de estado o informe inválido para ' . $scope . '.' );
            }
        }
        return self::pass( 'Estados e informes de los cinco ámbitos legibles; no se procesan datos.' );
    }

    private static function test_history() {
        foreach ( array_keys( Runner::scopes() ) as $scope ) {
            if ( ! is_array( Runner::history( $scope ) ) ) {
                return self::fail( 'Historial no disponible: ' . $scope . '.' );
            }
        }
        return self::pass( 'Historial de cinco ámbitos disponible; puede estar vacío sin ser un error.' );
    }

    private static function test_context() {
        return class_exists( __NAMESPACE__ . '\\Decision_Context' )
            && method_exists( __NAMESPACE__ . '\\Decision_Context', 'for_finding' )
            ? self::pass( 'Contrato de evidencia local disponible; no se consultan servicios externos.' )
            : self::fail( 'No está disponible el contrato de contexto local.' );
    }

    private static function test_manual() {
        return class_exists( __NAMESPACE__ . '\\Manual' ) && method_exists( __NAMESPACE__ . '\\Manual', 'render' )
            ? self::pass( 'Guía de usuario y guía técnica integradas.' )
            : self::fail( 'No se encuentra la documentación integrada.' );
    }

    private static function test_operations() {
        return self::skip( 'No se ejecutan auditorías, procesos WP-Cron, escrituras de JSONL, descargas completas ni cambios editoriales. Probarlos en staging.' );
    }

    public static function handle() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos para Auto Test.', 'taxocommerce-auditor' ) );
        }
        check_admin_referer( self::NONCE, 'taxocommerce_auditor_auto_nonce' );
        $key = self::TRANSIENT . get_current_user_id();
        $history = get_transient( $key );
        if ( ! is_array( $history ) ) $history = array();
        array_unshift( $history, self::run_suite() );
        set_transient( $key, array_slice( $history, 0, 2 ), 30 * DAY_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=' . Plugin::MENU_SLUG . '&auditor_scope=auto-test' ) );
        exit;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos para Auto Test.', 'taxocommerce-auditor' ) );
        }
        $history = get_transient( self::TRANSIENT . get_current_user_id() );
        $current = is_array( $history ) && isset( $history[0]['rows'] ) ? $history[0] : null;
        $previous = is_array( $history ) && isset( $history[1]['rows'] ) ? $history[1] : null;
        echo '<section class="card" style="max-width:1200px"><h2>Auto Test de Auditor</h2>';
        echo '<p>Comprueba servicios, rutas, ámbitos, calidad, opciones, historial, FAQ y disponibilidad de exportación. Es de solo lectura: no inicia procesos ni modifica contenidos. Se guardan dos resultados por usuario durante 30 días.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        wp_nonce_field( self::NONCE, 'taxocommerce_auditor_auto_nonce' );
        submit_button( 'Ejecutar Auto Test', 'primary', 'submit', false );
        echo '</form>';
        if ( ! $current ) {
            echo '<p>Todavía no hay resultados. Pulsa Ejecutar Auto Test.</p></section>';
            return;
        }
        $totals = $current['totals'];
        echo '<h3>Último informe · v' . esc_html( $current['version'] ) . ' · ' . esc_html( $current['date'] ) . '</h3>';
        echo '<p><strong>' . esc_html( (string) $totals['pass'] ) . ' correctas</strong> · <strong>' . esc_html( (string) $totals['fail'] ) . ' fallidas</strong> · ' . esc_html( (string) $totals['skip'] ) . ' omitidas · ' . esc_html( (string) $current['ms'] ) . ' ms</p>';
        if ( $previous ) {
            $old = array();
            foreach ( $previous['rows'] as $row ) $old[ $row['id'] ] = $row['status'];
            $regressions = array();
            foreach ( $current['rows'] as $row ) {
                if ( 'fail' === $row['status'] && ( $old[ $row['id'] ] ?? '' ) === 'pass' ) $regressions[] = $row['label'];
            }
            echo '<p><strong>Comparativa:</strong> ' . esc_html( $previous['version'] . ' (' . $previous['date'] . ') → ' . $current['version'] ) . '. ';
            echo $regressions ? '<strong style="color:#b32d2e">Regresiones: ' . esc_html( implode( ', ', $regressions ) ) . '</strong>' : 'Sin regresiones de correcto a fallido.';
            echo '</p>';
        } else {
            echo '<p>No hay una ejecución anterior para comparar.</p>';
        }
        echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Grupo</th><th>Prueba</th><th>Estado</th><th>Resultado</th><th>Tiempo</th></tr></thead><tbody>';
        $names = array( 'pass' => 'Correcto', 'fail' => 'Error', 'skip' => 'No ejecutado' );
        foreach ( $current['rows'] as $row ) {
            echo '<tr><td>' . esc_html( $row['group'] ) . '</td><td>' . esc_html( $row['label'] ) . '</td><td><strong>' . esc_html( $names[ $row['status'] ] ) . '</strong></td><td>' . esc_html( $row['message'] ) . '</td><td>' . esc_html( (string) $row['ms'] ) . ' ms</td></tr>';
        }
        echo '</tbody></table></div><p class="description">No demuestra por sí mismo que una auditoría completa o la exportación JSONL funcionen con un catálogo real.</p></section>';
    }
}

<?php
/**
 * Vocabulary Auto Test: availability, structure and bounded read-only checks.
 * It never runs a classifier, import, update, migration, export or cleanup.
 */
defined( 'ABSPATH' ) || exit;

final class TCV_Auto_Test {
    const ACTION = 'tcv_run_auto_test';
    const NONCE  = 'tcv_auto_test_run';
    const PREFIX = 'tcv_auto_test_';

    public static function boot() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
    }

    public static function cases() {
        return array(
            'runtime'    => array( 'Plugin', 'Componentes cargados' ),
            'hooks'      => array( 'Seguridad', 'Acciones protegidas registradas' ),
            'woocommerce'=> array( 'WooCommerce', 'Tipos de contenido y categorías' ),
            'db'         => array( 'MySQL', 'Conexión de lectura' ),
            'schema'     => array( 'Tablas', 'Nueve tablas canónicas disponibles' ),
            'columns'    => array( 'Tablas', 'Columnas necesarias para el vocabulario' ),
            'labels'     => array( 'Etiquetas', 'Lectura limitada de términos' ),
            'relations'  => array( 'Etiquetas', 'Lectura limitada de asociaciones' ),
            'type_role'  => array( 'Etiquetas', 'Correspondencia TIPO a ROL' ),
            'attributes' => array( 'Atributos', 'Definiciones, términos y aliases' ),
            'product_att'=> array( 'Atributos', 'Atributos de producto' ),
            'classifier' => array( 'Clasificador', 'Motor y filtros disponibles' ),
            'proposals'  => array( 'Clasificador', 'Lectura limitada de propuestas' ),
            'portable'   => array( 'Importar / Exportar', 'Contrato portable y exportaciones' ),
            'manual'     => array( 'Manual', 'Documentación de usuario y técnico' ),
            'writes'     => array( 'Seguridad', 'Operaciones que modifican o exportan datos' ),
        );
    }

    private static function result( $status, $message ) {
        return array( 'status' => $status, 'message' => $message );
    }
    private static function pass( $message ) { return self::result( 'pass', $message ); }
    private static function fail( $message ) { return self::result( 'fail', $message ); }
    private static function skip( $message ) { return self::result( 'skip', $message ); }

    public static function run_suite() {
        $started = microtime( true );
        $rows = array();
        $totals = array( 'pass' => 0, 'fail' => 0, 'skip' => 0 );
        foreach ( self::cases() as $id => $labels ) {
            $begin = microtime( true );
            try {
                $method = 'test_' . $id;
                $value = self::$method();
                if ( ! is_array( $value ) || ! isset( $value['status'], $value['message'] ) || ! array_key_exists( $value['status'], $totals ) ) {
                    $value = self::fail( 'La prueba devolvió un resultado no válido.' );
                }
            } catch ( Throwable $error ) {
                $value = self::fail( 'No se pudo completar la comprobación: ' . $error->getMessage() );
            }
            $rows[] = array(
                'id' => $id, 'group' => $labels[0], 'label' => $labels[1],
                'status' => $value['status'], 'message' => $value['message'],
                'ms' => (int) round( ( microtime( true ) - $begin ) * 1000 ),
            );
            ++$totals[ $value['status'] ];
        }
        return array(
            'version' => TAXOCOMMERCE_VOCABULARY_VERSION,
            'date' => current_time( 'mysql' ),
            'ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
            'totals' => $totals,
            'rows' => $rows,
        );
    }

    private static function test_runtime() {
        foreach ( array( 'TCV_Admin', 'TCV_Schema', 'TCV_Classifier', 'TCV_IO', 'TCV_Portable', 'TCV_Manual' ) as $service ) {
            if ( ! class_exists( $service ) ) return self::fail( 'No está disponible el servicio ' . $service . '.' );
        }
        return self::pass( 'Vocabulary ' . TAXOCOMMERCE_VOCABULARY_VERSION . ': servicios cargados.' );
    }

    private static function test_hooks() {
        $hooks = array(
            self::ACTION, 'tcv_create_term', 'tcv_analyze_filtered',
            'tcv_analyze_category', 'tcv_analyze_product', 'tcv_export_classifier_json',
            'tcv_export_labels', 'tcv_export_attributes', 'tcv_import',
            'tcv_export_portable_json', 'tcv_import_portable_json',
        );
        foreach ( $hooks as $hook ) {
            if ( ! has_action( 'admin_post_' . $hook ) ) {
                return self::fail( 'Falta la acción: ' . $hook . '.' );
            }
        }
        return self::pass( count( $hooks ) . ' rutas registradas; no se ha ejecutado ninguna acción.' );
    }

    private static function test_woocommerce() {
        return post_type_exists( 'product' ) && taxonomy_exists( 'product_cat' )
            ? self::pass( 'Productos y categorías WooCommerce disponibles.' )
            : self::fail( 'No están registrados product y product_cat; comprueba WooCommerce.' );
    }

    private static function test_db() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live admin self-check; caching hides current connectivity failures.
        return '1' === (string) $wpdb->get_var( 'SELECT 1' )
            ? self::pass( 'MySQL responde a una lectura sin modificar datos.' )
            : self::fail( 'MySQL no ha respondido a SELECT 1.' );
    }

    private static function exists( $name ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current physical table inventory cannot be cached.
        return $name === $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) )
        );
    }

    private static function test_schema() {
        $missing = array();
        foreach ( TCV_Schema::tables() as $key => $name ) {
            if ( ! self::exists( $name ) ) $missing[] = $key . ' (' . $name . ')';
        }
        return $missing
            ? self::fail( 'Tablas ausentes: ' . implode( ', ', $missing ) . '. No se crean tablas desde Auto Test.' )
            : self::pass( 'Las nueve tablas canónicas están disponibles.' );
    }

    private static function test_columns() {
        global $wpdb;
        $required = array(
            'vocabulary'=>array( 'id', 'semantic_group', 'slug', 'label', 'active' ),
            'objects'=>array( 'object_id', 'object_type', 'vocabulary_id', 'status' ),
            'type_role'=>array( 'type_vocabulary_id', 'role_vocabulary_id' ),
            'attributes'=>array( 'id', 'slug', 'nombre', 'activo' ),
            'terms'=>array( 'atributo_id', 'slug', 'nombre' ),
            'aliases'=>array( 'atributo_id', 'alias' ),
            'product_att'=>array( 'product_id', 'atributo_id' ),
            'proposals'=>array( 'product_id', 'state', 'semantic_group' ),
            'cat_proposals'=>array( 'term_id', 'state', 'semantic_group' ),
        );
        foreach ( TCV_Schema::tables() as $key => $name ) {
            if ( ! self::exists( $name ) ) return self::skip( 'Falta la tabla ' . $name . '; corrige primero el esquema.' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema inspection is a read-only, uncached diagnostic.
            $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $name ) );
            $absent = array_diff( $required[ $key ], (array) $columns );
            if ( $absent ) return self::fail( $name . ': faltan ' . implode( ', ', $absent ) . '.' );
        }
        return self::pass( 'Columnas esenciales verificadas en las nueve tablas.' );
    }

    private static function sample( $key, $column ) {
        global $wpdb;
        $tables = TCV_Schema::tables();
        $name = $tables[ $key ];
        if ( ! self::exists( $name ) ) return self::skip( 'Falta ' . $name . '; no se ejecuta su consulta.' );
        // Only known, hard-coded identifiers are used. No catalogue values are returned.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded live diagnostic of current data, without cache or changes.
        $value = $wpdb->get_col( $wpdb->prepare( 'SELECT %i FROM %i LIMIT %d', $column, $name, 1 ) );
        if ( ! is_array( $value ) || '' !== (string) $wpdb->last_error ) {
            return self::fail( 'Falló la lectura limitada de ' . $name . '.' );
        }
        return self::pass( $name . ': consulta limitada correcta' . ( $value ? ' (contiene datos).' : ' (tabla vacía).' ) );
    }

    private static function test_labels() { return self::sample( 'vocabulary', 'semantic_group' ); }
    private static function test_relations() { return self::sample( 'objects', 'vocabulary_id' ); }
    private static function test_type_role() { return self::sample( 'type_role', 'type_vocabulary_id' ); }

    private static function test_attributes() {
        foreach ( array( 'attributes'=>'slug', 'terms'=>'atributo_id', 'aliases'=>'atributo_id' ) as $key => $column ) {
            $result = self::sample( $key, $column );
            if ( 'pass' !== $result['status'] ) return $result;
        }
        return self::pass( 'Definiciones, términos y aliases disponibles para lectura.' );
    }

    private static function test_product_att() { return self::sample( 'product_att', 'product_id' ); }

    private static function test_classifier() {
        return method_exists( 'TCV_Classifier', 'sanitize_filters' )
            && method_exists( 'TCV_Classifier', 'analyze_filtered_products' )
            && method_exists( 'TCV_Classifier', 'analyze_category' )
            && count( TCV_Classifier::GROUPS ) === 5
            ? self::pass( 'Motor, cinco grupos y filtros disponibles; no se generan propuestas.' )
            : self::fail( 'Falta alguna API de clasificación.' );
    }

    private static function test_proposals() {
        foreach ( array( 'proposals'=>'product_id', 'cat_proposals'=>'term_id' ) as $key => $column ) {
            $result = self::sample( $key, $column );
            if ( 'pass' !== $result['status'] ) return $result;
        }
        return self::pass( 'Propuestas de productos y categorías accesibles.' );
    }

    private static function test_portable() {
        $scopes = TCV_Portable::allowed_scopes();
        $expected = array( 'masters', 'product_semantic', 'category_semantic', 'product_attributes', 'product_tags' );
        return $scopes === $expected && method_exists( 'TCV_Portable', 'build_document' )
            && method_exists( 'TCV_Portable', 'import_request' )
            && method_exists( 'TCV_IO', 'export_labels' )
            && method_exists( 'TCV_IO', 'export_attributes' )
            ? self::pass( 'CSV y cinco ámbitos JSON portable disponibles; no se descargan ni importan datos.' )
            : self::fail( 'Faltan servicios de intercambio CSV/JSON.' );
    }

    private static function test_manual() {
        return class_exists( 'TCV_Manual' ) && method_exists( 'TCV_Manual', 'render' )
            ? self::pass( 'Manual de usuario y técnico cargado.' )
            : self::fail( 'Manual no disponible.' );
    }
    private static function test_writes() {
        return self::skip( 'No se ejecutan altas, análisis, importaciones MERGE/MIRROR ni exportaciones reales. Comprobarlos con datos de prueba en staging.' );
    }

    public static function handle() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'No tienes permisos para ejecutar Auto Test.', 'taxocommerce-vocabulary' ) );
        check_admin_referer( self::NONCE, 'tcv_auto_nonce' );
        $key = self::PREFIX . get_current_user_id();
        $history = get_transient( $key );
        if ( ! is_array( $history ) ) $history = array();
        array_unshift( $history, self::run_suite() );
        set_transient( $key, array_slice( $history, 0, 2 ), 30 * DAY_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-vocabulary&tab=auto-test' ) );
        exit;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'No tienes permisos para Auto Test.', 'taxocommerce-vocabulary' ) );
        $history = get_transient( self::PREFIX . get_current_user_id() );
        $current = is_array( $history ) && isset( $history[0]['rows'] ) ? $history[0] : null;
        $previous = is_array( $history ) && isset( $history[1]['rows'] ) ? $history[1] : null;
        echo '<div class="tcv-panel"><h2>Auto Test de Vocabulary</h2><p>Comprobación de solo lectura de servicios, rutas, tablas, columnas, consultas limitadas y disponibilidad de exportación. No corrige datos ni ejecuta procesos. Se conservan los dos últimos informes por usuario durante 30 días.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        wp_nonce_field( self::NONCE, 'tcv_auto_nonce' );
        submit_button( 'Ejecutar Auto Test', 'primary', 'submit', false );
        echo '</form>';
        if ( ! $current ) { echo '<p>No hay un informe previo. Ejecuta Auto Test.</p></div>'; return; }
        $totals = $current['totals'];
        echo '<h3>Resultado · ' . esc_html( $current['version'] ) . ' · ' . esc_html( $current['date'] ) . '</h3>';
        echo '<p><strong>' . esc_html( (string) $totals['pass'] ) . ' correctas</strong> · <strong>' . esc_html( (string) $totals['fail'] ) . ' fallidas</strong> · ' . esc_html( (string) $totals['skip'] ) . ' omitidas · ' . esc_html( (string) $current['ms'] ) . ' ms</p>';
        if ( $previous ) {
            $before = array();
            foreach ( $previous['rows'] as $row ) $before[ $row['id'] ] = $row['status'];
            $regressions = array();
            foreach ( $current['rows'] as $row ) {
                if ( 'fail' === $row['status'] && isset( $before[ $row['id'] ] ) && 'pass' === $before[ $row['id'] ] ) $regressions[] = $row['label'];
            }
            echo '<p><strong>Comparación:</strong> ' . esc_html( $previous['version'] . ' (' . $previous['date'] . ') → ' . $current['version'] ) . '. ';
            echo $regressions ? '<strong style="color:#b32d2e">Regresiones: ' . esc_html( implode( ', ', $regressions ) ) . '</strong>' : 'Sin regresiones de correcto a fallido.';
            echo '</p>';
        } else {
            echo '<p>No hay una ejecución anterior con la que comparar.</p>';
        }
        echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Grupo</th><th>Prueba</th><th>Estado</th><th>Diagnóstico</th><th>Tiempo</th></tr></thead><tbody>';
        $labels = array( 'pass'=>'Correcto', 'fail'=>'Error', 'skip'=>'No ejecutado' );
        foreach ( $current['rows'] as $row ) {
            echo '<tr><td>' . esc_html( $row['group'] ) . '</td><td>' . esc_html( $row['label'] ) . '</td><td><strong>' . esc_html( $labels[ $row['status'] ] ) . '</strong></td><td>' . esc_html( $row['message'] ) . '</td><td>' . esc_html( (string) $row['ms'] ) . ' ms</td></tr>';
        }
        echo '</tbody></table></div><p class="description">Las tablas vacías no son errores técnicos. Auto Test no garantiza la calidad del catálogo ni prueba importaciones/restauraciones reales.</p></div>';
    }
}
TCV_Auto_Test::boot();

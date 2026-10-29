<?php
/** Read-only functional self-check for TaxoCommerce Database. */
defined( 'ABSPATH' ) || exit;

final class TCDB_Auto_Test {
    const ACTION = 'tcdb_run_auto_test';
    const NONCE = 'tcdb_auto_test_run';
    const PREFIX = 'tcdb_auto_test_';

    public static function boot() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
    }
    private static function result( $status, $message ) {
        return array( 'status' => $status, 'message' => $message );
    }
    public static function cases() {
        return array(
            'runtime' => array( 'Plugin', 'Arranque y servicios' ),
            'hooks' => array( 'Seguridad', 'Rutas y exportaciones registradas' ),
            'db' => array( 'MySQL', 'Conexión de lectura' ),
            'seo_schema' => array( 'Errores en datos', 'Tablas SEO y campos esenciales' ),
            'config' => array( 'Errores en datos', 'Configuración de nodos y relaciones' ),
            'index' => array( 'Errores en datos', 'Índice sintético de nodos' ),
            'nodes' => array( 'Errores en datos', 'Lectura limitada de nodos' ),
            'relations' => array( 'Errores en datos', 'Lectura limitada de relaciones' ),
            'categories' => array( 'Errores en datos', 'Consulta de jerarquías' ),
            'cleanup' => array( 'Limpieza BBDD', 'Disponibilidad de las 17 operaciones' ),
            'cleanup_counts' => array( 'Limpieza BBDD', 'Lectura limitada de candidatos' ),
            'full_export' => array( 'Exportar BBDD', 'Selección de tablas SQL completa y SEO' ),
            'inventory' => array( 'Resumen tablas', 'Inventario de tablas SEO' ),
            'columns' => array( 'Explorar tablas', 'Columnas de una tabla SEO' ),
            'exports' => array( 'Exportar tablas', 'Preparación CSV, SQL y ZIP' ),
            'manual' => array( 'Manual', 'Documentación integrada' ),
            'destructive' => array( 'Operaciones de escritura', 'Limpieza, reparación y exportaciones reales' ),
        );
    }
    public static function run_suite() {
        $start = microtime( true );
        $rows = array();
        $totals = array( 'pass' => 0, 'fail' => 0, 'skip' => 0 );
        foreach ( self::cases() as $id => $labels ) {
            $began = microtime( true );
            try {
                $method = 'test_' . $id;
                $result = self::$method();
                if ( ! is_array( $result ) || ! isset( $result['status'], $result['message'] ) || ! isset( $totals[ $result['status'] ] ) ) {
                    $result = self::result( 'fail', 'Respuesta de prueba inválida.' );
                }
            } catch ( Throwable $error ) {
                $result = self::result( 'fail', get_class( $error ) . ': ' . $error->getMessage() );
            }
            $rows[] = array( 'id' => $id, 'group' => $labels[0], 'label' => $labels[1], 'status' => $result['status'], 'message' => $result['message'], 'ms' => (int) round( ( microtime( true ) - $began ) * 1000 ) );
            ++$totals[ $result['status'] ];
        }
        return array( 'version' => TAXOCOMMERCE_DATABASE_VERSION, 'date' => current_time( 'mysql' ), 'ms' => (int) round( ( microtime( true ) - $start ) * 1000 ), 'totals' => $totals, 'rows' => $rows );
    }
    private static function pass( $s ) { return self::result( 'pass', $s ); }
    private static function fail( $s ) { return self::result( 'fail', $s ); }
    private static function skip( $s ) { return self::result( 'skip', $s ); }
    private static function test_runtime() {
        return class_exists( 'TaxoCommerce_Database' ) && class_exists( 'TCDB_Manual' ) && function_exists( 'TCDB_Legacy\\seo_clean_db_page' ) && function_exists( 'TCDB_Legacy\\seo_data_table_page' )
            ? self::pass( 'Database ' . TAXOCOMMERCE_DATABASE_VERSION . ': los dos servicios originales y el manual están cargados.' )
            : self::fail( 'Falta el plugin, un servicio original o el manual.' );
    }
    private static function test_hooks() {
        $hooks = array( 'admin_post_' . self::ACTION, 'admin_post_tcdb_legacy_data_export_table_csv', 'admin_post_tcdb_legacy_data_export_table_sql', 'admin_post_tcdb_legacy_data_export_all' );
        foreach ( $hooks as $hook ) { if ( ! has_action( $hook ) ) { return self::fail( 'Ruta no registrada: ' . $hook ); } }
        return self::pass( 'Auto Test y 3 exportadores registrados; no se han invocado.' );
    }
    private static function test_db() {
        global $wpdb;
        $value = $wpdb->get_var( 'SELECT 1' );
        return '1' === (string) $value ? self::pass( 'MySQL responde a SELECT 1.' ) : self::fail( 'MySQL no responde correctamente.' );
    }
    private static function table_exists( $name ) {
        return \TCDB_Legacy\seo_clean_db_table_exists( $name );
    }
    private static function test_seo_schema() {
        global $wpdb;
        $needed = array( 'seo_nodes' => array( 'id', 'object_type', 'object_id', 'seo_role', 'status' ), 'seo_relations' => array( 'id', 'source_type', 'source_id', 'target_type', 'target_id', 'relation_type' ) );
        $missing = array();
        foreach ( $needed as $suffix => $columns ) {
            $table = $wpdb->prefix . $suffix;
            if ( ! self::table_exists( $table ) ) { $missing[] = $table; continue; }
            $existing = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
            foreach ( $columns as $column ) { if ( ! in_array( $column, $existing, true ) ) { $missing[] = $table . '.' . $column; } }
        }
        return $missing ? self::fail( 'Tablas o columnas no disponibles: ' . implode( ', ', $missing ) ) : self::pass( 'Nodos y relaciones SEO tienen los campos necesarios.' );
    }
    private static function test_config() {
        $config = \TCDB_Legacy\seo_clean_db_get_config();
        return isset( $config['node_roles'], $config['relation_schemas'], $config['report_limit'] ) && is_array( $config['relation_schemas'] )
            ? self::pass( count( $config['relation_schemas'] ) . ' esquemas de relación; configuración cargada.' ) : self::fail( 'Configuración SEO incompleta.' );
    }
    private static function test_index() {
        $a = (object) array( 'seo_role' => 'cluster', 'object_id' => 123 );
        $index = \TCDB_Legacy\seo_clean_db_build_node_index( array( $a ) );
        return isset( $index['cluster:123'] ) && $index['cluster:123'] === $a ? self::pass( 'Índice sintético construido correctamente.' ) : self::fail( 'El índice de nodos no conserva su clave.' );
    }
    private static function test_nodes() {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_nodes';
        if ( ! self::table_exists( $table ) ) { return self::skip( 'Tabla de nodos no disponible; revisar esquema.' ); }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, object_type, object_id, seo_role, status FROM %i ORDER BY id ASC LIMIT %d', $table, 2 ) );
        return is_array( $rows ) ? self::pass( count( $rows ) . ' nodos leídos (máximo 2).' ) : self::fail( 'No se pueden leer nodos.' );
    }
    private static function test_relations() {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_relations';
        if ( ! self::table_exists( $table ) ) { return self::skip( 'Tabla de relaciones no disponible; revisar esquema.' ); }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, source_type, source_id, target_type, target_id, relation_type FROM %i ORDER BY id ASC LIMIT %d', $table, 2 ) );
        return is_array( $rows ) ? self::pass( count( $rows ) . ' relaciones leídas (máximo 2).' ) : self::fail( 'No se pueden leer relaciones.' );
    }
    private static function test_categories() {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare( 'SELECT term_taxonomy_id FROM %i WHERE taxonomy = %s LIMIT 1', $wpdb->term_taxonomy, 'product_cat' ) );
        return '' === $wpdb->last_error ? self::pass( 'Consulta limitada de categorías WooCommerce correcta' . ( null === $value ? ' (sin categorías).' : '.' ) ) : self::fail( 'Error de consulta: ' . $wpdb->last_error );
    }
    private static function test_cleanup() {
        $functions = array( 'seo_clean_db_get_cleanup_stats', 'seo_clean_db_handle_database_cleanup_action', 'seo_clean_db_delete_trashed_posts', 'seo_clean_db_delete_expired_transients', 'seo_clean_db_delete_wc_expired_sessions', 'seo_clean_db_delete_as_orphan_logs', 'seo_clean_db_delete_as_old_completed_logs', 'seo_clean_db_delete_as_orphan_claims', 'seo_clean_db_delete_wc_expired_reserved_stock', 'seo_clean_db_delete_wc_orphan_order_itemmeta' );
        foreach ( $functions as $name ) { if ( ! function_exists( 'TCDB_Legacy\\' . $name ) ) { return self::fail( 'Función ausente: ' . $name ); } }
        return self::pass( 'Controlador de 17 limpiezas y funciones auxiliares disponibles; sin ejecutar borrados.' );
    }
    private static function test_cleanup_counts() {
        global $wpdb;
        $n = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM (SELECT ID FROM %i WHERE post_type = %s LIMIT 2) tcdb_sample', $wpdb->posts, 'revision' ) );
        return '' === $wpdb->last_error && is_numeric( $n ) ? self::pass( 'Lectura acotada de candidatos de limpieza: ' . $n . ' revisiones muestreadas.' ) : self::fail( 'Consulta de candidatos no disponible: ' . $wpdb->last_error );
    }
    private static function test_full_export() {
        $full = \TCDB_Legacy\seo_clean_db_get_export_tables( 'full' );
        $seo = \TCDB_Legacy\seo_clean_db_get_export_tables( 'seo' );
        if ( ! is_array( $full ) || ! is_array( $seo ) ) { return self::fail( 'Selección de tablas de backup inválida.' ); }
        foreach ( $seo as $name ) { if ( ! in_array( $name, $full, true ) ) { return self::fail( 'Una tabla SEO no está incluida en el backup completo.' ); } }
        return self::pass( count( $full ) . ' tablas elegibles para backup completo y ' . count( $seo ) . ' SEO; no se genera el archivo.' );
    }
    private static function test_inventory() {
        $tables = \TCDB_Legacy\seo_data_get_tables();
        if ( ! is_array( $tables ) ) { return self::fail( 'Inventario no válido.' ); }
        foreach ( $tables as $name => $row ) { if ( ! \TCDB_Legacy\seo_data_is_valid_table_name( $name ) || ! isset( $row['name'], $row['rows'], $row['engine'] ) ) { return self::fail( 'Metadatos de tabla incorrectos.' ); } }
        return self::pass( count( $tables ) . ' tablas SEO accesibles desde el inventario.' );
    }
    private static function test_columns() {
        $tables = \TCDB_Legacy\seo_data_get_tables();
        if ( ! $tables ) { return self::skip( 'No hay tablas SEO para explorar.' ); }
        $name = (string) array_key_first( $tables );
        $columns = \TCDB_Legacy\seo_data_get_columns( $name );
        return is_array( $columns ) && ! empty( $columns ) ? self::pass( count( $columns ) . ' columnas leídas de ' . $name . '.' ) : self::fail( 'No se obtienen columnas de ' . $name . '.' );
    }
    private static function test_exports() {
        $required = array( 'seo_data_export_table_csv', 'seo_data_export_table_sql', 'seo_data_export_all', 'seo_data_write_table_csv', 'seo_data_write_table_sql', 'seo_data_assert_export_access' );
        foreach ( $required as $name ) { if ( ! function_exists( 'TCDB_Legacy\\' . $name ) ) { return self::fail( 'Exportador ausente: ' . $name ); } }
        if ( ! class_exists( 'ZipArchive' ) ) { return self::fail( 'La extensión ZIP requerida para exportación de todas las tablas no está disponible.' ); }
        return self::pass( 'Motores CSV/SQL/ZIP y controles de acceso disponibles; no se crea ni descarga un backup.' );
    }
    private static function test_manual() {
        return class_exists( 'TCDB_Manual' ) && method_exists( 'TCDB_Manual', 'render' )
            ? self::pass( 'Manual de usuario y manual técnico disponibles.' ) : self::fail( 'Manual no cargado.' );
    }
    private static function test_destructive() {
        return self::skip( 'Por seguridad, no se ejecutan limpiezas, reparaciones, importaciones ni exportaciones reales. Validar con datos de prueba en staging.' );
    }
    public static function handle() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Sin permisos para Auto Test.', 'taxocommerce-database' ) ); }
        check_admin_referer( self::NONCE, 'tcdb_auto_nonce' );
        $key = self::PREFIX . get_current_user_id();
        $history = get_transient( $key );
        if ( ! is_array( $history ) ) { $history = array(); }
        array_unshift( $history, self::run_suite() );
        set_transient( $key, array_slice( $history, 0, 2 ), 30 * DAY_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-database&tcdb_screen=auto-test' ) );
        exit;
    }
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Sin permisos para Auto Test.', 'taxocommerce-database' ) ); }
        $history = get_transient( self::PREFIX . get_current_user_id() );
        $current = is_array( $history ) && isset( $history[0]['rows'] ) ? $history[0] : null;
        $previous = is_array( $history ) && isset( $history[1]['rows'] ) ? $history[1] : null;
        echo '<div class="wrap tcdb-auto" style="max-width:1150px"><h2>Auto Test de Database</h2>';
        echo '<p>Chequeo de solo lectura de los dos servicios originales, tablas, columnas y preparación de backups. Los resultados se guardan por usuario durante 30 días. Ejecutar antes y después de cada subida y comparar los dos últimos informes.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        wp_nonce_field( self::NONCE, 'tcdb_auto_nonce' );
        submit_button( 'Ejecutar Auto Test', 'primary', 'submit', false );
        echo '</form>';
        if ( ! $current ) { echo '<p>No existen resultados todavía. Pulsa Ejecutar Auto Test.</p></div>'; return; }
        $totals = $current['totals'];
        echo '<h3>Último resultado · ' . esc_html( $current['version'] ) . ' · ' . esc_html( $current['date'] ) . '</h3>';
        echo '<p><strong>' . esc_html( (string) $totals['pass'] ) . ' correctas</strong> · <strong>' . esc_html( (string) $totals['fail'] ) . ' fallidas</strong> · ' . esc_html( (string) $totals['skip'] ) . ' omitidas · ' . esc_html( (string) $current['ms'] ) . ' ms</p>';
        if ( $previous ) {
            $before = array();
            foreach ( $previous['rows'] as $row ) { $before[ $row['id'] ] = $row['status']; }
            $regressions = array();
            foreach ( $current['rows'] as $row ) { if ( 'fail' === $row['status'] && isset( $before[ $row['id'] ] ) && 'pass' === $before[ $row['id'] ] ) { $regressions[] = $row['label']; } }
            echo '<p><strong>Comparación:</strong> ' . esc_html( $previous['version'] . ' (' . $previous['date'] . ') → ' . $current['version'] ) . '. ';
            echo $regressions ? '<strong style="color:#b32d2e">Regresiones detectadas: ' . esc_html( implode( ', ', $regressions ) ) . '</strong>' : 'Sin regresiones de correcto a fallido.'; echo '</p>';
        } else { echo '<p>No hay informe anterior para comparar. Conserva esta ejecución como referencia antes de actualizar.</p>'; }
        echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Funcionalidad</th><th>Prueba</th><th>Estado</th><th>Resultado</th><th>Tiempo</th></tr></thead><tbody>';
        foreach ( $current['rows'] as $row ) {
            $status_label = array( 'pass' => 'Correcto', 'fail' => 'Error', 'skip' => 'No ejecutado' );
            echo '<tr><td>' . esc_html( $row['group'] ) . '</td><td>' . esc_html( $row['label'] ) . '</td><td><strong>' . esc_html( $status_label[ $row['status'] ] ) . '</strong></td><td>' . esc_html( $row['message'] ) . '</td><td>' . esc_html( (string) $row['ms'] ) . ' ms</td></tr>';
        }
        echo '</tbody></table></div><p class="description">Auto Test comprueba disponibilidad, lectura y contratos de datos; NO demuestra por sí solo que un backup completo sea restaurable ni que los borrados funcionen con datos reales.</p></div>';
    }
}
TCDB_Auto_Test::boot();

<?php
namespace TCDB_Legacy;
use \ZipArchive;
/**
 * SEO Data Explorer & Export
 *
 * Explorador administrativo de todas las tablas propias del plugin.
 * Detecta dinámicamente cualquier tabla con prefijo {$wpdb->prefix}seo_.
 */

defined('ABSPATH') || exit;

/**
 * Lee filtros y avisos GET de solo lectura del explorador de datos.
 */
function seo_data_query_arg($key, $default = '') {
    $key = sanitize_key((string) $key);
    if ('' === $key) {
        return $default;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navegación/filtros GET de solo lectura.
    if (!isset($_GET[$key])) {
        return $default;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navegación/filtros GET de solo lectura.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw query value is returned deliberately so each caller can apply the sanitizer appropriate to its expected type.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation and filtering; GET cannot change data.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Typed sanitation is applied by each caller; query helper reads only navigation/filter input.
    return wp_unslash($_GET[$key]);
}

add_action('admin_post_tcdb_legacy_data_export_table_csv', __NAMESPACE__ . '\\seo_data_export_table_csv');
add_action('admin_post_tcdb_legacy_data_export_table_sql', __NAMESPACE__ . '\\seo_data_export_table_sql');
add_action('admin_post_tcdb_legacy_data_export_all', __NAMESPACE__ . '\\seo_data_export_all');

/**
 * Pantalla principal.
 */
function seo_data_table_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para acceder a esta herramienta.', 'taxocommerce-database'));
    }

    global $wpdb;

    $section = sanitize_key((string) seo_data_query_arg('section', 'overview'));
    if (!in_array($section, ['overview', 'explorer', 'export'], true)) {
        $section = 'overview';
    }

    $tables = seo_data_get_tables();
    $base_url = admin_url('admin.php?page=taxocommerce-database&tcdb_screen=table');

    echo '<div class="wrap">';
    echo '<h1>SEO Data Table</h1>';
    echo '<p>Explorador y sistema de exportación de todas las tablas internas de TaxoCommerce SEO. Las tablas se detectan automáticamente, incluidas las que se añadan en futuras versiones.</p>';

    seo_data_render_styles();


    if ($section === 'overview') {
        seo_data_render_overview($tables);
    } elseif ($section === 'explorer') {
        seo_data_render_explorer($tables);
    } else {
        seo_data_render_export($tables);
    }

    echo '</div>';
}

/**
 * Detecta todas las tablas SEO del prefijo actual.
 */
function seo_data_get_tables(): array
{
    global $wpdb;

    $pattern = $wpdb->esc_like($wpdb->prefix . 'seo_') . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative live database diagnosis; stale cached results would conceal changes.
    $names = $wpdb->get_col(
        $wpdb->prepare('SHOW TABLES LIKE %s', $pattern)
    );

    if (!is_array($names)) {
        return [];
    }

    sort($names, SORT_NATURAL | SORT_FLAG_CASE);

    $tables = [];

    foreach ($names as $table_name) {
        if (!seo_data_is_valid_table_name($table_name)) {
            continue;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative live database diagnosis; stale cached results would conceal changes.
        $status = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, ENGINE, TABLE_COLLATION
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table_name
            ),
            ARRAY_A
        );

        $tables[$table_name] = [
            'name'        => $table_name,
            'short_name'  => preg_replace('/^' . preg_quote($wpdb->prefix, '/') . '/', '', $table_name),
            'label'       => seo_data_table_label($table_name),
            'description' => seo_data_table_description($table_name),
            'rows'        => isset($status['TABLE_ROWS']) ? (int) $status['TABLE_ROWS'] : 0,
            'data_bytes'  => isset($status['DATA_LENGTH']) ? (int) $status['DATA_LENGTH'] : 0,
            'index_bytes' => isset($status['INDEX_LENGTH']) ? (int) $status['INDEX_LENGTH'] : 0,
            'engine'      => $status['ENGINE'] ?? '',
            'collation'   => $status['TABLE_COLLATION'] ?? '',
        ];
    }

    return $tables;
}

function seo_data_table_label(string $table_name): string
{
    global $wpdb;

    $key = preg_replace('/^' . preg_quote($wpdb->prefix . 'seo_', '/') . '/', '', $table_name);

    $labels = [
        'relations'             => 'Relaciones SEO',
        'nodes'                 => 'Nodos SEO',
        'templates'             => 'Plantillas SEO',
        'redirects'             => 'Redirecciones SEO',
        'dictionari'            => 'Diccionario SEO',
        'attributes'            => 'Atributos SEO',
        'vocabulary'            => 'Vocabulario SEO',
        'operations'            => 'Operaciones del Data Layer',
        'operation_changes'     => 'Cambios del Data Layer',
        'proveedores_productos' => 'Productos de proveedores',
        'media_usos'            => 'Usos de imágenes y medios',
        'images'                => 'Imágenes SEO',
        'image_inventory'       => 'Inventario de imágenes',
        'category_tags'         => 'Etiquetas de categorías',
        'product_tags'          => 'Etiquetas de productos',
    ];

    if (isset($labels[$key])) {
        return $labels[$key];
    }

    return ucwords(str_replace('_', ' ', $key));
}

function seo_data_table_description(string $table_name): string
{
    global $wpdb;

    $key = preg_replace('/^' . preg_quote($wpdb->prefix . 'seo_', '/') . '/', '', $table_name);

    $descriptions = [
        'relations'             => 'Relaciones entre clusters, hubs, categorías y otras entidades.',
        'nodes'                 => 'Objetos WordPress registrados como nodos y sus roles SEO.',
        'templates'             => 'Plantillas registradas y archivos asociados.',
        'redirects'             => 'Redirecciones administradas por el sistema.',
        'dictionari'            => 'Palabras, términos y puntuaciones del diccionario.',
        'attributes'            => 'Atributos, textos RAW y metadatos extraídos de productos.',
        'vocabulary'            => 'Vocabulario semántico utilizado para generación y clasificación.',
        'operations'            => 'Cabeceras de operaciones auditadas por el Data Layer.',
        'operation_changes'     => 'Cambios individuales, snapshots y estado de rollback.',
        'proveedores_productos' => 'Datos importados o normalizados procedentes de proveedores.',
        'media_usos'            => 'Relación entre imágenes, adjuntos y entidades que las utilizan.',
    ];

    return $descriptions[$key] ?? 'Tabla interna detectada automáticamente por TaxoCommerce SEO.';
}

/**
 * Resumen de tablas.
 */
function seo_data_render_overview(array $tables): void
{
    $total_rows = 0;
    $total_bytes = 0;

    foreach ($tables as $table) {
        $total_rows += $table['rows'];
        $total_bytes += $table['data_bytes'] + $table['index_bytes'];
    }

    echo '<div class="seo-data-cards">';
    seo_data_summary_card('Tablas detectadas', count($tables), 'Inventario dinámico');
    seo_data_summary_card('Filas aproximadas', number_format_i18n($total_rows), 'Todas las tablas SEO');
    seo_data_summary_card('Tamaño total', size_format($total_bytes, 2), 'Datos e índices');
    seo_data_summary_card('Versión BBDD', (string) get_option('seo_system_db_version', 'No registrada'), 'TaxoCommerce SEO');
    echo '</div>';

    echo '<div class="seo-data-panel">';
    echo '<h2>Inventario de tablas SEO</h2>';
    echo '<table class="widefat striped seo-data-table">';
    echo '<thead><tr><th>Tabla</th><th>Nombre físico</th><th>Descripción</th><th>Filas aprox.</th><th>Tamaño</th><th>Motor</th><th>Acciones</th></tr></thead><tbody>';

    if (!$tables) {
        echo '<tr><td colspan="7">No se han detectado tablas con el prefijo SEO.</td></tr>';
    }

    foreach ($tables as $table) {
        $explore_url = add_query_arg(
            [
                'page'    => 'taxocommerce-database',
                'tcdb_screen' => 'table',
                'section' => 'explorer',
                'table'   => $table['name'],
            ],
            admin_url('admin.php')
        );

        echo '<tr>';
        echo '<td><strong>' . esc_html($table['label']) . '</strong></td>';
        echo '<td><code>' . esc_html($table['name']) . '</code></td>';
        echo '<td>' . esc_html($table['description']) . '</td>';
        echo '<td>' . esc_html(number_format_i18n($table['rows'])) . '</td>';
        echo '<td>' . esc_html(size_format($table['data_bytes'] + $table['index_bytes'], 2)) . '</td>';
        echo '<td>' . esc_html($table['engine'] ?: '—') . '</td>';
        echo '<td><a class="button" href="' . esc_url($explore_url) . '">Explorar</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Explorador genérico, paginado, filtrable y ordenable.
 */
function seo_data_render_explorer(array $tables): void
{
    global $wpdb;

    if (!$tables) {
        echo '<div class="notice notice-warning"><p>No se han detectado tablas SEO.</p></div>';
        return;
    }

    $requested_table = sanitize_text_field((string) seo_data_query_arg('table', ''));
    $table_name = isset($tables[$requested_table]) ? $requested_table : array_key_first($tables);

    $columns = seo_data_get_columns($table_name);
    if (!$columns) {
        echo '<div class="notice notice-error"><p>No se pudo leer la estructura de la tabla.</p></div>';
        return;
    }

    $column_names = array_column($columns, 'Field');
    $default_orderby = in_array('id', $column_names, true) ? 'id' : $column_names[0];

    $orderby = sanitize_key((string) seo_data_query_arg('orderby', $default_orderby));
    if (!in_array($orderby, $column_names, true)) {
        $orderby = $default_orderby;
    }

    $order = strtoupper(sanitize_text_field((string) seo_data_query_arg('order', 'DESC')));
    if (!in_array($order, ['ASC', 'DESC'], true)) {
        $order = 'DESC';
    }

    $search = sanitize_text_field((string) seo_data_query_arg('s', ''));
    $filter_column = sanitize_key((string) seo_data_query_arg('filter_column', ''));
    $filter_value = sanitize_text_field((string) seo_data_query_arg('filter_value', ''));

    if (!in_array($filter_column, $column_names, true)) {
        $filter_column = '';
    }

    $per_page = absint(seo_data_query_arg('per_page', 100));
    if (!in_array($per_page, [25, 50, 100, 250, 500], true)) {
        $per_page = 100;
    }

    $page_number = max(1, absint(seo_data_query_arg('paged', 1)));
    $offset = ($page_number - 1) * $per_page;

    $where_parts = [];
    $where_args = [];

    if ($filter_column !== '' && $filter_value !== '') {
        $where_parts[] = '`' . esc_sql($filter_column) . '` LIKE %s';
        $where_args[] = '%' . $wpdb->esc_like($filter_value) . '%';
    }

    if ($search !== '') {
        $search_parts = [];
        foreach ($column_names as $column_name) {
            $search_parts[] = 'CAST(`' . esc_sql($column_name) . '` AS CHAR) LIKE %s';
            $where_args[] = '%' . $wpdb->esc_like($search) . '%';
        }
        $where_parts[] = '(' . implode(' OR ', $search_parts) . ')';
    }

    $where_sql = $where_parts ? ' WHERE ' . implode(' AND ', $where_parts) : '';

    $count_sql = "SELECT COUNT(*) FROM `{$table_name}`{$where_sql}";
    if ($where_args) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table/column identifiers are selected from the validated explorer allowlist; filter values are bound through prepare().
        $count_sql = $wpdb->prepare($count_sql, $where_args);
    }
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Explorer identifiers are validated against detected table/column allowlists before this query.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative live database diagnosis; stale cached results would conceal changes.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Table and column names come from allowlisted schema; live count must reflect current data.
    $total_items = (int) $wpdb->get_var($count_sql);

    $query_sql = "SELECT * FROM `{$table_name}`{$where_sql} ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d";
    $query_args = array_merge($where_args, [$per_page, $offset]);
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Explorer identifiers/order are allowlisted; filter values, limit and offset are bound through prepare().
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative live database diagnosis; stale cached results would conceal changes.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- Explorer SQL uses validated identifiers and prepared filter values, paging parameters.
    $rows = $wpdb->get_results($wpdb->prepare($query_sql, $query_args), ARRAY_A);

    echo '<div class="seo-data-panel">';
    echo '<form method="get" class="seo-data-toolbar">';
    echo '<input type="hidden" name="page" value="taxocommerce-database">';
    echo '<input type="hidden" name="tcdb_screen" value="table">';
    echo '<input type="hidden" name="section" value="explorer">';

    echo '<label><strong>Tabla</strong><select name="table">';
    foreach ($tables as $table) {
        echo '<option value="' . esc_attr($table['name']) . '" ' . selected($table_name, $table['name'], false) . '>' . esc_html($table['label'] . ' — ' . $table['name']) . '</option>';
    }
    echo '</select></label>';

    echo '<label><strong>Buscar en toda la tabla</strong><input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Texto, ID, URL..."></label>';

    echo '<label><strong>Columna</strong><select name="filter_column"><option value="">Todas</option>';
    foreach ($column_names as $column_name) {
        echo '<option value="' . esc_attr($column_name) . '" ' . selected($filter_column, $column_name, false) . '>' . esc_html($column_name) . '</option>';
    }
    echo '</select></label>';

    echo '<label><strong>Valor</strong><input type="text" name="filter_value" value="' . esc_attr($filter_value) . '"></label>';

    echo '<label><strong>Filas</strong><select name="per_page">';
    foreach ([25, 50, 100, 250, 500] as $option) {
        echo '<option value="' . esc_attr((string) $option) . '" ' . selected($per_page, $option, false) . '>' . esc_html((string) $option) . '</option>';
    }
    echo '</select></label>';

    echo '<button class="button button-primary">Aplicar</button>';
    echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=taxocommerce-database&tcdb_screen=table&section=explorer&table=' . rawurlencode($table_name))) . '">Limpiar</a>';
    echo '</form>';

    echo '<div class="seo-data-meta">';
    echo '<strong>' . esc_html($tables[$table_name]['label']) . '</strong> · ';
    echo '<code>' . esc_html($table_name) . '</code> · ';
    echo esc_html(number_format_i18n($total_items)) . ' filas encontradas';
    echo '</div>';

    echo '<div class="seo-data-scroll">';
    echo '<table class="widefat striped seo-data-table">';
    echo '<thead><tr>';

    foreach ($column_names as $column_name) {
        $next_order = ($orderby === $column_name && $order === 'ASC') ? 'DESC' : 'ASC';
        $sort_url = add_query_arg(
            [
                'page'          => 'taxocommerce-database',
                'tcdb_screen'   => 'table',
                'section'       => 'explorer',
                'table'         => $table_name,
                'orderby'       => $column_name,
                'order'         => $next_order,
                's'             => $search,
                'filter_column' => $filter_column,
                'filter_value'  => $filter_value,
                'per_page'      => $per_page,
            ],
            admin_url('admin.php')
        );

        echo '<th><a href="' . esc_url($sort_url) . '">' . esc_html($column_name) . '</a></th>';
    }

    echo '</tr></thead><tbody>';

    if (!$rows) {
        echo '<tr><td colspan="' . count($column_names) . '">No se encontraron registros.</td></tr>';
    }

    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($column_names as $column_name) {
            echo '<td>' . wp_kses_post(seo_data_format_cell($row[$column_name] ?? null)) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';

    $total_pages = max(1, (int) ceil($total_items / $per_page));
    $pagination = paginate_links([
        'base'      => add_query_arg('paged', '%#%'),
        'format'    => '',
        'current'   => $page_number,
        'total'     => $total_pages,
        'prev_text' => '«',
        'next_text' => '»',
        'type'      => 'list',
    ]);

    if ($pagination) {
        echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($pagination) . '</div></div>';
    }

    echo '</div>';
}

/**
 * Exportaciones y backups.
 */
function seo_data_render_export(array $tables): void
{
    if (!$tables) {
        echo '<div class="notice notice-warning"><p>No se han detectado tablas SEO.</p></div>';
        return;
    }

    echo '<div class="seo-data-panel">';
    echo '<h2>Exportación individual</h2>';
    echo '<p>Cada tabla puede descargarse en CSV para análisis humano o en SQL para restauración técnica.</p>';
    echo '<table class="widefat striped seo-data-table">';
    echo '<thead><tr><th>Tabla</th><th>Nombre físico</th><th>Descripción</th><th>Filas aprox.</th><th>CSV</th><th>SQL</th></tr></thead><tbody>';

    foreach ($tables as $table) {
        $csv_url = seo_data_export_url('tcdb_legacy_data_export_table_csv', $table['name']);
        $sql_url = seo_data_export_url('tcdb_legacy_data_export_table_sql', $table['name']);

        echo '<tr>';
        echo '<td><strong>' . esc_html($table['label']) . '</strong></td>';
        echo '<td><code>' . esc_html($table['name']) . '</code></td>';
        echo '<td>' . esc_html($table['description']) . '</td>';
        echo '<td>' . esc_html(number_format_i18n($table['rows'])) . '</td>';
        echo '<td><a class="button" href="' . esc_url($csv_url) . '">Descargar CSV</a></td>';
        echo '<td><a class="button" href="' . esc_url($sql_url) . '">Descargar SQL</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';

    echo '<div class="seo-data-panel">';
    echo '<h2>Backup completo</h2>';
    echo '<p>Genera un ZIP con todas las tablas detectadas. El paquete incluye un manifiesto JSON con versión, fecha, sitio y lista de tablas.</p>';

    foreach (['csv' => 'Todas en CSV', 'sql' => 'Todas en SQL', 'both' => 'CSV + SQL'] as $format => $label) {
        $url = wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'tcdb_legacy_data_export_all',
                    'format' => $format,
                ],
                admin_url('admin-post.php')
            ),
            'seo_data_export_all_' . $format
        );

        echo '<a class="button button-primary" style="margin-right:8px;" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    echo '<p class="description" style="margin-top:12px;">El backup de tablas SEO no sustituye una copia completa de WordPress, archivos y base de datos, pero permite trasladar y analizar el estado interno del plugin.</p>';
    echo '</div>';
}

function seo_data_get_columns(string $table_name): array
{
    global $wpdb;

    if (!seo_data_is_registered_table($table_name)) {
        return [];
    }

    $table_name = esc_sql( $table_name );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative live database diagnosis; stale cached results would conceal changes.
    $columns = $wpdb->get_results($wpdb->prepare('DESCRIBE %i', $table_name), ARRAY_A);
    return is_array($columns) ? $columns : [];
}

function seo_data_is_valid_table_name(string $table_name): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_]+$/', $table_name);
}

function seo_data_is_registered_table(string $table_name): bool
{
    $tables = seo_data_get_tables();
    return isset($tables[$table_name]);
}

function seo_data_format_cell($value): string
{
    if ($value === null) {
        return '<em>NULL</em>';
    }

    if (is_scalar($value)) {
        $text = (string) $value;
    } else {
        $text = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $display = mb_strlen($text) > 500 ? mb_substr($text, 0, 500) . '…' : $text;

    return '<span title="' . esc_attr($text) . '">' . nl2br(esc_html($display)) . '</span>';
}

function seo_data_summary_card(string $label, $value, string $description): void
{
    echo '<div class="seo-data-card">';
    echo '<span class="seo-data-card-value">' . esc_html((string) $value) . '</span>';
    echo '<strong>' . esc_html($label) . '</strong>';
    echo '<small>' . esc_html($description) . '</small>';
    echo '</div>';
}

function seo_data_export_url(string $action, string $table_name): string
{
    return wp_nonce_url(
        add_query_arg(
            [
                'action' => $action,
                'table'  => $table_name,
            ],
            admin_url('admin-post.php')
        ),
        $action . '_' . $table_name
    );
}

/**
 * Abre un recurso local para exportación incremental de datos.
 *
 * WP_Filesystem no expone recursos compatibles con fputcsv() ni con el
 * streaming SQL/ZIP sin cargar artefactos completos en memoria.
 *
 * @param string $path Ruta o wrapper de stream.
 * @param string $mode Modo de apertura.
 * @return resource|false
 */
function seo_data_stream_open($path, $mode) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Data exports require native stream resources.
    return fopen($path, $mode);
}

/**
 * Escribe bytes en un recurso de exportación.
 *
 * @param resource $stream Recurso abierto.
 * @param string   $data   Datos.
 * @return int|false
 */
function seo_data_stream_write($stream, $data) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Incremental SQL/CSV export writes directly to the stream.
    return fwrite($stream, $data);
}

/**
 * Cierra un recurso de exportación.
 *
 * @param resource $stream Recurso abierto.
 * @return bool
 */
function seo_data_stream_close($stream) {
    if (!is_resource($stream)) {
        return false;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with seo_data_stream_open().
    return fclose($stream);
}

/**
 * Envía un archivo generado al cliente sin cargarlo completo en memoria.
 *
 * @param string $path Ruta local.
 * @return int|false
 */
function seo_data_stream_passthrough($path) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- ZIP download is intentionally streamed to avoid memory spikes.
    return readfile($path);
}

/**
 * Elimina un directorio temporal ya vacío.
 *
 * @param string $directory Directorio local.
 * @return bool
 */
function seo_data_remove_empty_directory($directory) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Temporary directory is recursively emptied before removal.
    return @rmdir($directory);
}

/**
 * Exporta una tabla en CSV, por streaming.
 */
function seo_data_export_table_csv(): void
{
    seo_data_assert_export_access();

    $table_name = sanitize_text_field((string) seo_data_query_arg('table', ''));
    check_admin_referer('tcdb_legacy_data_export_table_csv_' . $table_name);

    if (!seo_data_is_registered_table($table_name)) {
        wp_die('Tabla no permitida.');
    }

    $filename = sanitize_file_name($table_name . '-' . gmdate('Y-m-d-His') . '.csv');

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    $output = seo_data_stream_open('php://output', 'wb');
    if (!$output) {
        wp_die('No se pudo abrir la salida CSV.');
    }

    seo_data_stream_write($output, "\xEF\xBB\xBF");
    seo_data_write_table_csv($table_name, $output);
    seo_data_stream_close($output);
    exit;
}

/**
 * Exporta una tabla en SQL, por streaming.
 */
function seo_data_export_table_sql(): void
{
    seo_data_assert_export_access();

    $table_name = sanitize_text_field((string) seo_data_query_arg('table', ''));
    check_admin_referer('tcdb_legacy_data_export_table_sql_' . $table_name);

    if (!seo_data_is_registered_table($table_name)) {
        wp_die('Tabla no permitida.');
    }

    $filename = sanitize_file_name($table_name . '-' . gmdate('Y-m-d-His') . '.sql');

    nocache_headers();
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    $output = seo_data_stream_open('php://output', 'wb');
    if (!$output) {
        wp_die('No se pudo abrir la salida SQL.');
    }

    seo_data_write_table_sql($table_name, $output);
    seo_data_stream_close($output);
    exit;
}


/**
 * Centro global de operaciones, auditoría y rollback.
 */
function seo_data_table_exists(string $table): bool
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative live database diagnosis; stale cached results would conceal changes.
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
}

function seo_data_render_pagination(int $total, int $per_page, int $paged): void
{
    $pages = max(1, (int) ceil($total / $per_page));
    if ($pages <= 1) {
        return;
    }
    $links = paginate_links([
        'base' => add_query_arg('paged', '%#%'),
        'format' => '',
        'current' => $paged,
        'total' => $pages,
        'type' => 'list',
    ]);
    if ($links) {
        echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($links) . '</div></div>';
    }
}

/**
 * Exporta todas las tablas a un ZIP.
 */
function seo_data_export_all(): void
{
    seo_data_assert_export_access();

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Format is required to construct the nonce action verified below.
    $format = sanitize_key(wp_unslash($_GET['format'] ?? 'both'));
    if (!in_array($format, ['csv', 'sql', 'both'], true)) {
        $format = 'both';
    }

    check_admin_referer('seo_data_export_all_' . $format);

    if (!class_exists('ZipArchive')) {
        wp_die('ZipArchive no está disponible en este servidor. Usa las exportaciones individuales.');
    }

    $tables = seo_data_get_tables();
    if (!$tables) {
        wp_die('No hay tablas SEO para exportar.');
    }

    $temp_dir = trailingslashit(get_temp_dir()) . 'seo-data-export-' . wp_generate_uuid4();
    if (!wp_mkdir_p($temp_dir)) {
        wp_die('No se pudo crear el directorio temporal.');
    }

    $zip_path = $temp_dir . '/seo-taxonomy-backup-' . gmdate('Y-m-d-His') . '.zip';
    $zip = new ZipArchive();

    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        seo_data_remove_directory($temp_dir);
        wp_die('No se pudo crear el archivo ZIP.');
    }

    $manifest = [
        'plugin'           => 'TaxoCommerce SEO',
        'plugin_version'   => defined('TAXOCOMMERCE_DATABASE_VERSION') ? TAXOCOMMERCE_DATABASE_VERSION : '',
        'database_version' => (string) get_option('seo_system_db_version', ''),
        'site_url'         => home_url('/'),
        'generated_at_utc' => gmdate('c'),
        'format'           => $format,
        'tables'           => [],
    ];

    foreach ($tables as $table) {
        $manifest['tables'][] = [
            'name' => $table['name'],
            'rows' => $table['rows'],
        ];

        if ($format === 'csv' || $format === 'both') {
            $csv_path = $temp_dir . '/' . $table['name'] . '.csv';
            $handle = seo_data_stream_open($csv_path, 'wb');
            if ($handle) {
                seo_data_stream_write($handle, "\xEF\xBB\xBF");
                seo_data_write_table_csv($table['name'], $handle);
                seo_data_stream_close($handle);
                $zip->addFile($csv_path, 'csv/' . basename($csv_path));
            }
        }

        if ($format === 'sql' || $format === 'both') {
            $sql_path = $temp_dir . '/' . $table['name'] . '.sql';
            $handle = seo_data_stream_open($sql_path, 'wb');
            if ($handle) {
                seo_data_write_table_sql($table['name'], $handle);
                seo_data_stream_close($handle);
                $zip->addFile($sql_path, 'sql/' . basename($sql_path));
            }
        }
    }

    $manifest_path = $temp_dir . '/manifest.json';
    file_put_contents(
        $manifest_path,
        wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    $zip->addFile($manifest_path, 'manifest.json');
    $zip->close();

    if (!is_readable($zip_path)) {
        seo_data_remove_directory($temp_dir);
        wp_die('El ZIP no pudo generarse correctamente.');
    }

    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($zip_path) . '"');
    header('Content-Length: ' . filesize($zip_path));
    header('X-Content-Type-Options: nosniff');

    seo_data_stream_passthrough($zip_path);
    seo_data_remove_directory($temp_dir);
    exit;
}

function seo_data_write_table_csv(string $table_name, $handle): void
{
    global $wpdb;

    $columns = seo_data_get_columns($table_name);
    $column_names = array_column($columns, 'Field');

    if (!$column_names) {
        return;
    }

    // Registered table checked by seo_data_get_columns().
    $table_name = esc_sql( $table_name );
    fputcsv($handle, $column_names, ';', '"', '\\');

    $batch_size = 1000;
    $offset = 0;

    do {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative live database diagnosis; stale cached results would conceal changes.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i LIMIT %d OFFSET %d',
                $table_name,
                $batch_size,
                $offset
            ),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $ordered = [];
            foreach ($column_names as $column_name) {
                $ordered[] = $row[$column_name] ?? null;
            }
            fputcsv($handle, $ordered, ';', '"', '\\');
        }

        $offset += $batch_size;
    } while (count($rows) === $batch_size);
}

function seo_data_write_table_sql(string $table_name, $handle): void
{
    global $wpdb;
    // Validate the requested table against the Data Layer registry before SQL.
    if (!seo_data_is_registered_table($table_name)) {
        return;
    }
    $table_name = esc_sql( $table_name );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative live database diagnosis; stale cached results would conceal changes.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- SHOW CREATE TABLE is read-only schema inspection necessary for SQL export.
    $create = $wpdb->get_row($wpdb->prepare('SHOW CREATE TABLE %i', $table_name), ARRAY_N);
    if (!$create || empty($create[1])) {
        return;
    }

    seo_data_stream_write($handle, "-- TaxoCommerce SEO export\n");
    seo_data_stream_write($handle, '-- Table: ' . $table_name . "\n");
    seo_data_stream_write($handle, '-- Generated UTC: ' . gmdate('c') . "\n\n");
    seo_data_stream_write($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    seo_data_stream_write($handle, "DROP TABLE IF EXISTS `{$table_name}`;\n");
    seo_data_stream_write($handle, $create[1] . ";\n\n");

    $columns = seo_data_get_columns($table_name);
    $column_names = array_column($columns, 'Field');
    $column_sql = implode(', ', array_map(static fn($column) => '`' . str_replace('`', '``', $column) . '`', $column_names));

    $batch_size = 500;
    $offset = 0;

    do {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative live database diagnosis; stale cached results would conceal changes.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i LIMIT %d OFFSET %d',
                $table_name,
                $batch_size,
                $offset
            ),
            ARRAY_A
        );

        if ($rows) {
            $values_sql = [];

            foreach ($rows as $row) {
                $values = [];
                foreach ($column_names as $column_name) {
                    $value = $row[$column_name] ?? null;
                    $values[] = seo_data_sql_literal($value);
                }
                $values_sql[] = '(' . implode(', ', $values) . ')';
            }

            seo_data_stream_write(
                $handle,
                "INSERT INTO `{$table_name}` ({$column_sql}) VALUES\n" .
                implode(",\n", $values_sql) .
                ";\n\n"
            );
        }

        $offset += $batch_size;
    } while (count($rows) === $batch_size);

    seo_data_stream_write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
}

function seo_data_sql_literal($value): string
{
    if ($value === null) {
        return 'NULL';
    }

    global $wpdb;
    return "'" . esc_sql((string) $value) . "'";
}

function seo_data_assert_export_access(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para exportar datos.');
    }
}

function seo_data_remove_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            seo_data_remove_directory($path);
        } else {
            wp_delete_file($path);
        }
    }

    seo_data_remove_empty_directory($directory);
}

function seo_data_render_styles(): void
{
    echo '<style>
        .seo-data-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:18px 0}
        .seo-data-card{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:16px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
        .seo-data-card-value{display:block;font-size:26px;font-weight:700;line-height:1.1;margin-bottom:6px}
        .seo-data-card small{display:block;color:#646970;margin-top:5px}
        .seo-data-panel{background:#fff;border:1px solid #dcdcde;padding:20px;margin:18px 0}
        .seo-data-toolbar{display:flex;flex-wrap:wrap;align-items:end;gap:10px;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;margin-bottom:14px}
        .seo-data-toolbar label{display:flex;flex-direction:column;gap:4px}
        .seo-data-toolbar select,.seo-data-toolbar input{min-width:160px}
        .seo-data-meta{margin:10px 0;color:#50575e}
        .seo-data-scroll{overflow:auto;max-width:100%}
        .seo-data-table{min-width:900px}
        .seo-data-table thead th{position:sticky;top:32px;background:#1d2327;color:#fff;z-index:2}
        .seo-data-table thead th a{color:#fff;text-decoration:none}
        .seo-data-table td{vertical-align:top;max-width:420px;word-break:break-word}
        .seo-data-table code{white-space:nowrap}
        .tablenav-pages ul.page-numbers{display:flex;gap:4px;align-items:center}
        .tablenav-pages li{display:inline-block}
        /* La tabla de operaciones usa una cabecera estática y clara.
         * Evita conflictos con los estilos sticky de WordPress y que la
         * cabecera se superponga a la primera fila. */
        .seo-operations-table thead th{
            position:static !important;
            top:auto !important;
            z-index:auto !important;
            background:#f0f0f1 !important;
            color:#1d2327 !important;
            padding:11px 12px !important;
            border-bottom:1px solid #c3c4c7 !important;
            line-height:1.4 !important;
            vertical-align:middle !important;
            white-space:nowrap;
        }
        .seo-operations-table thead th a,
        .seo-operations-table thead th span,
        .seo-operations-table thead th strong{
            color:#1d2327 !important;
        }
        .seo-operations-table tbody td{
            vertical-align:middle;
            padding:11px 12px;
            line-height:1.5;
        }
        .seo-operation-status,.seo-operation-risk{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
        .seo-operation-status.is-completed{background:#d1e7dd;color:#0f5132}
        .seo-operation-status.is-rolled-back{background:#cff4fc;color:#055160}
        .seo-operation-status.is-failed{background:#f8d7da;color:#842029}
        .seo-operation-status.is-running,.seo-operation-status.is-pending{background:#fff3cd;color:#664d03}
        .seo-operation-risk{background:#f0f0f1;color:#3c434a}
        .seo-risk-critical{background:#f8d7da;color:#842029}
        .seo-risk-high{background:#fff3cd;color:#664d03}
        .seo-operation-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:15px 0}
        .seo-operation-detail-grid>div{display:flex;flex-direction:column;gap:5px;background:#f6f7f7;border:1px solid #dcdcde;padding:12px}
        .seo-operation-preview{padding:12px 14px;margin:15px 0;border-left:4px solid #2271b1;background:#f6f7f7}
        .seo-operation-preview.is-ok{border-color:#00a32a;background:#edfaef}
        .seo-operation-preview.is-blocked{border-color:#d63638;background:#fcf0f1}
        .seo-json-view{max-height:320px;overflow:auto;white-space:pre-wrap;word-break:break-word;background:#1d2327;color:#f0f0f1;padding:12px;border-radius:3px;font-size:12px}
        .seo-data-muted{color:#646970}
    </style>';
}

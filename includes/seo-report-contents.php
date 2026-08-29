<?php
/**
 * Informe editorial de contenidos para SEO Reports.
 *
 * Vista de solo lectura. Centraliza cobertura y calidad objetiva de:
 * Cluster -> Hub Primary -> Hub Secondary -> Categorias -> Productos.
 *
 * Fuentes utilizadas:
 * - Clusters / hubs: wp_posts (excerpt/description) + wp_seo_nodes (etiquetas).
 * - Categorias: Vocabulary canonico (ROL/TIPO/APLICACION/PLATAFORMA/SUBTIPO) + wp_seo_nodes (excerpt/description) + termmeta seo_excerpt visible.
 * - Productos: wp_posts + Vocabulary semántico + vocabulario canónico de atributos de producto.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Comprueba si una tabla existe en la base de datos actual.
 */
function seo_report_contents_table_exists($table_name) {
    global $wpdb;

    $found = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $table_name)
    );

    return $found === $table_name;
}

/**
 * URL interna de la pestana Contenido.
 */
function seo_report_contents_url($args = array()) {
    $base = array(
        'page' => 'seo-reports',
        'tab'  => 'content',
    );

    return add_query_arg(
        array_merge($base, $args),
        admin_url('admin.php')
    );
}

/**
 * Etiqueta legible de un nivel.
 */
function seo_report_contents_level_label($level) {
    $labels = array(
        'cluster'       => 'Clusters',
        'hub_primary'   => 'Hubs primarios',
        'hub_secondary' => 'Hubs secundarios',
        'category'      => 'Categorias',
        'product'       => 'Productos',
    );

    return isset($labels[$level]) ? $labels[$level] : $level;
}

/**
 * Etiqueta legible de una incidencia.
 */
function seo_report_contents_issue_label($issue) {
    $labels = array(
        'missing_excerpt'       => 'Sin excerpt',
        'missing_description'   => 'Sin description',
        'missing_tags'          => 'Sin etiquetas',
        'missing_attributes'    => 'Sin atributos SEO',
        'duplicate_excerpt'     => 'Excerpt duplicado exacto',
        'duplicate_description' => 'Description duplicada exacta',
        'missing_visible_excerpt' => 'Sin excerpt visible',
        'excerpt_desync'        => 'Excerpt desincronizado',
        'attr_empty_value'      => 'Atributos con valor vacio',
        'attr_duplicate'        => 'Atributos exactos duplicados',
        'attr_unresolved_term'  => 'Atributos término sin resolver',
    );

    return isset($labels[$issue]) ? $labels[$issue] : $issue;
}

/**
 * Subconsulta de paginas activas de un rol SEO, una fila por object_id.
 */
function seo_report_contents_page_role_subquery($role) {
    global $wpdb;

    $nodes_table = $wpdb->prefix . 'seo_nodes';
    $role = esc_sql($role);

    return "
        SELECT
            object_id,
            MAX(NULLIF(TRIM(keywords), '')) AS etiquetas
        FROM {$nodes_table}
        WHERE object_type = 'page'
          AND seo_role = '{$role}'
          AND status = 1
        GROUP BY object_id
    ";
}

/**
 * Resumen objetivo de Cluster / Hub Primary / Hub Secondary.
 */
function seo_report_contents_get_page_role_summary($role) {
    global $wpdb;

    $role_subquery = seo_report_contents_page_role_subquery($role);

    $row = $wpdb->get_row("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN TRIM(COALESCE(p.post_excerpt, '')) = '' THEN 1 ELSE 0 END) AS missing_excerpt,
            SUM(CASE WHEN TRIM(COALESCE(p.post_content, '')) = '' THEN 1 ELSE 0 END) AS missing_description,
            SUM(CASE WHEN TRIM(COALESCE(n.etiquetas, '')) = '' THEN 1 ELSE 0 END) AS missing_tags,
            SUM(CASE
                WHEN TRIM(COALESCE(p.post_excerpt, '')) <> ''
                 AND TRIM(COALESCE(p.post_content, '')) <> ''
                 AND TRIM(COALESCE(n.etiquetas, '')) <> ''
                THEN 1 ELSE 0 END
            ) AS complete
        FROM ({$role_subquery}) n
        INNER JOIN {$wpdb->posts} p
            ON p.ID = n.object_id
           AND p.post_type = 'page'
           AND p.post_status <> 'trash'
    ", ARRAY_A);

    if (!is_array($row)) {
        $row = array();
    }

    return array(
        'total'                 => (int) ($row['total'] ?? 0),
        'complete'              => (int) ($row['complete'] ?? 0),
        'missing_excerpt'       => (int) ($row['missing_excerpt'] ?? 0),
        'missing_description'   => (int) ($row['missing_description'] ?? 0),
        'missing_tags'          => (int) ($row['missing_tags'] ?? 0),
        'missing_attributes'    => null,
        'duplicate_excerpt'     => seo_report_contents_get_page_duplicate_count($role, 'post_excerpt'),
        'duplicate_description' => seo_report_contents_get_page_duplicate_count($role, 'post_content'),
        'applicable_fields'     => 3,
    );
}

/**
 * Numero de paginas afectadas por duplicado exacto no vacio.
 */
function seo_report_contents_get_page_duplicate_count($role, $field) {
    global $wpdb;

    if (!in_array($field, array('post_excerpt', 'post_content'), true)) {
        return 0;
    }

    $role_subquery = seo_report_contents_page_role_subquery($role);

    return (int) $wpdb->get_var("
        SELECT COALESCE(SUM(d.total_group), 0)
        FROM (
            SELECT COUNT(*) AS total_group
            FROM ({$role_subquery}) n
            INNER JOIN {$wpdb->posts} p
                ON p.ID = n.object_id
               AND p.post_type = 'page'
               AND p.post_status <> 'trash'
            WHERE TRIM(COALESCE(p.{$field}, '')) <> ''
            GROUP BY MD5(TRIM(p.{$field}))
            HAVING COUNT(*) > 1
        ) d
    ");
}

/**
 * Subconsulta pivot del contenido editorial de categorias en seo_nodes.
 *
 * La semantica de categoria ya no vive aqui: solo excerpt y description.
 */
function seo_report_contents_category_nodes_subquery() {
    global $wpdb;

    $nodes_table = $wpdb->prefix . 'seo_nodes';

    return "
        SELECT
            object_id,
            MAX(CASE WHEN seo_role = 'excerpt' THEN NULLIF(TRIM(keywords), '') END) AS excerpt_node,
            MAX(CASE WHEN seo_role = 'description' THEN NULLIF(TRIM(keywords), '') END) AS description_node
        FROM {$nodes_table}
        WHERE object_type = 'category'
          AND status = 1
          AND seo_role IN ('excerpt', 'description')
        GROUP BY object_id
    ";
}

/**
 * Subconsulta de semantica canonica de categorias, una fila por product_cat.
 */
function seo_report_contents_category_tags_subquery() {
    global $wpdb;

    $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
    $object_table = $wpdb->prefix . 'seo_object_vocabulary';

    return "
        SELECT
            ov.object_id,
            GROUP_CONCAT(
                DISTINCT CONCAT(
                    CASE v.semantic_group
                        WHEN 'rol' THEN 'ROL'
                        WHEN 'tipo' THEN 'TIPO'
                        WHEN 'aplicacion' THEN 'APLICACION'
                        WHEN 'plataforma' THEN 'PLATAFORMA'
                        WHEN 'subtipo' THEN 'SUBTIPO'
                        ELSE UPPER(v.semantic_group)
                    END,
                    ': ',
                    v.label
                )
                ORDER BY FIELD(v.semantic_group, 'rol','tipo','aplicacion','plataforma','subtipo'), v.label
                SEPARATOR ' | '
            ) AS etiquetas
        FROM {$object_table} ov
        JOIN {$vocabulary_table} v
          ON v.id = ov.vocabulary_id
         AND v.active = 1
         AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
        WHERE ov.object_type = 'product_cat'
          AND ov.status = 1
        GROUP BY ov.object_id
    ";
}

/**
 * Subconsulta del excerpt visible almacenado en termmeta.
 */
function seo_report_contents_category_visible_excerpt_subquery() {
    global $wpdb;

    return "
        SELECT
            term_id,
            MAX(NULLIF(TRIM(meta_value), '')) AS visible_excerpt
        FROM {$wpdb->termmeta}
        WHERE meta_key = 'seo_excerpt'
        GROUP BY term_id
    ";
}

/**
 * Resumen de categorias.
 */
function seo_report_contents_get_category_summary() {
    global $wpdb;

    $node_subquery = seo_report_contents_category_nodes_subquery();
    $tags_subquery = seo_report_contents_category_tags_subquery();
    $visible_subquery = seo_report_contents_category_visible_excerpt_subquery();

    $row = $wpdb->get_row("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN TRIM(COALESCE(n.excerpt_node, '')) = '' THEN 1 ELSE 0 END) AS missing_excerpt,
            SUM(CASE WHEN TRIM(COALESCE(n.description_node, '')) = '' THEN 1 ELSE 0 END) AS missing_description,
            SUM(CASE WHEN TRIM(COALESCE(s.etiquetas, '')) = '' THEN 1 ELSE 0 END) AS missing_tags,
            SUM(CASE WHEN TRIM(COALESCE(v.visible_excerpt, '')) = '' THEN 1 ELSE 0 END) AS missing_visible_excerpt,
            SUM(CASE
                WHEN TRIM(COALESCE(n.excerpt_node, '')) <> TRIM(COALESCE(v.visible_excerpt, ''))
                THEN 1 ELSE 0 END
            ) AS excerpt_desync,
            SUM(CASE
                WHEN TRIM(COALESCE(n.excerpt_node, '')) <> ''
                 AND TRIM(COALESCE(n.description_node, '')) <> ''
                 AND TRIM(COALESCE(s.etiquetas, '')) <> ''
                THEN 1 ELSE 0 END
            ) AS complete
        FROM {$wpdb->term_taxonomy} tt
        INNER JOIN {$wpdb->terms} t
            ON t.term_id = tt.term_id
        LEFT JOIN ({$node_subquery}) n
            ON n.object_id = tt.term_id
        LEFT JOIN ({$tags_subquery}) s
            ON s.object_id = tt.term_id
        LEFT JOIN ({$visible_subquery}) v
            ON v.term_id = tt.term_id
        WHERE tt.taxonomy = 'product_cat'
    ", ARRAY_A);

    if (!is_array($row)) {
        $row = array();
    }

    return array(
        'total'                   => (int) ($row['total'] ?? 0),
        'complete'                => (int) ($row['complete'] ?? 0),
        'missing_excerpt'         => (int) ($row['missing_excerpt'] ?? 0),
        'missing_description'     => (int) ($row['missing_description'] ?? 0),
        'missing_tags'            => (int) ($row['missing_tags'] ?? 0),
        'missing_attributes'      => null,
        'duplicate_excerpt'       => seo_report_contents_get_category_duplicate_count('excerpt'),
        'duplicate_description'   => seo_report_contents_get_category_duplicate_count('description'),
        'missing_visible_excerpt' => (int) ($row['missing_visible_excerpt'] ?? 0),
        'excerpt_desync'          => (int) ($row['excerpt_desync'] ?? 0),
        'applicable_fields'       => 3,
    );
}

/**
 * Numero de categorias afectadas por duplicado exacto no vacio en seo_nodes.
 */
function seo_report_contents_get_category_duplicate_count($content_role) {
    global $wpdb;

    $seo_role = $content_role === 'excerpt' ? 'excerpt' : 'description';
    $nodes_table = $wpdb->prefix . 'seo_nodes';

    return (int) $wpdb->get_var($wpdb->prepare("
        SELECT COALESCE(SUM(d.total_group), 0)
        FROM (
            SELECT COUNT(DISTINCT n.object_id) AS total_group
            FROM {$nodes_table} n
            INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_id = n.object_id
               AND tt.taxonomy = 'product_cat'
            WHERE n.object_type = 'category'
              AND n.seo_role = %s
              AND n.status = 1
              AND TRIM(COALESCE(n.keywords, '')) <> ''
            GROUP BY MD5(TRIM(n.keywords))
            HAVING COUNT(DISTINCT n.object_id) > 1
        ) d
    ", $seo_role));
}

/**
 * Subconsulta de etiquetas de producto, una fila por producto.
 */
function seo_report_contents_product_tags_subquery() {
    global $wpdb;

    $vocabulary_table = $wpdb->prefix . 'seo_vocabulary';
    $object_table = $wpdb->prefix . 'seo_object_vocabulary';

    return "
        SELECT
            ov.object_id,
            GROUP_CONCAT(DISTINCT v.label ORDER BY FIELD(v.semantic_group, 'tipo','aplicacion','plataforma','subtipo'), v.label SEPARATOR ', ') AS etiquetas
        FROM {$object_table} ov
        JOIN {$vocabulary_table} v
          ON v.id = ov.vocabulary_id
         AND v.active = 1
         AND v.semantic_group IN ('tipo','aplicacion','plataforma','subtipo')
        WHERE ov.object_type = 'product'
          AND ov.status = 1
        GROUP BY ov.object_id
    ";
}

/**
 * Subconsulta de asignaciones de atributos, una fila por producto.
 */
function seo_report_contents_product_attributes_subquery() {
    global $wpdb;

    if (!function_exists('seo_attributes_tables')) {
        return false;
    }
    $tables = seo_attributes_tables();
    foreach ($tables as $table) {
        if (!seo_report_contents_table_exists($table)) {
            return false;
        }
    }

    return "
        SELECT product_id, COUNT(*) AS attribute_count
        FROM {$tables['values']}
        WHERE product_id > 0
        GROUP BY product_id
    ";
}

/**
 * Resumen de productos publicados.
 */
function seo_report_contents_get_product_summary() {
    global $wpdb;

    $tags_subquery = seo_report_contents_product_tags_subquery();
    $attrs_subquery = seo_report_contents_product_attributes_subquery();
    $attrs_available = $attrs_subquery !== false;

    $attrs_join = $attrs_available
        ? "LEFT JOIN ({$attrs_subquery}) a ON a.product_id = p.ID"
        : '';

    $missing_attrs_sql = $attrs_available
        ? "SUM(CASE WHEN COALESCE(a.attribute_count, 0) = 0 THEN 1 ELSE 0 END)"
        : '0';

    $complete_attrs_sql = $attrs_available
        ? "AND COALESCE(a.attribute_count, 0) > 0"
        : '';

    $row = $wpdb->get_row("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN TRIM(COALESCE(p.post_excerpt, '')) = '' THEN 1 ELSE 0 END) AS missing_excerpt,
            SUM(CASE WHEN TRIM(COALESCE(p.post_content, '')) = '' THEN 1 ELSE 0 END) AS missing_description,
            SUM(CASE WHEN TRIM(COALESCE(n.etiquetas, '')) = '' THEN 1 ELSE 0 END) AS missing_tags,
            {$missing_attrs_sql} AS missing_attributes,
            SUM(CASE
                WHEN TRIM(COALESCE(p.post_excerpt, '')) <> ''
                 AND TRIM(COALESCE(p.post_content, '')) <> ''
                 AND TRIM(COALESCE(n.etiquetas, '')) <> ''
                 {$complete_attrs_sql}
                THEN 1 ELSE 0 END
            ) AS complete
        FROM {$wpdb->posts} p
        LEFT JOIN ({$tags_subquery}) n
            ON n.object_id = p.ID
        {$attrs_join}
        WHERE p.post_type = 'product'
          AND p.post_status = 'publish'
    ", ARRAY_A);

    if (!is_array($row)) {
        $row = array();
    }

    return array(
        'total'                 => (int) ($row['total'] ?? 0),
        'complete'              => (int) ($row['complete'] ?? 0),
        'missing_excerpt'       => (int) ($row['missing_excerpt'] ?? 0),
        'missing_description'   => (int) ($row['missing_description'] ?? 0),
        'missing_tags'          => (int) ($row['missing_tags'] ?? 0),
        'missing_attributes'    => $attrs_available ? (int) ($row['missing_attributes'] ?? 0) : null,
        'duplicate_excerpt'     => seo_report_contents_get_product_duplicate_count('post_excerpt'),
        'duplicate_description' => seo_report_contents_get_product_duplicate_count('post_content'),
        'applicable_fields'     => $attrs_available ? 4 : 3,
        'attributes_available'  => $attrs_available,
    );
}

/**
 * Numero de productos publicados afectados por duplicado exacto no vacio.
 */
function seo_report_contents_get_product_duplicate_count($field) {
    global $wpdb;

    if (!in_array($field, array('post_excerpt', 'post_content'), true)) {
        return 0;
    }

    return (int) $wpdb->get_var("
        SELECT COALESCE(SUM(d.total_group), 0)
        FROM (
            SELECT COUNT(*) AS total_group
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'product'
              AND p.post_status = 'publish'
              AND TRIM(COALESCE(p.{$field}, '')) <> ''
            GROUP BY MD5(TRIM(p.{$field}))
            HAVING COUNT(*) > 1
        ) d
    ");
}

/**
 * Estado objetivo del vocabulario canónico de atributos de producto.
 */
function seo_report_contents_get_attribute_summary() {
    global $wpdb;

    $summary = array(
        'available'       => false,
        'assignments'     => 0,
        'master_rows'     => 0,
        'products_with'   => 0,
        'empty_value'     => 0,
        'duplicates'      => 0,
        'unresolved_term' => 0,
    );

    if (!function_exists('seo_attributes_tables')) {
        return $summary;
    }
    $tables = seo_attributes_tables();
    foreach ($tables as $table) {
        if (!seo_report_contents_table_exists($table)) {
            return $summary;
        }
    }

    $values = $tables['values'];
    $definitions = $tables['definitions'];
    $summary['available'] = true;
    $summary['assignments'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$values} WHERE product_id > 0");
    $summary['master_rows'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$definitions} WHERE activo = 1");
    $summary['products_with'] = (int) $wpdb->get_var("
        SELECT COUNT(DISTINCT pa.product_id)
        FROM {$values} pa
        INNER JOIN {$wpdb->posts} p
            ON p.ID = pa.product_id
           AND p.post_type = 'product'
           AND p.post_status = 'publish'
        WHERE pa.product_id > 0
    ");
    $summary['empty_value'] = (int) $wpdb->get_var("
        SELECT COUNT(*)
        FROM {$values} pa
        INNER JOIN {$definitions} a ON a.id = pa.atributo_id
        WHERE pa.product_id > 0
          AND (
              (a.tipo = 'termino' AND pa.termino_id IS NULL)
              OR (
                  a.tipo <> 'termino'
                  AND pa.termino_id IS NULL
                  AND pa.valor_numero IS NULL
                  AND TRIM(COALESCE(pa.valor_texto, pa.valor_original, '')) = ''
              )
          )
    ");
    $summary['unresolved_term'] = (int) $wpdb->get_var("
        SELECT COUNT(*)
        FROM {$values} pa
        INNER JOIN {$definitions} a ON a.id = pa.atributo_id
        WHERE pa.product_id > 0
          AND a.tipo = 'termino'
          AND pa.termino_id IS NULL
    ");
    $summary['duplicates'] = (int) $wpdb->get_var("
        SELECT COALESCE(SUM(d.extra_rows), 0)
        FROM (
            SELECT COUNT(*) - 1 AS extra_rows
            FROM {$values}
            WHERE product_id > 0
            GROUP BY product_id, atributo_id,
                     COALESCE(termino_id, 0),
                     COALESCE(valor_texto, ''),
                     COALESCE(valor_numero, -999999999999.999999),
                     COALESCE(valor_numero_max, -999999999999.999999),
                     COALESCE(unidad, '')
            HAVING COUNT(*) > 1
        ) d
    ");

    return $summary;
}

/**
 * Cobertura porcentual de campos aplicables.
 */
function seo_report_contents_coverage_percent($summary) {
    $total = (int) ($summary['total'] ?? 0);
    $fields = (int) ($summary['applicable_fields'] ?? 0);

    if ($total <= 0 || $fields <= 0) {
        return 100.0;
    }

    $missing = (int) ($summary['missing_excerpt'] ?? 0)
        + (int) ($summary['missing_description'] ?? 0)
        + (int) ($summary['missing_tags'] ?? 0);

    if ($fields >= 4 && $summary['missing_attributes'] !== null) {
        $missing += (int) $summary['missing_attributes'];
    }

    $possible = $total * $fields;
    $present = max(0, $possible - $missing);

    return round(($present / $possible) * 100, 1);
}

/**
 * Enlace de contador para ver afectados.
 */
function seo_report_contents_render_count_link($count, $level, $issue) {
    if ($count === null) {
        echo '<span style="color:#8c8f94;">No aplica</span>';
        return;
    }

    $count = (int) $count;

    if ($count <= 0) {
        echo '<strong style="color:#2e7d32;">0</strong>';
        return;
    }

    $url = seo_report_contents_url(array(
        'content_level' => $level,
        'content_issue' => $issue,
    ));

    echo '<a href="' . esc_url($url) . '" style="font-weight:600;color:#b32d2e;">'
        . esc_html(number_format_i18n($count))
        . '</a>';
}

/**
 * Tabla principal de cobertura por nivel.
 */
function seo_report_contents_render_summary_table($summaries) {
    echo '<h2 style="margin-top:28px;">Cobertura de contenido por nivel</h2>';
    echo '<p>Los contadores son objetivos y cada enlace abre los elementos afectados. En productos se analizan los publicados; los atributos solo aplican a producto.</p>';

    echo '<div style="overflow-x:auto;">';
    echo '<table class="widefat striped" style="min-width:1050px;">';
    echo '<thead><tr>';
    echo '<th>Nivel</th>';
    echo '<th>Total</th>';
    echo '<th>Completos</th>';
    echo '<th>Cobertura</th>';
    echo '<th>Sin excerpt</th>';
    echo '<th>Sin description</th>';
    echo '<th>Sin etiquetas</th>';
    echo '<th>Sin atributos</th>';
    echo '<th>Excerpt duplicado</th>';
    echo '<th>Description duplicada</th>';
    echo '</tr></thead><tbody>';

    foreach ($summaries as $level => $summary) {
        $coverage = seo_report_contents_coverage_percent($summary);
        $coverage_color = $coverage >= 95 ? '#2e7d32' : ($coverage >= 80 ? '#b57d00' : '#b32d2e');

        echo '<tr>';
        echo '<td><strong>' . esc_html(seo_report_contents_level_label($level)) . '</strong></td>';
        echo '<td>' . esc_html(number_format_i18n((int) $summary['total'])) . '</td>';
        echo '<td><strong>' . esc_html(number_format_i18n((int) $summary['complete'])) . '</strong></td>';
        echo '<td><strong style="color:' . esc_attr($coverage_color) . ';">' . esc_html(number_format_i18n($coverage, 1)) . '%</strong></td>';

        echo '<td>';
        seo_report_contents_render_count_link($summary['missing_excerpt'], $level, 'missing_excerpt');
        echo '</td>';

        echo '<td>';
        seo_report_contents_render_count_link($summary['missing_description'], $level, 'missing_description');
        echo '</td>';

        echo '<td>';
        seo_report_contents_render_count_link($summary['missing_tags'], $level, 'missing_tags');
        echo '</td>';

        echo '<td>';
        seo_report_contents_render_count_link($summary['missing_attributes'], $level, 'missing_attributes');
        echo '</td>';

        echo '<td>';
        seo_report_contents_render_count_link($summary['duplicate_excerpt'], $level, 'duplicate_excerpt');
        echo '</td>';

        echo '<td>';
        seo_report_contents_render_count_link($summary['duplicate_description'], $level, 'duplicate_description');
        echo '</td>';

        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Incidencias especificas de categorias.
 */
function seo_report_contents_render_category_integrity($summary) {
    echo '<h2 style="margin-top:30px;">Categorias: fuente interna y excerpt visible</h2>';
    echo '<p>Se compara el excerpt editorial de <code>wp_seo_nodes</code> con el meta <code>seo_excerpt</code> que puede consumir la plantilla publica.</p>';

    echo '<table class="widefat striped" style="max-width:900px;">';
    echo '<thead><tr><th>Chequeo</th><th>Afectadas</th><th>Lectura</th></tr></thead><tbody>';

    echo '<tr><td><strong>Sin excerpt visible</strong></td><td>';
    seo_report_contents_render_count_link($summary['missing_visible_excerpt'], 'category', 'missing_visible_excerpt');
    echo '</td><td>El termmeta <code>seo_excerpt</code> esta vacio o ausente.</td></tr>';

    echo '<tr><td><strong>Excerpt desincronizado</strong></td><td>';
    seo_report_contents_render_count_link($summary['excerpt_desync'], 'category', 'excerpt_desync');
    echo '</td><td>El excerpt activo de <code>seo_nodes</code> no coincide exactamente con <code>seo_excerpt</code>.</td></tr>';

    echo '</tbody></table>';
}

/**
 * Panel de atributos de productos.
 */
function seo_report_contents_render_attribute_panel($summary, $product_summary) {
    echo '<h2 style="margin-top:30px;">Atributos técnicos de producto</h2>';

    if (empty($summary['available'])) {
        echo '<div class="notice notice-warning inline"><p>No se encuentran las tablas del vocabulario canónico de atributos; el informe no puede evaluarlos.</p></div>';
        return;
    }

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:14px 0 18px;">';
    $cards = array(
        array('Asignaciones', $summary['assignments'], 'Filas en wp_sql_product_atributos.'),
        array('Definiciones activas', $summary['master_rows'], 'Atributos activos en wp_sql_atributos.'),
        array('Productos con atributos', $summary['products_with'], 'Productos publicados con al menos una asignación.'),
        array('Productos sin atributos', $product_summary['missing_attributes'], 'Productos publicados sin asignaciones técnicas.'),
        array('Valores vacíos', $summary['empty_value'], 'Asignaciones sin un valor canónico utilizable.'),
        array('Duplicados exactos', $summary['duplicates'], 'Copias sobrantes de la misma asignación canónica.'),
        array('Términos sin resolver', $summary['unresolved_term'], 'Atributos de tipo término sin termino_id.'),
    );

    foreach ($cards as $card) {
        $value = (int) $card[1];
        $color = $value > 0 && in_array($card[0], array('Productos sin atributos', 'Valores vacíos', 'Duplicados exactos', 'Términos sin resolver'), true)
            ? '#b32d2e' : '#1d2327';
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px;">';
        echo '<div style="font-size:12px;color:#646970;">' . esc_html($card[0]) . '</div>';
        echo '<div style="font-size:24px;font-weight:700;color:' . esc_attr($color) . ';margin:4px 0;">' . esc_html(number_format_i18n($value)) . '</div>';
        echo '<div style="font-size:12px;color:#646970;">' . esc_html($card[2]) . '</div>';
        echo '</div>';
    }
    echo '</div>';

    echo '<p>';
    if ((int) $summary['empty_value'] > 0) {
        echo '<a class="button button-secondary" href="' . esc_url(seo_report_contents_url(array('content_level' => 'product', 'content_issue' => 'attr_empty_value'))) . '">Ver valores vacíos</a> ';
    }
    if ((int) $summary['duplicates'] > 0) {
        echo '<a class="button button-secondary" href="' . esc_url(seo_report_contents_url(array('content_level' => 'product', 'content_issue' => 'attr_duplicate'))) . '">Ver duplicados</a> ';
    }
    if ((int) $summary['unresolved_term'] > 0) {
        echo '<a class="button button-secondary" href="' . esc_url(seo_report_contents_url(array('content_level' => 'product', 'content_issue' => 'attr_unresolved_term'))) . '">Ver términos sin resolver</a>';
    }
    echo '</p>';
}

/**
 * Calcula el total ponderado de cobertura.
 */
function seo_report_contents_overall_coverage($summaries) {
    $possible = 0;
    $missing = 0;

    foreach ($summaries as $summary) {
        $total = (int) ($summary['total'] ?? 0);
        $fields = (int) ($summary['applicable_fields'] ?? 0);

        if ($total <= 0 || $fields <= 0) {
            continue;
        }

        $possible += $total * $fields;
        $missing += (int) ($summary['missing_excerpt'] ?? 0);
        $missing += (int) ($summary['missing_description'] ?? 0);
        $missing += (int) ($summary['missing_tags'] ?? 0);

        if ($fields >= 4 && $summary['missing_attributes'] !== null) {
            $missing += (int) $summary['missing_attributes'];
        }
    }

    if ($possible <= 0) {
        return 100.0;
    }

    return round((max(0, $possible - $missing) / $possible) * 100, 1);
}

/**
 * Datos de detalle para una incidencia.
 */
function seo_report_contents_get_affected_rows($level, $issue, $limit, $offset) {
    global $wpdb;

    $result = array(
        'total' => 0,
        'rows'  => array(),
    );

    $limit = max(1, min(100, (int) $limit));
    $offset = max(0, (int) $offset);

    if (in_array($level, array('cluster', 'hub_primary', 'hub_secondary'), true)) {
        $role_subquery = seo_report_contents_page_role_subquery($level);

        $where = '';
        if ($issue === 'missing_excerpt') {
            $where = "TRIM(COALESCE(p.post_excerpt, '')) = ''";
        } elseif ($issue === 'missing_description') {
            $where = "TRIM(COALESCE(p.post_content, '')) = ''";
        } elseif ($issue === 'missing_tags') {
            $where = "TRIM(COALESCE(n.etiquetas, '')) = ''";
        } elseif ($issue === 'duplicate_excerpt') {
            $where = "TRIM(COALESCE(p.post_excerpt, '')) <> '' AND MD5(TRIM(p.post_excerpt)) IN (
                SELECT sig FROM (
                    SELECT MD5(TRIM(p2.post_excerpt)) AS sig
                    FROM ({$role_subquery}) n2
                    INNER JOIN {$wpdb->posts} p2 ON p2.ID = n2.object_id
                    WHERE p2.post_type = 'page' AND p2.post_status <> 'trash'
                      AND TRIM(COALESCE(p2.post_excerpt, '')) <> ''
                    GROUP BY MD5(TRIM(p2.post_excerpt))
                    HAVING COUNT(*) > 1
                ) x
            )";
        } elseif ($issue === 'duplicate_description') {
            $where = "TRIM(COALESCE(p.post_content, '')) <> '' AND MD5(TRIM(p.post_content)) IN (
                SELECT sig FROM (
                    SELECT MD5(TRIM(p2.post_content)) AS sig
                    FROM ({$role_subquery}) n2
                    INNER JOIN {$wpdb->posts} p2 ON p2.ID = n2.object_id
                    WHERE p2.post_type = 'page' AND p2.post_status <> 'trash'
                      AND TRIM(COALESCE(p2.post_content, '')) <> ''
                    GROUP BY MD5(TRIM(p2.post_content))
                    HAVING COUNT(*) > 1
                ) x
            )";
        } else {
            return $result;
        }

        $base_from = "
            FROM ({$role_subquery}) n
            INNER JOIN {$wpdb->posts} p
                ON p.ID = n.object_id
               AND p.post_type = 'page'
               AND p.post_status <> 'trash'
            WHERE {$where}
        ";

        $result['total'] = (int) $wpdb->get_var("SELECT COUNT(*) {$base_from}");
        $result['rows'] = $wpdb->get_results($wpdb->prepare("
            SELECT
                p.ID AS object_id,
                p.post_title AS title,
                p.post_status AS status,
                p.post_excerpt AS excerpt_value,
                p.post_content AS description_value,
                n.etiquetas AS tags_value
            {$base_from}
            ORDER BY p.post_title ASC, p.ID ASC
            LIMIT %d OFFSET %d
        ", $limit, $offset), ARRAY_A);

        return $result;
    }

    if ($level === 'category') {
        $node_subquery = seo_report_contents_category_nodes_subquery();
        $tags_subquery = seo_report_contents_category_tags_subquery();
        $visible_subquery = seo_report_contents_category_visible_excerpt_subquery();

        $where = '';
        if ($issue === 'missing_excerpt') {
            $where = "TRIM(COALESCE(n.excerpt_node, '')) = ''";
        } elseif ($issue === 'missing_description') {
            $where = "TRIM(COALESCE(n.description_node, '')) = ''";
        } elseif ($issue === 'missing_tags') {
            $where = "TRIM(COALESCE(s.etiquetas, '')) = ''";
        } elseif ($issue === 'missing_visible_excerpt') {
            $where = "TRIM(COALESCE(v.visible_excerpt, '')) = ''";
        } elseif ($issue === 'excerpt_desync') {
            $where = "TRIM(COALESCE(n.excerpt_node, '')) <> TRIM(COALESCE(v.visible_excerpt, ''))";
        } elseif ($issue === 'duplicate_excerpt') {
            $where = "TRIM(COALESCE(n.excerpt_node, '')) <> '' AND MD5(TRIM(n.excerpt_node)) IN (
                SELECT sig FROM (
                    SELECT MD5(TRIM(n2.keywords)) AS sig
                    FROM {$wpdb->prefix}seo_nodes n2
                    INNER JOIN {$wpdb->term_taxonomy} tt2
                        ON tt2.term_id = n2.object_id AND tt2.taxonomy = 'product_cat'
                    WHERE n2.object_type = 'category'
                      AND n2.seo_role = 'excerpt'
                      AND n2.status = 1
                      AND TRIM(COALESCE(n2.keywords, '')) <> ''
                    GROUP BY MD5(TRIM(n2.keywords))
                    HAVING COUNT(DISTINCT n2.object_id) > 1
                ) x
            )";
        } elseif ($issue === 'duplicate_description') {
            $where = "TRIM(COALESCE(n.description_node, '')) <> '' AND MD5(TRIM(n.description_node)) IN (
                SELECT sig FROM (
                    SELECT MD5(TRIM(n2.keywords)) AS sig
                    FROM {$wpdb->prefix}seo_nodes n2
                    INNER JOIN {$wpdb->term_taxonomy} tt2
                        ON tt2.term_id = n2.object_id AND tt2.taxonomy = 'product_cat'
                    WHERE n2.object_type = 'category'
                      AND n2.seo_role = 'description'
                      AND n2.status = 1
                      AND TRIM(COALESCE(n2.keywords, '')) <> ''
                    GROUP BY MD5(TRIM(n2.keywords))
                    HAVING COUNT(DISTINCT n2.object_id) > 1
                ) x
            )";
        } else {
            return $result;
        }

        $base_from = "
            FROM {$wpdb->term_taxonomy} tt
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            LEFT JOIN ({$node_subquery}) n ON n.object_id = tt.term_id
            LEFT JOIN ({$tags_subquery}) s ON s.object_id = tt.term_id
            LEFT JOIN ({$visible_subquery}) v ON v.term_id = tt.term_id
            WHERE tt.taxonomy = 'product_cat'
              AND {$where}
        ";

        $result['total'] = (int) $wpdb->get_var("SELECT COUNT(*) {$base_from}");
        $result['rows'] = $wpdb->get_results($wpdb->prepare("
            SELECT
                tt.term_id AS object_id,
                t.name AS title,
                'product_cat' AS status,
                n.excerpt_node AS excerpt_value,
                n.description_node AS description_value,
                s.etiquetas AS tags_value,
                v.visible_excerpt AS visible_excerpt
            {$base_from}
            ORDER BY t.name ASC, tt.term_id ASC
            LIMIT %d OFFSET %d
        ", $limit, $offset), ARRAY_A);

        return $result;
    }

    if ($level === 'product') {
        $tags_subquery = seo_report_contents_product_tags_subquery();
        $attrs_subquery = seo_report_contents_product_attributes_subquery();

        if (in_array($issue, array('attr_empty_value', 'attr_duplicate', 'attr_unresolved_term'), true)) {
            if (!function_exists('seo_attributes_tables')) {
                return $result;
            }
            $attribute_tables = seo_attributes_tables();
            foreach ($attribute_tables as $attribute_table) {
                if (!seo_report_contents_table_exists($attribute_table)) {
                    return $result;
                }
            }

            $values_table = $attribute_tables['values'];
            $definitions_table = $attribute_tables['definitions'];
            $terms_table = $attribute_tables['terms'];
            $display_value = "COALESCE(t.nombre, NULLIF(pa.valor_texto, ''), NULLIF(pa.valor_original, ''), CONCAT(CAST(pa.valor_numero AS CHAR), IF(pa.valor_numero_max IS NULL, '', CONCAT(' - ', CAST(pa.valor_numero_max AS CHAR))), IF(TRIM(COALESCE(pa.unidad,''))='', '', CONCAT(' ', pa.unidad))))";

            if ($issue === 'attr_empty_value') {
                $where = "pa.product_id > 0 AND ((a.tipo='termino' AND pa.termino_id IS NULL) OR (a.tipo<>'termino' AND pa.termino_id IS NULL AND pa.valor_numero IS NULL AND TRIM(COALESCE(pa.valor_texto,pa.valor_original,''))=''))";
            } elseif ($issue === 'attr_unresolved_term') {
                $where = "pa.product_id > 0 AND a.tipo='termino' AND pa.termino_id IS NULL";
            } else {
                $where = 'pa.product_id > 0';
            }

            if ($issue === 'attr_duplicate') {
                $result['total'] = (int) $wpdb->get_var("
                    SELECT COUNT(*) FROM (
                        SELECT pa.product_id, pa.atributo_id, COALESCE(pa.termino_id,0) AS termino_key,
                               COALESCE(pa.valor_texto,'') AS texto_key,
                               COALESCE(pa.valor_numero,-999999999999.999999) AS numero_key,
                               COALESCE(pa.valor_numero_max,-999999999999.999999) AS numero_max_key,
                               COALESCE(pa.unidad,'') AS unidad_key
                        FROM {$values_table} pa
                        WHERE pa.product_id > 0
                        GROUP BY pa.product_id, pa.atributo_id, termino_key, texto_key, numero_key, numero_max_key, unidad_key
                        HAVING COUNT(*) > 1
                    ) d
                ");

                $result['rows'] = $wpdb->get_results($wpdb->prepare("
                    SELECT pa.product_id AS object_id, p.post_title AS title, p.post_status AS status,
                           'global' AS ambito, a.slug AS attribute_type, {$display_value} AS attribute_value,
                           COUNT(*) AS duplicate_count
                    FROM {$values_table} pa
                    INNER JOIN {$definitions_table} a ON a.id = pa.atributo_id
                    LEFT JOIN {$terms_table} t ON t.id = pa.termino_id
                    LEFT JOIN {$wpdb->posts} p ON p.ID = pa.product_id
                    WHERE pa.product_id > 0
                    GROUP BY pa.product_id, p.post_title, p.post_status, a.slug, pa.atributo_id,
                             COALESCE(pa.termino_id,0), COALESCE(pa.valor_texto,''),
                             COALESCE(pa.valor_numero,-999999999999.999999),
                             COALESCE(pa.valor_numero_max,-999999999999.999999), COALESCE(pa.unidad,''), {$display_value}
                    HAVING COUNT(*) > 1
                    ORDER BY duplicate_count DESC, pa.product_id ASC
                    LIMIT %d OFFSET %d
                ", $limit, $offset), ARRAY_A);
            } else {
                $result['total'] = (int) $wpdb->get_var("
                    SELECT COUNT(*)
                    FROM {$values_table} pa
                    INNER JOIN {$definitions_table} a ON a.id = pa.atributo_id
                    WHERE {$where}
                ");
                $result['rows'] = $wpdb->get_results($wpdb->prepare("
                    SELECT pa.product_id AS object_id, p.post_title AS title, p.post_status AS status,
                           'global' AS ambito, a.slug AS attribute_type, {$display_value} AS attribute_value
                    FROM {$values_table} pa
                    INNER JOIN {$definitions_table} a ON a.id = pa.atributo_id
                    LEFT JOIN {$terms_table} t ON t.id = pa.termino_id
                    LEFT JOIN {$wpdb->posts} p ON p.ID = pa.product_id
                    WHERE {$where}
                    ORDER BY pa.product_id ASC, pa.id ASC
                    LIMIT %d OFFSET %d
                ", $limit, $offset), ARRAY_A);
            }

            return $result;
        }

        $attrs_join = $attrs_subquery !== false
            ? "LEFT JOIN ({$attrs_subquery}) a ON a.product_id = p.ID"
            : '';

        $where = '';
        if ($issue === 'missing_excerpt') {
            $where = "TRIM(COALESCE(p.post_excerpt, '')) = ''";
        } elseif ($issue === 'missing_description') {
            $where = "TRIM(COALESCE(p.post_content, '')) = ''";
        } elseif ($issue === 'missing_tags') {
            $where = "TRIM(COALESCE(n.etiquetas, '')) = ''";
        } elseif ($issue === 'missing_attributes' && $attrs_subquery !== false) {
            $where = 'COALESCE(a.attribute_count, 0) = 0';
        } elseif ($issue === 'duplicate_excerpt') {
            $where = "TRIM(COALESCE(p.post_excerpt, '')) <> '' AND MD5(TRIM(p.post_excerpt)) IN (
                SELECT sig FROM (
                    SELECT MD5(TRIM(p2.post_excerpt)) AS sig
                    FROM {$wpdb->posts} p2
                    WHERE p2.post_type = 'product'
                      AND p2.post_status = 'publish'
                      AND TRIM(COALESCE(p2.post_excerpt, '')) <> ''
                    GROUP BY MD5(TRIM(p2.post_excerpt))
                    HAVING COUNT(*) > 1
                ) x
            )";
        } elseif ($issue === 'duplicate_description') {
            $where = "TRIM(COALESCE(p.post_content, '')) <> '' AND MD5(TRIM(p.post_content)) IN (
                SELECT sig FROM (
                    SELECT MD5(TRIM(p2.post_content)) AS sig
                    FROM {$wpdb->posts} p2
                    WHERE p2.post_type = 'product'
                      AND p2.post_status = 'publish'
                      AND TRIM(COALESCE(p2.post_content, '')) <> ''
                    GROUP BY MD5(TRIM(p2.post_content))
                    HAVING COUNT(*) > 1
                ) x
            )";
        } else {
            return $result;
        }

        $base_from = "
            FROM {$wpdb->posts} p
            LEFT JOIN ({$tags_subquery}) n ON n.object_id = p.ID
            {$attrs_join}
            WHERE p.post_type = 'product'
              AND p.post_status = 'publish'
              AND {$where}
        ";

        $result['total'] = (int) $wpdb->get_var("SELECT COUNT(*) {$base_from}");
        $result['rows'] = $wpdb->get_results($wpdb->prepare("
            SELECT
                p.ID AS object_id,
                p.post_title AS title,
                p.post_status AS status,
                p.post_excerpt AS excerpt_value,
                p.post_content AS description_value,
                n.etiquetas AS tags_value,
                " . ($attrs_subquery !== false ? 'COALESCE(a.attribute_count, 0)' : 'NULL') . " AS attribute_count
            {$base_from}
            ORDER BY p.post_title ASC, p.ID ASC
            LIMIT %d OFFSET %d
        ", $limit, $offset), ARRAY_A);

        return $result;
    }

    return $result;
}

/**
 * Render del detalle seleccionado.
 */
function seo_report_contents_render_affected_detail($level, $issue) {
    $allowed_levels = array('cluster', 'hub_primary', 'hub_secondary', 'category', 'product');
    $allowed_issues = array(
        'missing_excerpt',
        'missing_description',
        'missing_tags',
        'missing_attributes',
        'duplicate_excerpt',
        'duplicate_description',
        'missing_visible_excerpt',
        'excerpt_desync',
        'attr_empty_value',
        'attr_duplicate',
        'attr_unresolved_term',
    );

    if (!in_array($level, $allowed_levels, true) || !in_array($issue, $allowed_issues, true)) {
        return;
    }

    $paged = isset($_GET['content_paged']) ? max(1, absint($_GET['content_paged'])) : 1;
    $per_page = 50;
    $offset = ($paged - 1) * $per_page;
    $data = seo_report_contents_get_affected_rows($level, $issue, $per_page, $offset);

    echo '<div id="seo-content-detail" style="margin-top:32px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:16px;">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
    echo '<h2 style="margin:0;">' . esc_html(seo_report_contents_level_label($level)) . ' · ' . esc_html(seo_report_contents_issue_label($issue)) . '</h2>';
    echo '<a class="button" href="' . esc_url(seo_report_contents_url()) . '">Cerrar detalle</a>';
    echo '</div>';
    echo '<p>Afectados: <strong>' . esc_html(number_format_i18n((int) $data['total'])) . '</strong>. Se muestran 50 por pagina.</p>';

    if (empty($data['rows'])) {
        echo '<p style="color:#2e7d32;">No hay filas que mostrar para este chequeo.</p>';
        echo '</div>';
        return;
    }

    echo '<div style="overflow-x:auto;">';
    echo '<table class="widefat striped" style="min-width:920px;">';

    if (in_array($issue, array('attr_empty_value', 'attr_duplicate', 'attr_unresolved_term'), true)) {
        echo '<thead><tr><th>ID producto</th><th>Producto</th><th>Estado</th><th>Ámbito</th><th>Atributo</th><th>Valor</th>';
        if ($issue === 'attr_duplicate') {
            echo '<th>Repeticiones</th>';
        }
        echo '<th>Accion</th></tr></thead><tbody>';

        foreach ($data['rows'] as $row) {
            $id = (int) ($row['object_id'] ?? 0);
            echo '<tr>';
            echo '<td><code>' . esc_html((string) $id) . '</code></td>';
            echo '<td>' . esc_html((string) ($row['title'] ?? '(Producto no encontrado)')) . '</td>';
            echo '<td>' . esc_html((string) ($row['status'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['ambito'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['attribute_type'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['attribute_value'] ?? '')) . '</td>';
            if ($issue === 'attr_duplicate') {
                echo '<td>' . esc_html(number_format_i18n((int) ($row['duplicate_count'] ?? 0))) . '</td>';
            }
            echo '<td>';
            if ($id > 0) {
                $edit = get_edit_post_link($id, 'raw');
                if ($edit) {
                    echo '<a href="' . esc_url($edit) . '">Editar</a>';
                }
            }
            echo '</td>';
            echo '</tr>';
        }
    } else {
        echo '<thead><tr><th>ID</th><th>Elemento</th><th>Estado</th><th>Excerpt</th><th>Description</th><th>Etiquetas / atributos</th><th>Accion</th></tr></thead><tbody>';

        foreach ($data['rows'] as $row) {
            $id = (int) ($row['object_id'] ?? 0);
            $excerpt = trim(wp_strip_all_tags((string) ($row['excerpt_value'] ?? '')));
            $description = trim(wp_strip_all_tags((string) ($row['description_value'] ?? '')));
            $tags = trim((string) ($row['tags_value'] ?? ''));

            echo '<tr>';
            echo '<td><code>' . esc_html((string) $id) . '</code></td>';
            echo '<td><strong>' . esc_html((string) ($row['title'] ?? '')) . '</strong></td>';
            echo '<td>' . esc_html((string) ($row['status'] ?? '')) . '</td>';
            echo '<td>' . ($excerpt === '' ? '<span style="color:#b32d2e;">Vacio</span>' : esc_html(wp_html_excerpt($excerpt, 90, '...'))) . '</td>';
            echo '<td>' . ($description === '' ? '<span style="color:#b32d2e;">Vacia</span>' : esc_html(wp_html_excerpt($description, 120, '...'))) . '</td>';

            echo '<td>';
            if ($level === 'product' && array_key_exists('attribute_count', $row)) {
                echo 'Etiquetas: ' . ($tags === '' ? '<span style="color:#b32d2e;">0</span>' : esc_html(wp_html_excerpt($tags, 80, '...')));
                echo '<br>Atributos: <strong>' . esc_html(number_format_i18n((int) $row['attribute_count'])) . '</strong>';
            } else {
                echo $tags === '' ? '<span style="color:#b32d2e;">Sin etiquetas</span>' : esc_html(wp_html_excerpt($tags, 100, '...'));
                if ($level === 'category' && array_key_exists('visible_excerpt', $row)) {
                    $visible = trim(wp_strip_all_tags((string) $row['visible_excerpt']));
                    echo '<br><span style="font-size:11px;color:#646970;">Excerpt visible: ' . ($visible === '' ? 'vacio' : esc_html(wp_html_excerpt($visible, 70, '...'))) . '</span>';
                }
            }
            echo '</td>';

            echo '<td>';
            if ($level === 'category') {
                $edit = get_edit_term_link($id, 'product_cat');
            } else {
                $edit = get_edit_post_link($id, 'raw');
            }
            if ($edit && !is_wp_error($edit)) {
                echo '<a href="' . esc_url($edit) . '">Editar</a>';
            }
            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';

    $total_pages = (int) ceil(((int) $data['total']) / $per_page);
    if ($total_pages > 1) {
        echo '<div style="margin-top:14px;display:flex;gap:8px;align-items:center;">';

        if ($paged > 1) {
            echo '<a class="button" href="' . esc_url(seo_report_contents_url(array(
                'content_level' => $level,
                'content_issue' => $issue,
                'content_paged' => $paged - 1,
            ))) . '#seo-content-detail">Anterior</a>';
        }

        echo '<span>Pagina ' . esc_html(number_format_i18n($paged)) . ' de ' . esc_html(number_format_i18n($total_pages)) . '</span>';

        if ($paged < $total_pages) {
            echo '<a class="button" href="' . esc_url(seo_report_contents_url(array(
                'content_level' => $level,
                'content_issue' => $issue,
                'content_paged' => $paged + 1,
            ))) . '#seo-content-detail">Siguiente</a>';
        }

        echo '</div>';
    }

    echo '</div>';
}

/**
 * Render principal de la pestana Contenido.
 */
function seo_report_contents_render_page() {
    global $wpdb;

    if (!current_user_can('manage_options')) {
        return;
    }

    $nodes_table = $wpdb->prefix . 'seo_nodes';

    echo '<div class="seo-report-contents">';
    echo '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-top:18px;">';
    echo '<div>';
    echo '<h2 style="margin:0 0 6px;">Informe de contenido</h2>';
    echo '<p style="margin:0;max-width:900px;color:#50575e;">Cobertura editorial desde Cluster hasta Producto. Esta vista es de solo lectura: detecta contenido ausente, duplicados exactos y problemas objetivos de atributos, y permite abrir directamente los elementos afectados.</p>';
    echo '</div>';
    echo '<a class="button button-secondary" href="' . esc_url(seo_report_contents_url(array('content_refresh' => wp_rand(1000, 999999)))) . '">Actualizar informe</a>';
    echo '</div>';

    if (!seo_report_contents_table_exists($nodes_table)) {
        echo '<div class="notice notice-error inline" style="margin-top:18px;"><p>No se encuentra <code>' . esc_html($nodes_table) . '</code>. El informe de contenido no puede ejecutarse.</p></div>';
        echo '</div>';
        return;
    }

    $summaries = array(
        'cluster'       => seo_report_contents_get_page_role_summary('cluster'),
        'hub_primary'   => seo_report_contents_get_page_role_summary('hub_primary'),
        'hub_secondary' => seo_report_contents_get_page_role_summary('hub_secondary'),
        'category'      => seo_report_contents_get_category_summary(),
        'product'       => seo_report_contents_get_product_summary(),
    );

    $attribute_summary = seo_report_contents_get_attribute_summary();
    $overall_coverage = seo_report_contents_overall_coverage($summaries);
    $overall_color = $overall_coverage >= 95 ? '#2e7d32' : ($overall_coverage >= 80 ? '#b57d00' : '#b32d2e');

    $draft_products = (int) $wpdb->get_var("
        SELECT COUNT(*)
        FROM {$wpdb->posts}
        WHERE post_type = 'product'
          AND post_status = 'draft'
    ");

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:20px 0;">';

    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;border-left:4px solid ' . esc_attr($overall_color) . ';">';
    echo '<div style="font-size:12px;color:#646970;">Cobertura editorial ponderada</div>';
    echo '<div style="font-size:30px;font-weight:700;color:' . esc_attr($overall_color) . ';">' . esc_html(number_format_i18n($overall_coverage, 1)) . '%</div>';
    echo '<div style="font-size:12px;color:#646970;">Excerpt + description + etiquetas; atributos tambien en producto.</div>';
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;">';
    echo '<div style="font-size:12px;color:#646970;">Productos publicados</div>';
    echo '<div style="font-size:30px;font-weight:700;">' . esc_html(number_format_i18n((int) $summaries['product']['total'])) . '</div>';
    echo '<div style="font-size:12px;color:#646970;">Base utilizada para el chequeo editorial de producto.</div>';
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;">';
    echo '<div style="font-size:12px;color:#646970;">Productos en draft</div>';
    echo '<div style="font-size:30px;font-weight:700;color:' . ($draft_products > 0 ? '#b57d00' : '#2e7d32') . ';">' . esc_html(number_format_i18n($draft_products)) . '</div>';
    echo '<div style="font-size:12px;color:#646970;">Informativo; los drafts no entran en los porcentajes de contenido publicado.</div>';
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;">';
    echo '<div style="font-size:12px;color:#646970;">Categorias</div>';
    echo '<div style="font-size:30px;font-weight:700;">' . esc_html(number_format_i18n((int) $summaries['category']['total'])) . '</div>';
    echo '<div style="font-size:12px;color:#646970;">Incluye categorias vacias y con productos.</div>';
    echo '</div>';

    echo '</div>';

    echo '<div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 14px;margin:14px 0 20px;line-height:1.6;">';
    echo '<strong>Fuentes:</strong> clusters y hubs leen <code>wp_posts</code> para excerpt/description y <code>wp_seo_nodes.keywords</code> para sus etiquetas estructurales; categorías leen semántica desde <code>wp_seo_object_vocabulary → wp_seo_vocabulary</code> y mantienen excerpt/description en <code>wp_seo_nodes</code>; productos leen contenido WordPress, semántica desde Vocabulary y atributos desde <code>wp_sql_atributos</code> / <code>wp_sql_product_atributos</code>.';
    echo '</div>';

    seo_report_contents_render_summary_table($summaries);
    seo_report_contents_render_category_integrity($summaries['category']);
    seo_report_contents_render_attribute_panel($attribute_summary, $summaries['product']);

    echo '<h2 style="margin-top:30px;">Criterio del informe</h2>';
    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;max-width:1050px;line-height:1.6;">';
    echo '<p style="margin-top:0;"><strong>Integridad:</strong> un campo vacio se considera incidencia. La cobertura no intenta decidir si un texto es comercialmente bueno; mide si existe.</p>';
    echo '<p><strong>Duplicidad:</strong> se marca solo cuando excerpt o description son exactamente iguales despues de eliminar espacios exteriores. No se usa similitud aproximada en esta primera version.</p>';
    echo '<p><strong>Atributos:</strong> no aplican a clusters, hubs ni categorias. En producto se revisa presencia, valores vacíos, duplicados exactos y términos controlados sin resolver.</p>';
    echo '<p style="margin-bottom:0;"><strong>Lectura:</strong> los contadores enlazados sirven como lista de trabajo; este informe no modifica contenido ni ejecuta limpiezas.</p>';
    echo '</div>';

    $level = isset($_GET['content_level']) ? sanitize_key(wp_unslash($_GET['content_level'])) : '';
    $issue = isset($_GET['content_issue']) ? sanitize_key(wp_unslash($_GET['content_issue'])) : '';

    if ($level !== '' && $issue !== '') {
        seo_report_contents_render_affected_detail($level, $issue);
    }

    echo '</div>';
}

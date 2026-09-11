<?php
/**
 * Comentarista - cobertura de contenido externo por producto.
 *
 * No mantiene una tabla auxiliar: calcula la cobertura cruzando los productos
 * WooCommerce publicados con los registros publicados de Comentarista.
 */

defined('ABSPATH') || exit;

/**
 * Segmentos de cobertura. Se usan tanto en los KPI como en el filtro de trabajo.
 */
function seo_comentarista_coverage_segments()
{
    return array(
        'all'    => 'Todos',
        'zero'   => '0 evidencias',
        'low'    => '1-4 evidencias',
        'medium' => '5-10 evidencias',
        'high'   => 'Más de 10',
    );
}

/**
 * Devuelve la clausula HAVING asociada a un segmento.
 */
function seo_comentarista_coverage_having($segment)
{
    switch ($segment) {
        case 'zero':
            return 'HAVING evidence_count = 0';
        case 'low':
            return 'HAVING evidence_count BETWEEN 1 AND 4';
        case 'medium':
            return 'HAVING evidence_count BETWEEN 5 AND 10';
        case 'high':
            return 'HAVING evidence_count > 10';
        default:
            return '';
    }
}

/**
 * Construye las condiciones de producto compartidas por las consultas de cobertura.
 *
 * @return array{sql:string,args:array}
 */
function seo_comentarista_coverage_product_where($search = '', $category_id = 0)
{
    global $wpdb;

    $where = array(
        "p.post_type = 'product'",
        "p.post_status = 'publish'",
    );
    $args = array();

    $search = trim((string) $search);
    if ($search !== '') {
        $where[] = 'p.post_title LIKE %s';
        $args[] = '%' . $wpdb->esc_like($search) . '%';
    }

    $category_id = absint($category_id);
    if ($category_id) {
        $where[] = "EXISTS (
            SELECT 1
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tr.object_id = p.ID
              AND tt.taxonomy = 'product_cat'
              AND tt.term_id = %d
        )";
        $args[] = $category_id;
    }

    return array(
        'sql'  => 'WHERE ' . implode(' AND ', $where),
        'args' => $args,
    );
}

/**
 * Estadisticas globales de productos publicados.
 */
function seo_comentarista_coverage_stats()
{
    global $wpdb;

    if (!seo_comentarista_table_exists()) {
        return array(
            'products' => 0,
            'covered'  => 0,
            'zero'     => 0,
            'low'      => 0,
            'medium'   => 0,
            'high'     => 0,
            'coverage' => 0.0,
        );
    }

    $table = seo_comentarista_table_name();

    $sql = "SELECT
                COUNT(*) AS products,
                SUM(CASE WHEN x.evidence_count = 0 THEN 1 ELSE 0 END) AS zero_count,
                SUM(CASE WHEN x.evidence_count BETWEEN 1 AND 4 THEN 1 ELSE 0 END) AS low_count,
                SUM(CASE WHEN x.evidence_count BETWEEN 5 AND 10 THEN 1 ELSE 0 END) AS medium_count,
                SUM(CASE WHEN x.evidence_count > 10 THEN 1 ELSE 0 END) AS high_count
            FROM (
                SELECT p.ID, COUNT(c.id) AS evidence_count
                FROM {$wpdb->posts} p
                LEFT JOIN {$table} c
                    ON c.product_id = p.ID
                   AND c.status = 'published'
                WHERE p.post_type = 'product'
                  AND p.post_status = 'publish'
                GROUP BY p.ID
            ) x";

    $row = (array) $wpdb->get_row($sql, ARRAY_A);

    $products = (int) ($row['products'] ?? 0);
    $zero = (int) ($row['zero_count'] ?? 0);
    $covered = max(0, $products - $zero);

    return array(
        'products' => $products,
        'covered'  => $covered,
        'zero'     => $zero,
        'low'      => (int) ($row['low_count'] ?? 0),
        'medium'   => (int) ($row['medium_count'] ?? 0),
        'high'     => (int) ($row['high_count'] ?? 0),
        'coverage' => $products > 0 ? round(($covered / $products) * 100, 1) : 0.0,
    );
}

/**
 * Devuelve el inventario paginado de productos con desglose por tipo/plataforma.
 *
 * @return array{items:array,total:int,pages:int,page:int,per_page:int}
 */
function seo_comentarista_coverage_inventory($args = array())
{
    global $wpdb;

    $defaults = array(
        'segment'     => 'all',
        'search'      => '',
        'category_id' => 0,
        'page'        => 1,
        'per_page'    => 50,
    );
    $args = wp_parse_args($args, $defaults);

    $segments = seo_comentarista_coverage_segments();
    $segment = sanitize_key((string) $args['segment']);
    if (!isset($segments[$segment])) {
        $segment = 'all';
    }

    $page = max(1, absint($args['page']));
    $per_page = min(100, max(10, absint($args['per_page'])));
    $offset = ($page - 1) * $per_page;

    $conditions = seo_comentarista_coverage_product_where($args['search'], $args['category_id']);
    $having = seo_comentarista_coverage_having($segment);
    $table = seo_comentarista_table_name();

    $grouped = "SELECT
                    p.ID AS product_id,
                    p.post_title,
                    COUNT(c.id) AS evidence_count,
                    SUM(CASE WHEN c.content_type = 'comment' THEN 1 ELSE 0 END) AS comment_count,
                    SUM(CASE WHEN c.source_platform = 'youtube' THEN 1 ELSE 0 END) AS youtube_count,
                    SUM(CASE WHEN c.source_platform = 'tiktok' THEN 1 ELSE 0 END) AS tiktok_count,
                    SUM(CASE WHEN c.source_platform = 'instagram' THEN 1 ELSE 0 END) AS instagram_count,
                    SUM(CASE WHEN c.content_type = 'video' THEN 1 ELSE 0 END) AS video_count,
                    SUM(CASE WHEN c.content_type = 'social_post' THEN 1 ELSE 0 END) AS social_count,
                    SUM(CASE WHEN c.content_type IN ('article', 'link') THEN 1 ELSE 0 END) AS other_count
                FROM {$wpdb->posts} p
                LEFT JOIN {$table} c
                    ON c.product_id = p.ID
                   AND c.status = 'published'
                {$conditions['sql']}
                GROUP BY p.ID, p.post_title
                {$having}";

    $count_sql = "SELECT COUNT(*) FROM ({$grouped}) coverage_rows";
    if ($conditions['args']) {
        $count_sql = $wpdb->prepare($count_sql, $conditions['args']);
    }
    $total = (int) $wpdb->get_var($count_sql);

    $list_sql = $grouped . ' ORDER BY evidence_count ASC, p.post_title ASC LIMIT %d OFFSET %d';
    $list_args = array_merge($conditions['args'], array($per_page, $offset));
    $list_sql = $wpdb->prepare($list_sql, $list_args);
    $items = (array) $wpdb->get_results($list_sql, ARRAY_A);

    foreach ($items as &$item) {
        foreach (array('product_id', 'evidence_count', 'comment_count', 'youtube_count', 'tiktok_count', 'instagram_count', 'video_count', 'social_count', 'other_count') as $field) {
            $item[$field] = (int) ($item[$field] ?? 0);
        }
    }
    unset($item);

    return array(
        'items'    => $items,
        'total'    => $total,
        'pages'    => max(1, (int) ceil($total / $per_page)),
        'page'     => $page,
        'per_page' => $per_page,
    );
}

/**
 * Etiqueta visual del tramo de cobertura de un producto.
 */
function seo_comentarista_coverage_badge($count)
{
    $count = (int) $count;
    if ($count === 0) {
        return array('Sin cobertura', '#b32d2e', '#fff1f1');
    }
    if ($count <= 4) {
        return array('Baja', '#996800', '#fff8df');
    }
    if ($count <= 10) {
        return array('Media', '#135e96', '#eef7ff');
    }
    return array('Alta', '#008a20', '#edfaef');
}

/**
 * Pantalla de cobertura dentro de Comentarista.
 */
function seo_comentarista_coverage_admin_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'seo-system'));
    }

    $segments = seo_comentarista_coverage_segments();
    $segment = sanitize_key(wp_unslash($_GET['segment'] ?? 'all'));
    if (!isset($segments[$segment])) {
        $segment = 'all';
    }

    $search = sanitize_text_field(wp_unslash($_GET['coverage_search'] ?? ''));
    $category_id = absint($_GET['coverage_category'] ?? 0);
    $page = max(1, absint($_GET['coverage_page'] ?? 1));

    $stats = seo_comentarista_coverage_stats();
    $inventory = seo_comentarista_coverage_inventory(array(
        'segment'     => $segment,
        'search'      => $search,
        'category_id' => $category_id,
        'page'        => $page,
        'per_page'    => 50,
    ));

    $base_url = add_query_arg(array('page' => 'seo-comentarista', 'view' => 'coverage'), admin_url('admin.php'));
    ?>
    <div class="wrap">
        <h1>Comentarista</h1>
        <?php if (function_exists('seo_comentarista_admin_tabs')) : ?>
            <?php seo_comentarista_admin_tabs('coverage'); ?>
        <?php endif; ?>

        <p>Inventario de cobertura de contenido externo para productos publicados. Solo cuentan los registros de Comentarista con estado <strong>Publicado</strong>.</p>

        <style>
            .seo-comentarista-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:14px;margin:18px 0 22px;max-width:1200px}
            .seo-comentarista-kpi{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 18px;box-sizing:border-box}
            .seo-comentarista-kpi strong{display:block;font-size:25px;line-height:1.15;margin-bottom:5px}
            .seo-comentarista-kpi span{color:#50575e}
            .seo-comentarista-kpi--alert{border-left:4px solid #b32d2e}
            .seo-comentarista-kpi--good{border-left:4px solid #00a32a}
            .seo-comentarista-segments{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px}
            .seo-comentarista-segments .button.current{background:#2271b1;border-color:#2271b1;color:#fff}
            .seo-comentarista-coverage-filters{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 18px}
            .seo-comentarista-coverage-filters input[type=search]{min-width:280px}
            .seo-comentarista-coverage-badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}
            .seo-comentarista-platform-count{white-space:nowrap}
        </style>

        <div class="seo-comentarista-kpis">
            <div class="seo-comentarista-kpi"><strong><?php echo esc_html(number_format_i18n($stats['products'])); ?></strong><span>Productos publicados</span></div>
            <div class="seo-comentarista-kpi seo-comentarista-kpi--good"><strong><?php echo esc_html(number_format_i18n($stats['covered'])); ?></strong><span>Con evidencias</span></div>
            <div class="seo-comentarista-kpi seo-comentarista-kpi--alert"><strong><?php echo esc_html(number_format_i18n($stats['zero'])); ?></strong><span>Sin evidencias</span></div>
            <div class="seo-comentarista-kpi"><strong><?php echo esc_html(number_format_i18n($stats['coverage'], 1)); ?>%</strong><span>Cobertura</span></div>
            <div class="seo-comentarista-kpi"><strong><?php echo esc_html(number_format_i18n($stats['low'])); ?></strong><span>1-4 evidencias</span></div>
            <div class="seo-comentarista-kpi"><strong><?php echo esc_html(number_format_i18n($stats['medium'])); ?></strong><span>5-10 evidencias</span></div>
            <div class="seo-comentarista-kpi"><strong><?php echo esc_html(number_format_i18n($stats['high'])); ?></strong><span>Más de 10</span></div>
        </div>

        <?php if ($stats['zero'] > 0) : ?>
            <div class="notice notice-warning inline"><p><strong>Cola de trabajo:</strong> hay <?php echo esc_html(number_format_i18n($stats['zero'])); ?> productos publicados sin ninguna evidencia externa.</p></div>
        <?php endif; ?>

        <div class="seo-comentarista-segments">
            <?php
            $segment_counts = array(
                'all'    => $stats['products'],
                'zero'   => $stats['zero'],
                'low'    => $stats['low'],
                'medium' => $stats['medium'],
                'high'   => $stats['high'],
            );
            foreach ($segments as $key => $label) :
                $url = add_query_arg('segment', $key, $base_url);
                ?>
                <a class="button <?php echo $segment === $key ? 'current' : ''; ?>" href="<?php echo esc_url($url); ?>">
                    <?php echo esc_html($label); ?> (<?php echo esc_html(number_format_i18n($segment_counts[$key])); ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <form method="get" class="seo-comentarista-coverage-filters">
            <input type="hidden" name="page" value="seo-comentarista">
            <input type="hidden" name="view" value="coverage">
            <input type="hidden" name="segment" value="<?php echo esc_attr($segment); ?>">
            <input type="search" name="coverage_search" value="<?php echo esc_attr($search); ?>" placeholder="Buscar producto por nombre...">
            <?php
            wp_dropdown_categories(array(
                'taxonomy'          => 'product_cat',
                'name'              => 'coverage_category',
                'selected'          => $category_id,
                'show_option_all'   => 'Todas las categorías',
                'hide_empty'        => false,
                'hierarchical'      => true,
                'orderby'           => 'name',
                'class'             => 'postform',
                'value_field'       => 'term_id',
            ));
            ?>
            <button class="button" type="submit">Filtrar</button>
            <?php if ($search !== '' || $category_id) : ?>
                <a class="button" href="<?php echo esc_url(add_query_arg('segment', $segment, $base_url)); ?>">Limpiar</a>
            <?php endif; ?>
        </form>

        <p><strong><?php echo esc_html(number_format_i18n($inventory['total'])); ?></strong> productos en este resultado.</p>

        <div style="overflow:auto;max-width:1400px;">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Total</th>
                        <th>Comentarios</th>
                        <th>YouTube</th>
                        <th>TikTok</th>
                        <th>Instagram</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$inventory['items']) : ?>
                        <tr><td colspan="8">No hay productos que coincidan con los filtros.</td></tr>
                    <?php else : ?>
                        <?php foreach ($inventory['items'] as $item) :
                            $badge = seo_comentarista_coverage_badge($item['evidence_count']);
                            $manage_url = add_query_arg(
                                array(
                                    'page'       => 'seo-comentarista',
                                    'product_id' => $item['product_id'],
                                ),
                                admin_url('admin.php')
                            );
                            $product_edit_url = get_edit_post_link($item['product_id'], 'raw');
                            ?>
                            <tr>
                                <td><strong>#<?php echo esc_html($item['product_id']); ?></strong> <?php echo esc_html($item['post_title']); ?></td>
                                <td><strong><?php echo esc_html($item['evidence_count']); ?></strong></td>
                                <td class="seo-comentarista-platform-count"><?php echo esc_html($item['comment_count']); ?></td>
                                <td class="seo-comentarista-platform-count"><?php echo esc_html($item['youtube_count']); ?></td>
                                <td class="seo-comentarista-platform-count"><?php echo esc_html($item['tiktok_count']); ?></td>
                                <td class="seo-comentarista-platform-count"><?php echo esc_html($item['instagram_count']); ?></td>
                                <td><span class="seo-comentarista-coverage-badge" style="color:<?php echo esc_attr($badge[1]); ?>;background:<?php echo esc_attr($badge[2]); ?>;"><?php echo esc_html($badge[0]); ?></span></td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url($manage_url); ?>">Gestionar</a>
                                    <?php if ($product_edit_url) : ?>
                                        <a class="button button-small" href="<?php echo esc_url($product_edit_url); ?>">Producto</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($inventory['pages'] > 1) :
            $pagination_base = add_query_arg(
                array(
                    'page'              => 'seo-comentarista',
                    'view'              => 'coverage',
                    'segment'           => $segment,
                    'coverage_search'   => $search,
                    'coverage_category' => $category_id,
                    'coverage_page'     => '%#%',
                ),
                admin_url('admin.php')
            );
            ?>
            <div class="tablenav"><div class="tablenav-pages">
                <?php
                echo wp_kses_post(paginate_links(array(
                    'base'      => $pagination_base,
                    'format'    => '',
                    'current'   => $inventory['page'],
                    'total'     => $inventory['pages'],
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                )));
                ?>
            </div></div>
        <?php endif; ?>
    </div>
    <?php
}

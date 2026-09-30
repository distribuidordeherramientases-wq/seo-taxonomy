<?php

defined('ABSPATH') || exit;
function seo_dashboard_enqueue_assets() {
    if (!is_admin()) {
        return;
    }

    wp_enqueue_style(
        'seo-taxonomy-dashboard',
        SEO_SYSTEM_URL . 'includes/assets/seo-dashboard.css',
        array(),
        defined('SEO_SYSTEM_VERSION') ? SEO_SYSTEM_VERSION : '1.0.0'
    );
}
add_action('admin_enqueue_scripts', 'seo_dashboard_enqueue_assets');

/**
 * Sustituye únicamente la función seo_dashboard_page() actual por esta versión.
 */
function seo_dashboard_page() {

    if (!current_user_can('manage_options')) return;

    global $wpdb;

    $nodes_table     = $wpdb->prefix . 'seo_nodes';
    $relations_table = $wpdb->prefix . 'seo_relations';

    /* ---------------------------------------------------------
     * Nodos publicados
     * --------------------------------------------------------- */
    $nodes = $wpdb->get_results("\n        SELECT DISTINCT n.object_id, n.seo_role\n        FROM {$nodes_table} n\n        INNER JOIN {$wpdb->posts} p ON p.ID = n.object_id\n        WHERE n.status = 1\n          AND p.post_status = 'publish'\n          AND n.seo_role IN ('cluster', 'hub_primary', 'hub_secondary')\n    ");

    $clusters       = [];
    $hubs_primary   = [];
    $hubs_secondary = [];

    foreach ($nodes as $node) {
        $id = (int) $node->object_id;
        if ($node->seo_role === 'cluster')       $clusters[$id] = $id;
        if ($node->seo_role === 'hub_primary')   $hubs_primary[$id] = $id;
        if ($node->seo_role === 'hub_secondary') $hubs_secondary[$id] = $id;
    }

    $landings = (int) $wpdb->get_var("\n        SELECT COUNT(DISTINCT target_id)\n        FROM {$relations_table}\n        WHERE target_type = 'landing_page'\n    ");

    /* Devuelve los productos publicados relacionados con unas categorías. */
    $get_product_ids = static function(array $category_ids) use ($wpdb) {
        $category_ids = array_values(array_unique(array_filter(array_map('intval', $category_ids))));
        if (!$category_ids) return [];

        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));

        return array_map('intval', $wpdb->get_col($wpdb->prepare("\n            SELECT DISTINCT p.ID\n            FROM {$wpdb->posts} p\n            INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID\n            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id\n            WHERE p.post_type = 'product'\n              AND p.post_status = 'publish'\n              AND tt.taxonomy = 'product_cat'\n              AND tt.term_id IN ({$placeholders})\n        ", ...$category_ids)));
    };

    /* ---------------------------------------------------------
     * Construcción del informe por cluster
     * --------------------------------------------------------- */
    $cluster_reports = [];
    $all_categories  = [];
    $all_products    = [];

    foreach ($clusters as $cluster_id) {
        $cluster_post  = get_post($cluster_id);
        $cluster_title = $cluster_post ? $cluster_post->post_title : 'Cluster ' . $cluster_id;

        $primary_ids = array_map('intval', $wpdb->get_col($wpdb->prepare("\n            SELECT DISTINCT target_id\n            FROM {$relations_table}\n            WHERE source_type = 'cluster'\n              AND source_id = %d\n              AND relation_type = 'cluster_to_primary'\n        ", $cluster_id)));

        $primary_rows        = [];
        $cluster_secondaries = [];
        $cluster_categories  = [];
        $cluster_products    = [];

        foreach ($primary_ids as $primary_id) {
            $primary_post  = get_post($primary_id);
            $primary_title = $primary_post ? $primary_post->post_title : 'Hub ' . $primary_id;

            $secondary_ids = array_map('intval', $wpdb->get_col($wpdb->prepare("\n                SELECT DISTINCT target_id\n                FROM {$relations_table}\n                WHERE source_type = 'hub_primary'\n                  AND source_id = %d\n                  AND relation_type = 'hub_primary_to_hub_secondary'\n            ", $primary_id)));

            $category_ids = [];

            if ($secondary_ids) {
                $placeholders = implode(',', array_fill(0, count($secondary_ids), '%d'));
                $category_ids = array_map('intval', $wpdb->get_col($wpdb->prepare("\n                    SELECT DISTINCT target_id\n                    FROM {$relations_table}\n                    WHERE source_type = 'hub_secondary'\n                      AND source_id IN ({$placeholders})\n                      AND target_type = 'product_cat'\n                ", ...$secondary_ids)));
            }

            $product_ids = $get_product_ids($category_ids);

            foreach ($secondary_ids as $id) $cluster_secondaries[$id] = $id;
            foreach ($category_ids as $id)  $cluster_categories[$id]  = $id;
            foreach ($product_ids as $id)   $cluster_products[$id]    = $id;

            $primary_rows[] = [
                'id'          => $primary_id,
                'label'       => $primary_title,
                'secondaries' => count($secondary_ids),
                'categories'  => count(array_unique($category_ids)),
                'products'    => count(array_unique($product_ids)),
            ];
        }

        foreach ($cluster_categories as $id) $all_categories[$id] = $id;
        foreach ($cluster_products as $id)   $all_products[$id]   = $id;

        $category_count = count($cluster_categories);
        $product_count  = count($cluster_products);

        $cluster_reports[] = [
            'id'          => $cluster_id,
            'title'       => $cluster_title,
            'primary'     => count($primary_ids),
            'secondary'   => count($cluster_secondaries),
            'categories'  => $category_count,
            'products'    => $product_count,
            'density'     => $category_count > 0 ? round($product_count / $category_count, 1) : 0,
            'primaryRows' => $primary_rows,
        ];
    }

    usort($cluster_reports, static function($a, $b) {
        return $b['products'] <=> $a['products'];
    });

    $seo_pages = count($clusters) + count($hubs_primary) + count($hubs_secondary) + $landings;


    echo '<div class="wrap seo-dashboard">';
    echo '<div class="seo-heading"><div><h1>Distribución de la arquitectura SEO</h1><p>Lectura de páginas, categorías y productos a través de los clusters.</p></div><span>Actualizado ' . esc_html(wp_date('d/m/Y H:i')) . '</span></div>';
    ?>

    <div class="seo-kpis">
        <div class="seo-card seo-card-blue"><span>CLUSTERS</span><strong><?php echo count($clusters); ?></strong><small>Estructuras principales</small></div>
        <div class="seo-card seo-card-violet"><span>PÁGINAS SEO</span><strong><?php echo $seo_pages; ?></strong><small><?php echo count($hubs_primary); ?> primarias · <?php echo count($hubs_secondary); ?> secundarias · <?php echo $landings; ?> landings</small></div>
        <div class="seo-card seo-card-amber"><span>CATEGORÍAS CUBIERTAS</span><strong><?php echo count($all_categories); ?></strong><small>Categorías únicas vinculadas</small></div>
        <div class="seo-card seo-card-green"><span>PRODUCTOS CUBIERTOS</span><strong><?php echo count($all_products); ?></strong><small>Productos publicados únicos</small></div>
    </div>

    <?php if (!$cluster_reports): ?>
        <div class="seo-empty">No existen clusters publicados con los que construir el informe.</div>
    <?php else: ?>

        <section class="seo-section">
            <div class="seo-section-title">
                <div><span>VISIÓN GLOBAL</span><h2>Cómo se reparte la arquitectura</h2></div>
                <p>Cada gráfico mide una capa distinta. Los clusters aparecen ordenados por número de productos.</p>
            </div>

            <div class="seo-box seo-box-wide">
                <div class="seo-box-heading"><div><h3>Resumen por cluster</h3><p>Distribución de hubs, categorías y productos sin dependencias JavaScript externas.</p></div></div>
                <div class="seo-table-wrap">
                    <table>
                        <thead>
                            <tr><th>Cluster</th><th>Hubs primarios</th><th>Hubs secundarios</th><th>Categorías</th><th>Productos</th><th>Prod./categoría</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cluster_reports as $cluster): ?>
                            <tr>
                                <td><strong><?php echo esc_html($cluster['title']); ?></strong></td>
                                <td><?php echo esc_html(number_format_i18n((int) $cluster['primary'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int) $cluster['secondary'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int) $cluster['categories'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int) $cluster['products'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n((float) $cluster['density'], 1)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="seo-section">
            <div class="seo-section-title">
                <div><span>ANATOMÍA INTERNA</span><h2>Detalle por cluster</h2></div>
                <p>El gráfico muestra el reparto de productos entre hubs primarios; la tabla permite localizar desequilibrios concretos.</p>
            </div>

            <div class="seo-clusters-grid">
                <?php foreach ($cluster_reports as $cluster): ?>
                    <article class="seo-cluster-box">
                        <header>
                            <div><span>CLUSTER</span><h3><?php echo esc_html($cluster['title']); ?></h3></div>
                            <strong><?php echo (int) $cluster['products']; ?> <small>productos</small></strong>
                        </header>

                        <div class="seo-cluster-stats">
                            <div><strong><?php echo (int) $cluster['primary']; ?></strong><span>Hubs primarios</span></div>
                            <div><strong><?php echo (int) $cluster['secondary']; ?></strong><span>Hubs secundarios</span></div>
                            <div><strong><?php echo (int) $cluster['categories']; ?></strong><span>Categorías</span></div>
                            <div><strong><?php echo esc_html($cluster['density']); ?></strong><span>Prod./categoría</span></div>
                        </div>

                        <?php if ($cluster['primaryRows']): ?>
                            <div class="seo-cluster-body">
                                <div class="seo-primary-bars">
                                    <h4>Reparto por hub primario</h4>
                                    <?php foreach ($cluster['primaryRows'] as $row): ?>
                                        <?php
                                        $max_value = max(1, (int) $cluster['products']);
                                        $row_value = (int) $row['products'];
                                        ?>
                                        <div class="seo-primary-bar">
                                            <div><span><?php echo esc_html($row['label']); ?></span><strong><?php echo esc_html(number_format_i18n($row_value)); ?></strong></div>
                                            <progress max="<?php echo esc_attr($max_value); ?>" value="<?php echo esc_attr($row_value); ?>"></progress>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="seo-table-wrap">
                                    <table>
                                        <thead><tr><th>Hub primario</th><th>Subhubs</th><th>Categorías</th><th>Productos</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($cluster['primaryRows'] as $row): ?>
                                            <tr>
                                                <td><?php echo esc_html($row['label']); ?></td>
                                                <td><?php echo (int) $row['secondaries']; ?></td>
                                                <td><?php echo (int) $row['categories']; ?></td>
                                                <td><strong><?php echo (int) $row['products']; ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="seo-no-data">Este cluster todavía no tiene hubs primarios vinculados.</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>



    <?php
    echo '</div>';
}

?>
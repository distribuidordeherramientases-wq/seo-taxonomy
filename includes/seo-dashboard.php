<?php
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

    $labels          = array_column($cluster_reports, 'title');
    $primary_data    = array_column($cluster_reports, 'primary');
    $secondary_data  = array_column($cluster_reports, 'secondary');
    $categories_data = array_column($cluster_reports, 'categories');
    $products_data   = array_column($cluster_reports, 'products');

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
                <div class="seo-box-heading"><div><h3>Páginas por cluster</h3><p>Hubs primarios y secundarios que sostienen cada estructura.</p></div></div>
                <div class="seo-chart-wide"><canvas id="seoPagesChart"></canvas></div>
            </div>

            <div class="seo-report-grid">
                <div class="seo-box">
                    <div class="seo-box-heading"><div><h3>Categorías por cluster</h3><p>Amplitud temática de cada cluster.</p></div></div>
                    <div class="seo-chart"><canvas id="seoCategoriesChart"></canvas></div>
                </div>
                <div class="seo-box">
                    <div class="seo-box-heading"><div><h3>Productos por cluster</h3><p>Peso real del catálogo publicado.</p></div></div>
                    <div class="seo-chart"><canvas id="seoProductsChart"></canvas></div>
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
                                <div class="seo-donut"><canvas id="clusterChart_<?php echo (int) $cluster['id']; ?>"></canvas></div>
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

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof Chart === 'undefined') return;

        const labels = <?php echo wp_json_encode($labels); ?>;
        const palette = ['#2563eb','#059669','#d97706','#7c3aed','#dc2626','#0891b2','#db2777','#4f46e5','#65a30d','#ea580c'];
        Chart.defaults.color = '#64748b';
        Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

        const baseOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, grid: { color: '#eef2f7' }, ticks: { precision: 0 } },
                y: { grid: { display: false } }
            }
        };

        new Chart(document.getElementById('seoPagesChart'), {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: 'Hubs primarios', data: <?php echo wp_json_encode($primary_data); ?>, backgroundColor: '#2563eb', borderRadius: 4 },
                    { label: 'Hubs secundarios', data: <?php echo wp_json_encode($secondary_data); ?>, backgroundColor: '#93c5fd', borderRadius: 4 }
                ]
            },
            options: {
                ...baseOptions,
                indexAxis: 'y',
                plugins: { legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: {
                    x: { stacked: true, beginAtZero: true, grid: { color: '#eef2f7' }, ticks: { precision: 0 } },
                    y: { stacked: true, grid: { display: false } }
                }
            }
        });

        new Chart(document.getElementById('seoCategoriesChart'), {
            type: 'bar',
            data: { labels, datasets: [{ data: <?php echo wp_json_encode($categories_data); ?>, backgroundColor: '#f59e0b', borderRadius: 5 }] },
            options: { ...baseOptions, indexAxis: 'y' }
        });

        new Chart(document.getElementById('seoProductsChart'), {
            type: 'bar',
            data: { labels, datasets: [{ data: <?php echo wp_json_encode($products_data); ?>, backgroundColor: '#10b981', borderRadius: 5 }] },
            options: { ...baseOptions, indexAxis: 'y' }
        });

        const clusterReports = <?php echo wp_json_encode($cluster_reports); ?>;
        clusterReports.forEach((cluster) => {
            const canvas = document.getElementById('clusterChart_' + cluster.id);
            if (!canvas || !cluster.primaryRows.length) return;

            const productValues = cluster.primaryRows.map(row => row.products);
            const hasProducts = productValues.some(value => value > 0);
            const values = hasProducts ? productValues : cluster.primaryRows.map(row => row.categories);

            new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: cluster.primaryRows.map(row => row.label),
                    datasets: [{ data: values, backgroundColor: palette, borderColor: '#fff', borderWidth: 3, hoverOffset: 5 }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '68%',
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: context => ` ${context.label}: ${context.raw} ${hasProducts ? 'productos' : 'categorías'}` } }
                    }
                }
            });
        });
    });
    </script>

    <style>
    .seo-dashboard{background:#f6f8fc;padding:24px;margin:0 0 0 -20px;min-height:100vh;color:#172033}
    .seo-heading{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:22px}
    .seo-heading h1{font-size:26px;margin:0 0 5px;color:#172033}.seo-heading p{margin:0;color:#64748b}.seo-heading>span{font-size:12px;color:#94a3b8}
    .seo-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:32px}
    .seo-card{background:#fff;border:1px solid #e6ebf2;border-top:3px solid #cbd5e1;border-radius:12px;padding:18px;box-shadow:0 2px 7px rgba(15,23,42,.03)}
    .seo-card>span{display:block;font-size:10px;font-weight:700;letter-spacing:.08em;color:#64748b}.seo-card>strong{display:block;font-size:31px;line-height:1.2;margin:4px 0;color:#172033}.seo-card small{color:#8491a5}
    .seo-card-blue{border-top-color:#2563eb}.seo-card-violet{border-top-color:#7c3aed}.seo-card-amber{border-top-color:#f59e0b}.seo-card-green{border-top-color:#10b981}
    .seo-section{margin-top:32px}.seo-section-title{display:flex;justify-content:space-between;align-items:flex-end;gap:30px;margin-bottom:16px}.seo-section-title span{font-size:10px;letter-spacing:.12em;font-weight:800;color:#2563eb}.seo-section-title h2{font-size:21px;margin:3px 0 0}.seo-section-title p{max-width:520px;margin:0;color:#64748b;font-size:13px;text-align:right}
    .seo-report-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.seo-box,.seo-cluster-box{background:#fff;border:1px solid #e6ebf2;border-radius:13px;box-shadow:0 2px 8px rgba(15,23,42,.035)}
    .seo-box{padding:18px}.seo-box-wide{margin-bottom:16px}.seo-box-heading h3{font-size:15px;margin:0 0 3px}.seo-box-heading p{font-size:12px;color:#8491a5;margin:0}.seo-chart{height:max(300px,calc(38px * <?php echo max(1, count($cluster_reports)); ?>));margin-top:18px}.seo-chart-wide{height:max(320px,calc(42px * <?php echo max(1, count($cluster_reports)); ?>));margin-top:18px}
    .seo-clusters-grid{display:grid;grid-template-columns:1fr;gap:18px}.seo-cluster-box{overflow:hidden}.seo-cluster-box>header{display:flex;justify-content:space-between;align-items:center;padding:18px 20px;border-bottom:1px solid #edf1f6}.seo-cluster-box header span{font-size:9px;font-weight:800;letter-spacing:.12em;color:#2563eb}.seo-cluster-box header h3{font-size:17px;margin:2px 0 0}.seo-cluster-box header>strong{font-size:23px;color:#059669}.seo-cluster-box header>strong small{font-size:11px;color:#64748b;font-weight:500}
    .seo-cluster-stats{display:grid;grid-template-columns:repeat(4,1fr);background:#f8fafc;border-bottom:1px solid #edf1f6}.seo-cluster-stats div{padding:13px 20px;border-right:1px solid #edf1f6}.seo-cluster-stats div:last-child{border:0}.seo-cluster-stats strong,.seo-cluster-stats span{display:block}.seo-cluster-stats strong{font-size:18px}.seo-cluster-stats span{font-size:10px;color:#7b8798}
    .seo-cluster-body{display:grid;grid-template-columns:230px 1fr;gap:22px;padding:20px}.seo-donut{height:210px}.seo-table-wrap{overflow:auto}.seo-table-wrap table{width:100%;border-collapse:collapse}.seo-table-wrap th,.seo-table-wrap td{text-align:left;border-bottom:1px solid #edf1f6;padding:10px 8px;font-size:12px}.seo-table-wrap th{font-size:9px;letter-spacing:.06em;text-transform:uppercase;color:#7b8798}.seo-table-wrap td:not(:first-child),.seo-table-wrap th:not(:first-child){text-align:center}.seo-no-data,.seo-empty{padding:35px;text-align:center;color:#8491a5;background:#fff;border:1px dashed #cbd5e1;border-radius:12px}
    @media(max-width:1000px){.seo-kpis{grid-template-columns:repeat(2,1fr)}.seo-report-grid{grid-template-columns:1fr}.seo-cluster-body{grid-template-columns:1fr}.seo-donut{height:240px}}
    @media(max-width:600px){.seo-dashboard{padding:16px}.seo-heading,.seo-section-title{display:block}.seo-heading>span{display:block;margin-top:8px}.seo-section-title p{text-align:left;margin-top:8px}.seo-kpis{grid-template-columns:1fr}.seo-cluster-stats{grid-template-columns:1fr 1fr}}
    </style>

    <?php
    echo '</div>';
}

?>
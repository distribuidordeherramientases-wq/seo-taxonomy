<?php
/**
 * Inventario ligero de relaciones por categoria de producto.
 *
 * Fuentes utilizadas exclusivamente:
 * - wp_seo_relations: jerarquia SEO, posts y landings.
 * - wp_seo_object_vocabulary + wp_seo_vocabulary: etiquetas canonicas de la categoria.
 * - WordPress/WooCommerce: numero de productos de product_cat.
 * - wp_seo_faq: FAQs de categoria (object_type = 2, object_id = term_id).
 *
 * No consulta comparativas, seo_nodes, productos completos ni relaciones inventadas.
 * Procesa las categorias por paginas para mantener un consumo de memoria estable.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_category_inventory_table_exists')) {
    function seo_category_inventory_table_exists($table_name) {
        global $wpdb;
        static $cache = array();

        $table_name = (string) $table_name;
        if ($table_name === '') {
            return false;
        }

        if (array_key_exists($table_name, $cache)) {
            return $cache[$table_name];
        }

        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))
        );

        $cache[$table_name] = ($found === $table_name);
        return $cache[$table_name];
    }
}

if (!function_exists('seo_category_inventory_placeholders')) {
    function seo_category_inventory_placeholders($values, $placeholder = '%d') {
        $values = array_values((array) $values);
        return $values ? implode(',', array_fill(0, count($values), $placeholder)) : '';
    }
}

if (!function_exists('seo_category_inventory_unique_ids')) {
    function seo_category_inventory_unique_ids($values) {
        return array_values(array_unique(array_filter(array_map('absint', (array) $values))));
    }
}

if (!function_exists('seo_category_inventory_get_posts_map')) {
    function seo_category_inventory_get_posts_map($ids) {
        global $wpdb;

        $ids = seo_category_inventory_unique_ids($ids);
        if (!$ids) {
            return array();
        }

        $ph = seo_category_inventory_placeholders($ids, '%d');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_status, post_type\n"
                . "FROM {$wpdb->posts}\n"
                . "WHERE ID IN ({$ph})",
                ...$ids
            ),
            ARRAY_A
        );

        $map = array();
        foreach ((array) $rows as $row) {
            $id = absint($row['ID'] ?? 0);
            if (!$id) {
                continue;
            }

            $map[$id] = array(
                'id'     => $id,
                'title'  => (string) ($row['post_title'] ?? ('#' . $id)),
                'status' => (string) ($row['post_status'] ?? ''),
                'type'   => (string) ($row['post_type'] ?? ''),
            );
        }

        return $map;
    }
}

if (!function_exists('seo_category_inventory_collect')) {
    function seo_category_inventory_collect($terms) {
        global $wpdb;

        $terms = array_values((array) $terms);
        $category_ids = seo_category_inventory_unique_ids(array_map(static function ($term) {
            return isset($term->term_id) ? $term->term_id : 0;
        }, $terms));

        $data = array(
            'items' => array(),
            'tables' => array(
                'relations'  => false,
                'vocabulary' => false,
                'faq'        => false,
            ),
        );

        foreach ($terms as $term) {
            $term_id = absint($term->term_id ?? 0);
            if (!$term_id) {
                continue;
            }

            $data['items'][$term_id] = array(
                'term'       => $term,
                'secondary'  => array(),
                'primary'    => array(),
                'cluster'    => array(),
                'paths'      => array(),
                'vocabulary' => array(),
                'faqs'       => array(),
                'posts'      => array(),
                'landings'   => array(),
                'products'   => absint($term->count ?? 0),
            );
        }

        if (!$category_ids) {
            return $data;
        }

        $cat_ph = seo_category_inventory_placeholders($category_ids, '%d');
        $relations_table = $wpdb->prefix . 'seo_relations';

        /* ----------------------------------------------------------
         * 1. Jerarquia y contenidos: solo wp_seo_relations.
         * ------------------------------------------------------- */
        if (seo_category_inventory_table_exists($relations_table)) {
            $data['tables']['relations'] = true;

            // Categoria <- Hub secundario.
            $secondary_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT source_id, target_id\n"
                    . "FROM {$relations_table}\n"
                    . "WHERE source_type = 'hub_secondary'\n"
                    . "  AND target_type = 'product_cat'\n"
                    . "  AND relation_type = 'hub_secondary_to_category'\n"
                    . "  AND target_id IN ({$cat_ph})",
                    ...$category_ids
                ),
                ARRAY_A
            );

            $category_to_secondary = array();
            $secondary_ids = array();
            foreach ((array) $secondary_rows as $row) {
                $category_id  = absint($row['target_id'] ?? 0);
                $secondary_id = absint($row['source_id'] ?? 0);
                if (!$category_id || !$secondary_id || !isset($data['items'][$category_id])) {
                    continue;
                }
                $category_to_secondary[$category_id][] = $secondary_id;
                $secondary_ids[] = $secondary_id;
            }
            foreach ($category_to_secondary as $category_id => $ids) {
                $category_to_secondary[$category_id] = seo_category_inventory_unique_ids($ids);
            }
            $secondary_ids = seo_category_inventory_unique_ids($secondary_ids);

            // Hub secundario <- Hub primario.
            $secondary_to_primary = array();
            $primary_ids = array();
            if ($secondary_ids) {
                $sec_ph = seo_category_inventory_placeholders($secondary_ids, '%d');
                $primary_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT source_id, target_id\n"
                        . "FROM {$relations_table}\n"
                        . "WHERE source_type = 'hub_primary'\n"
                        . "  AND target_type = 'hub_secondary'\n"
                        . "  AND relation_type = 'hub_primary_to_hub_secondary'\n"
                        . "  AND target_id IN ({$sec_ph})",
                        ...$secondary_ids
                    ),
                    ARRAY_A
                );

                foreach ((array) $primary_rows as $row) {
                    $secondary_id = absint($row['target_id'] ?? 0);
                    $primary_id   = absint($row['source_id'] ?? 0);
                    if (!$secondary_id || !$primary_id) {
                        continue;
                    }
                    $secondary_to_primary[$secondary_id][] = $primary_id;
                    $primary_ids[] = $primary_id;
                }
            }
            foreach ($secondary_to_primary as $secondary_id => $ids) {
                $secondary_to_primary[$secondary_id] = seo_category_inventory_unique_ids($ids);
            }
            $primary_ids = seo_category_inventory_unique_ids($primary_ids);

            // Hub primario <- Cluster.
            $primary_to_cluster = array();
            $cluster_ids = array();
            if ($primary_ids) {
                $primary_ph = seo_category_inventory_placeholders($primary_ids, '%d');
                $cluster_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT source_id, target_id\n"
                        . "FROM {$relations_table}\n"
                        . "WHERE source_type = 'cluster'\n"
                        . "  AND target_type = 'hub_primary'\n"
                        . "  AND relation_type = 'cluster_to_primary'\n"
                        . "  AND target_id IN ({$primary_ph})",
                        ...$primary_ids
                    ),
                    ARRAY_A
                );

                foreach ((array) $cluster_rows as $row) {
                    $primary_id = absint($row['target_id'] ?? 0);
                    $cluster_id = absint($row['source_id'] ?? 0);
                    if (!$primary_id || !$cluster_id) {
                        continue;
                    }
                    $primary_to_cluster[$primary_id][] = $cluster_id;
                    $cluster_ids[] = $cluster_id;
                }
            }
            foreach ($primary_to_cluster as $primary_id => $ids) {
                $primary_to_cluster[$primary_id] = seo_category_inventory_unique_ids($ids);
            }
            $cluster_ids = seo_category_inventory_unique_ids($cluster_ids);

            // Posts y landings relacionados directamente con las categorias visibles.
            $content_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT source_id, source_type, target_id, relation_type\n"
                    . "FROM {$relations_table}\n"
                    . "WHERE target_type = 'product_cat'\n"
                    . "  AND target_id IN ({$cat_ph})\n"
                    . "  AND relation_type IN ('post_to_category','landing_to_category')",
                    ...$category_ids
                ),
                ARRAY_A
            );

            $content_ids = array();
            foreach ((array) $content_rows as $row) {
                $content_ids[] = absint($row['source_id'] ?? 0);
            }

            $all_node_ids = array_merge($secondary_ids, $primary_ids, $cluster_ids, $content_ids);
            $posts_map = seo_category_inventory_get_posts_map($all_node_ids);

            // Construir rutas por categoria sin consultar relaciones globales.
            foreach ($category_ids as $category_id) {
                $secondary_for_category = $category_to_secondary[$category_id] ?? array();

                foreach ($secondary_for_category as $secondary_id) {
                    if (isset($posts_map[$secondary_id])) {
                        $data['items'][$category_id]['secondary'][$secondary_id] = $posts_map[$secondary_id];
                    }

                    $primary_for_secondary = $secondary_to_primary[$secondary_id] ?? array();
                    if (!$primary_for_secondary) {
                        $data['items'][$category_id]['paths'][] = array(0, 0, $secondary_id);
                        continue;
                    }

                    foreach ($primary_for_secondary as $primary_id) {
                        if (isset($posts_map[$primary_id])) {
                            $data['items'][$category_id]['primary'][$primary_id] = $posts_map[$primary_id];
                        }

                        $cluster_for_primary = $primary_to_cluster[$primary_id] ?? array();
                        if (!$cluster_for_primary) {
                            $data['items'][$category_id]['paths'][] = array(0, $primary_id, $secondary_id);
                            continue;
                        }

                        foreach ($cluster_for_primary as $cluster_id) {
                            if (isset($posts_map[$cluster_id])) {
                                $data['items'][$category_id]['cluster'][$cluster_id] = $posts_map[$cluster_id];
                            }
                            $data['items'][$category_id]['paths'][] = array($cluster_id, $primary_id, $secondary_id);
                        }
                    }
                }

                $path_keys = array();
                $unique_paths = array();
                foreach ($data['items'][$category_id]['paths'] as $path) {
                    $key = implode(':', array_map('absint', $path));
                    if (isset($path_keys[$key])) {
                        continue;
                    }
                    $path_keys[$key] = true;
                    $unique_paths[] = $path;
                }
                $data['items'][$category_id]['paths'] = $unique_paths;
            }

            foreach ((array) $content_rows as $row) {
                $category_id  = absint($row['target_id'] ?? 0);
                $source_id    = absint($row['source_id'] ?? 0);
                $relation_type = (string) ($row['relation_type'] ?? '');

                if (!$category_id || !$source_id || !isset($data['items'][$category_id]) || !isset($posts_map[$source_id])) {
                    continue;
                }

                if ($relation_type === 'post_to_category') {
                    $data['items'][$category_id]['posts'][$source_id] = $posts_map[$source_id];
                } elseif ($relation_type === 'landing_to_category') {
                    $data['items'][$category_id]['landings'][$source_id] = $posts_map[$source_id];
                }
            }
        }

        /* ----------------------------------------------------------
         * 2. Vocabulary: asignacion + nombre canonico.
         * ------------------------------------------------------- */
        $object_vocab_table = $wpdb->prefix . 'seo_object_vocabulary';
        $vocab_table        = $wpdb->prefix . 'seo_vocabulary';

        if (
            seo_category_inventory_table_exists($object_vocab_table)
            && seo_category_inventory_table_exists($vocab_table)
        ) {
            $data['tables']['vocabulary'] = true;

            $vocab_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ov.object_id, v.semantic_group, v.slug, v.label\n"
                    . "FROM {$object_vocab_table} ov\n"
                    . "INNER JOIN {$vocab_table} v ON v.id = ov.vocabulary_id\n"
                    . "WHERE ov.object_type = 'product_cat'\n"
                    . "  AND ov.status = 1\n"
                    . "  AND v.active = 1\n"
                    . "  AND ov.object_id IN ({$cat_ph})\n"
                    . "ORDER BY ov.object_id ASC, v.semantic_group ASC, v.label ASC",
                    ...$category_ids
                ),
                ARRAY_A
            );

            foreach ((array) $vocab_rows as $row) {
                $category_id = absint($row['object_id'] ?? 0);
                $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
                $label = trim((string) ($row['label'] ?? $row['slug'] ?? ''));

                if (!$category_id || !$group || $label === '' || !isset($data['items'][$category_id])) {
                    continue;
                }

                if (!isset($data['items'][$category_id]['vocabulary'][$group])) {
                    $data['items'][$category_id]['vocabulary'][$group] = array();
                }
                $data['items'][$category_id]['vocabulary'][$group][] = $label;
            }

            foreach ($data['items'] as &$item) {
                foreach ($item['vocabulary'] as $group => $labels) {
                    $item['vocabulary'][$group] = array_values(array_unique(array_filter(array_map('trim', $labels))));
                }
            }
            unset($item);
        }

        /* ----------------------------------------------------------
         * 3. FAQs: wp_seo_faq, categoria = object_type 2.
         * ------------------------------------------------------- */
        $faq_table = $wpdb->prefix . 'seo_faq';
        if (seo_category_inventory_table_exists($faq_table)) {
            $data['tables']['faq'] = true;

            $faq_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, object_id, question, active, sort_order\n"
                    . "FROM {$faq_table}\n"
                    . "WHERE object_type = 2\n"
                    . "  AND object_id IN ({$cat_ph})\n"
                    . "ORDER BY object_id ASC, sort_order ASC, id ASC",
                    ...$category_ids
                ),
                ARRAY_A
            );

            foreach ((array) $faq_rows as $row) {
                $category_id = absint($row['object_id'] ?? 0);
                if (!$category_id || !isset($data['items'][$category_id])) {
                    continue;
                }

                $data['items'][$category_id]['faqs'][] = array(
                    'id'       => absint($row['id'] ?? 0),
                    'question' => trim((string) ($row['question'] ?? '')),
                    'active'   => absint($row['active'] ?? 0) === 1,
                );
            }
        }

        return $data;
    }
}

if (!function_exists('seo_category_inventory_post_link')) {
    function seo_category_inventory_post_link($item) {
        $id = absint($item['id'] ?? 0);
        $title = (string) ($item['title'] ?? ('#' . $id));
        $status = (string) ($item['status'] ?? '');

        if ($id && $status === 'publish') {
            $url = get_permalink($id);
            if ($url) {
                return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($title) . '</a>';
            }
        }

        return esc_html($title) . ($status !== '' ? ' <small>(' . esc_html($status) . ')</small>' : '');
    }
}

if (!function_exists('seo_category_inventory_count_published')) {
    function seo_category_inventory_count_published($items) {
        $count = 0;
        foreach ((array) $items as $item) {
            if (($item['status'] ?? '') === 'publish') {
                $count++;
            }
        }
        return $count;
    }
}

if (!function_exists('seo_category_inventory_render_paths')) {
    function seo_category_inventory_render_paths($item) {
        $paths = (array) ($item['paths'] ?? array());
        if (!$paths) {
            echo '<span style="color:#b32d2e;">Sin jerarquia SEO enlazada</span>';
            return;
        }

        foreach ($paths as $path) {
            $cluster_id = absint($path[0] ?? 0);
            $primary_id = absint($path[1] ?? 0);
            $secondary_id = absint($path[2] ?? 0);

            echo '<div style="margin-bottom:7px;line-height:1.45;">';
            if ($cluster_id && isset($item['cluster'][$cluster_id])) {
                echo seo_category_inventory_post_link($item['cluster'][$cluster_id]);
            } else {
                echo '<span style="color:#999;">Sin cluster</span>';
            }
            echo ' &rarr; ';
            if ($primary_id && isset($item['primary'][$primary_id])) {
                echo seo_category_inventory_post_link($item['primary'][$primary_id]);
            } else {
                echo '<span style="color:#999;">Sin hub primario</span>';
            }
            echo ' &rarr; ';
            if ($secondary_id && isset($item['secondary'][$secondary_id])) {
                echo seo_category_inventory_post_link($item['secondary'][$secondary_id]);
            } else {
                echo '<span style="color:#999;">Sin hub secundario</span>';
            }
            echo '</div>';
        }
    }
}

if (!function_exists('seo_category_inventory_render_vocab')) {
    function seo_category_inventory_render_vocab($groups) {
        $groups = (array) $groups;
        if (!$groups) {
            echo '<span style="color:#999;">Sin etiquetas</span>';
            return;
        }

        foreach ($groups as $group => $labels) {
            if (!$labels) {
                continue;
            }
            echo '<div style="margin-bottom:5px;"><strong>' . esc_html($group) . ':</strong> ' . esc_html(implode(', ', $labels)) . '</div>';
        }
    }
}

if (!function_exists('seo_category_inventory_render_content')) {
    function seo_category_inventory_render_content($items) {
        $items = (array) $items;
        if (!$items) {
            echo '<span style="color:#999;">Ninguno</span>';
            return;
        }

        echo '<ul style="margin:4px 0 0 18px;">';
        foreach ($items as $item) {
            echo '<li>' . seo_category_inventory_post_link($item) . '</li>';
        }
        echo '</ul>';
    }
}

if (!function_exists('seo_category_inventory_render_faqs')) {
    function seo_category_inventory_render_faqs($faqs) {
        $faqs = (array) $faqs;
        if (!$faqs) {
            echo '<span style="color:#999;">Ninguna</span>';
            return;
        }

        echo '<ul style="margin:4px 0 0 18px;">';
        foreach ($faqs as $faq) {
            echo '<li>' . esc_html($faq['question'] ?: ('FAQ #' . absint($faq['id'] ?? 0)));
            if (empty($faq['active'])) {
                echo ' <small style="color:#b32d2e;">(inactiva)</small>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}

if (!function_exists('seo_render_category_info_related')) {
    function seo_render_category_info_related($page_slug = 'category-seo-admin') {
        if (!current_user_can('manage_options')) {
            return;
        }

        $page_slug = sanitize_key((string) $page_slug);
        $search = isset($_GET['category_inventory_q'])
            ? sanitize_text_field(wp_unslash($_GET['category_inventory_q']))
            : '';
        $paged = max(1, absint($_GET['category_inventory_page'] ?? 1));
        $per_page = 50;

        $term_args = array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
            'number'     => $per_page,
            'offset'     => ($paged - 1) * $per_page,
        );
        if ($search !== '') {
            $term_args['search'] = $search;
        }

        $terms = get_terms($term_args);
        if (is_wp_error($terms)) {
            echo '<div class="notice notice-error"><p>' . esc_html($terms->get_error_message()) . '</p></div>';
            return;
        }

        $count_args = array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        );
        if ($search !== '') {
            $count_args['search'] = $search;
        }

        $total = wp_count_terms($count_args);
        $total = is_wp_error($total) ? count($terms) : absint($total);
        $total_pages = max(1, (int) ceil($total / $per_page));

        $dataset = seo_category_inventory_collect($terms);

        $base_url = add_query_arg(
            array(
                'page' => $page_slug,
                'tab'  => 'inventario',
            ),
            admin_url('admin.php')
        );

        echo '<div style="max-width:100%;">';
        echo '<h2>Inventario por categoria</h2>';
        echo '<p>Vista ligera, paginada y de solo lectura. Fuentes: <code>seo_relations</code>, Vocabulary canonico, WordPress/WooCommerce y <code>seo_faq</code>.</p>';
        echo '<p><strong>No se consulta ninguna tabla de comparativas ni <code>seo_nodes</code>.</strong></p>';

        echo '<form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:16px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page_slug) . '">';
        echo '<input type="hidden" name="tab" value="inventario">';
        echo '<input type="search" name="category_inventory_q" value="' . esc_attr($search) . '" placeholder="Buscar categoria..." style="min-width:280px;">';
        echo '<button class="button button-primary" type="submit">Buscar</button>';
        if ($search !== '') {
            echo '<a class="button" href="' . esc_url($base_url) . '">Limpiar</a>';
        }
        echo '</form>';

        echo '<p><strong>' . esc_html(number_format_i18n($total)) . '</strong> categorias encontradas. Se muestran ' . esc_html(number_format_i18n(count($terms))) . ' en esta pagina.</p>';

        if (!$dataset['tables']['relations']) {
            echo '<div class="notice notice-warning inline"><p>No se encontro <code>wp_seo_relations</code>.</p></div>';
        }
        if (!$dataset['tables']['vocabulary']) {
            echo '<div class="notice notice-warning inline"><p>No se encontraron las tablas de Vocabulary canonico.</p></div>';
        }
        if (!$dataset['tables']['faq']) {
            echo '<div class="notice notice-warning inline"><p>No se encontro <code>wp_seo_faq</code>.</p></div>';
        }

        echo '<div style="overflow:auto;background:#fff;border:1px solid #dcdcde;">';
        echo '<table class="widefat striped" style="min-width:1450px;">';
        echo '<thead><tr>';
        echo '<th>Categoria</th>';
        echo '<th>Jerarquia superior</th>';
        echo '<th>Etiquetas</th>';
        echo '<th>FAQs</th>';
        echo '<th>Posts</th>';
        echo '<th>Landings</th>';
        echo '<th>Productos</th>';
        echo '<th>Carga editorial</th>';
        echo '</tr></thead><tbody>';

        if (!$dataset['items']) {
            echo '<tr><td colspan="8">No hay categorias para mostrar.</td></tr>';
        }

        foreach ($dataset['items'] as $term_id => $item) {
            $term = $item['term'];
            $term_url = get_term_link($term, 'product_cat');
            $edit_url = function_exists('seo_get_category_editor_url')
                ? seo_get_category_editor_url($term_id, $page_slug)
                : get_edit_term_link($term_id, 'product_cat');

            $faq_active = 0;
            foreach ((array) $item['faqs'] as $faq) {
                if (!empty($faq['active'])) {
                    $faq_active++;
                }
            }
            $posts_published = seo_category_inventory_count_published($item['posts']);
            $landings_published = seo_category_inventory_count_published($item['landings']);
            $editorial_load = $posts_published + $landings_published;

            echo '<tr>';
            echo '<td style="min-width:190px;"><strong>' . esc_html($term->name) . '</strong><br><small>ID ' . esc_html($term_id) . ' · /' . esc_html($term->slug) . '/</small><br>';
            if (!is_wp_error($term_url)) {
                echo '<a href="' . esc_url($term_url) . '" target="_blank" rel="noopener">ver</a> ';
            }
            if ($edit_url) {
                echo '<a href="' . esc_url($edit_url) . '">editar</a>';
            }
            echo '</td>';

            echo '<td style="min-width:300px;">';
            seo_category_inventory_render_paths($item);
            echo '</td>';

            echo '<td style="min-width:260px;">';
            seo_category_inventory_render_vocab($item['vocabulary']);
            echo '</td>';

            echo '<td style="min-width:240px;"><strong>' . esc_html($faq_active) . '</strong> activas / ' . esc_html(count((array) $item['faqs'])) . ' total';
            echo '<details><summary>ver FAQs</summary>';
            seo_category_inventory_render_faqs($item['faqs']);
            echo '</details></td>';

            echo '<td style="min-width:240px;"><strong>' . esc_html($posts_published) . '</strong> publicados / ' . esc_html(count((array) $item['posts'])) . ' relaciones';
            echo '<details><summary>ver posts</summary>';
            seo_category_inventory_render_content($item['posts']);
            echo '</details></td>';

            echo '<td style="min-width:240px;"><strong>' . esc_html($landings_published) . '</strong> publicadas / ' . esc_html(count((array) $item['landings'])) . ' relaciones';
            echo '<details><summary>ver landings</summary>';
            seo_category_inventory_render_content($item['landings']);
            echo '</details></td>';

            echo '<td><strong style="font-size:18px;">' . esc_html(number_format_i18n($item['products'])) . '</strong></td>';
            echo '<td><strong style="font-size:18px;">' . esc_html($editorial_load) . '</strong><br><small>' . esc_html($posts_published) . ' posts + ' . esc_html($landings_published) . ' landings</small></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        if ($total_pages > 1) {
            echo '<div style="display:flex;align-items:center;gap:10px;margin-top:14px;">';
            if ($paged > 1) {
                $prev_args = array(
                    'page' => $page_slug,
                    'tab' => 'inventario',
                    'category_inventory_page' => $paged - 1,
                );
                if ($search !== '') {
                    $prev_args['category_inventory_q'] = $search;
                }
                echo '<a class="button" href="' . esc_url(add_query_arg($prev_args, admin_url('admin.php'))) . '">&laquo; Anterior</a>';
            }

            echo '<span>Pagina ' . esc_html($paged) . ' de ' . esc_html($total_pages) . '</span>';

            if ($paged < $total_pages) {
                $next_args = array(
                    'page' => $page_slug,
                    'tab' => 'inventario',
                    'category_inventory_page' => $paged + 1,
                );
                if ($search !== '') {
                    $next_args['category_inventory_q'] = $search;
                }
                echo '<a class="button" href="' . esc_url(add_query_arg($next_args, admin_url('admin.php'))) . '">Siguiente &raquo;</a>';
            }
            echo '</div>';
        }

        echo '</div>';
    }
}

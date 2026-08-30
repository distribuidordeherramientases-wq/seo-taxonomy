<?php
/**
 * Inventario de contenido relacionado por categoria de producto.
 *
 * Centro de control por product_cat:
 * - Jerarquia SEO superior (cluster > hub primario > hub secundario).
 * - Vocabulary canonico y etiquetas legacy de la plantilla.
 * - FAQs activas.
 * - Posts relacionados.
 * - Landings relacionadas.
 * - Productos publicados directos y contando subcategorias.
 *
 * El indicador de "carga URL" cuenta unicamente posts + landings publicados.
 * No es un diagnostico automatico de canibalizacion; sirve para priorizar revision.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_category_info_related_table_exists')) {
    function seo_category_info_related_table_exists($table_name) {
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

if (!function_exists('seo_category_info_related_placeholders')) {
    function seo_category_info_related_placeholders($values, $placeholder = '%d') {
        $values = array_values((array) $values);
        if (!$values) {
            return '';
        }
        return implode(',', array_fill(0, count($values), $placeholder));
    }
}

if (!function_exists('seo_category_info_related_post_data')) {
    function seo_category_info_related_post_data($post_id) {
        $post_id = absint($post_id);
        if ($post_id < 1) {
            return null;
        }

        $post = get_post($post_id);
        if (!$post) {
            return null;
        }

        return array(
            'id'     => $post_id,
            'title'  => get_the_title($post_id) ?: ('#' . $post_id),
            'status' => (string) $post->post_status,
            'type'   => (string) $post->post_type,
            'url'    => get_permalink($post_id) ?: '',
            'edit'   => get_edit_post_link($post_id, '') ?: '',
        );
    }
}

if (!function_exists('seo_category_info_related_linked_node')) {
    function seo_category_info_related_linked_node($post_id, $label = '') {
        $data = seo_category_info_related_post_data($post_id);
        if (!$data) {
            return '';
        }

        $text = $label !== '' ? $label : $data['title'];
        $html = '';

        if ($data['status'] === 'publish' && $data['url'] !== '') {
            $html = '<a href="' . esc_url($data['url']) . '" target="_blank" rel="noopener">' . esc_html($text) . '</a>';
        } else {
            $html = esc_html($text);
        }

        if ($data['status'] !== 'publish') {
            $html .= ' <span class="cir-status cir-status-' . esc_attr(sanitize_html_class($data['status'] ?: 'unknown')) . '">' . esc_html(seo_category_info_related_status_label($data['status'])) . '</span>';
        }

        return $html;
    }
}

if (!function_exists('seo_category_info_related_add_path')) {
    function seo_category_info_related_add_path(&$paths, $cluster_id, $primary_id, $secondary_id) {
        $cluster_id   = absint($cluster_id);
        $primary_id   = absint($primary_id);
        $secondary_id = absint($secondary_id);

        if (!$cluster_id && !$primary_id && !$secondary_id) {
            return;
        }

        $key = $cluster_id . ':' . $primary_id . ':' . $secondary_id;
        if (isset($paths[$key])) {
            return;
        }

        $paths[$key] = array(
            'cluster'       => $cluster_id,
            'hub_primary'   => $primary_id,
            'hub_secondary' => $secondary_id,
        );
    }
}

if (!function_exists('seo_category_info_related_load_level')) {
    function seo_category_info_related_load_level($url_count) {
        $url_count = max(0, absint($url_count));

        if ($url_count >= 6) {
            return array('key' => 'high', 'label' => 'Alta', 'color' => '#b32d2e', 'background' => '#fcf0f1');
        }
        if ($url_count >= 3) {
            return array('key' => 'medium', 'label' => 'Media', 'color' => '#8a6d1d', 'background' => '#fff8e5');
        }

        return array('key' => 'low', 'label' => 'Baja', 'color' => '#006505', 'background' => '#edfaef');
    }
}

if (!function_exists('seo_category_info_related_status_label')) {
    function seo_category_info_related_status_label($status) {
        $status = sanitize_key((string) $status);
        $labels = array(
            'publish' => 'publicado',
            'draft'   => 'borrador',
            'pending' => 'pendiente',
            'private' => 'privado',
            'future'  => 'programado',
            'trash'   => 'papelera',
        );

        return isset($labels[$status]) ? $labels[$status] : ($status !== '' ? $status : 'desconocido');
    }
}

if (!function_exists('seo_category_info_related_collect_data')) {
    function seo_category_info_related_collect_data($categories) {
        global $wpdb;

        $categories = array_values((array) $categories);
        $category_ids = array_values(array_filter(array_map(static function ($term) {
            return absint($term->term_id ?? 0);
        }, $categories)));

        $result = array(
            'items' => array(),
            'tables' => array(
                'relations'  => false,
                'faq'        => false,
                'vocabulary' => false,
                'legacy'     => false,
            ),
        );

        if (!$category_ids) {
            return $result;
        }

        foreach ($categories as $term) {
            $term_id = absint($term->term_id ?? 0);
            if ($term_id < 1) {
                continue;
            }

            $result['items'][$term_id] = array(
                'term'               => $term,
                'paths'              => array(),
                'vocabulary'         => array(),
                'legacy_tags'        => array(),
                'faqs'               => array(),
                'posts'              => array(),
                'landings'           => array(),
                'products_direct'    => 0,
                'products_inclusive' => 0,
                'children'           => 0,
            );
        }

        $id_placeholders = seo_category_info_related_placeholders($category_ids, '%d');

        /* --------------------------------------------------------------
         * Relaciones SEO: jerarquia, posts y landings.
         * ----------------------------------------------------------- */
        $relations_table = $wpdb->prefix . 'seo_relations';
        if (seo_category_info_related_table_exists($relations_table)) {
            $result['tables']['relations'] = true;

            $category_relation_types = array(
                'cluster_to_category',
                'hub_primary_to_category',
                'hub_secondary_to_category',
                'post_to_category',
                'landing_to_category',
            );
            $relation_placeholders = seo_category_info_related_placeholders($category_relation_types, '%s');
            $params = array_merge($category_relation_types, $category_ids);

            $category_relations = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, source_id, source_type, target_id, target_type, relation_type\n"
                    . "FROM {$relations_table}\n"
                    . "WHERE target_type = 'product_cat'\n"
                    . "  AND relation_type IN ({$relation_placeholders})\n"
                    . "  AND target_id IN ({$id_placeholders})\n"
                    . "ORDER BY id ASC",
                    ...$params
                ),
                ARRAY_A
            );

            $structural_rows = $wpdb->get_results(
                "SELECT id, source_id, source_type, target_id, target_type, relation_type\n"
                . "FROM {$relations_table}\n"
                . "WHERE relation_type IN ('hub_primary_to_hub_secondary','cluster_to_primary','cluster_to_hub_primary')\n"
                . "ORDER BY id ASC",
                ARRAY_A
            );

            $primary_by_secondary = array();
            $cluster_by_primary   = array();

            foreach ((array) $structural_rows as $row) {
                $source_id = absint($row['source_id'] ?? 0);
                $target_id = absint($row['target_id'] ?? 0);
                $type      = (string) ($row['relation_type'] ?? '');

                if (!$source_id || !$target_id) {
                    continue;
                }

                if ($type === 'hub_primary_to_hub_secondary') {
                    $primary_by_secondary[$target_id][] = $source_id;
                } elseif (in_array($type, array('cluster_to_primary', 'cluster_to_hub_primary'), true)) {
                    $cluster_by_primary[$target_id][] = $source_id;
                }
            }

            foreach ($primary_by_secondary as $id => $values) {
                $primary_by_secondary[$id] = array_values(array_unique(array_filter(array_map('absint', $values))));
            }
            foreach ($cluster_by_primary as $id => $values) {
                $cluster_by_primary[$id] = array_values(array_unique(array_filter(array_map('absint', $values))));
            }

            $source_content_ids = array();
            foreach ((array) $category_relations as $row) {
                $term_id       = absint($row['target_id'] ?? 0);
                $source_id     = absint($row['source_id'] ?? 0);
                $relation_type = (string) ($row['relation_type'] ?? '');

                if (!isset($result['items'][$term_id]) || !$source_id) {
                    continue;
                }

                if ($relation_type === 'post_to_category' || $relation_type === 'landing_to_category') {
                    $source_content_ids[$source_id] = true;
                }
            }

            $content_map = array();
            foreach (array_keys($source_content_ids) as $source_id) {
                $post_data = seo_category_info_related_post_data($source_id);
                if ($post_data) {
                    $content_map[$source_id] = $post_data;
                }
            }

            foreach ((array) $category_relations as $row) {
                $term_id       = absint($row['target_id'] ?? 0);
                $source_id     = absint($row['source_id'] ?? 0);
                $relation_type = (string) ($row['relation_type'] ?? '');

                if (!isset($result['items'][$term_id]) || !$source_id) {
                    continue;
                }

                if ($relation_type === 'hub_secondary_to_category') {
                    $primary_ids = $primary_by_secondary[$source_id] ?? array();
                    if (!$primary_ids) {
                        seo_category_info_related_add_path($result['items'][$term_id]['paths'], 0, 0, $source_id);
                    } else {
                        foreach ($primary_ids as $primary_id) {
                            $cluster_ids = $cluster_by_primary[$primary_id] ?? array();
                            if (!$cluster_ids) {
                                seo_category_info_related_add_path($result['items'][$term_id]['paths'], 0, $primary_id, $source_id);
                            } else {
                                foreach ($cluster_ids as $cluster_id) {
                                    seo_category_info_related_add_path($result['items'][$term_id]['paths'], $cluster_id, $primary_id, $source_id);
                                }
                            }
                        }
                    }
                } elseif ($relation_type === 'hub_primary_to_category') {
                    $cluster_ids = $cluster_by_primary[$source_id] ?? array();
                    if (!$cluster_ids) {
                        seo_category_info_related_add_path($result['items'][$term_id]['paths'], 0, $source_id, 0);
                    } else {
                        foreach ($cluster_ids as $cluster_id) {
                            seo_category_info_related_add_path($result['items'][$term_id]['paths'], $cluster_id, $source_id, 0);
                        }
                    }
                } elseif ($relation_type === 'cluster_to_category') {
                    seo_category_info_related_add_path($result['items'][$term_id]['paths'], $source_id, 0, 0);
                } elseif ($relation_type === 'post_to_category') {
                    if (isset($content_map[$source_id])) {
                        $result['items'][$term_id]['posts'][$source_id] = $content_map[$source_id];
                    }
                } elseif ($relation_type === 'landing_to_category') {
                    if (isset($content_map[$source_id])) {
                        $result['items'][$term_id]['landings'][$source_id] = $content_map[$source_id];
                    }
                }
            }
        }

        /* --------------------------------------------------------------
         * Vocabulary canonico de categoria.
         * ----------------------------------------------------------- */
        $object_vocab_table = $wpdb->prefix . 'seo_object_vocabulary';
        $vocab_table        = $wpdb->prefix . 'seo_vocabulary';

        if (
            seo_category_info_related_table_exists($object_vocab_table)
            && seo_category_info_related_table_exists($vocab_table)
        ) {
            $result['tables']['vocabulary'] = true;

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ov.object_id, v.semantic_group, v.label, v.slug\n"
                    . "FROM {$object_vocab_table} ov\n"
                    . "INNER JOIN {$vocab_table} v ON v.id = ov.vocabulary_id\n"
                    . "WHERE ov.object_type = 'product_cat'\n"
                    . "  AND ov.status = 1\n"
                    . "  AND v.active = 1\n"
                    . "  AND ov.object_id IN ({$id_placeholders})\n"
                    . "ORDER BY ov.object_id ASC, FIELD(v.semantic_group,'rol','tipo','aplicacion','plataforma','subtipo'), v.label ASC",
                    ...$category_ids
                ),
                ARRAY_A
            );

            foreach ((array) $rows as $row) {
                $term_id = absint($row['object_id'] ?? 0);
                $group   = sanitize_key((string) ($row['semantic_group'] ?? ''));
                $label   = trim((string) ($row['label'] ?? $row['slug'] ?? ''));

                if (!isset($result['items'][$term_id]) || $group === '' || $label === '') {
                    continue;
                }

                if (!isset($result['items'][$term_id]['vocabulary'][$group])) {
                    $result['items'][$term_id]['vocabulary'][$group] = array();
                }
                if (!in_array($label, $result['items'][$term_id]['vocabulary'][$group], true)) {
                    $result['items'][$term_id]['vocabulary'][$group][] = $label;
                }
            }
        }

        /* --------------------------------------------------------------
         * Keywords legacy que la plantilla de categoria puede mostrar.
         * ----------------------------------------------------------- */
        $nodes_table = $wpdb->prefix . 'seo_nodes';
        if (seo_category_info_related_table_exists($nodes_table)) {
            $result['tables']['legacy'] = true;

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT object_id, keywords, id\n"
                    . "FROM {$nodes_table}\n"
                    . "WHERE object_type = 'category'\n"
                    . "  AND seo_role = 'category'\n"
                    . "  AND status = 1\n"
                    . "  AND object_id IN ({$id_placeholders})\n"
                    . "ORDER BY object_id ASC, id DESC",
                    ...$category_ids
                ),
                ARRAY_A
            );

            $seen_legacy = array();
            foreach ((array) $rows as $row) {
                $term_id = absint($row['object_id'] ?? 0);
                if (!isset($result['items'][$term_id]) || isset($seen_legacy[$term_id])) {
                    continue;
                }
                $seen_legacy[$term_id] = true;

                $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($row['keywords'] ?? ''))))));
                $result['items'][$term_id]['legacy_tags'] = $tags;
            }
        }

        /* --------------------------------------------------------------
         * FAQs de categoria. object_type = 2 segun template-faq.php.
         * ----------------------------------------------------------- */
        $faq_table = $wpdb->prefix . 'seo_faq';
        if (seo_category_info_related_table_exists($faq_table)) {
            $result['tables']['faq'] = true;

            $faq_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, object_id, question, active, sort_order\n"
                    . "FROM {$faq_table}\n"
                    . "WHERE object_type = 2\n"
                    . "  AND object_id IN ({$id_placeholders})\n"
                    . "ORDER BY object_id ASC, active DESC, sort_order ASC, id ASC",
                    ...$category_ids
                ),
                ARRAY_A
            );

            foreach ((array) $faq_rows as $row) {
                $term_id = absint($row['object_id'] ?? 0);
                if (!isset($result['items'][$term_id])) {
                    continue;
                }
                $result['items'][$term_id]['faqs'][] = array(
                    'id'       => absint($row['id'] ?? 0),
                    'question' => trim((string) ($row['question'] ?? '')),
                    'active'   => absint($row['active'] ?? 0) === 1,
                );
            }
        }

        /* --------------------------------------------------------------
         * Productos publicados: directos + total por arbol de product_cat.
         * Se cuenta cada producto una sola vez por ancestro aunque tenga varias
         * asignaciones dentro de la misma rama.
         * ----------------------------------------------------------- */
        $parent_map = array();
        $child_count = array();
        foreach ($categories as $term) {
            $term_id = absint($term->term_id ?? 0);
            $parent  = absint($term->parent ?? 0);
            if ($term_id < 1) {
                continue;
            }
            $parent_map[$term_id] = $parent;
            if ($parent > 0) {
                $child_count[$parent] = ($child_count[$parent] ?? 0) + 1;
            }
        }
        foreach ($result['items'] as $term_id => &$item) {
            $item['children'] = absint($child_count[$term_id] ?? 0);
        }
        unset($item);

        $product_rows = $wpdb->get_results(
            "SELECT DISTINCT p.ID AS product_id, tt.term_id\n"
            . "FROM {$wpdb->posts} p\n"
            . "INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID\n"
            . "INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id\n"
            . "WHERE p.post_type = 'product'\n"
            . "  AND p.post_status = 'publish'\n"
            . "  AND tt.taxonomy = 'product_cat'\n"
            . "ORDER BY p.ID ASC, tt.term_id ASC",
            ARRAY_A
        );

        $current_product_id = 0;
        $current_terms = array();

        $flush_product = static function ($product_id, $term_ids) use (&$result, $parent_map) {
            $product_id = absint($product_id);
            if ($product_id < 1 || !$term_ids) {
                return;
            }

            $tree_terms = array();
            foreach (array_values(array_unique(array_filter(array_map('absint', $term_ids)))) as $term_id) {
                if (isset($result['items'][$term_id])) {
                    $result['items'][$term_id]['products_direct']++;
                }

                $guard = 0;
                $cursor = $term_id;
                while ($cursor > 0 && $guard < 50) {
                    $tree_terms[$cursor] = true;
                    $cursor = absint($parent_map[$cursor] ?? 0);
                    $guard++;
                }
            }

            foreach (array_keys($tree_terms) as $term_id) {
                if (isset($result['items'][$term_id])) {
                    $result['items'][$term_id]['products_inclusive']++;
                }
            }
        };

        foreach ((array) $product_rows as $row) {
            $product_id = absint($row['product_id'] ?? 0);
            $term_id    = absint($row['term_id'] ?? 0);
            if ($product_id < 1 || $term_id < 1) {
                continue;
            }

            if ($current_product_id && $current_product_id !== $product_id) {
                $flush_product($current_product_id, $current_terms);
                $current_terms = array();
            }

            $current_product_id = $product_id;
            $current_terms[] = $term_id;
        }
        if ($current_product_id) {
            $flush_product($current_product_id, $current_terms);
        }

        return $result;
    }
}

if (!function_exists('seo_category_info_related_count_published')) {
    function seo_category_info_related_count_published($items) {
        $count = 0;
        foreach ((array) $items as $item) {
            if (($item['status'] ?? '') === 'publish') {
                $count++;
            }
        }
        return $count;
    }
}

if (!function_exists('seo_category_info_related_label_count')) {
    function seo_category_info_related_label_count($vocabulary, $legacy_tags) {
        $labels = array();

        foreach ((array) $vocabulary as $values) {
            foreach ((array) $values as $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                $key = remove_accents(mb_strtolower($value, 'UTF-8'));
                $labels[$key] = true;
            }
        }

        foreach ((array) $legacy_tags as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $key = remove_accents(mb_strtolower($value, 'UTF-8'));
            $labels[$key] = true;
        }

        return count($labels);
    }
}

if (!function_exists('seo_category_info_related_render_content_list')) {
    function seo_category_info_related_render_content_list($items) {
        if (!$items) {
            echo '<span class="cir-muted">Ninguno.</span>';
            return;
        }

        echo '<ul class="cir-compact-list">';
        foreach ((array) $items as $item) {
            $title  = (string) ($item['title'] ?? ('#' . absint($item['id'] ?? 0)));
            $status = (string) ($item['status'] ?? '');
            $url    = (string) ($item['url'] ?? '');
            $edit   = (string) ($item['edit'] ?? '');

            echo '<li>';
            if ($url !== '' && $status === 'publish') {
                echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener"><strong>' . esc_html($title) . '</strong></a>';
            } else {
                echo '<strong>' . esc_html($title) . '</strong>';
            }
            echo ' <span class="cir-status cir-status-' . esc_attr(sanitize_html_class($status ?: 'unknown')) . '">' . esc_html(seo_category_info_related_status_label($status)) . '</span>';
            if ($edit !== '') {
                echo ' <a class="cir-edit" href="' . esc_url($edit) . '">editar</a>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}

if (!function_exists('seo_category_info_related_render_paths')) {
    function seo_category_info_related_render_paths($paths) {
        $paths = array_values((array) $paths);
        if (!$paths) {
            echo '<span class="cir-alert-text">Sin jerarquia SEO superior enlazada</span>';
            return;
        }

        echo '<div class="cir-paths">';
        foreach ($paths as $path) {
            $parts = array();
            $cluster_id   = absint($path['cluster'] ?? 0);
            $primary_id   = absint($path['hub_primary'] ?? 0);
            $secondary_id = absint($path['hub_secondary'] ?? 0);

            if ($cluster_id) {
                $parts[] = '<span class="cir-node cir-node-cluster">Cluster: ' . seo_category_info_related_linked_node($cluster_id) . '</span>';
            }
            if ($primary_id) {
                $parts[] = '<span class="cir-node cir-node-primary">Hub P.: ' . seo_category_info_related_linked_node($primary_id) . '</span>';
            }
            if ($secondary_id) {
                $parts[] = '<span class="cir-node cir-node-secondary">Hub S.: ' . seo_category_info_related_linked_node($secondary_id) . '</span>';
            }

            echo '<div class="cir-path">' . implode('<span class="cir-arrow">›</span>', $parts) . '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('seo_category_info_related_render_labels')) {
    function seo_category_info_related_render_labels($vocabulary, $legacy_tags) {
        $vocabulary = (array) $vocabulary;
        $legacy_tags = array_values((array) $legacy_tags);

        $group_labels = array(
            'rol'        => 'Rol',
            'tipo'       => 'Tipo',
            'aplicacion' => 'Aplicacion',
            'plataforma' => 'Plataforma',
            'subtipo'    => 'Subtipo',
        );

        if (!$vocabulary && !$legacy_tags) {
            echo '<span class="cir-muted">Sin etiquetas.</span>';
            return;
        }

        if ($vocabulary) {
            echo '<div class="cir-label-block"><strong>Vocabulary:</strong>';
            foreach ($group_labels as $group => $label) {
                if (empty($vocabulary[$group])) {
                    continue;
                }
                echo '<div class="cir-label-line"><span class="cir-label-group">' . esc_html($label) . ':</span> ';
                foreach ((array) $vocabulary[$group] as $value) {
                    echo '<span class="cir-chip">' . esc_html($value) . '</span> ';
                }
                echo '</div>';
            }
            echo '</div>';
        }

        if ($legacy_tags) {
            echo '<div class="cir-label-block cir-legacy"><strong>Legacy / plantilla:</strong><div class="cir-label-line">';
            foreach ($legacy_tags as $value) {
                echo '<span class="cir-chip cir-chip-legacy">' . esc_html($value) . '</span> ';
            }
            echo '</div></div>';
        }
    }
}

if (!function_exists('seo_category_info_related_render_faqs')) {
    function seo_category_info_related_render_faqs($faqs) {
        $faqs = array_values((array) $faqs);
        if (!$faqs) {
            echo '<span class="cir-muted">Sin FAQs.</span>';
            return;
        }

        echo '<ul class="cir-compact-list">';
        foreach ($faqs as $faq) {
            $active = !empty($faq['active']);
            echo '<li>' . esc_html((string) ($faq['question'] ?? ''));
            echo ' <span class="cir-status ' . ($active ? 'cir-status-publish' : 'cir-status-draft') . '">' . ($active ? 'activa' : 'inactiva') . '</span>';
            echo '</li>';
        }
        echo '</ul>';
    }
}

if (!function_exists('seo_render_category_info_related')) {
    function seo_render_category_info_related($page_slug = 'category-seo-admin') {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos suficientes para ver este informe.');
        }

        $categories = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));

        if (is_wp_error($categories)) {
            echo '<div class="notice notice-error inline"><p>No se pudieron cargar las categorias: ' . esc_html($categories->get_error_message()) . '</p></div>';
            return;
        }

        $dataset = seo_category_info_related_collect_data($categories);
        $items   = array_values($dataset['items']);

        $query  = isset($_GET['category_related_q']) ? sanitize_text_field(wp_unslash($_GET['category_related_q'])) : '';
        $filter = isset($_GET['category_related_filter']) ? sanitize_key($_GET['category_related_filter']) : 'all';
        $sort   = isset($_GET['category_related_sort']) ? sanitize_key($_GET['category_related_sort']) : 'load_desc';

        $allowed_filters = array('all', 'high_load', 'multi_path', 'no_hierarchy', 'no_labels', 'no_faq', 'no_posts', 'no_landings', 'no_products');
        if (!in_array($filter, $allowed_filters, true)) {
            $filter = 'all';
        }
        $allowed_sorts = array('load_desc', 'products_desc', 'faqs_desc', 'name');
        if (!in_array($sort, $allowed_sorts, true)) {
            $sort = 'load_desc';
        }

        $summary = array(
            'categories'    => count($items),
            'no_hierarchy'  => 0,
            'multi_path'    => 0,
            'high_load'     => 0,
            'no_products'   => 0,
            'published_posts'    => 0,
            'published_landings' => 0,
        );

        foreach ($items as &$item) {
            $published_posts    = seo_category_info_related_count_published($item['posts']);
            $published_landings = seo_category_info_related_count_published($item['landings']);
            $active_faqs = count(array_filter((array) $item['faqs'], static function ($faq) {
                return !empty($faq['active']);
            }));
            $label_count = seo_category_info_related_label_count($item['vocabulary'], $item['legacy_tags']);
            $load_count  = $published_posts + $published_landings;

            $item['_published_posts']    = $published_posts;
            $item['_published_landings'] = $published_landings;
            $item['_active_faqs']        = $active_faqs;
            $item['_label_count']        = $label_count;
            $item['_load_count']         = $load_count;
            $item['_load_level']         = seo_category_info_related_load_level($load_count);

            if (empty($item['paths'])) {
                $summary['no_hierarchy']++;
            }
            if (count((array) $item['paths']) > 1) {
                $summary['multi_path']++;
            }
            if ($item['_load_level']['key'] === 'high') {
                $summary['high_load']++;
            }
            if (absint($item['products_inclusive']) === 0) {
                $summary['no_products']++;
            }
            $summary['published_posts'] += $published_posts;
            $summary['published_landings'] += $published_landings;
        }
        unset($item);

        $needle = trim(remove_accents(mb_strtolower($query, 'UTF-8')));
        $items = array_values(array_filter($items, static function ($item) use ($needle, $filter) {
            $term = $item['term'];
            if ($needle !== '') {
                $haystack = remove_accents(mb_strtolower((string) ($term->name ?? '') . ' ' . (string) ($term->slug ?? ''), 'UTF-8'));
                if (strpos($haystack, $needle) === false) {
                    return false;
                }
            }

            switch ($filter) {
                case 'high_load':
                    return ($item['_load_level']['key'] ?? '') === 'high';
                case 'multi_path':
                    return count((array) $item['paths']) > 1;
                case 'no_hierarchy':
                    return empty($item['paths']);
                case 'no_labels':
                    return absint($item['_label_count']) === 0;
                case 'no_faq':
                    return absint($item['_active_faqs']) === 0;
                case 'no_posts':
                    return absint($item['_published_posts']) === 0;
                case 'no_landings':
                    return absint($item['_published_landings']) === 0;
                case 'no_products':
                    return absint($item['products_inclusive']) === 0;
                default:
                    return true;
            }
        }));

        usort($items, static function ($a, $b) use ($sort) {
            if ($sort === 'products_desc') {
                $cmp = absint($b['products_inclusive']) <=> absint($a['products_inclusive']);
            } elseif ($sort === 'faqs_desc') {
                $cmp = absint($b['_active_faqs']) <=> absint($a['_active_faqs']);
            } elseif ($sort === 'name') {
                $cmp = strcasecmp((string) $a['term']->name, (string) $b['term']->name);
            } else {
                $cmp = absint($b['_load_count']) <=> absint($a['_load_count']);
            }

            if ($cmp !== 0) {
                return $cmp;
            }
            return strcasecmp((string) $a['term']->name, (string) $b['term']->name);
        });

        ?>
        <style>
            .cir-wrap{max-width:100%;}
            .cir-intro{background:#fff;border:1px solid #dcdcde;border-left:5px solid #2271b1;border-radius:6px;padding:14px 16px;margin:0 0 16px;}
            .cir-intro p{margin:5px 0;}
            .cir-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px;margin:0 0 16px;}
            .cir-card{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:12px 14px;}
            .cir-card strong{display:block;font-size:24px;line-height:1.1;margin-top:4px;}
            .cir-card span{font-size:11px;color:#646970;text-transform:uppercase;font-weight:700;letter-spacing:.02em;}
            .cir-filterbar{display:flex;gap:8px;align-items:end;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:12px;margin-bottom:14px;}
            .cir-filterbar label{display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:700;color:#50575e;}
            .cir-filterbar input,.cir-filterbar select{min-width:180px;}
            .cir-table-wrap{overflow:auto;background:#fff;border:1px solid #dcdcde;border-radius:7px;}
            .cir-table{border-collapse:collapse;width:100%;min-width:1320px;}
            .cir-table th{position:sticky;top:32px;z-index:2;background:#f6f7f7;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.02em;color:#50575e;border-bottom:1px solid #c3c4c7;padding:9px;}
            .cir-table td{vertical-align:top;border-bottom:1px solid #ececec;padding:10px;font-size:12px;line-height:1.45;}
            .cir-table tr:last-child td{border-bottom:0;}
            .cir-category{min-width:190px;}
            .cir-category-title{font-size:14px;font-weight:700;display:block;margin-bottom:4px;}
            .cir-meta{color:#646970;font-size:11px;}
            .cir-paths{min-width:280px;display:flex;flex-direction:column;gap:6px;}
            .cir-path{display:flex;align-items:center;gap:4px;flex-wrap:wrap;}
            .cir-node{display:inline-block;padding:2px 5px;border-radius:4px;background:#f6f7f7;border:1px solid #e0e0e0;}
            .cir-node a{text-decoration:none;}
            .cir-node-cluster{background:#f0f0f1;}
            .cir-node-primary{background:#edf5ff;}
            .cir-node-secondary{background:#f4f9ff;}
            .cir-arrow{color:#8c8f94;font-weight:700;}
            .cir-chip{display:inline-block;background:#eef4fb;border:1px solid #c8def5;border-radius:999px;padding:1px 6px;margin:1px 1px 1px 0;font-size:10px;}
            .cir-chip-legacy{background:#fff7ed;border-color:#fed7aa;}
            .cir-label-block{margin-bottom:6px;min-width:210px;}
            .cir-label-line{margin-top:3px;}
            .cir-label-group{font-size:10px;color:#646970;text-transform:uppercase;font-weight:700;}
            .cir-legacy{border-top:1px dashed #dcdcde;padding-top:5px;margin-top:5px;}
            .cir-count{font-size:18px;font-weight:700;display:block;line-height:1.1;}
            .cir-subcount{font-size:10px;color:#646970;display:block;margin-top:3px;}
            .cir-compact-list{margin:6px 0 0 17px;max-width:330px;}
            .cir-compact-list li{margin:0 0 4px;}
            .cir-status{display:inline-block;border-radius:999px;padding:1px 5px;font-size:9px;font-weight:700;background:#f0f0f1;color:#50575e;}
            .cir-status-publish{background:#edfaef;color:#006505;}
            .cir-status-draft,.cir-status-pending{background:#fff8e5;color:#8a6d1d;}
            .cir-status-private,.cir-status-trash{background:#fcf0f1;color:#b32d2e;}
            .cir-edit{font-size:10px;margin-left:3px;}
            .cir-muted{color:#8c8f94;font-style:italic;}
            .cir-alert-text{color:#b32d2e;font-weight:600;}
            .cir-details summary{cursor:pointer;color:#2271b1;font-size:11px;margin-top:4px;}
            .cir-load{display:inline-block;border-radius:999px;padding:3px 7px;font-size:10px;font-weight:700;white-space:nowrap;}
            .cir-note{font-size:11px;color:#646970;margin:10px 0 0;}
            @media(max-width:782px){.cir-table th{top:46px}.cir-filterbar input,.cir-filterbar select{min-width:150px;width:100%;}.cir-filterbar label{width:100%;}}
        </style>

        <div class="cir-wrap">
            <div class="cir-intro">
                <h2 style="margin:0 0 6px;">Inventario por categoria</h2>
                <p>La categoria se usa como centro del mapa: arriba se muestra su jerarquia SEO y alrededor se inventarian etiquetas, FAQs, posts, landings y productos.</p>
                <p><strong>Carga URL</strong> = posts publicados + landings publicadas relacionados con la categoria. Es una alarma visual para revisar solapamientos, no una conclusion automatica de canibalizacion.</p>
            </div>

            <div class="cir-cards">
                <div class="cir-card"><span>Categorias</span><strong><?php echo esc_html(number_format_i18n($summary['categories'])); ?></strong></div>
                <div class="cir-card"><span>Sin jerarquia SEO</span><strong><?php echo esc_html(number_format_i18n($summary['no_hierarchy'])); ?></strong></div>
                <div class="cir-card"><span>Varias rutas</span><strong><?php echo esc_html(number_format_i18n($summary['multi_path'])); ?></strong></div>
                <div class="cir-card"><span>Carga URL alta (6+)</span><strong><?php echo esc_html(number_format_i18n($summary['high_load'])); ?></strong></div>
                <div class="cir-card"><span>Sin productos</span><strong><?php echo esc_html(number_format_i18n($summary['no_products'])); ?></strong></div>
                <div class="cir-card"><span>Posts publicados relacionados</span><strong><?php echo esc_html(number_format_i18n($summary['published_posts'])); ?></strong></div>
                <div class="cir-card"><span>Landings publicadas relacionadas</span><strong><?php echo esc_html(number_format_i18n($summary['published_landings'])); ?></strong></div>
            </div>

            <form method="get" class="cir-filterbar">
                <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
                <input type="hidden" name="tab" value="inventario">
                <label>Buscar categoria
                    <input type="search" name="category_related_q" value="<?php echo esc_attr($query); ?>" placeholder="Nombre o slug">
                </label>
                <label>Filtro
                    <select name="category_related_filter">
                        <option value="all" <?php selected($filter, 'all'); ?>>Todas</option>
                        <option value="high_load" <?php selected($filter, 'high_load'); ?>>Carga URL alta (6+)</option>
                        <option value="multi_path" <?php selected($filter, 'multi_path'); ?>>Varias rutas superiores</option>
                        <option value="no_hierarchy" <?php selected($filter, 'no_hierarchy'); ?>>Sin jerarquia SEO</option>
                        <option value="no_labels" <?php selected($filter, 'no_labels'); ?>>Sin etiquetas</option>
                        <option value="no_faq" <?php selected($filter, 'no_faq'); ?>>Sin FAQs activas</option>
                        <option value="no_posts" <?php selected($filter, 'no_posts'); ?>>Sin posts publicados</option>
                        <option value="no_landings" <?php selected($filter, 'no_landings'); ?>>Sin landings publicadas</option>
                        <option value="no_products" <?php selected($filter, 'no_products'); ?>>Sin productos publicados</option>
                    </select>
                </label>
                <label>Ordenar
                    <select name="category_related_sort">
                        <option value="load_desc" <?php selected($sort, 'load_desc'); ?>>Carga URL: mayor primero</option>
                        <option value="products_desc" <?php selected($sort, 'products_desc'); ?>>Productos: mayor primero</option>
                        <option value="faqs_desc" <?php selected($sort, 'faqs_desc'); ?>>FAQs: mayor primero</option>
                        <option value="name" <?php selected($sort, 'name'); ?>>Nombre</option>
                    </select>
                </label>
                <button class="button button-primary" type="submit">Aplicar</button>
                <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => $page_slug, 'tab' => 'inventario'), admin_url('admin.php'))); ?>">Limpiar</a>
            </form>

            <p style="margin:0 0 8px;color:#646970;"><strong><?php echo esc_html(number_format_i18n(count($items))); ?></strong> categorias mostradas.</p>

            <div class="cir-table-wrap">
                <table class="cir-table">
                    <thead>
                        <tr>
                            <th>Categoria</th>
                            <th>Jerarquia superior enlazada</th>
                            <th>Etiquetas</th>
                            <th>FAQs</th>
                            <th>Posts</th>
                            <th>Landings</th>
                            <th>Productos</th>
                            <th>Carga URL</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$items): ?>
                        <tr><td colspan="8"><span class="cir-muted">No hay categorias que coincidan con el filtro.</span></td></tr>
                    <?php else: ?>
                        <?php foreach ($items as $item):
                            $term = $item['term'];
                            $term_id = absint($term->term_id);
                            $term_url = get_term_link($term, 'product_cat');
                            $edit_url = function_exists('seo_get_category_editor_url')
                                ? seo_get_category_editor_url($term_id, $page_slug)
                                : get_edit_term_link($term_id, 'product_cat');
                            $active_faqs = absint($item['_active_faqs']);
                            $inactive_faqs = max(0, count((array) $item['faqs']) - $active_faqs);
                            $post_total = count((array) $item['posts']);
                            $landing_total = count((array) $item['landings']);
                            $load_level = $item['_load_level'];
                        ?>
                        <tr>
                            <td class="cir-category">
                                <span class="cir-category-title"><?php echo esc_html($term->name); ?></span>
                                <div class="cir-meta">ID <?php echo esc_html($term_id); ?> · /<?php echo esc_html($term->slug); ?>/</div>
                                <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;">
                                    <?php if (!is_wp_error($term_url)): ?><a href="<?php echo esc_url($term_url); ?>" target="_blank" rel="noopener">ver</a><?php endif; ?>
                                    <?php if ($edit_url): ?><a href="<?php echo esc_url($edit_url); ?>">editar</a><?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php seo_category_info_related_render_paths($item['paths']); ?>
                                <?php if (count((array) $item['paths']) > 1): ?>
                                    <div class="cir-meta" style="margin-top:5px;color:#8a6d1d;"><strong><?php echo esc_html(count((array) $item['paths'])); ?></strong> rutas superiores: revisar si son intencionales.</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="cir-count"><?php echo esc_html(number_format_i18n($item['_label_count'])); ?></span>
                                <details class="cir-details">
                                    <summary>ver etiquetas</summary>
                                    <?php seo_category_info_related_render_labels($item['vocabulary'], $item['legacy_tags']); ?>
                                </details>
                            </td>
                            <td>
                                <span class="cir-count"><?php echo esc_html(number_format_i18n($active_faqs)); ?></span>
                                <?php if ($inactive_faqs): ?><span class="cir-subcount"><?php echo esc_html($inactive_faqs); ?> inactivas</span><?php endif; ?>
                                <details class="cir-details">
                                    <summary>ver preguntas</summary>
                                    <?php seo_category_info_related_render_faqs($item['faqs']); ?>
                                </details>
                            </td>
                            <td>
                                <span class="cir-count"><?php echo esc_html(number_format_i18n($item['_published_posts'])); ?></span>
                                <?php if ($post_total !== absint($item['_published_posts'])): ?><span class="cir-subcount"><?php echo esc_html($post_total); ?> relaciones totales</span><?php endif; ?>
                                <details class="cir-details">
                                    <summary>ver posts</summary>
                                    <?php seo_category_info_related_render_content_list($item['posts']); ?>
                                </details>
                            </td>
                            <td>
                                <span class="cir-count"><?php echo esc_html(number_format_i18n($item['_published_landings'])); ?></span>
                                <?php if ($landing_total !== absint($item['_published_landings'])): ?><span class="cir-subcount"><?php echo esc_html($landing_total); ?> relaciones totales</span><?php endif; ?>
                                <details class="cir-details">
                                    <summary>ver landings</summary>
                                    <?php seo_category_info_related_render_content_list($item['landings']); ?>
                                </details>
                            </td>
                            <td>
                                <span class="cir-count"><?php echo esc_html(number_format_i18n($item['products_direct'])); ?></span>
                                <span class="cir-subcount">directos</span>
                                <?php if (absint($item['products_inclusive']) !== absint($item['products_direct'])): ?>
                                    <span class="cir-subcount"><strong><?php echo esc_html(number_format_i18n($item['products_inclusive'])); ?></strong> contando subcategorias</span>
                                <?php endif; ?>
                                <?php if (absint($item['children']) > 0): ?><span class="cir-subcount"><?php echo esc_html(number_format_i18n($item['children'])); ?> subcategorias directas</span><?php endif; ?>
                            </td>
                            <td>
                                <span class="cir-count"><?php echo esc_html(number_format_i18n($item['_load_count'])); ?></span>
                                <span class="cir-load" style="color:<?php echo esc_attr($load_level['color']); ?>;background:<?php echo esc_attr($load_level['background']); ?>;">
                                    <?php echo esc_html($load_level['label']); ?>
                                </span>
                                <span class="cir-subcount"><?php echo esc_html($item['_published_posts']); ?> posts + <?php echo esc_html($item['_published_landings']); ?> landings</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="cir-note"><strong>Umbral visual:</strong> baja 0–2 URLs editoriales, media 3–5, alta 6 o mas. Ajustalo si tu modelo editorial necesita otra densidad; el numero por si solo no demuestra canibalizacion.</p>
            <?php if (!$dataset['tables']['relations']): ?><div class="notice notice-warning inline"><p>No se encontro <code>seo_relations</code>; jerarquia, posts y landings no pueden inventariarse.</p></div><?php endif; ?>
            <?php if (!$dataset['tables']['faq']): ?><div class="notice notice-warning inline"><p>No se encontro <code>seo_faq</code>; las FAQs no pueden inventariarse.</p></div><?php endif; ?>
            <?php if (!$dataset['tables']['vocabulary']): ?><div class="notice notice-warning inline"><p>No se encontraron las tablas de Vocabulary canonico; se mostraran solo las etiquetas legacy si existen.</p></div><?php endif; ?>
        </div>
        <?php
    }
}

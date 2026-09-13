<?php
/**
 * Inventario avanzado de productos.
 *
 * Sustituye la vista jerarquica legacy por un inventario paginado y filtrable
 * sobre las fuentes canonicas del producto: product_cat, Vocabulary,
 * atributos, proveedor y datos editoriales de WooCommerce.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_product_inventory_table_exists')) {
    function seo_product_inventory_table_exists($table_name) {
        global $wpdb;

        $table_name = (string) $table_name;
        if ($table_name === '') {
            return false;
        }

        if (function_exists('seo_product_table_exists')) {
            return seo_product_table_exists($table_name);
        }

        return (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))
        ) === $table_name;
    }
}

if (!function_exists('seo_product_inventory_prepare')) {
    function seo_product_inventory_prepare($sql, array $args) {
        global $wpdb;
        return empty($args) ? $sql : $wpdb->prepare($sql, $args);
    }
}

/**
 * Resuelve la jerarquia SEO a la que pertenece una categoria.
 * Se conserva como API publica para enlaces desde otras pantallas.
 */
if (!function_exists('seo_get_category_product_inventory_context')) {
    function seo_get_category_product_inventory_context($category_id) {
        $category_id = absint($category_id);
        $category = get_term($category_id, 'product_cat');

        if (!$category_id || !$category || is_wp_error($category)) {
            return new WP_Error(
                'seo_inventory_category_not_found',
                'La categoria solicitada no existe.'
            );
        }

        global $wpdb;
        $relations_table = $wpdb->prefix . 'seo_relations';

        if (!seo_product_inventory_table_exists($relations_table)) {
            return [
                'cluster'        => 0,
                'hub_primario'   => 0,
                'hub_secundario' => 0,
                'cat'            => $category_id,
            ];
        }

        $hub_secondary_id = absint($wpdb->get_var($wpdb->prepare(
            "SELECT source_id
             FROM {$relations_table}
             WHERE target_id = %d
               AND target_type = 'product_cat'
               AND relation_type = 'hub_secondary_to_category'
             LIMIT 1",
            $category_id
        )));

        $hub_primary_id = 0;
        if ($hub_secondary_id > 0) {
            $hub_primary_id = absint($wpdb->get_var($wpdb->prepare(
                "SELECT source_id
                 FROM {$relations_table}
                 WHERE target_id = %d
                   AND relation_type = 'hub_primary_to_hub_secondary'
                 LIMIT 1",
                $hub_secondary_id
            )));
        }

        $cluster_id = 0;
        if ($hub_primary_id > 0) {
            $cluster_id = absint($wpdb->get_var($wpdb->prepare(
                "SELECT source_id
                 FROM {$relations_table}
                 WHERE target_id = %d
                   AND relation_type = 'cluster_to_primary'
                 LIMIT 1",
                $hub_primary_id
            )));
        }

        return [
            'cluster'        => $cluster_id,
            'hub_primario'   => $hub_primary_id,
            'hub_secundario' => $hub_secondary_id,
            'cat'            => $category_id,
        ];
    }
}

if (!function_exists('seo_get_category_products_inventory_url')) {
    function seo_get_category_products_inventory_url($category_id, $page_slug = 'product-page-admin') {
        $context = seo_get_category_product_inventory_context($category_id);
        if (is_wp_error($context)) {
            return '';
        }

        return add_query_arg(
            array_merge(
                [
                    'page' => sanitize_key($page_slug),
                    'tab'  => 'inventario',
                ],
                $context
            ),
            admin_url('admin.php')
        );
    }
}

if (!function_exists('seo_product_inventory_get_vocabulary_terms')) {
    function seo_product_inventory_get_vocabulary_terms() {
        global $wpdb;

        $groups = [
            'rol'        => [],
            'tipo'       => [],
            'aplicacion' => [],
            'plataforma' => [],
            'subtipo'    => [],
        ];

        $table = $wpdb->prefix . 'seo_vocabulary';
        if (!seo_product_inventory_table_exists($table)) {
            return $groups;
        }

        $rows = $wpdb->get_results(
            "SELECT id, semantic_group, slug, label
             FROM {$table}
             WHERE active = 1
               AND semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
             ORDER BY FIELD(semantic_group,'rol','tipo','aplicacion','plataforma','subtipo'), label ASC, slug ASC",
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            if (isset($groups[$group])) {
                $groups[$group][] = $row;
            }
        }

        return $groups;
    }
}

if (!function_exists('seo_product_inventory_get_attribute_catalog')) {
    function seo_product_inventory_get_attribute_catalog() {
        if (function_exists('seo_attributes_get_catalog')) {
            return (array) seo_attributes_get_catalog(true);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sql_atributos';
        if (!seo_product_inventory_table_exists($table)) {
            return [];
        }

        return (array) $wpdb->get_results(
            "SELECT id, slug, nombre, grupo, tipo
             FROM {$table}
             WHERE activo = 1
             ORDER BY grupo ASC, orden ASC, nombre ASC",
            ARRAY_A
        );
    }
}

if (!function_exists('seo_product_inventory_get_brands')) {
    function seo_product_inventory_get_brands() {
        global $wpdb;
        $values = $wpdb->get_col(
            "SELECT DISTINCT meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_seo_marca_proveedor'
               AND meta_value IS NOT NULL
               AND meta_value <> ''
             ORDER BY meta_value ASC"
        );

        $values = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $values))));
        natcasesort($values);
        return array_values($values);
    }
}

if (!function_exists('seo_product_inventory_get_hierarchy_options')) {
    function seo_product_inventory_get_hierarchy_options($cluster, $hub_primary, $hub_secondary) {
        global $wpdb;

        $relations_table = $wpdb->prefix . 'seo_relations';
        $result = [
            'clusters'        => [],
            'hub_primarios'   => [],
            'hub_secundarios' => [],
            'category_ids'    => null,
        ];

        if (!seo_product_inventory_table_exists($relations_table)) {
            return $result;
        }

        $result['clusters'] = array_map('absint', (array) $wpdb->get_col(
            "SELECT DISTINCT source_id
             FROM {$relations_table}
             WHERE relation_type = 'cluster_to_primary'
               AND source_id > 0
             ORDER BY source_id ASC"
        ));

        if ($cluster > 0) {
            $result['hub_primarios'] = array_map('absint', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$relations_table}
                 WHERE source_id = %d
                   AND relation_type = 'cluster_to_primary'
                 ORDER BY target_id ASC",
                $cluster
            )));
        } else {
            $result['hub_primarios'] = array_map('absint', (array) $wpdb->get_col(
                "SELECT DISTINCT target_id
                 FROM {$relations_table}
                 WHERE relation_type = 'cluster_to_primary'
                   AND target_id > 0
                 ORDER BY target_id ASC"
            ));
        }

        if ($hub_primary > 0) {
            $result['hub_secundarios'] = array_map('absint', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$relations_table}
                 WHERE source_id = %d
                   AND relation_type = 'hub_primary_to_hub_secondary'
                 ORDER BY target_id ASC",
                $hub_primary
            )));
        } elseif ($cluster > 0 && !empty($result['hub_primarios'])) {
            $placeholders = implode(',', array_fill(0, count($result['hub_primarios']), '%d'));
            $result['hub_secundarios'] = array_map('absint', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$relations_table}
                 WHERE source_id IN ({$placeholders})
                   AND relation_type = 'hub_primary_to_hub_secondary'
                 ORDER BY target_id ASC",
                $result['hub_primarios']
            )));
        } else {
            $result['hub_secundarios'] = array_map('absint', (array) $wpdb->get_col(
                "SELECT DISTINCT target_id
                 FROM {$relations_table}
                 WHERE relation_type = 'hub_primary_to_hub_secondary'
                   AND target_id > 0
                 ORDER BY target_id ASC"
            ));
        }

        $scope_secondary = [];
        if ($hub_secondary > 0) {
            $scope_secondary = [$hub_secondary];
        } elseif ($hub_primary > 0) {
            $scope_secondary = $result['hub_secundarios'];
        } elseif ($cluster > 0) {
            $scope_secondary = $result['hub_secundarios'];
        }

        if (!empty($scope_secondary)) {
            $placeholders = implode(',', array_fill(0, count($scope_secondary), '%d'));
            $result['category_ids'] = array_map('absint', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$relations_table}
                 WHERE source_id IN ({$placeholders})
                   AND relation_type = 'hub_secondary_to_category'
                   AND target_type = 'product_cat'
                 ORDER BY target_id ASC",
                $scope_secondary
            )));
        }

        return $result;
    }
}

if (!function_exists('seo_product_inventory_context_label')) {
    function seo_product_inventory_context_label($id, $fallback) {
        $id = absint($id);
        if ($id < 1) {
            return '';
        }
        $post = get_post($id);
        return $post instanceof WP_Post && $post->post_title !== ''
            ? (string) $post->post_title
            : $fallback . ' #' . $id;
    }
}

if (!function_exists('seo_product_inventory_get_filters')) {
    function seo_product_inventory_get_filters() {
        $get = wp_unslash($_GET);

        $array_ids = static function ($key) use ($get) {
            $values = isset($get[$key]) ? (array) $get[$key] : [];
            return array_values(array_unique(array_filter(array_map('absint', $values))));
        };

        $allowed_status = ['', 'publish', 'draft', 'pending', 'private'];
        $allowed_stock = ['', 'instock', 'outofstock', 'onbackorder'];
        $allowed_semantic = ['', 'classified', 'with_any', 'without_any', 'missing_tipo', 'missing_rol', 'missing_aplicacion', 'missing_plataforma', 'missing_subtipo'];
        $allowed_binary = ['', 'with', 'without'];
        $allowed_content = ['', 'complete', 'missing_excerpt', 'missing_description', 'missing_any', 'long_title', 'long_slug'];
        $allowed_sort = ['modified_desc', 'modified_asc', 'title_asc', 'title_desc', 'id_desc', 'id_asc'];
        $allowed_per_page = [25, 50, 100, 200];

        $status = isset($get['status']) ? sanitize_key((string) $get['status']) : '';
        $stock = isset($get['stock']) ? sanitize_key((string) $get['stock']) : '';
        $semantic_state = isset($get['semantic_state']) ? sanitize_key((string) $get['semantic_state']) : '';
        $category_state = isset($get['category_state']) ? sanitize_key((string) $get['category_state']) : '';
        $attribute_state = isset($get['attribute_state']) ? sanitize_key((string) $get['attribute_state']) : '';
        $supplier_state = isset($get['supplier_state']) ? sanitize_key((string) $get['supplier_state']) : '';
        $content_state = isset($get['content_state']) ? sanitize_key((string) $get['content_state']) : '';
        $sort = isset($get['sort']) ? sanitize_key((string) $get['sort']) : 'modified_desc';
        $per_page = isset($get['per_page']) ? absint($get['per_page']) : 50;

        return [
            'q'                => isset($get['q']) ? sanitize_text_field((string) $get['q']) : '',
            'status'           => in_array($status, $allowed_status, true) ? $status : '',
            'stock'            => in_array($stock, $allowed_stock, true) ? $stock : '',
            'provider'         => isset($get['provider']) ? sanitize_text_field((string) $get['provider']) : '',
            'brand'            => isset($get['brand']) ? sanitize_text_field((string) $get['brand']) : '',
            'semantic_state'   => in_array($semantic_state, $allowed_semantic, true) ? $semantic_state : '',
            'category_state'   => in_array($category_state, $allowed_binary, true) ? $category_state : '',
            'attribute_state'  => in_array($attribute_state, $allowed_binary, true) ? $attribute_state : '',
            'supplier_state'   => in_array($supplier_state, $allowed_binary, true) ? $supplier_state : '',
            'content_state'    => in_array($content_state, $allowed_content, true) ? $content_state : '',
            'attribute_ids'    => $array_ids('attribute_ids'),
            'attribute_value'  => isset($get['attribute_value']) ? sanitize_text_field((string) $get['attribute_value']) : '',
            'vocab_rol'        => $array_ids('vocab_rol'),
            'vocab_tipo'       => $array_ids('vocab_tipo'),
            'vocab_aplicacion' => $array_ids('vocab_aplicacion'),
            'vocab_plataforma' => $array_ids('vocab_plataforma'),
            'vocab_subtipo'    => $array_ids('vocab_subtipo'),
            'sort'              => in_array($sort, $allowed_sort, true) ? $sort : 'modified_desc',
            'per_page'          => in_array($per_page, $allowed_per_page, true) ? $per_page : 50,
            'paged'             => isset($get['paged']) ? max(1, absint($get['paged'])) : 1,
        ];
    }
}

if (!function_exists('seo_product_inventory_build_where')) {
    function seo_product_inventory_build_where(array $filters, $category_scope) {
        global $wpdb;

        $where = ["p.post_type = 'product'", "p.post_status IN ('publish','draft','pending','private')"];
        $args = [];

        $postmeta = $wpdb->postmeta;
        $term_relationships = $wpdb->term_relationships;
        $term_taxonomy = $wpdb->term_taxonomy;
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $object_vocabulary = $wpdb->prefix . 'seo_object_vocabulary';
        $type_role_map = $wpdb->prefix . 'seo_type_role_map';
        $attribute_values = $wpdb->prefix . 'sql_product_atributos';
        $attribute_terms = $wpdb->prefix . 'sql_atributos_terminos';

        $has_vocab = seo_product_inventory_table_exists($vocabulary) && seo_product_inventory_table_exists($object_vocabulary);
        $has_type_role = $has_vocab && seo_product_inventory_table_exists($type_role_map);
        $has_attrs = seo_product_inventory_table_exists($attribute_values);
        $has_attr_terms = $has_attrs && seo_product_inventory_table_exists($attribute_terms);

        if ($filters['status'] !== '') {
            $where[] = 'p.post_status = %s';
            $args[] = $filters['status'];
        }

        if ($filters['q'] !== '') {
            $like = '%' . $wpdb->esc_like($filters['q']) . '%';
            $search = [
                'p.post_title LIKE %s',
                'p.post_name LIKE %s',
                "EXISTS (
                    SELECT 1 FROM {$postmeta} pm_search
                    WHERE pm_search.post_id = p.ID
                      AND pm_search.meta_key IN ('_sku','_seo_proveedor_id_externo','_seo_proveedor_mpn')
                      AND pm_search.meta_value LIKE %s
                )",
            ];
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
            if (ctype_digit($filters['q'])) {
                $search[] = 'p.ID = %d';
                $args[] = absint($filters['q']);
            }
            $where[] = '(' . implode(' OR ', $search) . ')';
        }

        if ($filters['provider'] !== '') {
            $where[] = "EXISTS (
                SELECT 1 FROM {$postmeta} pm_provider
                WHERE pm_provider.post_id = p.ID
                  AND pm_provider.meta_key = '_seo_proveedor'
                  AND pm_provider.meta_value = %s
            )";
            $args[] = $filters['provider'];
        }

        if ($filters['brand'] !== '') {
            $where[] = "EXISTS (
                SELECT 1 FROM {$postmeta} pm_brand
                WHERE pm_brand.post_id = p.ID
                  AND pm_brand.meta_key = '_seo_marca_proveedor'
                  AND pm_brand.meta_value = %s
            )";
            $args[] = $filters['brand'];
        }

        if ($filters['stock'] !== '') {
            $where[] = "EXISTS (
                SELECT 1 FROM {$postmeta} pm_stock
                WHERE pm_stock.post_id = p.ID
                  AND pm_stock.meta_key = '_stock_status'
                  AND pm_stock.meta_value = %s
            )";
            $args[] = $filters['stock'];
        }

        if (is_array($category_scope)) {
            if (empty($category_scope)) {
                $where[] = '1 = 0';
            } else {
                $placeholders = implode(',', array_fill(0, count($category_scope), '%d'));
                $where[] = "EXISTS (
                    SELECT 1
                    FROM {$term_relationships} tr_scope
                    INNER JOIN {$term_taxonomy} tt_scope
                      ON tt_scope.term_taxonomy_id = tr_scope.term_taxonomy_id
                    WHERE tr_scope.object_id = p.ID
                      AND tt_scope.taxonomy = 'product_cat'
                      AND tt_scope.term_id IN ({$placeholders})
                )";
                $args = array_merge($args, array_map('absint', $category_scope));
            }
        }

        if ($filters['category_state'] === 'with' || $filters['category_state'] === 'without') {
            $exists = "EXISTS (
                SELECT 1
                FROM {$term_relationships} tr_cat
                INNER JOIN {$term_taxonomy} tt_cat
                  ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id
                WHERE tr_cat.object_id = p.ID
                  AND tt_cat.taxonomy = 'product_cat'
            )";
            $where[] = $filters['category_state'] === 'with' ? $exists : 'NOT ' . $exists;
        }

        $group_filters = [
            'tipo'       => $filters['vocab_tipo'],
            'aplicacion' => $filters['vocab_aplicacion'],
            'plataforma' => $filters['vocab_plataforma'],
            'subtipo'    => $filters['vocab_subtipo'],
        ];

        if ($has_vocab) {
            foreach ($group_filters as $group => $ids) {
                if (empty($ids)) {
                    continue;
                }
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $where[] = "EXISTS (
                    SELECT 1
                    FROM {$object_vocabulary} ov_{$group}
                    INNER JOIN {$vocabulary} v_{$group}
                      ON v_{$group}.id = ov_{$group}.vocabulary_id
                     AND v_{$group}.active = 1
                    WHERE ov_{$group}.object_type = 'product'
                      AND ov_{$group}.object_id = p.ID
                      AND ov_{$group}.status = 1
                      AND v_{$group}.semantic_group = '{$group}'
                      AND ov_{$group}.vocabulary_id IN ({$placeholders})
                )";
                $args = array_merge($args, $ids);
            }

            if (!empty($filters['vocab_rol'])) {
                $placeholders = implode(',', array_fill(0, count($filters['vocab_rol']), '%d'));
                $role_direct = "EXISTS (
                    SELECT 1
                    FROM {$object_vocabulary} ov_role
                    INNER JOIN {$vocabulary} v_role
                      ON v_role.id = ov_role.vocabulary_id
                     AND v_role.active = 1
                    WHERE ov_role.object_type = 'product'
                      AND ov_role.object_id = p.ID
                      AND ov_role.status = 1
                      AND v_role.semantic_group = 'rol'
                      AND ov_role.vocabulary_id IN ({$placeholders})
                )";
                $args = array_merge($args, $filters['vocab_rol']);

                if ($has_type_role) {
                    $derived_placeholders = implode(',', array_fill(0, count($filters['vocab_rol']), '%d'));
                    $role_derived = "EXISTS (
                        SELECT 1
                        FROM {$object_vocabulary} ov_type_role
                        INNER JOIN {$type_role_map} trm_role
                          ON trm_role.type_vocabulary_id = ov_type_role.vocabulary_id
                         AND trm_role.active = 1
                        WHERE ov_type_role.object_type = 'product'
                          AND ov_type_role.object_id = p.ID
                          AND ov_type_role.status = 1
                          AND trm_role.role_vocabulary_id IN ({$derived_placeholders})
                    )";
                    $args = array_merge($args, $filters['vocab_rol']);
                    $where[] = '(' . $role_direct . ' OR ' . $role_derived . ')';
                } else {
                    $where[] = $role_direct;
                }
            }

            $semantic_exists = "EXISTS (
                SELECT 1
                FROM {$object_vocabulary} ov_sem
                INNER JOIN {$vocabulary} v_sem
                  ON v_sem.id = ov_sem.vocabulary_id
                 AND v_sem.active = 1
                WHERE ov_sem.object_type = 'product'
                  AND ov_sem.object_id = p.ID
                  AND ov_sem.status = 1
                  AND v_sem.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
            )";

            if ($filters['semantic_state'] === 'with_any') {
                $where[] = $semantic_exists;
            } elseif ($filters['semantic_state'] === 'without_any') {
                $where[] = 'NOT ' . $semantic_exists;
            } elseif ($filters['semantic_state'] === 'classified') {
                $where[] = "EXISTS (
                    SELECT 1 FROM {$object_vocabulary} ov_class
                    INNER JOIN {$vocabulary} v_class ON v_class.id = ov_class.vocabulary_id AND v_class.active = 1
                    WHERE ov_class.object_type = 'product' AND ov_class.object_id = p.ID AND ov_class.status = 1
                      AND v_class.semantic_group = 'tipo'
                )";
            } elseif (strpos($filters['semantic_state'], 'missing_') === 0) {
                $missing_group = substr($filters['semantic_state'], 8);
                if (in_array($missing_group, ['tipo','rol','aplicacion','plataforma','subtipo'], true)) {
                    if ($missing_group === 'rol' && $has_type_role) {
                        $where[] = "NOT EXISTS (
                            SELECT 1
                            FROM {$object_vocabulary} ov_missing_role
                            INNER JOIN {$type_role_map} trm_missing_role
                              ON trm_missing_role.type_vocabulary_id = ov_missing_role.vocabulary_id
                             AND trm_missing_role.active = 1
                            WHERE ov_missing_role.object_type = 'product'
                              AND ov_missing_role.object_id = p.ID
                              AND ov_missing_role.status = 1
                        )";
                    } else {
                        $where[] = "NOT EXISTS (
                            SELECT 1
                            FROM {$object_vocabulary} ov_missing
                            INNER JOIN {$vocabulary} v_missing
                              ON v_missing.id = ov_missing.vocabulary_id
                             AND v_missing.active = 1
                            WHERE ov_missing.object_type = 'product'
                              AND ov_missing.object_id = p.ID
                              AND ov_missing.status = 1
                              AND v_missing.semantic_group = '{$missing_group}'
                        )";
                    }
                }
            }
        } elseif ($filters['semantic_state'] !== '' || !empty($filters['vocab_rol']) || !empty($filters['vocab_tipo']) || !empty($filters['vocab_aplicacion']) || !empty($filters['vocab_plataforma']) || !empty($filters['vocab_subtipo'])) {
            $where[] = '1 = 0';
        }

        if ($filters['attribute_state'] === 'with' || $filters['attribute_state'] === 'without') {
            if ($has_attrs) {
                $exists = "EXISTS (SELECT 1 FROM {$attribute_values} pa_state WHERE pa_state.product_id = p.ID)";
                $where[] = $filters['attribute_state'] === 'with' ? $exists : 'NOT ' . $exists;
            } elseif ($filters['attribute_state'] === 'with') {
                $where[] = '1 = 0';
            }
        }

        if (!empty($filters['attribute_ids'])) {
            if ($has_attrs) {
                $placeholders = implode(',', array_fill(0, count($filters['attribute_ids']), '%d'));
                $where[] = "EXISTS (
                    SELECT 1 FROM {$attribute_values} pa_filter
                    WHERE pa_filter.product_id = p.ID
                      AND pa_filter.atributo_id IN ({$placeholders})
                )";
                $args = array_merge($args, $filters['attribute_ids']);
            } else {
                $where[] = '1 = 0';
            }
        }

        if ($filters['attribute_value'] !== '') {
            if ($has_attrs) {
                $like = '%' . $wpdb->esc_like($filters['attribute_value']) . '%';
                $join_term = $has_attr_terms
                    ? "LEFT JOIN {$attribute_terms} pat_filter ON pat_filter.id = pa_value.termino_id"
                    : '';
                $term_condition = $has_attr_terms
                    ? " OR pat_filter.nombre LIKE %s OR pat_filter.slug LIKE %s"
                    : '';
                $where[] = "EXISTS (
                    SELECT 1
                    FROM {$attribute_values} pa_value
                    {$join_term}
                    WHERE pa_value.product_id = p.ID
                      AND (
                        pa_value.valor_texto LIKE %s
                        OR pa_value.valor_original LIKE %s
                        OR CAST(pa_value.valor_numero AS CHAR) LIKE %s
                        OR CAST(pa_value.valor_numero_max AS CHAR) LIKE %s
                        {$term_condition}
                      )
                )";
                $args[] = $like;
                $args[] = $like;
                $args[] = $like;
                $args[] = $like;
                if ($has_attr_terms) {
                    $args[] = $like;
                    $args[] = $like;
                }
            } else {
                $where[] = '1 = 0';
            }
        }

        if ($filters['supplier_state'] === 'with' || $filters['supplier_state'] === 'without') {
            $exists = "EXISTS (
                SELECT 1 FROM {$postmeta} pm_supplier
                WHERE pm_supplier.post_id = p.ID
                  AND (
                    (pm_supplier.meta_key = '_seo_proveedor' AND pm_supplier.meta_value <> '')
                    OR (pm_supplier.meta_key = '_seo_proveedor_catalogo_id' AND CAST(pm_supplier.meta_value AS UNSIGNED) > 0)
                  )
            )";
            $where[] = $filters['supplier_state'] === 'with' ? $exists : 'NOT ' . $exists;
        }

        if ($filters['content_state'] === 'complete') {
            $where[] = "TRIM(COALESCE(p.post_excerpt,'')) <> ''";
            $where[] = "TRIM(COALESCE(p.post_content,'')) <> ''";
        } elseif ($filters['content_state'] === 'missing_excerpt') {
            $where[] = "TRIM(COALESCE(p.post_excerpt,'')) = ''";
        } elseif ($filters['content_state'] === 'missing_description') {
            $where[] = "TRIM(COALESCE(p.post_content,'')) = ''";
        } elseif ($filters['content_state'] === 'missing_any') {
            $where[] = "(TRIM(COALESCE(p.post_excerpt,'')) = '' OR TRIM(COALESCE(p.post_content,'')) = '')";
        } elseif ($filters['content_state'] === 'long_title') {
            $where[] = 'CHAR_LENGTH(p.post_title) > 120';
        } elseif ($filters['content_state'] === 'long_slug') {
            $where[] = 'CHAR_LENGTH(p.post_name) > 120';
        }

        return [implode(' AND ', $where), $args];
    }
}

if (!function_exists('seo_product_inventory_get_kpis')) {
    function seo_product_inventory_get_kpis() {
        global $wpdb;

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $object_vocabulary = $wpdb->prefix . 'seo_object_vocabulary';
        $attribute_values = $wpdb->prefix . 'sql_product_atributos';
        $has_vocab = seo_product_inventory_table_exists($vocabulary) && seo_product_inventory_table_exists($object_vocabulary);
        $has_attrs = seo_product_inventory_table_exists($attribute_values);

        $tipo_sql = $has_vocab
            ? "EXISTS (
                SELECT 1 FROM {$object_vocabulary} ov_kpi
                INNER JOIN {$vocabulary} v_kpi ON v_kpi.id = ov_kpi.vocabulary_id AND v_kpi.active = 1
                WHERE ov_kpi.object_type = 'product' AND ov_kpi.object_id = p.ID AND ov_kpi.status = 1
                  AND v_kpi.semantic_group = 'tipo'
              )"
            : '0';

        $attrs_sql = $has_attrs
            ? "EXISTS (SELECT 1 FROM {$attribute_values} pa_kpi WHERE pa_kpi.product_id = p.ID)"
            : '0';

        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(p.post_status = 'publish') AS published,
                    SUM(EXISTS (
                        SELECT 1
                        FROM {$wpdb->term_relationships} tr_kpi
                        INNER JOIN {$wpdb->term_taxonomy} tt_kpi
                          ON tt_kpi.term_taxonomy_id = tr_kpi.term_taxonomy_id
                        WHERE tr_kpi.object_id = p.ID
                          AND tt_kpi.taxonomy = 'product_cat'
                    )) AS with_category,
                    SUM({$tipo_sql}) AS with_type,
                    SUM({$attrs_sql}) AS with_attributes,
                    SUM(EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} pm_supplier_kpi
                        WHERE pm_supplier_kpi.post_id = p.ID
                          AND (
                            (pm_supplier_kpi.meta_key = '_seo_proveedor' AND pm_supplier_kpi.meta_value <> '')
                            OR (pm_supplier_kpi.meta_key = '_seo_proveedor_catalogo_id' AND CAST(pm_supplier_kpi.meta_value AS UNSIGNED) > 0)
                          )
                    )) AS with_supplier,
                    SUM(TRIM(COALESCE(p.post_excerpt,'')) <> '' AND TRIM(COALESCE(p.post_content,'')) <> '') AS content_complete,
                    SUM(EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} pm_stock_kpi
                        WHERE pm_stock_kpi.post_id = p.ID
                          AND pm_stock_kpi.meta_key = '_stock_status'
                          AND pm_stock_kpi.meta_value = 'instock'
                    )) AS in_stock
                FROM {$wpdb->posts} p
                WHERE p.post_type = 'product'
                  AND p.post_status IN ('publish','draft','pending','private')";

        $row = $wpdb->get_row($sql, ARRAY_A);
        $keys = ['total','published','with_category','with_type','with_attributes','with_supplier','content_complete','in_stock'];
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = absint($row[$key] ?? 0);
        }
        return $result;
    }
}

if (!function_exists('seo_product_inventory_get_rows_data')) {
    function seo_product_inventory_get_rows_data(array $ids) {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        $result = [
            'categories' => [],
            'semantic'   => [],
            'attributes' => [],
        ];
        if (empty($ids)) {
            return $result;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $category_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.object_id, t.term_id, t.name
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE tr.object_id IN ({$placeholders})
               AND tt.taxonomy = 'product_cat'
             ORDER BY t.name ASC",
            $ids
        ));

        foreach ((array) $category_rows as $row) {
            $product_id = absint($row->object_id ?? 0);
            if ($product_id > 0) {
                $result['categories'][$product_id][] = [
                    'id'   => absint($row->term_id ?? 0),
                    'name' => (string) ($row->name ?? ''),
                ];
            }
        }

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $object_vocabulary = $wpdb->prefix . 'seo_object_vocabulary';
        $type_role_map = $wpdb->prefix . 'seo_type_role_map';

        if (seo_product_inventory_table_exists($vocabulary) && seo_product_inventory_table_exists($object_vocabulary)) {
            $semantic_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ov.object_id, v.id, v.semantic_group, v.slug, v.label
                 FROM {$object_vocabulary} ov
                 INNER JOIN {$vocabulary} v ON v.id = ov.vocabulary_id AND v.active = 1
                 WHERE ov.object_type = 'product'
                   AND ov.object_id IN ({$placeholders})
                   AND ov.status = 1
                   AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                 ORDER BY ov.object_id ASC,
                          FIELD(v.semantic_group,'rol','tipo','aplicacion','plataforma','subtipo'),
                          v.label ASC",
                $ids
            ));

            foreach ((array) $semantic_rows as $row) {
                $product_id = absint($row->object_id ?? 0);
                $group = sanitize_key((string) ($row->semantic_group ?? ''));
                if ($product_id < 1 || !in_array($group, ['rol','tipo','aplicacion','plataforma','subtipo'], true)) {
                    continue;
                }
                if (!isset($result['semantic'][$product_id])) {
                    $result['semantic'][$product_id] = [
                        'rol' => [], 'tipo' => [], 'aplicacion' => [], 'plataforma' => [], 'subtipo' => [],
                    ];
                }
                $result['semantic'][$product_id][$group][] = [
                    'id'    => absint($row->id ?? 0),
                    'slug'  => (string) ($row->slug ?? ''),
                    'label' => (string) ($row->label ?? ''),
                ];
            }

            if (seo_product_inventory_table_exists($type_role_map)) {
                $role_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT DISTINCT ov.object_id, rv.id, rv.slug, rv.label
                     FROM {$object_vocabulary} ov
                     INNER JOIN {$type_role_map} trm
                       ON trm.type_vocabulary_id = ov.vocabulary_id
                      AND trm.active = 1
                     INNER JOIN {$vocabulary} rv
                       ON rv.id = trm.role_vocabulary_id
                      AND rv.semantic_group = 'rol'
                      AND rv.active = 1
                     WHERE ov.object_type = 'product'
                       AND ov.object_id IN ({$placeholders})
                       AND ov.status = 1
                     ORDER BY ov.object_id ASC, rv.label ASC",
                    $ids
                ));

                foreach ((array) $role_rows as $row) {
                    $product_id = absint($row->object_id ?? 0);
                    if ($product_id < 1) {
                        continue;
                    }
                    if (!isset($result['semantic'][$product_id])) {
                        $result['semantic'][$product_id] = [
                            'rol' => [], 'tipo' => [], 'aplicacion' => [], 'plataforma' => [], 'subtipo' => [],
                        ];
                    }
                    if (!isset($result['semantic'][$product_id]['_derived_roles'])) {
                        $result['semantic'][$product_id]['_derived_roles'] = [];
                    }
                    $result['semantic'][$product_id]['_derived_roles'][] = [
                        'id'    => absint($row->id ?? 0),
                        'slug'  => (string) ($row->slug ?? ''),
                        'label' => (string) ($row->label ?? ''),
                    ];
                }
            }
        }

        if (function_exists('seo_attributes_get_rows_for_products')) {
            $attribute_rows = seo_attributes_get_rows_for_products($ids);
            foreach ((array) $attribute_rows as $row) {
                $product_id = absint($row->product_id ?? 0);
                if ($product_id > 0) {
                    $result['attributes'][$product_id][] = $row;
                }
            }
        }

        return $result;
    }
}

if (!function_exists('seo_product_inventory_filter_query_args')) {
    function seo_product_inventory_filter_query_args(array $filters, $cluster, $hub_primary, $hub_secondary, $cat) {
        $args = [
            'page'          => 'product-page-admin',
            'tab'           => 'inventario',
            'cluster'       => absint($cluster),
            'hub_primario'  => absint($hub_primary),
            'hub_secundario'=> absint($hub_secondary),
            'cat'           => absint($cat),
        ];

        foreach (['q','status','stock','provider','brand','semantic_state','category_state','attribute_state','supplier_state','content_state','attribute_value','sort'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $args[$key] = $filters[$key];
            }
        }

        if ((int) $filters['per_page'] !== 50) {
            $args['per_page'] = absint($filters['per_page']);
        }

        foreach (['attribute_ids','vocab_rol','vocab_tipo','vocab_aplicacion','vocab_plataforma','vocab_subtipo'] as $key) {
            if (!empty($filters[$key])) {
                $args[$key] = array_map('absint', $filters[$key]);
            }
        }

        return array_filter($args, static function ($value) {
            return !($value === 0 || $value === '' || $value === []);
        });
    }
}

if (!function_exists('seo_product_inventory_render_multiselect')) {
    function seo_product_inventory_render_multiselect($name, $label, array $rows, array $selected, $placeholder = '') {
        echo '<div class="seo-pi-field">';
        echo '<label>' . esc_html($label) . '</label>';
        echo '<select class="seo-pi-selectwoo" name="' . esc_attr($name) . '[]" multiple data-placeholder="' . esc_attr($placeholder ?: $label) . '">';
        foreach ($rows as $row) {
            $id = absint($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $text = trim((string) ($row['label'] ?? $row['nombre'] ?? $row['slug'] ?? ''));
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($slug !== '' && remove_accents(mb_strtolower($text, 'UTF-8')) !== str_replace('_', ' ', $slug)) {
                $text .= ' · ' . $slug;
            }
            echo '<option value="' . esc_attr($id) . '" ' . selected(in_array($id, $selected, true), true, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }
}

if (!function_exists('seo_product_inventory_kpi_card')) {
    function seo_product_inventory_kpi_card($label, $value, $total, $note = '') {
        $percent = $total > 0 ? round(($value / $total) * 100, 1) : 0;
        echo '<div class="seo-pi-kpi">';
        echo '<div class="seo-pi-kpi-label">' . esc_html($label) . '</div>';
        echo '<div class="seo-pi-kpi-value">' . esc_html(number_format_i18n($value)) . '</div>';
        if ($note !== '') {
            echo '<div class="seo-pi-kpi-note">' . esc_html($note) . '</div>';
        } elseif ($total > 0 && $value !== $total) {
            echo '<div class="seo-pi-kpi-note">' . esc_html(number_format_i18n($percent, 1) . '% del catalogo') . '</div>';
        } else {
            echo '<div class="seo-pi-kpi-note">&nbsp;</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('seo_product_inventory_page')) {
    function seo_product_inventory_page($requested_category_id = 0) {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;

        if (function_exists('wp_script_is') && wp_script_is('selectWoo', 'registered')) {
            wp_enqueue_script('selectWoo');
        }
        if (function_exists('wp_style_is') && wp_style_is('select2', 'registered')) {
            wp_enqueue_style('select2');
        }

        $cluster = isset($_GET['cluster']) ? absint($_GET['cluster']) : 0;
        $hub_primary = isset($_GET['hub_primario']) ? absint($_GET['hub_primario']) : 0;
        $hub_secondary = isset($_GET['hub_secundario']) ? absint($_GET['hub_secundario']) : 0;
        $cat = isset($_GET['cat']) ? absint($_GET['cat']) : 0;

        $requested_category_id = absint($requested_category_id);
        if ($requested_category_id > 0) {
            $context = seo_get_category_product_inventory_context($requested_category_id);
            if (!is_wp_error($context)) {
                $cluster = absint($context['cluster'] ?? 0);
                $hub_primary = absint($context['hub_primario'] ?? 0);
                $hub_secondary = absint($context['hub_secundario'] ?? 0);
                $cat = absint($context['cat'] ?? 0);
            } else {
                $cat = $requested_category_id;
            }
        }

        $filters = seo_product_inventory_get_filters();
        $hierarchy = seo_product_inventory_get_hierarchy_options($cluster, $hub_primary, $hub_secondary);

        $category_scope = null;
        if ($cat > 0) {
            $category_scope = [$cat];
        } elseif ($hub_secondary > 0 || $hub_primary > 0 || $cluster > 0) {
            $category_scope = is_array($hierarchy['category_ids']) ? $hierarchy['category_ids'] : [];
        }

        list($where_sql, $where_args) = seo_product_inventory_build_where($filters, $category_scope);

        $total_filtered = absint($wpdb->get_var(seo_product_inventory_prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where_sql}",
            $where_args
        )));

        $order_map = [
            'modified_desc' => 'p.post_modified DESC, p.ID DESC',
            'modified_asc'  => 'p.post_modified ASC, p.ID ASC',
            'title_asc'     => 'p.post_title ASC, p.ID ASC',
            'title_desc'    => 'p.post_title DESC, p.ID DESC',
            'id_desc'       => 'p.ID DESC',
            'id_asc'        => 'p.ID ASC',
        ];
        $order_by = $order_map[$filters['sort']] ?? $order_map['modified_desc'];

        $max_pages = max(1, (int) ceil($total_filtered / max(1, $filters['per_page'])));
        if ($filters['paged'] > $max_pages) {
            $filters['paged'] = $max_pages;
        }
        $offset = ($filters['paged'] - 1) * $filters['per_page'];

        $row_args = array_merge($where_args, [$filters['per_page'], $offset]);
        $posts = $wpdb->get_results(seo_product_inventory_prepare(
            "SELECT p.ID, p.post_title, p.post_name, p.post_excerpt, p.post_content, p.post_status, p.post_modified
             FROM {$wpdb->posts} p
             WHERE {$where_sql}
             ORDER BY {$order_by}
             LIMIT %d OFFSET %d",
            $row_args
        ));

        $ids = array_values(array_filter(array_map(static function ($row) {
            return absint($row->ID ?? 0);
        }, (array) $posts)));

        if (!empty($ids)) {
            update_meta_cache('post', $ids);
        }
        $row_data = seo_product_inventory_get_rows_data($ids);

        $kpis = seo_product_inventory_get_kpis();
        $vocabulary_terms = seo_product_inventory_get_vocabulary_terms();
        $attribute_catalog = seo_product_inventory_get_attribute_catalog();
        $providers = function_exists('seo_product_get_provider_suggestions')
            ? seo_product_get_provider_suggestions()
            : [];
        $brands = seo_product_inventory_get_brands();

        $all_categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);
        if (is_wp_error($all_categories)) {
            $all_categories = [];
        }
        $allowed_category_ids = is_array($hierarchy['category_ids']) ? array_flip($hierarchy['category_ids']) : null;

        $clear_url = add_query_arg(
            ['page' => 'product-page-admin', 'tab' => 'inventario'],
            admin_url('admin.php')
        );

        ?>
        <style>
            .seo-pi-wrap{max-width:1600px}
            .seo-pi-intro{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin:0 0 16px}
            .seo-pi-intro h2{margin:0 0 5px;font-size:22px}
            .seo-pi-intro p{margin:0;color:#646970;max-width:900px}
            .seo-pi-kpis{display:grid;grid-template-columns:repeat(9,minmax(125px,1fr));gap:10px;margin:0 0 18px}
            .seo-pi-kpi{background:#fff;border:1px solid #dcdcde;border-radius:9px;padding:13px 14px;min-width:0}
            .seo-pi-kpi-label{font-size:12px;font-weight:700;color:#50575e;text-transform:uppercase;letter-spacing:.02em}
            .seo-pi-kpi-value{font-size:25px;line-height:1.1;font-weight:700;margin-top:5px;color:#1d2327}
            .seo-pi-kpi-note{font-size:11px;color:#787c82;margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .seo-pi-filter-card{background:#fff;border:1px solid #dcdcde;border-radius:9px;margin:0 0 16px;overflow:hidden}
            .seo-pi-filter-head{padding:14px 16px;border-bottom:1px solid #f0f0f1;display:flex;align-items:center;justify-content:space-between;gap:12px}
            .seo-pi-filter-head strong{font-size:15px}
            .seo-pi-filter-body{padding:15px 16px}
            .seo-pi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px 14px}
            .seo-pi-grid-5{grid-template-columns:repeat(5,minmax(0,1fr))}
            .seo-pi-field label{display:block;font-weight:600;margin-bottom:5px}
            .seo-pi-field input[type=text],.seo-pi-field select{width:100%;max-width:none}
            .seo-pi-selectwoo{width:100%}
            .seo-pi-details{border-top:1px solid #f0f0f1;margin-top:14px;padding-top:12px}
            .seo-pi-details summary{cursor:pointer;font-weight:600;color:#2271b1}
            .seo-pi-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:15px}
            .seo-pi-actions-left,.seo-pi-actions-right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
            .seo-pi-result-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:18px 0 9px}
            .seo-pi-result-title h3{margin:0;font-size:16px}
            .seo-pi-table{table-layout:auto}
            .seo-pi-table th{white-space:nowrap}
            .seo-pi-product-title{font-weight:700;font-size:14px;line-height:1.35}
            .seo-pi-muted{font-size:11px;color:#787c82;margin-top:3px;word-break:break-word}
            .seo-pi-chip{display:inline-block;padding:2px 6px;border:1px solid #c3c4c7;border-radius:999px;background:#f6f7f7;font-size:11px;line-height:1.4;margin:1px 2px 1px 0}
            .seo-pi-chip strong{font-size:10px;color:#50575e}
            .seo-pi-badge{display:inline-block;border-radius:999px;padding:3px 7px;font-size:11px;font-weight:600;background:#f0f0f1;margin:1px 3px 1px 0}
            .seo-pi-badge.ok{background:#edfaef;color:#008a20}
            .seo-pi-badge.warn{background:#fff8e5;color:#996800}
            .seo-pi-badge.bad{background:#fcf0f1;color:#b32d2e}
            .seo-pi-sem-group{margin-bottom:4px}
            .seo-pi-sem-group:last-child{margin-bottom:0}
            .seo-pi-sem-label{display:inline-block;min-width:72px;font-size:10px;font-weight:700;color:#646970}
            .seo-pi-row-details{margin-top:7px}
            .seo-pi-row-details summary{cursor:pointer;color:#2271b1;font-size:12px}
            .seo-pi-row-details-body{padding:8px 10px;background:#f6f7f7;border-radius:6px;margin-top:5px;font-size:12px;line-height:1.45;max-width:650px}
            .seo-pi-pagination .page-numbers{margin:0 2px}
            @media(max-width:1400px){.seo-pi-kpis{grid-template-columns:repeat(5,1fr)}.seo-pi-grid,.seo-pi-grid-5{grid-template-columns:repeat(3,1fr)}}
            @media(max-width:900px){.seo-pi-kpis{grid-template-columns:repeat(2,1fr)}.seo-pi-grid,.seo-pi-grid-5{grid-template-columns:1fr}.seo-pi-intro{display:block}.seo-pi-table{display:block;overflow:auto}}
        </style>

        <div class="seo-pi-wrap">
            <div class="seo-pi-intro">
                <div>
                    <h2>Inventario avanzado de productos</h2>
                    <p>Filtra directamente el catalogo por jerarquia SEO, categorias, Vocabulary canonico, atributos, proveedor, stock y calidad editorial. Ya no es obligatorio seleccionar un cluster para consultar productos.</p>
                </div>
            </div>

            <div class="seo-pi-kpis">
                <?php
                seo_product_inventory_kpi_card('Productos', $kpis['total'], $kpis['total'], 'Catalogo gestionado');
                seo_product_inventory_kpi_card('Publicados', $kpis['published'], $kpis['total']);
                seo_product_inventory_kpi_card('Resultado', $total_filtered, $kpis['total'], 'Con los filtros actuales');
                seo_product_inventory_kpi_card('Con categoria', $kpis['with_category'], $kpis['total']);
                seo_product_inventory_kpi_card('Clasificados', $kpis['with_type'], $kpis['total'], 'TIPO canonico asignado');
                seo_product_inventory_kpi_card('Con atributos', $kpis['with_attributes'], $kpis['total']);
                seo_product_inventory_kpi_card('Con proveedor', $kpis['with_supplier'], $kpis['total']);
                seo_product_inventory_kpi_card('Contenido completo', $kpis['content_complete'], $kpis['total'], 'Excerpt + description');
                seo_product_inventory_kpi_card('En stock', $kpis['in_stock'], $kpis['total']);
                ?>
            </div>

            <form method="get" class="seo-pi-filter-card">
                <input type="hidden" name="page" value="product-page-admin">
                <input type="hidden" name="tab" value="inventario">

                <div class="seo-pi-filter-head">
                    <strong>Filtros del catalogo</strong>
                    <a class="button button-small" href="<?php echo esc_url($clear_url); ?>">Limpiar todos</a>
                </div>

                <div class="seo-pi-filter-body">
                    <div class="seo-pi-grid seo-pi-grid-5">
                        <div class="seo-pi-field" style="grid-column:span 2;">
                            <label for="seo-pi-q">Buscar</label>
                            <input id="seo-pi-q" type="text" name="q" value="<?php echo esc_attr($filters['q']); ?>" placeholder="Titulo, slug, SKU, ID, MPN o ID externo">
                        </div>

                        <div class="seo-pi-field">
                            <label>Estado</label>
                            <select name="status">
                                <option value="">Todos</option>
                                <option value="publish" <?php selected($filters['status'], 'publish'); ?>>Publicado</option>
                                <option value="draft" <?php selected($filters['status'], 'draft'); ?>>Borrador</option>
                                <option value="pending" <?php selected($filters['status'], 'pending'); ?>>Pendiente</option>
                                <option value="private" <?php selected($filters['status'], 'private'); ?>>Privado</option>
                            </select>
                        </div>

                        <div class="seo-pi-field">
                            <label>Stock</label>
                            <select name="stock">
                                <option value="">Todos</option>
                                <option value="instock" <?php selected($filters['stock'], 'instock'); ?>>En stock</option>
                                <option value="outofstock" <?php selected($filters['stock'], 'outofstock'); ?>>Agotado</option>
                                <option value="onbackorder" <?php selected($filters['stock'], 'onbackorder'); ?>>Bajo pedido</option>
                            </select>
                        </div>

                        <div class="seo-pi-field">
                            <label>Proveedor</label>
                            <select class="seo-pi-selectwoo" name="provider" data-placeholder="Todos los proveedores">
                                <option value=""></option>
                                <?php foreach ((array) $providers as $provider_option): ?>
                                    <option value="<?php echo esc_attr($provider_option); ?>" <?php selected($filters['provider'], $provider_option); ?>><?php echo esc_html($provider_option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="seo-pi-field">
                            <label>Marca</label>
                            <select class="seo-pi-selectwoo" name="brand" data-placeholder="Todas las marcas">
                                <option value=""></option>
                                <?php foreach ((array) $brands as $brand_option): ?>
                                    <option value="<?php echo esc_attr($brand_option); ?>" <?php selected($filters['brand'], $brand_option); ?>><?php echo esc_html($brand_option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="seo-pi-field">
                            <label>Cluster</label>
                            <select class="seo-pi-selectwoo" name="cluster" data-placeholder="Todos">
                                <option value=""></option>
                                <?php foreach ((array) $hierarchy['clusters'] as $id): ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($cluster, $id); ?>><?php echo esc_html(seo_product_inventory_context_label($id, 'Cluster')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="seo-pi-field">
                            <label>Hub primario</label>
                            <select class="seo-pi-selectwoo" name="hub_primario" data-placeholder="Todos">
                                <option value=""></option>
                                <?php foreach ((array) $hierarchy['hub_primarios'] as $id): ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($hub_primary, $id); ?>><?php echo esc_html(seo_product_inventory_context_label($id, 'Hub primario')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="seo-pi-field">
                            <label>Hub secundario</label>
                            <select class="seo-pi-selectwoo" name="hub_secundario" data-placeholder="Todos">
                                <option value=""></option>
                                <?php foreach ((array) $hierarchy['hub_secundarios'] as $id): ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($hub_secondary, $id); ?>><?php echo esc_html(seo_product_inventory_context_label($id, 'Hub secundario')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="seo-pi-field">
                            <label>Categoria</label>
                            <select class="seo-pi-selectwoo" name="cat" data-placeholder="Todas las categorias">
                                <option value=""></option>
                                <?php foreach ((array) $all_categories as $term): ?>
                                    <?php if (is_array($allowed_category_ids) && !isset($allowed_category_ids[(int) $term->term_id]) && (int) $term->term_id !== $cat) continue; ?>
                                    <option value="<?php echo esc_attr($term->term_id); ?>" <?php selected($cat, $term->term_id); ?>><?php echo esc_html($term->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <details class="seo-pi-details" open>
                        <summary>Vocabulary canonico</summary>
                        <div class="seo-pi-grid seo-pi-grid-5" style="margin-top:12px;">
                            <?php
                            seo_product_inventory_render_multiselect('vocab_rol', 'ROL', $vocabulary_terms['rol'], $filters['vocab_rol'], 'Cualquier ROL');
                            seo_product_inventory_render_multiselect('vocab_tipo', 'TIPO', $vocabulary_terms['tipo'], $filters['vocab_tipo'], 'Cualquier TIPO');
                            seo_product_inventory_render_multiselect('vocab_aplicacion', 'APLICACION', $vocabulary_terms['aplicacion'], $filters['vocab_aplicacion'], 'Cualquier aplicacion');
                            seo_product_inventory_render_multiselect('vocab_plataforma', 'PLATAFORMA', $vocabulary_terms['plataforma'], $filters['vocab_plataforma'], 'Cualquier plataforma');
                            seo_product_inventory_render_multiselect('vocab_subtipo', 'SUBTIPO', $vocabulary_terms['subtipo'], $filters['vocab_subtipo'], 'Cualquier subtipo');
                            ?>
                        </div>
                    </details>

                    <details class="seo-pi-details">
                        <summary>Calidad, atributos y cobertura</summary>
                        <div class="seo-pi-grid" style="margin-top:12px;">
                            <div class="seo-pi-field">
                                <label>Cobertura semantica</label>
                                <select name="semantic_state">
                                    <option value="">Todas</option>
                                    <option value="classified" <?php selected($filters['semantic_state'], 'classified'); ?>>Con TIPO canonico</option>
                                    <option value="with_any" <?php selected($filters['semantic_state'], 'with_any'); ?>>Con algun Vocabulary</option>
                                    <option value="without_any" <?php selected($filters['semantic_state'], 'without_any'); ?>>Sin Vocabulary</option>
                                    <option value="missing_tipo" <?php selected($filters['semantic_state'], 'missing_tipo'); ?>>Sin TIPO</option>
                                    <option value="missing_rol" <?php selected($filters['semantic_state'], 'missing_rol'); ?>>Sin ROL derivable</option>
                                    <option value="missing_aplicacion" <?php selected($filters['semantic_state'], 'missing_aplicacion'); ?>>Sin APLICACION</option>
                                    <option value="missing_plataforma" <?php selected($filters['semantic_state'], 'missing_plataforma'); ?>>Sin PLATAFORMA</option>
                                    <option value="missing_subtipo" <?php selected($filters['semantic_state'], 'missing_subtipo'); ?>>Sin SUBTIPO</option>
                                </select>
                            </div>

                            <div class="seo-pi-field">
                                <label>Categoria</label>
                                <select name="category_state">
                                    <option value="">Todas</option>
                                    <option value="with" <?php selected($filters['category_state'], 'with'); ?>>Con categoria</option>
                                    <option value="without" <?php selected($filters['category_state'], 'without'); ?>>Sin categoria</option>
                                </select>
                            </div>

                            <div class="seo-pi-field">
                                <label>Atributos</label>
                                <select name="attribute_state">
                                    <option value="">Todos</option>
                                    <option value="with" <?php selected($filters['attribute_state'], 'with'); ?>>Con atributos</option>
                                    <option value="without" <?php selected($filters['attribute_state'], 'without'); ?>>Sin atributos</option>
                                </select>
                            </div>

                            <div class="seo-pi-field">
                                <label>Vinculo proveedor</label>
                                <select name="supplier_state">
                                    <option value="">Todos</option>
                                    <option value="with" <?php selected($filters['supplier_state'], 'with'); ?>>Con proveedor</option>
                                    <option value="without" <?php selected($filters['supplier_state'], 'without'); ?>>Sin proveedor</option>
                                </select>
                            </div>

                            <div class="seo-pi-field">
                                <label>Contenido editorial</label>
                                <select name="content_state">
                                    <option value="">Todos</option>
                                    <option value="complete" <?php selected($filters['content_state'], 'complete'); ?>>Excerpt + description</option>
                                    <option value="missing_excerpt" <?php selected($filters['content_state'], 'missing_excerpt'); ?>>Sin excerpt</option>
                                    <option value="missing_description" <?php selected($filters['content_state'], 'missing_description'); ?>>Sin description</option>
                                    <option value="missing_any" <?php selected($filters['content_state'], 'missing_any'); ?>>Falta excerpt o description</option>
                                    <option value="long_title" <?php selected($filters['content_state'], 'long_title'); ?>>Titulo &gt; 120 caracteres</option>
                                    <option value="long_slug" <?php selected($filters['content_state'], 'long_slug'); ?>>Slug &gt; 120 caracteres</option>
                                </select>
                            </div>

                            <?php
                            $attribute_rows = [];
                            foreach ((array) $attribute_catalog as $row) {
                                if (is_object($row)) {
                                    $row = (array) $row;
                                }
                                if (!is_array($row)) {
                                    continue;
                                }
                                $attribute_rows[] = [
                                    'id'    => absint($row['id'] ?? 0),
                                    'slug'  => (string) ($row['slug'] ?? ''),
                                    'label' => (string) ($row['nombre'] ?? $row['slug'] ?? ''),
                                ];
                            }
                            seo_product_inventory_render_multiselect('attribute_ids', 'Tipos de atributo', $attribute_rows, $filters['attribute_ids'], 'Cualquier atributo');
                            ?>

                            <div class="seo-pi-field" style="grid-column:span 2;">
                                <label>Valor de atributo contiene</label>
                                <input type="text" name="attribute_value" value="<?php echo esc_attr($filters['attribute_value']); ?>" placeholder="Ej.: 18 V, acero, 230 mm, Makita...">
                            </div>
                        </div>
                    </details>

                    <div class="seo-pi-actions">
                        <div class="seo-pi-actions-left">
                            <button class="button button-primary" type="submit">Aplicar filtros</button>
                            <a class="button" href="<?php echo esc_url($clear_url); ?>">Restablecer</a>
                        </div>
                        <div class="seo-pi-actions-right">
                            <label>Orden
                                <select name="sort">
                                    <option value="modified_desc" <?php selected($filters['sort'], 'modified_desc'); ?>>Modificados recientemente</option>
                                    <option value="modified_asc" <?php selected($filters['sort'], 'modified_asc'); ?>>Modificados antiguos</option>
                                    <option value="title_asc" <?php selected($filters['sort'], 'title_asc'); ?>>Titulo A-Z</option>
                                    <option value="title_desc" <?php selected($filters['sort'], 'title_desc'); ?>>Titulo Z-A</option>
                                    <option value="id_desc" <?php selected($filters['sort'], 'id_desc'); ?>>ID descendente</option>
                                    <option value="id_asc" <?php selected($filters['sort'], 'id_asc'); ?>>ID ascendente</option>
                                </select>
                            </label>
                            <label>Por pagina
                                <select name="per_page">
                                    <?php foreach ([25,50,100,200] as $size): ?>
                                        <option value="<?php echo esc_attr($size); ?>" <?php selected($filters['per_page'], $size); ?>><?php echo esc_html($size); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                    </div>
                </div>
            </form>

            <div class="seo-pi-result-title">
                <h3><?php echo esc_html(number_format_i18n($total_filtered)); ?> productos encontrados</h3>
                <span class="seo-pi-muted">Pagina <?php echo esc_html(number_format_i18n($filters['paged'])); ?> de <?php echo esc_html(number_format_i18n($max_pages)); ?></span>
            </div>

            <table class="widefat striped seo-pi-table">
                <thead>
                    <tr>
                        <th style="width:70px;">ID</th>
                        <th style="min-width:310px;">Producto</th>
                        <th style="width:150px;">SKU / estado</th>
                        <th style="min-width:180px;">Categorias</th>
                        <th style="min-width:330px;">Vocabulary</th>
                        <th style="min-width:190px;">Origen</th>
                        <th style="min-width:180px;">Cobertura</th>
                        <th style="width:150px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($posts)): ?>
                    <tr><td colspan="8">No hay productos que coincidan con estos filtros.</td></tr>
                <?php else: ?>
                    <?php foreach ($posts as $post_row): ?>
                        <?php
                        $product_id = absint($post_row->ID);
                        $categories = $row_data['categories'][$product_id] ?? [];
                        $semantic = $row_data['semantic'][$product_id] ?? [
                            'rol' => [], 'tipo' => [], 'aplicacion' => [], 'plataforma' => [], 'subtipo' => [],
                        ];
                        if (!empty($semantic['_derived_roles'])) {
                            $semantic['rol'] = $semantic['_derived_roles'];
                        }
                        $attributes = $row_data['attributes'][$product_id] ?? [];

                        $sku = (string) get_post_meta($product_id, '_sku', true);
                        $stock_status = (string) get_post_meta($product_id, '_stock_status', true);
                        $provider = (string) get_post_meta($product_id, '_seo_proveedor', true);
                        $provider_external_id = (string) get_post_meta($product_id, '_seo_proveedor_id_externo', true);
                        $provider_mpn = (string) get_post_meta($product_id, '_seo_proveedor_mpn', true);
                        $provider_catalog_id = absint(get_post_meta($product_id, '_seo_proveedor_catalogo_id', true));
                        $provider_url = (string) get_post_meta($product_id, '_seo_proveedor_url_origen', true);
                        $brand = (string) get_post_meta($product_id, '_seo_marca_proveedor', true);

                        $has_type = !empty($semantic['tipo']);
                        $has_role = !empty($semantic['rol']);
                        $has_excerpt = trim((string) $post_row->post_excerpt) !== '';
                        $has_description = trim((string) $post_row->post_content) !== '';
                        $status_obj = get_post_status_object((string) $post_row->post_status);
                        $status_label = $status_obj ? $status_obj->label : (string) $post_row->post_status;

                        $edit_url = add_query_arg([
                            'page' => 'product-page-admin',
                            'tab' => 'editar',
                            'product_id' => $product_id,
                        ], admin_url('admin.php'));
                        $wp_edit_url = admin_url('post.php?post=' . $product_id . '&action=edit');
                        $view_url = get_permalink($product_id);
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($product_id); ?></strong></td>
                            <td>
                                <div class="seo-pi-product-title"><?php echo esc_html($post_row->post_title ?: '(Sin titulo)'); ?></div>
                                <div class="seo-pi-muted"><code><?php echo esc_html($post_row->post_name ?: 'sin-slug'); ?></code></div>
                                <div class="seo-pi-muted">Modificado: <?php echo esc_html(mysql2date('d/m/Y H:i', $post_row->post_modified)); ?></div>

                                <details class="seo-pi-row-details">
                                    <summary>Contenido y atributos</summary>
                                    <div class="seo-pi-row-details-body">
                                        <strong>Excerpt:</strong> <?php echo $has_excerpt ? esc_html(wp_trim_words(wp_strip_all_tags($post_row->post_excerpt), 35)) : '<span class="seo-pi-badge bad">Vacio</span>'; ?><br><br>
                                        <strong>Description:</strong> <?php echo $has_description ? esc_html(wp_trim_words(wp_strip_all_tags($post_row->post_content), 55)) : '<span class="seo-pi-badge bad">Vacia</span>'; ?>
                                        <?php if (!empty($attributes)): ?>
                                            <br><br><strong>Atributos (<?php echo esc_html(count($attributes)); ?>):</strong>
                                            <?php
                                            $attribute_preview = [];
                                            foreach (array_slice($attributes, 0, 10) as $attribute) {
                                                $name = trim((string) ($attribute->attribute_name ?? $attribute->attribute_type ?? ''));
                                                $value = trim((string) ($attribute->attribute_value ?? ''));
                                                if ($name !== '' && $value !== '') {
                                                    $attribute_preview[] = $name . ': ' . $value;
                                                }
                                            }
                                            echo esc_html(implode(' · ', $attribute_preview));
                                            if (count($attributes) > 10) {
                                                echo ' <span class="seo-pi-muted">+' . esc_html(count($attributes) - 10) . ' mas</span>';
                                            }
                                            ?>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            </td>
                            <td>
                                <code><?php echo esc_html($sku !== '' ? $sku : '—'); ?></code><br>
                                <span class="seo-pi-badge <?php echo $post_row->post_status === 'publish' ? 'ok' : 'warn'; ?>"><?php echo esc_html($status_label); ?></span>
                                <?php if ($stock_status !== ''): ?>
                                    <span class="seo-pi-badge <?php echo $stock_status === 'instock' ? 'ok' : ($stock_status === 'outofstock' ? 'bad' : 'warn'); ?>"><?php echo esc_html($stock_status); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($categories)): ?>
                                    <span class="seo-pi-badge bad">Sin categoria</span>
                                <?php else: ?>
                                    <?php foreach ($categories as $category): ?>
                                        <span class="seo-pi-chip"><?php echo esc_html($category['name']); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $semantic_labels = [
                                    'rol' => 'ROL', 'tipo' => 'TIPO', 'aplicacion' => 'APLIC.', 'plataforma' => 'PLAT.', 'subtipo' => 'SUBTIPO',
                                ];
                                $has_any_semantic = false;
                                foreach ($semantic_labels as $group => $group_label) {
                                    $values = $semantic[$group] ?? [];
                                    if (empty($values)) {
                                        continue;
                                    }
                                    $has_any_semantic = true;
                                    echo '<div class="seo-pi-sem-group"><span class="seo-pi-sem-label">' . esc_html($group_label) . '</span>';
                                    foreach ($values as $item) {
                                        echo '<span class="seo-pi-chip">' . esc_html($item['label'] ?? $item['slug'] ?? '') . '</span>';
                                    }
                                    echo '</div>';
                                }
                                if (!$has_any_semantic) {
                                    echo '<span class="seo-pi-badge bad">Sin Vocabulary</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($provider !== ''): ?>
                                    <strong><?php echo esc_html($provider); ?></strong>
                                <?php else: ?>
                                    <span class="seo-pi-badge warn">Sin proveedor</span>
                                <?php endif; ?>
                                <?php if ($brand !== ''): ?><div class="seo-pi-muted">Marca: <?php echo esc_html($brand); ?></div><?php endif; ?>
                                <?php if ($provider_mpn !== ''): ?><div class="seo-pi-muted">MPN: <code><?php echo esc_html($provider_mpn); ?></code></div><?php endif; ?>
                                <?php if ($provider_external_id !== ''): ?><div class="seo-pi-muted">ID externo: <code><?php echo esc_html($provider_external_id); ?></code></div><?php endif; ?>
                                <?php if ($provider_catalog_id > 0): ?><div class="seo-pi-muted">Catalogo: #<?php echo esc_html($provider_catalog_id); ?></div><?php endif; ?>
                                <?php if ($provider_url !== ''): ?><div><a href="<?php echo esc_url($provider_url); ?>" target="_blank" rel="noopener noreferrer">Ver proveedor ↗</a></div><?php endif; ?>
                            </td>
                            <td>
                                <span class="seo-pi-badge <?php echo !empty($categories) ? 'ok' : 'bad'; ?>">Categoria</span>
                                <span class="seo-pi-badge <?php echo $has_type ? 'ok' : 'bad'; ?>">TIPO</span>
                                <span class="seo-pi-badge <?php echo $has_role ? 'ok' : 'warn'; ?>">ROL</span>
                                <span class="seo-pi-badge <?php echo !empty($attributes) ? 'ok' : 'warn'; ?>">Atributos <?php echo esc_html(count($attributes)); ?></span>
                                <span class="seo-pi-badge <?php echo $has_excerpt ? 'ok' : 'bad'; ?>">Excerpt</span>
                                <span class="seo-pi-badge <?php echo $has_description ? 'ok' : 'bad'; ?>">Description</span>
                            </td>
                            <td>
                                <a class="button button-small button-primary" href="<?php echo esc_url($edit_url); ?>">Editar SEO</a>
                                <a class="button button-small" href="<?php echo esc_url($wp_edit_url); ?>" style="margin-top:4px;">Woo</a>
                                <?php if ($view_url): ?><a class="button button-small" href="<?php echo esc_url($view_url); ?>" target="_blank" rel="noopener noreferrer" style="margin-top:4px;">Ver</a><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($max_pages > 1): ?>
                <?php
                $base_args = seo_product_inventory_filter_query_args($filters, $cluster, $hub_primary, $hub_secondary, $cat);
                $pagination_marker = 999999999;
                $base_args['paged'] = $pagination_marker;
                $pagination_base = add_query_arg($base_args, admin_url('admin.php'));
                $pagination_base = str_replace((string) $pagination_marker, '%#%', $pagination_base);
                $pagination = paginate_links([
                    'base'      => esc_url_raw($pagination_base),
                    'format'    => '',
                    'current'   => $filters['paged'],
                    'total'     => $max_pages,
                    'type'      => 'plain',
                    'prev_text' => '«',
                    'next_text' => '»',
                ]);
                ?>
                <?php if ($pagination): ?>
                    <div class="seo-pi-pagination" style="margin-top:16px;"><?php echo wp_kses_post($pagination); ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($){
            if ($.fn.selectWoo) {
                $('.seo-pi-selectwoo').each(function(){
                    var $select = $(this);
                    $select.selectWoo({
                        width: '100%',
                        allowClear: !$select.prop('multiple'),
                        placeholder: $select.data('placeholder') || ''
                    });
                });
            }
        });
        </script>
        <?php
    }
}

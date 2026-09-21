<?php
if (!defined('ABSPATH')) exit;



// =========================================================================
// ACCIÓN 1 AJAX: SOLO DESVINCULAR (Borrar de wp_seo_relations + Crear Redirect)
// =========================================================================
add_action('wp_ajax_seo_solo_desvincular_relacion', 'seo_solo_desvincular_relacion_callback');

function seo_solo_desvincular_relacion_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' =>'No tienes permisos suficientes.']);
    }

    $term_id     = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
    $url_origen  = isset($_POST['url_origen']) ? esc_url_raw($_POST['url_origen']) : '';
    $url_destino = isset($_POST['url_destino']) ? esc_url_raw($_POST['url_destino']) : '';

    if (!$term_id || empty($url_origen) || empty($url_destino)) {
        wp_send_json_error(['message' => 'Faltan datos requeridos para procesar la acción.']);
    }

    global $wpdb;
    $tabla_relations = $wpdb->prefix . 'seo_relations';
    $tabla_redirects = $wpdb->prefix . 'seo_redirects';

    // Formatear la URL Origen: Relativa y sin barra al final para tu motor SQL
    $ruta_origen = '/' . trim(wp_make_link_relative($url_origen), '/');
    $ruta_destino = esc_url_raw($url_destino);
    $ruta_destino_relativa = '/' . trim(wp_make_link_relative($ruta_destino), '/');

    // Control de bucles de redirección
    if ($ruta_origen === $ruta_destino_relativa) {
        wp_send_json_error(['message' => 'Error: Estás intentando redirigir una categoría hacia sí misma. Elige un destino diferente.']);
    }

    // Interceptar si ya existe una redirección previa para esta URL de origen
    $redirect_existente = $wpdb->get_row($wpdb->prepare(
        "SELECT target_url FROM $tabla_redirects WHERE origin_url = %s LIMIT 1", 
        $ruta_origen
    ));

    if ($redirect_existente) {
        wp_send_json_error([
            'message' => sprintf(
                'Conflicto de Redirección: La URL "%s" ya cuenta con una redirección activa hacia "%s". Por seguridad, el proceso se ha cancelado.',
                $ruta_origen,
                esc_url($redirect_existente->target_url)
            )
        ]);
    }

    // Insertar en tu estructura de tabla exacta de redirecciones si todo está limpio
    $insert_redirect = $wpdb->insert(
        $tabla_redirects,
        array(
            'origin_url'  => $ruta_origen,
            'target_url'  => $ruta_destino,
            'status_code' => 301,
            'hits'        => 0,
            'last_hit'    => null
        ),
        array('%s', '%s', '%d', '%d', '%s')
    );

    if ($insert_redirect === false) {
        wp_send_json_error(['message' => 'Error al insertar registro en redirecciones: ' . $wpdb->last_error]);
    }

    // Eliminar únicamente la relación del mapa SEO
    $wpdb->delete(
        $tabla_relations,
        array('target_id' => $term_id, 'target_type' => 'product_cat'),
        array('%d', '%s')
    );

    wp_send_json_success(['message' => 'Desvinculado con éxito del mapa relacional.']);
}

// =========================================================================
// ACCIÓN 2 AJAX: BORRADO COMPLETO (Relaciones + Redirect + Borrado de WooCommerce)
// =========================================================================
add_action('wp_ajax_seo_borrar_y_redirigir_categoria', 'seo_borrar_y_redirigir_categoria_callback');

function seo_borrar_y_redirigir_categoria_callback() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'No tienes permisos suficientes.']);
    }

    $term_id     = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
    $url_origen  = isset($_POST['url_origen']) ? esc_url_raw($_POST['url_origen']) : '';
    $url_destino = isset($_POST['url_destino']) ? esc_url_raw($_POST['url_destino']) : '';

    if (!$term_id || empty($url_origen) || empty($url_destino)) {
        wp_send_json_error(['message' => 'Faltan datos requeridos para procesar la acción.']);
    }

    global $wpdb;
    $tabla_relations = $wpdb->prefix . 'seo_relations';
    $tabla_redirects = $wpdb->prefix . 'seo_redirects';

    $ruta_origen = '/' . trim(wp_make_link_relative($url_origen), '/');
    $ruta_destino = esc_url_raw($url_destino);
    $ruta_destino_relativa = '/' . trim(wp_make_link_relative($ruta_destino), '/');

    // Control de bucles de redirección
    if ($ruta_origen === $ruta_destino_relativa) {
        wp_send_json_error(['message' => 'Error: Estás intentando redirigir una categoría hacia sí misma. Elige un destino diferente.']);
    }

    // Interceptar si ya existe una redirección previa para esta URL de origen
    $redirect_existente = $wpdb->get_row($wpdb->prepare(
        "SELECT target_url FROM $tabla_redirects WHERE origin_url = %s LIMIT 1", 
        $ruta_origen
    ));

    if ($redirect_existente) {
        wp_send_json_error([
            'message' => sprintf(
                'Conflicto de Redirección: La URL "%s" ya cuenta con una redirección activa hacia "%s". Por seguridad, el proceso se ha cancelado.',
                $ruta_origen,
                esc_url($redirect_existente->target_url)
            )
        ]);
    }

    $insert_redirect = $wpdb->insert(
        $tabla_redirects,
        array(
            'origin_url'  => $ruta_origen,
            'target_url'  => $ruta_destino,
            'status_code' => 301,
            'hits'        => 0,
            'last_hit'    => null
        ),
        array('%s', '%s', '%d', '%d', '%s')
    );

    if ($insert_redirect === false) {
        wp_send_json_error(['message' => 'Error al insertar registro en redirecciones.']);
    }

    $wpdb->delete(
        $tabla_relations,
        array('target_id' => $term_id, 'target_type' => 'product_cat'),
        array('%d', '%s')
    );

    // Borrado físico total del término en WordPress/WooCommerce
    $borrado_wc = wp_delete_term($term_id, 'product_cat');

    if (is_wp_error($borrado_wc)) {
        wp_send_json_error(['message' => 'Redirección creada, pero falló el borrado en WC: ' . $borrado_wc->get_error_message()]);
    }

    wp_send_json_success(['message' => 'Categoría eliminada y redirección configurada con éxito.']);
}

// =========================================================================
// API PÚBLICA DEL EDITOR DE CATEGORÍAS
// =========================================================================

/**
 * Genera el enlace al editor individual desde cualquier informe del plugin.
 *
 * @param int    $term_id   ID de product_cat.
 * @param string $page_slug Slug de la página administrativa.
 * @return string
 */
function seo_get_category_editor_url($term_id, $page_slug = 'category-seo-admin') {

    $term_id = absint($term_id);

    if (!$term_id) {
        return '';
    }

    return add_query_arg(
        [
            'page'              => sanitize_key($page_slug),
            'tab'               => 'categorias',
            'edit_category_id'  => $term_id,
        ],
        admin_url('admin.php')
    );
}

/**
 * Guarda los campos editables de una categoría.
 *
 * Puede llamarse desde otros procesos del plugin.
 *
 * @param int   $term_id ID de product_cat.
 * @param array $data    name, excerpt, description y keywords.
 * @return true|WP_Error
 */
function seo_save_category_editor_data($term_id, array $data) {

    if (!current_user_can('manage_options')) {
        return new WP_Error('seo_category_forbidden', 'No tienes permisos para editar categorías.');
    }

    $term_id = absint($term_id);
    $term = get_term($term_id, 'product_cat');

    if (!$term_id || !$term || is_wp_error($term)) {
        return new WP_Error('seo_category_not_found', 'La categoría solicitada no existe.');
    }

    global $wpdb;

    $nodes_table = $wpdb->prefix . 'seo_nodes';

    $update_data = [];

    if (isset($data['name']) && trim((string) $data['name']) !== '') {
        $update_data['name'] = sanitize_text_field($data['name']);
    }

    // Solo actualizar nombre en WordPress
    if ($update_data) {
        $updated = wp_update_term(
            $term_id,
            'product_cat',
            $update_data
        );

        if (is_wp_error($updated)) {
            return $updated;
        }
    }


    // Excerpt en wp_seo_nodes
        if (array_key_exists('excerpt', $data)) {
        
            $excerpt = wp_kses_post($data['excerpt']);
        
            $excerpt_node_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM {$nodes_table}
                     WHERE object_type = 'category'
                     AND object_id = %d
                     AND seo_role = 'excerpt'
                     LIMIT 1",
                    $term_id
                )
            );
        
            if ($excerpt_node_id) {
        
                $result = $wpdb->update(
                    $nodes_table,
                    [
                        'keywords' => $excerpt
                    ],
                    [
                        'id' => absint($excerpt_node_id)
                    ],
                    ['%s'],
                    ['%d']
                );
        
            } else {
        
                $result = $wpdb->insert(
                    $nodes_table,
                    [
                        'object_type' => 'category',
                        'object_id'   => $term_id,
                        'seo_role'    => 'excerpt',
                        'keywords'    => $excerpt,
                        'status'      => 1
                    ],
                    ['%s','%d','%s','%s','%d']
                );
        
            }
        
            if ($result === false) {
                return new WP_Error(
                    'seo_category_excerpt_error',
                    $wpdb->last_error
                );
            }
        }

    // Description HTML en seo_nodes
    if (array_key_exists('description', $data)) {

        $description = wp_kses_post($data['description']);

        $description_node_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$nodes_table}
                 WHERE object_type = 'category'
                   AND object_id = %d
                   AND seo_role = 'description'
                 LIMIT 1",
                $term_id
            )
        );

        if ($description_node_id) {

            $result = $wpdb->update(
                $nodes_table,
                [
                    'keywords' => $description
                ],
                [
                    'id' => absint($description_node_id)
                ],
                ['%s'],
                ['%d']
            );

        } else {

            $result = $wpdb->insert(
                $nodes_table,
                [
                    'object_type' => 'category',
                    'object_id'   => $term_id,
                    'seo_role'    => 'description',
                    'keywords'    => $description,
                    'status'      => 1
                ],
                ['%s', '%d', '%s', '%s', '%d']
            );
        }

        if ($result === false) {
            return new WP_Error(
                'seo_category_description_error',
                $wpdb->last_error
            );
        }
    }

    // Keywords SEO
    if (array_key_exists('keywords', $data)) {

        $keywords = sanitize_textarea_field($data['keywords']);

        $node_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$nodes_table}
                 WHERE object_type = 'category'
                   AND object_id = %d
                   AND seo_role = 'category'
                 LIMIT 1",
                $term_id
            )
        );

        if ($node_id) {

            $result = $wpdb->update(
                $nodes_table,
                ['keywords' => $keywords],
                ['id' => absint($node_id)],
                ['%s'],
                ['%d']
            );

        } else {

            $result = $wpdb->insert(
                $nodes_table,
                [
                    'object_type' => 'category',
                    'object_id'   => $term_id,
                    'seo_role'    => 'category',
                    'keywords'    => $keywords,
                    'status'      => 1
                ],
                ['%s', '%d', '%s', '%s', '%d']
            );
        }

        if ($result === false) {
            return new WP_Error(
                'seo_category_database_error',
                $wpdb->last_error
            );
        }
    }

    return true;
}


// =========================================================================
// INVENTARIO EDITORIAL POR CATEGORÍA
// =========================================================================

if (!function_exists('seo_category_inventory_table_exists')) {
    function seo_category_inventory_table_exists($table_name) {
        global $wpdb;
        static $cache = [];

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

        $cache[$table_name] = ((string) $found === $table_name);
        return $cache[$table_name];
    }
}

if (!function_exists('seo_category_inventory_post_link')) {
    function seo_category_inventory_post_link($post_id, $title, $status = '') {
        $post_id = absint($post_id);
        $title   = trim((string) $title);
        $status  = sanitize_key((string) $status);

        if ($title === '') {
            $title = '#' . $post_id;
        }

        $edit_url = $post_id > 0
            ? add_query_arg(['post' => $post_id, 'action' => 'edit'], admin_url('post.php'))
            : '';

        $status_label = $status !== '' ? ' <small style="color:#646970;">(' . esc_html($status) . ')</small>' : '';

        if ($edit_url) {
            return '<a href="' . esc_url($edit_url) . '">' . esc_html($title) . '</a>' . $status_label;
        }

        return esc_html($title) . $status_label;
    }
}

if (!function_exists('seo_category_inventory_export_batch')) {
    /**
     * Recoge un lote de categorías para el JSON sin cargar el catálogo completo
     * en memoria. Las fuentes son las mismas que en la pestaña Inventario.
     *
     * @param array $categories WP_Term[] de product_cat.
     * @return array<int,array>
     */
    function seo_category_inventory_export_batch($categories) {
        global $wpdb;

        $categories = array_values((array) $categories);
        $category_ids = array_values(array_filter(array_map(static function($term) {
            return absint($term->term_id ?? 0);
        }, $categories)));

        if (!$category_ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $relations_table = $wpdb->prefix . 'seo_relations';
        $posts_table = $wpdb->posts;
        $vocab_table = $wpdb->prefix . 'seo_vocabulary';
        $object_vocab_table = $wpdb->prefix . 'seo_object_vocabulary';
        $faq_table = $wpdb->prefix . 'seo_faq';

        $hierarchy = $vocabulary = $posts = $landings = $faqs = [];
        foreach ($category_ids as $category_id) {
            $hierarchy[$category_id] = [];
            $vocabulary[$category_id] = [];
            $posts[$category_id] = [];
            $landings[$category_id] = [];
            $faqs[$category_id] = [];
        }

        // Jerarquía explícita: cluster -> hub_primary -> hub_secondary -> product_cat.
        if (seo_category_inventory_table_exists($relations_table)) {
            $sql = "SELECT
                        rc.target_id AS category_id,
                        rc.source_id AS hub_secondary_id,
                        hs.post_title AS hub_secondary_title,
                        hs.post_status AS hub_secondary_status,
                        rp.source_id AS hub_primary_id,
                        hp.post_title AS hub_primary_title,
                        hp.post_status AS hub_primary_status,
                        rcl.source_id AS cluster_id,
                        cl.post_title AS cluster_title,
                        cl.post_status AS cluster_status
                    FROM {$relations_table} rc
                    LEFT JOIN {$relations_table} rp
                      ON rp.target_id = rc.source_id
                     AND rp.relation_type = 'hub_primary_to_hub_secondary'
                    LEFT JOIN {$relations_table} rcl
                      ON rcl.target_id = rp.source_id
                     AND rcl.relation_type = 'cluster_to_primary'
                    LEFT JOIN {$posts_table} hs ON hs.ID = rc.source_id
                    LEFT JOIN {$posts_table} hp ON hp.ID = rp.source_id
                    LEFT JOIN {$posts_table} cl ON cl.ID = rcl.source_id
                    WHERE rc.relation_type = 'hub_secondary_to_category'
                      AND rc.target_type = 'product_cat'
                      AND rc.target_id IN ({$placeholders})
                    ORDER BY rc.target_id ASC, cl.post_title ASC, hp.post_title ASC, hs.post_title ASC";

            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$category_ids), ARRAY_A);
            foreach ((array) $rows as $row) {
                $category_id = absint($row['category_id'] ?? 0);
                if (!isset($hierarchy[$category_id])) {
                    continue;
                }
                $key = absint($row['cluster_id'] ?? 0) . ':'
                     . absint($row['hub_primary_id'] ?? 0) . ':'
                     . absint($row['hub_secondary_id'] ?? 0);
                $hierarchy[$category_id][$key] = [
                    'cluster' => [
                        'id' => absint($row['cluster_id'] ?? 0),
                        'title' => (string) ($row['cluster_title'] ?? ''),
                        'status' => (string) ($row['cluster_status'] ?? ''),
                    ],
                    'hub_primary' => [
                        'id' => absint($row['hub_primary_id'] ?? 0),
                        'title' => (string) ($row['hub_primary_title'] ?? ''),
                        'status' => (string) ($row['hub_primary_status'] ?? ''),
                    ],
                    'hub_secondary' => [
                        'id' => absint($row['hub_secondary_id'] ?? 0),
                        'title' => (string) ($row['hub_secondary_title'] ?? ''),
                        'status' => (string) ($row['hub_secondary_status'] ?? ''),
                    ],
                ];
            }
        }

        // Vocabulary canónico asignado a product_cat.
        if (
            seo_category_inventory_table_exists($vocab_table)
            && seo_category_inventory_table_exists($object_vocab_table)
        ) {
            $sql = "SELECT ov.object_id AS category_id, v.id AS vocabulary_id,
                           v.semantic_group, v.label, v.slug
                    FROM {$object_vocab_table} ov
                    INNER JOIN {$vocab_table} v ON v.id = ov.vocabulary_id
                    WHERE ov.object_type = 'product_cat'
                      AND ov.status = 1
                      AND v.active = 1
                      AND ov.object_id IN ({$placeholders})
                    ORDER BY ov.object_id ASC, v.semantic_group ASC, v.label ASC";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$category_ids), ARRAY_A);
            foreach ((array) $rows as $row) {
                $category_id = absint($row['category_id'] ?? 0);
                if (!isset($vocabulary[$category_id])) {
                    continue;
                }
                $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
                if ($group === '') {
                    $group = 'sin_grupo';
                }
                if (!isset($vocabulary[$category_id][$group])) {
                    $vocabulary[$category_id][$group] = [];
                }
                $vocabulary[$category_id][$group][] = [
                    'id' => absint($row['vocabulary_id'] ?? 0),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'label' => (string) ($row['label'] ?? ''),
                ];
            }
        }

        // Posts y landings: solo relaciones explícitas con la categoría.
        if (seo_category_inventory_table_exists($relations_table)) {
            $sql = "SELECT r.id AS relation_id, r.target_id AS category_id, r.source_id,
                           r.source_type, r.relation_type, p.post_title, p.post_status, p.post_type
                    FROM {$relations_table} r
                    LEFT JOIN {$posts_table} p ON p.ID = r.source_id
                    WHERE r.target_type = 'product_cat'
                      AND r.target_id IN ({$placeholders})
                      AND r.relation_type IN ('post_to_category','landing_to_category')
                    ORDER BY r.target_id ASC, r.relation_type ASC, p.post_title ASC";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$category_ids), ARRAY_A);
            foreach ((array) $rows as $row) {
                $category_id = absint($row['category_id'] ?? 0);
                $source_id = absint($row['source_id'] ?? 0);
                if (!$source_id || !isset($posts[$category_id])) {
                    continue;
                }
                $item = [
                    'relation_id' => absint($row['relation_id'] ?? 0),
                    'id' => $source_id,
                    'source_type' => (string) ($row['source_type'] ?? ''),
                    'relation_type' => (string) ($row['relation_type'] ?? ''),
                    'post_type' => (string) ($row['post_type'] ?? ''),
                    'status' => (string) ($row['post_status'] ?? ''),
                    'title' => (string) ($row['post_title'] ?? ''),
                ];
                if (($row['relation_type'] ?? '') === 'post_to_category') {
                    $posts[$category_id][$source_id] = $item;
                } elseif (($row['relation_type'] ?? '') === 'landing_to_category') {
                    $landings[$category_id][$source_id] = $item;
                }
            }
        }

        // FAQs: relación real de seo_relations + datos de seo_faq.
        if (
            seo_category_inventory_table_exists($relations_table)
            && seo_category_inventory_table_exists($faq_table)
        ) {
            $sql = "SELECT id AS relation_id, source_type, source_id, target_type, target_id, relation_type
                    FROM {$relations_table}
                    WHERE (
                        target_type = 'product_cat'
                        AND target_id IN ({$placeholders})
                        AND (source_type LIKE '%%faq%%' OR relation_type LIKE '%%faq%%')
                    ) OR (
                        source_type = 'product_cat'
                        AND source_id IN ({$placeholders})
                        AND (target_type LIKE '%%faq%%' OR relation_type LIKE '%%faq%%')
                    )";
            $relation_rows = $wpdb->get_results(
                $wpdb->prepare($sql, ...array_merge($category_ids, $category_ids)),
                ARRAY_A
            );

            $faq_ids_by_category = [];
            $faq_relations_by_category = [];
            $all_faq_ids = [];

            foreach ((array) $relation_rows as $row) {
                $category_id = 0;
                $faq_id = 0;
                if (($row['target_type'] ?? '') === 'product_cat') {
                    $category_id = absint($row['target_id'] ?? 0);
                    $faq_id = absint($row['source_id'] ?? 0);
                } elseif (($row['source_type'] ?? '') === 'product_cat') {
                    $category_id = absint($row['source_id'] ?? 0);
                    $faq_id = absint($row['target_id'] ?? 0);
                }
                if (!$category_id || !$faq_id || !isset($faqs[$category_id])) {
                    continue;
                }
                $faq_ids_by_category[$category_id][$faq_id] = true;
                $faq_relations_by_category[$category_id][$faq_id] = [
                    'relation_id' => absint($row['relation_id'] ?? 0),
                    'relation_type' => (string) ($row['relation_type'] ?? ''),
                    'source_type' => (string) ($row['source_type'] ?? ''),
                    'target_type' => (string) ($row['target_type'] ?? ''),
                ];
                $all_faq_ids[$faq_id] = true;
            }

            if ($all_faq_ids) {
                $faq_ids = array_keys($all_faq_ids);
                $faq_placeholders = implode(',', array_fill(0, count($faq_ids), '%d'));
                $faq_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, object_type, object_id, question, active, sort_order
                         FROM {$faq_table}
                         WHERE id IN ({$faq_placeholders})
                         ORDER BY active DESC, sort_order ASC, id ASC",
                        ...$faq_ids
                    ),
                    ARRAY_A
                );
                $faq_by_id = [];
                foreach ((array) $faq_rows as $faq_row) {
                    $faq_by_id[absint($faq_row['id'] ?? 0)] = $faq_row;
                }
                foreach ($faq_ids_by_category as $category_id => $ids) {
                    foreach (array_keys($ids) as $faq_id) {
                        if (!isset($faq_by_id[$faq_id])) {
                            continue;
                        }
                        $faq_row = $faq_by_id[$faq_id];
                        $relation = $faq_relations_by_category[$category_id][$faq_id] ?? [];
                        $faqs[$category_id][$faq_id] = [
                            'id' => $faq_id,
                            'object_type' => absint($faq_row['object_type'] ?? 0),
                            'object_id' => absint($faq_row['object_id'] ?? 0),
                            'question' => (string) ($faq_row['question'] ?? ''),
                            'active' => absint($faq_row['active'] ?? 0) === 1,
                            'sort_order' => absint($faq_row['sort_order'] ?? 0),
                            'relation' => $relation,
                        ];
                    }
                }
            }
        }

        $export = [];
        foreach ($categories as $category) {
            $category_id = absint($category->term_id ?? 0);
            if (!$category_id) {
                continue;
            }

            $paths = array_values($hierarchy[$category_id] ?? []);
            $post_items = array_values($posts[$category_id] ?? []);
            $landing_items = array_values($landings[$category_id] ?? []);
            $faq_items = array_values($faqs[$category_id] ?? []);

            $published_posts = count(array_filter($post_items, static function($row) {
                return ($row['status'] ?? '') === 'publish';
            }));
            $published_landings = count(array_filter($landing_items, static function($row) {
                return ($row['status'] ?? '') === 'publish';
            }));
            $active_faqs = count(array_filter($faq_items, static function($row) {
                return !empty($row['active']);
            }));
            $url_load = $published_posts + $published_landings;
            $url_level = $url_load >= 6 ? 'alta' : ($url_load >= 3 ? 'media' : 'baja');

            $term_url = get_term_link($category);
            if (is_wp_error($term_url)) {
                $term_url = '';
            }

            $vocab_groups = $vocabulary[$category_id] ?? [];
            $vocab_count = 0;
            foreach ($vocab_groups as $group_items) {
                $vocab_count += count((array) $group_items);
            }

            $export[] = [
                'category' => [
                    'id' => $category_id,
                    'name' => (string) ($category->name ?? ''),
                    'slug' => (string) ($category->slug ?? ''),
                    'parent_id' => absint($category->parent ?? 0),
                    'url' => (string) $term_url,
                ],
                'hierarchy' => [
                    'path_count' => count($paths),
                    'paths' => $paths,
                ],
                'vocabulary' => [
                    'count' => $vocab_count,
                    'groups' => $vocab_groups,
                ],
                'faqs' => [
                    'active_count' => $active_faqs,
                    'total_count' => count($faq_items),
                    'items' => $faq_items,
                ],
                'posts' => [
                    'published_count' => $published_posts,
                    'total_count' => count($post_items),
                    'items' => $post_items,
                ],
                'landings' => [
                    'published_count' => $published_landings,
                    'total_count' => count($landing_items),
                    'items' => $landing_items,
                ],
                'products' => [
                    'count' => absint($category->count ?? 0),
                    'source' => 'wordpress_product_cat_count',
                ],
                'url_load' => [
                    'count' => $url_load,
                    'level' => $url_level,
                    'definition' => 'posts_publicados + landings_publicadas',
                ],
                'signals' => [
                    'sin_jerarquia' => count($paths) === 0,
                    'multiples_rutas' => count($paths) > 1,
                    'sin_vocabulary' => $vocab_count === 0,
                    'sin_faq_activas' => $active_faqs === 0,
                    'sin_posts_publicados' => $published_posts === 0,
                    'sin_landings_publicadas' => $published_landings === 0,
                    'sin_productos' => absint($category->count ?? 0) === 0,
                    'carga_url_alta' => $url_load >= 6,
                ],
            ];
        }

        return $export;
    }
}

if (!function_exists('seo_category_inventory_export_json')) {
    /**
     * Descarga todo el inventario como JSON mediante streaming por lotes.
     */
    function seo_category_inventory_export_json() {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para exportar este inventario.');
        }

        check_admin_referer('seo_category_inventory_export_json');

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $charset = get_option('blog_charset') ?: 'UTF-8';
        $filename = 'inventario-categorias-seo-' . wp_date('Ymd-His') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=' . $charset);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $total_categories = wp_count_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);
        $total_categories = is_wp_error($total_categories) ? 0 : absint($total_categories);

        $relations_table = $GLOBALS['wpdb']->prefix . 'seo_relations';
        $vocab_table = $GLOBALS['wpdb']->prefix . 'seo_vocabulary';
        $object_vocab_table = $GLOBALS['wpdb']->prefix . 'seo_object_vocabulary';
        $faq_table = $GLOBALS['wpdb']->prefix . 'seo_faq';

        $header = [
            'schema_version' => '1.0',
            'generated_at' => wp_date('c'),
            'site_url' => home_url('/'),
            'taxonomy' => 'product_cat',
            'total_categories' => $total_categories,
            'sources' => [
                'hierarchy' => 'seo_relations: cluster_to_primary > hub_primary_to_hub_secondary > hub_secondary_to_category',
                'vocabulary' => 'seo_object_vocabulary + seo_vocabulary',
                'faqs' => 'seo_relations + seo_faq',
                'posts' => 'seo_relations: post_to_category',
                'landings' => 'seo_relations: landing_to_category',
                'products' => 'WordPress/WooCommerce product_cat count',
            ],
            'tables_available' => [
                'seo_relations' => seo_category_inventory_table_exists($relations_table),
                'seo_vocabulary' => seo_category_inventory_table_exists($vocab_table),
                'seo_object_vocabulary' => seo_category_inventory_table_exists($object_vocab_table),
                'seo_faq' => seo_category_inventory_table_exists($faq_table),
            ],
            'url_load_rule' => [
                'formula' => 'published_posts + published_landings',
                'low' => '0-2',
                'medium' => '3-5',
                'high' => '6+',
                'note' => 'Indicador interno de auditoría; no demuestra canibalización por sí solo.',
            ],
        ];

        $header_json = wp_json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($header_json === false) {
            $header_json = '{}';
        }
        // Convertimos el objeto cabecera en el inicio del documento y abrimos categories.
        echo substr($header_json, 0, -1) . ',"categories":[';

        $batch_size = 50;
        $offset = 0;
        $first = true;

        while ($offset < $total_categories) {
            $categories = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC',
                'number' => $batch_size,
                'offset' => $offset,
            ]);

            if (is_wp_error($categories) || empty($categories)) {
                break;
            }

            $items = seo_category_inventory_export_batch($categories);
            foreach ($items as $item) {
                $json = wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    continue;
                }
                if (!$first) {
                    echo ',';
                }
                echo $json;
                $first = false;
            }

            $offset += count($categories);
            if (function_exists('flush')) {
                @flush();
            }
            if (count($categories) < $batch_size) {
                break;
            }
        }

        echo ']}';
        exit;
    }
}
add_action('admin_post_seo_category_inventory_export_json', 'seo_category_inventory_export_json');

if (!function_exists('seo_category_inventory_render')) {
    function seo_category_inventory_render($page_slug = 'category-seo-admin') {
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>No tienes permisos para ver este inventario.</p></div>';
            return;
        }

        global $wpdb;

        $per_page = 50;
        $current_page = isset($_GET['inventory_page']) ? max(1, absint($_GET['inventory_page'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        $inventory_sort = isset($_GET['inventory_sort'])
            ? sanitize_key(wp_unslash($_GET['inventory_sort']))
            : 'name_asc';

        $allowed_inventory_sorts = [
            'name_asc',
            'pressure_desc',
            'pressure_asc',
        ];

        if (!in_array($inventory_sort, $allowed_inventory_sorts, true)) {
            $inventory_sort = 'name_asc';
        }

        $total_categories = wp_count_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);
        $total_categories = is_wp_error($total_categories) ? 0 : absint($total_categories);

        /*
         * El orden por presión se calcula globalmente antes de paginar.
         * Solo se trae un COUNT agregado por categoría, nunca las filas completas
         * de posts/landings de todo el catálogo.
         */
        if ($inventory_sort === 'pressure_desc' || $inventory_sort === 'pressure_asc') {
            $relations_sort_table = $wpdb->prefix . 'seo_relations';
            $url_load_by_category = [];

            if (seo_category_inventory_table_exists($relations_sort_table)) {
                $load_rows = $wpdb->get_results(
                    "SELECT r.target_id AS category_id,
                            COUNT(DISTINCT r.relation_type, r.source_id) AS url_load
                     FROM {$relations_sort_table} r
                     INNER JOIN {$wpdb->posts} p ON p.ID = r.source_id
                     WHERE r.target_type = 'product_cat'
                       AND r.relation_type IN ('post_to_category','landing_to_category')
                       AND p.post_status = 'publish'
                     GROUP BY r.target_id",
                    ARRAY_A
                );

                foreach ((array) $load_rows as $load_row) {
                    $url_load_by_category[absint($load_row['category_id'] ?? 0)] =
                        absint($load_row['url_load'] ?? 0);
                }
            }

            $all_categories_for_sort = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]);

            if (is_wp_error($all_categories_for_sort)) {
                echo '<div class="notice notice-error"><p>' . esc_html($all_categories_for_sort->get_error_message()) . '</p></div>';
                return;
            }

            usort($all_categories_for_sort, static function($a, $b) use ($url_load_by_category, $inventory_sort) {
                $a_id = absint($a->term_id ?? 0);
                $b_id = absint($b->term_id ?? 0);
                $a_load = absint($url_load_by_category[$a_id] ?? 0);
                $b_load = absint($url_load_by_category[$b_id] ?? 0);

                $a_pressure = $a_load >= 6 ? 3 : ($a_load >= 3 ? 2 : 1);
                $b_pressure = $b_load >= 6 ? 3 : ($b_load >= 3 ? 2 : 1);

                if ($a_pressure !== $b_pressure) {
                    if ($inventory_sort === 'pressure_desc') {
                        return $b_pressure <=> $a_pressure;
                    }
                    return $a_pressure <=> $b_pressure;
                }

                // Dentro del mismo nivel, ordenar también por carga real.
                if ($a_load !== $b_load) {
                    if ($inventory_sort === 'pressure_desc') {
                        return $b_load <=> $a_load;
                    }
                    return $a_load <=> $b_load;
                }

                return strnatcasecmp((string) ($a->name ?? ''), (string) ($b->name ?? ''));
            });

            $categories = array_slice($all_categories_for_sort, $offset, $per_page);
            unset($all_categories_for_sort, $load_rows, $url_load_by_category);
        } else {
            $categories = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
                'number'     => $per_page,
                'offset'     => $offset,
            ]);

            if (is_wp_error($categories)) {
                echo '<div class="notice notice-error"><p>' . esc_html($categories->get_error_message()) . '</p></div>';
                return;
            }
        }

        echo '<div style="max-width:100%;">';
        echo '<h2 style="margin-top:0;">Inventario de contenidos por categoría</h2>';
        echo '<p style="max-width:1100px;">Vista de control centrada en cada categoría de producto. La jerarquía, posts, landings y FAQs se obtienen de <code>seo_relations</code>; las etiquetas del Vocabulary; y el número de productos del contador nativo de WordPress/WooCommerce. La carga URL cuenta solo posts y landings publicados.</p>';
        $export_url = wp_nonce_url(
            admin_url('admin-post.php?action=seo_category_inventory_export_json'),
            'seo_category_inventory_export_json'
        );
        echo '<p style="margin:12px 0 12px;">';
        echo '<a class="button button-primary" href="' . esc_url($export_url) . '">Descargar JSON completo</a>';
        echo ' <span style="color:#646970;font-size:12px;">Exporta todas las categorías por lotes, no solo esta página.</span>';
        echo '</p>';

        echo '<form method="get" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 18px;padding:10px 12px;background:#fff;border:1px solid #dcdcde;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page_slug) . '">';
        echo '<input type="hidden" name="tab" value="inventario">';
        echo '<label for="seo_inventory_sort"><strong>Ordenar inventario:</strong></label>';
        echo '<select id="seo_inventory_sort" name="inventory_sort">';
        echo '<option value="name_asc" ' . selected($inventory_sort, 'name_asc', false) . '>Nombre A–Z</option>';
        echo '<option value="pressure_desc" ' . selected($inventory_sort, 'pressure_desc', false) . '>Presión URL: alta → media → baja</option>';
        echo '<option value="pressure_asc" ' . selected($inventory_sort, 'pressure_asc', false) . '>Presión URL: baja → media → alta</option>';
        echo '</select>';
        echo '<button type="submit" class="button button-secondary">Ordenar</button>';
        echo '<span style="color:#646970;font-size:12px;">Alta = 6+, media = 3–5, baja = 0–2 URLs editoriales.</span>';
        echo '</form>';

        if (empty($categories)) {
            echo '<p>No hay categorías que mostrar.</p></div>';
            return;
        }

        $category_ids = array_values(array_filter(array_map(static function($term) {
            return absint($term->term_id ?? 0);
        }, $categories)));

        if (empty($category_ids)) {
            echo '<p>No hay IDs de categoría válidos.</p></div>';
            return;
        }

        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $relations_table = $wpdb->prefix . 'seo_relations';
        $posts_table = $wpdb->posts;
        $vocab_table = $wpdb->prefix . 'seo_vocabulary';
        $object_vocab_table = $wpdb->prefix . 'seo_object_vocabulary';
        $faq_table = $wpdb->prefix . 'seo_faq';

        $hierarchy = [];
        $vocabulary = [];
        $posts = [];
        $landings = [];
        $faqs = [];

        foreach ($category_ids as $category_id) {
            $hierarchy[$category_id] = [];
            $vocabulary[$category_id] = [];
            $posts[$category_id] = [];
            $landings[$category_id] = [];
            $faqs[$category_id] = [];
        }

        // 1) Jerarquía superior: Cluster -> Hub primario -> Hub secundario -> Categoría.
        if (seo_category_inventory_table_exists($relations_table)) {
            $sql = "SELECT
                        rc.target_id AS category_id,
                        rc.source_id AS hub_secondary_id,
                        hs.post_title AS hub_secondary_title,
                        rp.source_id AS hub_primary_id,
                        hp.post_title AS hub_primary_title,
                        rcl.source_id AS cluster_id,
                        cl.post_title AS cluster_title
                    FROM {$relations_table} rc
                    LEFT JOIN {$relations_table} rp
                      ON rp.target_id = rc.source_id
                     AND rp.relation_type = 'hub_primary_to_hub_secondary'
                    LEFT JOIN {$relations_table} rcl
                      ON rcl.target_id = rp.source_id
                     AND rcl.relation_type = 'cluster_to_primary'
                    LEFT JOIN {$posts_table} hs ON hs.ID = rc.source_id
                    LEFT JOIN {$posts_table} hp ON hp.ID = rp.source_id
                    LEFT JOIN {$posts_table} cl ON cl.ID = rcl.source_id
                    WHERE rc.relation_type = 'hub_secondary_to_category'
                      AND rc.target_type = 'product_cat'
                      AND rc.target_id IN ({$placeholders})
                    ORDER BY rc.target_id ASC, cl.post_title ASC, hp.post_title ASC, hs.post_title ASC";

            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$category_ids), ARRAY_A);

            foreach ((array) $rows as $row) {
                $category_id = absint($row['category_id'] ?? 0);
                if (!isset($hierarchy[$category_id])) {
                    continue;
                }

                $key = absint($row['cluster_id'] ?? 0) . ':'
                     . absint($row['hub_primary_id'] ?? 0) . ':'
                     . absint($row['hub_secondary_id'] ?? 0);

                $hierarchy[$category_id][$key] = $row;
            }
        }

        // 2) Etiquetas de Vocabulary asignadas a la categoría.
        if (
            seo_category_inventory_table_exists($vocab_table)
            && seo_category_inventory_table_exists($object_vocab_table)
        ) {
            $sql = "SELECT ov.object_id AS category_id, v.semantic_group, v.label, v.slug
                    FROM {$object_vocab_table} ov
                    INNER JOIN {$vocab_table} v ON v.id = ov.vocabulary_id
                    WHERE ov.object_type = 'product_cat'
                      AND ov.status = 1
                      AND v.active = 1
                      AND ov.object_id IN ({$placeholders})
                    ORDER BY ov.object_id ASC, v.semantic_group ASC, v.label ASC";

            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$category_ids), ARRAY_A);

            foreach ((array) $rows as $row) {
                $category_id = absint($row['category_id'] ?? 0);
                if (!isset($vocabulary[$category_id])) {
                    continue;
                }
                $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
                $label = trim((string) ($row['label'] ?? $row['slug'] ?? ''));
                if ($label === '') {
                    continue;
                }
                if (!isset($vocabulary[$category_id][$group])) {
                    $vocabulary[$category_id][$group] = [];
                }
                $vocabulary[$category_id][$group][] = $label;
            }
        }

        // 3 y 4) Posts y landings: únicamente relaciones explícitas en seo_relations.
        if (seo_category_inventory_table_exists($relations_table)) {
            $sql = "SELECT r.target_id AS category_id, r.source_id, r.source_type,
                           r.relation_type, p.post_title, p.post_status, p.post_type
                    FROM {$relations_table} r
                    LEFT JOIN {$posts_table} p ON p.ID = r.source_id
                    WHERE r.target_type = 'product_cat'
                      AND r.target_id IN ({$placeholders})
                      AND r.relation_type IN ('post_to_category','landing_to_category')
                    ORDER BY r.target_id ASC, r.relation_type ASC, p.post_title ASC";

            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$category_ids), ARRAY_A);

            foreach ((array) $rows as $row) {
                $category_id = absint($row['category_id'] ?? 0);
                $source_id = absint($row['source_id'] ?? 0);
                if (!$source_id || !isset($posts[$category_id])) {
                    continue;
                }

                if (($row['relation_type'] ?? '') === 'post_to_category') {
                    $posts[$category_id][$source_id] = $row;
                } elseif (($row['relation_type'] ?? '') === 'landing_to_category') {
                    $landings[$category_id][$source_id] = $row;
                }
            }
        }

        // 5) FAQs: la relación con la categoría sale de seo_relations.
        // No se presupone un relation_type concreto: se acepta cualquier relación real
        // en la que uno de los extremos sea product_cat y el otro extremo sea FAQ.
        if (
            seo_category_inventory_table_exists($relations_table)
            && seo_category_inventory_table_exists($faq_table)
        ) {
            $sql = "SELECT source_type, source_id, target_type, target_id, relation_type
                    FROM {$relations_table}
                    WHERE (
                        target_type = 'product_cat'
                        AND target_id IN ({$placeholders})
                        AND (source_type LIKE '%%faq%%' OR relation_type LIKE '%%faq%%')
                    ) OR (
                        source_type = 'product_cat'
                        AND source_id IN ({$placeholders})
                        AND (target_type LIKE '%%faq%%' OR relation_type LIKE '%%faq%%')
                    )";

            $faq_relation_rows = $wpdb->get_results(
                $wpdb->prepare($sql, ...array_merge($category_ids, $category_ids)),
                ARRAY_A
            );

            $faq_ids_by_category = [];
            $all_faq_ids = [];

            foreach ((array) $faq_relation_rows as $row) {
                $category_id = 0;
                $faq_id = 0;

                if (($row['target_type'] ?? '') === 'product_cat') {
                    $category_id = absint($row['target_id'] ?? 0);
                    $faq_id = absint($row['source_id'] ?? 0);
                } elseif (($row['source_type'] ?? '') === 'product_cat') {
                    $category_id = absint($row['source_id'] ?? 0);
                    $faq_id = absint($row['target_id'] ?? 0);
                }

                if (!$category_id || !$faq_id || !isset($faqs[$category_id])) {
                    continue;
                }

                $faq_ids_by_category[$category_id][$faq_id] = true;
                $all_faq_ids[$faq_id] = true;
            }

            if (!empty($all_faq_ids)) {
                $faq_ids = array_keys($all_faq_ids);
                $faq_placeholders = implode(',', array_fill(0, count($faq_ids), '%d'));
                $faq_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, question, active, sort_order
                         FROM {$faq_table}
                         WHERE id IN ({$faq_placeholders})
                         ORDER BY active DESC, sort_order ASC, id ASC",
                        ...$faq_ids
                    ),
                    ARRAY_A
                );

                $faq_by_id = [];
                foreach ((array) $faq_rows as $faq_row) {
                    $faq_by_id[absint($faq_row['id'] ?? 0)] = $faq_row;
                }

                foreach ($faq_ids_by_category as $category_id => $ids) {
                    foreach (array_keys($ids) as $faq_id) {
                        if (isset($faq_by_id[$faq_id])) {
                            $faqs[$category_id][$faq_id] = $faq_by_id[$faq_id];
                        }
                    }
                }
            }
        }

        $from = $total_categories > 0 ? ($offset + 1) : 0;
        $to = min($offset + count($categories), $total_categories);

        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0;">';
        echo '<span style="background:#fff;border:1px solid #dcdcde;padding:7px 10px;border-radius:5px;"><strong>Categorías:</strong> ' . number_format_i18n($total_categories) . '</span>';
        echo '<span style="background:#fff;border:1px solid #dcdcde;padding:7px 10px;border-radius:5px;"><strong>Mostrando:</strong> ' . number_format_i18n($from) . '–' . number_format_i18n($to) . '</span>';
        echo '<span style="background:#fff;border:1px solid #dcdcde;padding:7px 10px;border-radius:5px;"><strong>Por página:</strong> ' . number_format_i18n($per_page) . '</span>';
        echo '</div>';

        echo '<div style="overflow-x:auto;background:#fff;border:1px solid #dcdcde;">';
        echo '<table class="widefat striped" style="min-width:1500px;">';
        echo '<thead><tr>';
        echo '<th style="width:220px;">Categoría</th>';
        echo '<th style="width:300px;">Jerarquía superior</th>';
        echo '<th style="width:280px;">Vocabulary / etiquetas</th>';
        echo '<th style="width:280px;">FAQs</th>';
        echo '<th style="width:280px;">Posts</th>';
        echo '<th style="width:280px;">Landings</th>';
        echo '<th style="width:90px;text-align:center;">Productos</th>';
        echo '<th style="width:110px;text-align:center;">Carga URL</th>';
        echo '</tr></thead><tbody>';

        foreach ($categories as $category) {
            $category_id = absint($category->term_id);
            $term_link = get_term_link($category);
            $term_link = is_wp_error($term_link) ? '' : $term_link;
            $edit_link = seo_get_category_editor_url($category_id, $page_slug);

            $published_posts = 0;
            foreach ($posts[$category_id] as $row) {
                if (($row['post_status'] ?? '') === 'publish') {
                    $published_posts++;
                }
            }
            $published_landings = 0;
            foreach ($landings[$category_id] as $row) {
                if (($row['post_status'] ?? '') === 'publish') {
                    $published_landings++;
                }
            }
            $url_load = $published_posts + $published_landings;

            if ($url_load >= 6) {
                $load_label = 'Alta';
                $load_style = 'background:#fce8e6;color:#8a1f11;border:1px solid #f3b8b2;';
            } elseif ($url_load >= 3) {
                $load_label = 'Media';
                $load_style = 'background:#fff4ce;color:#6b4f00;border:1px solid #ead58a;';
            } else {
                $load_label = 'Baja';
                $load_style = 'background:#edfaef;color:#006505;border:1px solid #b7dfbd;';
            }

            echo '<tr>';

            echo '<td>';
            echo '<strong>' . esc_html($category->name) . '</strong><br>';
            echo '<code>ID ' . esc_html($category_id) . '</code><br>';
            echo '<a href="' . esc_url($edit_link) . '">Editar</a>';
            if ($term_link) {
                echo ' · <a href="' . esc_url($term_link) . '" target="_blank" rel="noopener">Ver</a>';
            }
            echo '</td>';

            echo '<td>';
            if (empty($hierarchy[$category_id])) {
                echo '<span style="color:#b32d2e;">Sin ruta SEO relacionada</span>';
            } else {
                foreach ($hierarchy[$category_id] as $path) {
                    echo '<div style="margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #eee;">';
                    $cluster_id = absint($path['cluster_id'] ?? 0);
                    $primary_id = absint($path['hub_primary_id'] ?? 0);
                    $secondary_id = absint($path['hub_secondary_id'] ?? 0);
                    echo '<strong>Cluster:</strong> ' . seo_category_inventory_post_link($cluster_id, $path['cluster_title'] ?? 'Sin cluster') . '<br>';
                    echo '<strong>Hub P.:</strong> ' . seo_category_inventory_post_link($primary_id, $path['hub_primary_title'] ?? 'Sin hub primario') . '<br>';
                    echo '<strong>Hub S.:</strong> ' . seo_category_inventory_post_link($secondary_id, $path['hub_secondary_title'] ?? 'Sin hub secundario');
                    echo '</div>';
                }
            }
            echo '</td>';

            echo '<td>';
            if (empty($vocabulary[$category_id])) {
                echo '<span style="color:#646970;">Sin Vocabulary asignado</span>';
            } else {
                foreach ($vocabulary[$category_id] as $group => $labels) {
                    $labels = array_values(array_unique(array_filter(array_map('trim', (array) $labels))));
                    echo '<div style="margin-bottom:6px;"><strong>' . esc_html($group ?: 'grupo') . ':</strong> ' . esc_html(implode(', ', $labels)) . '</div>';
                }
            }
            echo '</td>';

            echo '<td>';
            $faq_count = count($faqs[$category_id]);
            $faq_active = 0;
            foreach ($faqs[$category_id] as $faq_row) {
                if (absint($faq_row['active'] ?? 0) === 1) {
                    $faq_active++;
                }
            }
            echo '<strong>' . number_format_i18n($faq_active) . ' activas</strong> / ' . number_format_i18n($faq_count) . ' relacionadas';
            if ($faq_count) {
                echo '<details style="margin-top:6px;"><summary>Ver preguntas</summary><ul style="margin:6px 0 0 18px;">';
                foreach ($faqs[$category_id] as $faq_row) {
                    $question = trim((string) ($faq_row['question'] ?? ''));
                    $active = absint($faq_row['active'] ?? 0) === 1;
                    echo '<li>' . esc_html($question !== '' ? $question : ('FAQ #' . absint($faq_row['id'] ?? 0))) . ($active ? '' : ' <small>(inactiva)</small>') . '</li>';
                }
                echo '</ul></details>';
            }
            echo '</td>';

            echo '<td>';
            echo '<strong>' . number_format_i18n($published_posts) . ' publicados</strong> / ' . number_format_i18n(count($posts[$category_id])) . ' relacionados';
            if (!empty($posts[$category_id])) {
                echo '<ul style="margin:6px 0 0 18px;">';
                foreach ($posts[$category_id] as $row) {
                    echo '<li>' . seo_category_inventory_post_link($row['source_id'] ?? 0, $row['post_title'] ?? '', $row['post_status'] ?? '') . '</li>';
                }
                echo '</ul>';
            }
            echo '</td>';

            echo '<td>';
            echo '<strong>' . number_format_i18n($published_landings) . ' publicadas</strong> / ' . number_format_i18n(count($landings[$category_id])) . ' relacionadas';
            if (!empty($landings[$category_id])) {
                echo '<ul style="margin:6px 0 0 18px;">';
                foreach ($landings[$category_id] as $row) {
                    echo '<li>' . seo_category_inventory_post_link($row['source_id'] ?? 0, $row['post_title'] ?? '', $row['post_status'] ?? '') . '</li>';
                }
                echo '</ul>';
            }
            echo '</td>';

            echo '<td style="text-align:center;font-size:16px;"><strong>' . number_format_i18n(absint($category->count)) . '</strong></td>';
            echo '<td style="text-align:center;">';
            echo '<span style="display:inline-block;padding:5px 8px;border-radius:12px;' . esc_attr($load_style) . '"><strong>' . esc_html($url_load) . '</strong> · ' . esc_html($load_label) . '</span>';
            echo '<div style="font-size:10px;color:#646970;margin-top:4px;">posts + landings publicados</div>';
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody></table></div>';

        $total_pages = $per_page > 0 ? (int) ceil($total_categories / $per_page) : 1;
        if ($total_pages > 1) {
            $base_url = add_query_arg([
                'page' => sanitize_key($page_slug),
                'tab'  => 'inventario',
                'inventory_sort' => $inventory_sort,
                'inventory_page' => '%#%',
            ], admin_url('admin.php'));

            $pagination = paginate_links([
                'base'      => $base_url,
                'format'    => '',
                'current'   => $current_page,
                'total'     => $total_pages,
                'type'      => 'list',
                'prev_text' => '« Anterior',
                'next_text' => 'Siguiente »',
            ]);

            if ($pagination) {
                echo '<div class="tablenav"><div class="tablenav-pages" style="float:none;margin:18px 0;">' . $pagination . '</div></div>';
            }
        }

        echo '<p style="color:#646970;font-size:12px;margin-top:14px;">Carga URL: 0–2 baja, 3–5 media, 6 o más alta. Las FAQs se contabilizan aparte porque no crean una URL indexable independiente.</p>';
        echo '</div>';
    }
}



// =========================================================================
// TABLA DE INVENTARIO REAL CATEGORÍAS <-> PRODUCTOS
// =========================================================================

if (!function_exists('seo_category_catalog_path_label')) {
    /**
     * Construye una ruta legible de categoría usando un mapa ya cargado.
     * Evita consultas repetidas a WordPress en tablas grandes.
     *
     * @param int   $term_id
     * @param array $category_map [term_id => ['name' => ..., 'parent' => ...]]
     * @return string
     */
    function seo_category_catalog_path_label($term_id, array $category_map) {
        $term_id = absint($term_id);
        $parts = [];
        $seen = [];
        $current = $term_id;
        $guard = 0;

        while ($current > 0 && isset($category_map[$current]) && $guard < 30) {
            if (isset($seen[$current])) {
                break;
            }
            $seen[$current] = true;
            array_unshift($parts, (string) ($category_map[$current]['name'] ?? ('#' . $current)));
            $current = absint($category_map[$current]['parent'] ?? 0);
            $guard++;
        }

        return implode(' > ', array_filter($parts, 'strlen'));
    }
}


if (!function_exists('seo_category_catalog_export_json')) {
    /**
     * Exporta el inventario REAL categoria-producto de WooCommerce a JSON.
     * Respeta los filtros activos de "Tabla catalogo", pero ignora la paginacion
     * para incluir todas las categorias que coincidan.
     */
    function seo_category_catalog_export_json() {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para exportar el inventario de catalogo.');
        }

        check_admin_referer('seo_category_catalog_export_json');

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        global $wpdb;

        $terms_table         = $wpdb->terms;
        $tt_table            = $wpdb->term_taxonomy;
        $relationships_table = $wpdb->term_relationships;
        $posts_table         = $wpdb->posts;
        $postmeta_table      = $wpdb->postmeta;

        $catalog_q = isset($_GET['catalog_q'])
            ? sanitize_text_field(wp_unslash($_GET['catalog_q']))
            : '';
        $catalog_state = isset($_GET['catalog_state'])
            ? sanitize_key(wp_unslash($_GET['catalog_state']))
            : 'all';
        $catalog_sort = isset($_GET['catalog_sort'])
            ? sanitize_key(wp_unslash($_GET['catalog_sort']))
            : 'products_desc';

        if (!in_array($catalog_state, ['all', 'with_products', 'empty'], true)) {
            $catalog_state = 'all';
        }
        if (!in_array($catalog_sort, ['products_desc', 'products_asc', 'published_desc', 'name_asc', 'name_desc'], true)) {
            $catalog_sort = 'products_desc';
        }

        $valid_product_status_sql = "p.post_status NOT IN ('trash','auto-draft','inherit')";

        $total_categories = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$tt_table} WHERE taxonomy = 'product_cat'"
        );
        $total_products = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$posts_table} p
             WHERE p.post_type = 'product'
               AND {$valid_product_status_sql}"
        );
        $published_products = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$posts_table} p
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'"
        );
        $categorized_products = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$posts_table} p
             INNER JOIN {$relationships_table} tr ON tr.object_id = p.ID
             INNER JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE p.post_type = 'product'
               AND {$valid_product_status_sql}
               AND tt.taxonomy = 'product_cat'"
        );
        $category_product_assignments = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$relationships_table} tr
             INNER JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$posts_table} p ON p.ID = tr.object_id
             WHERE tt.taxonomy = 'product_cat'
               AND p.post_type = 'product'
               AND {$valid_product_status_sql}"
        );
        $categories_with_products = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT tt.term_id)
             FROM {$tt_table} tt
             INNER JOIN {$relationships_table} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$posts_table} p ON p.ID = tr.object_id
             WHERE tt.taxonomy = 'product_cat'
               AND p.post_type = 'product'
               AND {$valid_product_status_sql}"
        );
        $multi_category_products = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT p.ID
                FROM {$posts_table} p
                INNER JOIN {$relationships_table} tr ON tr.object_id = p.ID
                INNER JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE p.post_type = 'product'
                  AND {$valid_product_status_sql}
                  AND tt.taxonomy = 'product_cat'
                GROUP BY p.ID
                HAVING COUNT(DISTINCT tt.term_id) > 1
             ) seo_multi_category_products"
        );
        $counter_mismatches = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT tt.term_taxonomy_id,
                       tt.count AS woo_count,
                       COUNT(DISTINCT p.ID) AS real_published_count
                FROM {$tt_table} tt
                LEFT JOIN {$relationships_table} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                LEFT JOIN {$posts_table} p
                       ON p.ID = tr.object_id
                      AND p.post_type = 'product'
                      AND p.post_status = 'publish'
                WHERE tt.taxonomy = 'product_cat'
                GROUP BY tt.term_taxonomy_id, tt.count
                HAVING real_published_count <> woo_count
             ) seo_category_counter_mismatches"
        );

        $empty_categories = max(0, $total_categories - $categories_with_products);
        $uncategorized_products_count = max(0, $total_products - $categorized_products);
        $avg_categories_per_product = $categorized_products > 0
            ? round($category_product_assignments / $categorized_products, 2)
            : 0;

        $search_sql = '';
        $search_params = [];
        if ($catalog_q !== '') {
            $like = '%' . $wpdb->esc_like($catalog_q) . '%';
            $search_sql = " AND (
                t.name LIKE %s
                OR t.slug LIKE %s
                OR EXISTS (
                    SELECT 1
                    FROM {$relationships_table} tr_search
                    INNER JOIN {$posts_table} p_search ON p_search.ID = tr_search.object_id
                    LEFT JOIN {$postmeta_table} sku_search
                           ON sku_search.post_id = p_search.ID
                          AND sku_search.meta_key = '_sku'
                    WHERE tr_search.term_taxonomy_id = tt.term_taxonomy_id
                      AND p_search.post_type = 'product'
                      AND p_search.post_status NOT IN ('trash','auto-draft','inherit')
                      AND (p_search.post_title LIKE %s OR sku_search.meta_value LIKE %s)
                )
            )";
            $search_params = [$like, $like, $like, $like];
        }

        $summary_sql = "SELECT
                tt.term_id,
                tt.term_taxonomy_id,
                tt.parent,
                tt.count AS woo_count,
                t.name,
                t.slug,
                COUNT(DISTINCT p.ID) AS product_count,
                COUNT(DISTINCT CASE WHEN p.post_status = 'publish' THEN p.ID END) AS published_count,
                COUNT(DISTINCT CASE WHEN p.ID IS NOT NULL AND p.post_status <> 'publish' THEN p.ID END) AS nonpublished_count
            FROM {$tt_table} tt
            INNER JOIN {$terms_table} t ON t.term_id = tt.term_id
            LEFT JOIN {$relationships_table} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
            LEFT JOIN {$posts_table} p
                   ON p.ID = tr.object_id
                  AND p.post_type = 'product'
                  AND p.post_status NOT IN ('trash','auto-draft','inherit')
            WHERE tt.taxonomy = 'product_cat'
            {$search_sql}
            GROUP BY tt.term_id, tt.term_taxonomy_id, tt.parent, tt.count, t.name, t.slug";

        if ($catalog_state === 'with_products') {
            $summary_sql .= ' HAVING product_count > 0';
        } elseif ($catalog_state === 'empty') {
            $summary_sql .= ' HAVING product_count = 0';
        }

        switch ($catalog_sort) {
            case 'products_asc':
                $summary_sql .= ' ORDER BY product_count ASC, t.name ASC';
                break;
            case 'published_desc':
                $summary_sql .= ' ORDER BY published_count DESC, product_count DESC, t.name ASC';
                break;
            case 'name_desc':
                $summary_sql .= ' ORDER BY t.name DESC';
                break;
            case 'name_asc':
                $summary_sql .= ' ORDER BY t.name ASC';
                break;
            case 'products_desc':
            default:
                $summary_sql .= ' ORDER BY product_count DESC, t.name ASC';
                break;
        }

        $summary_rows = $search_params
            ? $wpdb->get_results($wpdb->prepare($summary_sql, ...$search_params), ARRAY_A)
            : $wpdb->get_results($summary_sql, ARRAY_A);
        $summary_rows = is_array($summary_rows) ? $summary_rows : [];

        // Mapa completo de categorias para construir rutas aunque el export este filtrado.
        $category_map = [];
        $all_terms_for_path = $wpdb->get_results(
            "SELECT tt.term_id, tt.parent, t.name
             FROM {$tt_table} tt
             INNER JOIN {$terms_table} t ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'product_cat'",
            ARRAY_A
        );
        foreach ((array) $all_terms_for_path as $path_row) {
            $category_map[absint($path_row['term_id'] ?? 0)] = [
                'name'   => (string) ($path_row['name'] ?? ''),
                'parent' => absint($path_row['parent'] ?? 0),
            ];
        }

        $uncategorized_rows = $wpdb->get_results(
            "SELECT p.ID AS product_id,
                    p.post_title,
                    p.post_name,
                    p.post_status,
                    MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) AS sku,
                    MAX(CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END) AS stock_status,
                    MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) AS stock_quantity
             FROM {$posts_table} p
             LEFT JOIN {$postmeta_table} pm
                    ON pm.post_id = p.ID
                   AND pm.meta_key IN ('_sku','_stock_status','_stock')
             WHERE p.post_type = 'product'
               AND {$valid_product_status_sql}
               AND NOT EXISTS (
                    SELECT 1
                    FROM {$relationships_table} tr_u
                    INNER JOIN {$tt_table} tt_u ON tt_u.term_taxonomy_id = tr_u.term_taxonomy_id
                    WHERE tr_u.object_id = p.ID
                      AND tt_u.taxonomy = 'product_cat'
               )
             GROUP BY p.ID, p.post_title, p.post_name, p.post_status
             ORDER BY p.post_title ASC",
            ARRAY_A
        );

        $charset = get_option('blog_charset') ?: 'UTF-8';
        $filename = 'inventario-real-categorias-productos-' . wp_date('Ymd-His') . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=' . $charset);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        $header = [
            'schema' => [
                'name' => 'seo-category-real-catalog-inventory',
                'version' => 1,
            ],
            'generated_at' => wp_date('c'),
            'site_url' => home_url('/'),
            'source_of_truth' => [
                'categories' => 'term_taxonomy taxonomy=product_cat',
                'assignments' => 'term_relationships + term_taxonomy + posts',
                'products' => "posts post_type=product excluding trash/auto-draft/inherit",
                'woo_counter' => 'term_taxonomy.count diagnostic only',
            ],
            'filters' => [
                'query' => $catalog_q,
                'state' => $catalog_state,
                'sort' => $catalog_sort,
                'pagination_applied' => false,
            ],
            'kpis' => [
                'categories_total' => $total_categories,
                'categories_with_products' => $categories_with_products,
                'categories_empty' => $empty_categories,
                'products_real' => $total_products,
                'products_published' => $published_products,
                'products_uncategorized' => $uncategorized_products_count,
                'category_product_assignments' => $category_product_assignments,
                'products_multi_category' => $multi_category_products,
                'avg_categories_per_categorized_product' => $avg_categories_per_product,
                'woo_counter_mismatches' => $counter_mismatches,
                'categories_matching_filters' => count($summary_rows),
            ],
        ];

        $header_json = wp_json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($header_json === false) {
            $header_json = '{}';
        }
        echo substr($header_json, 0, -1) . ',"categories":[';

        $batch_size = 50;
        $first_category = true;

        for ($offset = 0; $offset < count($summary_rows); $offset += $batch_size) {
            $batch_rows = array_slice($summary_rows, $offset, $batch_size);
            $batch_category_ids = array_values(array_filter(array_map(static function($row) {
                return absint($row['term_id'] ?? 0);
            }, $batch_rows)));

            $products_by_category = [];
            foreach ($batch_category_ids as $category_id) {
                $products_by_category[$category_id] = [];
            }

            if ($batch_category_ids) {
                $placeholders = implode(',', array_fill(0, count($batch_category_ids), '%d'));
                $product_sql = "SELECT
                        tt.term_id AS category_id,
                        p.ID AS product_id,
                        p.post_title,
                        p.post_name,
                        p.post_status,
                        MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) AS sku,
                        MAX(CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END) AS stock_status,
                        MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) AS stock_quantity
                    FROM {$tt_table} tt
                    INNER JOIN {$relationships_table} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    INNER JOIN {$posts_table} p
                            ON p.ID = tr.object_id
                           AND p.post_type = 'product'
                           AND p.post_status NOT IN ('trash','auto-draft','inherit')
                    LEFT JOIN {$postmeta_table} pm
                           ON pm.post_id = p.ID
                          AND pm.meta_key IN ('_sku','_stock_status','_stock')
                    WHERE tt.taxonomy = 'product_cat'
                      AND tt.term_id IN ({$placeholders})
                    GROUP BY tt.term_id, p.ID, p.post_title, p.post_name, p.post_status
                    ORDER BY tt.term_id ASC, p.post_title ASC";

                $product_rows = $wpdb->get_results(
                    $wpdb->prepare($product_sql, ...$batch_category_ids),
                    ARRAY_A
                );

                foreach ((array) $product_rows as $product_row) {
                    $category_id = absint($product_row['category_id'] ?? 0);
                    if (!isset($products_by_category[$category_id])) {
                        $products_by_category[$category_id] = [];
                    }
                    $products_by_category[$category_id][] = [
                        'id' => absint($product_row['product_id'] ?? 0),
                        'title' => (string) ($product_row['post_title'] ?? ''),
                        'slug' => (string) ($product_row['post_name'] ?? ''),
                        'sku' => (string) ($product_row['sku'] ?? ''),
                        'status' => (string) ($product_row['post_status'] ?? ''),
                        'stock_status' => (string) ($product_row['stock_status'] ?? ''),
                        'stock_quantity' => ($product_row['stock_quantity'] ?? '') === ''
                            ? null
                            : (string) $product_row['stock_quantity'],
                    ];
                }
            }

            foreach ($batch_rows as $row) {
                $category_id = absint($row['term_id'] ?? 0);
                $published_count = absint($row['published_count'] ?? 0);
                $woo_count = absint($row['woo_count'] ?? 0);
                $item = [
                    'id' => $category_id,
                    'term_taxonomy_id' => absint($row['term_taxonomy_id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'parent_id' => absint($row['parent'] ?? 0),
                    'path' => seo_category_catalog_path_label($category_id, $category_map),
                    'counts' => [
                        'real_products' => absint($row['product_count'] ?? 0),
                        'published_products' => $published_count,
                        'nonpublished_products' => absint($row['nonpublished_count'] ?? 0),
                        'woo_counter' => $woo_count,
                        'woo_counter_matches_published' => $woo_count === $published_count,
                    ],
                    'products' => $products_by_category[$category_id] ?? [],
                ];

                $json = wp_json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    continue;
                }
                if (!$first_category) {
                    echo ',';
                }
                echo $json;
                $first_category = false;
            }

            if (function_exists('flush')) {
                @flush();
            }
        }

        echo '],"uncategorized_products":';
        $uncategorized_export = [];
        foreach ((array) $uncategorized_rows as $product_row) {
            $uncategorized_export[] = [
                'id' => absint($product_row['product_id'] ?? 0),
                'title' => (string) ($product_row['post_title'] ?? ''),
                'slug' => (string) ($product_row['post_name'] ?? ''),
                'sku' => (string) ($product_row['sku'] ?? ''),
                'status' => (string) ($product_row['post_status'] ?? ''),
                'stock_status' => (string) ($product_row['stock_status'] ?? ''),
                'stock_quantity' => ($product_row['stock_quantity'] ?? '') === ''
                    ? null
                    : (string) $product_row['stock_quantity'],
            ];
        }
        $uncategorized_json = wp_json_encode($uncategorized_export, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo $uncategorized_json !== false ? $uncategorized_json : '[]';
        echo '}';
        exit;
    }
}
add_action('admin_post_seo_category_catalog_export_json', 'seo_category_catalog_export_json');


if (!function_exists('seo_category_catalog_table_render')) {
    /**
     * Tabla de control del inventario REAL de WooCommerce por relaciones
     * product_cat <-> product. No usa afinidad SEO ni inferencias semánticas.
     *
     * Fuente de verdad de la relación: wp_term_relationships +
     * wp_term_taxonomy + wp_posts.
     */
    function seo_category_catalog_table_render($page_slug = 'category-seo-admin') {
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>No tienes permisos para ver el inventario de catálogo.</p></div>';
            return;
        }

        global $wpdb;

        $terms_table         = $wpdb->terms;
        $tt_table            = $wpdb->term_taxonomy;
        $relationships_table = $wpdb->term_relationships;
        $posts_table         = $wpdb->posts;
        $postmeta_table      = $wpdb->postmeta;

        $catalog_q = isset($_GET['catalog_q'])
            ? sanitize_text_field(wp_unslash($_GET['catalog_q']))
            : '';
        $catalog_state = isset($_GET['catalog_state'])
            ? sanitize_key(wp_unslash($_GET['catalog_state']))
            : 'all';
        $catalog_sort = isset($_GET['catalog_sort'])
            ? sanitize_key(wp_unslash($_GET['catalog_sort']))
            : 'products_desc';
        $per_page = isset($_GET['catalog_per_page']) ? absint($_GET['catalog_per_page']) : 50;
        if (!in_array($per_page, [25, 50, 100], true)) {
            $per_page = 50;
        }
        $current_page = isset($_GET['catalog_page']) ? max(1, absint($_GET['catalog_page'])) : 1;

        if (!in_array($catalog_state, ['all', 'with_products', 'empty'], true)) {
            $catalog_state = 'all';
        }
        if (!in_array($catalog_sort, ['products_desc', 'products_asc', 'published_desc', 'name_asc', 'name_desc'], true)) {
            $catalog_sort = 'products_desc';
        }

        // -----------------------------------------------------------------
        // KPIs globales. Se calculan sobre productos reales, excluyendo
        // papelera, autodraft e inherit. Las categorías cuentan relaciones
        // directas product_cat -> product.
        // -----------------------------------------------------------------
        $valid_product_status_sql = "p.post_status NOT IN ('trash','auto-draft','inherit')";

        $total_categories = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$tt_table} WHERE taxonomy = 'product_cat'"
        );

        $total_products = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$posts_table} p
             WHERE p.post_type = 'product'
               AND {$valid_product_status_sql}"
        );

        $published_products = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$posts_table} p
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'"
        );

        $categorized_products = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$posts_table} p
             INNER JOIN {$relationships_table} tr ON tr.object_id = p.ID
             INNER JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE p.post_type = 'product'
               AND {$valid_product_status_sql}
               AND tt.taxonomy = 'product_cat'"
        );

        $category_product_assignments = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$relationships_table} tr
             INNER JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$posts_table} p ON p.ID = tr.object_id
             WHERE tt.taxonomy = 'product_cat'
               AND p.post_type = 'product'
               AND {$valid_product_status_sql}"
        );

        $categories_with_products = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT tt.term_id)
             FROM {$tt_table} tt
             INNER JOIN {$relationships_table} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$posts_table} p ON p.ID = tr.object_id
             WHERE tt.taxonomy = 'product_cat'
               AND p.post_type = 'product'
               AND {$valid_product_status_sql}"
        );

        $multi_category_products = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT p.ID
                FROM {$posts_table} p
                INNER JOIN {$relationships_table} tr ON tr.object_id = p.ID
                INNER JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE p.post_type = 'product'
                  AND {$valid_product_status_sql}
                  AND tt.taxonomy = 'product_cat'
                GROUP BY p.ID
                HAVING COUNT(DISTINCT tt.term_id) > 1
             ) seo_multi_category_products"
        );

        // Compara el contador nativo de term_taxonomy con relaciones PUBLICADAS.
        // Es diagnóstico: si hay diferencias podemos detectar contadores obsoletos.
        $counter_mismatches = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT tt.term_taxonomy_id,
                       tt.count AS woo_count,
                       COUNT(DISTINCT p.ID) AS real_published_count
                FROM {$tt_table} tt
                LEFT JOIN {$relationships_table} tr
                       ON tr.term_taxonomy_id = tt.term_taxonomy_id
                LEFT JOIN {$posts_table} p
                       ON p.ID = tr.object_id
                      AND p.post_type = 'product'
                      AND p.post_status = 'publish'
                WHERE tt.taxonomy = 'product_cat'
                GROUP BY tt.term_taxonomy_id, tt.count
                HAVING real_published_count <> woo_count
             ) seo_category_counter_mismatches"
        );

        $empty_categories = max(0, $total_categories - $categories_with_products);
        $uncategorized_products = max(0, $total_products - $categorized_products);
        $avg_categories_per_product = $categorized_products > 0
            ? round($category_product_assignments / $categorized_products, 2)
            : 0;

        // -----------------------------------------------------------------
        // Resumen real por categoría. No se usa WP_Term->count para decidir
        // profundidad: contamos directamente las relaciones existentes.
        // -----------------------------------------------------------------
        $search_sql = '';
        $search_params = [];
        if ($catalog_q !== '') {
            $like = '%' . $wpdb->esc_like($catalog_q) . '%';
            $search_sql = " AND (
                t.name LIKE %s
                OR t.slug LIKE %s
                OR EXISTS (
                    SELECT 1
                    FROM {$relationships_table} tr_search
                    INNER JOIN {$posts_table} p_search ON p_search.ID = tr_search.object_id
                    LEFT JOIN {$postmeta_table} sku_search
                           ON sku_search.post_id = p_search.ID
                          AND sku_search.meta_key = '_sku'
                    WHERE tr_search.term_taxonomy_id = tt.term_taxonomy_id
                      AND p_search.post_type = 'product'
                      AND p_search.post_status NOT IN ('trash','auto-draft','inherit')
                      AND (p_search.post_title LIKE %s OR sku_search.meta_value LIKE %s)
                )
            )";
            $search_params = [$like, $like, $like, $like];
        }

        $summary_sql = "SELECT
                tt.term_id,
                tt.term_taxonomy_id,
                tt.parent,
                tt.count AS woo_count,
                t.name,
                t.slug,
                COUNT(DISTINCT p.ID) AS product_count,
                COUNT(DISTINCT CASE WHEN p.post_status = 'publish' THEN p.ID END) AS published_count,
                COUNT(DISTINCT CASE WHEN p.ID IS NOT NULL AND p.post_status <> 'publish' THEN p.ID END) AS nonpublished_count
            FROM {$tt_table} tt
            INNER JOIN {$terms_table} t ON t.term_id = tt.term_id
            LEFT JOIN {$relationships_table} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
            LEFT JOIN {$posts_table} p
                   ON p.ID = tr.object_id
                  AND p.post_type = 'product'
                  AND p.post_status NOT IN ('trash','auto-draft','inherit')
            WHERE tt.taxonomy = 'product_cat'
            {$search_sql}
            GROUP BY tt.term_id, tt.term_taxonomy_id, tt.parent, tt.count, t.name, t.slug";

        if ($catalog_state === 'with_products') {
            $summary_sql .= ' HAVING product_count > 0';
        } elseif ($catalog_state === 'empty') {
            $summary_sql .= ' HAVING product_count = 0';
        }

        switch ($catalog_sort) {
            case 'products_asc':
                $summary_sql .= ' ORDER BY product_count ASC, t.name ASC';
                break;
            case 'published_desc':
                $summary_sql .= ' ORDER BY published_count DESC, product_count DESC, t.name ASC';
                break;
            case 'name_desc':
                $summary_sql .= ' ORDER BY t.name DESC';
                break;
            case 'name_asc':
                $summary_sql .= ' ORDER BY t.name ASC';
                break;
            case 'products_desc':
            default:
                $summary_sql .= ' ORDER BY product_count DESC, t.name ASC';
                break;
        }

        $summary_rows = $search_params
            ? $wpdb->get_results($wpdb->prepare($summary_sql, ...$search_params), ARRAY_A)
            : $wpdb->get_results($summary_sql, ARRAY_A);

        $summary_rows = is_array($summary_rows) ? $summary_rows : [];
        $filtered_categories = count($summary_rows);
        $total_pages = max(1, (int) ceil($filtered_categories / $per_page));
        if ($current_page > $total_pages) {
            $current_page = $total_pages;
        }
        $offset = ($current_page - 1) * $per_page;
        $page_rows = array_slice($summary_rows, $offset, $per_page);

        // Mapa completo para construir la ruta jerárquica sin nuevas consultas.
        $category_map = [];
        foreach ($summary_rows as $row) {
            $category_map[absint($row['term_id'] ?? 0)] = [
                'name'   => (string) ($row['name'] ?? ''),
                'parent' => absint($row['parent'] ?? 0),
            ];
        }
        // Si hay búsqueda, los padres pueden no estar en summary_rows.
        if ($catalog_q !== '' && $page_rows) {
            $all_terms_for_path = $wpdb->get_results(
                "SELECT tt.term_id, tt.parent, t.name
                 FROM {$tt_table} tt
                 INNER JOIN {$terms_table} t ON t.term_id = tt.term_id
                 WHERE tt.taxonomy = 'product_cat'",
                ARRAY_A
            );
            foreach ((array) $all_terms_for_path as $path_row) {
                $category_map[absint($path_row['term_id'] ?? 0)] = [
                    'name'   => (string) ($path_row['name'] ?? ''),
                    'parent' => absint($path_row['parent'] ?? 0),
                ];
            }
        }

        // Productos de las categorías de esta página.
        $products_by_category = [];
        $page_category_ids = array_values(array_filter(array_map(static function($row) {
            return absint($row['term_id'] ?? 0);
        }, $page_rows)));

        foreach ($page_category_ids as $category_id) {
            $products_by_category[$category_id] = [];
        }

        if ($page_category_ids) {
            $placeholders = implode(',', array_fill(0, count($page_category_ids), '%d'));
            $product_sql = "SELECT
                    tt.term_id AS category_id,
                    p.ID AS product_id,
                    p.post_title,
                    p.post_status,
                    MAX(CASE WHEN pm.meta_key = '_sku' THEN pm.meta_value END) AS sku,
                    MAX(CASE WHEN pm.meta_key = '_stock_status' THEN pm.meta_value END) AS stock_status
                FROM {$tt_table} tt
                INNER JOIN {$relationships_table} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$posts_table} p
                        ON p.ID = tr.object_id
                       AND p.post_type = 'product'
                       AND p.post_status NOT IN ('trash','auto-draft','inherit')
                LEFT JOIN {$postmeta_table} pm
                       ON pm.post_id = p.ID
                      AND pm.meta_key IN ('_sku','_stock_status')
                WHERE tt.taxonomy = 'product_cat'
                  AND tt.term_id IN ({$placeholders})
                GROUP BY tt.term_id, p.ID, p.post_title, p.post_status
                ORDER BY tt.term_id ASC, p.post_title ASC";

            $product_rows = $wpdb->get_results(
                $wpdb->prepare($product_sql, ...$page_category_ids),
                ARRAY_A
            );

            foreach ((array) $product_rows as $product_row) {
                $category_id = absint($product_row['category_id'] ?? 0);
                if (!isset($products_by_category[$category_id])) {
                    $products_by_category[$category_id] = [];
                }
                $products_by_category[$category_id][] = $product_row;
            }
        }

        echo '<div style="max-width:100%;">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
        echo '<h2 style="margin:0;">Inventario real categorías ↔ productos</h2>';
        $catalog_export_url = wp_nonce_url(
            add_query_arg([
                'action' => 'seo_category_catalog_export_json',
                'catalog_q' => $catalog_q,
                'catalog_state' => $catalog_state,
                'catalog_sort' => $catalog_sort,
            ], admin_url('admin-post.php')),
            'seo_category_catalog_export_json'
        );
        echo '<a class="button button-primary" href="' . esc_url($catalog_export_url) . '">Descargar JSON</a>';
        echo '</div>';
        echo '<p style="max-width:1180px;">Esta vista cuenta las asignaciones reales de WooCommerce leyendo <code>term_relationships</code> + <code>term_taxonomy</code> + <code>posts</code>. No usa afinidad SEO, Vocabulary ni el contador de productos del informe editorial para decidir cuántos productos tiene una categoría. El contador Woo/WordPress se muestra aparte solo como diagnóstico.</p>';
        echo '<p style="max-width:1180px;color:#646970;"><strong>JSON:</strong> exporta todos los resultados que coinciden con los filtros actuales, sin paginación, incluyendo KPIs, categorías, ruta jerárquica y los productos reales asignados (ID, título, slug, SKU, estado y stock).</p>';

        // KPIs.
        $kpis = [
            ['label' => 'Categorías', 'value' => $total_categories, 'hint' => 'product_cat totales'],
            ['label' => 'Con productos', 'value' => $categories_with_products, 'hint' => 'relación real directa'],
            ['label' => 'Vacías', 'value' => $empty_categories, 'hint' => 'sin producto directo'],
            ['label' => 'Productos reales', 'value' => $total_products, 'hint' => 'sin papelera/autodraft'],
            ['label' => 'Publicados', 'value' => $published_products, 'hint' => 'post_status=publish'],
            ['label' => 'Sin categoría', 'value' => $uncategorized_products, 'hint' => 'sin ninguna product_cat'],
            ['label' => 'Asignaciones', 'value' => $category_product_assignments, 'hint' => 'categoría-producto'],
            ['label' => 'Multicategoría', 'value' => $multi_category_products, 'hint' => 'producto en >1 categoría'],
            ['label' => 'Categorías/prod.', 'value' => $avg_categories_per_product, 'hint' => 'media sobre categorizados'],
            ['label' => 'Descuadres Woo', 'value' => $counter_mismatches, 'hint' => 'contador vs publicados reales'],
        ];

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:16px 0 18px;max-width:1500px;">';
        foreach ($kpis as $kpi) {
            echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;min-height:72px;box-sizing:border-box;">';
            echo '<div style="font-size:12px;color:#646970;margin-bottom:4px;">' . esc_html($kpi['label']) . '</div>';
            echo '<div style="font-size:24px;line-height:1.1;font-weight:700;color:#1d2327;">' . esc_html(number_format_i18n($kpi['value'], is_float($kpi['value']) ? 2 : 0)) . '</div>';
            echo '<div style="font-size:10px;color:#8c8f94;margin-top:5px;">' . esc_html($kpi['hint']) . '</div>';
            echo '</div>';
        }
        echo '</div>';

        echo '<form method="get" style="display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin:0 0 18px;padding:12px;background:#fff;border:1px solid #dcdcde;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page_slug) . '">';
        echo '<input type="hidden" name="tab" value="tabla_catalogo">';
        echo '<div><label for="seo_catalog_q"><strong>Buscar categoría / producto / SKU</strong></label><br>';
        echo '<input id="seo_catalog_q" type="search" name="catalog_q" value="' . esc_attr($catalog_q) . '" style="min-width:280px;" placeholder="Ej.: diamante, VEVOR, SKU..."></div>';
        echo '<div><label for="seo_catalog_state"><strong>Estado</strong></label><br>';
        echo '<select id="seo_catalog_state" name="catalog_state">';
        echo '<option value="all" ' . selected($catalog_state, 'all', false) . '>Todas</option>';
        echo '<option value="with_products" ' . selected($catalog_state, 'with_products', false) . '>Con productos</option>';
        echo '<option value="empty" ' . selected($catalog_state, 'empty', false) . '>Vacías</option>';
        echo '</select></div>';
        echo '<div><label for="seo_catalog_sort"><strong>Orden</strong></label><br>';
        echo '<select id="seo_catalog_sort" name="catalog_sort">';
        echo '<option value="products_desc" ' . selected($catalog_sort, 'products_desc', false) . '>Más productos primero</option>';
        echo '<option value="products_asc" ' . selected($catalog_sort, 'products_asc', false) . '>Menos productos primero</option>';
        echo '<option value="published_desc" ' . selected($catalog_sort, 'published_desc', false) . '>Más publicados primero</option>';
        echo '<option value="name_asc" ' . selected($catalog_sort, 'name_asc', false) . '>Nombre A–Z</option>';
        echo '<option value="name_desc" ' . selected($catalog_sort, 'name_desc', false) . '>Nombre Z–A</option>';
        echo '</select></div>';
        echo '<div><label for="seo_catalog_per_page"><strong>Por página</strong></label><br>';
        echo '<select id="seo_catalog_per_page" name="catalog_per_page">';
        foreach ([25, 50, 100] as $size) {
            echo '<option value="' . esc_attr($size) . '" ' . selected($per_page, $size, false) . '>' . esc_html($size) . '</option>';
        }
        echo '</select></div>';
        echo '<div><button type="submit" class="button button-primary">Aplicar</button> ';
        $reset_url = add_query_arg(['page' => $page_slug, 'tab' => 'tabla_catalogo'], admin_url('admin.php'));
        echo '<a class="button" href="' . esc_url($reset_url) . '">Limpiar</a></div>';
        echo '</form>';

        $from = $filtered_categories > 0 ? ($offset + 1) : 0;
        $to = min($offset + count($page_rows), $filtered_categories);
        echo '<p style="color:#646970;margin:0 0 10px;">Mostrando categorías <strong>' . number_format_i18n($from) . '–' . number_format_i18n($to) . '</strong> de <strong>' . number_format_i18n($filtered_categories) . '</strong> según los filtros actuales.</p>';

        if (!$page_rows) {
            echo '<div class="notice notice-info inline"><p>No hay categorías que coincidan con los filtros.</p></div></div>';
            return;
        }

        echo '<div style="overflow-x:auto;background:#fff;border:1px solid #dcdcde;">';
        echo '<table class="widefat striped" style="min-width:1250px;">';
        echo '<thead><tr>';
        echo '<th style="width:240px;">Categoría</th>';
        echo '<th style="width:310px;">Ruta</th>';
        echo '<th style="width:105px;text-align:center;">Reales</th>';
        echo '<th style="width:105px;text-align:center;">Publicados</th>';
        echo '<th style="width:105px;text-align:center;">No publicados</th>';
        echo '<th style="width:105px;text-align:center;">Contador Woo</th>';
        echo '<th>Productos asignados directamente</th>';
        echo '</tr></thead><tbody>';

        $display_product_limit = 100;

        foreach ($page_rows as $row) {
            $category_id = absint($row['term_id'] ?? 0);
            $category_name = (string) ($row['name'] ?? '');
            $product_count = absint($row['product_count'] ?? 0);
            $published_count = absint($row['published_count'] ?? 0);
            $nonpublished_count = absint($row['nonpublished_count'] ?? 0);
            $woo_count = absint($row['woo_count'] ?? 0);
            $path = seo_category_catalog_path_label($category_id, $category_map);
            $products = $products_by_category[$category_id] ?? [];

            $edit_term_url = add_query_arg([
                'taxonomy' => 'product_cat',
                'tag_ID' => $category_id,
                'post_type' => 'product',
                'action' => 'edit',
            ], admin_url('term.php'));
            $plugin_editor_url = seo_get_category_editor_url($category_id, $page_slug);
            $products_admin_url = add_query_arg([
                'page' => 'product-page-admin',
                'cat'  => $category_id,
            ], admin_url('admin.php'));

            $term_obj = get_term($category_id, 'product_cat');
            $public_url = ($term_obj && !is_wp_error($term_obj)) ? get_term_link($term_obj) : '';
            if (is_wp_error($public_url)) {
                $public_url = '';
            }

            $row_style = $product_count === 0 ? 'background:#fff8f8;' : '';
            echo '<tr style="' . esc_attr($row_style) . '">';
            echo '<td><strong>' . esc_html($category_name) . '</strong><br>';
            echo '<code>ID ' . esc_html($category_id) . '</code> · <code>' . esc_html((string) ($row['slug'] ?? '')) . '</code><br>';
            echo '<a href="' . esc_url($plugin_editor_url) . '">Editor SEO</a> · ';
            echo '<a href="' . esc_url($edit_term_url) . '">Editar WC</a>';
            if ($public_url) {
                echo ' · <a href="' . esc_url($public_url) . '" target="_blank" rel="noopener">Ver</a>';
            }
            echo '</td>';

            echo '<td>' . esc_html($path !== '' ? $path : $category_name) . '</td>';
            echo '<td style="text-align:center;font-size:18px;"><strong>' . number_format_i18n($product_count) . '</strong></td>';
            echo '<td style="text-align:center;"><strong>' . number_format_i18n($published_count) . '</strong></td>';
            echo '<td style="text-align:center;">' . number_format_i18n($nonpublished_count) . '</td>';

            $counter_style = $woo_count === $published_count
                ? 'color:#006505;'
                : 'color:#b32d2e;font-weight:700;';
            echo '<td style="text-align:center;' . esc_attr($counter_style) . '">' . number_format_i18n($woo_count) . '</td>';

            echo '<td>';
            if ($product_count === 0) {
                echo '<span style="color:#b32d2e;font-weight:600;">Categoría vacía</span>';
            } else {
                echo '<details>';
                echo '<summary style="cursor:pointer;"><strong>Ver productos (' . number_format_i18n($product_count) . ')</strong></summary>';
                echo '<div style="margin-top:8px;max-height:420px;overflow:auto;border-left:3px solid #dcdcde;padding-left:10px;">';
                echo '<ol style="margin:0 0 0 18px;">';
                $shown = 0;
                foreach ($products as $product) {
                    if ($shown >= $display_product_limit) {
                        break;
                    }
                    $product_id = absint($product['product_id'] ?? 0);
                    $title = trim((string) ($product['post_title'] ?? ''));
                    $sku = trim((string) ($product['sku'] ?? ''));
                    $status = sanitize_key((string) ($product['post_status'] ?? ''));
                    $stock_status = sanitize_key((string) ($product['stock_status'] ?? ''));
                    $product_edit_url = add_query_arg(['post' => $product_id, 'action' => 'edit'], admin_url('post.php'));
                    echo '<li style="margin-bottom:5px;">';
                    echo '<a href="' . esc_url($product_edit_url) . '">' . esc_html($title !== '' ? $title : ('Producto #' . $product_id)) . '</a>';
                    echo ' <code>#' . esc_html($product_id) . '</code>';
                    if ($sku !== '') {
                        echo ' · SKU <code>' . esc_html($sku) . '</code>';
                    }
                    if ($status !== 'publish') {
                        echo ' · <small style="color:#996800;">' . esc_html($status) . '</small>';
                    }
                    if ($stock_status !== '') {
                        echo ' · <small style="color:#646970;">stock: ' . esc_html($stock_status) . '</small>';
                    }
                    echo '</li>';
                    $shown++;
                }
                echo '</ol>';
                if ($product_count > $shown) {
                    echo '<p style="margin:8px 0 0;color:#646970;"><strong>Mostrando ' . number_format_i18n($shown) . ' de ' . number_format_i18n($product_count) . '.</strong> La cuenta superior sigue siendo completa.</p>';
                }
                echo '</div>';
                echo '</details>';
            }
            echo '<div style="margin-top:8px;"><a class="button button-small" href="' . esc_url($products_admin_url) . '">Abrir listado de productos</a></div>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        if ($total_pages > 1) {
            $base_args = [
                'page' => sanitize_key($page_slug),
                'tab' => 'tabla_catalogo',
                'catalog_q' => $catalog_q,
                'catalog_state' => $catalog_state,
                'catalog_sort' => $catalog_sort,
                'catalog_per_page' => $per_page,
                'catalog_page' => '%#%',
            ];
            $base_url = add_query_arg($base_args, admin_url('admin.php'));
            $pagination = paginate_links([
                'base'      => $base_url,
                'format'    => '',
                'current'   => $current_page,
                'total'     => $total_pages,
                'type'      => 'list',
                'prev_text' => '« Anterior',
                'next_text' => 'Siguiente »',
            ]);
            if ($pagination) {
                echo '<div class="tablenav"><div class="tablenav-pages" style="float:none;margin:18px 0;">' . $pagination . '</div></div>';
            }
        }

        echo '<p style="color:#646970;font-size:12px;margin-top:14px;"><strong>Reales</strong> = productos enlazados directamente a esa <code>product_cat</code>, excluyendo papelera/autodraft/inherit. <strong>Contador Woo</strong> = <code>term_taxonomy.count</code>. Si no coincide con “Publicados”, la fila queda marcada en rojo en ese contador para poder localizar contadores desactualizados o diferencias de recuento.</p>';
        echo '</div>';
    }
}


// =========================================================================
// FUNCIÓN PRINCIPAL DEL PANEL DE ADMINISTRACIÓN
// =========================================================================
function seo_category_admin_callback($requested_term_id = 0) {


$page_slug  = isset($_GET['page'])
    ? sanitize_key($_GET['page'])
    : 'category-seo-admin';
$active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'categorias';
$requested_term_id = absint($requested_term_id);

if (!$requested_term_id && isset($_GET['edit_category_id'])) {
    $requested_term_id = absint($_GET['edit_category_id']);
}

$single_category_mode = $requested_term_id > 0;

if ($single_category_mode) {
    $requested_term = get_term($requested_term_id, 'product_cat');

    if (!$requested_term || is_wp_error($requested_term)) {
        wp_die('La categoría solicitada no existe.');
    }

    $active_tab = 'categorias';
}


if (!in_array($active_tab,
[
    'categorias',
    'estructura',
    'reasignar_categorias',
    'informes',
    'inventario',
    'tabla_catalogo'
], true)) {

    $active_tab = 'categorias';
}



// URL PESTAÑA CATEGORÍAS
$url_categorias = add_query_arg(
    [
        'page' => $page_slug,
        'tab'  => 'categorias',
    ],
    admin_url('admin.php')
);

// URL PESTAÑA INFORME / ESTRUCTURA DE CATEGORÍAS
$url_estructura = add_query_arg(
    [
        'page' => $page_slug,
        'tab'  => 'estructura',
    ],
    admin_url('admin.php')
);

// URL PESTAÑA REASIGNACIÓN DE CATEGORÍAS
$url_reasignar_categorias = add_query_arg(
    [
        'page' => $page_slug,
        'tab'  => 'reasignar_categorias',
    ],
    admin_url('admin.php')
);


// URL PESTANA INFORMES GOOGLE
$url_informes = add_query_arg(
    [
        'page' => $page_slug,
        'tab'  => 'informes',
    ],
    admin_url('admin.php')
);

// URL PESTAÑA INVENTARIO EDITORIAL POR CATEGORÍA
$url_inventario = add_query_arg(
    [
        'page' => $page_slug,
        'tab'  => 'inventario',
    ],
    admin_url('admin.php')
);

// URL PESTAÑA TABLA DE INVENTARIO REAL CATEGORÍAS-PRODUCTOS
$url_tabla_catalogo = add_query_arg(
    [
        'page' => $page_slug,
        'tab'  => 'tabla_catalogo',
    ],
    admin_url('admin.php')
);

echo '<div class="wrap">';

echo '<h1 style="margin-bottom:15px;">Categorías SEO</h1>';


if (!$single_category_mode) {
echo '<h2 class="nav-tab-wrapper" style="margin-bottom:20px;">';

    echo '<a href="' . esc_url($url_categorias) . '" class="nav-tab ' . ($active_tab === 'categorias' ? 'nav-tab-active' : '') . '">Categorías</a>';

    echo '<a href="' . esc_url($url_estructura) . '" class="nav-tab ' . ($active_tab === 'estructura' ? 'nav-tab-active' : '') . '">Informe categorías</a>';

    echo '<a href="' . esc_url($url_reasignar_categorias) . '" class="nav-tab ' . ($active_tab === 'reasignar_categorias' ? 'nav-tab-active' : '') . '">Reasignación de Categorías</a>';

    echo '<a href="' . esc_url($url_informes) . '" class="nav-tab ' . ($active_tab === 'informes' ? 'nav-tab-active' : '') . '">Informes Google</a>';

    echo '<a href="' . esc_url($url_inventario) . '" class="nav-tab ' . ($active_tab === 'inventario' ? 'nav-tab-active' : '') . '">Inventario</a>';

    echo '<a href="' . esc_url($url_tabla_catalogo) . '" class="nav-tab ' . ($active_tab === 'tabla_catalogo' ? 'nav-tab-active' : '') . '">Tabla catálogo</a>';



echo '</h2>';
}

// =========================
// PESTAÑA TABLA DE INVENTARIO REAL CATEGORÍAS-PRODUCTOS
// =========================
if ($active_tab === 'tabla_catalogo') {
    seo_category_catalog_table_render($page_slug);
    echo '</div>';
    return;
}

// =========================
// PESTAÑA INVENTARIO EDITORIAL POR CATEGORÍA
// Se resuelve aquí y hace return antes del editor pesado.
// =========================
if ($active_tab === 'inventario') {
    seo_category_inventory_render($page_slug);
    echo '</div>';
    return;
}

// =========================
// PESTAÑA INFORME / ESTRUCTURA DE CATEGORÍAS
// =========================
if ($active_tab === 'estructura') {

    if (function_exists('seo_render_total_structure_report')) {
        seo_render_total_structure_report();
    } else {
        echo '<div class="notice notice-error"><p>No esta disponible el informe de estructura de categorias.</p></div>';
    }

    echo '</div>';
    return;
}

// =========================
// PESTANA INFORMES GOOGLE
// =========================
if ($active_tab === 'informes') {

    if (function_exists('seo_category_reports_page')) {
        seo_category_reports_page($page_slug);
    } else {
        echo '<div class="notice notice-error"><p>No esta disponible el modulo de informes Google por categoria.</p></div>';
    }

    echo '</div>';
    return;
}

if ($active_tab === 'reasignar_categorias') {

    if (function_exists('seo_render_reasignacion_ui')) {
        seo_render_reasignacion_ui([]);
    } else {
        echo '<p>Función seo_render_reasignacion_ui() no encontrada</p>';
    }

    echo '</div>';
    return;
}
    if (!current_user_can('manage_options')) return;

    global $wpdb;
    $tabla_relations = $wpdb->prefix . 'seo_relations';

    // Guardado estándar mediante la API modular.
    if (isset($_POST['action']) && $_POST['action'] === 'update_seo_categories') {
        if (
            empty($_POST['seo_category_editor_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST['seo_category_editor_nonce'])),
                'seo_save_category_editor'
            )
        ) {
            echo '<div class="notice notice-error"><p>La sesión ha caducado. Recarga la página antes de guardar.</p></div>';
            return;
        }

        $cat_names        = $_POST['cat_name'] ?? [];
        $cat_excerpts     = $_POST['cat_excerpt'] ?? [];
        $cat_descriptions = $_POST['cat_description'] ?? [];
        $cat_tags         = $_POST['cat_tags'] ?? [];
        $save_errors      = [];
        $saved_count      = 0;

        foreach ($cat_names as $term_id => $name) {
            $term_id = absint($term_id);

            if ($single_category_mode && $term_id !== $requested_term_id) {
                continue;
            }

            $result = seo_save_category_editor_data($term_id, [
                'name'        => $name,
                'excerpt'     => $cat_excerpts[$term_id] ?? '',
                'description' => $cat_descriptions[$term_id] ?? '',
                'keywords'    => $cat_tags[$term_id] ?? '',
            ]);

            if (is_wp_error($result)) {
                $save_errors[] = $result->get_error_message();
            } else {
                $saved_count++;
            }
        }

        if ($saved_count > 0) {
            echo '<div class="notice notice-success"><p>Categorías actualizadas correctamente: ' .
                intval($saved_count) .
                '</p></div>';
        }

        foreach ($save_errors as $save_error) {
            echo '<div class="notice notice-error"><p>' .
                esc_html($save_error) .
                '</p></div>';
        }
    }

    // 1. Clusters Únicos (Nivel 1)
    $clusters_sistema = $wpdb->get_results("
        SELECT DISTINCT r.source_id as id, p.post_title as nombre 
        FROM $tabla_relations r
        INNER JOIN {$wpdb->prefix}posts p ON r.source_id = p.ID
        WHERE r.source_type = 'cluster' AND p.post_status = 'publish'
        ORDER BY p.post_title ASC
    ");

    // 2. Mapeo General de Relaciones
    $mapeo_completo = $wpdb->get_results("
        SELECT r.source_id, r.source_type, r.target_id, r.target_type, r.relation_type, p.post_title as target_title
        FROM $tabla_relations r
        INNER JOIN {$wpdb->prefix}posts p ON r.target_id = p.ID
        WHERE p.post_status = 'publish'
    ");

    // 3. Relaciones Nivel 3 -> Nivel 4 (Hub Secundario a Categorías)
    $relaciones_categorias = $wpdb->get_results("
        SELECT r.source_id as hub_secundario_id, r.target_id as cat_term_id, t.name as cat_nombre
        FROM $tabla_relations r
        INNER JOIN {$wpdb->prefix}terms t ON r.target_id = t.term_id
        WHERE r.relation_type = 'hub_secondary_to_category' 
          AND r.target_type = 'product_cat'
    ");

    // 4. Precalcular URLs absolutas
    $urls_precalculadas = [];
    $post_ids_unicos = $wpdb->get_col("SELECT DISTINCT target_id FROM $tabla_relations UNION SELECT DISTINCT source_id FROM $tabla_relations");
    if (!empty($post_ids_unicos)) {
        foreach ($post_ids_unicos as $p_id) {
            $p_id = intval($p_id);
            if ($p_id > 0) {
                $urls_precalculadas['post_' . $p_id] = get_permalink($p_id);
            }
        }
    }

    $all_categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    if (!is_wp_error($all_categories) && !empty($all_categories)) {
        foreach ($all_categories as $cat) {
            $urls_precalculadas['cat_' . $cat->term_id] = get_term_link($cat);
        }
    }

    $categories_args = [
        'taxonomy'   => 'product_cat',
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC'
    ];

    if ($single_category_mode) {
        $categories_args['include'] = [$requested_term_id];
    }

    $categories_list = get_terms($categories_args);
    

?>

<div style="padding:20px; max-width:100%; font-family:sans-serif;">

<div style="padding:20px; max-width:100%; font-family:sans-serif;">


<?php if ($single_category_mode): ?>
    <div style="background:#fff; border:1px solid #ccd0d4; padding:15px 18px; border-radius:8px; margin-bottom:20px;">
        <h2 style="margin:0 0 10px;">
            Editar categoría: <?php echo esc_html($requested_term->name); ?>
        </h2>
        <a class="button button-secondary" href="<?php echo esc_url($url_categorias); ?>">
            ← Volver a todas las categorías
        </a>
    </div>
<?php else: ?>
<div style="background:#e8f4fd;border-left:6px solid #0078d4;padding:15px;margin:15px 0;">

<h2>📂 Guía para crear nuevas categorías SEO</h2>
<details>
    <summary><strong>ormas para la creación de categorías y contenidos SEO.</strong></summary>

    <br>

    <p><strong>Objetivo:</strong> Cada categoría debe ayudar al usuario a entender qué productos contiene, para qué sirven, cuándo utilizarlos y qué variantes puede encontrar.</p>

    <p><strong>Excerpt:</strong> 35 a 50 palabras.</p>

    <p><strong>Descripción:</strong> 400 a 700 palabras.</p>

    <p><strong>Estructura obligatoria:</strong></p>

    <ul>
        <li>Características de la categoría</li>
        <li>Ventajas</li>
        <li>Aplicaciones</li>
        <li>Tipos de productos incluidos</li>
    </ul>
    
    1. ¿Qué es esta categoría?

Define qué engloba.

La categoría de llaves dinamométricas reúne herramientas diseñadas para aplicar un par de apriete controlado...

2. ¿Para qué sirven?

Explica la función.

Se utilizan cuando es necesario apretar tornillos con un par específico...

3. ¿Quién utiliza estos productos?

Da contexto profesional.

Son habituales en talleres mecánicos, mantenimiento industrial, aeronáutica...

4. ¿Qué tipos de productos incluye?

Explica las variantes.

Dentro de esta categoría existen modelos de disparo, digitales, de cuadradillo...

5. ¿Cómo elegir el producto adecuado?

Esta sección es muy potente para SEO.

La elección depende del rango de par, precisión, frecuencia de uso...

6. Aplicaciones habituales

Casos reales de uso.

7. Productos o categorías relacionadas

Genera contexto semántico.

8. Preguntas frecuentes

Cinco o seis FAQs.

</details>

</div>



    <?php
    // ============================================
    // CREAR NUEVA CATEGORÍA SEO
    // ============================================
    if (isset($_POST['action']) && $_POST['action'] === 'create_seo_category') {
    
        $nombre       = sanitize_text_field($_POST['new_cat_name'] ?? '');
        $excerpt      = wp_kses_post($_POST['new_cat_excerpt'] ?? '');
        $descripcion  = wp_kses_post($_POST['new_cat_description'] ?? '');
        $tags         = sanitize_textarea_field($_POST['new_cat_tags'] ?? '');
        $ambito       = sanitize_text_field($_POST['new_cat_ambito'] ?? '');

        if (!empty($nombre)) {
    
            $parent_id = isset($_POST['new_cat_parent'])
                ? absint($_POST['new_cat_parent'])
                : 0;
            
            $nueva_cat = wp_insert_term(
                $nombre,
                'product_cat',
                [
                    'parent' => $parent_id
                ]
            );
    
            if (!is_wp_error($nueva_cat)) {
    
                $term_id = $nueva_cat['term_id'];
    
                // Guardar excerpt
                    if (!empty($excerpt)) {
                    
                        $wpdb->insert(
                            "{$wpdb->prefix}seo_nodes",
                            [
                                'object_type' => 'category',
                                'object_id'   => $term_id,
                                'seo_role'    => 'excerpt',
                                'keywords'    => $excerpt,
                                'status'      => 1,
                                'created_at'  => current_time('mysql'),
                                'updated_at'  => current_time('mysql')
                            ],
                            ['%s','%d','%s','%s','%d','%s','%s']
                        );
                    
                    }
    
                // Guardar etiquetas SEO en tabla personalizada
                global $wpdb;
    
                // Guardar description SEO en wp_seo_nodes
                if (!empty($descripcion)) {
                
                    $wpdb->insert(
                        "{$wpdb->prefix}seo_nodes",
                        [
                            'object_type' => 'category',
                            'object_id'   => $term_id,
                            'seo_role'    => 'description',
                            'keywords'    => $descripcion,
                            'status'      => 1,
                            'created_at'  => current_time('mysql'),
                            'updated_at'  => current_time('mysql')
                        ],
                        ['%s', '%d', '%s', '%s', '%d', '%s', '%s']
                    );
                
                }
                
                // Guardar ámbito
                if (!empty($ambito)) {
                
                    $wpdb->insert(
                        "{$wpdb->prefix}seo_nodes",
                        [
                            'object_type' => 'category',
                            'object_id'   => $term_id,
                            'seo_role'    => 'ambito',
                            'keywords'    => $ambito,
                            'status'      => 1,
                            'created_at'  => current_time('mysql'),
                            'updated_at'  => current_time('mysql')
                        ],
                        ['%s', '%d', '%s', '%s', '%d', '%s', '%s']
                    );
                
                }
                
                
                // Guardar etiquetas SEO en wp_seo_nodes
                if (!empty($tags)) {
                
                    $wpdb->insert(
                        "{$wpdb->prefix}seo_nodes",
                        [
                            'object_type' => 'category',
                            'object_id'   => $term_id,
                            'seo_role'    => 'category',
                            'keywords'    => $tags,
                            'status'      => 1,
                            'created_at'  => current_time('mysql'),
                            'updated_at'  => current_time('mysql')
                        ],
                        ['%s', '%d', '%s', '%s', '%d', '%s', '%s']
                    );
                
                }
    
                echo '<div class="notice notice-success"><p>Categoría creada correctamente</p></div>';
    
            } else {
                echo '<div class="notice notice-error"><p>Error: ' . $nueva_cat->get_error_message() . '</p></div>';
            }
    
        } else {
            echo '<div class="notice notice-error"><p>El nombre es obligatorio</p></div>';
        }
    }
    ?>
    
    
    <?php
    //Toma valor del ambito disponible de tabla wp_eo_nodes
    $ambitos_disponibles = $wpdb->get_col("
        SELECT DISTINCT keywords
        FROM {$wpdb->prefix}seo_nodes
        WHERE seo_role = 'ambito'
        ORDER BY keywords ASC
    ");
    ?>

    <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; margin-bottom:30px;">
        
        <h2 style="margin-top:0;">➕ Crear nueva categoría</h2>
    
        <form method="post">
            <input type="hidden" name="action" value="create_seo_category">
    
            <div style="margin-bottom:10px;">
                <strong>Nombre:</strong>
                <input type="text" name="new_cat_name" style="width:100%; padding:6px;">
            </div>
    
            <div style="margin-bottom:10px;">
                <strong>Excerpt SEO:</strong>
                <textarea name="new_cat_excerpt" style="width:100%; min-height:60px;"></textarea>
            </div>
    
            <div style="margin-bottom:10px;">
                <strong>Descripción:</strong>
                <textarea name="new_cat_description" style="width:100%; min-height:100px;"></textarea>
            </div>
    
            <div style="margin-bottom:10px;">
                <strong>Etiquetas SEO:</strong>
                <textarea name="new_cat_tags" style="width:100%; min-height:60px;"></textarea>
            </div>
    
            <div style="margin-bottom:15px;">
                <strong>Ámbito:</strong>
            
                <select name="new_cat_ambito" style="width:100%; padding:6px;">
                    <option value="">-- Seleccionar ámbito --</option>
            
                    <?php foreach ($ambitos_disponibles as $ambito): ?>
            
                        <option value="<?php echo esc_attr($ambito); ?>">
                            <?php echo esc_html($ambito); ?>
                        </option>
            
                    <?php endforeach; ?>
            
                </select>
            </div>

            <button type="submit" style="background:#2271b1; color:#fff; padding:10px 20px; border:none; border-radius:4px;">
                Crear categoría
            </button>
    
        </form>
    </div>
<?php endif; ?>


    <form method="post">
        <input type="hidden" name="action" value="update_seo_categories">
        <?php wp_nonce_field('seo_save_category_editor', 'seo_category_editor_nonce'); ?>

        <?php if (empty($categories_list) || is_wp_error($categories_list)): ?>

            <p style="color:#777;">No se encontraron categorías de producto.</p>

        <?php else: ?>

            <ul style="list-style:none; padding:0; margin:15px 0 30px 0;">

            <?php

            // Preparar categorías agrupadas por jerarquía SEO
            $categorias_agrupadas = [];

            foreach ($categories_list as $category) {

                $term_id = $category->term_id;
                $term_link = get_term_link($category);
                $url_origen_completa = !is_wp_error($term_link) ? $term_link : '#';

                $id_cluster = 0;
                $id_hub_primario = 0;
                $id_hub_secundario = 0;

                $cluster_nombre = '';
                $hub_primario_nombre = '';
                $hub_secundario_nombre = '';

                // Buscar Hub Secundario asociado a esta categoría
                $hub_s_data = $wpdb->get_row($wpdb->prepare("
                    SELECT source_id 
                    FROM $tabla_relations 
                    WHERE target_id = %d 
                      AND relation_type = 'hub_secondary_to_category' 
                    LIMIT 1
                ", $term_id));

                if ($hub_s_data) {
                    $id_hub_secundario = intval($hub_s_data->source_id);

                    // Buscar Hub Primario asociado al Hub Secundario
                    $hub_p_data = $wpdb->get_row($wpdb->prepare("
                        SELECT source_id 
                        FROM $tabla_relations 
                        WHERE target_id = %d 
                          AND relation_type = 'hub_primary_to_hub_secondary' 
                        LIMIT 1
                    ", $id_hub_secundario));

                    if ($hub_p_data) {
                        $id_hub_primario = intval($hub_p_data->source_id);

                        // Buscar Cluster asociado al Hub Primario
                        $cluster_data = $wpdb->get_row($wpdb->prepare("
                            SELECT source_id 
                            FROM $tabla_relations 
                            WHERE target_id = %d 
                              AND relation_type = 'cluster_to_primary' 
                            LIMIT 1
                        ", $id_hub_primario));

                        if ($cluster_data) {
                            $id_cluster = intval($cluster_data->source_id);
                        }
                    }
                }

                // Obtener nombres visibles de la jerarquía SEO
                if ($id_cluster > 0) {
                    $obj = get_post($id_cluster);
                    $cluster_nombre = $obj ? $obj->post_title : '';
                }

                if ($id_hub_primario > 0) {
                    $obj = get_post($id_hub_primario);
                    $hub_primario_nombre = $obj ? $obj->post_title : '';
                }

                if ($id_hub_secundario > 0) {
                    $obj = get_post($id_hub_secundario);
                    $hub_secundario_nombre = $obj ? $obj->post_title : '';
                }

                // URL dinámica hacia productos de la categoría
                $url_ver_productos = add_query_arg([
                    'page'           => 'product-page-admin',
                    'cluster'        => $id_cluster,
                    'hub_primario'   => $id_hub_primario,
                    'hub_secundario' => $id_hub_secundario,
                    'cat'            => $term_id
                ], admin_url('admin.php'));

                $categorias_agrupadas[] = [
                    'category'               => $category,
                    'term_id'                => $term_id,
                    'url_origen_completa'    => $url_origen_completa,
                    'url_ver_productos'      => $url_ver_productos,
                    'id_cluster'             => $id_cluster,
                    'id_hub_primario'        => $id_hub_primario,
                    'id_hub_secundario'      => $id_hub_secundario,
                    'cluster_nombre'         => $cluster_nombre ?: 'Sin cluster',
                    'hub_primario_nombre'    => $hub_primario_nombre ?: 'Sin hub primario',
                    'hub_secundario_nombre'  => $hub_secundario_nombre ?: 'Sin hub secundario',
                ];
            }

            // Ordenar visualmente por Cluster > Hub Primario > Hub Secundario > Categoría
            usort($categorias_agrupadas, function($a, $b) {
                return [
                    $a['cluster_nombre'],
                    $a['hub_primario_nombre'],
                    $a['hub_secundario_nombre'],
                    $a['category']->name
                ] <=> [
                    $b['cluster_nombre'],
                    $b['hub_primario_nombre'],
                    $b['hub_secundario_nombre'],
                    $b['category']->name
                ];
            });

            // Control de cabeceras visuales
            $grupo_cluster_anterior = null;
            $grupo_hub_primario_anterior = null;
            $grupo_hub_secundario_anterior = null;

            foreach ($categorias_agrupadas as $item):

                $category = $item['category'];
                $term_id = $item['term_id'];
                //Info de la descripction de la ctegoria que no esta en Wrdpress, esta enmi tabla
                $seo_node_keywords = $wpdb->get_var($wpdb->prepare("
                    SELECT keywords
                    FROM {$wpdb->prefix}seo_nodes
                    WHERE object_type = 'category'
                      AND object_id = %d
                      AND seo_role = 'category'
                      AND status = 1
                    ORDER BY updated_at DESC, id DESC
                    LIMIT 1
                ", $term_id));
                
                $url_origen_completa = $item['url_origen_completa'];
                $url_ver_productos = $item['url_ver_productos'];

                $id_cluster = $item['id_cluster'];
                $id_hub_primario = $item['id_hub_primario'];
                $id_hub_secundario = $item['id_hub_secundario'];

                $cluster_nombre = $item['cluster_nombre'];
                $hub_primario_nombre = $item['hub_primario_nombre'];
                $hub_secundario_nombre = $item['hub_secundario_nombre'];
                
                // Excerpt SEO guardado en wp_seo_nodes
                    $seo_excerpt = $wpdb->get_var(
                        $wpdb->prepare("
                            SELECT keywords
                            FROM {$wpdb->prefix}seo_nodes
                            WHERE object_type = 'category'
                              AND object_id = %d
                              AND seo_role = 'excerpt'
                              AND status = 1
                            ORDER BY updated_at DESC, id DESC
                            LIMIT 1
                        ", $term_id)
                    );
                // Descripción SEO HTML guardada en wp_seo_nodes
                $seo_description = $wpdb->get_var($wpdb->prepare("
                    SELECT keywords
                    FROM {$wpdb->prefix}seo_nodes
                    WHERE object_type = 'category'
                      AND object_id = %d
                      AND seo_role = 'description'
                      AND status = 1
                    ORDER BY updated_at DESC, id DESC
                    LIMIT 1
                ", $term_id));

                // Cabecera Cluster
                if ($cluster_nombre !== $grupo_cluster_anterior) {

                    echo '<li style="list-style:none; margin:34px 0 14px 0; padding:15px 18px; background:#1d2327; color:#fff; border-radius:8px; font-size:18px; font-weight:bold;">';
                    echo 'Cluster: ' . esc_html($cluster_nombre);
                    echo '</li>';

                    $grupo_cluster_anterior = $cluster_nombre;
                    $grupo_hub_primario_anterior = null;
                    $grupo_hub_secundario_anterior = null;
                }

                // Cabecera Hub Primario
                if ($hub_primario_nombre !== $grupo_hub_primario_anterior) {

                    echo '<li style="list-style:none; margin:20px 0 10px 0; padding:11px 16px; background:#e7f1ff; border-left:5px solid #2271b1; color:#1d2327; border-radius:6px; font-size:15px; font-weight:bold;">';
                    echo 'Hub primario: ' . esc_html($hub_primario_nombre);
                    echo '</li>';

                    $grupo_hub_primario_anterior = $hub_primario_nombre;
                    $grupo_hub_secundario_anterior = null;
                }

                // Cabecera Hub Secundario
                if ($hub_secundario_nombre !== $grupo_hub_secundario_anterior) {

                    echo '<li style="list-style:none; margin:14px 0 8px 0; padding:9px 14px; background:#f6f7f7; border-left:5px solid #72aee6; color:#1d2327; border-radius:6px; font-size:13px; font-weight:bold;">';
                    echo 'Hub secundario: ' . esc_html($hub_secundario_nombre);
                    echo '</li>';

                    $grupo_hub_secundario_anterior = $hub_secundario_nombre;
                }

            ?>

                <li id="cat_row_<?php echo $term_id; ?>" style="
                    margin-bottom:20px;
                    padding:18px;
                    border:1px solid #e5e7eb;
                    border-radius:10px;
                    background:#fff;
                    box-shadow:0 1px 2px rgba(0,0,0,0.04);
                    transition: all 0.4s ease;
                ">

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
                        <strong style="font-size:16px; color:#1d2327;">
                            📦 <?php echo esc_html($category->name); ?>
                        </strong>

                        <button type="submit" style="padding:6px 14px; background:#2271b1; color:#fff; border:none; border-radius:4px; font-weight:bold; font-size:12px; cursor:pointer;">
                            💾 Grabar esta categoría
                        </button>
                    </div>

                    <?php /* URL ACTUAL DE LA CATEGORÍA */ ?>
                    
                    <div style="font-size:12px; margin-bottom:4px;">
                    
                        <strong>URL Origen (A borrar):</strong>
                    
                        <a href="<?php echo esc_url($url_origen_completa); ?>"
                           target="_blank"
                           style="color:#2271b1; text-decoration:none;">
                    
                            <?php echo esc_html($url_origen_completa); ?>
                    
                        </a>
                    
                    </div>

                    <div style="font-size:12px;margin-top:8px;margin-bottom:8px;color:#555;">
                        <strong>Cluster:</strong> <?php echo esc_html($cluster_nombre); ?>
                        <br>
                        <strong>Hub primario:</strong> <?php echo esc_html($hub_primario_nombre); ?>
                        <br>
                        <strong>Hub secundario:</strong> <?php echo esc_html($hub_secundario_nombre); ?>
                    </div>
                    <!-- Edición y redirección de slug -->
                        <!-- Redirect tras modificar -->
                        <?php if (
                            isset($_GET['seo_slug_updated']) &&
                            absint($_GET['seo_slug_updated']) === 1
                        ): ?>
                        
                            <div class="notice notice-success is-dismissible">
                                <p>
                                    <strong>
                                        Slug modificado y redirección 301 creada correctamente.
                                    </strong>
                                </p>
                            </div>
                        <!-- Sistena de modificacion, redirect, etc... -->
                        <?php endif; ?>
                        <div style="
                            display:flex;
                            align-items:center;
                            flex-wrap:wrap;
                            gap:6px;
                            margin-top:6px;
                            font-size:12px;
                        ">
                        
                            <strong>ID:</strong>
                        
                            <span>
                                <?php echo intval($term_id); ?>
                            </span>
                        
                            <span style="color:#a7aaad;">|</span>
                        
                            <strong>Slug:</strong>
                        
                            <span style="color:#646970;">/</span>
                        
                            <input
                                type="text"
                                id="cat_slug_<?php echo intval($term_id); ?>"
                                name="cat_slug[<?php echo intval($term_id); ?>]"
                                value="<?php echo esc_attr($category->slug); ?>"
                                data-original-slug="<?php echo esc_attr($category->slug); ?>"
                                style="
                                    width:220px;
                                    height:28px;
                                    padding:2px 7px;
                                    font-size:12px;
                                    border:1px solid #8c8f94;
                                    border-radius:3px;
                                "
                            >
                        
                            <button
                                type="submit"
                                name="seo_update_category_slug"
                                value="<?php echo intval($term_id); ?>"
                                class="button button-small"
                                title="Cambiar un slug puede provocar errores 404 y pérdida de posicionamiento. Se creará automáticamente una redirección 301 desde la URL antigua hacia la nueva."
                                onclick="return confirm(
                                    '¿Modificar el slug de esta categoría?\n\nSe creará automáticamente una redirección 301 desde la URL anterior hacia la nueva.'
                                );"
                            >
                                Modificar slug
                            </button>
                        
                            <input
                                type="hidden"
                                name="cat_old_slug[<?php echo intval($term_id); ?>]"
                                value="<?php echo esc_attr($category->slug); ?>"
                            >
                        
                            <input
                                type="hidden"
                                name="cat_old_url[<?php echo intval($term_id); ?>]"
                                value="<?php echo esc_url($url_origen_completa); ?>"
                            >
                        
                        </div>
                        
                        <!-- Fin edición y redirección de slug -->

                    <?php /* ENLACE AL LISTADO DE PRODUCTOS DE LA CATEGORÍA */ ?>
                    
                    <div style="font-size:13px; margin-top:14px; margin-bottom:10px; color:#1d2327;">
                    
                        <strong>Productos asociados:</strong>
                        <?php echo intval($category->count); ?> productos
                    
                        <a href="<?php echo esc_url($url_ver_productos); ?>"
                           class="button button-small"
                           style="margin-left:8px; background:#f6f7f7; border:1px solid #8c8f94; color:#2271b1; padding:3px 8px; border-radius:3px; text-decoration:none; font-size:11px; font-weight:600;">
                    
                            Ver productos
                    
                        </a>
                    
                    </div>

                    <hr style="border:0; border-top:1px solid #eee; margin:15px 0;">

                    <div style="margin-top:12px; margin-bottom:12px; font-size:12px;">
                        <strong>Nombre de la Categoría:</strong>
                        <input type="text" name="cat_name[<?php echo $term_id; ?>]" value="<?php echo esc_attr($category->name); ?>" style="width:100%; font-size:13px; padding:6px 10px; border:1px solid #c3c4c7; border-radius:4px; margin-top:4px;">
                    </div>
                    

                    <?php /* EXCERPT SEO DE LA CATEGORÍA - Se lee y guarda en wp_seo_nodes (seo_role = excerpt) */ ?>
                    
                    <div style="margin-bottom:12px; font-size:12px;">
                        <strong>Excerpt SEO:</strong>
                    
                        <textarea
                            name="cat_excerpt[<?php echo $term_id; ?>]"
                            style="width:100%; min-height:80px; font-size:12px; margin-top:4px; border:1px solid #c3c4c7; border-radius:4px; padding:6px; box-sizing:border-box; font-family:sans-serif; resize:vertical;"
                        ><?php echo esc_textarea($seo_excerpt); ?></textarea>
                    </div>
                    
                    <?php /* ETIQUETAS SEO DE LA CATEGORÍA - Se leen desde wp_seo_nodes.keywords */ ?>
                    
                    <div style="margin-bottom:12px; font-size:12px;">
                        <strong>Etiquetas SEO:</strong>
                    
                        <textarea
                            name="cat_tags[<?php echo $term_id; ?>]"
                            style="width:100%; min-height:60px; font-size:12px; margin-top:4px; border:1px solid #c3c4c7; border-radius:4px; padding:6px; box-sizing:border-box; font-family:sans-serif; resize:vertical;"
                        ><?php echo esc_textarea($seo_node_keywords); ?></textarea>
                    </div>



                    <div style="margin-bottom:12px; font-size:12px;">
                        <strong>Descripción (Contenido SEO):</strong>
                        <textarea name="cat_description[<?php echo $term_id; ?>]" style="width:100%; min-height:100px; font-size:12px; margin-top:4px; border:1px solid #c3c4c7; border-radius:4px; padding:6px; box-sizing:border-box; font-family:sans-serif; resize:vertical;"><?php echo esc_textarea($seo_description); ?></textarea>
                    </div>
                    <!-- ELIMINAR CATEGORÍA Y CREAR REDIRECCIÓN -->
<div style="background:#fcfcfc; border:1px dashed #c3c4c7; padding:12px; border-radius:6px; margin-top:12px; display:flex; gap:12px; flex-direction:column;">

    <span style="font-size:11px; font-weight:bold; color:#1d2327; text-transform:uppercase; letter-spacing:0.5px;">
        Elegir Destino de Redirección:
    </span>

    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">

        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:11px; color:#646970; font-weight:600;">1. Clusters</label>
            <select id="cluster_<?php echo $term_id; ?>"
                    class="seo-select-<?php echo $term_id; ?>"
                    data-level="1"
                    onchange="seoFiltrarCascada(<?php echo $term_id; ?>, 1)"
                    style="width:160px; font-size:12px; height:28px;">

                <option value="">-- Seleccionar Cluster --</option>

                <?php foreach ($clusters_sistema as $c): ?>
                    <option value="<?php echo intval($c->id); ?>">
                        <?php echo esc_html($c->nombre); ?>
                    </option>
                <?php endforeach; ?>

            </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:11px; color:#646970; font-weight:600;">2. Hubs Primarios</label>
            <select id="hub_p_<?php echo $term_id; ?>"
                    class="seo-select-<?php echo $term_id; ?>"
                    data-level="2"
                    disabled
                    onchange="seoFiltrarCascada(<?php echo $term_id; ?>, 2)"
                    style="width:160px; font-size:12px; height:28px;">

                <option value="">-- Esperando Cluster --</option>

            </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:11px; color:#646970; font-weight:600;">3. Hubs Secundarios</label>
            <select id="hub_s_<?php echo $term_id; ?>"
                    class="seo-select-<?php echo $term_id; ?>"
                    data-level="3"
                    disabled
                    onchange="seoFiltrarCascada(<?php echo $term_id; ?>, 3)"
                    style="width:160px; font-size:12px; height:28px;">

                <option value="">-- Esperando Hub P. --</option>

            </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:4px;">
            <label style="font-size:11px; color:#646970; font-weight:600;">4. Categoría Destino</label>
            <select id="cat_dest_<?php echo $term_id; ?>"
                    class="seo-select-<?php echo $term_id; ?>"
                    data-level="4"
                    disabled
                    onchange="seoFiltrarCascada(<?php echo $term_id; ?>, 4)"
                    style="width:160px; font-size:12px; height:28px;">

                <option value="">-- Esperando Hub S. --</option>

            </select>
        </div>

        <div style="display:flex; flex-direction:column; gap:4px; min-width:220px; max-width:320px;">
            <label style="font-size:11px; color:#1d2327; font-weight:bold;">
                🔗 Enlace Destino de Redirección:
            </label>

            <div id="url_preview_<?php echo $term_id; ?>"
                 style="font-size:11px; color:#646970; background:#f0f0f1; padding:6px 10px; border:1px solid #dcdcde; border-radius:4px; min-height:28px; word-break:break-all; display:flex; align-items:center;">

                <i>Ningún destino seleccionado</i>

            </div>
        </div>

        <div style="display:flex; flex-direction:column; gap:4px;">
            <button type="button"
                    id="btn_redirect_<?php echo $term_id; ?>"
                    disabled
                    onclick="seoProcesarAccionV2(<?php echo $term_id; ?>, '<?php echo esc_js($url_origen_completa); ?>', 'borrado_total')"
                    style="height:28px; padding:0 12px; background:#f6f7f7; border:1px solid #dcdcde; color:#a7aaad; border-radius:4px; font-weight:bold; cursor:not-allowed; transition:all 0.2s;">

                Eliminar Categoría

            </button>
        </div>

    </div>

</div>

                </li>

            <?php endforeach; ?>

            </ul>

        <?php endif; ?>

        <button type="submit" style="margin-top:20px; padding:12px 25px; background:#1d2327; color:#fff; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">
            Guardar todos los cambios
        </button>

    </form>

</div>





<script type="text/javascript">
var seoDatosRelaciones = <?php echo json_encode($mapeo_completo); ?>;
var seoRelacionesCategorias = <?php echo json_encode($relaciones_categorias); ?>;
var seoUrlsPrecalculadas = <?php echo json_encode($urls_precalculadas); ?>;

var seoUrlsDestinoPorFila = {};





function seoFiltrarCascada(termId, nivelModificado) {
    var clusterSel  = jQuery('#cluster_' + termId);
    var hubPSel     = jQuery('#hub_p_' + termId);
    var hubSSel     = jQuery('#hub_s_' + termId);
    var catDestSel  = jQuery('#cat_dest_' + termId);
    var previewDiv  = jQuery('#url_preview_' + termId);
    
    var btnRelOnly  = jQuery('#btn_rel_only_' + termId);
    var btnBorrar   = jQuery('#btn_redirect_' + termId);

    if (nivelModificado === 1) {
        var clusterId = clusterSel.val();
        hubPSel.html('<option value="">-- Seleccionar Hub Primario --</option>').attr('disabled', 'disabled');
        hubSSel.html('<option value="">-- Esperando Hub P. --</option>').attr('disabled', 'disabled');
        catDestSel.html('<option value="">-- Esperando Hub S. --</option>').attr('disabled', 'disabled');

        if (clusterId !== "") {
            var primarios = seoDatosRelaciones.filter(function(r) {
                return r.source_id === clusterId && r.target_type === 'hub_primary';
            });
            if (primarios.length > 0) {
                primarios.forEach(function(item) {
                    hubPSel.append('<option value="' + item.target_id + '">' + item.target_title + '</option>');
                });
                hubPSel.removeAttr('disabled');
            } else {
                hubPSel.html('<option value="">Sin Hubs Primarios</option>');
            }
        }
    }

    if (nivelModificado === 2) {
        var hubPId = hubPSel.val();
        hubSSel.html('<option value="">-- Seleccionar Hub Secundario --</option>').attr('disabled', 'disabled');
        catDestSel.html('<option value="">-- Esperando Hub S. --</option>').attr('disabled', 'disabled');

        if (hubPId !== "") {
            var secundarios = seoDatosRelaciones.filter(function(r) {
                return r.source_id === hubPId && (r.target_type === 'hub_secondary' || r.target_type === 'hub_secundario');
            });
            if (secundarios.length > 0) {
                secundarios.forEach(function(item) {
                    hubSSel.append('<option value="' + item.target_id + '">' + item.target_title + '</option>');
                });
                hubSSel.removeAttr('disabled');
            } else {
                hubSSel.html('<option value="">Sin Hubs Secundarios</option>');
            }
        }
    }

    if (nivelModificado === 3) {
        var hubSId = hubSSel.val();
        catDestSel.html('<option value="">-- Seleccionar Categoría Destino --</option>').attr('disabled', 'disabled');

        if (hubSId !== "") {
            var categoriasFiltradas = seoRelacionesCategorias.filter(function(c) {
                return c.hub_secundario_id === hubSId;
            });

            if (categoriasFiltradas.length > 0) {
                categoriasFiltradas.forEach(function(item) {
                    if (parseInt(item.cat_term_id) !== parseInt(termId)) {
                        catDestSel.append('<option value="' + item.cat_term_id + '">' + item.cat_nombre + '</option>');
                    }
                });
                catDestSel.removeAttr('disabled');
            } else {
                catDestSel.html('<option value="">Sin Categorías vinculadas</option>');
            }
        }
    }

    var finalUrl = "";
    if (catDestSel.val() && catDestSel.val() !== "") {
        finalUrl = seoUrlsPrecalculadas['cat_' + catDestSel.val()] || "";
    } else if (hubSSel.val() && hubSSel.val() !== "") {
        finalUrl = seoUrlsPrecalculadas['post_' + hubSSel.val()] || "";
    } else if (hubPSel.val() && hubPSel.val() !== "") {
        finalUrl = seoUrlsPrecalculadas['post_' + hubPSel.val()] || "";
    } else if (clusterSel.val() && clusterSel.val() !== "") {
        finalUrl = seoUrlsPrecalculadas['post_' + clusterSel.val()] || "";
    }

    seoUrlsDestinoPorFila[termId] = finalUrl;

    if (finalUrl !== "") {
        previewDiv.html('<strong style="color:#006505;">' + finalUrl + '</strong>');
        
        btnRelOnly.removeAttr('disabled').css({
            'background': '#e2f0fd',
            'border-color': '#2271b1',
            'color': '#2271b1',
            'cursor': 'pointer'
        });
        btnBorrar.removeAttr('disabled').css({
            'background': '#bae0ba',
            'border-color': '#00a32a',
            'color': '#006505',
            'cursor': 'pointer'
        });
    } else {
        previewDiv.html('<i>Ningún destino seleccionado</i>');
        btnRelOnly.attr('disabled', 'disabled').css({
            'background': '#f6f7f7',
            'border-color': '#dcdcde',
            'color': '#a7aaad',
            'cursor': 'not-allowed'
        });
        btnBorrar.attr('disabled', 'disabled').css({
            'background': '#f6f7f7',
            'border-color': '#dcdcde',
            'color': '#a7aaad',
            'cursor': 'not-allowed'
        });
    }
}

function seoProcesarAccionV2(termId, urlOrigen) {

    var urlDestino = seoUrlsDestinoPorFila[termId] || "";

    if (!urlDestino) {
        alert("Error: No se ha detectado ninguna URL de destino válida.");
        return;
    }

    var btnBorrar = jQuery('#btn_redirect_' + termId);
    var fila      = jQuery('#cat_row_' + termId);

    btnBorrar.attr('disabled', 'disabled').css('background', '#f0f0f1');

    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',

        data: {
            action: 'seo_borrar_y_redirigir_categoria',
            term_id: termId,
            url_origen: urlOrigen,
            url_destino: urlDestino
        },

        success: function(response) {

            if (!response || !response.success) {

                alert(
                    'RESPUESTA RECIBIDA: ' +
                    JSON.stringify(response)
                );

                btnBorrar.removeAttr('disabled').css({
                    'background': '#bae0ba',
                    'border-color': '#00a32a',
                    'color': '#006505',
                    'cursor': 'pointer'
                });

                return;
            }

            fila.css({
                'background': '#ffdede',
                'border-color': '#cc0000',
                'opacity': '0.3',
                'transform': 'scale(0.96)'
            });

            setTimeout(function() {

                fila.slideUp(300, function() {
                    jQuery(this).remove();
                });

            }, 300);
        },

        error: function(xhr, textStatus, errorThrown) {

            alert(
                "Error AJAX\n" +
                "Estado: " + textStatus + "\n" +
                "HTTP: " + xhr.status + "\n" +
                "Error: " + errorThrown + "\n" +
                "Respuesta: " + xhr.responseText
            );

            btnBorrar.removeAttr('disabled').css({
                'background': '#bae0ba',
                'border-color': '#00a32a',
                'color': '#006505',
                'cursor': 'pointer'
            });
        }
    });
}



</script>


<?php
// ======================================================
// MODIFICAR SLUG DE CATEGORÍA Y CREAR REDIRECCIÓN 301
// ======================================================

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['seo_update_category_slug'])
    ) {
        /*
         * Comprobar el nonce del formulario.
         */
        if (
            empty($_POST['seo_category_editor_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(
                    wp_unslash($_POST['seo_category_editor_nonce'])
                ),
                'seo_save_category_editor'
            )
        ) {
            wp_die('Error de seguridad: nonce no válido.');
        }
    
        /*
         * Permisos para modificar categorías de productos.
         */
        if (!current_user_can('manage_product_terms')) {
            wp_die('No tienes permisos para modificar categorías de producto.');
        }
    
        $term_id = absint($_POST['seo_update_category_slug']);
        $taxonomy = 'product_cat';
    
        if ($term_id <= 0) {
            wp_die('El ID de la categoría no es válido.');
        }
    
        /*
         * Obtener la categoría antes de modificarla.
         */
        $term = get_term($term_id, $taxonomy);
    
        if (!$term || is_wp_error($term)) {
            wp_die('No se ha encontrado la categoría de producto.');
        }
    
        /*
         * Leer y limpiar el nuevo slug.
         */
        $new_slug = '';
    
        if (
            isset($_POST['cat_slug']) &&
            is_array($_POST['cat_slug']) &&
            isset($_POST['cat_slug'][$term_id])
        ) {
            $new_slug = sanitize_title(
                wp_unslash($_POST['cat_slug'][$term_id])
            );
        }
    
        if ($new_slug === '') {
            wp_die('El nuevo slug no puede estar vacío.');
        }
    
        $old_slug = $term->slug;
    
        if ($new_slug === $old_slug) {
            wp_die('El slug introducido es igual al slug actual.');
        }
    
        /*
         * Obtener la URL antigua antes de modificar WordPress.
         */
        $old_url = get_term_link($term);
    
        if (is_wp_error($old_url)) {
            wp_die(
                'No se ha podido obtener la URL antigua: ' .
                esc_html($old_url->get_error_message())
            );
        }
    
        /*
         * Modificar el slug con las herramientas nativas de WordPress.
         */
        $update_result = wp_update_term(
            $term_id,
            $taxonomy,
            [
                'slug' => $new_slug,
            ]
        );
    
        if (is_wp_error($update_result)) {
            wp_die(
                'WordPress no ha podido modificar el slug: ' .
                esc_html($update_result->get_error_message())
            );
        }
    
        /*
         * Limpiar caché y recuperar la categoría actualizada.
         */
        clean_term_cache($term_id, $taxonomy);
    
        $updated_term = get_term($term_id, $taxonomy);
    
        if (!$updated_term || is_wp_error($updated_term)) {
            wp_die(
                'El slug se ha modificado, pero no se pudo recuperar la categoría.'
            );
        }
    
        /*
         * Obtener la nueva URL completa.
         */
        $new_url = get_term_link($updated_term);
    
        if (is_wp_error($new_url)) {
            wp_die(
                'El slug se ha modificado, pero no se pudo generar la nueva URL.'
            );
        }
    
        /*
         * La URL de origen se guarda como ruta interna.
         *
         * Ejemplo:
         * /tienda/herramientas/slug-antiguo
         */
        $origin_url = wp_parse_url(
            $old_url,
            PHP_URL_PATH
        );
    
        $origin_url = '/' . ltrim(
            (string) $origin_url,
            '/'
        );
    
        /*
         * Eliminar barras duplicadas.
         */
        $origin_url = preg_replace(
            '#/+#',
            '/',
            $origin_url
        );
    
        /*
         * Adaptarlo al formato de tu tabla:
         * sin barra final, salvo que sea la raíz.
         */
        if ($origin_url !== '/') {
            $origin_url = untrailingslashit($origin_url);
        }
    
        /*
         * En tu tabla, target_url se guarda como URL completa.
         */
        $target_url = esc_url_raw($new_url);
    
        if (
            empty($origin_url) ||
            empty($target_url)
        ) {
            wp_die(
                'No se pudieron preparar las URLs de la redirección.'
            );
        }
    
        $table_redirects = $wpdb->prefix . 'seo_redirects';
    
        /*
         * Buscar una redirección anterior con el mismo origen.
         * origin_url tiene un índice único.
         */
        $existing_redirect_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$table_redirects}
                 WHERE origin_url = %s
                 LIMIT 1",
                $origin_url
            )
        );
    
        if ($existing_redirect_id) {
            /*
             * Si el origen ya existe, actualizar el destino.
             */
            $redirect_result = $wpdb->update(
                $table_redirects,
                [
                    'target_url'  => $target_url,
                    'status_code' => 301,
                ],
                [
                    'id' => absint($existing_redirect_id),
                ],
                [
                    '%s',
                    '%d',
                ],
                [
                    '%d',
                ]
            );
        } else {
            /*
             * Crear una nueva redirección.
             */
            $redirect_result = $wpdb->insert(
                $table_redirects,
                [
                    'origin_url'  => $origin_url,
                    'target_url'  => $target_url,
                    'status_code' => 301,
                    'hits'        => 0,
                    'last_hit'    => null,
                ],
                [
                    '%s',
                    '%s',
                    '%d',
                    '%d',
                    '%s',
                ]
            );
        }
    
        if ($redirect_result === false) {
            wp_die(
                'El slug se modificó correctamente, pero no se pudo guardar ' .
                'la redirección.<br><br>' .
    
                '<strong>Tabla:</strong> ' .
                esc_html($table_redirects) .
                '<br>' .
    
                '<strong>Origen:</strong> ' .
                esc_html($origin_url) .
                '<br>' .
    
                '<strong>Destino:</strong> ' .
                esc_html($target_url) .
                '<br>' .
    
                '<strong>Error SQL:</strong> ' .
                esc_html($wpdb->last_error)
            );
        }
    
        /*
         * Volver a la página sin repetir el POST.
         */
            $return_url = add_query_arg(
                [
                    'page'             => 'category-seo-admin',
                    'seo_slug_updated' => 1,
                    'seo_slug_term'    => $term_id,
                ],
                admin_url('admin.php')
            );
            
            wp_safe_redirect($return_url);
            exit;


    }  //Fin de funcion de modificar slug

} //Fin de funcion general

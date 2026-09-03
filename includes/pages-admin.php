<?php
/**
 * Editor de páginas SEO.
 *
 * Ámbitos separados:
 * - Estructura SEO: cluster, hub_primary, hub_secondary.
 * - Landings: landing + relación comercial landing_to_category.
 * - Corporativas: corporate_page.
 *
 * Las etiquetas SEO legacy se leen y guardan exclusivamente en
 * wp_seo_nodes.keywords. No se sincronizan con post_tag.
 *
 * Version: 2026-08-26
 * Build: 2
 */

if (!defined('ABSPATH')) {
    exit;
}

/* -------------------------------------------------------------------------
 * Helpers de datos
 * ---------------------------------------------------------------------- */

if (!function_exists('seo_page_editor_allowed_statuses')) {
    function seo_page_editor_allowed_statuses() {
        return array(
            'publish' => 'PUBLICADA',
            'future'  => 'PROGRAMADA',
            'draft'   => 'BORRADOR',
            'pending' => 'PENDIENTE',
            'private' => 'PRIVADA',
        );
    }
}

if (!function_exists('seo_page_editor_allowed_roles')) {
    function seo_page_editor_allowed_roles() {
        return array('cluster', 'hub_primary', 'hub_secondary', 'landing', 'corporate_page');
    }
}

if (!function_exists('seo_page_editor_get_node')) {
    function seo_page_editor_get_node($page_id, $role) {
        global $wpdb;

        $page_id = absint($page_id);
        $role    = sanitize_key($role);

        if ($page_id < 1 || !in_array($role, seo_page_editor_allowed_roles(), true)) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, object_type, object_id, seo_role, keywords, status
                 FROM {$wpdb->prefix}seo_nodes
                 WHERE object_type = 'page'
                   AND object_id = %d
                   AND seo_role = %s
                 ORDER BY status DESC, id ASC
                 LIMIT 1",
                $page_id,
                $role
            )
        );
    }
}

if (!function_exists('seo_page_editor_save_node_keywords')) {
    function seo_page_editor_save_node_keywords($page_id, $role, $keywords) {
        global $wpdb;

        $page_id  = absint($page_id);
        $role     = sanitize_key($role);
        $keywords = trim((string) $keywords);

        if ($page_id < 1 || !in_array($role, seo_page_editor_allowed_roles(), true)) {
            return new WP_Error('seo_page_role_invalid', 'Rol SEO de página no válido.');
        }

        $table = $wpdb->prefix . 'seo_nodes';
        $node  = seo_page_editor_get_node($page_id, $role);
        $now   = current_time('mysql');

        if ($node) {
            $updated = $wpdb->update(
                $table,
                array(
                    'keywords'   => $keywords !== '' ? $keywords : null,
                    'status'     => 1,
                    'updated_at' => $now,
                ),
                array('id' => (int) $node->id),
                array('%s', '%d', '%s'),
                array('%d')
            );

            if ($updated === false) {
                return new WP_Error(
                    'seo_page_node_update',
                    'No se pudieron guardar las etiquetas SEO en seo_nodes. ' . $wpdb->last_error
                );
            }

            return true;
        }

        $inserted = $wpdb->insert(
            $table,
            array(
                'object_type' => 'page',
                'object_id'   => $page_id,
                'seo_role'    => $role,
                'keywords'    => $keywords !== '' ? $keywords : null,
                'status'      => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array('%s', '%d', '%s', '%s', '%d', '%s', '%s')
        );

        if ($inserted === false) {
            return new WP_Error(
                'seo_page_node_insert',
                'No se pudo crear el nodo SEO de la página. ' . $wpdb->last_error
            );
        }

        return true;
    }
}

if (!function_exists('seo_page_editor_get_pages_by_roles')) {
    function seo_page_editor_get_pages_by_roles($roles) {
        global $wpdb;

        $roles = array_values(array_intersect(
            seo_page_editor_allowed_roles(),
            array_map('sanitize_key', (array) $roles)
        ));

        if (empty($roles)) {
            return array();
        }

        $role_placeholders = implode(',', array_fill(0, count($roles), '%s'));
        $status_placeholders = implode(',', array_fill(0, count(seo_page_editor_allowed_statuses()), '%s'));
        $params = array_merge($roles, array_keys(seo_page_editor_allowed_statuses()));

        $sql = "SELECT
                    p.ID,
                    p.post_title,
                    p.post_name,
                    p.post_status,
                    p.post_excerpt,
                    p.post_content,
                    p.post_date,
                    p.post_modified,
                    n.id AS node_id,
                    n.seo_role,
                    n.keywords AS seo_keywords
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->prefix}seo_nodes n
                    ON n.object_type = 'page'
                   AND n.object_id = p.ID
                   AND n.status = 1
                WHERE p.post_type = 'page'
                  AND n.seo_role IN ({$role_placeholders})
                  AND p.post_status IN ({$status_placeholders})
                ORDER BY n.seo_role ASC, p.post_title ASC";

        return (array) $wpdb->get_results($wpdb->prepare($sql, $params));
    }
}

if (!function_exists('seo_page_editor_get_structure_tree')) {
    function seo_page_editor_get_structure_tree() {
        global $wpdb;

        $relations = $wpdb->prefix . 'seo_relations';

        $rows = $wpdb->get_results(
            "SELECT DISTINCT
                cp.source_id AS cluster_id,
                cpost.post_title AS cluster_title,
                cp.target_id AS hub_primary_id,
                hppost.post_title AS hub_primary_title,
                hs.target_id AS hub_secondary_id,
                hspost.post_title AS hub_secondary_title,
                hc.target_id AS category_id,
                t.name AS category_name,
                t.slug AS category_slug
             FROM {$relations} cp
             INNER JOIN {$wpdb->posts} cpost
                ON cpost.ID = cp.source_id
               AND cpost.post_type = 'page'
             INNER JOIN {$wpdb->posts} hppost
                ON hppost.ID = cp.target_id
               AND hppost.post_type = 'page'
             INNER JOIN {$relations} hs
                ON hs.source_id = cp.target_id
               AND hs.source_type = 'hub_primary'
               AND hs.target_type IN ('hub_secondary', 'hub_secundario')
               AND hs.relation_type IN ('hub_primary_to_hub_secondary', 'hub_primary_to_secondary')
             INNER JOIN {$wpdb->posts} hspost
                ON hspost.ID = hs.target_id
               AND hspost.post_type = 'page'
             LEFT JOIN {$relations} hc
                ON hc.source_id = hs.target_id
               AND hc.source_type IN ('hub_secondary', 'hub_secundario')
               AND hc.target_type = 'product_cat'
               AND hc.relation_type = 'hub_secondary_to_category'
             LEFT JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_id = hc.target_id
               AND tt.taxonomy = 'product_cat'
             LEFT JOIN {$wpdb->terms} t
                ON t.term_id = tt.term_id
             WHERE cp.source_type = 'cluster'
               AND cp.target_type = 'hub_primary'
               AND cp.relation_type IN ('cluster_to_primary', 'cluster_to_hub_primary')
             ORDER BY cpost.post_title, hppost.post_title, hspost.post_title, t.name"
        );

        $tree = array();

        foreach ((array) $rows as $row) {
            $cluster_id = absint($row->cluster_id);
            $primary_id = absint($row->hub_primary_id);
            $secondary_id = absint($row->hub_secondary_id);
            $category_id = absint($row->category_id);

            if (!$cluster_id || !$primary_id || !$secondary_id) {
                continue;
            }

            if (!isset($tree[$cluster_id])) {
                $tree[$cluster_id] = array(
                    'id'       => $cluster_id,
                    'title'    => (string) $row->cluster_title,
                    'primaries'=> array(),
                );
            }

            if (!isset($tree[$cluster_id]['primaries'][$primary_id])) {
                $tree[$cluster_id]['primaries'][$primary_id] = array(
                    'id'          => $primary_id,
                    'title'       => (string) $row->hub_primary_title,
                    'secondaries' => array(),
                );
            }

            if (!isset($tree[$cluster_id]['primaries'][$primary_id]['secondaries'][$secondary_id])) {
                $tree[$cluster_id]['primaries'][$primary_id]['secondaries'][$secondary_id] = array(
                    'id'         => $secondary_id,
                    'title'      => (string) $row->hub_secondary_title,
                    'categories' => array(),
                );
            }

            if ($category_id > 0 && $row->category_name !== null) {
                $tree[$cluster_id]['primaries'][$primary_id]['secondaries'][$secondary_id]['categories'][$category_id] = array(
                    'id'   => $category_id,
                    'name' => (string) $row->category_name,
                    'slug' => (string) $row->category_slug,
                );
            }
        }

        return $tree;
    }
}

if (!function_exists('seo_page_editor_get_category_paths')) {
    function seo_page_editor_get_category_paths($tree) {
        $paths = array();

        foreach ((array) $tree as $cluster) {
            foreach ((array) $cluster['primaries'] as $primary) {
                foreach ((array) $primary['secondaries'] as $secondary) {
                    foreach ((array) $secondary['categories'] as $category) {
                        $category_id = absint($category['id']);
                        if (!$category_id) {
                            continue;
                        }

                        $paths[$category_id][] = sprintf(
                            '%s → %s → %s',
                            $cluster['title'],
                            $primary['title'],
                            $secondary['title']
                        );
                    }
                }
            }
        }

        return $paths;
    }
}

if (!function_exists('seo_page_editor_get_landing_category_ids')) {
    function seo_page_editor_get_landing_category_ids($page_id) {
        global $wpdb;

        $page_id = absint($page_id);
        if (!$page_id) {
            return array();
        }

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$wpdb->prefix}seo_relations
                 WHERE source_type = 'landing'
                   AND source_id = %d
                   AND target_type = 'product_cat'
                   AND relation_type = 'landing_to_category'
                 ORDER BY target_id ASC",
                $page_id
            )
        );

        return array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
    }
}

if (!function_exists('seo_page_editor_replace_landing_categories')) {
    function seo_page_editor_replace_landing_categories($page_id, $category_ids) {
        global $wpdb;

        $page_id = absint($page_id);
        $category_ids = array_values(array_unique(array_filter(array_map('absint', (array) $category_ids))));

        if (!$page_id || get_post_type($page_id) !== 'page') {
            return new WP_Error('seo_page_invalid_landing', 'Página landing no válida.');
        }

        foreach ($category_ids as $term_id) {
            $term = get_term($term_id, 'product_cat');
            if (!$term || is_wp_error($term)) {
                return new WP_Error(
                    'seo_page_invalid_product_cat',
                    sprintf('La categoría de producto ID %d no existe o no es product_cat.', $term_id)
                );
            }
        }

        $table = $wpdb->prefix . 'seo_relations';
        $wpdb->query('START TRANSACTION');

        $deleted = $wpdb->delete(
            $table,
            array(
                'source_type'   => 'landing',
                'source_id'     => $page_id,
                'target_type'   => 'product_cat',
                'relation_type' => 'landing_to_category',
            ),
            array('%s', '%d', '%s', '%s')
        );

        if ($deleted === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('seo_page_relation_delete', 'No se pudieron sustituir las relaciones comerciales. ' . $wpdb->last_error);
        }

        foreach ($category_ids as $term_id) {
            $inserted = $wpdb->insert(
                $table,
                array(
                    'source_type'   => 'landing',
                    'source_id'     => $page_id,
                    'target_type'   => 'product_cat',
                    'target_id'     => $term_id,
                    'relation_type' => 'landing_to_category',
                    'created_at'    => current_time('mysql'),
                ),
                array('%s', '%d', '%s', '%d', '%s', '%s')
            );

            if ($inserted === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('seo_page_relation_insert', 'No se pudo guardar una relación comercial. ' . $wpdb->last_error);
            }
        }

        $wpdb->query('COMMIT');
        return true;
    }
}

if (!function_exists('seo_page_editor_get_all_product_categories')) {
    function seo_page_editor_get_all_product_categories() {
        $terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ));

        return is_wp_error($terms) ? array() : (array) $terms;
    }
}

/* -------------------------------------------------------------------------
 * AJAX heredado: desvincular/borrar páginas estructurales con redirección.
 * ---------------------------------------------------------------------- */

add_action('wp_ajax_seo_solo_desvincular_pagina', 'seo_solo_desvincular_pagina_callback');
add_action('wp_ajax_seo_borrar_y_redirigir_pagina', 'seo_borrar_y_redirigir_pagina_callback');

if (!function_exists('seo_page_editor_validate_redirect_request')) {
    function seo_page_editor_validate_redirect_request() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'No tienes permisos suficientes.'));
        }

        check_ajax_referer('seo_page_editor_ajax', 'nonce');

        $page_id     = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
        $url_origen  = isset($_POST['url_origen']) ? esc_url_raw(wp_unslash($_POST['url_origen'])) : '';
        $url_destino = isset($_POST['url_destino']) ? esc_url_raw(wp_unslash($_POST['url_destino'])) : '';

        if (!$page_id || !$url_origen || !$url_destino) {
            wp_send_json_error(array('message' => 'Faltan datos requeridos para procesar la acción.'));
        }

        if (get_post_type($page_id) !== 'page') {
            wp_send_json_error(array('message' => 'El objeto indicado no es una página.'));
        }

        return array($page_id, $url_origen, $url_destino);
    }
}

function seo_solo_desvincular_pagina_callback() {
    list($page_id, $url_origen, $url_destino) = seo_page_editor_validate_redirect_request();

    global $wpdb;
    $relations = $wpdb->prefix . 'seo_relations';
    $redirects = $wpdb->prefix . 'seo_redirects';

    $origin_path = '/' . trim(wp_make_link_relative($url_origen), '/');
    $target_path = '/' . trim(wp_make_link_relative($url_destino), '/');

    if ($origin_path === $target_path) {
        wp_send_json_error(array('message' => 'No puedes redirigir una página hacia sí misma.'));
    }

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT target_url FROM {$redirects} WHERE origin_url = %s LIMIT 1",
        $origin_path
    ));

    if ($existing) {
        wp_send_json_error(array('message' => 'La URL de origen ya tiene una redirección activa.'));
    }

    $inserted = $wpdb->insert(
        $redirects,
        array(
            'origin_url'  => $origin_path,
            'target_url'  => $url_destino,
            'status_code' => 301,
            'hits'        => 0,
            'last_hit'    => null,
        ),
        array('%s', '%s', '%d', '%d', '%s')
    );

    if ($inserted === false) {
        wp_send_json_error(array('message' => 'No se pudo crear la redirección. ' . $wpdb->last_error));
    }

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$relations}
         WHERE (target_id = %d AND target_type IN ('cluster','hub_primary','hub_secondary','hub_secundario'))
            OR (source_id = %d AND source_type IN ('cluster','hub_primary','hub_secondary','hub_secundario'))",
        $page_id,
        $page_id
    ));

    wp_send_json_success(array('message' => 'Página desvinculada del mapa estructural y redirección creada.'));
}

function seo_borrar_y_redirigir_pagina_callback() {
    list($page_id, $url_origen, $url_destino) = seo_page_editor_validate_redirect_request();

    global $wpdb;
    $relations = $wpdb->prefix . 'seo_relations';
    $redirects = $wpdb->prefix . 'seo_redirects';
    $nodes     = $wpdb->prefix . 'seo_nodes';

    $origin_path = '/' . trim(wp_make_link_relative($url_origen), '/');
    $target_path = '/' . trim(wp_make_link_relative($url_destino), '/');

    if ($origin_path === $target_path) {
        wp_send_json_error(array('message' => 'No puedes redirigir una página hacia sí misma.'));
    }

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$redirects} WHERE origin_url = %s LIMIT 1",
        $origin_path
    ));

    if ($existing) {
        wp_send_json_error(array('message' => 'La URL de origen ya tiene una redirección activa.'));
    }

    $inserted = $wpdb->insert(
        $redirects,
        array(
            'origin_url'  => $origin_path,
            'target_url'  => $url_destino,
            'status_code' => 301,
            'hits'        => 0,
            'last_hit'    => null,
        ),
        array('%s', '%s', '%d', '%d', '%s')
    );

    if ($inserted === false) {
        wp_send_json_error(array('message' => 'No se pudo crear la redirección. ' . $wpdb->last_error));
    }

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$relations} WHERE source_id = %d OR target_id = %d",
        $page_id,
        $page_id
    ));

    $wpdb->delete($nodes, array('object_type' => 'page', 'object_id' => $page_id), array('%s', '%d'));

    if (!wp_delete_post($page_id, true)) {
        wp_send_json_error(array('message' => 'La redirección se creó, pero no se pudo borrar la página de WordPress.'));
    }

    wp_send_json_success(array('message' => 'Página eliminada y redirección creada.'));
}

/* -------------------------------------------------------------------------
 * Guardado / creación
 * ---------------------------------------------------------------------- */

if (!function_exists('seo_page_editor_process_save')) {
    function seo_page_editor_process_save(&$notices) {
        if (empty($_POST['seo_page_editor_action']) || $_POST['seo_page_editor_action'] !== 'save_page') {
            return;
        }

        $page_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
        $role    = isset($_POST['seo_role']) ? sanitize_key(wp_unslash($_POST['seo_role'])) : '';

        if (!$page_id || !in_array($role, seo_page_editor_allowed_roles(), true)) {
            $notices[] = array('error', 'Página o rol SEO no válidos.');
            return;
        }

        if (!isset($_POST['seo_page_editor_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['seo_page_editor_nonce'])), 'seo_page_editor_save_' . $page_id)) {
            $notices[] = array('error', 'Nonce inválido. No se guardó la página.');
            return;
        }

        $post = get_post($page_id);
        if (!$post || $post->post_type !== 'page') {
            $notices[] = array('error', 'La página indicada ya no existe.');
            return;
        }

        $title   = isset($_POST['page_title']) ? sanitize_text_field(wp_unslash($_POST['page_title'])) : $post->post_title;
        $excerpt = isset($_POST['page_excerpt']) ? wp_kses_post(wp_unslash($_POST['page_excerpt'])) : $post->post_excerpt;
        $content = isset($_POST['page_content']) ? wp_kses_post(wp_unslash($_POST['page_content'])) : $post->post_content;
        $status  = isset($_POST['page_status']) ? sanitize_key(wp_unslash($_POST['page_status'])) : $post->post_status;
        $keywords = isset($_POST['seo_keywords']) ? sanitize_textarea_field(wp_unslash($_POST['seo_keywords'])) : '';

        if (!array_key_exists($status, seo_page_editor_allowed_statuses())) {
            $status = $post->post_status;
        }

        $update_data = array(
            'ID'           => $page_id,
            'post_title'   => $title,
            'post_excerpt' => $excerpt,
            'post_content' => $content,
        );

        if ($status !== $post->post_status) {
            $update_data['post_status'] = $status;
        }

        $updated = wp_update_post(wp_slash($update_data), true);
        if (is_wp_error($updated)) {
            $notices[] = array('error', 'No se pudo guardar WordPress: ' . $updated->get_error_message());
            return;
        }

        $node_result = seo_page_editor_save_node_keywords($page_id, $role, $keywords);
        if (is_wp_error($node_result)) {
            $notices[] = array('error', $node_result->get_error_message());
            return;
        }

        if ($role === 'landing') {
            $raw_ids = isset($_POST['landing_category_ids'])
                ? sanitize_text_field(wp_unslash($_POST['landing_category_ids']))
                : '';

            $category_ids = array();
            if ($raw_ids !== '') {
                $category_ids = array_map('absint', preg_split('/\s*,\s*/', $raw_ids));
            }

            $relation_result = seo_page_editor_replace_landing_categories($page_id, $category_ids);
            if (is_wp_error($relation_result)) {
                $notices[] = array('error', 'Contenido guardado, pero falló la relación comercial: ' . $relation_result->get_error_message());
                return;
            }
        }

        $notices[] = array('success', sprintf('Página %d guardada correctamente.', $page_id));
    }
}

if (!function_exists('seo_page_editor_process_create')) {
    function seo_page_editor_process_create(&$notices) {
        if (empty($_POST['seo_page_editor_action']) || $_POST['seo_page_editor_action'] !== 'create_page') {
            return;
        }

        if (!isset($_POST['seo_page_create_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['seo_page_create_nonce'])), 'seo_page_editor_create')) {
            $notices[] = array('error', 'Nonce inválido. No se creó la página.');
            return;
        }

        $title = isset($_POST['new_page_title']) ? sanitize_text_field(wp_unslash($_POST['new_page_title'])) : '';
        $slug  = isset($_POST['new_page_slug']) ? sanitize_title(wp_unslash($_POST['new_page_slug'])) : '';
        $role  = isset($_POST['new_page_role']) ? sanitize_key(wp_unslash($_POST['new_page_role'])) : '';

        if ($title === '' || !in_array($role, seo_page_editor_allowed_roles(), true)) {
            $notices[] = array('error', 'Título o rol SEO no válido.');
            return;
        }

        $page_id = wp_insert_post(array(
            'post_title'  => $title,
            'post_name'   => $slug,
            'post_type'   => 'page',
            'post_status' => 'draft',
        ), true);

        if (is_wp_error($page_id)) {
            $notices[] = array('error', 'No se pudo crear la página: ' . $page_id->get_error_message());
            return;
        }

        $node_result = seo_page_editor_save_node_keywords($page_id, $role, '');
        if (is_wp_error($node_result)) {
            wp_delete_post($page_id, true);
            $notices[] = array('error', 'No se pudo registrar el rol SEO: ' . $node_result->get_error_message());
            return;
        }

        $notices[] = array('success', sprintf('Página %d creada como borrador con rol %s.', $page_id, $role));
    }
}

/* -------------------------------------------------------------------------
 * Render
 * ---------------------------------------------------------------------- */

if (!function_exists('seo_page_editor_render_notice')) {
    function seo_page_editor_render_notice($notice) {
        $type = isset($notice[0]) && $notice[0] === 'error' ? 'error' : 'success';
        $message = isset($notice[1]) ? (string) $notice[1] : '';
        echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html($message) . '</p></div>';
    }
}

if (!function_exists('seo_page_editor_role_label')) {
    function seo_page_editor_role_label($role) {
        $labels = array(
            'cluster'        => 'Cluster',
            'hub_primary'    => 'Hub primario',
            'hub_secondary'  => 'Hub secundario',
            'landing'        => 'Landing',
            'corporate_page' => 'Corporativa',
        );
        return isset($labels[$role]) ? $labels[$role] : $role;
    }
}

if (!function_exists('seo_page_editor_render_create_box')) {
    function seo_page_editor_render_create_box($tab) {
        $roles = array();

        if ($tab === 'estructura') {
            $roles = array(
                'cluster'       => 'Cluster',
                'hub_primary'   => 'Hub primario',
                'hub_secondary' => 'Hub secundario',
            );
        } elseif ($tab === 'landings') {
            $roles = array('landing' => 'Landing');
        } elseif ($tab === 'corporativas') {
            $roles = array('corporate_page' => 'Corporativa');
        }

        if (empty($roles)) {
            return;
        }

        echo '<details style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;margin:0 0 18px;">';
        echo '<summary style="cursor:pointer;font-weight:600;">Crear nueva página en este ámbito</summary>';
        echo '<form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:12px;">';
        echo '<input type="hidden" name="seo_page_editor_action" value="create_page">';
        wp_nonce_field('seo_page_editor_create', 'seo_page_create_nonce');
        echo '<div><label style="display:block;font-size:12px;font-weight:600;">Título</label><input type="text" name="new_page_title" required style="width:300px;"></div>';
        echo '<div><label style="display:block;font-size:12px;font-weight:600;">Slug</label><input type="text" name="new_page_slug" style="width:240px;" placeholder="opcional"></div>';
        echo '<div><label style="display:block;font-size:12px;font-weight:600;">Rol SEO</label><select name="new_page_role">';
        foreach ($roles as $role => $label) {
            echo '<option value="' . esc_attr($role) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></div>';
        echo '<button class="button button-primary" type="submit">Crear borrador</button>';
        echo '</form></details>';
    }
}

if (!function_exists('seo_page_editor_render_landing_relations')) {
    function seo_page_editor_render_landing_relations($page_id, $tree, $category_paths, $all_categories) {
        $current_ids = seo_page_editor_get_landing_category_ids($page_id);

        echo '<div class="seo-landing-relations" data-page-id="' . esc_attr($page_id) . '" style="margin-top:16px;padding:14px;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:6px;">';
        echo '<div style="font-weight:700;margin-bottom:5px;">Relación comercial con categorías de producto</div>';
        echo '<p style="margin:0 0 10px;color:#50575e;font-size:12px;">Se guarda en <code>wp_seo_relations</code> como <code>landing_to_category</code>. Puedes añadir varias categorías.</p>';

        echo '<input type="hidden" class="seo-selected-category-ids" name="landing_category_ids" value="' . esc_attr(implode(',', $current_ids)) . '">';
        echo '<div class="seo-selected-categories" style="margin-bottom:12px;">';

        if (empty($current_ids)) {
            echo '<div class="seo-no-categories" style="color:#b32d2e;font-size:12px;">Sin categoría comercial asociada.</div>';
        }

        foreach ($current_ids as $term_id) {
            $term = get_term($term_id, 'product_cat');
            $name = ($term && !is_wp_error($term)) ? $term->name : 'Categoría inexistente';
            $path = !empty($category_paths[$term_id][0]) ? $category_paths[$term_id][0] : 'Sin ruta estructural detectada';
            echo '<span class="seo-category-chip" data-category-id="' . esc_attr($term_id) . '" style="display:inline-flex;align-items:center;gap:6px;margin:3px 6px 3px 0;padding:5px 8px;background:#fff;border:1px solid #8c8f94;border-radius:999px;font-size:12px;">';
            echo '<span><strong>' . esc_html($name) . '</strong> <small style="color:#646970;">ID ' . esc_html($term_id) . ' · ' . esc_html($path) . '</small></span>';
            echo '<button type="button" class="seo-remove-category" data-category-id="' . esc_attr($term_id) . '" style="border:0;background:transparent;color:#b32d2e;cursor:pointer;font-weight:700;">×</button>';
            echo '</span>';
        }

        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:8px;align-items:end;">';
        echo '<div><label>1. Cluster</label><select class="seo-rel-cluster" style="width:100%;"><option value="">Seleccionar</option>';
        foreach ($tree as $cluster) {
            echo '<option value="' . esc_attr($cluster['id']) . '">' . esc_html($cluster['title']) . '</option>';
        }
        echo '</select></div>';
        echo '<div><label>2. Hub primario</label><select class="seo-rel-primary" style="width:100%;" disabled><option value="">Seleccionar cluster</option></select></div>';
        echo '<div><label>3. Hub secundario</label><select class="seo-rel-secondary" style="width:100%;" disabled><option value="">Seleccionar hub primario</option></select></div>';
        echo '<div><label>4. Categoría de producto</label><select class="seo-rel-category" style="width:100%;" disabled><option value="">Seleccionar hub secundario</option></select></div>';
        echo '</div>';

        echo '<div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-top:10px;">';
        echo '<button type="button" class="button seo-add-structured-category" disabled>Añadir categoría seleccionada</button>';
        echo '<span style="color:#646970;font-size:12px;">o, si la categoría todavía no está dentro del árbol estructural:</span>';
        echo '<select class="seo-direct-category" style="min-width:300px;"><option value="">Búsqueda directa de product_cat</option>';
        foreach ($all_categories as $term) {
            echo '<option value="' . esc_attr($term->term_id) . '">' . esc_html($term->name) . ' (#' . esc_html($term->term_id) . ')</option>';
        }
        echo '</select>';
        echo '<button type="button" class="button seo-add-direct-category">Añadir directa</button>';
        echo '</div>';
        echo '</div>';
    }
}

if (!function_exists('seo_page_editor_render_page_card')) {
    function seo_page_editor_render_page_card($page, $tab, $tree, $category_paths, $all_categories, $focus_page_id) {
        $page_id = absint($page->ID);
        $role    = sanitize_key($page->seo_role);
        $url     = get_permalink($page_id);
        $focused = $focus_page_id === $page_id;

        echo '<article id="seo-page-' . esc_attr($page_id) . '" style="margin:0 0 18px;padding:18px;background:#fff;border:' . ($focused ? '2px solid #2271b1' : '1px solid #dcdcde') . ';border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,.04);">';
        echo '<form method="post">';
        echo '<input type="hidden" name="seo_page_editor_action" value="save_page">';
        echo '<input type="hidden" name="page_id" value="' . esc_attr($page_id) . '">';
        echo '<input type="hidden" name="seo_role" value="' . esc_attr($role) . '">';
        wp_nonce_field('seo_page_editor_save_' . $page_id, 'seo_page_editor_nonce');

        echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">';
        echo '<div><h3 style="margin:0 0 4px;">' . esc_html($page->post_title ?: '(Sin título)') . '</h3>';
        echo '<div style="font-size:12px;color:#646970;">ID ' . esc_html($page_id) . ' · <strong>' . esc_html(seo_page_editor_role_label($role)) . '</strong> · ' . esc_html($page->post_status) . '</div>';
        if ($url) {
            echo '<div style="font-size:12px;margin-top:3px;"><a href="' . esc_url($url) . '" target="_blank" rel="noopener">Ver página</a> · <a href="' . esc_url(get_edit_post_link($page_id, 'raw')) . '">Editor WordPress</a></div>';
        }
        echo '</div>';
        echo '<button type="submit" class="button button-primary">Guardar esta página</button>';
        echo '</div>';

        if ($tab === 'estructura') {
            echo '<details style="margin-top:12px;background:#fff8e5;border-left:4px solid #dba617;padding:9px 12px;">';
            echo '<summary style="cursor:pointer;font-weight:600;">Desvincular o eliminar con redirección</summary>';
            echo '<p style="font-size:12px;color:#646970;">Herramienta heredada para páginas estructurales. Selecciona un destino del árbol antes de ejecutar una acción.</p>';
            echo '<div class="seo-redirect-controls" data-page-id="' . esc_attr($page_id) . '" data-origin-url="' . esc_attr($url) . '" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">';
            echo '<div><label>Cluster</label><select class="seo-redir-cluster"><option value="">Seleccionar</option>';
            foreach ($tree as $cluster) {
                echo '<option value="' . esc_attr($cluster['id']) . '">' . esc_html($cluster['title']) . '</option>';
            }
            echo '</select></div>';
            echo '<div><label>Hub primario</label><select class="seo-redir-primary" disabled><option value="">Seleccionar cluster</option></select></div>';
            echo '<div><label>Hub secundario</label><select class="seo-redir-secondary" disabled><option value="">Seleccionar hub primario</option></select></div>';
            echo '<button type="button" class="button seo-redir-unlink" disabled>Descartar del mapa</button>';
            echo '<button type="button" class="button seo-redir-delete" disabled style="color:#b32d2e;">Borrar de WordPress</button>';
            echo '</div></details>';
        }

        echo '<div style="display:grid;grid-template-columns:180px 1fr;gap:10px 16px;margin-top:16px;align-items:start;">';
        echo '<label><strong>Estado</strong></label><div>';
        foreach (seo_page_editor_allowed_statuses() as $status => $label) {
            echo '<label style="margin-right:12px;white-space:nowrap;"><input type="radio" name="page_status" value="' . esc_attr($status) . '" ' . checked($page->post_status, $status, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</div>';

        echo '<label for="seo-title-' . esc_attr($page_id) . '"><strong>Título</strong></label>';
        echo '<input id="seo-title-' . esc_attr($page_id) . '" type="text" name="page_title" value="' . esc_attr($page->post_title) . '" style="width:100%;">';

        echo '<label for="seo-excerpt-' . esc_attr($page_id) . '"><strong>Extracto</strong></label>';
        echo '<textarea id="seo-excerpt-' . esc_attr($page_id) . '" name="page_excerpt" rows="4" style="width:100%;">' . esc_textarea($page->post_excerpt) . '</textarea>';

        echo '<label for="seo-content-' . esc_attr($page_id) . '"><strong>Contenido</strong></label>';
        echo '<textarea id="seo-content-' . esc_attr($page_id) . '" name="page_content" rows="10" style="width:100%;font-family:monospace;">' . esc_textarea($page->post_content) . '</textarea>';

        echo '<label for="seo-keywords-' . esc_attr($page_id) . '"><strong>Etiquetas SEO legacy</strong></label>';
        echo '<div><textarea id="seo-keywords-' . esc_attr($page_id) . '" name="seo_keywords" rows="3" style="width:100%;" placeholder="Separadas por comas">' . esc_textarea((string) $page->seo_keywords) . '</textarea>';
        echo '<div style="font-size:11px;color:#646970;margin-top:3px;">Fuente actual: <code>wp_seo_nodes.keywords</code>. No se sincroniza con <code>post_tag</code>. Se conserva como legacy hasta la futura migración al vocabulario canónico.</div></div>';
        echo '</div>';

        if ($role === 'landing') {
            seo_page_editor_render_landing_relations($page_id, $tree, $category_paths, $all_categories);
        }

        echo '</form></article>';
    }
}

if (!function_exists('seo_page_editor_render_page_list')) {
    function seo_page_editor_render_page_list($pages, $tab, $tree, $category_paths, $all_categories, $focus_page_id) {
        if (empty($pages)) {
            echo '<div class="notice notice-info inline"><p>No hay páginas registradas en este ámbito.</p></div>';
            return;
        }

        if ($focus_page_id > 0) {
            usort($pages, function($a, $b) use ($focus_page_id) {
                if ((int) $a->ID === $focus_page_id) return -1;
                if ((int) $b->ID === $focus_page_id) return 1;
                return strcasecmp((string) $a->post_title, (string) $b->post_title);
            });
        }

        foreach ($pages as $page) {
            seo_page_editor_render_page_card($page, $tab, $tree, $category_paths, $all_categories, $focus_page_id);
        }
    }
}

function seo_page_admin_callback() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $notices = array();
    seo_page_editor_process_save($notices);
    seo_page_editor_process_create($notices);

    $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'estructura';
    if (!in_array($tab, array('estructura', 'landings', 'landing-report', 'corporativas'), true)) {
        $tab = 'estructura';
    }

    $focus_page_id = isset($_GET['edit_page']) ? absint($_GET['edit_page']) : 0;
    $base_url = admin_url('admin.php?page=seo-page-admin');
    $tree = seo_page_editor_get_structure_tree();
    $category_paths = seo_page_editor_get_category_paths($tree);
    $all_categories = seo_page_editor_get_all_product_categories();

    if ($tab === 'landings') {
        $pages = seo_page_editor_get_pages_by_roles(array('landing'));
        $title = 'Landing pages';
        $description = 'Páginas comerciales/editoriales conectadas directamente con una o varias categorías WooCommerce mediante landing_to_category.';
    } elseif ($tab === 'landing-report') {
        $pages = array();
        $title = 'Informe landings';
        $description = 'Rendimiento, señales externas, candidatas y cobertura de las landing pages.';
    } elseif ($tab === 'corporativas') {
        $pages = seo_page_editor_get_pages_by_roles(array('corporate_page'));
        $title = 'Páginas corporativas';
        $description = 'Páginas corporativas registradas en seo_nodes. No requieren una relación product_cat directa.';
    } else {
        $pages = seo_page_editor_get_pages_by_roles(array('cluster', 'hub_primary', 'hub_secondary'));
        $title = 'Estructura SEO';
        $description = 'Clusters, hubs primarios y hubs secundarios que forman el árbol estructural del catálogo.';
    }

    ?>
    <div class="wrap">
        <h1>Editor de páginas SEO</h1>
        <p><?php echo esc_html($description); ?></p>

        <nav class="nav-tab-wrapper" style="margin-bottom:18px;">
            <a class="nav-tab <?php echo $tab === 'estructura' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', 'estructura', $base_url)); ?>">Estructura SEO</a>
            <a class="nav-tab <?php echo $tab === 'landings' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', 'landings', $base_url)); ?>">Landings</a>
            <a class="nav-tab <?php echo $tab === 'landing-report' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', 'landing-report', $base_url)); ?>">Informe landings</a>
            <a class="nav-tab <?php echo $tab === 'corporativas' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', 'corporativas', $base_url)); ?>">Corporativas</a>
        </nav>

        <?php foreach ($notices as $notice) { seo_page_editor_render_notice($notice); } ?>

        <?php if ($tab === 'landing-report'): ?>
            <?php if (function_exists('seo_landing_render_admin_tab')): ?>
                <?php seo_landing_render_admin_tab(); ?>
            <?php else: ?>
                <div class="notice notice-error inline"><p>No se ha podido cargar el módulo <code>seo-landing-pages.php</code>.</p></div>
            <?php endif; ?>
        </div>
        <?php return; ?>
        <?php endif; ?>

        <div style="background:#fff;border-left:4px solid #2271b1;padding:10px 14px;margin:0 0 16px;">
            <strong><?php echo esc_html($title); ?></strong> · <?php echo esc_html(number_format_i18n(count($pages))); ?> páginas.
            <?php if ($tab === 'landings'): ?>
                <br><span style="font-size:12px;color:#50575e;">La selección comercial se guarda solo en <code>wp_seo_relations</code>; las etiquetas SEO legacy se guardan solo en <code>wp_seo_nodes.keywords</code>.</span>
            <?php endif; ?>
        </div>

        <?php seo_page_editor_render_create_box($tab); ?>
        <?php seo_page_editor_render_page_list($pages, $tab, $tree, $category_paths, $all_categories, $focus_page_id); ?>
    </div>

    <script>
    (function(){
        var tree = <?php echo wp_json_encode($tree); ?> || {};
        var urls = {};
        <?php
        foreach ($tree as $cluster) {
            echo 'urls[' . wp_json_encode((string) $cluster['id']) . ']=' . wp_json_encode(get_permalink($cluster['id'])) . ';';
            foreach ($cluster['primaries'] as $primary) {
                echo 'urls[' . wp_json_encode((string) $primary['id']) . ']=' . wp_json_encode(get_permalink($primary['id'])) . ';';
                foreach ($primary['secondaries'] as $secondary) {
                    echo 'urls[' . wp_json_encode((string) $secondary['id']) . ']=' . wp_json_encode(get_permalink($secondary['id'])) . ';';
                }
            }
        }
        ?>
        var ajaxNonce = <?php echo wp_json_encode(wp_create_nonce('seo_page_editor_ajax')); ?>;

        function option(value, text) {
            var o = document.createElement('option');
            o.value = String(value || '');
            o.textContent = text || '';
            return o;
        }

        function clearSelect(select, placeholder) {
            select.innerHTML = '';
            select.appendChild(option('', placeholder));
            select.disabled = true;
        }

        function getCluster(id) {
            return tree[String(id)] || tree[id] || null;
        }

        document.querySelectorAll('.seo-landing-relations').forEach(function(box){
            var cluster = box.querySelector('.seo-rel-cluster');
            var primary = box.querySelector('.seo-rel-primary');
            var secondary = box.querySelector('.seo-rel-secondary');
            var category = box.querySelector('.seo-rel-category');
            var addStructured = box.querySelector('.seo-add-structured-category');
            var direct = box.querySelector('.seo-direct-category');
            var addDirect = box.querySelector('.seo-add-direct-category');
            var hidden = box.querySelector('.seo-selected-category-ids');
            var selectedBox = box.querySelector('.seo-selected-categories');

            function ids() {
                return (hidden.value || '').split(',').map(function(v){return parseInt(v,10);}).filter(function(v){return v>0;});
            }
            function syncHidden(values) {
                var unique = [];
                values.forEach(function(v){ if (v>0 && unique.indexOf(v) === -1) unique.push(v); });
                hidden.value = unique.join(',');
                var empty = selectedBox.querySelector('.seo-no-categories');
                if (empty && unique.length) empty.remove();
                if (!unique.length && !selectedBox.querySelector('.seo-no-categories')) {
                    var n = document.createElement('div');
                    n.className = 'seo-no-categories';
                    n.style.cssText = 'color:#b32d2e;font-size:12px;';
                    n.textContent = 'Sin categoría comercial asociada.';
                    selectedBox.appendChild(n);
                }
            }
            function addCategory(id, name, path) {
                id = parseInt(id,10);
                if (!id || ids().indexOf(id) !== -1) return;
                var values = ids(); values.push(id); syncHidden(values);
                var chip = document.createElement('span');
                chip.className = 'seo-category-chip';
                chip.dataset.categoryId = String(id);
                chip.style.cssText = 'display:inline-flex;align-items:center;gap:6px;margin:3px 6px 3px 0;padding:5px 8px;background:#fff;border:1px solid #8c8f94;border-radius:999px;font-size:12px;';
                var txt = document.createElement('span');
                var strong = document.createElement('strong'); strong.textContent = name;
                var small = document.createElement('small'); small.style.color='#646970'; small.textContent=' ID '+id+(path?' · '+path:'');
                txt.appendChild(strong); txt.appendChild(document.createTextNode(' ')); txt.appendChild(small);
                var remove = document.createElement('button'); remove.type='button'; remove.className='seo-remove-category'; remove.dataset.categoryId=String(id); remove.style.cssText='border:0;background:transparent;color:#b32d2e;cursor:pointer;font-weight:700;'; remove.textContent='×';
                chip.appendChild(txt); chip.appendChild(remove); selectedBox.appendChild(chip);
            }

            cluster.addEventListener('change', function(){
                clearSelect(primary, 'Seleccionar hub primario');
                clearSelect(secondary, 'Seleccionar hub secundario');
                clearSelect(category, 'Seleccionar categoría');
                addStructured.disabled = true;
                var c = getCluster(cluster.value); if (!c) return;
                Object.keys(c.primaries || {}).forEach(function(id){ primary.appendChild(option(id, c.primaries[id].title)); });
                primary.disabled = false;
            });
            primary.addEventListener('change', function(){
                clearSelect(secondary, 'Seleccionar hub secundario');
                clearSelect(category, 'Seleccionar categoría');
                addStructured.disabled = true;
                var c = getCluster(cluster.value); if (!c || !c.primaries[primary.value]) return;
                var p = c.primaries[primary.value];
                Object.keys(p.secondaries || {}).forEach(function(id){ secondary.appendChild(option(id, p.secondaries[id].title)); });
                secondary.disabled = false;
            });
            secondary.addEventListener('change', function(){
                clearSelect(category, 'Seleccionar categoría');
                addStructured.disabled = true;
                var c = getCluster(cluster.value); if (!c || !c.primaries[primary.value]) return;
                var p = c.primaries[primary.value]; if (!p.secondaries[secondary.value]) return;
                var s = p.secondaries[secondary.value];
                Object.keys(s.categories || {}).forEach(function(id){ category.appendChild(option(id, s.categories[id].name)); });
                category.disabled = false;
            });
            category.addEventListener('change', function(){ addStructured.disabled = !category.value; });
            addStructured.addEventListener('click', function(){
                if (!category.value) return;
                var c = getCluster(cluster.value), p = c && c.primaries[primary.value], s = p && p.secondaries[secondary.value], cat = s && s.categories[category.value];
                if (!cat) return;
                addCategory(cat.id, cat.name, c.title+' → '+p.title+' → '+s.title);
            });
            addDirect.addEventListener('click', function(){
                if (!direct.value) return;
                addCategory(direct.value, direct.options[direct.selectedIndex].text.replace(/ \(#\d+\)$/,''), 'Selección directa');
            });
            selectedBox.addEventListener('click', function(e){
                if (!e.target.classList.contains('seo-remove-category')) return;
                var id = parseInt(e.target.dataset.categoryId,10);
                syncHidden(ids().filter(function(v){return v!==id;}));
                var chip = e.target.closest('.seo-category-chip'); if (chip) chip.remove();
            });
        });

        document.querySelectorAll('.seo-redirect-controls').forEach(function(box){
            var pageId = parseInt(box.dataset.pageId,10);
            var origin = box.dataset.originUrl || '';
            var cluster = box.querySelector('.seo-redir-cluster');
            var primary = box.querySelector('.seo-redir-primary');
            var secondary = box.querySelector('.seo-redir-secondary');
            var unlink = box.querySelector('.seo-redir-unlink');
            var del = box.querySelector('.seo-redir-delete');

            function targetId(){ return secondary.value || primary.value || cluster.value || ''; }
            function updateButtons(){
                var id = parseInt(targetId(),10);
                var enabled = id > 0 && id !== pageId && !!urls[String(id)];
                unlink.disabled = !enabled; del.disabled = !enabled;
            }
            cluster.addEventListener('change', function(){
                clearSelect(primary, 'Seleccionar hub primario'); clearSelect(secondary, 'Seleccionar hub secundario');
                var c = getCluster(cluster.value); if (c) { Object.keys(c.primaries||{}).forEach(function(id){primary.appendChild(option(id,c.primaries[id].title));}); primary.disabled=false; }
                updateButtons();
            });
            primary.addEventListener('change', function(){
                clearSelect(secondary, 'Seleccionar hub secundario');
                var c=getCluster(cluster.value), p=c&&c.primaries[primary.value]; if(p){Object.keys(p.secondaries||{}).forEach(function(id){secondary.appendChild(option(id,p.secondaries[id].title));});secondary.disabled=false;}
                updateButtons();
            });
            secondary.addEventListener('change', updateButtons);

            function run(action){
                var id = parseInt(targetId(),10), target = urls[String(id)] || '';
                if (!target || id === pageId) return;
                var warning = action === 'seo_borrar_y_redirigir_pagina'
                    ? 'Se borrará definitivamente la página de WordPress y se creará una redirección 301. ¿Continuar?'
                    : 'Se eliminarán sus relaciones estructurales y se creará una redirección 301. ¿Continuar?';
                if (!window.confirm(warning)) return;
                var data = new URLSearchParams();
                data.append('action', action); data.append('nonce', ajaxNonce); data.append('page_id', String(pageId)); data.append('url_origen', origin); data.append('url_destino', target);
                fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:data.toString()})
                    .then(function(r){return r.json();})
                    .then(function(r){ if(r.success){window.location.reload();}else{alert((r.data&&r.data.message)||'Error');} })
                    .catch(function(){alert('Error de red.');});
            }
            unlink.addEventListener('click', function(){run('seo_solo_desvincular_pagina');});
            del.addEventListener('click', function(){run('seo_borrar_y_redirigir_pagina');});
        });

        var focus = <?php echo (int) $focus_page_id; ?>;
        if (focus > 0) {
            var el = document.getElementById('seo-page-' + focus);
            if (el) { setTimeout(function(){ el.scrollIntoView({behavior:'smooth', block:'start'}); }, 120); }
        }
    })();
    </script>
    <?php
}

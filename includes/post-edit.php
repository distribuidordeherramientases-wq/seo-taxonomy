<?php
/**
 * Editor unitario de entradas de WordPress con relaciones comerciales
 * post -> product_cat almacenadas en wp_seo_relations.
 *
 * Puede usarse como pagina independiente (Entradas > Editor SEO posts) o
 * llamar directamente a seo_page_edit_posts() desde otro router/tabs del plugin.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_post_editor_allowed_statuses')) {
    function seo_post_editor_allowed_statuses() {
        return array('publish', 'draft', 'pending', 'private');
    }
}

if (!function_exists('seo_post_editor_relations_table')) {
    function seo_post_editor_relations_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_relations';
    }
}

if (!function_exists('seo_post_editor_relations_table_exists')) {
    function seo_post_editor_relations_table_exists() {
        global $wpdb;

        $table = seo_post_editor_relations_table();
        static $cache = array();

        if (!array_key_exists($table, $cache)) {
            $cache[$table] = ($table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
        }

        return $cache[$table];
    }
}

if (!function_exists('seo_post_editor_route_context')) {
    function seo_post_editor_route_context() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'seo-post-editor';
        $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

        if ($page === '') {
            $page = 'seo-post-editor';
        }

        global $pagenow;
        $base = ($pagenow === 'edit.php') ? 'edit.php' : 'admin.php';

        return array(
            'page' => $page,
            'tab'  => $tab,
            'base' => $base,
        );
    }
}

if (!function_exists('seo_post_editor_admin_url')) {
    function seo_post_editor_admin_url($extra = array(), $context = null) {
        if (!is_array($context)) {
            $context = seo_post_editor_route_context();
        }

        $args = array('page' => $context['page']);
        if (!empty($context['tab'])) {
            $args['tab'] = $context['tab'];
        }

        $base = !empty($context['base']) && $context['base'] === 'edit.php' ? 'edit.php' : 'admin.php';
        return add_query_arg(array_merge($args, (array) $extra), admin_url($base));
    }
}

if (!function_exists('seo_post_editor_redirect_url_from_request')) {
    function seo_post_editor_redirect_url_from_request($extra = array()) {
        $page = isset($_POST['return_page']) ? sanitize_key(wp_unslash($_POST['return_page'])) : 'seo-post-editor';
        $tab  = isset($_POST['return_tab']) ? sanitize_key(wp_unslash($_POST['return_tab'])) : '';
        $base = isset($_POST['return_base']) && wp_unslash($_POST['return_base']) === 'edit.php' ? 'edit.php' : 'admin.php';

        if ($page === '') {
            $page = 'seo-post-editor';
        }

        $args = array('page' => $page);
        if ($tab !== '') {
            $args['tab'] = $tab;
        }

        return add_query_arg(array_merge($args, (array) $extra), admin_url($base));
    }
}

if (!function_exists('seo_post_editor_get_product_cat_ids')) {
    function seo_post_editor_get_product_cat_ids($post_id) {
        global $wpdb;

        $post_id = absint($post_id);
        if ($post_id <= 0 || !seo_post_editor_relations_table_exists()) {
            return array();
        }

        $table = seo_post_editor_relations_table();
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$table}
                 WHERE source_type = 'post'
                   AND source_id = %d
                   AND target_type = 'product_cat'
                   AND relation_type = 'post_to_category'
                 ORDER BY target_id ASC",
                $post_id
            )
        );

        return array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
    }
}

if (!function_exists('seo_post_editor_validate_product_cat_ids')) {
    function seo_post_editor_validate_product_cat_ids($term_ids) {
        $term_ids = array_values(array_unique(array_filter(array_map('absint', (array) $term_ids))));
        $valid = array();

        foreach ($term_ids as $term_id) {
            $term = get_term($term_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $valid[] = $term_id;
            }
        }

        return $valid;
    }
}

if (!function_exists('seo_post_editor_replace_product_cat_relations')) {
    function seo_post_editor_replace_product_cat_relations($post_id, $term_ids) {
        global $wpdb;

        $post_id = absint($post_id);
        if ($post_id <= 0 || get_post_type($post_id) !== 'post') {
            return new WP_Error('seo_post_editor_invalid_post', 'La entrada indicada no es valida.');
        }

        if (!seo_post_editor_relations_table_exists()) {
            return new WP_Error('seo_post_editor_relations_missing', 'No existe la tabla seo_relations.');
        }

        $requested = array_values(array_unique(array_filter(array_map('absint', (array) $term_ids))));
        $valid = seo_post_editor_validate_product_cat_ids($requested);

        if (count($requested) !== count($valid)) {
            return new WP_Error('seo_post_editor_invalid_category', 'Alguna categoria de producto seleccionada ya no existe.');
        }

        if (function_exists('seo_ie_replace_product_cat_relations')) {
            return seo_ie_replace_product_cat_relations('post', $post_id, $valid);
        }

        $table = seo_post_editor_relations_table();
        $failed = false;

        $wpdb->query('START TRANSACTION');

        $deleted = $wpdb->delete(
            $table,
            array(
                'source_type'   => 'post',
                'source_id'     => $post_id,
                'target_type'   => 'product_cat',
                'relation_type' => 'post_to_category',
            ),
            array('%s', '%d', '%s', '%s')
        );

        if ($deleted === false) {
            $failed = true;
        }

        if (!$failed) {
            foreach ($valid as $term_id) {
                $inserted = $wpdb->insert(
                    $table,
                    array(
                        'source_type'   => 'post',
                        'source_id'     => $post_id,
                        'target_type'   => 'product_cat',
                        'target_id'     => $term_id,
                        'relation_type' => 'post_to_category',
                        'created_at'    => current_time('mysql'),
                    ),
                    array('%s', '%d', '%s', '%d', '%s', '%s')
                );

                if ($inserted === false) {
                    $failed = true;
                    break;
                }
            }
        }

        if ($failed) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('seo_post_editor_relation_write', 'No se pudieron guardar las relaciones con categorias de producto.');
        }

        $wpdb->query('COMMIT');
        return true;
    }
}

if (!function_exists('seo_post_editor_delete_relations')) {
    function seo_post_editor_delete_relations($post_id) {
        global $wpdb;

        $post_id = absint($post_id);
        if ($post_id <= 0 || !seo_post_editor_relations_table_exists()) {
            return true;
        }

        $deleted = $wpdb->delete(
            seo_post_editor_relations_table(),
            array(
                'source_type'   => 'post',
                'source_id'     => $post_id,
                'target_type'   => 'product_cat',
                'relation_type' => 'post_to_category',
            ),
            array('%s', '%d', '%s', '%s')
        );

        return $deleted !== false;
    }
}

if (!function_exists('seo_post_editor_get_relation_map')) {
    function seo_post_editor_get_relation_map($post_ids) {
        global $wpdb;

        $post_ids = array_values(array_unique(array_filter(array_map('absint', (array) $post_ids))));
        $map = array();

        foreach ($post_ids as $post_id) {
            $map[$post_id] = array();
        }

        if (empty($post_ids) || !seo_post_editor_relations_table_exists()) {
            return $map;
        }

        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $table = seo_post_editor_relations_table();
        $sql = "SELECT r.source_id, r.target_id, t.name
                FROM {$table} r
                INNER JOIN {$wpdb->terms} t ON t.term_id = r.target_id
                INNER JOIN {$wpdb->term_taxonomy} tt
                    ON tt.term_id = r.target_id
                   AND tt.taxonomy = 'product_cat'
                WHERE r.source_type = 'post'
                  AND r.target_type = 'product_cat'
                  AND r.relation_type = 'post_to_category'
                  AND r.source_id IN ({$placeholders})
                ORDER BY t.name ASC, r.target_id ASC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $post_ids));

        foreach ((array) $rows as $row) {
            $source_id = absint($row->source_id ?? 0);
            if ($source_id <= 0 || !isset($map[$source_id])) {
                continue;
            }

            $map[$source_id][] = array(
                'id'   => absint($row->target_id ?? 0),
                'name' => (string) ($row->name ?? ''),
            );
        }

        return $map;
    }
}

if (!function_exists('seo_post_editor_get_ids_for_relation_filter')) {
    function seo_post_editor_get_ids_for_relation_filter($filter) {
        global $wpdb;

        if (!seo_post_editor_relations_table_exists()) {
            return array(0);
        }

        $table = seo_post_editor_relations_table();

        if ($filter === 'none') {
            return array_values(array_unique(array_filter(array_map(
                'absint',
                (array) $wpdb->get_col(
                    "SELECT p.ID
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$table} r
                       ON r.source_id = p.ID
                      AND r.source_type = 'post'
                      AND r.target_type = 'product_cat'
                      AND r.relation_type = 'post_to_category'
                     WHERE p.post_type = 'post'
                       AND r.source_id IS NULL"
                )
            ))));
        }

        $term_id = absint($filter);
        if ($term_id <= 0) {
            return array();
        }

        return array_values(array_unique(array_filter(array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT source_id
                     FROM {$table}
                     WHERE source_type = 'post'
                       AND target_type = 'product_cat'
                       AND relation_type = 'post_to_category'
                       AND target_id = %d",
                    $term_id
                )
            )
        ))));
    }
}

if (!function_exists('seo_post_editor_group_terms_by_parent')) {
    function seo_post_editor_group_terms_by_parent($terms) {
        $grouped = array();
        foreach ((array) $terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }
            $parent = absint($term->parent);
            if (!isset($grouped[$parent])) {
                $grouped[$parent] = array();
            }
            $grouped[$parent][] = $term;
        }

        foreach ($grouped as &$children) {
            usort($children, static function ($a, $b) {
                return strcasecmp((string) $a->name, (string) $b->name);
            });
        }
        unset($children);

        return $grouped;
    }
}

if (!function_exists('seo_post_editor_category_option_tree')) {
    function seo_post_editor_category_option_tree($terms, $selected = '', $parent = 0, $depth = 0, $grouped = null) {
        if ($grouped === null) {
            $grouped = seo_post_editor_group_terms_by_parent($terms);
        }

        if (empty($grouped[$parent])) {
            return;
        }

        foreach ($grouped[$parent] as $term) {
            $label = str_repeat('— ', $depth) . $term->name;
            echo '<option value="' . esc_attr($term->term_id) . '" ' . selected((string) $selected, (string) $term->term_id, false) . '>' . esc_html($label) . '</option>';
            seo_post_editor_category_option_tree($terms, $selected, $term->term_id, $depth + 1, $grouped);
        }
    }
}

if (!function_exists('seo_post_editor_category_checkbox_tree')) {
    function seo_post_editor_category_checkbox_tree($terms, $selected_ids, $parent = 0, $depth = 0, $grouped = null) {
        if ($grouped === null) {
            $grouped = seo_post_editor_group_terms_by_parent($terms);
        }

        if (empty($grouped[$parent])) {
            return;
        }

        $selected_ids = array_map('absint', (array) $selected_ids);

        foreach ($grouped[$parent] as $term) {
            $term_id = absint($term->term_id);
            $checked = in_array($term_id, $selected_ids, true);
            $search  = function_exists('mb_strtolower') ? mb_strtolower((string) $term->name) : strtolower((string) $term->name);

            echo '<label class="seo-post-product-cat-item" data-search="' . esc_attr($search) . '" style="display:block;padding:4px 6px 4px ' . esc_attr(8 + ($depth * 20)) . 'px;border-radius:4px;">';
            echo '<input type="checkbox" name="product_cat_ids[]" value="' . esc_attr($term_id) . '" ' . checked($checked, true, false) . '> ';
            echo '<span>' . esc_html($term->name) . '</span> ';
            echo '<code style="font-size:11px;opacity:.65;">' . esc_html($term_id) . '</code>';
            echo '</label>';

            seo_post_editor_category_checkbox_tree($terms, $selected_ids, $term_id, $depth + 1, $grouped);
        }
    }
}

if (!function_exists('seo_post_editor_sanitize_content')) {
    function seo_post_editor_sanitize_content($raw) {
        $content = wp_unslash((string) $raw);
        return current_user_can('unfiltered_html') ? $content : wp_kses_post($content);
    }
}

if (!function_exists('seo_post_editor_handle_save')) {
    function seo_post_editor_handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para editar entradas.', 'seo-system'));
        }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        check_admin_referer('seo_post_editor_save_' . $post_id, 'seo_post_editor_nonce');

        if ($post_id > 0 && get_post_type($post_id) !== 'post') {
            wp_die(esc_html__('La entrada indicada no existe.', 'seo-system'));
        }

        if ($post_id > 0 && !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('No tienes permisos para editar esta entrada.', 'seo-system'));
        }

        if (!seo_post_editor_relations_table_exists()) {
            wp_safe_redirect(seo_post_editor_redirect_url_from_request(array(
                'post_id' => $post_id,
                'seo_post_msg' => 'relations_missing',
            )));
            exit;
        }

        $title   = isset($_POST['post_title']) ? sanitize_text_field(wp_unslash($_POST['post_title'])) : '';
        $slug    = isset($_POST['post_name']) ? sanitize_title(wp_unslash($_POST['post_name'])) : '';
        $status  = isset($_POST['post_status']) ? sanitize_key(wp_unslash($_POST['post_status'])) : 'draft';
        $excerpt = isset($_POST['post_excerpt']) ? seo_post_editor_sanitize_content($_POST['post_excerpt']) : '';
        $content = isset($_POST['post_content']) ? seo_post_editor_sanitize_content($_POST['post_content']) : '';
        $tags    = isset($_POST['post_tags']) ? sanitize_text_field(wp_unslash($_POST['post_tags'])) : '';

        if ($title === '') {
            wp_safe_redirect(seo_post_editor_redirect_url_from_request(array(
                'post_id' => $post_id,
                'new_post' => $post_id > 0 ? null : 1,
                'seo_post_msg' => 'title_required',
            )));
            exit;
        }

        if (!in_array($status, seo_post_editor_allowed_statuses(), true)) {
            $status = 'draft';
        }

        $product_cat_ids = isset($_POST['product_cat_ids'])
            ? (array) wp_unslash($_POST['product_cat_ids'])
            : array();
        $product_cat_ids = array_values(array_unique(array_filter(array_map('absint', $product_cat_ids))));
        $valid_product_cat_ids = seo_post_editor_validate_product_cat_ids($product_cat_ids);

        if (count($product_cat_ids) !== count($valid_product_cat_ids)) {
            wp_safe_redirect(seo_post_editor_redirect_url_from_request(array(
                'post_id' => $post_id,
                'new_post' => $post_id > 0 ? null : 1,
                'seo_post_msg' => 'invalid_category',
            )));
            exit;
        }

        $creating = ($post_id <= 0);
        $post_data = array(
            'post_type'    => 'post',
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_status'  => $status,
            'post_excerpt' => $excerpt,
            'post_content' => $content,
        );

        if (!$creating) {
            $post_data['ID'] = $post_id;
        }

        $saved_id = $creating
            ? wp_insert_post(wp_slash($post_data), true)
            : wp_update_post(wp_slash($post_data), true);

        if (is_wp_error($saved_id) || absint($saved_id) <= 0) {
            wp_safe_redirect(seo_post_editor_redirect_url_from_request(array(
                'post_id' => $post_id,
                'new_post' => $creating ? 1 : null,
                'seo_post_msg' => 'save_error',
            )));
            exit;
        }

        $post_id = absint($saved_id);

        $tag_result = wp_set_post_tags($post_id, $tags, false);
        if (is_wp_error($tag_result)) {
            wp_safe_redirect(seo_post_editor_redirect_url_from_request(array(
                'post_id' => $post_id,
                'seo_post_msg' => 'tag_error',
            )));
            exit;
        }

        $relation_result = seo_post_editor_replace_product_cat_relations($post_id, $valid_product_cat_ids);
        if (is_wp_error($relation_result)) {
            wp_safe_redirect(seo_post_editor_redirect_url_from_request(array(
                'post_id' => $post_id,
                'seo_post_msg' => 'relation_error',
            )));
            exit;
        }

        clean_post_cache($post_id);

        wp_safe_redirect(seo_post_editor_redirect_url_from_request(array(
            'post_id' => $post_id,
            'seo_post_msg' => $creating ? 'created' : 'saved',
        )));
        exit;
    }
}

if (!function_exists('seo_post_editor_handle_trash')) {
    function seo_post_editor_handle_trash() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para enviar entradas a la papelera.', 'seo-system'));
        }

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        check_admin_referer('seo_post_editor_trash_' . $post_id, 'seo_post_editor_trash_nonce');

        if ($post_id <= 0 || get_post_type($post_id) !== 'post' || !current_user_can('delete_post', $post_id)) {
            wp_die(esc_html__('La entrada indicada no se puede eliminar.', 'seo-system'));
        }

        $trashed = wp_trash_post($post_id);
        if (!$trashed) {
            wp_safe_redirect(seo_post_editor_redirect_url_from_request(array('seo_post_msg' => 'trash_error')));
            exit;
        }

        $relations_deleted = seo_post_editor_delete_relations($post_id);

        wp_safe_redirect(seo_post_editor_redirect_url_from_request(array(
            'seo_post_msg' => $relations_deleted ? 'trashed' : 'trash_relation_error',
        )));
        exit;
    }
}

if (!function_exists('seo_post_editor_cleanup_relations_on_post_delete')) {
    function seo_post_editor_cleanup_relations_on_post_delete($post_id) {
        $post_id = absint($post_id);
        if ($post_id > 0 && get_post_type($post_id) === 'post') {
            seo_post_editor_delete_relations($post_id);
        }
    }
}

add_action('admin_post_seo_post_editor_save', 'seo_post_editor_handle_save');
add_action('admin_post_seo_post_editor_trash', 'seo_post_editor_handle_trash');
add_action('trashed_post', 'seo_post_editor_cleanup_relations_on_post_delete', 10, 1);
add_action('before_delete_post', 'seo_post_editor_cleanup_relations_on_post_delete', 10, 1);

if (!function_exists('seo_post_editor_register_admin_page')) {
    function seo_post_editor_register_admin_page() {
        if (!apply_filters('seo_post_editor_register_submenu', true)) {
            return;
        }

        add_submenu_page(
            'edit.php',
            'Editor SEO de posts',
            'Editor SEO posts',
            'manage_options',
            'seo-post-editor',
            'seo_page_edit_posts'
        );
    }
}
add_action('admin_menu', 'seo_post_editor_register_admin_page');

if (!function_exists('seo_page_edit_posts')) {
    function seo_page_edit_posts() {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            return;
        }

        $context = seo_post_editor_route_context();
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $new_post = !empty($_GET['new_post']);
        $message = isset($_GET['seo_post_msg']) ? sanitize_key(wp_unslash($_GET['seo_post_msg'])) : '';

        $notice_messages = array(
            'created'              => array('success', 'Entrada creada y relaciones SEO guardadas.'),
            'saved'                => array('success', 'Entrada actualizada y relaciones SEO guardadas.'),
            'trashed'              => array('success', 'Entrada enviada a la papelera y relaciones SEO eliminadas.'),
            'title_required'       => array('error', 'El titulo es obligatorio.'),
            'invalid_category'     => array('error', 'Alguna categoria de producto seleccionada ya no existe.'),
            'relations_missing'    => array('error', 'No existe la tabla seo_relations. No se ha guardado la entrada.'),
            'save_error'           => array('error', 'WordPress no pudo guardar la entrada.'),
            'tag_error'            => array('error', 'La entrada se guardo, pero WordPress no pudo actualizar las etiquetas.'),
            'relation_error'       => array('error', 'La entrada se guardo, pero fallo la escritura en SEO Relations. Revisa la relacion antes de continuar.'),
            'trash_error'          => array('error', 'WordPress no pudo enviar la entrada a la papelera.'),
            'trash_relation_error' => array('error', 'La entrada esta en la papelera, pero no se pudieron limpiar sus relaciones SEO.'),
        );

        if ($post_id > 0 || $new_post) {
            $post = null;
            if ($post_id > 0) {
                $post = get_post($post_id);
                if (!$post || $post->post_type !== 'post') {
                    echo '<div class="notice notice-error"><p>La entrada solicitada no existe.</p></div>';
                    return;
                }
            }

            $creating = ($post_id <= 0);
            $selected_product_cats = $creating ? array() : seo_post_editor_get_product_cat_ids($post_id);
            $categories = get_terms(array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
            ));
            if (is_wp_error($categories)) {
                $categories = array();
            }

            $tag_names = array();
            if (!$creating) {
                $tag_names = wp_get_post_terms($post_id, 'post_tag', array('fields' => 'names'));
                if (is_wp_error($tag_names)) {
                    $tag_names = array();
                }
            }

            $title   = $creating ? '' : (string) $post->post_title;
            $slug    = $creating ? '' : (string) $post->post_name;
            $status  = $creating ? 'draft' : (string) $post->post_status;
            $excerpt = $creating ? '' : (string) $post->post_excerpt;
            $content = $creating ? '' : (string) $post->post_content;
            $tags    = implode(', ', array_map('strval', (array) $tag_names));

            echo '<div style="max-width:1180px;padding:10px 0 30px;">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:15px;flex-wrap:wrap;">';
            echo '<div style="display:flex;align-items:center;gap:12px;">';
            echo '<a class="button" href="' . esc_url(seo_post_editor_admin_url(array(), $context)) . '">← Volver a seleccion</a>';
            echo '<h1 style="margin:0;">' . ($creating ? 'Nueva entrada' : 'Editar entrada') . '</h1>';
            echo '</div>';
            if (!$creating && get_permalink($post_id)) {
                echo '<a class="button" href="' . esc_url(get_permalink($post_id)) . '" target="_blank" rel="noopener">Ver entrada</a>';
            }
            echo '</div>';

            if ($message !== '' && isset($notice_messages[$message])) {
                $notice = $notice_messages[$message];
                echo '<div class="notice notice-' . esc_attr($notice[0]) . ' inline"><p>' . esc_html($notice[1]) . '</p></div>';
            }

            if (!seo_post_editor_relations_table_exists()) {
                echo '<div class="notice notice-error inline"><p><strong>SEO Relations no esta disponible.</strong> El formulario queda visible, pero el guardado esta bloqueado para evitar una entrada sin relacion consistente.</p></div>';
            }
            ?>

            <?php wp_enqueue_media(); ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:18px;">
                <input type="hidden" name="action" value="seo_post_editor_save">
                <input type="hidden" name="post_id" value="<?php echo absint($post_id); ?>">
                <input type="hidden" name="return_page" value="<?php echo esc_attr($context['page']); ?>">
                <input type="hidden" name="return_tab" value="<?php echo esc_attr($context['tab']); ?>">
                <input type="hidden" name="return_base" value="<?php echo esc_attr($context['base']); ?>">
                <?php wp_nonce_field('seo_post_editor_save_' . absint($post_id), 'seo_post_editor_nonce'); ?>

                <div class="seo-post-editor-layout" style="display:grid;grid-template-columns:minmax(0,2fr) minmax(300px,.85fr);gap:18px;align-items:start;">
                    <div style="display:grid;gap:18px;">
                        <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;">
                            <h2 style="margin-top:0;">Datos de WordPress</h2>

                            <div style="margin-bottom:14px;">
                                <label for="seo-post-title" style="display:block;font-weight:600;margin-bottom:5px;">Titulo</label>
                                <input id="seo-post-title" type="text" name="post_title" value="<?php echo esc_attr($title); ?>" required style="width:100%;font-size:16px;">
                            </div>

                            <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:14px;">
                                <div>
                                    <label for="seo-post-slug" style="display:block;font-weight:600;margin-bottom:5px;">Slug</label>
                                    <input id="seo-post-slug" type="text" name="post_name" value="<?php echo esc_attr($slug); ?>" style="width:100%;">
                                </div>
                                <div>
                                    <label for="seo-post-status" style="display:block;font-weight:600;margin-bottom:5px;">Estado</label>
                                    <select id="seo-post-status" name="post_status" style="width:100%;">
                                        <option value="publish" <?php selected($status, 'publish'); ?>>Publicado</option>
                                        <option value="draft" <?php selected($status, 'draft'); ?>>Borrador</option>
                                        <option value="pending" <?php selected($status, 'pending'); ?>>Pendiente</option>
                                        <option value="private" <?php selected($status, 'private'); ?>>Privado</option>
                                    </select>
                                </div>
                            </div>

                            <div style="margin-bottom:14px;">
                                <label for="seo-post-excerpt" style="display:block;font-weight:600;margin-bottom:5px;">Excerpt</label>
                                <textarea id="seo-post-excerpt" name="post_excerpt" rows="5" style="width:100%;"><?php echo esc_textarea($excerpt); ?></textarea>
                            </div>

                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:5px;">Contenido</label>
                                <?php
                                wp_editor(
                                    $content,
                                    'seo_post_editor_content',
                                    array(
                                        'textarea_name' => 'post_content',
                                        'textarea_rows' => 18,
                                        'media_buttons' => true,
                                        'teeny'         => false,
                                    )
                                );
                                ?>
                            </div>
                        </div>
                    </div>

                    <div style="display:grid;gap:18px;position:sticky;top:46px;">
                        <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;">
                            <h2 style="margin-top:0;">Categorias de producto</h2>
                            <p style="margin-top:-4px;color:#646970;line-height:1.5;">Relacion comercial del post. Se guarda en <code>seo_relations</code> como <code>post_to_category</code>; no modifica la taxonomia editorial <code>category</code> de WordPress.</p>

                            <input id="seo-post-product-cat-search" type="search" placeholder="Filtrar categorias..." style="width:100%;margin-bottom:10px;">
                            <div id="seo-post-product-cat-list" style="max-height:390px;overflow:auto;border:1px solid #dcdcde;border-radius:6px;padding:6px;background:#fff;">
                                <?php if (empty($categories)): ?>
                                    <p style="margin:8px;color:#646970;">No hay categorias de producto disponibles.</p>
                                <?php else: ?>
                                    <?php seo_post_editor_category_checkbox_tree($categories, $selected_product_cats); ?>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex;gap:8px;margin-top:8px;">
                                <button type="button" class="button button-small" id="seo-post-product-cat-all">Marcar visibles</button>
                                <button type="button" class="button button-small" id="seo-post-product-cat-none">Desmarcar visibles</button>
                            </div>
                        </div>

                        <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;">
                            <h2 style="margin-top:0;">Etiquetas</h2>
                            <label for="seo-post-tags" style="display:block;font-weight:600;margin-bottom:5px;">Etiquetas de WordPress</label>
                            <input id="seo-post-tags" type="text" name="post_tags" value="<?php echo esc_attr($tags); ?>" placeholder="taladros, guias, mantenimiento" style="width:100%;">
                            <p style="color:#646970;margin-bottom:0;">Separadas por comas.</p>
                        </div>

                        <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;">
                            <button type="submit" class="button button-primary button-large" style="width:100%;" <?php disabled(!seo_post_editor_relations_table_exists()); ?>><?php echo $creating ? 'Crear entrada' : 'Guardar cambios'; ?></button>
                            <?php if (!$creating): ?>
                                <p style="margin:12px 0 0;color:#646970;">ID: <code><?php echo absint($post_id); ?></code></p>
                                <p style="margin:4px 0 0;color:#646970;">Modificada: <?php echo esc_html(get_post_modified_time('d/m/Y H:i', false, $post_id)); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>

            <?php if (!$creating): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:18px;padding-top:18px;border-top:1px solid #dcdcde;" onsubmit="return confirm('La entrada se enviara a la papelera y se eliminaran sus relaciones con categorias de producto en SEO Relations. ¿Continuar?');">
                    <input type="hidden" name="action" value="seo_post_editor_trash">
                    <input type="hidden" name="post_id" value="<?php echo absint($post_id); ?>">
                    <input type="hidden" name="return_page" value="<?php echo esc_attr($context['page']); ?>">
                    <input type="hidden" name="return_tab" value="<?php echo esc_attr($context['tab']); ?>">
                    <input type="hidden" name="return_base" value="<?php echo esc_attr($context['base']); ?>">
                    <?php wp_nonce_field('seo_post_editor_trash_' . absint($post_id), 'seo_post_editor_trash_nonce'); ?>
                    <button type="submit" class="button" style="color:#b32d2e;border-color:#b32d2e;">Enviar a la papelera</button>
                </form>
            <?php endif; ?>

            <script>
            (function () {
                var search = document.getElementById('seo-post-product-cat-search');
                var list = document.getElementById('seo-post-product-cat-list');
                var allButton = document.getElementById('seo-post-product-cat-all');
                var noneButton = document.getElementById('seo-post-product-cat-none');
                if (!search || !list) return;

                function normalize(value) {
                    value = (value || '').toLowerCase();
                    if (value.normalize) {
                        value = value.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                    }
                    return value;
                }

                function visibleItems() {
                    return Array.prototype.filter.call(list.querySelectorAll('.seo-post-product-cat-item'), function (item) {
                        return item.style.display !== 'none';
                    });
                }

                search.addEventListener('input', function () {
                    var needle = normalize(search.value);
                    Array.prototype.forEach.call(list.querySelectorAll('.seo-post-product-cat-item'), function (item) {
                        var haystack = normalize(item.getAttribute('data-search'));
                        item.style.display = !needle || haystack.indexOf(needle) !== -1 ? 'block' : 'none';
                    });
                });

                if (allButton) {
                    allButton.addEventListener('click', function () {
                        visibleItems().forEach(function (item) {
                            var checkbox = item.querySelector('input[type="checkbox"]');
                            if (checkbox) checkbox.checked = true;
                        });
                    });
                }

                if (noneButton) {
                    noneButton.addEventListener('click', function () {
                        visibleItems().forEach(function (item) {
                            var checkbox = item.querySelector('input[type="checkbox"]');
                            if (checkbox) checkbox.checked = false;
                        });
                    });
                }
            })();
            </script>

            <style>
                @media (max-width: 900px) {
                    .seo-post-editor-layout { grid-template-columns:1fr !important; }
                }
            </style>
            <?php
            echo '</div>';
            return;
        }

        $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $category_filter = isset($_GET['product_cat']) ? sanitize_text_field(wp_unslash($_GET['product_cat'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $paged  = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 40;

        if ($status !== '' && !in_array($status, seo_post_editor_allowed_statuses(), true)) {
            $status = '';
        }

        if ($category_filter !== '' && $category_filter !== 'none' && absint($category_filter) <= 0) {
            $category_filter = '';
        }

        $categories = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ));
        if (is_wp_error($categories)) {
            $categories = array();
        }

        $args = array(
            'post_type'      => 'post',
            'post_status'    => $status !== '' ? array($status) : seo_post_editor_allowed_statuses(),
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        );

        if ($search !== '') {
            if (ctype_digit($search) && get_post_type(absint($search)) === 'post') {
                $args['p'] = absint($search);
            } else {
                $args['s'] = $search;
            }
        }

        if ($category_filter !== '') {
            $relation_ids = seo_post_editor_get_ids_for_relation_filter($category_filter);
            $args['post__in'] = !empty($relation_ids) ? $relation_ids : array(0);
        }

        $query = new WP_Query($args);
        $post_ids = wp_list_pluck($query->posts, 'ID');
        $relation_map = seo_post_editor_get_relation_map($post_ids);

        echo '<div style="padding:10px 0 30px;max-width:1280px;">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">';
        echo '<div>';
        echo '<h1 style="margin-bottom:8px;">Editar posts</h1>';
        echo '<p style="margin-top:0;color:#646970;">Selecciona una entrada para editarla. La categoria de producto se gestiona exclusivamente mediante SEO Relations.</p>';
        echo '</div>';
        echo '<a class="button button-primary" href="' . esc_url(seo_post_editor_admin_url(array('new_post' => 1), $context)) . '">Nueva entrada</a>';
        echo '</div>';

        if ($message !== '' && isset($notice_messages[$message])) {
            $notice = $notice_messages[$message];
            echo '<div class="notice notice-' . esc_attr($notice[0]) . ' inline"><p>' . esc_html($notice[1]) . '</p></div>';
        }

        if (!seo_post_editor_relations_table_exists()) {
            echo '<div class="notice notice-error inline"><p>No existe la tabla <code>seo_relations</code>. El listado se muestra, pero los filtros y guardados de categorias de producto no estaran disponibles.</p></div>';
        }
        ?>

        <form method="get" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:18px 0;display:grid;grid-template-columns:2fr 1.35fr 1fr auto;gap:12px;align-items:end;">
            <input type="hidden" name="page" value="<?php echo esc_attr($context['page']); ?>">
            <?php if (!empty($context['tab'])): ?>
                <input type="hidden" name="tab" value="<?php echo esc_attr($context['tab']); ?>">
            <?php endif; ?>

            <div>
                <label style="display:block;font-weight:600;margin-bottom:5px;">Buscar</label>
                <input type="text" name="q" value="<?php echo esc_attr($search); ?>" placeholder="Titulo, contenido o ID" style="width:100%;">
            </div>

            <div>
                <label style="display:block;font-weight:600;margin-bottom:5px;">Categoria de producto</label>
                <select name="product_cat" style="width:100%;">
                    <option value="">Todas</option>
                    <option value="none" <?php selected($category_filter, 'none'); ?>>Sin categoria de producto</option>
                    <?php seo_post_editor_category_option_tree($categories, $category_filter); ?>
                </select>
            </div>

            <div>
                <label style="display:block;font-weight:600;margin-bottom:5px;">Estado</label>
                <select name="status" style="width:100%;">
                    <option value="">Todos</option>
                    <option value="publish" <?php selected($status, 'publish'); ?>>Publicado</option>
                    <option value="draft" <?php selected($status, 'draft'); ?>>Borrador</option>
                    <option value="pending" <?php selected($status, 'pending'); ?>>Pendiente</option>
                    <option value="private" <?php selected($status, 'private'); ?>>Privado</option>
                </select>
            </div>

            <div style="display:flex;gap:6px;">
                <button class="button button-primary" type="submit">Filtrar</button>
                <a class="button" href="<?php echo esc_url(seo_post_editor_admin_url(array(), $context)); ?>">Limpiar</a>
            </div>
        </form>

        <table class="widefat striped" style="margin-top:15px;">
            <thead>
                <tr>
                    <th style="width:70px;">ID</th>
                    <th>Entrada</th>
                    <th style="width:290px;">Categorias de producto</th>
                    <th style="width:220px;">Etiquetas</th>
                    <th style="width:110px;">Estado</th>
                    <th style="width:145px;">Modificada</th>
                    <th style="width:90px;">Accion</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$query->have_posts()): ?>
                    <tr><td colspan="7">No se han encontrado entradas con estos filtros.</td></tr>
                <?php else: ?>
                    <?php foreach ($query->posts as $post): ?>
                        <?php
                        $post_id = absint($post->ID);
                        $related = isset($relation_map[$post_id]) ? $relation_map[$post_id] : array();
                        $related_names = wp_list_pluck($related, 'name');
                        $row_tags = wp_get_post_terms($post_id, 'post_tag', array('fields' => 'names'));
                        if (is_wp_error($row_tags)) {
                            $row_tags = array();
                        }
                        $edit_url = seo_post_editor_admin_url(array('post_id' => $post_id), $context);
                        ?>
                        <tr>
                            <td><?php echo absint($post_id); ?></td>
                            <td>
                                <strong><?php echo esc_html($post->post_title ?: '(Sin titulo)'); ?></strong>
                                <?php if ($post->post_name): ?>
                                    <div style="color:#646970;font-size:12px;margin-top:3px;"><code><?php echo esc_html($post->post_name); ?></code></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($related_names)): ?>
                                    <span style="color:#b32d2e;">Sin relacion</span>
                                <?php else: ?>
                                    <?php echo esc_html(implode(' · ', $related_names)); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(!empty($row_tags) ? implode(', ', $row_tags) : '—'); ?></td>
                            <td><?php echo esc_html($post->post_status); ?></td>
                            <td><?php echo esc_html(mysql2date('d/m/Y H:i', $post->post_modified)); ?></td>
                            <td><a class="button button-small" href="<?php echo esc_url($edit_url); ?>">Editar</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
        if ($query->max_num_pages > 1) {
            $base_args = array(
                'page'        => $context['page'],
                'q'           => $search,
                'product_cat' => $category_filter,
                'status'      => $status,
                'paged'       => '%#%',
            );
            if (!empty($context['tab'])) {
                $base_args['tab'] = $context['tab'];
            }

            echo '<div style="margin-top:18px;">';
            echo wp_kses_post(
                paginate_links(array(
                    'base'      => esc_url_raw(add_query_arg($base_args, admin_url($context['base'] === 'edit.php' ? 'edit.php' : 'admin.php'))),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => max(1, (int) $query->max_num_pages),
                    'type'      => 'list',
                    'prev_text' => '«',
                    'next_text' => '»',
                ))
            );
            echo '</div>';
        }

        wp_reset_postdata();
        echo '</div>';
    }
}

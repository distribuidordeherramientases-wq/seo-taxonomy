<?php
/**
 * Import/export CSV de los borradores editoriales creados por Solucionador.
 *
 * Alcance: exactamente los posts que aparecen en la pestaña Solucionador:
 * draft + metadatos Solucionador + al menos una relación post_to_category.
 *
 * La importación es deliberadamente conservadora:
 * - actualiza únicamente posts existentes del mismo conjunto;
 * - nunca publica: mantiene post_status=draft;
 * - no crea categorías, etiquetas ni términos de Vocabulary;
 * - conserva asignaciones de Vocabulary no manuales;
 * - exporta metadatos internos de Solucionador para trazabilidad, pero no los importa.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_solucionador_posts_ie_ids')) {
    function seo_solucionador_posts_ie_ids() {
        if (!function_exists('seo_post_editor_get_ids_with_product_cat_relation')) {
            return array();
        }

        $ids = array_values(array_unique(array_filter(array_map(
            'absint',
            (array) seo_post_editor_get_ids_with_product_cat_relation()
        ))));
        if (!$ids) {
            return array();
        }

        $query = new WP_Query(array(
            'post_type'              => 'post',
            'post_status'            => 'draft',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'post__in'               => $ids,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                'relation' => 'AND',
                array('key' => '_seo_solucionador_topic_id', 'compare' => 'EXISTS'),
                array('key' => '_seo_solucionador_dossier_category_id', 'compare' => 'EXISTS'),
            ),
        ));

        return array_values(array_unique(array_filter(array_map('absint', (array) $query->posts))));
    }
}

if (!function_exists('seo_solucionador_posts_ie_is_allowed')) {
    function seo_solucionador_posts_ie_is_allowed($post_id) {
        $post_id = absint($post_id);
        if (!$post_id || get_post_type($post_id) !== 'post' || get_post_status($post_id) !== 'draft') {
            return false;
        }
        if (!function_exists('seo_post_editor_is_solucionador_post') || !seo_post_editor_is_solucionador_post($post_id)) {
            return false;
        }
        $cats = function_exists('seo_post_editor_get_product_cat_ids')
            ? seo_post_editor_get_product_cat_ids($post_id)
            : array();
        return !empty($cats);
    }
}

if (!function_exists('seo_solucionador_posts_ie_term_data')) {
    function seo_solucionador_posts_ie_term_data($post_id, $taxonomy) {
        $terms = wp_get_object_terms(absint($post_id), sanitize_key((string) $taxonomy));
        if (is_wp_error($terms)) {
            return array('ids' => array(), 'slugs' => array(), 'names' => array());
        }

        $out = array('ids' => array(), 'slugs' => array(), 'names' => array());
        foreach ((array) $terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }
            $out['ids'][] = absint($term->term_id);
            $out['slugs'][] = (string) $term->slug;
            $out['names'][] = (string) $term->name;
        }

        foreach ($out as $key => $values) {
            $out[$key] = array_values(array_unique(array_filter($values, static function ($value) {
                return (string) $value !== '';
            })));
        }

        return $out;
    }
}

if (!function_exists('seo_solucionador_posts_ie_product_cat_data')) {
    function seo_solucionador_posts_ie_product_cat_data($post_id) {
        $out = array('ids' => array(), 'slugs' => array(), 'names' => array());
        $ids = function_exists('seo_post_editor_get_product_cat_ids')
            ? seo_post_editor_get_product_cat_ids(absint($post_id))
            : array();

        foreach ((array) $ids as $term_id) {
            $term = get_term(absint($term_id), 'product_cat');
            if (!$term || is_wp_error($term)) {
                continue;
            }
            $out['ids'][] = absint($term->term_id);
            $out['slugs'][] = (string) $term->slug;
            $out['names'][] = (string) $term->name;
        }

        return $out;
    }
}

if (!function_exists('seo_solucionador_posts_ie_pipe')) {
    function seo_solucionador_posts_ie_pipe($values) {
        return implode('|', array_values(array_filter(array_map('strval', (array) $values), static function ($value) {
            return trim($value) !== '';
        })));
    }
}

if (!function_exists('seo_solucionador_posts_ie_csv_list')) {
    function seo_solucionador_posts_ie_csv_list($values) {
        return implode(',', array_values(array_filter(array_map('strval', (array) $values), static function ($value) {
            return trim($value) !== '';
        })));
    }
}

if (!function_exists('seo_solucionador_posts_ie_parse_pipe')) {
    function seo_solucionador_posts_ie_parse_pipe($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return array();
        }
        return array_values(array_unique(array_filter(array_map(
            'sanitize_title',
            preg_split('/\s*\|\s*/u', $value, -1, PREG_SPLIT_NO_EMPTY)
        ))));
    }
}

if (!function_exists('seo_solucionador_posts_ie_meta_json')) {
    function seo_solucionador_posts_ie_meta_json($post_id) {
        $all = get_post_meta(absint($post_id));
        $out = array();

        foreach ((array) $all as $key => $values) {
            if (strpos((string) $key, '_seo_solucionador_') !== 0) {
                continue;
            }
            $decoded = array_map('maybe_unserialize', (array) $values);
            $out[$key] = count($decoded) === 1 ? reset($decoded) : array_values($decoded);
        }

        ksort($out);
        return wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('seo_solucionador_posts_ie_vocab_slugs')) {
    function seo_solucionador_posts_ie_vocab_slugs($post_id, $group) {
        if (!function_exists('seo_content_vocab_export_group')) {
            return array();
        }
        return seo_content_vocab_export_group('post', absint($post_id), sanitize_key((string) $group), 'slug', '');
    }
}

if (!function_exists('seo_solucionador_posts_ie_columns')) {
    function seo_solucionador_posts_ie_columns() {
        return array(
            'post_id',
            'post_title',
            'post_name',
            'post_status',
            'post_excerpt',
            'post_content',
            'post_date',
            'post_date_gmt',
            'post_modified',
            'post_modified_gmt',
            'post_author_id',
            'post_author_login',
            'post_author_name',
            'featured_image_id',
            'featured_image_url',
            'wordpress_category_ids',
            'wordpress_category_slugs',
            'wordpress_category_names',
            'wordpress_tag_ids',
            'wordpress_tag_slugs',
            'wordpress_tag_names',
            'product_category_ids',
            'product_category_slugs',
            'product_category_names',
            'vocab_rol',
            'vocab_tipo',
            'vocab_aplicacion',
            'vocab_plataforma',
            'vocab_subtipo',
            'public_content_role',
            'solucionador_topic_id',
            'solucionador_dossier_category_id',
            'solucionador_meta_json',
        );
    }
}

if (!function_exists('seo_solucionador_posts_ie_export_row')) {
    function seo_solucionador_posts_ie_export_row($post_id) {
        $post = get_post(absint($post_id));
        if (!$post || $post->post_type !== 'post') {
            return array();
        }

        $author = get_userdata(absint($post->post_author));
        $image_id = absint(get_post_thumbnail_id($post_id));
        $wp_categories = seo_solucionador_posts_ie_term_data($post_id, 'category');
        $wp_tags = seo_solucionador_posts_ie_term_data($post_id, 'post_tag');
        $product_categories = seo_solucionador_posts_ie_product_cat_data($post_id);

        return array(
            'post_id'                         => absint($post_id),
            'post_title'                      => (string) $post->post_title,
            'post_name'                       => (string) $post->post_name,
            'post_status'                     => (string) $post->post_status,
            'post_excerpt'                    => (string) $post->post_excerpt,
            'post_content'                    => (string) $post->post_content,
            'post_date'                       => (string) $post->post_date,
            'post_date_gmt'                   => (string) $post->post_date_gmt,
            'post_modified'                   => (string) $post->post_modified,
            'post_modified_gmt'               => (string) $post->post_modified_gmt,
            'post_author_id'                  => absint($post->post_author),
            'post_author_login'               => $author ? (string) $author->user_login : '',
            'post_author_name'                => $author ? (string) $author->display_name : '',
            'featured_image_id'               => $image_id,
            'featured_image_url'              => $image_id ? (string) wp_get_attachment_image_url($image_id, 'full') : '',
            'wordpress_category_ids'          => seo_solucionador_posts_ie_pipe($wp_categories['ids']),
            'wordpress_category_slugs'        => seo_solucionador_posts_ie_pipe($wp_categories['slugs']),
            'wordpress_category_names'        => seo_solucionador_posts_ie_pipe($wp_categories['names']),
            'wordpress_tag_ids'               => seo_solucionador_posts_ie_pipe($wp_tags['ids']),
            'wordpress_tag_slugs'             => seo_solucionador_posts_ie_pipe($wp_tags['slugs']),
            'wordpress_tag_names'             => seo_solucionador_posts_ie_pipe($wp_tags['names']),
            'product_category_ids'            => seo_solucionador_posts_ie_pipe($product_categories['ids']),
            'product_category_slugs'          => seo_solucionador_posts_ie_pipe($product_categories['slugs']),
            'product_category_names'          => seo_solucionador_posts_ie_pipe($product_categories['names']),
            'vocab_rol'                       => seo_solucionador_posts_ie_csv_list(seo_solucionador_posts_ie_vocab_slugs($post_id, 'rol')),
            'vocab_tipo'                      => seo_solucionador_posts_ie_csv_list(seo_solucionador_posts_ie_vocab_slugs($post_id, 'tipo')),
            'vocab_aplicacion'                => seo_solucionador_posts_ie_csv_list(seo_solucionador_posts_ie_vocab_slugs($post_id, 'aplicacion')),
            'vocab_plataforma'                => seo_solucionador_posts_ie_csv_list(seo_solucionador_posts_ie_vocab_slugs($post_id, 'plataforma')),
            'vocab_subtipo'                   => seo_solucionador_posts_ie_csv_list(seo_solucionador_posts_ie_vocab_slugs($post_id, 'subtipo')),
            'public_content_role'             => function_exists('seo_post_editor_public_content_role') ? seo_post_editor_public_content_role($post_id) : '',
            'solucionador_topic_id'           => get_post_meta($post_id, '_seo_solucionador_topic_id', true),
            'solucionador_dossier_category_id'=> get_post_meta($post_id, '_seo_solucionador_dossier_category_id', true),
            'solucionador_meta_json'          => seo_solucionador_posts_ie_meta_json($post_id),
        );
    }
}

if (!function_exists('seo_solucionador_posts_ie_export_handler')) {
    function seo_solucionador_posts_ie_export_handler() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para exportar estos posts.', 'seo-taxonomy'));
        }

        check_admin_referer('seo_solucionador_posts_ie_export');

        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="seo-solucionador-posts-' . gmdate('Ymd-His') . '.csv"');

        $out = fopen('php://output', 'w');
        if (!$out) {
            wp_die(esc_html__('No se pudo generar el CSV.', 'seo-taxonomy'));
        }

        fwrite($out, "\xEF\xBB\xBF");
        $columns = seo_solucionador_posts_ie_columns();
        fputcsv($out, $columns, ';', '"', '');

        foreach (seo_solucionador_posts_ie_ids() as $post_id) {
            $row = seo_solucionador_posts_ie_export_row($post_id);
            if (!$row) {
                continue;
            }
            $values = array();
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? '';
            }
            fputcsv($out, $values, ';', '"', '');
        }

        fclose($out);
        exit;
    }
}

if (!function_exists('seo_solucionador_posts_ie_notice_key')) {
    function seo_solucionador_posts_ie_notice_key() {
        return 'seo_solucionador_posts_ie_notice_' . get_current_user_id();
    }
}

if (!function_exists('seo_solucionador_posts_ie_set_notice')) {
    function seo_solucionador_posts_ie_set_notice($type, $message, $stats = array(), $details = array()) {
        set_transient(
            seo_solucionador_posts_ie_notice_key(),
            array(
                'type'    => sanitize_key((string) $type),
                'message' => sanitize_text_field((string) $message),
                'stats'   => array_map('absint', (array) $stats),
                'details' => array_slice(array_map('sanitize_text_field', (array) $details), 0, 30),
            ),
            10 * MINUTE_IN_SECONDS
        );
    }
}

if (!function_exists('seo_solucionador_posts_ie_render_notice')) {
    function seo_solucionador_posts_ie_render_notice() {
        $notice = get_transient(seo_solucionador_posts_ie_notice_key());
        if (!is_array($notice)) {
            return;
        }
        delete_transient(seo_solucionador_posts_ie_notice_key());

        $type = in_array(($notice['type'] ?? ''), array('success', 'warning', 'error', 'info'), true)
            ? $notice['type']
            : 'info';

        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p><strong>'
            . esc_html((string) ($notice['message'] ?? '')) . '</strong>';

        $parts = array();
        foreach ((array) ($notice['stats'] ?? array()) as $label => $value) {
            $parts[] = sanitize_text_field((string) $label) . ': ' . absint($value);
        }
        if ($parts) {
            echo '<br>' . esc_html(implode(' · ', $parts));
        }
        echo '</p>';

        if (!empty($notice['details'])) {
            echo '<details style="margin:0 0 10px 12px;"><summary>Ver incidencias</summary><ul style="list-style:disc;margin-left:20px;">';
            foreach ((array) $notice['details'] as $detail) {
                echo '<li>' . esc_html((string) $detail) . '</li>';
            }
            echo '</ul></details>';
        }

        echo '</div>';
    }
}

if (!function_exists('seo_solucionador_posts_ie_redirect')) {
    function seo_solucionador_posts_ie_redirect() {
        wp_safe_redirect(admin_url('admin.php?page=seo-post-editor&tab=solucionador'));
        exit;
    }
}

if (!function_exists('seo_solucionador_posts_ie_csv_rows')) {
    function seo_solucionador_posts_ie_csv_rows($path) {
        $fh = fopen((string) $path, 'r');
        if (!$fh) {
            return new WP_Error('seo_solucionador_posts_ie_open', 'No se pudo abrir el CSV.');
        }

        $header = fgetcsv($fh, 0, ';', '"', '');
        if (!is_array($header)) {
            fclose($fh);
            return new WP_Error('seo_solucionador_posts_ie_header', 'El CSV no tiene una cabecera válida.');
        }

        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        }
        $header = array_map('sanitize_key', $header);

        if (!in_array('post_id', $header, true) || !in_array('post_title', $header, true)) {
            fclose($fh);
            return new WP_Error('seo_solucionador_posts_ie_columns', 'El CSV debe contener post_id y post_title.');
        }

        $rows = array();
        $line = 1;

        while (($values = fgetcsv($fh, 0, ';', '"', '')) !== false) {
            $line++;
            if (!$values || !array_filter($values, static function ($value) {
                return trim((string) $value) !== '';
            })) {
                continue;
            }

            $values = array_pad($values, count($header), '');
            $row = array_combine($header, array_slice($values, 0, count($header)));
            if (!is_array($row)) {
                fclose($fh);
                return new WP_Error('seo_solucionador_posts_ie_row', sprintf('No se pudo interpretar la fila %d.', $line));
            }

            $row['_line'] = $line;
            $rows[] = $row;
        }

        fclose($fh);
        return $rows;
    }
}

if (!function_exists('seo_solucionador_posts_ie_resolve_slugs')) {
    function seo_solucionador_posts_ie_resolve_slugs($taxonomy, $slugs) {
        $ids = array();

        foreach ((array) $slugs as $slug) {
            $slug = sanitize_title((string) $slug);
            if ($slug === '') {
                continue;
            }

            $term = get_term_by('slug', $slug, sanitize_key((string) $taxonomy));
            if (!$term || is_wp_error($term)) {
                return new WP_Error(
                    'seo_solucionador_posts_ie_term',
                    sprintf('No existe %s con slug "%s".', $taxonomy, $slug)
                );
            }
            $ids[] = absint($term->term_id);
        }

        return array_values(array_unique(array_filter($ids)));
    }
}

if (!function_exists('seo_solucionador_posts_ie_validate_row')) {
    function seo_solucionador_posts_ie_validate_row($row, $line) {
        $post_id = absint($row['post_id'] ?? 0);

        if (!$post_id || !seo_solucionador_posts_ie_is_allowed($post_id)) {
            return new WP_Error(
                'seo_solucionador_posts_ie_scope',
                sprintf('Fila %d: el post %d no pertenece actualmente a la pestaña Solucionador.', (int) $line, $post_id)
            );
        }

        $title = sanitize_text_field((string) ($row['post_title'] ?? ''));
        if ($title === '') {
            return new WP_Error('seo_solucionador_posts_ie_title', sprintf('Fila %d: el título está vacío.', (int) $line));
        }
        $row['post_title'] = $title;

        if (array_key_exists('product_category_slugs', $row)) {
            $slugs = seo_solucionador_posts_ie_parse_pipe($row['product_category_slugs']);
            if (!$slugs) {
                return new WP_Error(
                    'seo_solucionador_posts_ie_product_cat',
                    sprintf('Fila %d: debe mantenerse al menos una categoría de producto.', (int) $line)
                );
            }
            $resolved = seo_solucionador_posts_ie_resolve_slugs('product_cat', $slugs);
            if (is_wp_error($resolved)) {
                return $resolved;
            }
            $row['_product_cat_ids'] = $resolved;
        }

        foreach (array(
            'wordpress_category_slugs' => 'category',
            'wordpress_tag_slugs'      => 'post_tag',
        ) as $column => $taxonomy) {
            if (!array_key_exists($column, $row)) {
                continue;
            }
            $resolved = seo_solucionador_posts_ie_resolve_slugs(
                $taxonomy,
                seo_solucionador_posts_ie_parse_pipe($row[$column])
            );
            if (is_wp_error($resolved)) {
                return $resolved;
            }
            $row['_' . $taxonomy . '_ids'] = $resolved;
        }

        if (array_key_exists('featured_image_id', $row)) {
            $image_id = absint($row['featured_image_id']);
            if ($image_id > 0 && !wp_attachment_is_image($image_id)) {
                return new WP_Error(
                    'seo_solucionador_posts_ie_image',
                    sprintf('Fila %d: la imagen destacada %d no es válida.', (int) $line, $image_id)
                );
            }
        }

        if (!empty($row['post_author_id']) && !get_userdata(absint($row['post_author_id']))) {
            return new WP_Error(
                'seo_solucionador_posts_ie_author',
                sprintf('Fila %d: el autor %d no existe.', (int) $line, absint($row['post_author_id']))
            );
        }

        $role = sanitize_key((string) ($row['public_content_role'] ?? ''));
        if ($role !== '' && function_exists('seo_post_editor_public_content_roles')) {
            $roles = seo_post_editor_public_content_roles();
            if (!array_key_exists($role, $roles)) {
                return new WP_Error(
                    'seo_solucionador_posts_ie_role',
                    sprintf('Fila %d: el rol público "%s" no existe.', (int) $line, $role)
                );
            }
        }

        if (function_exists('seo_content_vocab_validate_import_row')) {
            $log = array('advertencias' => 0, 'detalles' => array());
            if (!seo_content_vocab_validate_import_row($row, $line, $log)) {
                return new WP_Error(
                    'seo_solucionador_posts_ie_vocab',
                    !empty($log['detalles'])
                        ? implode(' ', array_map('strval', (array) $log['detalles']))
                        : sprintf('Fila %d: Vocabulary no válido.', (int) $line)
                );
            }
        }

        return $row;
    }
}

if (!function_exists('seo_solucionador_posts_ie_apply_row')) {
    function seo_solucionador_posts_ie_apply_row($row, $line) {
        $row = seo_solucionador_posts_ie_validate_row($row, $line);
        if (is_wp_error($row)) {
            return $row;
        }

        $post_id = absint($row['post_id']);
        $post_data = array(
            'ID'           => $post_id,
            'post_status'  => 'draft',
            'post_title'   => (string) $row['post_title'],
            'post_name'    => sanitize_title((string) ($row['post_name'] ?? '')),
            'post_excerpt' => current_user_can('unfiltered_html')
                ? (string) ($row['post_excerpt'] ?? '')
                : wp_kses_post((string) ($row['post_excerpt'] ?? '')),
            'post_content' => current_user_can('unfiltered_html')
                ? (string) ($row['post_content'] ?? '')
                : wp_kses_post((string) ($row['post_content'] ?? '')),
        );

        if (!empty($row['post_author_id'])) {
            $post_data['post_author'] = absint($row['post_author_id']);
        }

        $saved = wp_update_post(wp_slash($post_data), true);
        if (is_wp_error($saved)) {
            return $saved;
        }

        if (array_key_exists('_category_ids', $row)) {
            $result = wp_set_post_terms($post_id, (array) $row['_category_ids'], 'category', false);
            if (is_wp_error($result)) {
                clean_post_cache($post_id);
                return $result;
            }
        }

        if (array_key_exists('_post_tag_ids', $row)) {
            $result = wp_set_post_terms($post_id, (array) $row['_post_tag_ids'], 'post_tag', false);
            if (is_wp_error($result)) {
                clean_post_cache($post_id);
                return $result;
            }
        }

        if (array_key_exists('_product_cat_ids', $row)) {
            $result = seo_post_editor_replace_product_cat_relations($post_id, (array) $row['_product_cat_ids']);
            if (is_wp_error($result)) {
                clean_post_cache($post_id);
                return $result;
            }
        }

        if (array_key_exists('featured_image_id', $row)) {
            $image_id = absint($row['featured_image_id']);
            if ($image_id > 0) {
                set_post_thumbnail($post_id, $image_id);
            } else {
                delete_post_thumbnail($post_id);
            }
        }

        if (array_key_exists('public_content_role', $row) && function_exists('seo_post_editor_set_public_content_role')) {
            $result = seo_post_editor_set_public_content_role(
                $post_id,
                sanitize_key((string) $row['public_content_role'])
            );
            if (is_wp_error($result)) {
                clean_post_cache($post_id);
                return $result;
            }
        }

        if (function_exists('seo_content_vocab_import_row')) {
            $log = array('advertencias' => 0, 'detalles' => array());
            seo_content_vocab_import_row('post', $post_id, $row, $line, $log);
            if (!empty($log['advertencias'])) {
                clean_post_cache($post_id);
                return new WP_Error(
                    'seo_solucionador_posts_ie_vocab_write',
                    implode(' ', array_map('strval', (array) ($log['detalles'] ?? array('No se pudo actualizar Vocabulary.'))))
                );
            }
        }
        clean_post_cache($post_id);
        return true;
    }
}

if (!function_exists('seo_solucionador_posts_ie_import_handler')) {
    function seo_solucionador_posts_ie_import_handler() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para importar estos posts.', 'seo-taxonomy'));
        }

        check_admin_referer('seo_solucionador_posts_ie_import', 'seo_solucionador_posts_ie_nonce');

        if (empty($_FILES['seo_solucionador_posts_csv']) || !is_array($_FILES['seo_solucionador_posts_csv'])) {
            seo_solucionador_posts_ie_set_notice('error', 'No se ha recibido ningún CSV.');
            seo_solucionador_posts_ie_redirect();
        }

        $file = $_FILES['seo_solucionador_posts_csv'];
        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
            seo_solucionador_posts_ie_set_notice('error', 'El archivo debe ser CSV.');
            seo_solucionador_posts_ie_redirect();
        }

        $tmp_path = (string) ($file['tmp_name'] ?? '');
        if ($tmp_path === '' || !is_readable($tmp_path)) {
            seo_solucionador_posts_ie_set_notice('error', 'No se puede leer el archivo temporal.');
            seo_solucionador_posts_ie_redirect();
        }

        $precheck = seo_solucionador_posts_ie_csv_rows($tmp_path);
        if (is_wp_error($precheck)) {
            seo_solucionador_posts_ie_set_notice('error', $precheck->get_error_message());
            seo_solucionador_posts_ie_redirect();
        }

        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || empty($uploads['basedir'])) {
            seo_solucionador_posts_ie_set_notice('error', 'No está disponible el directorio de uploads.');
            seo_solucionador_posts_ie_redirect();
        }

        $destination = trailingslashit((string) $uploads['basedir']) . 'seo-taxonomy/solucionador-imports';
        $stored = function_exists('seo_taxonomy_store_uploaded_file')
            ? seo_taxonomy_store_uploaded_file(
                $file,
                $destination,
                array('csv' => 'text/csv'),
                $name,
                false,
                true
            )
            : new WP_Error('seo_solucionador_posts_ie_upload', 'No está disponible el gestor seguro de subidas.');

        if (is_wp_error($stored)) {
            seo_solucionador_posts_ie_set_notice('error', $stored->get_error_message());
            seo_solucionador_posts_ie_redirect();
        }

        $path = (string) ($stored['path'] ?? '');
        $rows = seo_solucionador_posts_ie_csv_rows($path);
        wp_delete_file($path);

        if (is_wp_error($rows)) {
            seo_solucionador_posts_ie_set_notice('error', $rows->get_error_message());
            seo_solucionador_posts_ie_redirect();
        }

        $stats = array('procesados' => 0, 'actualizados' => 0, 'errores' => 0);
        $details = array();

        foreach ((array) $rows as $row) {
            $stats['procesados']++;
            $line = absint($row['_line'] ?? $stats['procesados']);
            unset($row['_line']);

            $result = seo_solucionador_posts_ie_apply_row($row, $line);
            if (is_wp_error($result)) {
                $stats['errores']++;
                $details[] = $result->get_error_message();
                continue;
            }

            $stats['actualizados']++;
        }

        if ($stats['errores'] > 0) {
            seo_solucionador_posts_ie_set_notice(
                $stats['actualizados'] > 0 ? 'warning' : 'error',
                $stats['actualizados'] > 0
                    ? 'Importación terminada con incidencias.'
                    : 'No se ha actualizado ningún post.',
                $stats,
                $details
            );
        } else {
            seo_solucionador_posts_ie_set_notice(
                'success',
                'Importación completada. Los posts continúan en borrador.',
                $stats
            );
        }

        seo_solucionador_posts_ie_redirect();
    }
}

if (!function_exists('seo_solucionador_posts_ie_render')) {
    function seo_solucionador_posts_ie_render() {
        $export_url = wp_nonce_url(
            admin_url('admin-post.php?action=seo_solucionador_posts_ie_export'),
            'seo_solucionador_posts_ie_export'
        );
        ?>
        <details style="margin:14px 0 18px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;">
            <summary style="cursor:pointer;font-weight:700;font-size:14px;">Importar / exportar posts de Solucionador</summary>
            <div style="margin-top:14px;">
                <p style="margin:0 0 10px;color:#50575e;line-height:1.55;">
                    Se exportan <strong>únicamente los borradores que aparecen en esta pestaña</strong>:
                    contenido, título, slug, excerpt, autor, imagen destacada, categorías editoriales,
                    etiquetas WordPress, categorías de producto, todas las etiquetas de Vocabulary,
                    rol público y metadatos de trazabilidad de Solucionador.
                </p>
                <p style="margin:0 0 12px;color:#646970;font-size:12px;line-height:1.5;">
                    Al importar se actualizan sólo posts existentes de este mismo conjunto. Se mantienen en <strong>draft</strong>.
                    No se crean categorías ni etiquetas nuevas y los metadatos internos de Solucionador son de sólo lectura.
                </p>

                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <a class="button button-secondary" href="<?php echo esc_url($export_url); ?>">Exportar CSV</a>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0;">
                        <input type="hidden" name="action" value="seo_solucionador_posts_ie_import">
                        <?php wp_nonce_field('seo_solucionador_posts_ie_import', 'seo_solucionador_posts_ie_nonce'); ?>
                        <input type="file" name="seo_solucionador_posts_csv" accept=".csv,text/csv" required>
                        <button type="submit" class="button button-primary">Importar CSV</button>
                    </form>
                </div>
            </div>
        </details>
        <?php
    }
}

add_action('admin_post_seo_solucionador_posts_ie_export', 'seo_solucionador_posts_ie_export_handler');
add_action('admin_post_seo_solucionador_posts_ie_import', 'seo_solucionador_posts_ie_import_handler');

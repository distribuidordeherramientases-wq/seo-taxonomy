<?php
/**
 * Creación y seguimiento de posts del proceso editorial propio de Ingeniero.
 */

defined('ABSPATH') || exit;

final class SEO_Ingeniero_Posts {
    const META_EDITORIAL_ID = '_seo_ingeniero_editorial_id';
    const META_TOPIC_KEY = '_seo_ingeniero_topic_key';
    const META_SOURCE_HASH = '_seo_ingeniero_source_hash';
    const META_KNOWLEDGE_IDS = '_seo_ingeniero_knowledge_ids';
    const META_PENDING_KNOWLEDGE_IDS = '_seo_ingeniero_pending_knowledge_ids';
    const META_PENDING_SOURCE_HASH = '_seo_ingeniero_pending_source_hash';
    const META_PENDING_DETECTED_AT = '_seo_ingeniero_pending_detected_at';
    const META_CONTENT_ROLE = '_seo_solucionador_content_role';

    public static function init() {
        add_action('transition_post_status', array(__CLASS__, 'transition_post_status'), 20, 3);
        add_action('before_delete_post', array(__CLASS__, 'before_delete_post'), 30, 1);
        add_action('seo_post_editor_before_form', array(__CLASS__, 'render_pending_review_panel'), 20, 1);
        add_action('admin_post_seo_ingeniero_post_update_action', array(__CLASS__, 'handle_update_action'));
    }

    public static function edit_url($post_id) {
        $post_id = absint($post_id);
        return $post_id
            ? add_query_arg(array('page'=>'seo-post-editor','post_id'=>$post_id), admin_url('edit.php'))
            : '';
    }

    public static function can_create_draft(array $dossier) {
        if ('CREATE_POST' !== strtoupper((string) ($dossier['recommended_action'] ?? ''))) {
            return new WP_Error('ingeniero_editorial_action', 'Esta propuesta no requiere crear un post nuevo.');
        }
        if ('approved' !== sanitize_key((string) ($dossier['status'] ?? ''))) {
            return new WP_Error('ingeniero_editorial_not_approved', 'La propuesta debe aprobarse antes de crear el borrador.');
        }
        if (absint($dossier['term_id'] ?? 0) < 1) {
            return new WP_Error('ingeniero_editorial_category', 'La propuesta no tiene categoría válida.');
        }
        return true;
    }

    public static function create_draft($editorial_id) {
        $editorial_id = absint($editorial_id);
        $dossier = SEO_Ingeniero_DB::editorial_get($editorial_id);
        if (!$dossier) return new WP_Error('ingeniero_editorial_missing', 'La propuesta editorial no existe.');

        $post_id = absint($dossier['post_id'] ?? 0);
        if ($post_id && 'post' === get_post_type($post_id) && 'trash' !== get_post_status($post_id)) {
            return $post_id;
        }

        $allowed = self::can_create_draft($dossier);
        if (is_wp_error($allowed)) return $allowed;

        $term_id = absint($dossier['term_id'] ?? 0);
        $term = $term_id ? get_term($term_id, 'product_cat') : null;
        if (!$term || is_wp_error($term)) {
            return new WP_Error('ingeniero_editorial_category', 'La categoría del dossier ya no existe.');
        }

        $title = sanitize_text_field((string) ($dossier['suggested_title'] ?? ''));
        if ('' === $title) return new WP_Error('ingeniero_editorial_title', 'La propuesta no tiene título.');

        $post_id = wp_insert_post(wp_slash(array(
            'post_type'    => 'post',
            'post_status'  => 'draft',
            'post_title'   => $title,
            'post_content' => '',
            'post_excerpt' => '',
            'post_author'  => get_current_user_id(),
        )), true);
        if (is_wp_error($post_id) || absint($post_id) < 1) {
            return is_wp_error($post_id) ? $post_id : new WP_Error('ingeniero_editorial_post', 'WordPress no pudo crear el borrador.');
        }
        $post_id = absint($post_id);

        update_post_meta($post_id, self::META_EDITORIAL_ID, $editorial_id);
        update_post_meta($post_id, self::META_TOPIC_KEY, (string) ($dossier['topic_key'] ?? ''));
        update_post_meta($post_id, self::META_SOURCE_HASH, (string) ($dossier['source_hash'] ?? ''));
        update_post_meta(
            $post_id,
            self::META_KNOWLEDGE_IDS,
            array_values(array_unique(array_filter(array_map('absint', (array) ($dossier['knowledge_ids'] ?? array())))))
        );
        update_post_meta($post_id, self::META_CONTENT_ROLE, 'ingeniero_qa_specialized');

        $relation = self::assign_category($post_id, $term_id);
        if (is_wp_error($relation)) {
            wp_delete_post($post_id, true);
            return $relation;
        }

        $vocabulary = self::assign_category_vocabulary($post_id, $term_id);
        if (is_wp_error($vocabulary)) {
            wp_delete_post($post_id, true);
            return $vocabulary;
        }

        SEO_Ingeniero_DB::update_editorial($editorial_id, array(
            'status'  => 'draft',
            'post_id' => $post_id,
        ));

        return $post_id;
    }

    private static function clear_pending_update($post_id) {
        delete_post_meta($post_id, self::META_PENDING_KNOWLEDGE_IDS);
        delete_post_meta($post_id, self::META_PENDING_SOURCE_HASH);
        delete_post_meta($post_id, self::META_PENDING_DETECTED_AT);
    }

    public static function pending_knowledge_ids($post_id) {
        return array_values(array_unique(array_filter(array_map(
            'absint',
            (array) get_post_meta(absint($post_id), self::META_PENDING_KNOWLEDGE_IDS, true)
        ))));
    }

    public static function pending_count($post_id) {
        return count(self::pending_knowledge_ids($post_id));
    }

    private static function dossier_for_post($post_id) {
        $editorial_id = absint(get_post_meta(absint($post_id), self::META_EDITORIAL_ID, true));
        return $editorial_id ? SEO_Ingeniero_DB::editorial_get($editorial_id) : array();
    }

    public static function pending_knowledge_details($post_id) {
        $dossier = self::dossier_for_post($post_id);
        if (!$dossier) return array();

        $lookup = array_fill_keys(self::pending_knowledge_ids($post_id), true);
        if (!$lookup) return array();

        $out = array();
        foreach ((array) SEO_Ingeniero::active_knowledge(absint($dossier['term_id'] ?? 0)) as $row) {
            $id = absint($row['id'] ?? 0);
            if ($id && isset($lookup[$id])) $out[] = $row;
        }
        return $out;
    }

    public static function sync_pending_update($editorial_id) {
        $editorial_id = absint($editorial_id);
        $dossier = SEO_Ingeniero_DB::editorial_get($editorial_id);
        if (!$dossier) return new WP_Error('ingeniero_editorial_missing', 'No existe el dossier editorial.');

        $post_id = absint($dossier['post_id'] ?? 0);
        if (!$post_id || get_post_type($post_id) !== 'post' || get_post_status($post_id) === 'trash') return 0;

        $current_ids = array_values(array_unique(array_filter(array_map(
            'absint',
            (array) ($dossier['knowledge_ids'] ?? array())
        ))));
        sort($current_ids, SORT_NUMERIC);

        $baseline_ids = array_values(array_unique(array_filter(array_map(
            'absint',
            (array) get_post_meta($post_id, self::META_KNOWLEDGE_IDS, true)
        ))));
        sort($baseline_ids, SORT_NUMERIC);

        $baseline_hash = (string) get_post_meta($post_id, self::META_SOURCE_HASH, true);
        $current_hash = (string) ($dossier['source_hash'] ?? '');

        // Compatibilidad con posts creados antes de guardar knowledge_ids:
        // si el hash coincide, el dossier actual es la línea base.
        if (!$baseline_ids && $baseline_hash !== '' && $baseline_hash === $current_hash) {
            update_post_meta($post_id, self::META_KNOWLEDGE_IDS, $current_ids);
            self::clear_pending_update($post_id);
            return 0;
        }

        $pending_ids = array_values(array_diff($current_ids, $baseline_ids));

        // Si cambió material ya existente (mismo ID, nuevo resumen/fuentes),
        // se muestra igualmente para revisión.
        if (!$pending_ids && $baseline_hash !== '' && $current_hash !== '' && $baseline_hash !== $current_hash) {
            $pending_ids = $current_ids;
        }
        sort($pending_ids, SORT_NUMERIC);

        if (!$pending_ids) {
            self::clear_pending_update($post_id);
            return 0;
        }

        update_post_meta($post_id, self::META_PENDING_KNOWLEDGE_IDS, $pending_ids);
        update_post_meta($post_id, self::META_PENDING_SOURCE_HASH, $current_hash);
        update_post_meta($post_id, self::META_PENDING_DETECTED_AT, current_time('mysql'));

        SEO_Ingeniero_DB::update_editorial($editorial_id, array(
            'status'=>'needs_update',
            'recommended_action'=>'IMPROVE_POST',
        ));

        return count($pending_ids);
    }

    public static function refresh_pending_for_post($post_id) {
        $post_id = absint($post_id);
        $editorial_id = absint(get_post_meta($post_id, self::META_EDITORIAL_ID, true));
        if (!$post_id || !$editorial_id || get_post_type($post_id) !== 'post') {
            return new WP_Error('ingeniero_post_invalid', 'El post no pertenece a Ingeniero.');
        }
        return self::sync_pending_update($editorial_id);
    }

    public static function mark_pending_reviewed($post_id) {
        $post_id = absint($post_id);
        $dossier = self::dossier_for_post($post_id);
        if (!$post_id || !$dossier || get_post_type($post_id) !== 'post') {
            return new WP_Error('ingeniero_post_invalid', 'El post no pertenece a Ingeniero.');
        }

        $current_ids = array_values(array_unique(array_filter(array_map(
            'absint',
            (array) ($dossier['knowledge_ids'] ?? array())
        ))));
        update_post_meta($post_id, self::META_KNOWLEDGE_IDS, $current_ids);
        update_post_meta($post_id, self::META_SOURCE_HASH, (string) ($dossier['source_hash'] ?? ''));
        self::clear_pending_update($post_id);

        $status = get_post_status($post_id) === 'publish' ? 'published' : 'draft';
        SEO_Ingeniero_DB::update_editorial(absint($dossier['id'] ?? 0), array(
            'status'=>$status,
            'recommended_action'=>'NO_ACTION',
        ));
        return true;
    }

    public static function handle_update_action() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos.');
        $post_id = absint($_POST['post_id'] ?? 0);
        $action = sanitize_key((string) ($_POST['update_action'] ?? ''));
        check_admin_referer('seo_ingeniero_post_update_' . $post_id);

        $result = $action === 'mark_reviewed'
            ? self::mark_pending_reviewed($post_id)
            : self::refresh_pending_for_post($post_id);

        $state = is_wp_error($result) ? 'error' : ($action === 'mark_reviewed' ? 'reviewed' : 'rescanned');
        if (is_wp_error($result)) {
            set_transient('seo_ingeniero_update_notice_' . get_current_user_id(), $result->get_error_message(), 90);
        }
        wp_safe_redirect(add_query_arg(
            'ingeniero_update',
            $state,
            self::edit_url($post_id)
        ));
        exit;
    }

    public static function render_pending_review_panel($post_id) {
        $post_id = absint($post_id);
        $editorial_id = absint(get_post_meta($post_id, self::META_EDITORIAL_ID, true));
        if (!$post_id || !$editorial_id || get_post_type($post_id) !== 'post') return;

        self::sync_pending_update($editorial_id);
        $pending = self::pending_knowledge_details($post_id);
        $count = count($pending);
        $state = sanitize_key((string) ($_GET['ingeniero_update'] ?? ''));

        echo '<div style="background:#fff;border:1px solid ' . ($count ? '#dba617' : '#c3c4c7') . ';border-left:4px solid ' . ($count ? '#dba617' : '#2271b1') . ';border-radius:6px;padding:16px 18px;margin:14px 0 18px">';
        echo '<h2 style="margin:0 0 6px">Ingeniero · novedades técnicas</h2>';
        if ($state === 'error') {
            $msg = get_transient('seo_ingeniero_update_notice_' . get_current_user_id());
            delete_transient('seo_ingeniero_update_notice_' . get_current_user_id());
            echo '<div class="notice notice-error inline"><p>' . esc_html($msg ?: 'No se pudo actualizar la revisión de Ingeniero.') . '</p></div>';
        } elseif ($state === 'reviewed') {
            echo '<div class="notice notice-success inline"><p>Novedades de Ingeniero marcadas como revisadas.</p></div>';
        }

        if ($count) {
            echo '<p><strong>' . esc_html(number_format_i18n($count)) . ' bloques de conocimiento nuevos o modificados</strong>. El post mantiene su estado actual y la versión pública no se modifica automáticamente.</p>';
            echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0">';

            $html = '<h2>Novedades técnicas de Ingeniero</h2>';
            foreach ($pending as $row) {
                $concept = trim((string) ($row['concept'] ?? ''));
                $summary = trim((string) ($row['summary'] ?? ''));
                if ($concept !== '') $html .= '<h3>' . esc_html($concept) . '</h3>';
                if ($summary !== '') $html .= '<p>' . esc_html($summary) . '</p>';
            }
            if (function_exists('seo_post_editor_render_prepend_payload')) {
                seo_post_editor_render_prepend_payload(
                    'seo-ingeniero-pending-' . $post_id,
                    $html,
                    'Incorporar al contenido de trabajo'
                );
            }

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
            echo '<input type="hidden" name="action" value="seo_ingeniero_post_update_action"><input type="hidden" name="post_id" value="' . esc_attr($post_id) . '"><input type="hidden" name="update_action" value="mark_reviewed">';
            wp_nonce_field('seo_ingeniero_post_update_' . $post_id);
            echo '<button class="button" type="submit">Marcar novedades como revisadas</button></form>';
            echo '</div>';

            foreach ($pending as $row) {
                echo '<div style="padding:10px 0;border-top:1px solid #f0f0f1">';
                echo '<strong>' . esc_html((string) ($row['concept'] ?? $row['knowledge_type'] ?? 'Conocimiento técnico')) . '</strong>';
                if (!empty($row['summary'])) echo '<div style="margin-top:5px">' . esc_html((string) $row['summary']) . '</div>';
                echo '<div class="description" style="margin-top:5px">Tipo: ' . esc_html((string) ($row['knowledge_type'] ?? ''))
                    . ' · Confianza: ' . esc_html(number_format_i18n((float) ($row['confidence'] ?? 0) * 100, 0)) . '%'
                    . ' · Fuentes: ' . esc_html(number_format_i18n(count((array) ($row['source_ids'] ?? array())))) . '</div>';
                echo '</div>';
            }
        } else {
            echo '<p>No hay novedades técnicas pendientes.</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
        echo '<input type="hidden" name="action" value="seo_ingeniero_post_update_action"><input type="hidden" name="post_id" value="' . esc_attr($post_id) . '"><input type="hidden" name="update_action" value="rescan">';
        wp_nonce_field('seo_ingeniero_post_update_' . $post_id);
        echo '<button class="button" type="submit">Reescanear novedades</button></form>';
        echo '<p class="description">Incorporar sólo modifica el editor abierto; guarda el post únicamente cuando la Editora termine la revisión.</p>';
        echo '</div>';
    }

    private static function assign_category($post_id, $term_id) {
        if (function_exists('seo_post_editor_replace_product_cat_relations')) {
            return seo_post_editor_replace_product_cat_relations($post_id, array($term_id));
        }
        return new WP_Error('ingeniero_relations_api', 'No está disponible la API canónica post_to_category.');
    }

    private static function assign_category_vocabulary($post_id, $term_id) {
        if (!function_exists('seo_content_vocab_replace_manual_group') || !function_exists('seo_content_vocab_groups')) {
            return new WP_Error('ingeniero_vocab_api', 'No está disponible la API canónica de Vocabulary.');
        }

        global $wpdb;
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.id,v.semantic_group
                 FROM {$objects} ov
                 INNER JOIN {$vocabulary} v
                    ON v.id=ov.vocabulary_id
                   AND v.active=1
                 WHERE ov.object_type='product_cat'
                   AND ov.object_id=%d
                   AND ov.status=1
                   AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                 ORDER BY v.semantic_group,v.id",
                absint($term_id)
            ),
            ARRAY_A
        );

        $by_group = array();
        foreach ($rows as $row) {
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            if (!isset(seo_content_vocab_groups()[$group])) continue;
            $by_group[$group][] = absint($row['id'] ?? 0);
        }

        foreach (array_keys(seo_content_vocab_groups()) as $group) {
            $result = seo_content_vocab_replace_manual_group(
                'post',
                $post_id,
                $group,
                array_values(array_unique(array_filter((array) ($by_group[$group] ?? array()))))
            );
            if (is_wp_error($result)) return $result;
        }

        return true;
    }

    public static function transition_post_status($new_status, $old_status, $post) {
        if (!$post instanceof WP_Post || 'post' !== $post->post_type) return;
        $editorial_id = absint(get_post_meta($post->ID, self::META_EDITORIAL_ID, true));
        if (!$editorial_id) return;

        $pending = self::pending_count($post->ID);

        if ('publish' === $new_status) {
            SEO_Ingeniero_DB::update_editorial($editorial_id, array(
                'status'  => $pending > 0 ? 'needs_update' : 'published',
                'post_id' => absint($post->ID),
                'recommended_action' => $pending > 0 ? 'IMPROVE_POST' : 'NO_ACTION',
            ));
            return;
        }

        if (in_array($new_status, array('draft','pending','private','future'), true)) {
            SEO_Ingeniero_DB::update_editorial($editorial_id, array(
                'status'  => $pending > 0 ? 'needs_update' : 'draft',
                'post_id' => absint($post->ID),
                'recommended_action' => $pending > 0 ? 'IMPROVE_POST' : 'NO_ACTION',
            ));
        }
    }

    public static function before_delete_post($post_id) {
        $post_id = absint($post_id);
        if (!$post_id || 'post' !== get_post_type($post_id)) return;
        $editorial_id = absint(get_post_meta($post_id, self::META_EDITORIAL_ID, true));
        if (!$editorial_id) return;

        $dossier = SEO_Ingeniero_DB::editorial_get($editorial_id);
        SEO_Ingeniero_DB::update_editorial($editorial_id, array(
            'status'  => !empty($dossier['source_hash']) ? 'approved' : 'candidate',
            'post_id' => 0,
        ));
    }
}

SEO_Ingeniero_Posts::init();

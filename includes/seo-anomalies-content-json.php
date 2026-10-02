<?php
/**
 * Import / export JSON correctivo para contenidos detectados en Informes > Anomalias.
 *
 * El paquete es deliberadamente conservador:
 * - exporta solo posts/landings con anomalias editoriales corregibles;
 * - importa solo IDs existentes y del mismo post_type;
 * - no crea ni elimina posts, paginas, categorias o terminos de Vocabulary;
 * - las relaciones y asignaciones manuales se resuelven por slug para ser portables.
 */

defined('ABSPATH') || exit;

add_action('admin_post_seo_reports_anomalies_content_json_export', 'seo_reports_anomalies_content_json_export_handler');
add_action('admin_post_seo_reports_anomalies_content_json_import', 'seo_reports_anomalies_content_json_import_handler');

if (!function_exists('seo_reports_anomalies_json_excluded_post_slugs')) {
    function seo_reports_anomalies_json_excluded_post_slugs() {
        return array_values(array_unique(array_filter(array_map(
            'sanitize_title',
            (array) apply_filters(
                'seo_reports_non_editorial_post_slugs',
                array(
                    'carrito',
                    'finalizar-compra',
                    'mi-cuenta',
                    'terminos-y-condiciones',
                    'privacidad-de-datos',
                    'devoluciones-y-reembolsos',
                    'contacto',
                    'blog',
                    'tienda',
                    'dependiente',
                    'inicio',
                    'nosotros',
                    'nuestro-servicio',
                    'proveedores-de-distribuidor-de-herramientas-es',
                    'densl-suministro-profesional-de-equipamiento-de-seguridad-vial-bajo-presupuesto',
                    'soluciones',
                    'productos-genericos-de-ferreteria',
                )
            )
        ))));
    }
}

if (!function_exists('seo_reports_anomalies_json_collect_targets')) {
    function seo_reports_anomalies_json_collect_targets() {
        global $wpdb;

        $targets = array();
        $add = static function ($object_type, $object_id, $code) use (&$targets) {
            $object_type = sanitize_key((string) $object_type);
            $object_id = absint($object_id);
            $code = sanitize_key((string) $code);
            if (!$object_id || !in_array($object_type, array('post', 'page'), true) || $code === '') {
                return;
            }
            $key = $object_type . ':' . $object_id;
            if (!isset($targets[$key])) {
                $targets[$key] = array(
                    'object_type' => $object_type,
                    'object_id'   => $object_id,
                    'anomalies'   => array(),
                );
            }
            if (!in_array($code, $targets[$key]['anomalies'], true)) {
                $targets[$key]['anomalies'][] = $code;
            }
        };

        // Landings publicadas/programadas sin relacion comercial.
        $landing_rows = $wpdb->get_results(
            "SELECT DISTINCT n.object_id
             FROM {$wpdb->prefix}seo_nodes n
             INNER JOIN {$wpdb->posts} p ON p.ID=n.object_id AND p.post_type='page'
             WHERE n.object_type='page'
               AND n.seo_role='landing'
               AND n.status=1
               AND p.post_status IN ('publish','future')
               AND NOT EXISTS (
                    SELECT 1
                    FROM {$wpdb->prefix}seo_relations r
                    WHERE r.source_type='landing'
                      AND r.source_id=n.object_id
                      AND r.target_type='product_cat'
                      AND r.relation_type='landing_to_category'
               )"
        );
        foreach ((array) $landing_rows as $row) {
            $add('page', $row->object_id ?? 0, 'landing_without_product_category');
        }

        // Guias/Comparativas sin relacion comercial.
        $post_relation_rows = $wpdb->get_results(
            "SELECT DISTINCT p.ID, p.post_name
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_taxonomy_id=tr.term_taxonomy_id
               AND tt.taxonomy='category'
             INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
             WHERE p.post_type='post'
               AND p.post_status IN ('publish','future')
               AND t.slug IN ('guias-y-comparativas','guias','comparativas')
               AND NOT EXISTS (
                    SELECT 1
                    FROM {$wpdb->prefix}seo_relations r
                    WHERE r.source_type='post'
                      AND r.source_id=p.ID
                      AND r.target_type='product_cat'
                      AND r.relation_type='post_to_category'
               )"
        );

        $excluded = seo_reports_anomalies_json_excluded_post_slugs();
        foreach ((array) $post_relation_rows as $row) {
            $slug = sanitize_title((string) ($row->post_name ?? ''));
            if (!in_array($slug, $excluded, true)) {
                $add('post', $row->ID ?? 0, 'post_without_product_category');
            }
        }

        // Entradas editoriales sin Vocabulary canonico activo.
        $post_vocab_rows = $wpdb->get_results(
            "SELECT p.ID, p.post_name
             FROM {$wpdb->posts} p
             WHERE p.post_type='post'
               AND p.post_status IN ('publish','future')
               AND NOT EXISTS (
                    SELECT 1
                    FROM {$wpdb->prefix}seo_object_vocabulary ov
                    INNER JOIN {$wpdb->prefix}seo_vocabulary v
                       ON v.id=ov.vocabulary_id
                      AND v.active=1
                    WHERE ov.object_type='post'
                      AND ov.object_id=p.ID
                      AND ov.status=1
                      AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
               )"
        );
        foreach ((array) $post_vocab_rows as $row) {
            $slug = sanitize_title((string) ($row->post_name ?? ''));
            if (!in_array($slug, $excluded, true)) {
                $add('post', $row->ID ?? 0, 'post_without_vocabulary');
            }
        }

        uasort($targets, static function ($a, $b) {
            $type_cmp = strcmp((string) $a['object_type'], (string) $b['object_type']);
            return $type_cmp !== 0 ? $type_cmp : ((int) $a['object_id'] <=> (int) $b['object_id']);
        });

        return array_values($targets);
    }
}

if (!function_exists('seo_reports_anomalies_json_term_slugs')) {
    function seo_reports_anomalies_json_term_slugs($object_id, $taxonomy) {
        $terms = wp_get_object_terms(absint($object_id), sanitize_key($taxonomy), array('fields' => 'slugs'));
        return is_wp_error($terms) ? array() : array_values(array_unique(array_map('sanitize_title', (array) $terms)));
    }
}

if (!function_exists('seo_reports_anomalies_json_product_cat_slugs')) {
    function seo_reports_anomalies_json_product_cat_slugs($object_type, $object_id) {
        global $wpdb;

        $object_type = sanitize_key((string) $object_type);
        $object_id = absint($object_id);
        if (!$object_id || !in_array($object_type, array('post', 'page'), true)) {
            return array();
        }

        $source_type = $object_type === 'page' ? 'landing' : 'post';
        $relation_type = $object_type === 'page' ? 'landing_to_category' : 'post_to_category';

        return array_values(array_unique(array_filter(array_map(
            'sanitize_title',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT t.slug
                     FROM {$wpdb->prefix}seo_relations r
                     INNER JOIN {$wpdb->terms} t ON t.term_id=r.target_id
                     INNER JOIN {$wpdb->term_taxonomy} tt
                        ON tt.term_id=r.target_id
                       AND tt.taxonomy='product_cat'
                     WHERE r.source_type=%s
                       AND r.source_id=%d
                       AND r.target_type='product_cat'
                       AND r.relation_type=%s
                     ORDER BY t.slug ASC",
                    $source_type,
                    $object_id,
                    $relation_type
                )
            )
        ))));
    }
}

if (!function_exists('seo_reports_anomalies_json_vocabulary')) {
    function seo_reports_anomalies_json_vocabulary($object_type, $object_id) {
        global $wpdb;

        $out = array(
            'rol'        => array(),
            'tipo'       => array(),
            'aplicacion' => array(),
            'plataforma' => array(),
            'subtipo'    => array(),
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.semantic_group, v.slug
                 FROM {$wpdb->prefix}seo_object_vocabulary ov
                 INNER JOIN {$wpdb->prefix}seo_vocabulary v
                    ON v.id=ov.vocabulary_id
                   AND v.active=1
                 WHERE ov.object_type=%s
                   AND ov.object_id=%d
                   AND ov.status=1
                   AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                 ORDER BY FIELD(v.semantic_group,'rol','tipo','aplicacion','plataforma','subtipo'), v.slug ASC",
                sanitize_key((string) $object_type),
                absint($object_id)
            )
        );

        foreach ((array) $rows as $row) {
            $group = sanitize_key((string) ($row->semantic_group ?? ''));
            $slug = sanitize_title((string) ($row->slug ?? ''));
            if ($slug !== '' && isset($out[$group]) && !in_array($slug, $out[$group], true)) {
                $out[$group][] = $slug;
            }
        }

        return $out;
    }
}

if (!function_exists('seo_reports_anomalies_json_page_role')) {
    function seo_reports_anomalies_json_page_role($page_id) {
        global $wpdb;
        return sanitize_key((string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT seo_role
                 FROM {$wpdb->prefix}seo_nodes
                 WHERE object_type='page' AND object_id=%d AND status=1
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 1",
                absint($page_id)
            )
        ));
    }
}

if (!function_exists('seo_reports_anomalies_json_build_document')) {
    function seo_reports_anomalies_json_build_document() {
        $items = array();

        foreach (seo_reports_anomalies_json_collect_targets() as $target) {
            $object_type = (string) $target['object_type'];
            $object_id = absint($target['object_id']);
            $post = get_post($object_id);
            if (!$post || $post->post_type !== $object_type) {
                continue;
            }

            $item = array(
                'object_type'       => $object_type,
                'object_id'         => $object_id,
                'anomalies'         => array_values((array) $target['anomalies']),
                'title'             => (string) $post->post_title,
                'slug'              => (string) $post->post_name,
                'status'            => (string) $post->post_status,
                'excerpt'           => (string) $post->post_excerpt,
                'content'           => (string) $post->post_content,
                'url'               => (string) get_permalink($object_id),
                'product_cat_slugs' => seo_reports_anomalies_json_product_cat_slugs($object_type, $object_id),
                'vocabulary'        => seo_reports_anomalies_json_vocabulary($object_type, $object_id),
            );

            if ($object_type === 'post') {
                $item['wordpress_category_slugs'] = seo_reports_anomalies_json_term_slugs($object_id, 'category');
                $item['wordpress_tag_slugs'] = seo_reports_anomalies_json_term_slugs($object_id, 'post_tag');
            } else {
                $item['seo_role'] = seo_reports_anomalies_json_page_role($object_id);
            }

            $items[] = $item;
        }

        return array(
            'schema' => array(
                'name'    => 'seo-anomalias-contenidos',
                'version' => 1,
            ),
            'generated_at' => gmdate('c'),
            'site' => array(
                'home_url'       => home_url('/'),
                'plugin_version' => defined('SEO_SYSTEM_VERSION') ? SEO_SYSTEM_VERSION : '',
            ),
            'rules' => array(
                'existing_objects_only' => true,
                'creates_content'       => false,
                'deletes_content'       => false,
                'portable_terms'        => 'slug',
                'vocabulary_policy'     => 'protected_non_manual_assignments_are_preserved',
            ),
            'items' => $items,
        );
    }
}

if (!function_exists('seo_reports_anomalies_content_json_export_handler')) {
    function seo_reports_anomalies_content_json_export_handler() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para exportar contenidos de anomalías.', 'seo-taxonomy'));
        }
        check_admin_referer('seo_reports_anomalies_content_json_export');

        $payload = seo_reports_anomalies_json_build_document();

        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="seo-anomalias-contenidos-' . gmdate('Ymd-His') . '.json"');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('seo_reports_anomalies_json_notice_key')) {
    function seo_reports_anomalies_json_notice_key() {
        return 'seo_anomalies_json_notice_' . get_current_user_id();
    }
}

if (!function_exists('seo_reports_anomalies_json_set_notice')) {
    function seo_reports_anomalies_json_set_notice($type, $message, $stats = array()) {
        set_transient(
            seo_reports_anomalies_json_notice_key(),
            array(
                'type'    => sanitize_key((string) $type),
                'message' => sanitize_text_field((string) $message),
                'stats'   => array_map('absint', (array) $stats),
            ),
            MINUTE_IN_SECONDS * 10
        );
    }
}

if (!function_exists('seo_reports_anomalies_json_redirect')) {
    function seo_reports_anomalies_json_redirect() {
        wp_safe_redirect(admin_url('admin.php?page=seo-reports&tab=anomalias'));
        exit;
    }
}

if (!function_exists('seo_reports_anomalies_json_resolve_slugs')) {
    function seo_reports_anomalies_json_resolve_slugs($taxonomy, $slugs) {
        $taxonomy = sanitize_key((string) $taxonomy);
        $ids = array();

        foreach (array_values(array_unique(array_filter(array_map('sanitize_title', (array) $slugs)))) as $slug) {
            $term = get_term_by('slug', $slug, $taxonomy);
            if (!$term || is_wp_error($term)) {
                return new WP_Error(
                    'seo_anomalies_json_unknown_term',
                    sprintf('No existe %s con slug "%s".', $taxonomy, $slug)
                );
            }
            $ids[] = absint($term->term_id);
        }

        return array_values(array_unique(array_filter($ids)));
    }
}

if (!function_exists('seo_reports_anomalies_json_replace_product_categories')) {
    function seo_reports_anomalies_json_replace_product_categories($object_type, $object_id, $term_ids) {
        global $wpdb;

        $object_type = sanitize_key((string) $object_type);
        $object_id = absint($object_id);
        $term_ids = array_values(array_unique(array_filter(array_map('absint', (array) $term_ids))));

        if ($object_type === 'page' && seo_reports_anomalies_json_page_role($object_id) !== 'landing') {
            return new WP_Error('seo_anomalies_json_page_not_landing', 'Solo las páginas con rol landing admiten product_cat_slugs.');
        }

        $source_type = $object_type === 'page' ? 'landing' : 'post';
        $relation_type = $object_type === 'page' ? 'landing_to_category' : 'post_to_category';
        $table = $wpdb->prefix . 'seo_relations';

        $wpdb->query('START TRANSACTION');

        $deleted = $wpdb->delete(
            $table,
            array(
                'source_type'   => $source_type,
                'source_id'     => $object_id,
                'target_type'   => 'product_cat',
                'relation_type' => $relation_type,
            ),
            array('%s', '%d', '%s', '%s')
        );
        if ($deleted === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('seo_anomalies_json_relation_delete', 'No se pudieron sustituir las relaciones comerciales.');
        }

        foreach ($term_ids as $term_id) {
            $inserted = $wpdb->insert(
                $table,
                array(
                    'source_type'   => $source_type,
                    'source_id'     => $object_id,
                    'target_type'   => 'product_cat',
                    'target_id'     => $term_id,
                    'relation_type' => $relation_type,
                    'created_at'    => current_time('mysql'),
                ),
                array('%s', '%d', '%s', '%d', '%s', '%s')
            );
            if ($inserted === false) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('seo_anomalies_json_relation_insert', 'No se pudo guardar una relación comercial.');
            }
        }

        $wpdb->query('COMMIT');
        return true;
    }
}

if (!function_exists('seo_reports_anomalies_json_replace_manual_vocabulary')) {
    function seo_reports_anomalies_json_replace_manual_vocabulary($object_type, $object_id, $vocabulary_map) {
        global $wpdb;

        $object_type = sanitize_key((string) $object_type);
        $object_id = absint($object_id);
        $groups = array('rol','tipo','aplicacion','plataforma','subtipo');
        $vocab_table = $wpdb->prefix . 'seo_vocabulary';
        $objects_table = $wpdb->prefix . 'seo_object_vocabulary';

        foreach ($groups as $group) {
            if (!array_key_exists($group, (array) $vocabulary_map)) {
                continue;
            }

            $requested_slugs = array_values(array_unique(array_filter(array_map(
                'sanitize_title',
                (array) $vocabulary_map[$group]
            ))));
            $requested_ids = array();

            foreach ($requested_slugs as $slug) {
                $vocab_id = absint($wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id
                         FROM {$vocab_table}
                         WHERE semantic_group=%s AND slug=%s AND active=1
                         LIMIT 1",
                        $group,
                        $slug
                    )
                ));
                if (!$vocab_id) {
                    return new WP_Error(
                        'seo_anomalies_json_unknown_vocabulary',
                        sprintf('Vocabulary activo no encontrado: %s/%s.', $group, $slug)
                    );
                }
                $requested_ids[] = $vocab_id;
            }
            $requested_ids = array_values(array_unique($requested_ids));

            $protected_ids = array_map(
                'absint',
                (array) $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT ov.vocabulary_id
                         FROM {$objects_table} ov
                         INNER JOIN {$vocab_table} v
                            ON v.id=ov.vocabulary_id
                           AND v.semantic_group=%s
                         WHERE ov.object_type=%s
                           AND ov.object_id=%d
                           AND ov.status=1
                           AND ov.source NOT IN ('manual','anomalies_json')",
                        $group,
                        $object_type,
                        $object_id
                    )
                )
            );

            $manual_target = array_values(array_diff($requested_ids, $protected_ids));

            $updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$objects_table} ov
                     INNER JOIN {$vocab_table} v
                        ON v.id=ov.vocabulary_id
                       AND v.semantic_group=%s
                     SET ov.status=0, ov.updated_at=NOW()
                     WHERE ov.object_type=%s
                       AND ov.object_id=%d
                       AND ov.source IN ('manual','anomalies_json')",
                    $group,
                    $object_type,
                    $object_id
                )
            );
            if ($updated === false) {
                return new WP_Error('seo_anomalies_json_vocab_clear', 'No se pudieron actualizar las asignaciones manuales de Vocabulary.');
            }

            foreach ($manual_target as $vocab_id) {
                $saved = $wpdb->query(
                    $wpdb->prepare(
                        "INSERT INTO {$objects_table}
                            (object_type, object_id, vocabulary_id, source, confidence, status)
                         VALUES (%s, %d, %d, 'anomalies_json', 1.0000, 1)
                         ON DUPLICATE KEY UPDATE
                            status=1,
                            confidence=1.0000,
                            source=IF(source IN ('manual','anomalies_json'), 'anomalies_json', source),
                            updated_at=NOW()",
                        $object_type,
                        $object_id,
                        $vocab_id
                    )
                );
                if ($saved === false) {
                    return new WP_Error('seo_anomalies_json_vocab_save', 'No se pudo guardar una asignación de Vocabulary.');
                }
            }
        }

        return true;
    }
}

if (!function_exists('seo_reports_anomalies_json_apply_item')) {
    function seo_reports_anomalies_json_apply_item($item) {
        $object_type = sanitize_key((string) ($item['object_type'] ?? ''));
        $object_id = absint($item['object_id'] ?? 0);

        if (!$object_id || !in_array($object_type, array('post','page'), true)) {
            return new WP_Error('seo_anomalies_json_invalid_identity', 'Falta object_type/object_id válido.');
        }

        $current = get_post($object_id);
        if (!$current || $current->post_type !== $object_type || $current->post_status === 'trash') {
            return new WP_Error('seo_anomalies_json_missing_object', 'El contenido no existe, no coincide con object_type o está en papelera.');
        }

        $postarr = array('ID' => $object_id);
        $has_post_change = false;

        if (array_key_exists('title', $item)) {
            $postarr['post_title'] = sanitize_text_field((string) $item['title']);
            $has_post_change = true;
        }
        if (array_key_exists('slug', $item)) {
            $postarr['post_name'] = sanitize_title((string) $item['slug']);
            $has_post_change = true;
        }
        if (array_key_exists('excerpt', $item)) {
            $postarr['post_excerpt'] = wp_kses_post((string) $item['excerpt']);
            $has_post_change = true;
        }
        if (array_key_exists('content', $item)) {
            $postarr['post_content'] = wp_kses_post((string) $item['content']);
            $has_post_change = true;
        }
        if (array_key_exists('status', $item)) {
            $status = sanitize_key((string) $item['status']);
            if (!in_array($status, array('publish','future','draft','pending','private'), true)) {
                return new WP_Error('seo_anomalies_json_invalid_status', 'Estado WordPress no permitido: ' . $status);
            }
            $postarr['post_status'] = $status;
            $has_post_change = true;
        }

        if ($has_post_change) {
            $updated = wp_update_post(wp_slash($postarr), true);
            if (is_wp_error($updated)) {
                return $updated;
            }
        }

        if ($object_type === 'post' && array_key_exists('wordpress_category_slugs', $item)) {
            $category_ids = seo_reports_anomalies_json_resolve_slugs('category', $item['wordpress_category_slugs']);
            if (is_wp_error($category_ids)) {
                return $category_ids;
            }
            $saved = wp_set_post_terms($object_id, $category_ids, 'category', false);
            if (is_wp_error($saved)) {
                return $saved;
            }
        }

        if ($object_type === 'post' && array_key_exists('wordpress_tag_slugs', $item)) {
            $tag_ids = seo_reports_anomalies_json_resolve_slugs('post_tag', $item['wordpress_tag_slugs']);
            if (is_wp_error($tag_ids)) {
                return $tag_ids;
            }
            $saved = wp_set_post_terms($object_id, $tag_ids, 'post_tag', false);
            if (is_wp_error($saved)) {
                return $saved;
            }
        }

        if (array_key_exists('product_cat_slugs', $item)) {
            $product_cat_ids = seo_reports_anomalies_json_resolve_slugs('product_cat', $item['product_cat_slugs']);
            if (is_wp_error($product_cat_ids)) {
                return $product_cat_ids;
            }
            $saved = seo_reports_anomalies_json_replace_product_categories($object_type, $object_id, $product_cat_ids);
            if (is_wp_error($saved)) {
                return $saved;
            }
        }

        if (array_key_exists('vocabulary', $item)) {
            $saved = seo_reports_anomalies_json_replace_manual_vocabulary(
                $object_type,
                $object_id,
                (array) $item['vocabulary']
            );
            if (is_wp_error($saved)) {
                return $saved;
            }
        }

        clean_post_cache($object_id);
        return true;
    }
}

if (!function_exists('seo_reports_anomalies_content_json_import_handler')) {
    function seo_reports_anomalies_content_json_import_handler() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para importar contenidos de anomalías.', 'seo-taxonomy'));
        }
        check_admin_referer('seo_reports_anomalies_content_json_import');

        $file = $_FILES['anomalies_content_json'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Uploaded file metadata is validated below.
        if (!is_array($file) || empty($file['tmp_name']) || !isset($file['error']) || UPLOAD_ERR_OK !== (int) $file['error']) {
            seo_reports_anomalies_json_set_notice('error', 'No se recibió un archivo JSON válido.');
            seo_reports_anomalies_json_redirect();
        }

        $size = absint($file['size'] ?? 0);
        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        if ($size < 1 || $size > 16 * MB_IN_BYTES || strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'json') {
            seo_reports_anomalies_json_set_notice('error', 'El archivo debe ser JSON y no superar 16 MiB.');
            seo_reports_anomalies_json_redirect();
        }

        $tmp_name = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp_name)) {
            seo_reports_anomalies_json_set_notice('error', 'El archivo recibido no supera la validación de subida.');
            seo_reports_anomalies_json_redirect();
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        if (!$wp_filesystem) {
            seo_reports_anomalies_json_set_notice('error', 'No se pudo inicializar el sistema de archivos de WordPress.');
            seo_reports_anomalies_json_redirect();
        }

        $raw = $wp_filesystem->get_contents($tmp_name);
        if (!is_string($raw) || trim($raw) === '') {
            seo_reports_anomalies_json_set_notice('error', 'El JSON está vacío o no se pudo leer.');
            seo_reports_anomalies_json_redirect();
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload) || JSON_ERROR_NONE !== json_last_error()) {
            seo_reports_anomalies_json_set_notice('error', 'El archivo no contiene JSON válido.');
            seo_reports_anomalies_json_redirect();
        }

        $schema = (array) ($payload['schema'] ?? array());
        if (($schema['name'] ?? '') !== 'seo-anomalias-contenidos' || absint($schema['version'] ?? 0) !== 1) {
            seo_reports_anomalies_json_set_notice('error', 'Esquema JSON no compatible con Anomalías de contenidos.');
            seo_reports_anomalies_json_redirect();
        }

        $items = $payload['items'] ?? array();
        if (!is_array($items)) {
            seo_reports_anomalies_json_set_notice('error', 'El documento no contiene una lista items válida.');
            seo_reports_anomalies_json_redirect();
        }

        $stats = array(
            'processed' => 0,
            'updated'   => 0,
            'errors'    => 0,
        );
        $details = array();

        foreach ($items as $index => $item) {
            $stats['processed']++;
            if (!is_array($item)) {
                $stats['errors']++;
                $details[] = 'Fila ' . ($index + 1) . ': formato inválido.';
                continue;
            }

            $result = seo_reports_anomalies_json_apply_item($item);
            if (is_wp_error($result)) {
                $stats['errors']++;
                $details[] = 'Objeto ' . absint($item['object_id'] ?? 0) . ': ' . $result->get_error_message();
                continue;
            }

            $stats['updated']++;
        }

        $message = sprintf(
            'Importación JSON completada: %d procesados, %d actualizados, %d errores.',
            $stats['processed'],
            $stats['updated'],
            $stats['errors']
        );
        if ($details) {
            $message .= ' Primer error: ' . reset($details);
        }

        seo_reports_anomalies_json_set_notice($stats['errors'] > 0 ? 'warning' : 'success', $message, $stats);
        seo_reports_anomalies_json_redirect();
    }
}

if (!function_exists('seo_reports_anomalies_content_json_render_panel')) {
    function seo_reports_anomalies_content_json_render_panel() {
        $notice = get_transient(seo_reports_anomalies_json_notice_key());
        if (is_array($notice)) {
            delete_transient(seo_reports_anomalies_json_notice_key());
            $type = in_array(($notice['type'] ?? ''), array('success','warning','error'), true)
                ? $notice['type']
                : 'info';
            echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html((string) ($notice['message'] ?? '')) . '</p></div>';
        }

        $export_url = wp_nonce_url(
            admin_url('admin-post.php?action=seo_reports_anomalies_content_json_export'),
            'seo_reports_anomalies_content_json_export'
        );
        $count = count(seo_reports_anomalies_json_collect_targets());

        echo '<div style="background:#f6f7f7;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:14px 16px;margin:16px 0 22px;">';
        echo '<div style="display:flex;justify-content:space-between;gap:18px;align-items:flex-start;flex-wrap:wrap;">';
        echo '<div style="max-width:780px;">';
        echo '<h3 style="margin:0 0 6px;">JSON correctivo de contenidos</h3>';
        echo '<p style="margin:0;color:#50575e;">Exporta los posts y landings detectados por anomalías editoriales para corregir contenido, categorías WordPress, relaciones con <code>product_cat</code> y Vocabulary, y volver a importarlos. El importador solo actualiza IDs existentes: no crea ni borra contenidos o términos.</p>';
        echo '<p style="margin:7px 0 0;"><strong>Objetos incluidos ahora:</strong> ' . esc_html(number_format_i18n($count)) . '</p>';
        echo '</div>';
        echo '<div style="display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;">';
        echo '<a class="button button-secondary" href="' . esc_url($export_url) . '">Descargar JSON</a>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data" style="display:flex;gap:7px;align-items:center;flex-wrap:wrap;">';
        echo '<input type="hidden" name="action" value="seo_reports_anomalies_content_json_import">';
        wp_nonce_field('seo_reports_anomalies_content_json_import');
        echo '<input type="file" name="anomalies_content_json" accept=".json,application/json" required>';
        echo '<button type="submit" class="button button-primary">Importar JSON</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
}

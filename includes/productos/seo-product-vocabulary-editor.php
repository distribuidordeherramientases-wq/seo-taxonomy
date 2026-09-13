<?php
/**
 * Editor manual del vocabulario semántico en la ficha nativa de producto.
 *
 * TIPO y ROL se muestran como identidad canónica de solo lectura.
 * APLICACION, PLATAFORMA y SUBTIPO son editables y cualquier selección
 * guardada por un usuario pasa a source=manual, confidence=1.0000.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_product_vocab_editor_groups')) {
    function seo_product_vocab_editor_groups() {
        return [
            'aplicacion' => 'APLICACIÓN',
            'plataforma' => 'PLATAFORMA',
            'subtipo'    => 'SUBTIPO',
        ];
    }
}

if (!function_exists('seo_product_vocab_editor_tables_ready')) {
    function seo_product_vocab_editor_tables_ready() {
        global $wpdb;

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';

        if (function_exists('seo_catalog_table_exists')) {
            return seo_catalog_table_exists($vocabulary) && seo_catalog_table_exists($objects);
        }

        $v_exists = (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($vocabulary))
        ) === $vocabulary;
        $o_exists = (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($objects))
        ) === $objects;

        return $v_exists && $o_exists;
    }
}

if (!function_exists('seo_product_vocab_editor_get_terms')) {
    function seo_product_vocab_editor_get_terms($group) {
        global $wpdb;

        $group = sanitize_key($group);
        if (!array_key_exists($group, seo_product_vocab_editor_groups())) {
            return [];
        }

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, slug, label, source
                 FROM {$vocabulary}
                 WHERE semantic_group = %s
                   AND active = 1
                 ORDER BY label ASC, slug ASC",
                $group
            ),
            ARRAY_A
        );
    }
}

if (!function_exists('seo_product_vocab_editor_get_assignments')) {
    function seo_product_vocab_editor_get_assignments($product_id) {
        global $wpdb;

        $product_id = absint($product_id);
        $result = [
            'rol' => [],
            'tipo' => [],
            'aplicacion' => [],
            'plataforma' => [],
            'subtipo' => [],
        ];

        if (!$product_id || !seo_product_vocab_editor_tables_ready()) {
            return $result;
        }

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.id, v.semantic_group, v.slug, v.label,
                        ov.source, ov.confidence
                 FROM {$objects} ov
                 JOIN {$vocabulary} v
                   ON v.id = ov.vocabulary_id
                  AND v.active = 1
                 WHERE ov.object_type = 'product'
                   AND ov.object_id = %d
                   AND ov.status = 1
                   AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                 ORDER BY v.semantic_group ASC, v.label ASC, v.slug ASC",
                $product_id
            ),
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $group = (string) ($row['semantic_group'] ?? '');
            if (isset($result[$group])) {
                $result[$group][] = $row;
            }
        }

        return $result;
    }
}

if (!function_exists('seo_product_vocab_editor_assignment_badge')) {
    function seo_product_vocab_editor_assignment_badge(array $row) {
        $source = trim((string) ($row['source'] ?? ''));
        $confidence = $row['confidence'];
        $meta = [];
        if ($source !== '') {
            $meta[] = $source;
        }
        if ($confidence !== null && $confidence !== '') {
            $meta[] = number_format_i18n(((float) $confidence) * 100, 0) . '%';
        }

        $title = $meta ? ' title="' . esc_attr(implode(' · ', $meta)) . '"' : '';
        return '<span style="display:inline-block;margin:0 5px 5px 0;padding:4px 8px;border:1px solid #c3c4c7;border-radius:999px;background:#f6f7f7;"' . $title . '>'
            . esc_html((string) ($row['label'] ?? $row['slug'] ?? ''))
            . '</span>';
    }
}

if (!function_exists('seo_product_vocab_editor_render_fields')) {
    function seo_product_vocab_editor_render_fields($product_id) {
        $product_id = absint($product_id);
        if ($product_id < 1 || !seo_product_vocab_editor_tables_ready()) {
            echo '<p>No están disponibles las tablas del vocabulario canónico.</p>';
            return;
        }

        $assignments = seo_product_vocab_editor_get_assignments($product_id);
        wp_nonce_field('seo_product_vocab_editor_save', 'seo_product_vocab_editor_nonce');

        echo '<div style="padding:12px 12px 4px;">';
        echo '<p style="margin-top:0;color:#50575e;">TIPO y Ámbito/ROL son la identidad canónica. APLICACIÓN, PLATAFORMA y SUBTIPO son editables.</p>';

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:10px 0 18px;">';
        foreach (['rol' => 'Ámbito / ROL', 'tipo' => 'TIPO'] as $group => $label) {
            echo '<div style="border:1px solid #dcdcde;border-radius:5px;padding:10px;background:#f9f9f9;">';
            echo '<strong>' . esc_html($label) . '</strong><div style="margin-top:7px;">';
            if (empty($assignments[$group])) {
                echo '<span style="color:#b32d2e;">Sin asignación</span>';
            } else {
                foreach ($assignments[$group] as $row) {
                    echo wp_kses_post(seo_product_vocab_editor_assignment_badge($row));
                }
            }
            echo '</div></div>';
        }
        echo '</div>';

        foreach (seo_product_vocab_editor_groups() as $group => $label) {
            $terms = seo_product_vocab_editor_get_terms($group);
            $selected = array_map('intval', array_column($assignments[$group], 'id'));
            $current_labels = array_map(
                static function ($row) {
                    return (string) ($row['label'] ?? $row['slug'] ?? '');
                },
                $assignments[$group]
            );

            echo '<div style="border-top:1px solid #dcdcde;padding:14px 0;">';
            echo '<label for="seo-product-vocab-' . esc_attr($group) . '" style="display:block;font-weight:700;margin-bottom:5px;">' . esc_html($label) . '</label>';
            echo '<div style="font-size:12px;color:#646970;margin-bottom:6px;">Actual: ' . esc_html($current_labels ? implode(' · ', $current_labels) : '—') . '</div>';
            echo '<select id="seo-product-vocab-' . esc_attr($group) . '" name="seo_product_vocab[' . esc_attr($group) . '][]" multiple size="' . ($group === 'plataforma' ? '6' : '8') . '" style="width:100%;min-height:' . ($group === 'plataforma' ? '125px' : '175px') . ';">';
            foreach ($terms as $term) {
                $term_id = (int) ($term['id'] ?? 0);
                if (!$term_id) {
                    continue;
                }
                echo '<option value="' . esc_attr($term_id) . '" ' . selected(in_array($term_id, $selected, true), true, false) . '>'
                    . esc_html((string) ($term['label'] ?? $term['slug'] ?? ''))
                    . '</option>';
            }
            echo '</select>';
            echo '<p style="margin:5px 0 0;font-size:12px;color:#646970;">Ctrl/Cmd + clic permite seleccionar varias.</p>';
            echo '<input type="text" name="seo_product_vocab_new[' . esc_attr($group) . ']" value="" style="width:100%;margin-top:7px;" placeholder="Añadir término nuevo (opcional; varios separados por coma)">';
            echo '</div>';
        }

        echo '<p style="margin-bottom:8px;color:#646970;font-size:12px;">Las etiquetas legacy de producto están retiradas. Los atributos técnicos permanecen separados del vocabulario semántico.</p>';
        echo '</div>';
    }
}

if (!function_exists('seo_product_vocab_editor_product_data_tab')) {
    function seo_product_vocab_editor_product_data_tab($tabs) {
        $tabs['seo_semantic_labels'] = [
            'label'    => 'Etiquetas semánticas',
            'target'   => 'seo_semantic_labels_product_data',
            'class'    => [],
            'priority' => 85,
        ];
        return $tabs;
    }
    add_filter('woocommerce_product_data_tabs', 'seo_product_vocab_editor_product_data_tab', 30);
}

if (!function_exists('seo_product_vocab_editor_product_data_panel')) {
    function seo_product_vocab_editor_product_data_panel() {
        global $post;
        if (!($post instanceof WP_Post) || $post->post_type !== 'product') {
            return;
        }
        echo '<div id="seo_semantic_labels_product_data" class="panel woocommerce_options_panel hidden">';
        seo_product_vocab_editor_render_fields($post->ID);
        echo '</div>';
    }
    add_action('woocommerce_product_data_panels', 'seo_product_vocab_editor_product_data_panel', 30);
}

if (!function_exists('seo_product_vocab_editor_create_term')) {
    function seo_product_vocab_editor_create_term($group, $label) {
        global $wpdb;

        $group = sanitize_key($group);
        $label = sanitize_text_field($label);
        if (!array_key_exists($group, seo_product_vocab_editor_groups()) || $label === '') {
            return 0;
        }

        $slug = sanitize_title($label);
        if ($slug === '') {
            return 0;
        }

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$vocabulary}
                 WHERE semantic_group = %s AND slug = %s
                 LIMIT 1",
                $group,
                $slug
            )
        );

        if ($existing > 0) {
            $wpdb->update(
                $vocabulary,
                ['active' => 1],
                ['id' => $existing],
                ['%d'],
                ['%d']
            );
            return $existing;
        }

        $inserted = $wpdb->insert(
            $vocabulary,
            [
                'semantic_group' => $group,
                'slug' => $slug,
                'label' => $label,
                'parent_id' => null,
                'source' => 'manual',
                'active' => 1,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%d']
        );

        return $inserted ? (int) $wpdb->insert_id : 0;
    }
}

if (!function_exists('seo_product_vocab_editor_replace_group')) {
    function seo_product_vocab_editor_replace_group($product_id, $group, array $vocabulary_ids) {
        global $wpdb;

        $product_id = absint($product_id);
        $group = sanitize_key($group);
        if (!$product_id || !array_key_exists($group, seo_product_vocab_editor_groups())) {
            return;
        }

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';

        // Validar la seleccion recibida contra el vocabulario activo del grupo.
        $vocabulary_ids = array_values(array_unique(array_filter(array_map('absint', $vocabulary_ids))));
        if ($vocabulary_ids) {
            $placeholders = implode(',', array_fill(0, count($vocabulary_ids), '%d'));
            $params = array_merge([$group], $vocabulary_ids);
            $valid_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$vocabulary}
                     WHERE semantic_group = %s
                       AND active = 1
                       AND id IN ({$placeholders})",
                    ...$params
                )
            );
            $vocabulary_ids = array_values(array_unique(array_map('intval', $valid_ids)));
        }

        // Leer las asignaciones activas actuales. Las que no cambian se dejan intactas
        // para conservar su source y confidence originales.
        $current_ids = array_values(array_unique(array_map(
            'intval',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ov.vocabulary_id
                     FROM {$objects} ov
                     JOIN {$vocabulary} v
                       ON v.id = ov.vocabulary_id
                      AND v.semantic_group = %s
                     WHERE ov.object_type = 'product'
                       AND ov.object_id = %d
                       AND ov.status = 1",
                    $group,
                    $product_id
                )
            )
        )));

        $to_remove = array_values(array_diff($current_ids, $vocabulary_ids));
        $to_add = array_values(array_diff($vocabulary_ids, $current_ids));

        // Desactivar solo lo que el usuario ha quitado.
        if ($to_remove) {
            $placeholders = implode(',', array_fill(0, count($to_remove), '%d'));
            $params = array_merge([$product_id, $group], $to_remove);
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$objects} ov
                     JOIN {$vocabulary} v ON v.id = ov.vocabulary_id
                     SET ov.status = 0,
                         ov.updated_at = NOW()
                     WHERE ov.object_type = 'product'
                       AND ov.object_id = %d
                       AND v.semantic_group = %s
                       AND ov.vocabulary_id IN ({$placeholders})",
                    ...$params
                )
            );
        }

        // Solo las asignaciones realmente nuevas pasan a manual.
        foreach ($to_add as $vocabulary_id) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$objects}
                        (object_type, object_id, vocabulary_id, source, confidence, status)
                     VALUES ('product', %d, %d, 'manual', 1.0000, 1)
                     ON DUPLICATE KEY UPDATE
                        source = 'manual',
                        confidence = 1.0000,
                        status = 1,
                        updated_at = NOW()",
                    $product_id,
                    $vocabulary_id
                )
            );
        }
    }
}

if (!function_exists('seo_product_vocab_editor_save')) {
    function seo_product_vocab_editor_save($post_id, $post) {
        if (!($post instanceof WP_Post) || $post->post_type !== 'product') {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (empty($_POST['seo_product_vocab_editor_nonce']) || !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['seo_product_vocab_editor_nonce'])),
            'seo_product_vocab_editor_save'
        )) {
            return;
        }
        if (!seo_product_vocab_editor_tables_ready()) {
            return;
        }

        $posted = isset($_POST['seo_product_vocab']) && is_array($_POST['seo_product_vocab'])
            ? wp_unslash($_POST['seo_product_vocab'])
            : [];
        $new_terms = isset($_POST['seo_product_vocab_new']) && is_array($_POST['seo_product_vocab_new'])
            ? wp_unslash($_POST['seo_product_vocab_new'])
            : [];

        foreach (seo_product_vocab_editor_groups() as $group => $label) {
            $selected = isset($posted[$group]) && is_array($posted[$group])
                ? array_map('absint', $posted[$group])
                : [];

            $raw_new = trim((string) ($new_terms[$group] ?? ''));
            if ($raw_new !== '') {
                $labels = preg_split('/\s*,\s*/u', $raw_new, -1, PREG_SPLIT_NO_EMPTY);
                foreach ((array) $labels as $new_label) {
                    $new_id = seo_product_vocab_editor_create_term($group, $new_label);
                    if ($new_id > 0) {
                        $selected[] = $new_id;
                    }
                }
            }

            seo_product_vocab_editor_replace_group($post_id, $group, $selected);
        }
    }
    add_action('save_post_product', 'seo_product_vocab_editor_save', 30, 2);
}

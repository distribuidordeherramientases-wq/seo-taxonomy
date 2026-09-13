<?php
/**
 * Vocabulary canonico para contenido editorial gestionado por SEO Taxonomy.
 *
 * Los posts usan wp_seo_object_vocabulary como unica fuente semantica.
 * Las taxonomias nativas de WordPress (post_tag) quedan fuera de esta capa.
 *
 * Version: 2026-09-08
 * Build: 1
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_content_vocab_groups')) {
    function seo_content_vocab_groups() {
        return array(
            'rol'        => 'ROL',
            'tipo'       => 'TIPO',
            'aplicacion' => 'APLICACION',
            'plataforma' => 'PLATAFORMA',
            'subtipo'    => 'SUBTIPO',
        );
    }
}

if (!function_exists('seo_content_vocab_allowed_object_types')) {
    function seo_content_vocab_allowed_object_types() {
        return array('post');
    }
}

if (!function_exists('seo_content_vocab_tables_ready')) {
    function seo_content_vocab_tables_ready() {
        global $wpdb;
        static $ready = null;

        if (null !== $ready) {
            return $ready;
        }

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects    = $wpdb->prefix . 'seo_object_vocabulary';

        $v_exists = (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($vocabulary))
        ) === $vocabulary;
        $o_exists = (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($objects))
        ) === $objects;

        $ready = $v_exists && $o_exists;
        return $ready;
    }
}

if (!function_exists('seo_content_vocab_validate_object')) {
    function seo_content_vocab_validate_object($object_type, $object_id) {
        $object_type = sanitize_key((string) $object_type);
        $object_id   = absint($object_id);

        if (!in_array($object_type, seo_content_vocab_allowed_object_types(), true) || !$object_id) {
            return false;
        }

        if ('post' === $object_type) {
            return 'post' === get_post_type($object_id);
        }

        return false;
    }
}

if (!function_exists('seo_content_vocab_get_terms')) {
    function seo_content_vocab_get_terms($group) {
        global $wpdb;
        static $cache = array();

        $group = sanitize_key((string) $group);
        if (!isset(seo_content_vocab_groups()[$group]) || !seo_content_vocab_tables_ready()) {
            return array();
        }
        if (isset($cache[$group])) {
            return $cache[$group];
        }

        $cache[$group] = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, semantic_group, slug, label, source
                 FROM {$wpdb->prefix}seo_vocabulary
                 WHERE semantic_group = %s
                   AND active = 1
                 ORDER BY label ASC, slug ASC",
                $group
            ),
            ARRAY_A
        );

        return $cache[$group];
    }
}

if (!function_exists('seo_content_vocab_get_assignments')) {
    function seo_content_vocab_get_assignments($object_type, $object_id) {
        global $wpdb;

        $object_type = sanitize_key((string) $object_type);
        $object_id   = absint($object_id);
        $result      = array();

        foreach (seo_content_vocab_groups() as $group => $label) {
            $result[$group] = array();
        }

        if (!seo_content_vocab_validate_object($object_type, $object_id) || !seo_content_vocab_tables_ready()) {
            return $result;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.id, v.semantic_group, v.slug, v.label,
                        ov.source, ov.confidence
                 FROM {$wpdb->prefix}seo_object_vocabulary ov
                 INNER JOIN {$wpdb->prefix}seo_vocabulary v
                    ON v.id = ov.vocabulary_id
                   AND v.active = 1
                 WHERE ov.object_type = %s
                   AND ov.object_id = %d
                   AND ov.status = 1
                   AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                 ORDER BY FIELD(v.semantic_group,'rol','tipo','aplicacion','plataforma','subtipo'),
                          v.label ASC, v.slug ASC",
                $object_type,
                $object_id
            ),
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            if (isset($result[$group])) {
                $result[$group][] = $row;
            }
        }

        return $result;
    }
}

if (!function_exists('seo_content_vocab_get_flat_terms')) {
    function seo_content_vocab_get_flat_terms($object_type, $object_id) {
        $assignments = seo_content_vocab_get_assignments($object_type, $object_id);
        $out = array();

        foreach (seo_content_vocab_groups() as $group => $group_label) {
            foreach ((array) ($assignments[$group] ?? array()) as $row) {
                $row['semantic_group'] = $group;
                $row['group_label'] = $group_label;
                $out[] = $row;
            }
        }

        return $out;
    }
}

if (!function_exists('seo_content_vocab_get_flat_labels')) {
    function seo_content_vocab_get_flat_labels($object_type, $object_id) {
        $labels = array();
        foreach (seo_content_vocab_get_flat_terms($object_type, $object_id) as $row) {
            $label = trim((string) ($row['label'] ?? $row['slug'] ?? ''));
            if ('' !== $label) {
                $labels[] = $label;
            }
        }
        return array_values(array_unique($labels));
    }
}

if (!function_exists('seo_content_vocab_get_grouped_label_map')) {
    function seo_content_vocab_get_grouped_label_map($object_type, $object_id) {
        $assignments = seo_content_vocab_get_assignments($object_type, $object_id);
        $out = array();

        foreach (seo_content_vocab_groups() as $group => $label) {
            $values = array();
            foreach ((array) ($assignments[$group] ?? array()) as $row) {
                $value = trim((string) ($row['label'] ?? $row['slug'] ?? ''));
                if ('' !== $value) {
                    $values[] = $value;
                }
            }
            $out[$group] = array_values(array_unique($values));
        }

        return $out;
    }
}

if (!function_exists('seo_content_vocab_replace_manual_group')) {
    /**
     * Sustituye solo las asignaciones manuales de un grupo semantico.
     * Las asignaciones de otras fuentes se conservan intactas.
     */
    function seo_content_vocab_replace_manual_group($object_type, $object_id, $group, array $vocabulary_ids) {
        global $wpdb;

        $object_type = sanitize_key((string) $object_type);
        $object_id   = absint($object_id);
        $group       = sanitize_key((string) $group);

        if (!seo_content_vocab_validate_object($object_type, $object_id)) {
            return new WP_Error('seo_content_vocab_invalid_object', 'El objeto editorial indicado no es valido.');
        }
        if (!isset(seo_content_vocab_groups()[$group])) {
            return new WP_Error('seo_content_vocab_invalid_group', 'El grupo semantico indicado no es valido.');
        }
        if (!seo_content_vocab_tables_ready()) {
            return new WP_Error('seo_content_vocab_tables', 'No estan disponibles las tablas del Vocabulary canonico.');
        }

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects    = $wpdb->prefix . 'seo_object_vocabulary';

        $requested_ids = array_values(array_unique(array_filter(array_map('absint', $vocabulary_ids))));
        $valid_ids = array();

        if ($requested_ids) {
            $placeholders = implode(',', array_fill(0, count($requested_ids), '%d'));
            $sql = "SELECT id
                    FROM {$vocabulary}
                    WHERE semantic_group = %s
                      AND active = 1
                      AND id IN ({$placeholders})";
            $params = array_merge(array($group), $requested_ids);
            $valid_ids = array_values(array_unique(array_map(
                'intval',
                (array) $wpdb->get_col($wpdb->prepare($sql, $params))
            )));

            if (count($valid_ids) !== count($requested_ids)) {
                return new WP_Error(
                    'seo_content_vocab_unknown_term',
                    'La seleccion contiene terminos que ya no existen o no pertenecen al grupo ' . strtoupper($group) . '.'
                );
            }
        }

        $current_ids = array_map(
            'intval',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ov.vocabulary_id
                     FROM {$objects} ov
                     INNER JOIN {$vocabulary} v
                        ON v.id = ov.vocabulary_id
                       AND v.semantic_group = %s
                     WHERE ov.object_type = %s
                       AND ov.object_id = %d
                       AND ov.status = 1
                       AND ov.source = 'manual'",
                    $group,
                    $object_type,
                    $object_id
                )
            )
        );
        $current_ids = array_values(array_unique($current_ids));

        $to_remove = array_values(array_diff($current_ids, $valid_ids));
        $to_add    = array_values(array_diff($valid_ids, $current_ids));

        if ($to_remove) {
            $placeholders = implode(',', array_fill(0, count($to_remove), '%d'));
            $sql = "UPDATE {$objects} ov
                    INNER JOIN {$vocabulary} v ON v.id = ov.vocabulary_id
                    SET ov.status = 0,
                        ov.updated_at = NOW()
                    WHERE ov.object_type = %s
                      AND ov.object_id = %d
                      AND ov.source = 'manual'
                      AND v.semantic_group = %s
                      AND ov.vocabulary_id IN ({$placeholders})";
            $params = array_merge(array($object_type, $object_id, $group), $to_remove);
            $updated = $wpdb->query($wpdb->prepare($sql, $params));
            if (false === $updated) {
                return new WP_Error('seo_content_vocab_remove', 'No se pudieron retirar asignaciones de Vocabulary: ' . $wpdb->last_error);
            }
        }

        foreach ($to_add as $vocabulary_id) {
            $saved = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$objects}
                        (object_type, object_id, vocabulary_id, source, confidence, status)
                     VALUES (%s, %d, %d, 'manual', 1.0000, 1)
                     ON DUPLICATE KEY UPDATE
                        source = 'manual',
                        confidence = 1.0000,
                        status = 1,
                        updated_at = NOW()",
                    $object_type,
                    $object_id,
                    $vocabulary_id
                )
            );
            if (false === $saved) {
                return new WP_Error('seo_content_vocab_add', 'No se pudo guardar una asignacion de Vocabulary: ' . $wpdb->last_error);
            }
        }

        return true;
    }
}

if (!function_exists('seo_content_vocab_save_from_request')) {
    function seo_content_vocab_save_from_request($object_type, $object_id, $field_name) {
        $object_type = sanitize_key((string) $object_type);
        $object_id   = absint($object_id);
        $field_name  = sanitize_key((string) $field_name);

        if (!seo_content_vocab_validate_object($object_type, $object_id) || '' === $field_name) {
            return new WP_Error('seo_content_vocab_request', 'No se pudo interpretar la seleccion de Vocabulary.');
        }

        $marker = $field_name . '_present';
        if (empty($_POST[$marker])) {
            return true;
        }

        $posted = isset($_POST[$field_name]) && is_array($_POST[$field_name])
            ? wp_unslash($_POST[$field_name])
            : array();

        foreach (seo_content_vocab_groups() as $group => $label) {
            $selected = isset($posted[$group]) && is_array($posted[$group])
                ? array_map('absint', $posted[$group])
                : array();

            $result = seo_content_vocab_replace_manual_group($object_type, $object_id, $group, $selected);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        return true;
    }
}

if (!function_exists('seo_content_vocab_render_fields')) {
    function seo_content_vocab_render_fields($object_type, $object_id, $field_name = 'seo_content_vocab', $compact = false) {
        $object_type = sanitize_key((string) $object_type);
        $object_id   = absint($object_id);
        $field_name  = sanitize_key((string) $field_name);

        if (!in_array($object_type, seo_content_vocab_allowed_object_types(), true)) {
            return;
        }
        if (!seo_content_vocab_tables_ready()) {
            echo '<div class="notice notice-error inline"><p>No estan disponibles las tablas del Vocabulary canonico.</p></div>';
            return;
        }

        $assignments = $object_id > 0
            ? seo_content_vocab_get_assignments($object_type, $object_id)
            : array();

        echo '<div class="seo-content-vocabulary">';
        echo '<input type="hidden" name="' . esc_attr($field_name) . '_present" value="1">';
        echo '<p style="margin:0 0 12px;color:#50575e;font-size:12px;line-height:1.5;">Fuente semantica canonica: <code>wp_seo_object_vocabulary</code> con <code>object_type=' . esc_html($object_type) . '</code>. No se leen ni se escriben <code>post_tag</code>.</p>';

        $grid = $compact ? '1fr' : 'repeat(2,minmax(280px,1fr))';
        echo '<div style="display:grid;grid-template-columns:' . esc_attr($grid) . ';gap:12px;">';

        foreach (seo_content_vocab_groups() as $group => $label) {
            $terms = seo_content_vocab_get_terms($group);
            $group_assignments = isset($assignments[$group]) ? (array) $assignments[$group] : array();
            $manual_selected = array();
            $protected = array();

            foreach ($group_assignments as $assignment) {
                if ('manual' === (string) ($assignment['source'] ?? '')) {
                    $manual_selected[] = (int) ($assignment['id'] ?? 0);
                } else {
                    $protected[] = $assignment;
                }
            }

            echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:5px;padding:10px;">';
            echo '<label style="display:block;font-weight:700;margin-bottom:6px;">' . esc_html($label) . '</label>';

            if ($protected) {
                echo '<div style="margin:0 0 7px;font-size:11px;color:#646970;">Asignaciones no manuales (no se alteran aqui): ';
                foreach ($protected as $assignment) {
                    echo '<span style="display:inline-block;margin:2px 3px;padding:2px 6px;border-radius:999px;background:#eef5ff;">' . esc_html((string) ($assignment['label'] ?? $assignment['slug'] ?? '')) . '</span>';
                }
                echo '</div>';
            }

            echo '<select name="' . esc_attr($field_name) . '[' . esc_attr($group) . '][]" multiple size="' . ($compact ? '5' : '7') . '" style="width:100%;min-height:' . ($compact ? '108px' : '150px') . ';">';
            foreach ($terms as $term) {
                $term_id = (int) ($term['id'] ?? 0);
                if (!$term_id) {
                    continue;
                }
                echo '<option value="' . esc_attr($term_id) . '" ' . selected(in_array($term_id, $manual_selected, true), true, false) . '>'
                    . esc_html((string) ($term['label'] ?? $term['slug'] ?? ''))
                    . '</option>';
            }
            echo '</select>';
            echo '<div style="margin-top:5px;font-size:11px;color:#646970;">Ctrl/Cmd + clic para seleccion multiple.</div>';
            echo '</div>';
        }

        echo '</div>';
        echo '<p style="margin:10px 0 0;font-size:12px;color:#646970;">Los terminos nuevos se crean desde <a href="' . esc_url(admin_url('admin.php?page=seo-tags-vocabulary')) . '">SEO Taxonomy - Etiquetas</a>; aqui solo se asignan terminos existentes.</p>';
        echo '</div>';
    }
}

if (!function_exists('seo_content_vocab_render_summary')) {
    function seo_content_vocab_render_summary($object_type, $object_id, $limit = 0) {
        $rows = seo_content_vocab_get_flat_terms($object_type, $object_id);
        if ($limit > 0) {
            $rows = array_slice($rows, 0, absint($limit));
        }

        if (!$rows) {
            echo '<span style="color:#b32d2e;font-size:12px;">Sin Vocabulary</span>';
            return;
        }

        foreach ($rows as $row) {
            echo '<span style="display:inline-block;margin:2px 4px 2px 0;padding:2px 6px;border:1px solid #c3c4c7;border-radius:999px;background:#fff;font-size:11px;">'
                . '<strong>' . esc_html((string) ($row['group_label'] ?? strtoupper((string) ($row['semantic_group'] ?? '')))) . ':</strong> '
                . esc_html((string) ($row['label'] ?? $row['slug'] ?? ''))
                . '</span>';
        }
    }
}

if (!function_exists('seo_content_vocab_export_group')) {
    function seo_content_vocab_export_group($object_type, $object_id, $group, $field = 'slug', $source = 'manual') {
        global $wpdb;

        $object_type = sanitize_key((string) $object_type);
        $object_id   = absint($object_id);
        $group       = sanitize_key((string) $group);
        $field       = 'label' === $field ? 'label' : 'slug';
        $source      = sanitize_key((string) $source);

        if (!seo_content_vocab_validate_object($object_type, $object_id) || !isset(seo_content_vocab_groups()[$group]) || !seo_content_vocab_tables_ready()) {
            return array();
        }

        $sql = "SELECT DISTINCT v.{$field}
                FROM {$wpdb->prefix}seo_object_vocabulary ov
                INNER JOIN {$wpdb->prefix}seo_vocabulary v
                   ON v.id = ov.vocabulary_id
                  AND v.active = 1
                WHERE ov.object_type = %s
                  AND ov.object_id = %d
                  AND ov.status = 1
                  AND v.semantic_group = %s";
        $params = array($object_type, $object_id, $group);

        if ('' !== $source) {
            $sql .= ' AND ov.source = %s';
            $params[] = $source;
        }
        $sql .= " ORDER BY v.{$field} ASC";

        return array_values(array_filter(array_map(
            'strval',
            (array) $wpdb->get_col($wpdb->prepare($sql, $params))
        )));
    }
}

if (!function_exists('seo_content_vocab_validate_import_row')) {
    /**
     * Valida vocab_* sin escribir. Se usa tambien en simulacion del importador.
     */
    function seo_content_vocab_validate_import_row(array $row, $line, array &$log) {
        global $wpdb;

        if (!seo_content_vocab_tables_ready()) {
            $message = sprintf('Fila %d: no estan disponibles las tablas del Vocabulary canonico.', (int) $line);
            if (function_exists('seo_ie_add_log_warning')) {
                seo_ie_add_log_warning($log, $message);
            }
            return false;
        }

        $valid = true;
        foreach (seo_content_vocab_groups() as $group => $label) {
            $column = 'vocab_' . $group;
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $raw_values = function_exists('seo_ie_decode_post_list')
                ? seo_ie_decode_post_list($row[$column])
                : array_filter(array_map('trim', explode(',', (string) $row[$column])));
            $slugs = array_values(array_unique(array_filter(array_map('sanitize_title', (array) $raw_values))));
            if (!$slugs) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($slugs), '%s'));
            $sql = "SELECT slug
                    FROM {$wpdb->prefix}seo_vocabulary
                    WHERE semantic_group = %s
                      AND active = 1
                      AND slug IN ({$placeholders})";
            $params = array_merge(array($group), $slugs);
            $found = array_map('strval', (array) $wpdb->get_col($wpdb->prepare($sql, $params)));
            $missing = array_values(array_diff($slugs, $found));
            if (!$missing) {
                continue;
            }

            $valid = false;
            $message = sprintf(
                'Fila %d: %s contiene terminos que no existen en Vocabulary: %s.',
                (int) $line,
                $column,
                implode(', ', $missing)
            );
            if (function_exists('seo_ie_add_log_warning')) {
                seo_ie_add_log_warning($log, $message);
            } else {
                $log['advertencias'] = isset($log['advertencias']) ? ((int) $log['advertencias'] + 1) : 1;
                $log['detalles'][] = $message;
            }
        }

        return $valid;
    }
}

if (!function_exists('seo_content_vocab_import_row')) {
    /**
     * Importa solo las columnas vocab_* presentes en la fila.
     * Nunca crea Vocabulary. Si falta un slug solicitado, el grupo completo
     * permanece sin cambios para evitar clasificaciones parciales.
     */
    function seo_content_vocab_import_row($object_type, $object_id, array $row, $line, array &$log) {
        global $wpdb;

        $object_type = sanitize_key((string) $object_type);
        $object_id   = absint($object_id);
        if (!seo_content_vocab_validate_object($object_type, $object_id) || !seo_content_vocab_tables_ready()) {
            return;
        }

        foreach (seo_content_vocab_groups() as $group => $label) {
            $column = 'vocab_' . $group;
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $raw_values = function_exists('seo_ie_decode_post_list')
                ? seo_ie_decode_post_list($row[$column])
                : array_filter(array_map('trim', explode(',', (string) $row[$column])));

            $slugs = array_values(array_unique(array_filter(array_map('sanitize_title', (array) $raw_values))));
            if (!$slugs) {
                $result = seo_content_vocab_replace_manual_group($object_type, $object_id, $group, array());
                if (is_wp_error($result)) {
                    $message = sprintf('Fila %d, %s %d: %s', (int) $line, $object_type, $object_id, $result->get_error_message());
                    if (function_exists('seo_ie_add_log_warning')) {
                        seo_ie_add_log_warning($log, $message);
                    }
                }
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($slugs), '%s'));
            $sql = "SELECT id, slug
                    FROM {$wpdb->prefix}seo_vocabulary
                    WHERE semantic_group = %s
                      AND active = 1
                      AND slug IN ({$placeholders})";
            $params = array_merge(array($group), $slugs);
            $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

            $resolved = array();
            $found_slugs = array();
            foreach ((array) $rows as $term) {
                $resolved[] = (int) ($term['id'] ?? 0);
                $found_slugs[] = (string) ($term['slug'] ?? '');
            }

            $missing = array_values(array_diff($slugs, $found_slugs));
            if ($missing) {
                $message = sprintf(
                    'Fila %d, %s %d: no se modifica %s porque faltan terminos en Vocabulary: %s.',
                    (int) $line,
                    $object_type,
                    $object_id,
                    $column,
                    implode(', ', $missing)
                );
                if (function_exists('seo_ie_add_log_warning')) {
                    seo_ie_add_log_warning($log, $message);
                } else {
                    $log['advertencias'] = isset($log['advertencias']) ? ((int) $log['advertencias'] + 1) : 1;
                    $log['detalles'][] = $message;
                }
                continue;
            }

            $result = seo_content_vocab_replace_manual_group($object_type, $object_id, $group, $resolved);
            if (is_wp_error($result)) {
                $message = sprintf('Fila %d, %s %d: %s', (int) $line, $object_type, $object_id, $result->get_error_message());
                if (function_exists('seo_ie_add_log_warning')) {
                    seo_ie_add_log_warning($log, $message);
                }
            }
        }
    }
}

if (!function_exists('seo_content_vocab_clear_manual_assignments')) {
    function seo_content_vocab_clear_manual_assignments($object_type, $object_id) {
        global $wpdb;

        $object_type = sanitize_key((string) $object_type);
        $object_id   = absint($object_id);
        if (!seo_content_vocab_validate_object($object_type, $object_id) || !seo_content_vocab_tables_ready()) {
            return true;
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'seo_object_vocabulary',
            array(
                'status'     => 0,
                'updated_at' => current_time('mysql'),
            ),
            array(
                'object_type' => $object_type,
                'object_id'   => $object_id,
                'source'      => 'manual',
            ),
            array('%d', '%s'),
            array('%s', '%d', '%s')
        );

        return false !== $updated;
    }
}

if (!function_exists('seo_content_vocab_delete_assignments')) {
    function seo_content_vocab_delete_assignments($object_type, $object_id) {
        global $wpdb;

        $object_type = sanitize_key((string) $object_type);
        $object_id   = absint($object_id);
        if (!$object_id || !in_array($object_type, seo_content_vocab_allowed_object_types(), true) || !seo_content_vocab_tables_ready()) {
            return true;
        }

        $deleted = $wpdb->delete(
            $wpdb->prefix . 'seo_object_vocabulary',
            array('object_type' => $object_type, 'object_id' => $object_id),
            array('%s', '%d')
        );

        return false !== $deleted;
    }
}

if (!function_exists('seo_content_vocab_find_post_ids_for_query')) {
    /**
     * Candidatos editoriales por coincidencia contra labels/slugs canonicos.
     * Se usa como complemento del buscador textual del Dependiente.
     */
    function seo_content_vocab_find_post_ids_for_query($query, $limit = 60) {
        global $wpdb;

        if (!seo_content_vocab_tables_ready()) {
            return array();
        }

        $query = trim(wp_strip_all_tags((string) $query));
        if ('' === $query) {
            return array();
        }

        $normalized = function_exists('remove_accents') ? remove_accents($query) : $query;
        $normalized = strtolower($normalized);
        $tokens = preg_split('/[^a-z0-9]+/i', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_values(array_unique(array_filter((array) $tokens, static function ($token) {
            return strlen((string) $token) >= 3;
        })));
        $tokens = array_slice($tokens, 0, 8);
        if (!$tokens) {
            return array();
        }

        $conditions = array();
        $params = array();
        foreach ($tokens as $token) {
            $like_label = '%' . $wpdb->esc_like($token) . '%';
            $slug_token = str_replace('-', '_', sanitize_title($token));
            $like_slug  = '%' . $wpdb->esc_like($slug_token) . '%';
            $conditions[] = '(LOWER(v.label) LIKE %s OR LOWER(v.slug) LIKE %s)';
            $params[] = $like_label;
            $params[] = $like_slug;
        }

        $limit = min(200, max(1, absint($limit)));
        $sql = "SELECT ov.object_id, COUNT(DISTINCT v.id) AS semantic_hits
                FROM {$wpdb->prefix}seo_object_vocabulary ov
                INNER JOIN {$wpdb->prefix}seo_vocabulary v
                   ON v.id = ov.vocabulary_id
                  AND v.active = 1
                INNER JOIN {$wpdb->posts} p
                   ON p.ID = ov.object_id
                  AND p.post_type = 'post'
                  AND p.post_status = 'publish'
                WHERE ov.object_type = 'post'
                  AND ov.status = 1
                  AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                  AND (" . implode(' OR ', $conditions) . ")
                GROUP BY ov.object_id
                ORDER BY semantic_hits DESC, ov.object_id DESC
                LIMIT {$limit}";

        return array_values(array_unique(array_filter(array_map(
            'absint',
            (array) $wpdb->get_col($wpdb->prepare($sql, $params))
        ))));
    }
}

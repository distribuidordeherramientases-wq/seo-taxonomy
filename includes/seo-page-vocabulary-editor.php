<?php
/**
 * Vocabulary canónico para páginas SEO, con foco inicial en landing pages.
 *
 * En esta fase:
 * - seo_nodes conserva exclusivamente el rol estructural de la página.
 * - las etiquetas semánticas de landing se leen/escriben en
 *   seo_object_vocabulary -> seo_vocabulary.
 * - no se heredan etiquetas automáticamente desde categorías.
 * - no se crean términos nuevos desde el editor de página: el diccionario se
 *   gobierna desde SEO Taxonomy > Etiquetas.
 *
 * Version: 2026-09-07
 * Build: 1
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_page_vocab_groups')) {
    function seo_page_vocab_groups() {
        return array(
            'rol'        => 'ROL',
            'tipo'       => 'TIPO',
            'aplicacion' => 'APLICACIÓN',
            'plataforma' => 'PLATAFORMA',
            'subtipo'    => 'SUBTIPO',
        );
    }
}

if (!function_exists('seo_page_vocab_tables_ready')) {
    function seo_page_vocab_tables_ready() {
        global $wpdb;
        static $ready = null;

        if ($ready !== null) {
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

if (!function_exists('seo_page_vocab_is_landing')) {
    function seo_page_vocab_is_landing($page_id) {
        global $wpdb;
        static $cache = array();

        $page_id = absint($page_id);
        if (!$page_id || get_post_type($page_id) !== 'page') {
            return false;
        }
        if (array_key_exists($page_id, $cache)) {
            return $cache[$page_id];
        }

        $cache[$page_id] = (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1
                 FROM {$wpdb->prefix}seo_nodes
                 WHERE object_type = 'page'
                   AND object_id = %d
                   AND seo_role = 'landing'
                   AND status = 1
                 LIMIT 1",
                $page_id
            )
        );

        return $cache[$page_id];
    }
}

if (!function_exists('seo_page_vocab_get_terms')) {
    function seo_page_vocab_get_terms($group) {
        global $wpdb;
        static $cache = array();

        $group = sanitize_key($group);
        if (!isset(seo_page_vocab_groups()[$group]) || !seo_page_vocab_tables_ready()) {
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

if (!function_exists('seo_page_vocab_get_assignments')) {
    function seo_page_vocab_get_assignments($page_id) {
        global $wpdb;

        $page_id = absint($page_id);
        $result = array();
        foreach (seo_page_vocab_groups() as $group => $label) {
            $result[$group] = array();
        }

        if (!$page_id || !seo_page_vocab_tables_ready()) {
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
                 WHERE ov.object_type = 'page'
                   AND ov.object_id = %d
                   AND ov.status = 1
                   AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                 ORDER BY FIELD(v.semantic_group,'rol','tipo','aplicacion','plataforma','subtipo'),
                          v.label ASC, v.slug ASC",
                $page_id
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

if (!function_exists('seo_page_vocab_replace_manual_group')) {
    /**
     * Sustituye solo las asignaciones manuales de un grupo.
     * Las asignaciones de otras fuentes quedan intactas para futuras fases.
     */
    function seo_page_vocab_replace_manual_group($page_id, $group, array $vocabulary_ids) {
        global $wpdb;

        $page_id = absint($page_id);
        $group   = sanitize_key($group);

        if (!$page_id || !isset(seo_page_vocab_groups()[$group])) {
            return new WP_Error('seo_page_vocab_invalid', 'Página o grupo semántico no válido.');
        }
        if (get_post_type($page_id) !== 'page') {
            return new WP_Error('seo_page_vocab_not_page', 'El objeto indicado no es una página WordPress.');
        }
        if (!seo_page_vocab_is_landing($page_id)) {
            return new WP_Error('seo_page_vocab_not_landing', 'En esta fase el Vocabulary de páginas solo se gestiona para landing pages.');
        }
        if (!seo_page_vocab_tables_ready()) {
            return new WP_Error('seo_page_vocab_tables', 'No están disponibles las tablas del Vocabulary canónico.');
        }

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects    = $wpdb->prefix . 'seo_object_vocabulary';

        $vocabulary_ids = array_values(array_unique(array_filter(array_map('absint', $vocabulary_ids))));

        if (!empty($vocabulary_ids)) {
            $placeholders = implode(',', array_fill(0, count($vocabulary_ids), '%d'));
            $sql = "SELECT id FROM {$vocabulary}
                    WHERE semantic_group = %s
                      AND active = 1
                      AND id IN ({$placeholders})";
            $params = array_merge(array($group), $vocabulary_ids);
            $valid_ids = $wpdb->get_col($wpdb->prepare($sql, $params));
            $vocabulary_ids = array_values(array_unique(array_map('intval', (array) $valid_ids)));
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
                     WHERE ov.object_type = 'page'
                       AND ov.object_id = %d
                       AND ov.status = 1
                       AND ov.source = 'manual'",
                    $group,
                    $page_id
                )
            )
        );
        $current_ids = array_values(array_unique($current_ids));

        $to_remove = array_values(array_diff($current_ids, $vocabulary_ids));
        $to_add    = array_values(array_diff($vocabulary_ids, $current_ids));

        if (!empty($to_remove)) {
            $placeholders = implode(',', array_fill(0, count($to_remove), '%d'));
            $sql = "UPDATE {$objects} ov
                    INNER JOIN {$vocabulary} v ON v.id = ov.vocabulary_id
                    SET ov.status = 0,
                        ov.updated_at = NOW()
                    WHERE ov.object_type = 'page'
                      AND ov.object_id = %d
                      AND ov.source = 'manual'
                      AND v.semantic_group = %s
                      AND ov.vocabulary_id IN ({$placeholders})";
            $params = array_merge(array($page_id, $group), $to_remove);
            $updated = $wpdb->query($wpdb->prepare($sql, $params));
            if ($updated === false) {
                return new WP_Error('seo_page_vocab_remove', 'No se pudieron retirar asignaciones de Vocabulary: ' . $wpdb->last_error);
            }
        }

        foreach ($to_add as $vocabulary_id) {
            $saved = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$objects}
                        (object_type, object_id, vocabulary_id, source, confidence, status)
                     VALUES ('page', %d, %d, 'manual', 1.0000, 1)
                     ON DUPLICATE KEY UPDATE
                        source = 'manual',
                        confidence = 1.0000,
                        status = 1,
                        updated_at = NOW()",
                    $page_id,
                    $vocabulary_id
                )
            );
            if ($saved === false) {
                return new WP_Error('seo_page_vocab_add', 'No se pudo guardar una asignación de Vocabulary: ' . $wpdb->last_error);
            }
        }

        return true;
    }
}

if (!function_exists('seo_page_vocab_save_from_request')) {
    function seo_page_vocab_save_from_request($page_id, $field_name) {
        $page_id    = absint($page_id);
        $field_name = sanitize_key($field_name);

        if (!$page_id || $field_name === '') {
            return new WP_Error('seo_page_vocab_request', 'No se pudo interpretar la selección de Vocabulary.');
        }

        $marker = $field_name . '_present';
        if (empty($_POST[$marker])) {
            return true;
        }

        $posted = isset($_POST[$field_name]) && is_array($_POST[$field_name])
            ? wp_unslash($_POST[$field_name])
            : array();

        foreach (seo_page_vocab_groups() as $group => $label) {
            $selected = isset($posted[$group]) && is_array($posted[$group])
                ? array_map('absint', $posted[$group])
                : array();

            $result = seo_page_vocab_replace_manual_group($page_id, $group, $selected);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        return true;
    }
}

if (!function_exists('seo_page_vocab_render_fields')) {
    function seo_page_vocab_render_fields($page_id, $field_name = 'seo_page_vocab', $compact = false) {
        $page_id    = absint($page_id);
        $field_name = sanitize_key($field_name);

        if (!seo_page_vocab_tables_ready()) {
            echo '<div class="notice notice-error inline"><p>No están disponibles las tablas del Vocabulary canónico.</p></div>';
            return;
        }

        $assignments = $page_id > 0 ? seo_page_vocab_get_assignments($page_id) : array();

        echo '<div class="seo-page-vocabulary" style="margin-top:16px;padding:14px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:6px;">';
        echo '<input type="hidden" name="' . esc_attr($field_name) . '_present" value="1">';
        echo '<div style="font-weight:700;margin-bottom:5px;">Etiquetas semánticas · Vocabulary canónico</div>';
        echo '<p style="margin:0 0 12px;color:#50575e;font-size:12px;">Se guardan en <code>wp_seo_object_vocabulary</code> con <code>object_type=page</code>. El rol estructural <code>landing</code> permanece separado en <code>wp_seo_nodes</code>.</p>';

        $grid = $compact ? 'repeat(2,minmax(240px,1fr))' : 'repeat(2,minmax(280px,1fr))';
        echo '<div style="display:grid;grid-template-columns:' . esc_attr($grid) . ';gap:12px;">';

        foreach (seo_page_vocab_groups() as $group => $label) {
            $terms = seo_page_vocab_get_terms($group);
            $group_assignments = isset($assignments[$group]) ? (array) $assignments[$group] : array();
            $manual_selected = array();
            $protected = array();

            foreach ($group_assignments as $assignment) {
                if ((string) ($assignment['source'] ?? '') === 'manual') {
                    $manual_selected[] = (int) ($assignment['id'] ?? 0);
                } else {
                    $protected[] = $assignment;
                }
            }

            echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:5px;padding:10px;">';
            echo '<label style="display:block;font-weight:700;margin-bottom:6px;">' . esc_html($label) . '</label>';

            if (!empty($protected)) {
                echo '<div style="margin:0 0 7px;font-size:11px;color:#646970;">Asignaciones no manuales (no se alteran aquí): ';
                foreach ($protected as $assignment) {
                    echo '<span style="display:inline-block;margin:2px 3px;padding:2px 6px;border-radius:999px;background:#eef5ff;">' . esc_html((string) ($assignment['label'] ?? $assignment['slug'] ?? '')) . '</span>';
                }
                echo '</div>';
            }

            echo '<select name="' . esc_attr($field_name) . '[' . esc_attr($group) . '][]" multiple size="' . ($compact ? '5' : '7') . '" style="width:100%;min-height:' . ($compact ? '110px' : '150px') . ';">';
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
            echo '<div style="margin-top:5px;font-size:11px;color:#646970;">Ctrl/Cmd + clic para selección múltiple.</div>';
            echo '</div>';
        }

        echo '</div>';
        echo '<p style="margin:10px 0 0;font-size:12px;color:#646970;">Los términos nuevos se crean y gobiernan desde <a href="' . esc_url(admin_url('admin.php?page=seo-tags-vocabulary')) . '">SEO Taxonomy → Etiquetas</a>; aquí solo se asignan términos existentes.</p>';
        echo '</div>';
    }
}

if (!function_exists('seo_page_vocab_render_summary')) {
    function seo_page_vocab_render_summary($page_id, $edit_url = '') {
        $page_id = absint($page_id);
        $assignments = seo_page_vocab_get_assignments($page_id);
        $total = 0;

        echo '<div style="margin-top:16px;padding:12px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">';
        echo '<strong>Etiquetas semánticas · Vocabulary</strong>';
        if ($edit_url !== '') {
            echo '<a class="button" href="' . esc_url($edit_url) . '">Editar etiquetas</a>';
        }
        echo '</div>';
        echo '<div style="margin-top:8px;">';

        foreach (seo_page_vocab_groups() as $group => $label) {
            foreach ((array) ($assignments[$group] ?? array()) as $assignment) {
                $total++;
                echo '<span style="display:inline-block;margin:2px 5px 2px 0;padding:3px 7px;border:1px solid #c3c4c7;border-radius:999px;background:#fff;font-size:12px;">'
                    . '<strong>' . esc_html($label) . ':</strong> '
                    . esc_html((string) ($assignment['label'] ?? $assignment['slug'] ?? ''))
                    . '</span>';
            }
        }

        if ($total === 0) {
            echo '<span style="color:#b32d2e;font-size:12px;">Sin etiquetas semánticas asignadas.</span>';
        }
        echo '</div></div>';
    }
}

if (!function_exists('seo_page_vocab_export_group')) {
    function seo_page_vocab_export_group($page_id, $group, $field = 'slug', $source = 'manual') {
        global $wpdb;

        $page_id = absint($page_id);
        $group   = sanitize_key($group);
        $field   = $field === 'label' ? 'label' : 'slug';
        $source  = sanitize_key($source);

        if (!$page_id || !isset(seo_page_vocab_groups()[$group]) || !seo_page_vocab_tables_ready() || !seo_page_vocab_is_landing($page_id)) {
            return array();
        }

        $sql = "SELECT DISTINCT v.{$field}
                FROM {$wpdb->prefix}seo_object_vocabulary ov
                INNER JOIN {$wpdb->prefix}seo_vocabulary v
                   ON v.id = ov.vocabulary_id
                  AND v.active = 1
                WHERE ov.object_type = 'page'
                  AND ov.object_id = %d
                  AND ov.status = 1
                  AND v.semantic_group = %s";
        $params = array($page_id, $group);

        if ($source !== '') {
            $sql .= ' AND ov.source = %s';
            $params[] = $source;
        }
        $sql .= " ORDER BY v.{$field} ASC";

        return array_values(array_filter(array_map('strval', (array) $wpdb->get_col($wpdb->prepare($sql, $params)))));
    }
}

if (!function_exists('seo_page_vocab_import_row')) {
    /**
     * Importa únicamente las columnas vocab_* presentes en la fila.
     * Solo acepta términos ya existentes en el diccionario. Si una celda
     * contiene términos desconocidos, ese grupo no se modifica.
     */
    function seo_page_vocab_import_row($page_id, array $row, $line, array &$log) {
        global $wpdb;

        $page_id = absint($page_id);
        if (!$page_id || get_post_type($page_id) !== 'page' || !seo_page_vocab_tables_ready() || !seo_page_vocab_is_landing($page_id)) {
            return;
        }

        foreach (seo_page_vocab_groups() as $group => $label) {
            $column = 'vocab_' . $group;
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $raw_values = function_exists('seo_ie_decode_post_list')
                ? seo_ie_decode_post_list($row[$column])
                : array_filter(array_map('trim', explode(',', (string) $row[$column])));

            $slugs = array_values(array_unique(array_filter(array_map('sanitize_title', (array) $raw_values))));
            if (empty($slugs)) {
                $result = seo_page_vocab_replace_manual_group($page_id, $group, array());
                if (is_wp_error($result)) {
                    $message = sprintf('Fila %d, página %d: %s', (int) $line, $page_id, $result->get_error_message());
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
            if (!empty($missing)) {
                $message = sprintf(
                    'Fila %d, página %d: no se modifica %s porque faltan términos en Vocabulary: %s.',
                    (int) $line,
                    $page_id,
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

            $result = seo_page_vocab_replace_manual_group($page_id, $group, $resolved);
            if (is_wp_error($result)) {
                $message = sprintf('Fila %d, página %d: %s', (int) $line, $page_id, $result->get_error_message());
                if (function_exists('seo_ie_add_log_warning')) {
                    seo_ie_add_log_warning($log, $message);
                }
            }
        }
    }
}

if (!function_exists('seo_page_vocab_clear_manual_assignments')) {
    function seo_page_vocab_clear_manual_assignments($page_id) {
        global $wpdb;

        $page_id = absint($page_id);
        if (!$page_id || !seo_page_vocab_tables_ready()) {
            return true;
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'seo_object_vocabulary',
            array(
                'status'     => 0,
                'updated_at' => current_time('mysql'),
            ),
            array(
                'object_type' => 'page',
                'object_id'   => $page_id,
                'source'      => 'manual',
            ),
            array('%d', '%s'),
            array('%s', '%d', '%s')
        );

        return $updated !== false;
    }
}

if (!function_exists('seo_page_vocab_delete_assignments')) {
    function seo_page_vocab_delete_assignments($page_id) {
        global $wpdb;

        $page_id = absint($page_id);
        if (!$page_id || !seo_page_vocab_tables_ready()) {
            return true;
        }

        $deleted = $wpdb->delete(
            $wpdb->prefix . 'seo_object_vocabulary',
            array('object_type' => 'page', 'object_id' => $page_id),
            array('%s', '%d')
        );

        return $deleted !== false;
    }
}

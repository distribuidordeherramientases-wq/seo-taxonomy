<?php
/**
 * Correspondencia semántica entre la jerarquía SEO de WordPress y Google.
 *
 * La jerarquía propia se lee dinámicamente desde wp_seo_relations. Esta capa
 * solo persiste la correspondencia externa y los nombres de trabajo que usará
 * Ojeador en una fase posterior.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_google_schema_version')) {
    function seo_classifier_google_schema_version() {
        return '1.1.0';
    }
}

if (!function_exists('seo_classifier_google_schema_table')) {
    function seo_classifier_google_schema_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_google_schema_map';
    }
}

if (!function_exists('seo_classifier_google_schema_relations_table')) {
    function seo_classifier_google_schema_relations_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_relations';
    }
}

if (!function_exists('seo_classifier_google_schema_node_types')) {
    function seo_classifier_google_schema_node_types() {
        return [
            'cluster' => 'Clusters',
            'hub_primary' => 'Hub primarios',
            'hub_secondary' => 'Hub secundarios',
            'product_cat' => 'Categorías',
        ];
    }
}

if (!function_exists('seo_classifier_google_schema_statuses')) {
    function seo_classifier_google_schema_statuses() {
        return [
            'pending' => 'Pendiente',
            'review' => 'Revisar',
            'approved' => 'Aprobado',
            'rejected' => 'Descartado',
        ];
    }
}

if (!function_exists('seo_classifier_google_schema_relation_types')) {
    function seo_classifier_google_schema_relation_types() {
        return [
            'cluster_to_primary',
            'hub_primary_to_hub_secondary',
            'hub_secondary_to_category',
            'hub_primary_to_category',
            'cluster_to_category',
        ];
    }
}

if (!function_exists('seo_classifier_google_schema_table_exists')) {
    function seo_classifier_google_schema_table_exists($table) {
        if (function_exists('seo_classifier_table_exists')) {
            return seo_classifier_table_exists($table);
        }
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like((string)$table)));
        return is_string($found) && strcasecmp($found, (string)$table) === 0;
    }
}

if (!function_exists('seo_classifier_google_schema_install_schema')) {
    function seo_classifier_google_schema_install_schema() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = seo_classifier_google_schema_table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(40) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            google_taxonomy_id VARCHAR(40) NOT NULL DEFAULT '',
            google_name_en VARCHAR(255) NOT NULL DEFAULT '',
            google_path_en TEXT NULL,
            google_alias_es VARCHAR(255) NOT NULL DEFAULT '',
            suggested_wp_name VARCHAR(255) NOT NULL DEFAULT '',
            shopping_query VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            confidence DECIMAL(8,4) NOT NULL DEFAULT 0,
            source VARCHAR(80) NOT NULL DEFAULT 'manual',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY object_key (object_type, object_id),
            KEY status (status),
            KEY google_taxonomy_id (google_taxonomy_id),
            KEY updated_at (updated_at)
        ) {$charset};";

        dbDelta($sql);
        update_option('seo_classifier_google_schema_schema_version', seo_classifier_google_schema_version(), false);
        return true;
    }
}

if (!function_exists('seo_classifier_google_schema_maybe_install_schema')) {
    function seo_classifier_google_schema_maybe_install_schema() {
        $installed = (string)get_option('seo_classifier_google_schema_schema_version', '0');
        if (version_compare($installed, seo_classifier_google_schema_version(), '<')) {
            seo_classifier_google_schema_install_schema();
        }
    }
    add_action('admin_init', 'seo_classifier_google_schema_maybe_install_schema', 3);
}

if (!function_exists('seo_classifier_google_schema_collect_nodes')) {
    /**
     * Devuelve los nodos reales de la arquitectura sin duplicarlos en esta capa.
     */
    function seo_classifier_google_schema_collect_nodes() {
        global $wpdb;
        $relations = seo_classifier_google_schema_relations_table();
        if (!seo_classifier_google_schema_table_exists($relations)) return [];

        $allowed = array_keys(seo_classifier_google_schema_node_types());
        $relation_types = seo_classifier_google_schema_relation_types();
        $quoted_types = implode(',', array_fill(0, count($allowed), '%s'));
        $quoted_relations = implode(',', array_fill(0, count($relation_types), '%s'));
        $sql = "SELECT object_type, object_id FROM (
                    SELECT source_type AS object_type, source_id AS object_id
                    FROM {$relations}
                    WHERE source_type IN ({$quoted_types}) AND relation_type IN ({$quoted_relations})
                    UNION
                    SELECT target_type AS object_type, target_id AS object_id
                    FROM {$relations}
                    WHERE target_type IN ({$quoted_types}) AND relation_type IN ({$quoted_relations})
                ) n
                ORDER BY FIELD(object_type,'cluster','hub_primary','hub_secondary','product_cat'), object_id";
        $args = array_merge($allowed, $relation_types, $allowed, $relation_types);
        $rows = (array)$wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);

        $nodes = [];
        foreach ($rows as $row) {
            $type = sanitize_key((string)($row['object_type'] ?? ''));
            $id = absint($row['object_id'] ?? 0);
            if ($id < 1 || !isset(seo_classifier_google_schema_node_types()[$type])) continue;
            $nodes[$type . ':' . $id] = [
                'object_type' => $type,
                'object_id' => $id,
                'name' => '',
                'slug' => '',
                'path' => '',
            ];
        }
        if (!$nodes) return [];

        $page_ids = [];
        $term_ids = [];
        foreach ($nodes as $node) {
            if ($node['object_type'] === 'product_cat') $term_ids[] = $node['object_id'];
            else $page_ids[] = $node['object_id'];
        }

        $names = [];
        $slugs = [];
        if ($page_ids) {
            $posts = get_posts([
                'post_type' => 'any',
                'post_status' => 'any',
                'post__in' => array_values(array_unique($page_ids)),
                'posts_per_page' => -1,
                'orderby' => 'post__in',
                'suppress_filters' => false,
            ]);
            foreach ((array)$posts as $post) {
                $names['post:' . (int)$post->ID] = (string)get_the_title($post);
                $slugs['post:' . (int)$post->ID] = (string)$post->post_name;
            }
        }
        if ($term_ids && taxonomy_exists('product_cat')) {
            $terms = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'include' => array_values(array_unique($term_ids)),
            ]);
            if (!is_wp_error($terms)) {
                foreach ((array)$terms as $term) {
                    $names['term:' . (int)$term->term_id] = (string)$term->name;
                    $slugs['term:' . (int)$term->term_id] = (string)$term->slug;
                }
            }
        }

        foreach ($nodes as $key => $node) {
            $name_key = $node['object_type'] === 'product_cat'
                ? 'term:' . $node['object_id']
                : 'post:' . $node['object_id'];
            $nodes[$key]['name'] = trim((string)($names[$name_key] ?? ''));
            $nodes[$key]['slug'] = sanitize_title((string)($slugs[$name_key] ?? ''));
            if ($nodes[$key]['name'] === '') {
                $nodes[$key]['name'] = '#' . $node['object_id'];
            }
        }

        // Construye padres únicamente desde las relaciones estructurales conocidas.
        $relation_types = seo_classifier_google_schema_relation_types();
        $quoted_relations = implode(',', array_fill(0, count($relation_types), '%s'));
        $rel_rows = (array)$wpdb->get_results(
            $wpdb->prepare(
                "SELECT source_type,source_id,target_type,target_id,relation_type
                 FROM {$relations}
                 WHERE relation_type IN ({$quoted_relations})",
                $relation_types
            ),
            ARRAY_A
        );
        $parents = [];
        foreach ($rel_rows as $row) {
            $source_type = sanitize_key((string)($row['source_type'] ?? ''));
            $target_type = sanitize_key((string)($row['target_type'] ?? ''));
            $source_id = absint($row['source_id'] ?? 0);
            $target_id = absint($row['target_id'] ?? 0);
            $source_key = $source_type . ':' . $source_id;
            $target_key = $target_type . ':' . $target_id;
            if (!isset($nodes[$source_key], $nodes[$target_key])) continue;
            if (!isset($parents[$target_key])) $parents[$target_key] = [];
            $parents[$target_key][$source_key] = $source_key;
        }

        $path_cache = [];
        $build_paths = static function($key, $seen = []) use (&$build_paths, &$path_cache, $parents, $nodes) {
            if (isset($path_cache[$key])) return $path_cache[$key];
            if (isset($seen[$key]) || !isset($nodes[$key])) return [];
            $seen[$key] = true;
            $name = (string)$nodes[$key]['name'];
            if (empty($parents[$key])) {
                return $path_cache[$key] = [$name];
            }
            $paths = [];
            foreach ($parents[$key] as $parent_key) {
                foreach ($build_paths($parent_key, $seen) as $parent_path) {
                    $paths[] = trim($parent_path . ' › ' . $name, ' ›');
                }
            }
            if (!$paths) $paths[] = $name;
            $paths = array_values(array_unique($paths));
            return $path_cache[$key] = $paths;
        };

        foreach (array_keys($nodes) as $key) {
            $paths = $build_paths($key);
            $nodes[$key]['path'] = (string)($paths[0] ?? $nodes[$key]['name']);
            $nodes[$key]['paths'] = $paths;
        }

        return array_values($nodes);
    }
}

if (!function_exists('seo_classifier_google_schema_mapping_index')) {
    function seo_classifier_google_schema_mapping_index() {
        global $wpdb;
        $table = seo_classifier_google_schema_table();
        if (!seo_classifier_google_schema_table_exists($table)) return [];
        $rows = (array)$wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $key = sanitize_key((string)($row['object_type'] ?? '')) . ':' . absint($row['object_id'] ?? 0);
            $out[$key] = $row;
        }
        return $out;
    }
}

if (!function_exists('seo_classifier_google_schema_get_mapping')) {
    function seo_classifier_google_schema_get_mapping($object_type, $object_id) {
        global $wpdb;
        $object_type = sanitize_key((string)$object_type);
        $object_id = absint($object_id);
        if ($object_id < 1 || !isset(seo_classifier_google_schema_node_types()[$object_type])) return null;
        $table = seo_classifier_google_schema_table();
        if (!seo_classifier_google_schema_table_exists($table)) return null;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE object_type=%s AND object_id=%d LIMIT 1", $object_type, $object_id),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('seo_classifier_google_schema_node_exists')) {
    function seo_classifier_google_schema_node_exists($object_type, $object_id) {
        global $wpdb;
        $object_type = sanitize_key((string)$object_type);
        $object_id = absint($object_id);
        if ($object_id < 1 || !isset(seo_classifier_google_schema_node_types()[$object_type])) return false;
        $relations = seo_classifier_google_schema_relations_table();
        if (!seo_classifier_google_schema_table_exists($relations)) return false;
        $count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$relations}
             WHERE (source_type=%s AND source_id=%d) OR (target_type=%s AND target_id=%d)",
            $object_type, $object_id, $object_type, $object_id
        ));
        return $count > 0;
    }
}

if (!function_exists('seo_classifier_google_schema_save_mapping')) {
    function seo_classifier_google_schema_save_mapping($object_type, $object_id, array $data) {
        global $wpdb;
        $object_type = sanitize_key((string)$object_type);
        $object_id = absint($object_id);
        if (!seo_classifier_google_schema_node_exists($object_type, $object_id)) {
            return new WP_Error('seo_google_schema_invalid_node', 'El elemento ya no pertenece a la jerarquía de seo_relations.');
        }
        seo_classifier_google_schema_maybe_install_schema();
        $table = seo_classifier_google_schema_table();
        $statuses = seo_classifier_google_schema_statuses();
        $status = sanitize_key((string)($data['status'] ?? 'pending'));
        if (!isset($statuses[$status])) $status = 'pending';

        $confidence = isset($data['confidence']) ? (float)$data['confidence'] : 0.0;
        $confidence = max(0.0, min(1.0, $confidence));
        $payload = [
            'object_type' => $object_type,
            'object_id' => $object_id,
            'google_taxonomy_id' => sanitize_text_field((string)($data['google_taxonomy_id'] ?? '')),
            'google_name_en' => sanitize_text_field((string)($data['google_name_en'] ?? '')),
            'google_path_en' => sanitize_text_field((string)($data['google_path_en'] ?? '')),
            'google_alias_es' => sanitize_text_field((string)($data['google_alias_es'] ?? '')),
            'suggested_wp_name' => sanitize_text_field((string)($data['suggested_wp_name'] ?? '')),
            'shopping_query' => sanitize_text_field((string)($data['shopping_query'] ?? '')),
            'status' => $status,
            'confidence' => $confidence,
            'source' => sanitize_key((string)($data['source'] ?? 'manual')) ?: 'manual',
            'notes' => sanitize_textarea_field((string)($data['notes'] ?? '')),
            'updated_at' => current_time('mysql'),
        ];
        $existing_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE object_type=%s AND object_id=%d",
            $object_type, $object_id
        ));
        if ($existing_id > 0) {
            $ok = $wpdb->update($table, $payload, ['id' => $existing_id]);
            if ($ok === false) return new WP_Error('seo_google_schema_update_failed', $wpdb->last_error ?: 'No se pudo actualizar la correspondencia.');
            return $existing_id;
        }
        $payload['created_at'] = current_time('mysql');
        $ok = $wpdb->insert($table, $payload);
        if (!$ok) return new WP_Error('seo_google_schema_insert_failed', $wpdb->last_error ?: 'No se pudo guardar la correspondencia.');
        return (int)$wpdb->insert_id;
    }
}

if (!function_exists('seo_classifier_google_schema_search_name')) {
    /**
     * API preparada para Ojeador. Solo consume nombres aprobados.
     * Prioridad: consulta Shopping > alias ES > nombre Google > fallback.
     */
    function seo_classifier_google_schema_search_name($object_type, $object_id, $fallback = '') {
        $mapping = seo_classifier_google_schema_get_mapping($object_type, $object_id);
        if (!$mapping || (string)($mapping['status'] ?? '') !== 'approved') return (string)$fallback;
        foreach (['shopping_query', 'google_alias_es', 'google_name_en'] as $field) {
            $value = trim((string)($mapping[$field] ?? ''));
            if ($value !== '') return $value;
        }
        return (string)$fallback;
    }
}


if (!function_exists('seo_classifier_google_schema_category_search_name')) {
    function seo_classifier_google_schema_category_search_name($category_id, $fallback = '') {
        return seo_classifier_google_schema_search_name('product_cat', absint($category_id), $fallback);
    }
}

if (!function_exists('seo_classifier_google_schema_admin_post_save')) {
    function seo_classifier_google_schema_admin_post_save() {
        if (!current_user_can('manage_options')) wp_die('Sin permisos.', 403);
        check_admin_referer('seo_classifier_google_schema_save');

        $result = seo_classifier_google_schema_save_mapping(
            sanitize_key((string)($_POST['object_type'] ?? '')),
            absint($_POST['object_id'] ?? 0),
            [
                'google_taxonomy_id' => wp_unslash($_POST['google_taxonomy_id'] ?? ''),
                'google_name_en' => wp_unslash($_POST['google_name_en'] ?? ''),
                'google_path_en' => wp_unslash($_POST['google_path_en'] ?? ''),
                'google_alias_es' => wp_unslash($_POST['google_alias_es'] ?? ''),
                'suggested_wp_name' => wp_unslash($_POST['suggested_wp_name'] ?? ''),
                'shopping_query' => wp_unslash($_POST['shopping_query'] ?? ''),
                'status' => wp_unslash($_POST['status'] ?? 'pending'),
                'source' => 'manual',
                'notes' => wp_unslash($_POST['notes'] ?? ''),
            ]
        );

        $referer = wp_get_referer();
        if (!$referer) $referer = admin_url();
        $args = is_wp_error($result)
            ? ['seo_google_schema_saved' => '0', 'seo_google_schema_message' => $result->get_error_message()]
            : ['seo_google_schema_saved' => '1'];
        wp_safe_redirect(add_query_arg($args, $referer));
        exit;
    }
    add_action('admin_post_seo_classifier_google_schema_save', 'seo_classifier_google_schema_admin_post_save');
}

if (!function_exists('seo_classifier_google_schema_tab')) {
    /**
     * Descriptor para que la pantalla administrativa del Clasificador registre
     * la pestaña sin conocer la implementación interna de este módulo.
     */
    function seo_classifier_google_schema_tab() {
        return [
            'slug' => 'google-schema',
            'label' => 'Google esquema',
            'callback' => 'seo_classifier_google_schema_render_panel',
        ];
    }
}

if (!function_exists('seo_classifier_google_schema_render_panel')) {
    /**
     * Render de la pestaña. La pantalla padre del Clasificador solo debe llamar
     * a esta función cuando el tab activo sea "google-schema".
     */
    function seo_classifier_google_schema_render_panel() {
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Sin permisos.</p></div>';
            return;
        }

        seo_classifier_google_schema_maybe_install_schema();
        $relations = seo_classifier_google_schema_relations_table();
        if (!seo_classifier_google_schema_table_exists($relations)) {
            echo '<div class="notice notice-error"><p>No existe la tabla <code>' . esc_html($relations) . '</code>.</p></div>';
            return;
        }

        if (isset($_GET['seo_google_schema_saved'])) {
            if ((string)$_GET['seo_google_schema_saved'] === '1') {
                echo '<div class="notice notice-success is-dismissible"><p>Correspondencia guardada.</p></div>';
            } else {
                $message = sanitize_text_field(wp_unslash($_GET['seo_google_schema_message'] ?? 'No se pudo guardar.'));
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            }
        }

        if (isset($_GET['seo_google_schema_imported']) && function_exists('seo_classifier_google_schema_get_import_report')) {
            $report = seo_classifier_google_schema_get_import_report();
            if (is_array($report)) {
                $errors_total = absint($report['errors_total'] ?? 0);
                $notice_class = $errors_total > 0 ? 'notice-warning' : 'notice-success';
                echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p><strong>Importación terminada.</strong> ';
                echo 'Insertados: ' . esc_html(number_format_i18n(absint($report['inserted'] ?? 0))) . ' · ';
                echo 'Actualizados: ' . esc_html(number_format_i18n(absint($report['updated'] ?? 0))) . ' · ';
                echo 'Omitidos: ' . esc_html(number_format_i18n(absint($report['skipped'] ?? 0))) . ' · ';
                echo 'Errores: ' . esc_html(number_format_i18n($errors_total)) . '.</p>';
                if (!empty($report['errors'])) {
                    echo '<ul style="margin:0 0 10px 32px;list-style:disc">';
                    foreach ((array)$report['errors'] as $error) {
                        echo '<li>' . esc_html((string)$error) . '</li>';
                    }
                    echo '</ul>';
                }
                echo '</div>';
            }
        }

        $nodes = seo_classifier_google_schema_collect_nodes();
        $mapping = seo_classifier_google_schema_mapping_index();
        $kpis = function_exists('seo_classifier_google_schema_kpis')
            ? seo_classifier_google_schema_kpis($nodes, $mapping)
            : ['total'=>count($nodes),'related'=>0,'approved'=>0,'pending'=>count($nodes),'coverage_pct'=>0];
        $types = seo_classifier_google_schema_node_types();
        $statuses = seo_classifier_google_schema_statuses();
        $grouped = array_fill_keys(array_keys($types), []);
        foreach ($nodes as $node) {
            if (isset($grouped[$node['object_type']])) $grouped[$node['object_type']][] = $node;
        }

        $export_base = add_query_arg(
            ['action' => 'seo_classifier_google_schema_export'],
            admin_url('admin-post.php')
        );
        $export_csv = wp_nonce_url(add_query_arg('format', 'csv', $export_base), 'seo_classifier_google_schema_export');
        $export_json = wp_nonce_url(add_query_arg('format', 'json', $export_base), 'seo_classifier_google_schema_export');

        echo '<div class="seo-classifier-google-schema">';
        echo '<p>La jerarquía y el nombre actual se leen dinámicamente de <code>seo_relations</code> y WordPress. Exporta el inventario, completa las columnas de Google y vuelve a importarlo. <strong>No modifiques object_type ni object_id.</strong></p>';
        echo '<style>
            .seo-classifier-google-schema .seo-gs-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;max-width:1000px;margin:16px 0 20px}
            .seo-classifier-google-schema .seo-gs-kpi{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .seo-classifier-google-schema .seo-gs-kpi strong{display:block;font-size:24px;line-height:1.2;margin-top:4px}
            .seo-classifier-google-schema .seo-gs-kpi span{color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
            .seo-classifier-google-schema .seo-gs-transfer{display:flex;align-items:flex-end;flex-wrap:wrap;gap:10px;padding:14px 16px;margin:0 0 24px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px}
            .seo-classifier-google-schema .seo-gs-transfer form{display:flex;align-items:flex-end;flex-wrap:wrap;gap:8px;margin:0}
            .seo-classifier-google-schema .seo-gs-transfer label{font-weight:600}
            .seo-classifier-google-schema .seo-gs-transfer input[type=file]{max-width:360px}
            .seo-classifier-google-schema .seo-gs-table{width:100%;border-collapse:collapse;margin:10px 0 28px;background:#fff}
            .seo-classifier-google-schema .seo-gs-table th,.seo-classifier-google-schema .seo-gs-table td{padding:8px;border:1px solid #dcdcde;vertical-align:top}
            .seo-classifier-google-schema .seo-gs-table th{background:#f6f7f7;text-align:left}
            .seo-classifier-google-schema .seo-gs-current{min-width:220px}
            .seo-classifier-google-schema .seo-gs-path{color:#646970;font-size:12px;margin-top:4px}
            .seo-classifier-google-schema input[type=text],.seo-classifier-google-schema select{width:100%;min-width:130px}
            .seo-classifier-google-schema textarea{width:100%;min-width:190px;min-height:48px}
            .seo-classifier-google-schema .seo-gs-actions{white-space:nowrap}
        </style>';

        echo '<div class="seo-gs-kpis">';
        $cards = [
            ['label'=>'Elementos','value'=>absint($kpis['total'] ?? 0)],
            ['label'=>'Relacionados','value'=>absint($kpis['related'] ?? 0)],
            ['label'=>'Aprobados','value'=>absint($kpis['approved'] ?? 0)],
            ['label'=>'Pendientes','value'=>absint($kpis['pending'] ?? 0)],
            ['label'=>'Cobertura','value'=>number_format_i18n((float)($kpis['coverage_pct'] ?? 0), 1) . '%'],
        ];
        foreach ($cards as $card) {
            echo '<div class="seo-gs-kpi"><span>' . esc_html($card['label']) . '</span><strong>' . esc_html((string)$card['value']) . '</strong></div>';
        }
        echo '</div>';

        echo '<div class="seo-gs-transfer">';
        echo '<a class="button button-secondary" href="' . esc_url($export_csv) . '">Exportar CSV</a>';
        echo '<a class="button button-secondary" href="' . esc_url($export_json) . '">Exportar JSON</a>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('seo_classifier_google_schema_import');
        echo '<input type="hidden" name="action" value="seo_classifier_google_schema_import">';
        echo '<label>Importar relaciones<br><input type="file" name="seo_google_schema_file" accept=".csv,.txt,.json,text/csv,text/plain,application/json" required></label>';
        submit_button('Importar', 'primary', 'submit', false);
        echo '</form>';
        echo '<span class="description">CSV (separado por ;, , o tabulador) o JSON. Las filas sin datos Google se ignoran.</span>';
        echo '</div>';

        foreach ($types as $type => $label) {
            echo '<h2>' . esc_html($label) . ' <span style="font-weight:400;color:#646970">(' . number_format_i18n(count($grouped[$type])) . ')</span></h2>';
            if (!$grouped[$type]) {
                echo '<p>No hay elementos de este tipo en <code>seo_relations</code>.</p>';
                continue;
            }
            echo '<table class="seo-gs-table"><thead><tr>';
            echo '<th class="seo-gs-current">Nombre actual</th>';
            echo '<th>ID Google</th><th>Nombre Google</th><th>Ruta Google</th><th>Alias ES</th><th>Nombre WP sugerido</th><th>Consulta Ojeador</th><th>Estado</th><th></th>';
            echo '</tr></thead><tbody>';
            foreach ($grouped[$type] as $node) {
                $key = $type . ':' . $node['object_id'];
                $row = (array)($mapping[$key] ?? []);
                $form_id = 'seo-gs-form-' . sanitize_html_class($type) . '-' . absint($node['object_id']);
                echo '<tr>';
                echo '<td class="seo-gs-current"><strong>' . esc_html($node['name']) . '</strong><div class="seo-gs-path">' . esc_html($node['path']) . '</div><div class="seo-gs-path">' . esc_html($type . ' #' . $node['object_id']) . '</div></td>';
                echo '<td><input form="' . esc_attr($form_id) . '" type="text" name="google_taxonomy_id" value="' . esc_attr((string)($row['google_taxonomy_id'] ?? '')) . '" placeholder="ID"></td>';
                echo '<td><input form="' . esc_attr($form_id) . '" type="text" name="google_name_en" value="' . esc_attr((string)($row['google_name_en'] ?? '')) . '" placeholder="Nombre Google"></td>';
                echo '<td><textarea form="' . esc_attr($form_id) . '" name="google_path_en" placeholder="Ruta de taxonomía">' . esc_textarea((string)($row['google_path_en'] ?? '')) . '</textarea></td>';
                echo '<td><input form="' . esc_attr($form_id) . '" type="text" name="google_alias_es" value="' . esc_attr((string)($row['google_alias_es'] ?? '')) . '" placeholder="Alias castellano"></td>';
                echo '<td><input form="' . esc_attr($form_id) . '" type="text" name="suggested_wp_name" value="' . esc_attr((string)($row['suggested_wp_name'] ?? '')) . '" placeholder="Propuesta visible"></td>';
                echo '<td><input form="' . esc_attr($form_id) . '" type="text" name="shopping_query" value="' . esc_attr((string)($row['shopping_query'] ?? '')) . '" placeholder="Nombre para Shopping"></td>';
                echo '<td><select form="' . esc_attr($form_id) . '" name="status">';
                $current_status = (string)($row['status'] ?? 'pending');
                foreach ($statuses as $status_key => $status_label) {
                    echo '<option value="' . esc_attr($status_key) . '"' . selected($current_status, $status_key, false) . '>' . esc_html($status_label) . '</option>';
                }
                echo '</select></td>';
                echo '<td class="seo-gs-actions"><form id="' . esc_attr($form_id) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('seo_classifier_google_schema_save');
                echo '<input type="hidden" name="action" value="seo_classifier_google_schema_save">';
                echo '<input type="hidden" name="object_type" value="' . esc_attr($type) . '">';
                echo '<input type="hidden" name="object_id" value="' . esc_attr((string)$node['object_id']) . '">';
                submit_button('Guardar', 'secondary small', 'submit', false);
                echo '</form></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}

<?php
/**
 * Panel de control del vocabulario semántico de producto.
 *
 * Vista de control del vocabulario canónico. La edición manual se realiza
 * desde la ficha nativa del producto para APLICACION / PLATAFORMA / SUBTIPO.
 * Esta pantalla gestiona el vocabulario canónico y la alineación semántica de productos.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_tags_vocab_table_exists')) {
    function seo_tags_vocab_table_exists($table) {
        if (function_exists('seo_catalog_table_exists')) {
            return seo_catalog_table_exists($table);
        }

        global $wpdb;
        $table = (string) $table;
        if ($table === '') {
            return false;
        }

        return (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        ) === $table;
    }
}

if (!function_exists('seo_tags_vocab_prepare_sql')) {
    function seo_tags_vocab_prepare_sql($sql, array $args = []) {
        global $wpdb;
        return $args ? $wpdb->prepare($sql, ...$args) : $sql;
    }
}

if (!function_exists('seo_tags_vocab_placeholders')) {
    function seo_tags_vocab_placeholders(array $values, $placeholder = '%d') {
        return implode(',', array_fill(0, count($values), $placeholder));
    }
}

if (!function_exists('seo_tags_vocab_group_label')) {
    function seo_tags_vocab_group_label($group) {
        $labels = [
            'rol'        => 'Ámbito / ROL',
            'tipo'       => 'TIPO',
            'aplicacion' => 'APLICACIÓN',
            'plataforma' => 'PLATAFORMA',
            'subtipo'    => 'SUBTIPO',
        ];

        return $labels[$group] ?? strtoupper((string) $group);
    }
}

if (!function_exists('seo_tags_vocab_render_styles')) {
    function seo_tags_vocab_render_styles() {
        echo '<style>
            .seo-tags-wrap{max-width:1700px}
            .seo-tags-intro{max-width:1180px;font-size:14px;color:#50575e}
            .seo-tags-mode{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;background:#e7f5ee;color:#136c3b;font-weight:600;font-size:12px;margin-left:8px}
            .seo-tags-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:18px 0}
            .seo-tags-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;box-shadow:0 1px 1px rgba(0,0,0,.03)}
            .seo-tags-card .value{font-size:26px;font-weight:700;line-height:1.15;margin:3px 0}
            .seo-tags-card .label{font-weight:600;color:#1d2327}
            .seo-tags-card .meta{font-size:12px;color:#646970;margin-top:5px}
            .seo-tags-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:16px 0}
            .seo-tags-filter{display:flex;flex-wrap:wrap;gap:10px;align-items:end}
            .seo-tags-filter label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#50575e}
            .seo-tags-filter input[type=text],.seo-tags-filter select{min-width:170px;max-width:270px}
            .seo-tags-filter .search-wide{min-width:310px;max-width:420px}
            .seo-tags-table{table-layout:auto}
            .seo-tags-table th{white-space:nowrap}
            .seo-tags-product{min-width:300px;max-width:470px}
            .seo-tags-pills{display:flex;flex-wrap:wrap;gap:4px;min-width:120px}
            .seo-tags-pill{display:inline-block;padding:3px 7px;border-radius:999px;background:#f0f0f1;border:1px solid #dcdcde;font-size:11px;line-height:1.25}
            .seo-tags-pill.role{background:#f1f5ff;border-color:#c7d2fe}
            .seo-tags-pill.type{background:#fff7e6;border-color:#f3d28e}
            .seo-tags-pill.application{background:#eaf7ef;border-color:#b7dfc6}
            .seo-tags-pill.platform{background:#eef7ff;border-color:#b9d9f2}
            .seo-tags-pill.subtype{background:#f8efff;border-color:#ddc3f4}
            .seo-tags-muted{color:#8c8f94;font-style:italic}
            .seo-tags-alignment{min-width:190px;max-width:300px}
            .seo-tags-alignment-state{display:inline-block;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
            .seo-tags-alignment-state.good{background:#edfaef;color:#176b2c}
            .seo-tags-alignment-state.warn{background:#fff8e5;color:#8a5a00}
            .seo-tags-alignment-state.bad{background:#fce8e6;color:#a12622}
            .seo-tags-alignment-state.muted{background:#f0f0f1;color:#646970}
            .seo-tags-count{color:#646970;font-size:12px;margin:10px 0}
            .seo-tags-pagination{display:flex;gap:5px;align-items:center;flex-wrap:wrap;margin-top:14px}
            .seo-tags-pagination a,.seo-tags-pagination span{display:inline-block;padding:4px 8px;border:1px solid #c3c4c7;background:#fff;text-decoration:none;border-radius:3px}
            .seo-tags-pagination .current{background:#2271b1;color:#fff;border-color:#2271b1}
            .seo-tags-ok{color:#137333;font-weight:700}.seo-tags-bad{color:#b32d2e;font-weight:700}.seo-tags-warn{color:#996800;font-weight:700}
            .seo-tags-control-grid{display:grid;grid-template-columns:minmax(250px,1fr) minmax(130px,.35fr) minmax(300px,1.3fr);gap:0;border:1px solid #dcdcde;border-bottom:0}
            .seo-tags-control-grid>div{padding:10px 12px;border-bottom:1px solid #dcdcde;background:#fff}
            .seo-tags-control-grid .head{font-weight:700;background:#f6f7f7}
            .seo-tags-vocab-source{font-size:11px;color:#646970}
            .seo-tags-manager-form{display:grid;grid-template-columns:minmax(180px,.55fr) minmax(280px,1.25fr) minmax(220px,.8fr) auto;gap:10px;align-items:end}
            .seo-tags-manager-form label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#50575e}
            .seo-tags-manager-form input,.seo-tags-manager-form select{width:100%}
            .seo-tags-state{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700}
            .seo-tags-state.active{background:#edfaef;color:#176b2c}.seo-tags-state.inactive{background:#f0f0f1;color:#646970}
            .seo-tags-row-form{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.seo-tags-row-form input[type=text]{min-width:240px}
            .seo-tags-danger{color:#b32d2e}.seo-tags-help{font-size:12px;color:#646970;margin-top:6px}
            @media(max-width:900px){.seo-tags-control-grid{grid-template-columns:1fr}.seo-tags-control-grid .head{display:none}.seo-tags-filter .search-wide{min-width:220px}.seo-tags-manager-form{grid-template-columns:1fr}}
        </style>';
    }
}

if (!function_exists('seo_tags_vocab_get_summary')) {
    function seo_tags_vocab_get_summary() {
        global $wpdb;

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';

        $summary = [
            'products' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
            ),
            'groups' => [],
        ];

        foreach (['rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo'] as $group) {
            $summary['groups'][$group] = [
                'terms' => 0,
                'assignments' => 0,
                'products' => 0,
            ];
        }

        if (seo_tags_vocab_table_exists($vocabulary) && seo_tags_vocab_table_exists($objects)) {
            $rows = $wpdb->get_results(
                "SELECT
                    v.semantic_group,
                    COUNT(DISTINCT v.id) AS term_count,
                    COUNT(DISTINCT ov.id) AS assignment_count,
                    COUNT(DISTINCT CASE WHEN p.ID IS NOT NULL THEN ov.object_id END) AS product_count
                 FROM {$vocabulary} v
                 LEFT JOIN {$objects} ov
                   ON ov.vocabulary_id = v.id
                  AND ov.object_type = 'product'
                  AND ov.status = 1
                 LEFT JOIN {$wpdb->posts} p
                   ON p.ID = ov.object_id
                  AND p.post_type = 'product'
                  AND p.post_status = 'publish'
                 WHERE v.active = 1
                   AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                 GROUP BY v.semantic_group",
                ARRAY_A
            );

            foreach ((array) $rows as $row) {
                $group = (string) ($row['semantic_group'] ?? '');
                if (!isset($summary['groups'][$group])) {
                    continue;
                }
                $summary['groups'][$group] = [
                    'terms' => (int) ($row['term_count'] ?? 0),
                    'assignments' => (int) ($row['assignment_count'] ?? 0),
                    'products' => (int) ($row['product_count'] ?? 0),
                ];
            }
        }

        return $summary;
    }
}

if (!function_exists('seo_tags_vocab_render_summary_cards')) {
    function seo_tags_vocab_render_summary_cards(array $summary) {
        $cards = [
            ['Productos', $summary['products'], 'Catálogo publicado'],
            ['TIPO', $summary['groups']['tipo']['products'], number_format_i18n($summary['groups']['tipo']['terms']) . ' términos'],
            ['Ámbito / ROL', $summary['groups']['rol']['products'], number_format_i18n($summary['groups']['rol']['terms']) . ' términos'],
            ['APLICACIÓN', $summary['groups']['aplicacion']['products'], number_format_i18n($summary['groups']['aplicacion']['terms']) . ' términos · ' . number_format_i18n($summary['groups']['aplicacion']['assignments']) . ' asignaciones'],
            ['PLATAFORMA', $summary['groups']['plataforma']['products'], number_format_i18n($summary['groups']['plataforma']['terms']) . ' términos · ' . number_format_i18n($summary['groups']['plataforma']['assignments']) . ' asignaciones'],
            ['SUBTIPO', $summary['groups']['subtipo']['products'], number_format_i18n($summary['groups']['subtipo']['terms']) . ' términos'],
        ];

        echo '<div class="seo-tags-cards">';
        foreach ($cards as $card) {
            echo '<div class="seo-tags-card">';
            echo '<div class="label">' . esc_html($card[0]) . '</div>';
            echo '<div class="value">' . esc_html(number_format_i18n((int) $card[1])) . '</div>';
            echo '<div class="meta">' . esc_html($card[2]) . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('seo_tags_vocab_get_term_options')) {
    function seo_tags_vocab_get_term_options() {
        global $wpdb;
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';

        $result = [
            'rol' => [],
            'aplicacion' => [],
            'plataforma' => [],
            'subtipo' => [],
        ];

        if (!seo_tags_vocab_table_exists($vocabulary)) {
            return $result;
        }

        $rows = $wpdb->get_results(
            "SELECT id, semantic_group, slug, label
             FROM {$vocabulary}
             WHERE active = 1
               AND semantic_group IN ('rol','aplicacion','plataforma','subtipo')
             ORDER BY semantic_group ASC, label ASC, slug ASC",
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

if (!function_exists('seo_tags_vocab_render_select')) {
    function seo_tags_vocab_render_select($name, $label, array $rows, $selected) {
        echo '<label>' . esc_html($label);
        echo '<select name="' . esc_attr($name) . '">';
        echo '<option value="0">Todos</option>';
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $text = trim((string) ($row['label'] ?? ''));
            if ($text === '') {
                $text = (string) ($row['slug'] ?? '');
            }
            echo '<option value="' . esc_attr($id) . '" ' . selected((int) $selected, $id, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select></label>';
    }
}

if (!function_exists('seo_tags_vocab_get_category_profiles')) {
    function seo_tags_vocab_get_category_profiles(array $category_ids) {
        global $wpdb;

        $category_ids = array_values(array_unique(array_filter(array_map('absint', $category_ids))));
        if (!$category_ids) {
            return [];
        }

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        if (!seo_tags_vocab_table_exists($vocabulary) || !seo_tags_vocab_table_exists($objects)) {
            return [];
        }

        $profiles = [];
        foreach ($category_ids as $category_id) {
            $term = get_term($category_id, 'product_cat');
            $profiles[$category_id] = [
                'name' => ($term && !is_wp_error($term)) ? (string) $term->name : ('Categoría #' . $category_id),
                'product_count' => 0,
                'groups' => [],
            ];
        }

        $ph = seo_tags_vocab_placeholders($category_ids, '%d');
        $count_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tt.term_id AS category_id, COUNT(DISTINCT p.ID) AS product_count
                 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt
                   ON tt.term_taxonomy_id = tr.term_taxonomy_id
                  AND tt.taxonomy = 'product_cat'
                 JOIN {$wpdb->posts} p
                   ON p.ID = tr.object_id
                  AND p.post_type = 'product'
                  AND p.post_status = 'publish'
                 WHERE tt.term_id IN ({$ph})
                 GROUP BY tt.term_id",
                ...$category_ids
            ),
            ARRAY_A
        );

        foreach ((array) $count_rows as $row) {
            $category_id = absint($row['category_id'] ?? 0);
            if (isset($profiles[$category_id])) {
                $profiles[$category_id]['product_count'] = absint($row['product_count'] ?? 0);
            }
        }

        $term_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    tt.term_id AS category_id,
                    v.semantic_group,
                    v.id AS vocabulary_id,
                    COUNT(DISTINCT p.ID) AS product_count
                 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt
                   ON tt.term_taxonomy_id = tr.term_taxonomy_id
                  AND tt.taxonomy = 'product_cat'
                 JOIN {$wpdb->posts} p
                   ON p.ID = tr.object_id
                  AND p.post_type = 'product'
                  AND p.post_status = 'publish'
                 JOIN {$objects} ov
                   ON ov.object_type = 'product'
                  AND ov.object_id = p.ID
                  AND ov.status = 1
                 JOIN {$vocabulary} v
                   ON v.id = ov.vocabulary_id
                  AND v.active = 1
                  AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                 WHERE tt.term_id IN ({$ph})
                 GROUP BY tt.term_id, v.semantic_group, v.id",
                ...$category_ids
            ),
            ARRAY_A
        );

        foreach ((array) $term_rows as $row) {
            $category_id = absint($row['category_id'] ?? 0);
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            $vocabulary_id = absint($row['vocabulary_id'] ?? 0);
            $hits = absint($row['product_count'] ?? 0);
            $total = absint($profiles[$category_id]['product_count'] ?? 0);
            if ($category_id < 1 || $vocabulary_id < 1 || $total < 1 || !isset($profiles[$category_id])) {
                continue;
            }

            if (!isset($profiles[$category_id]['groups'][$group])) {
                $profiles[$category_id]['groups'][$group] = [
                    'dominant_share' => 0.0,
                    'term_share' => [],
                ];
            }

            $share = $hits / $total;
            $profiles[$category_id]['groups'][$group]['term_share'][$vocabulary_id] = $share;
            if ($share > $profiles[$category_id]['groups'][$group]['dominant_share']) {
                $profiles[$category_id]['groups'][$group]['dominant_share'] = $share;
            }
        }

        return $profiles;
    }
}

if (!function_exists('seo_tags_vocab_get_type_role_map')) {
    function seo_tags_vocab_get_type_role_map(array $type_ids) {
        global $wpdb;

        $type_ids = array_values(array_unique(array_filter(array_map('absint', $type_ids))));
        if (!$type_ids) {
            return [];
        }

        $table = $wpdb->prefix . 'seo_type_role_map';
        if (!seo_tags_vocab_table_exists($table)) {
            return [];
        }

        $ph = seo_tags_vocab_placeholders($type_ids, '%d');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT type_vocabulary_id, role_vocabulary_id
                 FROM {$table}
                 WHERE active = 1
                   AND type_vocabulary_id IN ({$ph})",
                ...$type_ids
            ),
            ARRAY_A
        );

        $map = [];
        foreach ((array) $rows as $row) {
            $type_id = absint($row['type_vocabulary_id'] ?? 0);
            $role_id = absint($row['role_vocabulary_id'] ?? 0);
            if ($type_id > 0 && $role_id > 0) {
                $map[$type_id] = $role_id;
            }
        }
        return $map;
    }
}

if (!function_exists('seo_tags_vocab_evaluate_alignment')) {
    function seo_tags_vocab_evaluate_alignment(array $assignment, array $categories, array $profiles, array $type_role_map, $selected_category_id = 0) {
        $issues = [];
        $type_rows = (array) ($assignment['tipo'] ?? []);
        $role_rows = (array) ($assignment['rol'] ?? []);
        $type_ids = array_values(array_unique(array_filter(array_map(static function ($row) {
            return absint($row['vocabulary_id'] ?? 0);
        }, $type_rows))));
        $role_ids = array_values(array_unique(array_filter(array_map(static function ($row) {
            return absint($row['vocabulary_id'] ?? 0);
        }, $role_rows))));

        if (count($type_ids) !== 1) {
            $issues[] = count($type_ids) === 0 ? 'Falta TIPO' : 'Tiene más de un TIPO';
        }
        if (count($role_ids) !== 1) {
            $issues[] = count($role_ids) === 0 ? 'Falta ROL' : 'Tiene más de un ROL';
        }
        if (count($type_ids) === 1 && isset($type_role_map[$type_ids[0]])) {
            $expected_role = absint($type_role_map[$type_ids[0]]);
            if ($expected_role > 0 && !in_array($expected_role, $role_ids, true)) {
                $issues[] = 'ROL no corresponde al TIPO';
            }
        }

        $group_labels = [
            'rol' => 'ROL',
            'tipo' => 'TIPO',
            'aplicacion' => 'APLICACIÓN',
            'plataforma' => 'PLATAFORMA',
            'subtipo' => 'SUBTIPO',
        ];
        $category_scores = [];

        foreach ($categories as $category) {
            $category_id = absint($category['term_id'] ?? 0);
            if ($category_id < 1 || ($selected_category_id > 0 && $category_id !== (int) $selected_category_id)) {
                continue;
            }
            if (empty($profiles[$category_id])) {
                continue;
            }

            $profile = $profiles[$category_id];
            $peer_count = absint($profile['product_count'] ?? 0);
            if ($peer_count < 8) {
                continue;
            }

            $signals = [];
            $details = [];
            foreach ($group_labels as $group => $group_label) {
                $rows = (array) ($assignment[$group] ?? []);
                $group_profile = $profile['groups'][$group] ?? null;
                if (!$rows || !$group_profile) {
                    continue;
                }

                $dominant_share = (float) ($group_profile['dominant_share'] ?? 0);
                if ($dominant_share < 0.35) {
                    continue;
                }

                $support = 0.0;
                foreach ($rows as $row) {
                    $vocabulary_id = absint($row['vocabulary_id'] ?? 0);
                    $term_share = (float) ($group_profile['term_share'][$vocabulary_id] ?? 0);
                    if ($term_share > $support) {
                        $support = $term_share;
                    }
                }

                $signals[] = min(1.0, $support / $dominant_share);
                $details[] = sprintf(
                    '%s %d%% frente a patrón %d%%',
                    $group_label,
                    (int) round($support * 100),
                    (int) round($dominant_share * 100)
                );
            }

            if (count($signals) < 2) {
                continue;
            }

            $score = (int) round((array_sum($signals) / count($signals)) * 100);
            $category_scores[] = [
                'category_id' => $category_id,
                'category_name' => (string) ($profile['name'] ?? ($category['name'] ?? 'Categoría')),
                'score' => max(0, min(100, $score)),
                'peer_count' => $peer_count,
                'details' => $details,
            ];
        }

        $worst = null;
        foreach ($category_scores as $candidate) {
            if ($worst === null || $candidate['score'] < $worst['score']) {
                $worst = $candidate;
            }
        }

        if ($issues) {
            $detail = implode('; ', $issues);
            if ($worst) {
                $detail .= '. Alineación con ' . $worst['category_name'] . ': ' . $worst['score'] . '/100';
            }
            return [
                'score' => $worst ? $worst['score'] : null,
                'state' => 'Revisar etiquetas',
                'class' => 'bad',
                'category' => $worst['category_name'] ?? '',
                'detail' => $detail,
            ];
        }

        if (!$worst) {
            return [
                'score' => null,
                'state' => 'Sin patrón suficiente',
                'class' => 'muted',
                'category' => '',
                'detail' => 'Se necesitan al menos 8 productos y dos grupos semánticos con un patrón dominante del 35% o más.',
            ];
        }

        if ($worst['score'] < 40) {
            $state = 'Revisar categoría';
            $class = 'bad';
        } elseif ($worst['score'] < 70) {
            $state = 'Atípico';
            $class = 'warn';
        } else {
            $state = 'Correcto';
            $class = 'good';
        }

        $detail = $worst['category_name'] . ' · ' . $worst['peer_count'] . ' productos. ' . implode(' · ', $worst['details']);
        return [
            'score' => $worst['score'],
            'state' => $state,
            'class' => $class,
            'category' => $worst['category_name'],
            'detail' => $detail,
        ];
    }
}

if (!function_exists('seo_tags_vocab_render_products')) {
    function seo_tags_vocab_render_products() {
        global $wpdb;

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';

        if (!seo_tags_vocab_table_exists($vocabulary) || !seo_tags_vocab_table_exists($objects)) {
            echo '<div class="notice notice-error inline"><p>No están disponibles las tablas canónicas <code>seo_vocabulary</code> y <code>seo_object_vocabulary</code>.</p></div>';
            return;
        }

        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $type_search = sanitize_text_field(wp_unslash($_GET['tipo'] ?? ''));
        $role_id = absint($_GET['rol_id'] ?? 0);
        $application_id = absint($_GET['aplicacion_id'] ?? 0);
        $platform_id = absint($_GET['plataforma_id'] ?? 0);
        $subtype_id = absint($_GET['subtipo_id'] ?? 0);
        $term_id = absint($_GET['term_id'] ?? 0);
        $category_id = absint($_GET['category_id'] ?? 0);
        $coverage = sanitize_key($_GET['coverage'] ?? 'all');
        $allowed_coverage = ['all', 'with_application', 'without_application', 'with_platform', 'without_platform', 'with_subtype', 'without_subtype'];
        if (!in_array($coverage, $allowed_coverage, true)) {
            $coverage = 'all';
        }

        $per_page = absint($_GET['per_page'] ?? 50);
        if (!in_array($per_page, [25, 50, 100, 200], true)) {
            $per_page = 50;
        }
        $page_number = max(1, absint($_GET['paged'] ?? 1));
        $offset = ($page_number - 1) * $per_page;

        $where = ["p.post_type = 'product'", "p.post_status = 'publish'"];
        $args = [];

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $parts = ["p.post_title LIKE %s"];
            $local_args = [$like];
            if (ctype_digit($search)) {
                $parts[] = 'p.ID = %d';
                $local_args[] = (int) $search;
            }
            $parts[] = "EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm_search
                WHERE pm_search.post_id = p.ID
                  AND pm_search.meta_key = '_sku'
                  AND pm_search.meta_value LIKE %s
            )";
            $local_args[] = $like;
            $where[] = '(' . implode(' OR ', $parts) . ')';
            $args = array_merge($args, $local_args);
        }

        if ($category_id > 0) {
            $where[] = "EXISTS (
                SELECT 1
                FROM {$wpdb->term_relationships} trc
                JOIN {$wpdb->term_taxonomy} ttc
                  ON ttc.term_taxonomy_id = trc.term_taxonomy_id
                 AND ttc.taxonomy = 'product_cat'
                WHERE trc.object_id = p.ID
                  AND ttc.term_id = %d
            )";
            $args[] = $category_id;
        }

        $facet_filters = [
            ['id' => $role_id, 'group' => 'rol'],
            ['id' => $application_id, 'group' => 'aplicacion'],
            ['id' => $platform_id, 'group' => 'plataforma'],
            ['id' => $subtype_id, 'group' => 'subtipo'],
        ];

        foreach ($facet_filters as $filter) {
            if ($filter['id'] < 1) {
                continue;
            }
            $where[] = "EXISTS (
                SELECT 1
                FROM {$objects} ovf
                JOIN {$vocabulary} vf ON vf.id = ovf.vocabulary_id
                WHERE ovf.object_type = 'product'
                  AND ovf.object_id = p.ID
                  AND ovf.status = 1
                  AND vf.active = 1
                  AND vf.semantic_group = %s
                  AND vf.id = %d
            )";
            $args[] = $filter['group'];
            $args[] = $filter['id'];
        }

        if ($term_id > 0) {
            $where[] = "EXISTS (
                SELECT 1 FROM {$objects} ovterm
                JOIN {$vocabulary} vterm ON vterm.id = ovterm.vocabulary_id
                WHERE ovterm.object_type = 'product'
                  AND ovterm.object_id = p.ID
                  AND ovterm.status = 1
                  AND vterm.id = %d
            )";
            $args[] = $term_id;
        }

        if ($type_search !== '') {
            $type_like = '%' . $wpdb->esc_like($type_search) . '%';
            $where[] = "EXISTS (
                SELECT 1 FROM {$objects} ovt
                JOIN {$vocabulary} vt ON vt.id = ovt.vocabulary_id
                WHERE ovt.object_type = 'product'
                  AND ovt.object_id = p.ID
                  AND ovt.status = 1
                  AND vt.active = 1
                  AND vt.semantic_group = 'tipo'
                  AND (vt.label LIKE %s OR vt.slug LIKE %s)
            )";
            $args[] = $type_like;
            $args[] = $type_like;
        }

        $coverage_group = '';
        $coverage_positive = true;
        if ($coverage === 'with_application' || $coverage === 'without_application') {
            $coverage_group = 'aplicacion';
            $coverage_positive = $coverage === 'with_application';
        } elseif ($coverage === 'with_platform' || $coverage === 'without_platform') {
            $coverage_group = 'plataforma';
            $coverage_positive = $coverage === 'with_platform';
        } elseif ($coverage === 'with_subtype' || $coverage === 'without_subtype') {
            $coverage_group = 'subtipo';
            $coverage_positive = $coverage === 'with_subtype';
        }

        if ($coverage_group !== '') {
            $where[] = ($coverage_positive ? '' : 'NOT ') . "EXISTS (
                SELECT 1 FROM {$objects} ovc
                JOIN {$vocabulary} vc ON vc.id = ovc.vocabulary_id
                WHERE ovc.object_type = 'product'
                  AND ovc.object_id = p.ID
                  AND ovc.status = 1
                  AND vc.active = 1
                  AND vc.semantic_group = %s
            )";
            $args[] = $coverage_group;
        }

        $where_sql = implode(' AND ', $where);
        $count_sql = seo_tags_vocab_prepare_sql(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where_sql}",
            $args
        );
        $total = (int) $wpdb->get_var($count_sql);
        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($page_number > $total_pages) {
            $page_number = $total_pages;
            $offset = ($page_number - 1) * $per_page;
        }

        if ($category_id > 0) {
            // En una categoría concreta analizamos el conjunto completo para poder
            // ordenar de verdad por anomalía antes de paginar.
            $products_sql = seo_tags_vocab_prepare_sql(
                "SELECT p.ID, p.post_title
                 FROM {$wpdb->posts} p
                 WHERE {$where_sql}
                 ORDER BY p.ID DESC",
                $args
            );
        } else {
            $query_args = $args;
            $query_args[] = $per_page;
            $query_args[] = $offset;
            $products_sql = seo_tags_vocab_prepare_sql(
                "SELECT p.ID, p.post_title
                 FROM {$wpdb->posts} p
                 WHERE {$where_sql}
                 ORDER BY p.ID DESC
                 LIMIT %d OFFSET %d",
                $query_args
            );
        }
        $products = (array) $wpdb->get_results($products_sql, ARRAY_A);
        $product_ids = array_map('intval', array_column($products, 'ID'));

        $sku_map = [];
        $assignments = [];
        foreach ($product_ids as $product_id) {
            $assignments[$product_id] = [
                'rol' => [],
                'tipo' => [],
                'aplicacion' => [],
                'plataforma' => [],
                'subtipo' => [],
            ];
        }

        if ($product_ids) {
            $ph = seo_tags_vocab_placeholders($product_ids, '%d');

            $sku_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, MAX(meta_value) AS sku
                     FROM {$wpdb->postmeta}
                     WHERE meta_key = '_sku'
                       AND post_id IN ({$ph})
                     GROUP BY post_id",
                    ...$product_ids
                ),
                ARRAY_A
            );
            foreach ((array) $sku_rows as $row) {
                $sku_map[(int) $row['post_id']] = (string) ($row['sku'] ?? '');
            }

            $vocab_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ov.object_id, v.id AS vocabulary_id, v.semantic_group, v.slug, v.label, ov.source, ov.confidence
                     FROM {$objects} ov
                     JOIN {$vocabulary} v
                       ON v.id = ov.vocabulary_id
                      AND v.active = 1
                     WHERE ov.object_type = 'product'
                       AND ov.status = 1
                       AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                       AND ov.object_id IN ({$ph})
                     ORDER BY ov.object_id ASC, v.semantic_group ASC, v.label ASC, v.slug ASC",
                    ...$product_ids
                ),
                ARRAY_A
            );

            foreach ((array) $vocab_rows as $row) {
                $product_id = (int) ($row['object_id'] ?? 0);
                $group = (string) ($row['semantic_group'] ?? '');
                if (!isset($assignments[$product_id][$group])) {
                    continue;
                }
                $assignments[$product_id][$group][] = $row;
            }
        }

        $alignment = [];
        if ($category_id > 0) {
            $selected_term = get_term($category_id, 'product_cat');
            $selected_name = ($selected_term && !is_wp_error($selected_term))
                ? (string) $selected_term->name
                : ('Categoría #' . $category_id);
            $product_categories = [];
            foreach ($product_ids as $product_id) {
                $product_categories[$product_id] = [[
                    'term_id' => $category_id,
                    'name' => $selected_name,
                ]];
            }
            $profiles = seo_tags_vocab_get_category_profiles([$category_id]);

            $type_ids = [];
            foreach ($assignments as $assignment) {
                foreach ((array) ($assignment['tipo'] ?? []) as $row) {
                    $candidate_id = absint($row['vocabulary_id'] ?? 0);
                    if ($candidate_id > 0) {
                        $type_ids[] = $candidate_id;
                    }
                }
            }
            $type_role_map = seo_tags_vocab_get_type_role_map($type_ids);

            foreach ($product_ids as $product_id) {
                $alignment[$product_id] = seo_tags_vocab_evaluate_alignment(
                    $assignments[$product_id] ?? [],
                    $product_categories[$product_id] ?? [],
                    $profiles,
                    $type_role_map,
                    $category_id
                );
            }

            $class_rank = ['bad' => 0, 'warn' => 1, 'good' => 2, 'muted' => 3];
            usort($products, static function ($a, $b) use ($alignment, $class_rank) {
                $a_id = absint($a['ID'] ?? 0);
                $b_id = absint($b['ID'] ?? 0);
                $a_row = $alignment[$a_id] ?? [];
                $b_row = $alignment[$b_id] ?? [];
                $a_rank = $class_rank[$a_row['class'] ?? 'muted'] ?? 3;
                $b_rank = $class_rank[$b_row['class'] ?? 'muted'] ?? 3;
                if ($a_rank !== $b_rank) {
                    return $a_rank <=> $b_rank;
                }
                $a_score = $a_row['score'] === null ? 101 : (int) $a_row['score'];
                $b_score = $b_row['score'] === null ? 101 : (int) $b_row['score'];
                if ($a_score !== $b_score) {
                    return $a_score <=> $b_score;
                }
                return $b_id <=> $a_id;
            });

            $products = array_slice($products, $offset, $per_page);
        } else {
            // En la vista global calculamos la alineación para los productos de la página
            // usando sus categorías reales. Así la columna siempre aporta información sin
            // obligar a seleccionar previamente una categoría.
            $product_categories = [];
            $profile_category_ids = [];

            if ($product_ids) {
                $ph = seo_tags_vocab_placeholders($product_ids, '%d');
                $category_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT tr.object_id AS product_id, tt.term_id, t.name
                         FROM {$wpdb->term_relationships} tr
                         JOIN {$wpdb->term_taxonomy} tt
                           ON tt.term_taxonomy_id = tr.term_taxonomy_id
                          AND tt.taxonomy = 'product_cat'
                         JOIN {$wpdb->terms} t
                           ON t.term_id = tt.term_id
                         WHERE tr.object_id IN ({$ph})
                         ORDER BY tr.object_id ASC, t.name ASC",
                        ...$product_ids
                    ),
                    ARRAY_A
                );

                foreach ((array) $category_rows as $row) {
                    $product_id = absint($row['product_id'] ?? 0);
                    $row_category_id = absint($row['term_id'] ?? 0);
                    if ($product_id < 1 || $row_category_id < 1) {
                        continue;
                    }
                    if (!isset($product_categories[$product_id])) {
                        $product_categories[$product_id] = [];
                    }
                    $product_categories[$product_id][] = [
                        'term_id' => $row_category_id,
                        'name' => (string) ($row['name'] ?? ('Categoría #' . $row_category_id)),
                    ];
                    $profile_category_ids[] = $row_category_id;
                }
            }

            $profiles = seo_tags_vocab_get_category_profiles($profile_category_ids);

            $type_ids = [];
            foreach ($assignments as $assignment) {
                foreach ((array) ($assignment['tipo'] ?? []) as $row) {
                    $candidate_id = absint($row['vocabulary_id'] ?? 0);
                    if ($candidate_id > 0) {
                        $type_ids[] = $candidate_id;
                    }
                }
            }
            $type_role_map = seo_tags_vocab_get_type_role_map($type_ids);

            foreach ($product_ids as $product_id) {
                $alignment[$product_id] = seo_tags_vocab_evaluate_alignment(
                    $assignments[$product_id] ?? [],
                    $product_categories[$product_id] ?? [],
                    $profiles,
                    $type_role_map,
                    0
                );
            }
        }

        $options = seo_tags_vocab_get_term_options();
        $category_options = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
        if (is_wp_error($category_options)) {
            $category_options = [];
        }
        $base_url = admin_url('admin.php?page=seo-tags-vocabulary&section=products');

        echo '<div class="seo-tags-panel">';
        echo '<p class="seo-tags-help" style="margin-top:0"><strong>Alineación con categoría:</strong> se calcula para cada producto usando sus categorías reales y el vocabulary canónico de sus compañeros. Si seleccionas una categoría, los productos se ordenan de más atípicos a más alineados. No usa etiquetas legacy, no cuenta palabras y no mueve productos automáticamente.</p>';
        echo '<form method="get" class="seo-tags-filter">';
        echo '<input type="hidden" name="page" value="seo-tags-vocabulary">';
        echo '<input type="hidden" name="section" value="products">';
        if ($term_id > 0) {
            echo '<input type="hidden" name="term_id" value="' . esc_attr($term_id) . '">';
        }
        echo '<label>Producto / SKU / ID<input class="search-wide" type="text" name="s" value="' . esc_attr($search) . '" placeholder="Buscar producto, SKU o ID"></label>';
        echo '<label>Categoría<select name="category_id"><option value="0">Todas</option>';
        foreach ((array) $category_options as $category_option) {
            echo '<option value="' . esc_attr((int) $category_option->term_id) . '" ' . selected($category_id, (int) $category_option->term_id, false) . '>' . esc_html($category_option->name) . '</option>';
        }
        echo '</select></label>';
        echo '<label>TIPO contiene<input type="text" name="tipo" value="' . esc_attr($type_search) . '" placeholder="ej. taladro"></label>';
        seo_tags_vocab_render_select('rol_id', 'Ámbito / ROL', $options['rol'], $role_id);
        seo_tags_vocab_render_select('aplicacion_id', 'Aplicación', $options['aplicacion'], $application_id);
        seo_tags_vocab_render_select('plataforma_id', 'Plataforma', $options['plataforma'], $platform_id);
        seo_tags_vocab_render_select('subtipo_id', 'Subtipo', $options['subtipo'], $subtype_id);
        echo '<label>Cobertura<select name="coverage">';
        $coverage_options = [
            'all' => 'Todos',
            'with_application' => 'Con aplicación',
            'without_application' => 'Sin aplicación',
            'with_platform' => 'Con plataforma',
            'without_platform' => 'Sin plataforma',
            'with_subtype' => 'Con subtipo',
            'without_subtype' => 'Sin subtipo',
        ];
        foreach ($coverage_options as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($coverage, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Filas<select name="per_page">';
        foreach ([25, 50, 100, 200] as $n) {
            echo '<option value="' . esc_attr($n) . '" ' . selected($per_page, $n, false) . '>' . esc_html($n) . '</option>';
        }
        echo '</select></label>';
        echo '<div><button class="button button-primary" type="submit">Filtrar</button> <a class="button" href="' . esc_url($base_url) . '">Limpiar</a></div>';
        echo '</form>';
        echo '</div>';

        if ($term_id > 0) {
            $term = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT semantic_group, label, slug FROM {$vocabulary} WHERE id = %d LIMIT 1",
                    $term_id
                ),
                ARRAY_A
            );
            if ($term) {
                $term_text = trim((string) ($term['label'] ?? '')) ?: (string) ($term['slug'] ?? '');
                echo '<div class="notice notice-info inline"><p>Filtro de vocabulario: <strong>' . esc_html(seo_tags_vocab_group_label($term['semantic_group'])) . ' · ' . esc_html($term_text) . '</strong>. <a href="' . esc_url($base_url) . '">Quitar filtro</a></p></div>';
            }
        }

        echo '<div class="seo-tags-count">Mostrando ' . number_format_i18n(count($products)) . ' de ' . number_format_i18n($total) . ' productos.</div>';
        echo '<div style="overflow:auto">';
        echo '<table class="widefat striped seo-tags-table">';
        echo '<thead><tr><th>Producto</th><th>Ámbito / ROL</th><th>TIPO</th><th>APLICACIÓN</th><th>PLATAFORMA</th><th>SUBTIPO</th><th>Alineación</th><th>Acción</th></tr></thead><tbody>';

        if (!$products) {
            echo '<tr><td colspan="8">No hay productos que coincidan con los filtros.</td></tr>';
        }

        foreach ($products as $product) {
            $product_id = (int) $product['ID'];
            $title = (string) $product['post_title'];
            $sku = $sku_map[$product_id] ?? '';
            echo '<tr>';
            echo '<td class="seo-tags-product"><strong>#' . esc_html($product_id) . ' · ' . esc_html($title) . '</strong>';
            if ($sku !== '') {
                echo '<br><span class="seo-tags-vocab-source">SKU: ' . esc_html($sku) . '</span>';
            }
            echo '</td>';

            $groups = [
                'rol' => 'role',
                'tipo' => 'type',
                'aplicacion' => 'application',
                'plataforma' => 'platform',
                'subtipo' => 'subtype',
            ];
            foreach ($groups as $group => $class) {
                echo '<td><div class="seo-tags-pills">';
                $rows = $assignments[$product_id][$group] ?? [];
                if (!$rows) {
                    echo '<span class="seo-tags-muted">—</span>';
                } else {
                    foreach ($rows as $row) {
                        $label = trim((string) ($row['label'] ?? '')) ?: (string) ($row['slug'] ?? '');
                        $tooltip = trim('source=' . (string) ($row['source'] ?? '') . ' confidence=' . (string) ($row['confidence'] ?? ''));
                        echo '<span class="seo-tags-pill ' . esc_attr($class) . '" title="' . esc_attr($tooltip) . '">' . esc_html($label) . '</span>';
                    }
                }
                echo '</div></td>';
            }

            $alignment_row = $alignment[$product_id] ?? [
                'score' => null,
                'state' => 'Sin patrón suficiente',
                'class' => 'muted',
                'category' => '',
                'detail' => '',
            ];
            echo '<td class="seo-tags-alignment">';
            echo '<span class="seo-tags-alignment-state ' . esc_attr($alignment_row['class']) . '" title="' . esc_attr((string) $alignment_row['detail']) . '">';
            if ($alignment_row['score'] !== null) {
                echo '<strong>' . esc_html((int) $alignment_row['score']) . '/100</strong> · ';
            }
            echo esc_html((string) $alignment_row['state']) . '</span>';
            if (!empty($alignment_row['category'])) {
                echo '<br><span class="seo-tags-vocab-source">' . esc_html((string) $alignment_row['category']) . '</span>';
            }
            echo '</td>';

            $edit_url = get_edit_post_link($product_id, '');
            echo '<td>';
            if ($edit_url) {
                echo '<a class="button button-small" href="' . esc_url($edit_url . '#seo-product-vocabulary') . '">Gestionar clasificación</a>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        if ($total_pages > 1) {
            $query = $_GET;
            unset($query['paged']);
            echo '<div class="seo-tags-pagination">';
            $window_start = max(1, $page_number - 3);
            $window_end = min($total_pages, $page_number + 3);
            if ($page_number > 1) {
                $query['paged'] = $page_number - 1;
                echo '<a href="' . esc_url(add_query_arg($query, admin_url('admin.php'))) . '">‹</a>';
            }
            for ($i = $window_start; $i <= $window_end; $i++) {
                if ($i === $page_number) {
                    echo '<span class="current">' . esc_html($i) . '</span>';
                } else {
                    $query['paged'] = $i;
                    echo '<a href="' . esc_url(add_query_arg($query, admin_url('admin.php'))) . '">' . esc_html($i) . '</a>';
                }
            }
            if ($page_number < $total_pages) {
                $query['paged'] = $page_number + 1;
                echo '<a href="' . esc_url(add_query_arg($query, admin_url('admin.php'))) . '">›</a>';
            }
            echo '<span>Página ' . esc_html($page_number) . ' / ' . esc_html($total_pages) . '</span>';
            echo '</div>';
        }
    }
}


if (!function_exists('seo_tags_vocab_allowed_groups')) {
    function seo_tags_vocab_allowed_groups() {
        return ['rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo'];
    }
}

if (!function_exists('seo_tags_vocab_get_active_roles')) {
    function seo_tags_vocab_get_active_roles() {
        global $wpdb;
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        if (!seo_tags_vocab_table_exists($vocabulary)) {
            return [];
        }
        return (array) $wpdb->get_results(
            "SELECT id, slug, label
             FROM {$vocabulary}
             WHERE semantic_group = 'rol' AND active = 1
             ORDER BY label ASC, slug ASC",
            ARRAY_A
        );
    }
}

if (!function_exists('seo_tags_vocab_get_type_role_id')) {
    function seo_tags_vocab_get_type_role_id($type_id) {
        global $wpdb;
        $type_id = absint($type_id);
        if ($type_id < 1) {
            return 0;
        }
        $map = $wpdb->prefix . 'seo_type_role_map';
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        if (!seo_tags_vocab_table_exists($map) || !seo_tags_vocab_table_exists($vocabulary)) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT trm.role_vocabulary_id
             FROM {$map} trm
             JOIN {$vocabulary} rv
               ON rv.id = trm.role_vocabulary_id
              AND rv.semantic_group = 'rol'
              AND rv.active = 1
             WHERE trm.type_vocabulary_id = %d
               AND trm.active = 1
             ORDER BY trm.id ASC
             LIMIT 1",
            $type_id
        ));
    }
}

if (!function_exists('seo_tags_vocab_set_type_role')) {
    function seo_tags_vocab_set_type_role($type_id, $role_id) {
        global $wpdb;
        $type_id = absint($type_id);
        $role_id = absint($role_id);
        $map = $wpdb->prefix . 'seo_type_role_map';
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';

        if ($type_id < 1 || $role_id < 1 || !seo_tags_vocab_table_exists($map) || !seo_tags_vocab_table_exists($vocabulary)) {
            return false;
        }

        $valid_role = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$vocabulary}
             WHERE id = %d AND semantic_group = 'rol' AND active = 1",
            $role_id
        ));
        if ($valid_role !== 1) {
            return false;
        }

        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$map} WHERE type_vocabulary_id = %d ORDER BY active DESC, id ASC LIMIT 1",
            $type_id
        ));

        $wpdb->query('START TRANSACTION');
        $disabled = $wpdb->query($wpdb->prepare(
            "UPDATE {$map} SET active = 0 WHERE type_vocabulary_id = %d",
            $type_id
        ));
        if ($disabled === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        if ($existing_id > 0) {
            $ok = $wpdb->update(
                $map,
                ['role_vocabulary_id' => $role_id, 'confidence' => 1.0000, 'active' => 1],
                ['id' => $existing_id],
                ['%d', '%f', '%d'],
                ['%d']
            );
        } else {
            $ok = $wpdb->insert(
                $map,
                [
                    'type_vocabulary_id' => $type_id,
                    'role_vocabulary_id' => $role_id,
                    'confidence' => 1.0000,
                    'active' => 1,
                ],
                ['%d', '%d', '%f', '%d']
            );
        }

        if ($ok === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        $wpdb->query('COMMIT');
        return true;
    }
}

if (!function_exists('seo_tags_vocab_sync_products_for_type')) {
    function seo_tags_vocab_sync_products_for_type($type_id) {
        global $wpdb;
        $type_id = absint($type_id);
        if ($type_id < 1 || !function_exists('seo_catalog_sync_product_role_from_type')) {
            return;
        }
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        if (!seo_tags_vocab_table_exists($objects)) {
            return;
        }
        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT object_id
             FROM {$objects}
             WHERE object_type = 'product'
               AND vocabulary_id = %d
               AND status = 1",
            $type_id
        ));
        foreach ((array) $product_ids as $product_id) {
            seo_catalog_sync_product_role_from_type((int) $product_id, 'vocabulary_manager');
        }
    }
}

if (!function_exists('seo_tags_vocab_has_reactivation_conflict')) {
    function seo_tags_vocab_has_reactivation_conflict($term_id, $group) {
        global $wpdb;
        $term_id = absint($term_id);
        $group = sanitize_key($group);
        if ($term_id < 1 || !in_array($group, ['tipo', 'rol'], true)) {
            return false;
        }
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        if (!seo_tags_vocab_table_exists($vocabulary) || !seo_tags_vocab_table_exists($objects)) {
            return false;
        }
        $conflicts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT historical.object_id)
             FROM {$objects} historical
             JOIN {$objects} current_assignment
               ON current_assignment.object_type = historical.object_type
              AND current_assignment.object_id = historical.object_id
              AND current_assignment.status = 1
              AND current_assignment.vocabulary_id <> historical.vocabulary_id
             JOIN {$vocabulary} current_term
               ON current_term.id = current_assignment.vocabulary_id
              AND current_term.semantic_group = %s
              AND current_term.active = 1
             WHERE historical.object_type = 'product'
               AND historical.vocabulary_id = %d
               AND historical.status = 1",
            $group,
            $term_id
        ));
        return $conflicts > 0;
    }
}

if (!function_exists('seo_tags_vocab_manager_redirect')) {
    function seo_tags_vocab_manager_redirect($notice, $group = 'aplicacion', $status = 'all') {
        $group = in_array($group, seo_tags_vocab_allowed_groups(), true) ? $group : 'aplicacion';
        $status = in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'all';
        wp_safe_redirect(add_query_arg([
            'page' => 'seo-tags-vocabulary',
            'section' => 'vocabulary',
            'group' => $group,
            'status' => $status,
            'seo_vocab_notice' => sanitize_key($notice),
        ], admin_url('admin.php')));
        exit;
    }
}

if (!function_exists('seo_tags_vocab_handle_manager_action')) {
    function seo_tags_vocab_handle_manager_action() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['seo_vocab_manager_action'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para gestionar el vocabulario.');
        }
        check_admin_referer('seo_tags_vocab_manager', 'seo_tags_vocab_manager_nonce');

        global $wpdb;
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        $map = $wpdb->prefix . 'seo_type_role_map';
        if (!seo_tags_vocab_table_exists($vocabulary)) {
            seo_tags_vocab_manager_redirect('tables_missing');
        }

        $action = sanitize_key(wp_unslash($_POST['seo_vocab_manager_action']));
        $group = sanitize_key(wp_unslash($_POST['semantic_group'] ?? 'aplicacion'));
        $status_filter = sanitize_key(wp_unslash($_POST['return_status'] ?? 'all'));
        if (!in_array($group, seo_tags_vocab_allowed_groups(), true)) {
            $group = 'aplicacion';
        }

        if ($action === 'create') {
            $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
            $role_id = absint($_POST['role_id'] ?? 0);
            if ($label === '') {
                seo_tags_vocab_manager_redirect('empty_label', $group, $status_filter);
            }
            $slug = sanitize_title($label);
            if ($slug === '') {
                seo_tags_vocab_manager_redirect('invalid_slug', $group, $status_filter);
            }
            if ($group === 'tipo' && $role_id < 1) {
                seo_tags_vocab_manager_redirect('type_role_required', $group, $status_filter);
            }

            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, active FROM {$vocabulary}
                 WHERE semantic_group = %s AND slug = %s
                 LIMIT 1",
                $group,
                $slug
            ), ARRAY_A);

            if (is_array($existing) && !empty($existing['id'])) {
                $term_id = (int) $existing['id'];
                if ((int) $existing['active'] === 1) {
                    seo_tags_vocab_manager_redirect('duplicate', $group, $status_filter);
                }
                if (seo_tags_vocab_has_reactivation_conflict($term_id, $group)) {
                    seo_tags_vocab_manager_redirect('reactivation_conflict', $group, $status_filter);
                }
                $ok = $wpdb->update(
                    $vocabulary,
                    ['label' => $label, 'active' => 1, 'source' => 'manual', 'updated_at' => current_time('mysql')],
                    ['id' => $term_id],
                    ['%s', '%d', '%s', '%s'],
                    ['%d']
                );
                if ($ok === false) {
                    seo_tags_vocab_manager_redirect('save_failed', $group, $status_filter);
                }
            } else {
                $ok = $wpdb->insert(
                    $vocabulary,
                    [
                        'semantic_group' => $group,
                        'slug' => $slug,
                        'label' => $label,
                        'source' => 'manual',
                        'active' => 1,
                    ],
                    ['%s', '%s', '%s', '%s', '%d']
                );
                if (!$ok) {
                    seo_tags_vocab_manager_redirect('save_failed', $group, $status_filter);
                }
                $term_id = (int) $wpdb->insert_id;
            }

            if ($group === 'tipo') {
                if (!seo_tags_vocab_set_type_role($term_id, $role_id)) {
                    $wpdb->update($vocabulary, ['active' => 0], ['id' => $term_id], ['%d'], ['%d']);
                    seo_tags_vocab_manager_redirect('type_role_failed', $group, $status_filter);
                }
                seo_tags_vocab_sync_products_for_type($term_id);
            }
            seo_tags_vocab_manager_redirect('created', $group, $status_filter);
        }

        $term_id = absint($_POST['term_id'] ?? 0);
        $term = $term_id > 0 ? $wpdb->get_row($wpdb->prepare(
            "SELECT id, semantic_group, slug, label, active FROM {$vocabulary} WHERE id = %d LIMIT 1",
            $term_id
        ), ARRAY_A) : null;
        if (!is_array($term)) {
            seo_tags_vocab_manager_redirect('not_found', $group, $status_filter);
        }
        $group = (string) $term['semantic_group'];

        if ($action === 'update') {
            $label = sanitize_text_field(wp_unslash($_POST['label'] ?? ''));
            $role_id = absint($_POST['role_id'] ?? 0);
            if ($label === '') {
                seo_tags_vocab_manager_redirect('empty_label', $group, $status_filter);
            }
            $slug = sanitize_title($label);
            if ($slug === '') {
                seo_tags_vocab_manager_redirect('invalid_slug', $group, $status_filter);
            }
            $duplicate_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$vocabulary}
                 WHERE semantic_group = %s AND slug = %s AND id <> %d
                 LIMIT 1",
                $group,
                $slug,
                $term_id
            ));
            if ($duplicate_id > 0) {
                seo_tags_vocab_manager_redirect('duplicate', $group, $status_filter);
            }
            if ($group === 'tipo' && $role_id < 1) {
                seo_tags_vocab_manager_redirect('type_role_required', $group, $status_filter);
            }

            $ok = $wpdb->update(
                $vocabulary,
                ['label' => $label, 'slug' => $slug, 'source' => 'manual', 'updated_at' => current_time('mysql')],
                ['id' => $term_id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );
            if ($ok === false) {
                seo_tags_vocab_manager_redirect('save_failed', $group, $status_filter);
            }
            if ($group === 'tipo') {
                if (!seo_tags_vocab_set_type_role($term_id, $role_id)) {
                    seo_tags_vocab_manager_redirect('type_role_failed', $group, $status_filter);
                }
                if ((int) $term['active'] === 1) {
                    seo_tags_vocab_sync_products_for_type($term_id);
                }
            }
            seo_tags_vocab_manager_redirect('updated', $group, $status_filter);
        }

        if ($action === 'deactivate') {
            if ($group === 'rol' && seo_tags_vocab_table_exists($map)) {
                $dependent_types = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT tv.id)
                     FROM {$map} trm
                     JOIN {$vocabulary} tv
                       ON tv.id = trm.type_vocabulary_id
                      AND tv.semantic_group = 'tipo'
                      AND tv.active = 1
                     WHERE trm.role_vocabulary_id = %d
                       AND trm.active = 1",
                    $term_id
                ));
                if ($dependent_types > 0) {
                    seo_tags_vocab_manager_redirect('role_in_use_by_types', $group, $status_filter);
                }
            }
            $ok = $wpdb->update(
                $vocabulary,
                ['active' => 0, 'updated_at' => current_time('mysql')],
                ['id' => $term_id],
                ['%d', '%s'],
                ['%d']
            );
            if ($ok === false) {
                seo_tags_vocab_manager_redirect('save_failed', $group, $status_filter);
            }
            // No se borran ni se desactivan relaciones de producto: se conserva historial.
            seo_tags_vocab_manager_redirect('deactivated', $group, $status_filter);
        }

        if ($action === 'reactivate') {
            if ($group === 'tipo') {
                $role_id = seo_tags_vocab_get_type_role_id($term_id);
                if ($role_id < 1) {
                    seo_tags_vocab_manager_redirect('type_role_required', $group, $status_filter);
                }
            }

            if (seo_tags_vocab_has_reactivation_conflict($term_id, $group)) {
                seo_tags_vocab_manager_redirect('reactivation_conflict', $group, $status_filter);
            }

            $ok = $wpdb->update(
                $vocabulary,
                ['active' => 1, 'updated_at' => current_time('mysql')],
                ['id' => $term_id],
                ['%d', '%s'],
                ['%d']
            );
            if ($ok === false) {
                seo_tags_vocab_manager_redirect('save_failed', $group, $status_filter);
            }
            if ($group === 'tipo') {
                seo_tags_vocab_sync_products_for_type($term_id);
            }
            seo_tags_vocab_manager_redirect('reactivated', $group, $status_filter);
        }
    }
    add_action('admin_init', 'seo_tags_vocab_handle_manager_action');
}

if (!function_exists('seo_tags_vocab_render_manager_notice')) {
    function seo_tags_vocab_render_manager_notice() {
        $code = sanitize_key($_GET['seo_vocab_notice'] ?? '');
        if ($code === '') {
            return;
        }
        $messages = [
            'created' => ['success', 'Etiqueta creada y disponible para selección.'],
            'updated' => ['success', 'Etiqueta actualizada. Los productos asignados conservan el mismo ID y muestran el nuevo valor.'],
            'deactivated' => ['success', 'Etiqueta dada de baja. Se conservan sus relaciones históricas y deja de estar disponible para nuevas selecciones.'],
            'reactivated' => ['success', 'Etiqueta reactivada y disponible nuevamente.'],
            'reactivation_conflict' => ['error', 'No se puede reactivar porque algunos productos ya tienen otro TIPO o ROL activo. Las relaciones históricas se han conservado sin cambios.'],
            'duplicate' => ['error', 'Ya existe una etiqueta con ese valor dentro del mismo grupo.'],
            'empty_label' => ['error', 'El nombre de la etiqueta no puede estar vacío.'],
            'invalid_slug' => ['error', 'No se ha podido generar un identificador válido para la etiqueta.'],
            'type_role_required' => ['error', 'Todo TIPO necesita un ROL activo asociado.'],
            'type_role_failed' => ['error', 'No se ha podido guardar la relación TIPO → ROL.'],
            'role_in_use_by_types' => ['error', 'No se puede dar de baja este ROL porque todavía tiene TIPOS activos asociados. Cambia primero esos TIPOS a otro ROL.'],
            'not_found' => ['error', 'La etiqueta ya no existe o no se ha podido localizar.'],
            'tables_missing' => ['error', 'No está disponible la tabla del vocabulario.'],
            'save_failed' => ['error', 'No se ha podido guardar el cambio en la base de datos.'],
        ];
        if (!isset($messages[$code])) {
            return;
        }
        [$type, $message] = $messages[$code];
        echo '<div class="notice notice-' . esc_attr($type) . ' inline is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }
}

if (!function_exists('seo_tags_vocab_render_vocabulary')) {
    function seo_tags_vocab_render_vocabulary() {
        global $wpdb;

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        $type_role_map = $wpdb->prefix . 'seo_type_role_map';

        if (!seo_tags_vocab_table_exists($vocabulary) || !seo_tags_vocab_table_exists($objects)) {
            echo '<div class="notice notice-error inline"><p>No están disponibles las tablas del vocabulario.</p></div>';
            return;
        }

        $allowed_groups = seo_tags_vocab_allowed_groups();
        $group = sanitize_key($_GET['group'] ?? 'aplicacion');
        if (!in_array($group, $allowed_groups, true)) {
            $group = 'aplicacion';
        }
        $status = sanitize_key($_GET['status'] ?? 'all');
        if (!in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }
        $search = sanitize_text_field(wp_unslash($_GET['vs'] ?? ''));
        $per_page = 100;
        $page_number = max(1, absint($_GET['paged'] ?? 1));
        $offset = ($page_number - 1) * $per_page;
        $roles = seo_tags_vocab_get_active_roles();

        seo_tags_vocab_render_manager_notice();

        echo '<div class="seo-tags-panel">';
        echo '<h2 style="margin-top:0">Alta de etiqueta</h2>';
        echo '<p>Los productos solo podrán seleccionar términos activos de este vocabulario. Los TIPO requieren siempre un ROL.</p>';
        echo '<form method="post" class="seo-tags-manager-form">';
        wp_nonce_field('seo_tags_vocab_manager', 'seo_tags_vocab_manager_nonce');
        echo '<input type="hidden" name="seo_vocab_manager_action" value="create">';
        echo '<input type="hidden" name="return_status" value="' . esc_attr($status) . '">';
        echo '<label>Grupo<select name="semantic_group" id="seo-vocab-create-group">';
        foreach ($allowed_groups as $candidate) {
            echo '<option value="' . esc_attr($candidate) . '" ' . selected($group, $candidate, false) . '>' . esc_html(seo_tags_vocab_group_label($candidate)) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Nombre visible<input type="text" name="label" required placeholder="Ej. Instalación fotovoltaica"></label>';
        echo '<label>ROL para TIPO<select name="role_id"><option value="0">— Solo obligatorio para TIPO —</option>';
        foreach ($roles as $role) {
            $role_label = trim((string) ($role['label'] ?? '')) ?: (string) ($role['slug'] ?? '');
            echo '<option value="' . esc_attr((int) $role['id']) . '">' . esc_html($role_label) . '</option>';
        }
        echo '</select></label>';
        echo '<div><button class="button button-primary" type="submit">+ Dar de alta</button></div>';
        echo '</form>';
        echo '<p class="seo-tags-help">El slug se genera automáticamente. Si se da de alta un término que estaba inactivo, se reactiva sin perder sus relaciones anteriores.</p>';
        echo '</div>';

        $where = ['v.semantic_group = %s'];
        $args = [$group];
        if ($status === 'active') {
            $where[] = 'v.active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'v.active = 0';
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(v.label LIKE %s OR v.slug LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }
        $where_sql = implode(' AND ', $where);
        $total = (int) $wpdb->get_var(seo_tags_vocab_prepare_sql(
            "SELECT COUNT(*) FROM {$vocabulary} v WHERE {$where_sql}",
            $args
        ));
        $total_pages = max(1, (int) ceil($total / $per_page));

        $query_args = $args;
        $query_args[] = $per_page;
        $query_args[] = $offset;
        $rows = $wpdb->get_results(seo_tags_vocab_prepare_sql(
            "SELECT
                v.id, v.semantic_group, v.slug, v.label, v.active, v.source AS term_source,
                COUNT(DISTINCT CASE WHEN ov.status = 1 THEN ov.id END) AS assignment_count,
                COUNT(DISTINCT CASE WHEN ov.status = 1 AND p.ID IS NOT NULL THEN ov.object_id END) AS product_count,
                ROUND(AVG(CASE WHEN ov.status = 1 THEN ov.confidence END), 4) AS avg_confidence,
                GROUP_CONCAT(DISTINCT CASE WHEN ov.status = 1 THEN ov.source END ORDER BY ov.source SEPARATOR ', ') AS assignment_sources
             FROM {$vocabulary} v
             LEFT JOIN {$objects} ov
               ON ov.vocabulary_id = v.id
              AND ov.object_type = 'product'
             LEFT JOIN {$wpdb->posts} p
               ON p.ID = ov.object_id
              AND p.post_type = 'product'
              AND p.post_status <> 'trash'
             WHERE {$where_sql}
             GROUP BY v.id, v.semantic_group, v.slug, v.label, v.active, v.source
             ORDER BY v.active DESC, product_count DESC, v.label ASC, v.slug ASC
             LIMIT %d OFFSET %d",
            $query_args
        ), ARRAY_A);

        $type_roles = [];
        if ($group === 'tipo' && seo_tags_vocab_table_exists($type_role_map)) {
            $map_rows = $wpdb->get_results(
                "SELECT trm.type_vocabulary_id, trm.role_vocabulary_id, rv.label AS role_label, rv.slug AS role_slug, rv.active AS role_active
                 FROM {$type_role_map} trm
                 LEFT JOIN {$vocabulary} rv ON rv.id = trm.role_vocabulary_id AND rv.semantic_group = 'rol'
                 WHERE trm.active = 1",
                ARRAY_A
            );
            foreach ((array) $map_rows as $map_row) {
                $type_roles[(int) $map_row['type_vocabulary_id']] = $map_row;
            }
        }

        $role_dependents = [];
        if ($group === 'rol' && seo_tags_vocab_table_exists($type_role_map)) {
            $dep_rows = $wpdb->get_results(
                "SELECT trm.role_vocabulary_id, COUNT(DISTINCT tv.id) AS type_count
                 FROM {$type_role_map} trm
                 JOIN {$vocabulary} tv ON tv.id = trm.type_vocabulary_id AND tv.semantic_group = 'tipo' AND tv.active = 1
                 WHERE trm.active = 1
                 GROUP BY trm.role_vocabulary_id",
                ARRAY_A
            );
            foreach ((array) $dep_rows as $dep_row) {
                $role_dependents[(int) $dep_row['role_vocabulary_id']] = (int) $dep_row['type_count'];
            }
        }

        echo '<div class="seo-tags-panel">';
        echo '<form method="get" class="seo-tags-filter">';
        echo '<input type="hidden" name="page" value="seo-tags-vocabulary"><input type="hidden" name="section" value="vocabulary">';
        echo '<label>Grupo<select name="group">';
        foreach ($allowed_groups as $candidate) {
            echo '<option value="' . esc_attr($candidate) . '" ' . selected($group, $candidate, false) . '>' . esc_html(seo_tags_vocab_group_label($candidate)) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Estado<select name="status">';
        foreach (['all' => 'Activas e inactivas', 'active' => 'Solo activas', 'inactive' => 'Solo inactivas'] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($status, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Buscar término<input type="text" name="vs" value="' . esc_attr($search) . '" placeholder="nombre o slug"></label>';
        echo '<div><button class="button" type="submit">Filtrar</button></div>';
        echo '</form></div>';

        echo '<div class="seo-tags-count">' . esc_html(seo_tags_vocab_group_label($group)) . ': ' . number_format_i18n($total) . ' términos.</div>';
        echo '<div style="overflow:auto"><table class="widefat striped seo-tags-table"><thead><tr><th>ID</th><th>Nombre / edición</th><th>Slug</th><th>Estado</th>';
        if ($group === 'tipo') {
            echo '<th>ROL asociado</th>';
        } elseif ($group === 'rol') {
            echo '<th>TIPOS activos</th>';
        }
        echo '<th>Productos</th><th>Asignaciones</th><th>Fuente</th><th>Acciones</th></tr></thead><tbody>';

        if (!$rows) {
            $colspan = ($group === 'tipo' || $group === 'rol') ? 9 : 8;
            echo '<tr><td colspan="' . esc_attr($colspan) . '">No hay términos con estos filtros.</td></tr>';
        }

        foreach ((array) $rows as $row) {
            $term_id = (int) $row['id'];
            $label = trim((string) ($row['label'] ?? '')) ?: (string) ($row['slug'] ?? '');
            $is_active = (int) ($row['active'] ?? 0) === 1;
            $products_url = add_query_arg([
                'page' => 'seo-tags-vocabulary',
                'section' => 'products',
                'term_id' => $term_id,
            ], admin_url('admin.php'));
            $current_role_id = $group === 'tipo' && isset($type_roles[$term_id])
                ? (int) $type_roles[$term_id]['role_vocabulary_id']
                : 0;

            echo '<tr>';
            echo '<td>' . esc_html($term_id) . '</td>';
            echo '<td>';
            echo '<form method="post" class="seo-tags-row-form">';
            wp_nonce_field('seo_tags_vocab_manager', 'seo_tags_vocab_manager_nonce');
            echo '<input type="hidden" name="seo_vocab_manager_action" value="update">';
            echo '<input type="hidden" name="term_id" value="' . esc_attr($term_id) . '">';
            echo '<input type="hidden" name="semantic_group" value="' . esc_attr($group) . '">';
            echo '<input type="hidden" name="return_status" value="' . esc_attr($status) . '">';
            echo '<input type="text" name="label" value="' . esc_attr($label) . '" required>';
            if ($group === 'tipo') {
                echo '<select name="role_id" required title="ROL asociado al TIPO"><option value="0">— Seleccionar ROL —</option>';
                foreach ($roles as $role) {
                    $role_label = trim((string) ($role['label'] ?? '')) ?: (string) ($role['slug'] ?? '');
                    echo '<option value="' . esc_attr((int) $role['id']) . '" ' . selected($current_role_id, (int) $role['id'], false) . '>' . esc_html($role_label) . '</option>';
                }
                echo '</select>';
            }
            echo '<button class="button button-small" type="submit">Guardar</button>';
            echo '</form>';
            echo '</td>';
            echo '<td><code>' . esc_html((string) $row['slug']) . '</code></td>';
            echo '<td><span class="seo-tags-state ' . ($is_active ? 'active' : 'inactive') . '">' . ($is_active ? 'ACTIVA' : 'INACTIVA') . '</span></td>';

            if ($group === 'tipo') {
                $role_text = '—';
                if (isset($type_roles[$term_id])) {
                    $role_text = trim((string) ($type_roles[$term_id]['role_label'] ?? '')) ?: (string) ($type_roles[$term_id]['role_slug'] ?? '—');
                    if ((int) ($type_roles[$term_id]['role_active'] ?? 0) !== 1) {
                        $role_text .= ' (inactivo)';
                    }
                }
                echo '<td>' . esc_html($role_text) . '</td>';
            } elseif ($group === 'rol') {
                $dependent = (int) ($role_dependents[$term_id] ?? 0);
                echo '<td>' . esc_html(number_format_i18n($dependent)) . '</td>';
            }

            echo '<td>' . esc_html(number_format_i18n((int) $row['product_count'])) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) $row['assignment_count'])) . '</td>';
            echo '<td><span class="seo-tags-vocab-source">' . esc_html((string) ($row['term_source'] ?? '')) . '</span></td>';
            echo '<td><a class="button button-small" href="' . esc_url($products_url) . '">Ver productos</a> ';

            echo '<form method="post" style="display:inline-block;margin-left:4px">';
            wp_nonce_field('seo_tags_vocab_manager', 'seo_tags_vocab_manager_nonce');
            echo '<input type="hidden" name="term_id" value="' . esc_attr($term_id) . '">';
            echo '<input type="hidden" name="semantic_group" value="' . esc_attr($group) . '">';
            echo '<input type="hidden" name="return_status" value="' . esc_attr($status) . '">';
            if ($is_active) {
                echo '<input type="hidden" name="seo_vocab_manager_action" value="deactivate">';
                $blocked = $group === 'rol' && (int) ($role_dependents[$term_id] ?? 0) > 0;
                if ($blocked) {
                    echo '<button class="button button-small" type="button" disabled title="Tiene TIPOS activos asociados">Dar de baja</button>';
                } else {
                    echo '<button class="button button-small seo-tags-danger" type="submit" onclick="return confirm(\'La etiqueta dejará de estar disponible, pero sus relaciones históricas se conservarán. ¿Continuar?\');">Dar de baja</button>';
                }
            } else {
                echo '<input type="hidden" name="seo_vocab_manager_action" value="reactivate">';
                echo '<button class="button button-small" type="submit">Reactivar</button>';
            }
            echo '</form></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        if ($total_pages > 1) {
            $query = $_GET;
            unset($query['paged']);
            echo '<div class="seo-tags-pagination">';
            $window_start = max(1, $page_number - 3);
            $window_end = min($total_pages, $page_number + 3);
            for ($i = $window_start; $i <= $window_end; $i++) {
                if ($i === $page_number) {
                    echo '<span class="current">' . esc_html($i) . '</span>';
                } else {
                    $query['paged'] = $i;
                    echo '<a href="' . esc_url(add_query_arg($query, admin_url('admin.php'))) . '">' . esc_html($i) . '</a>';
                }
            }
            echo '<span>Página ' . esc_html($page_number) . ' / ' . esc_html($total_pages) . '</span>';
            echo '</div>';
        }
    }
}

if (!function_exists('seo_tags_vocab_count_duplicate_products')) {
    function seo_tags_vocab_count_duplicate_products($group) {
        global $wpdb;
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT ov.object_id
                    FROM {$objects} ov
                    JOIN {$vocabulary} v
                      ON v.id = ov.vocabulary_id
                     AND v.active = 1
                     AND v.semantic_group = %s
                    JOIN {$wpdb->posts} p
                      ON p.ID = ov.object_id
                     AND p.post_type = 'product'
                     AND p.post_status = 'publish'
                    WHERE ov.object_type = 'product'
                      AND ov.status = 1
                    GROUP BY ov.object_id
                    HAVING COUNT(DISTINCT ov.vocabulary_id) > 1
                 ) duplicate_products",
                $group
            )
        );
    }
}

if (!function_exists('seo_tags_vocab_count_invalid_assignments')) {
    function seo_tags_vocab_count_invalid_assignments($group) {
        global $wpdb;
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$objects} ov
                 JOIN {$vocabulary} v
                   ON v.id = ov.vocabulary_id
                  AND v.active = 1
                  AND v.semantic_group = %s
                 LEFT JOIN {$wpdb->posts} p
                   ON p.ID = ov.object_id
                  AND p.post_type = 'product'
                  AND p.post_status = 'publish'
                 WHERE ov.object_type = 'product'
                   AND ov.status = 1
                   AND p.ID IS NULL",
                $group
            )
        );
    }
}

if (!function_exists('seo_tags_vocab_render_control_row')) {
    function seo_tags_vocab_render_control_row($label, $value, $status, $note) {
        $class = $status === 'ok' ? 'seo-tags-ok' : ($status === 'warn' ? 'seo-tags-warn' : 'seo-tags-bad');
        echo '<div>' . esc_html($label) . '</div>';
        echo '<div class="' . esc_attr($class) . '">' . wp_kses_post($value) . '</div>';
        echo '<div>' . esc_html($note) . '</div>';
    }
}

if (!function_exists('seo_tags_vocab_render_control')) {
    function seo_tags_vocab_render_control(array $summary) {
        global $wpdb;

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        $type_role_map = $wpdb->prefix . 'seo_type_role_map';

        if (!seo_tags_vocab_table_exists($vocabulary) || !seo_tags_vocab_table_exists($objects)) {
            echo '<div class="notice notice-error inline"><p>No están disponibles las tablas canónicas del vocabulario.</p></div>';
            return;
        }

        $products = (int) $summary['products'];
        $type_products = (int) $summary['groups']['tipo']['products'];
        $role_products = (int) $summary['groups']['rol']['products'];
        $duplicate_type = seo_tags_vocab_count_duplicate_products('tipo');
        $duplicate_role = seo_tags_vocab_count_duplicate_products('rol');
        $invalid_app = seo_tags_vocab_count_invalid_assignments('aplicacion');
        $invalid_platform = seo_tags_vocab_count_invalid_assignments('plataforma');
        $invalid_subtype = seo_tags_vocab_count_invalid_assignments('subtipo');

        $types_without_map = null;
        $role_disagreements = null;
        if (seo_tags_vocab_table_exists($type_role_map)) {
            $types_without_map = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT tv.id)
                 FROM {$vocabulary} tv
                 JOIN {$objects} ot
                   ON ot.vocabulary_id = tv.id
                  AND ot.object_type = 'product'
                  AND ot.status = 1
                 JOIN {$wpdb->posts} p
                   ON p.ID = ot.object_id
                  AND p.post_type = 'product'
                  AND p.post_status = 'publish'
                 LEFT JOIN {$type_role_map} trm
                   ON trm.type_vocabulary_id = tv.id
                  AND trm.active = 1
                 WHERE tv.semantic_group = 'tipo'
                   AND tv.active = 1
                   AND trm.id IS NULL"
            );

            $role_disagreements = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT ot.object_id)
                 FROM {$objects} ot
                 JOIN {$vocabulary} tv
                   ON tv.id = ot.vocabulary_id
                  AND tv.semantic_group = 'tipo'
                  AND tv.active = 1
                 JOIN {$type_role_map} trm
                   ON trm.type_vocabulary_id = tv.id
                  AND trm.active = 1
                 JOIN {$wpdb->posts} p
                   ON p.ID = ot.object_id
                  AND p.post_type = 'product'
                  AND p.post_status = 'publish'
                 WHERE ot.object_type = 'product'
                   AND ot.status = 1
                   AND NOT EXISTS (
                       SELECT 1
                       FROM {$objects} ro
                       JOIN {$vocabulary} rv
                         ON rv.id = ro.vocabulary_id
                        AND rv.semantic_group = 'rol'
                        AND rv.active = 1
                       WHERE ro.object_type = 'product'
                         AND ro.object_id = ot.object_id
                         AND ro.status = 1
                         AND ro.vocabulary_id = trm.role_vocabulary_id
                   )"
            );
        }

        echo '<div class="seo-tags-panel">';
        echo '<h2>Control de integridad</h2>';
        echo '<p>Esta sección comprueba la capa canónica visible en esta pestaña. No modifica datos.</p>';
        echo '</div>';

        echo '<div class="seo-tags-control-grid">';
        echo '<div class="head">Control</div><div class="head">Resultado</div><div class="head">Lectura</div>';

        seo_tags_vocab_render_control_row(
            'Cobertura TIPO',
            number_format_i18n($type_products) . ' / ' . number_format_i18n($products),
            ($type_products === $products && $duplicate_type === 0) ? 'ok' : 'bad',
            $duplicate_type === 0 ? 'Un único TIPO activo por producto.' : number_format_i18n($duplicate_type) . ' productos tienen más de un TIPO activo.'
        );
        seo_tags_vocab_render_control_row(
            'Cobertura Ámbito / ROL',
            number_format_i18n($role_products) . ' / ' . number_format_i18n($products),
            ($role_products === $products && $duplicate_role === 0) ? 'ok' : 'bad',
            $duplicate_role === 0 ? 'Un único ROL activo por producto.' : number_format_i18n($duplicate_role) . ' productos tienen más de un ROL activo.'
        );
        seo_tags_vocab_render_control_row(
            'APLICACIÓN',
            number_format_i18n($summary['groups']['aplicacion']['products']) . ' productos',
            $invalid_app === 0 ? 'ok' : 'bad',
            number_format_i18n($summary['groups']['aplicacion']['terms']) . ' términos · ' . number_format_i18n($summary['groups']['aplicacion']['assignments']) . ' asignaciones · ' . number_format_i18n($invalid_app) . ' inválidas.'
        );
        seo_tags_vocab_render_control_row(
            'PLATAFORMA',
            number_format_i18n($summary['groups']['plataforma']['products']) . ' productos',
            $invalid_platform === 0 ? 'ok' : 'bad',
            number_format_i18n($summary['groups']['plataforma']['terms']) . ' términos · ' . number_format_i18n($summary['groups']['plataforma']['assignments']) . ' asignaciones · ' . number_format_i18n($invalid_platform) . ' inválidas.'
        );
        seo_tags_vocab_render_control_row(
            'SUBTIPO',
            number_format_i18n($summary['groups']['subtipo']['products']) . ' productos',
            $invalid_subtype === 0 ? ($summary['groups']['subtipo']['products'] === 0 ? 'warn' : 'ok') : 'bad',
            number_format_i18n($summary['groups']['subtipo']['terms']) . ' términos · ' . number_format_i18n($summary['groups']['subtipo']['assignments']) . ' asignaciones · ' . number_format_i18n($invalid_subtype) . ' inválidas.'
        );

        if ($types_without_map !== null) {
            seo_tags_vocab_render_control_row(
                'TIPO → ROL sin mapa',
                number_format_i18n($types_without_map),
                $types_without_map === 0 ? 'ok' : 'bad',
                'Tipos usados por productos publicados que no tienen mapa activo.'
            );
        }
        if ($role_disagreements !== null) {
            seo_tags_vocab_render_control_row(
                'ROL materializado vs TIPO → ROL',
                number_format_i18n($role_disagreements),
                $role_disagreements === 0 ? 'ok' : 'bad',
                'Productos cuyo ROL activo no coincide con el mapa de su TIPO.'
            );
        }

        echo '</div>';
    }
}

if (!function_exists('seo_tags_vocab_get_dictionary_rows')) {
    /**
     * Devuelve el diccionario activo que pueden utilizar los editores.
     *
     * El valor humano (label) es el que debe escribirse en el CSV/XLSX de
     * productos. Los IDs quedan como referencia interna y nunca son necesarios
     * para catalogar manualmente.
     */
    function seo_tags_vocab_get_dictionary_rows() {
        global $wpdb;

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        $type_role_map = $wpdb->prefix . 'seo_type_role_map';

        if (!seo_tags_vocab_table_exists($vocabulary)) {
            return [];
        }

        $has_objects = seo_tags_vocab_table_exists($objects);
        $has_type_role_map = seo_tags_vocab_table_exists($type_role_map);

        $assignment_select = $has_objects
            ? "COUNT(DISTINCT CASE WHEN ov.status = 1 AND ov.object_type = 'product' THEN ov.id END) AS assignment_count,\n"
              . "COUNT(DISTINCT CASE WHEN ov.status = 1 AND ov.object_type = 'product' AND p.ID IS NOT NULL THEN ov.object_id END) AS product_count"
            : "0 AS assignment_count, 0 AS product_count";

        $objects_join = $has_objects
            ? "LEFT JOIN {$objects} ov ON ov.vocabulary_id = v.id\n"
              . "LEFT JOIN {$wpdb->posts} p ON p.ID = ov.object_id AND p.post_type = 'product' AND p.post_status = 'publish'"
            : '';

        $rows = $wpdb->get_results(
            "SELECT
                v.id,
                v.semantic_group,
                v.slug,
                v.label,
                v.source,
                {$assignment_select}
             FROM {$vocabulary} v
             {$objects_join}
             WHERE v.active = 1
               AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
             GROUP BY v.id, v.semantic_group, v.slug, v.label, v.source
             ORDER BY FIELD(v.semantic_group, 'rol','tipo','aplicacion','plataforma','subtipo'), v.label ASC, v.slug ASC",
            ARRAY_A
        );

        $type_roles = [];
        if ($has_type_role_map) {
            $map_rows = $wpdb->get_results(
                "SELECT
                    trm.type_vocabulary_id,
                    rv.id AS role_id,
                    rv.slug AS role_slug,
                    rv.label AS role_label
                 FROM {$type_role_map} trm
                 JOIN {$vocabulary} rv
                   ON rv.id = trm.role_vocabulary_id
                  AND rv.semantic_group = 'rol'
                  AND rv.active = 1
                 WHERE trm.active = 1",
                ARRAY_A
            );

            foreach ((array) $map_rows as $map_row) {
                $type_id = (int) ($map_row['type_vocabulary_id'] ?? 0);
                if ($type_id < 1 || isset($type_roles[$type_id])) {
                    continue;
                }
                $type_roles[$type_id] = [
                    'role_id' => (int) ($map_row['role_id'] ?? 0),
                    'role_slug' => (string) ($map_row['role_slug'] ?? ''),
                    'role_label' => trim((string) ($map_row['role_label'] ?? '')),
                ];
            }
        }

        $dictionary = [];
        foreach ((array) $rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $group = (string) ($row['semantic_group'] ?? '');
            $label = trim((string) ($row['label'] ?? ''));
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($label === '') {
                $label = $slug;
            }

            $role = ($group === 'tipo' && isset($type_roles[$id])) ? $type_roles[$id] : [];
            $dictionary[] = [
                'vocabulary_id' => $id,
                'grupo' => $group,
                'grupo_nombre' => seo_tags_vocab_group_label($group),
                'valor_permitido' => $label,
                'slug' => $slug,
                'rol_asociado' => (string) ($role['role_label'] ?? ''),
                'rol_slug' => (string) ($role['role_slug'] ?? ''),
                'productos' => (int) ($row['product_count'] ?? 0),
                'asignaciones' => (int) ($row['assignment_count'] ?? 0),
                'fuente' => (string) ($row['source'] ?? ''),
            ];
        }

        return $dictionary;
    }
}

if (!function_exists('seo_tags_vocab_export_dictionary')) {
    /**
     * Descarga el diccionario activo en CSV o JSON.
     */
    function seo_tags_vocab_export_dictionary() {
        if (!is_admin() || empty($_GET['seo_vocab_export'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para exportar el vocabulario.', 'seo-taxonomy'));
        }

        check_admin_referer('seo_tags_vocab_export_dictionary');

        $format = sanitize_key(wp_unslash($_GET['seo_vocab_export']));
        if (!in_array($format, ['csv', 'json'], true)) {
            wp_die(esc_html__('Formato de exportación no válido.', 'seo-taxonomy'));
        }

        $rows = seo_tags_vocab_get_dictionary_rows();
        $stamp = current_time('Ymd_His');

        nocache_headers();

        if ($format === 'json') {
            $payload = [
                'schema' => 'seo-taxonomy-vocabulary-dictionary',
                'version' => 1,
                'generated_at' => current_time('mysql'),
                'allowed_groups' => ['rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo'],
                'editor_rule' => 'Usar valor_permitido en el archivo de productos. No usar vocabulary_id como valor de catalogación.',
                'terms' => $rows,
            ];

            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="diccionario_etiquetas_' . esc_attr($stamp) . '.json"');
            echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="diccionario_etiquetas_' . esc_attr($stamp) . '.csv"');

        $output = fopen('php://output', 'w');
        if ($output === false) {
            wp_die(esc_html__('No se pudo generar el CSV.', 'seo-taxonomy'));
        }

        // BOM UTF-8 para que Excel abra correctamente acentos y eñes.
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, [
            'grupo',
            'valor_permitido',
            'slug',
            'rol_asociado',
            'rol_slug',
            'productos',
            'asignaciones',
            'vocabulary_id',
        ], ';');

        foreach ($rows as $row) {
            fputcsv($output, [
                $row['grupo'],
                $row['valor_permitido'],
                $row['slug'],
                $row['rol_asociado'],
                $row['rol_slug'],
                $row['productos'],
                $row['asignaciones'],
                $row['vocabulary_id'],
            ], ';');
        }

        fclose($output);
        exit;
    }
}
add_action('admin_init', 'seo_tags_vocab_export_dictionary');

if (!function_exists('seo_tags_vocab_render_dictionary')) {
    /**
     * Inventario de valores admitidos para catalogación manual e importación.
     */
    function seo_tags_vocab_render_dictionary() {
        $rows = seo_tags_vocab_get_dictionary_rows();
        if (!$rows) {
            echo '<div class="notice notice-warning inline"><p>No hay términos activos disponibles en el vocabulario canónico.</p></div>';
            return;
        }

        $group_filter = sanitize_key($_GET['dictionary_group'] ?? 'all');
        $allowed_groups = ['all', 'rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo'];
        if (!in_array($group_filter, $allowed_groups, true)) {
            $group_filter = 'all';
        }
        $search = sanitize_text_field(wp_unslash($_GET['dictionary_s'] ?? ''));

        $filtered = array_values(array_filter($rows, static function ($row) use ($group_filter, $search) {
            if ($group_filter !== 'all' && ($row['grupo'] ?? '') !== $group_filter) {
                return false;
            }
            if ($search === '') {
                return true;
            }
            $haystack = implode(' ', [
                (string) ($row['grupo'] ?? ''),
                (string) ($row['valor_permitido'] ?? ''),
                (string) ($row['slug'] ?? ''),
                (string) ($row['rol_asociado'] ?? ''),
                (string) ($row['rol_slug'] ?? ''),
            ]);
            if (function_exists('mb_stripos')) {
                return mb_stripos($haystack, $search, 0, 'UTF-8') !== false;
            }
            return stripos($haystack, $search) !== false;
        }));

        $counts = array_fill_keys(['rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo'], 0);
        foreach ($rows as $row) {
            $group = (string) ($row['grupo'] ?? '');
            if (isset($counts[$group])) {
                $counts[$group]++;
            }
        }

        $base = admin_url('admin.php?page=seo-tags-vocabulary&section=dictionary');
        $csv_url = wp_nonce_url(
            admin_url('admin.php?page=seo-tags-vocabulary&section=dictionary&seo_vocab_export=csv'),
            'seo_tags_vocab_export_dictionary'
        );
        $json_url = wp_nonce_url(
            admin_url('admin.php?page=seo-tags-vocabulary&section=dictionary&seo_vocab_export=json'),
            'seo_tags_vocab_export_dictionary'
        );

        echo '<div class="seo-tags-panel">';
        echo '<h2 style="margin-top:0">Diccionario para editores</h2>';
        echo '<p>Este inventario contiene los <strong>valores activos permitidos</strong> para clasificar productos. En el Excel/CSV debe utilizarse la columna <code>valor_permitido</code>; el <code>vocabulary_id</code> es únicamente una referencia interna.</p>';
        echo '<p>Para <strong>TIPO</strong> se muestra también el <strong>ROL asociado</strong>. El editor no necesita decidir ni inventar el ROL si el TIPO ya lo determina.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($csv_url) . '">Descargar CSV</a> <a class="button" href="' . esc_url($json_url) . '">Descargar JSON</a></p>';
        echo '</div>';

        echo '<div class="seo-tags-cards">';
        foreach (['rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo'] as $group) {
            echo '<div class="seo-tags-card"><div class="label">' . esc_html(seo_tags_vocab_group_label($group)) . '</div><div class="value">' . esc_html(number_format_i18n($counts[$group])) . '</div><div class="meta">valores permitidos</div></div>';
        }
        echo '</div>';

        echo '<div class="seo-tags-panel">';
        echo '<form method="get" class="seo-tags-filter">';
        echo '<input type="hidden" name="page" value="seo-tags-vocabulary"><input type="hidden" name="section" value="dictionary">';
        echo '<label>Grupo<select name="dictionary_group"><option value="all">Todos</option>';
        foreach (['rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo'] as $group) {
            echo '<option value="' . esc_attr($group) . '" ' . selected($group_filter, $group, false) . '>' . esc_html(seo_tags_vocab_group_label($group)) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Buscar<input class="search-wide" type="text" name="dictionary_s" value="' . esc_attr($search) . '" placeholder="valor, slug o ROL"></label>';
        echo '<div><button class="button button-primary" type="submit">Filtrar</button> <a class="button" href="' . esc_url($base) . '">Limpiar</a></div>';
        echo '</form>';
        echo '</div>';

        echo '<div class="seo-tags-count">Mostrando ' . esc_html(number_format_i18n(count($filtered))) . ' de ' . esc_html(number_format_i18n(count($rows))) . ' valores activos.</div>';
        echo '<div style="overflow:auto"><table class="widefat striped seo-tags-table">';
        echo '<thead><tr><th>Grupo</th><th>Valor permitido</th><th>Slug</th><th>ROL asociado</th><th>Productos</th><th>Asignaciones</th><th>ID interno</th></tr></thead><tbody>';
        if (!$filtered) {
            echo '<tr><td colspan="7">No hay valores que coincidan con el filtro.</td></tr>';
        }
        foreach ($filtered as $row) {
            $role = trim((string) ($row['rol_asociado'] ?? ''));
            if (($row['grupo'] ?? '') === 'tipo' && $role === '') {
                $role_html = '<span class="seo-tags-bad">SIN ROL ASOCIADO</span>';
            } else {
                $role_html = $role !== '' ? esc_html($role) : '<span class="seo-tags-muted">—</span>';
            }
            echo '<tr>';
            echo '<td><strong>' . esc_html((string) $row['grupo_nombre']) . '</strong></td>';
            echo '<td><strong>' . esc_html((string) $row['valor_permitido']) . '</strong></td>';
            echo '<td><code>' . esc_html((string) $row['slug']) . '</code></td>';
            echo '<td>' . $role_html . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) $row['productos'])) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) $row['asignaciones'])) . '</td>';
            echo '<td><code>' . esc_html((int) $row['vocabulary_id']) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}


/**
 * Resumen del vocabulario técnico de atributos de producto.
 */
if (!function_exists('seo_semantic_attributes_get_summary')) {
    function seo_semantic_attributes_get_summary() {
        global $wpdb;
        if (!function_exists('seo_attributes_tables')) {
            return [];
        }
        $tables = seo_attributes_tables();
        $total_products = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type='product' AND post_status NOT IN ('trash','auto-draft')"
        );
        $products = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pa.product_id) FROM `{$tables['values']}` pa
             INNER JOIN `{$wpdb->posts}` p ON p.ID=pa.product_id
             WHERE p.post_type='product' AND p.post_status NOT IN ('trash','auto-draft')"
        );
        return [
            'products_total' => $total_products,
            'products'       => $products,
            'definitions'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tables['definitions']}` WHERE activo=1"),
            'terms'          => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tables['terms']}` WHERE activo=1"),
            'aliases'        => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tables['aliases']}`"),
            'assignments'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tables['values']}`"),
        ];
    }
}

if (!function_exists('seo_semantic_attributes_render_summary_cards')) {
    function seo_semantic_attributes_render_summary_cards(array $summary) {
        $total = (int) ($summary['products_total'] ?? 0);
        $with = (int) ($summary['products'] ?? 0);
        $coverage = $total > 0 ? round(($with / $total) * 100, 1) : 0;
        $cards = [
            ['Productos con atributos', $with, $coverage . '% de ' . number_format_i18n($total)],
            ['Atributos', (int) ($summary['definitions'] ?? 0), 'definiciones activas'],
            ['Términos', (int) ($summary['terms'] ?? 0), 'valores controlados'],
            ['Aliases', (int) ($summary['aliases'] ?? 0), 'sinónimos de entrada'],
            ['Asignaciones', (int) ($summary['assignments'] ?? 0), 'relaciones producto · atributo'],
        ];
        echo '<div class="seo-tags-cards">';
        foreach ($cards as $card) {
            echo '<div class="seo-tags-card"><div class="label">' . esc_html($card[0]) . '</div>';
            echo '<div class="value">' . esc_html(number_format_i18n((float) $card[1], is_float($card[1]) ? 1 : 0)) . '</div>';
            echo '<div class="meta">' . esc_html($card[2]) . '</div></div>';
        }
        echo '</div>';
    }
}

/** Procesa las escrituras de la sección Atributos. */
if (!function_exists('seo_semantic_attributes_handle_action')) {
    function seo_semantic_attributes_handle_action() {
        if (empty($_POST['seo_semantic_attribute_action'])) {
            return null;
        }
        $action = sanitize_key(wp_unslash($_POST['seo_semantic_attribute_action']));
        check_admin_referer('seo_semantic_attributes_admin', 'seo_semantic_attributes_nonce');

        try {
            switch ($action) {
                case 'save_definition':
                    $result = seo_attributes_save_definition([
                        'id'          => absint($_POST['definition_id'] ?? 0),
                        'slug'        => sanitize_text_field(wp_unslash($_POST['definition_slug'] ?? '')),
                        'nombre'      => sanitize_text_field(wp_unslash($_POST['definition_name'] ?? '')),
                        'grupo'       => sanitize_text_field(wp_unslash($_POST['definition_group'] ?? '')),
                        'tipo'        => sanitize_key(wp_unslash($_POST['definition_type'] ?? 'texto')),
                        'unidad_tipo' => sanitize_text_field(wp_unslash($_POST['definition_unit_type'] ?? '')),
                        'unidad_base' => sanitize_text_field(wp_unslash($_POST['definition_unit_base'] ?? '')),
                        'multiple'    => !empty($_POST['definition_multiple']),
                        'filtrable'   => !empty($_POST['definition_filterable']),
                        'visible'     => !empty($_POST['definition_visible']),
                        'seo'         => !empty($_POST['definition_seo']),
                        'orden'       => (int) ($_POST['definition_order'] ?? 0),
                        'activo'      => !empty($_POST['definition_active']),
                    ]);
                    return ['success', 'Definición guardada. Operación #' . (int) ($result['operation_id'] ?? 0) . '.'];

                case 'delete_definition':
                    $slug = sanitize_key(wp_unslash($_POST['definition_slug'] ?? ''));
                    $result = seo_attributes_delete_master_type($slug, 'semantic_attributes_admin');
                    return ['success', 'Definición eliminada: ' . (int) ($result['deleted'] ?? 0) . ' filas.'];

                case 'save_term':
                    $result = seo_attributes_save_term([
                        'id'          => absint($_POST['term_id'] ?? 0),
                        'atributo_id' => absint($_POST['attribute_id'] ?? 0),
                        'slug'        => sanitize_text_field(wp_unslash($_POST['term_slug'] ?? '')),
                        'nombre'      => sanitize_text_field(wp_unslash($_POST['term_name'] ?? '')),
                        'orden'       => (int) ($_POST['term_order'] ?? 0),
                        'activo'      => !empty($_POST['term_active']),
                    ]);
                    return ['success', 'Término guardado. Operación #' . (int) ($result['operation_id'] ?? 0) . '.'];

                case 'delete_term':
                    $result = seo_attributes_delete_term(absint($_POST['term_id'] ?? 0), 'semantic_attributes_admin');
                    return ['success', 'Término eliminado: ' . (int) ($result['deleted'] ?? 0) . ' filas.'];

                case 'add_alias':
                    $result = seo_attributes_add_alias(
                        absint($_POST['attribute_id'] ?? 0),
                        absint($_POST['term_id'] ?? 0),
                        sanitize_text_field(wp_unslash($_POST['alias_value'] ?? '')),
                        'semantic_attributes_admin'
                    );
                    return ['success', 'Alias añadido. Operación #' . (int) ($result['operation_id'] ?? 0) . '.'];

                case 'delete_alias':
                    $result = seo_attributes_delete_alias(absint($_POST['alias_id'] ?? 0), 'semantic_attributes_admin');
                    return ['success', 'Alias eliminado.'];
            }
            throw new InvalidArgumentException('Acción de atributos no reconocida.');
        } catch (Throwable $e) {
            return ['error', $e->getMessage()];
        }
    }
}

if (!function_exists('seo_semantic_attributes_notice')) {
    function seo_semantic_attributes_notice($notice) {
        if (!is_array($notice) || count($notice) < 2) return;
        $class = $notice[0] === 'success' ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . ' inline"><p>' . esc_html((string) $notice[1]) . '</p></div>';
    }
}

/** Gestión de definiciones de wp_sql_atributos. */
if (!function_exists('seo_semantic_attributes_render_definitions')) {
    function seo_semantic_attributes_render_definitions() {
        global $wpdb;
        $tables = seo_attributes_tables();
        $edit_id = absint($_GET['edit_attribute'] ?? 0);
        $editing = $edit_id > 0
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$tables['definitions']}` WHERE id=%d LIMIT 1", $edit_id), ARRAY_A)
            : null;
        $editing = is_array($editing) ? $editing : [];
        $defs = $wpdb->get_results(
            "SELECT a.*,
                    (SELECT COUNT(DISTINCT pa.product_id) FROM `{$tables['values']}` pa WHERE pa.atributo_id=a.id) AS products,
                    (SELECT COUNT(*) FROM `{$tables['values']}` pa WHERE pa.atributo_id=a.id) AS assignments,
                    (SELECT COUNT(*) FROM `{$tables['terms']}` t WHERE t.atributo_id=a.id) AS terms
             FROM `{$tables['definitions']}` a
             ORDER BY a.activo DESC,a.orden ASC,a.nombre ASC",
            ARRAY_A
        );
        $base = admin_url('admin.php?page=seo-tags-vocabulary&domain=attributes&attribute_section=definitions');

        echo '<div class="seo-tags-panel"><h2>' . ($editing ? 'Editar atributo' : 'Alta de atributo') . '</h2>';
        echo '<p class="seo-tags-help">La definición controla el tipo de dato, filtros, visibilidad, SEO, multiplicidad y unidad base. El slug no cambia al editar.</p>';
        echo '<form method="post" class="seo-tags-manager-form" style="grid-template-columns:1fr 1.4fr 1fr 1fr 1fr auto">';
        wp_nonce_field('seo_semantic_attributes_admin', 'seo_semantic_attributes_nonce');
        echo '<input type="hidden" name="seo_semantic_attribute_action" value="save_definition">';
        echo '<input type="hidden" name="definition_id" value="' . esc_attr((int) ($editing['id'] ?? 0)) . '">';
        echo '<label>Slug<input name="definition_slug" type="text" value="' . esc_attr((string) ($editing['slug'] ?? '')) . '" ' . ($editing ? 'readonly' : '') . ' placeholder="ej. material"></label>';
        echo '<label>Nombre visible<input name="definition_name" type="text" required value="' . esc_attr((string) ($editing['nombre'] ?? '')) . '" placeholder="Ej. Material"></label>';
        echo '<label>Grupo<input name="definition_group" type="text" value="' . esc_attr((string) ($editing['grupo'] ?? 'general')) . '" placeholder="general"></label>';
        echo '<label>Tipo<select name="definition_type">';
        foreach (['texto'=>'Texto','numero'=>'Número','boolean'=>'Booleano','termino'=>'Término','rango'=>'Rango'] as $key=>$label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected((string) ($editing['tipo'] ?? 'texto'), $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Unidad base<input name="definition_unit_base" type="text" value="' . esc_attr((string) ($editing['unidad_base'] ?? '')) . '" placeholder="mm, W, kg..."></label>';
        echo '<label>Orden<input name="definition_order" type="number" value="' . esc_attr((int) ($editing['orden'] ?? 0)) . '" style="width:90px"></label>';
        echo '<div style="grid-column:1/-1;display:flex;gap:18px;flex-wrap:wrap;align-items:center">';
        echo '<label><input type="checkbox" name="definition_multiple" value="1" ' . checked(!empty($editing['multiple']), true, false) . '> Múltiple</label>';
        echo '<label><input type="checkbox" name="definition_filterable" value="1" ' . checked(!empty($editing['filtrable']), true, false) . '> Filtrable</label>';
        echo '<label><input type="checkbox" name="definition_visible" value="1" ' . checked(array_key_exists('visible',$editing) ? !empty($editing['visible']) : true, true, false) . '> Visible</label>';
        echo '<label><input type="checkbox" name="definition_seo" value="1" ' . checked(!empty($editing['seo']), true, false) . '> SEO</label>';
        echo '<label><input type="checkbox" name="definition_active" value="1" ' . checked(array_key_exists('activo',$editing) ? !empty($editing['activo']) : true, true, false) . '> Activo</label>';
        echo '<label>Tipo unidad <input name="definition_unit_type" type="text" value="' . esc_attr((string) ($editing['unidad_tipo'] ?? '')) . '" placeholder="longitud, potencia..."></label>';
        echo '<button class="button button-primary" type="submit">' . ($editing ? 'Guardar cambios' : 'Crear atributo') . '</button>';
        if ($editing) echo '<a class="button" href="' . esc_url($base) . '">Cancelar</a>';
        echo '</div></form></div>';

        echo '<div class="seo-tags-panel"><h2>Definiciones de atributos</h2>';
        echo '<table class="widefat striped seo-tags-table"><thead><tr><th>Atributo</th><th>Grupo</th><th>Tipo</th><th>Unidad</th><th>Productos</th><th>Asignaciones</th><th>Términos</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
        foreach ((array) $defs as $row) {
            echo '<tr><td><strong>' . esc_html((string) $row['nombre']) . '</strong><br><code>' . esc_html((string) $row['slug']) . '</code></td>';
            echo '<td>' . esc_html((string) ($row['grupo'] ?? '')) . '</td><td>' . esc_html((string) $row['tipo']) . '</td><td>' . esc_html((string) ($row['unidad_base'] ?? '')) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) $row['products'])) . '</td><td>' . esc_html(number_format_i18n((int) $row['assignments'])) . '</td><td>' . esc_html(number_format_i18n((int) $row['terms'])) . '</td>';
            echo '<td><span class="seo-tags-state ' . (!empty($row['activo']) ? 'active' : 'inactive') . '">' . (!empty($row['activo']) ? 'Activo' : 'Inactivo') . '</span></td>';
            echo '<td><a class="button button-small" href="' . esc_url(add_query_arg('edit_attribute', (int) $row['id'], $base)) . '">Editar</a> ';
            if ((int) $row['assignments'] === 0) {
                echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Se eliminará la definición y sus términos/aliases. ¿Continuar?\');">';
                wp_nonce_field('seo_semantic_attributes_admin', 'seo_semantic_attributes_nonce');
                echo '<input type="hidden" name="seo_semantic_attribute_action" value="delete_definition"><input type="hidden" name="definition_slug" value="' . esc_attr((string) $row['slug']) . '"><button class="button button-small seo-tags-danger">Eliminar</button></form>';
            }
            echo '</td></tr>';
        }
        if (!$defs) echo '<tr><td colspan="9">No hay definiciones.</td></tr>';
        echo '</tbody></table></div>';
    }
}

/** Gestión de términos y aliases de atributos controlados. */
if (!function_exists('seo_semantic_attributes_render_terms')) {
    function seo_semantic_attributes_render_terms() {
        global $wpdb;
        $tables = seo_attributes_tables();
        $definitions = $wpdb->get_results(
            "SELECT * FROM `{$tables['definitions']}` WHERE tipo='termino' ORDER BY activo DESC,orden ASC,nombre ASC",
            ARRAY_A
        );
        $attribute_id = absint($_GET['attribute_id'] ?? 0);
        if ($attribute_id < 1 && !empty($definitions)) $attribute_id = (int) $definitions[0]['id'];
        $valid_ids = array_map(static fn($r) => (int) $r['id'], (array) $definitions);
        if ($attribute_id > 0 && !in_array($attribute_id, $valid_ids, true)) $attribute_id = $valid_ids ? $valid_ids[0] : 0;
        $edit_term_id = absint($_GET['edit_term'] ?? 0);
        $editing = $edit_term_id > 0
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$tables['terms']}` WHERE id=%d AND atributo_id=%d LIMIT 1", $edit_term_id, $attribute_id), ARRAY_A)
            : null;
        $editing = is_array($editing) ? $editing : [];
        $base = admin_url('admin.php?page=seo-tags-vocabulary&domain=attributes&attribute_section=terms');

        echo '<div class="seo-tags-panel"><form method="get" class="seo-tags-filter"><input type="hidden" name="page" value="seo-tags-vocabulary"><input type="hidden" name="domain" value="attributes"><input type="hidden" name="attribute_section" value="terms">';
        echo '<label>Atributo<select name="attribute_id" onchange="this.form.submit()">';
        foreach ((array) $definitions as $def) {
            echo '<option value="' . esc_attr((int) $def['id']) . '" ' . selected($attribute_id, (int) $def['id'], false) . '>' . esc_html((string) $def['nombre']) . ' (' . esc_html((string) $def['slug']) . ')</option>';
        }
        echo '</select></label></form></div>';
        if ($attribute_id < 1) {
            echo '<div class="notice notice-info inline"><p>No hay atributos de tipo término. Créalo primero en la pestaña Atributos.</p></div>';
            return;
        }

        $definition = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$tables['definitions']}` WHERE id=%d", $attribute_id), ARRAY_A);
        $terms = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.*,
                        (SELECT COUNT(DISTINCT pa.product_id) FROM `{$tables['values']}` pa WHERE pa.termino_id=t.id) AS products,
                        (SELECT COUNT(*) FROM `{$tables['values']}` pa WHERE pa.termino_id=t.id) AS assignments
                 FROM `{$tables['terms']}` t
                 WHERE t.atributo_id=%d ORDER BY t.activo DESC,t.orden ASC,t.nombre ASC",
                $attribute_id
            ), ARRAY_A
        );
        $aliases = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT al.*,t.nombre AS term_name FROM `{$tables['aliases']}` al
                 LEFT JOIN `{$tables['terms']}` t ON t.id=al.termino_id
                 WHERE al.atributo_id=%d ORDER BY t.nombre ASC,al.alias ASC",
                $attribute_id
            ), ARRAY_A
        );
        $aliases_by_term = [];
        foreach ((array) $aliases as $alias) $aliases_by_term[(int) $alias['termino_id']][] = $alias;

        echo '<div class="seo-tags-panel"><h2>' . ($editing ? 'Editar término' : 'Alta de término') . ': ' . esc_html((string) ($definition['nombre'] ?? '')) . '</h2>';
        echo '<form method="post" class="seo-tags-manager-form">';
        wp_nonce_field('seo_semantic_attributes_admin', 'seo_semantic_attributes_nonce');
        echo '<input type="hidden" name="seo_semantic_attribute_action" value="save_term"><input type="hidden" name="attribute_id" value="' . esc_attr($attribute_id) . '"><input type="hidden" name="term_id" value="' . esc_attr((int) ($editing['id'] ?? 0)) . '">';
        echo '<label>Nombre<input type="text" name="term_name" required value="' . esc_attr((string) ($editing['nombre'] ?? '')) . '" placeholder="Ej. Acero inoxidable"></label>';
        echo '<label>Slug<input type="text" name="term_slug" value="' . esc_attr((string) ($editing['slug'] ?? '')) . '" placeholder="se genera si queda vacío"></label>';
        echo '<label>Orden<input type="number" name="term_order" value="' . esc_attr((int) ($editing['orden'] ?? 0)) . '"></label>';
        echo '<label><input type="checkbox" name="term_active" value="1" ' . checked(array_key_exists('activo',$editing) ? !empty($editing['activo']) : true, true, false) . '> Activo</label>';
        echo '<button class="button button-primary">' . ($editing ? 'Guardar término' : 'Añadir término') . '</button>';
        if ($editing) echo '<a class="button" href="' . esc_url(add_query_arg('attribute_id', $attribute_id, $base)) . '">Cancelar</a>';
        echo '</form></div>';

        echo '<div class="seo-tags-panel"><h2>Términos y aliases</h2>';
        echo '<table class="widefat striped seo-tags-table"><thead><tr><th>Término</th><th>Productos</th><th>Estado</th><th>Aliases</th><th>Acciones</th></tr></thead><tbody>';
        foreach ((array) $terms as $term) {
            $tid = (int) $term['id'];
            echo '<tr><td><strong>' . esc_html((string) $term['nombre']) . '</strong><br><code>' . esc_html((string) $term['slug']) . '</code></td>';
            echo '<td>' . esc_html(number_format_i18n((int) $term['products'])) . ' <small>(' . esc_html(number_format_i18n((int) $term['assignments'])) . ' asign.)</small></td>';
            echo '<td><span class="seo-tags-state ' . (!empty($term['activo']) ? 'active' : 'inactive') . '">' . (!empty($term['activo']) ? 'Activo' : 'Inactivo') . '</span></td><td>';
            if (!empty($aliases_by_term[$tid])) {
                echo '<div class="seo-tags-pills">';
                foreach ($aliases_by_term[$tid] as $alias) {
                    echo '<span class="seo-tags-pill">' . esc_html((string) $alias['alias']) . ' ';
                    echo '<form method="post" style="display:inline">';
                    wp_nonce_field('seo_semantic_attributes_admin', 'seo_semantic_attributes_nonce');
                    echo '<input type="hidden" name="seo_semantic_attribute_action" value="delete_alias"><input type="hidden" name="alias_id" value="' . esc_attr((int) $alias['id']) . '"><button type="submit" title="Eliminar alias" style="border:0;background:transparent;color:#b32d2e;cursor:pointer;padding:0">×</button></form></span>';
                }
                echo '</div>';
            } else echo '<span class="seo-tags-muted">Sin aliases</span>';
            echo '<form method="post" style="display:flex;gap:5px;margin-top:7px">';
            wp_nonce_field('seo_semantic_attributes_admin', 'seo_semantic_attributes_nonce');
            echo '<input type="hidden" name="seo_semantic_attribute_action" value="add_alias"><input type="hidden" name="attribute_id" value="' . esc_attr($attribute_id) . '"><input type="hidden" name="term_id" value="' . esc_attr($tid) . '"><input type="text" name="alias_value" placeholder="nuevo alias" style="min-width:140px"><button class="button button-small">Añadir</button></form>';
            echo '</td><td><a class="button button-small" href="' . esc_url(add_query_arg(['attribute_id'=>$attribute_id,'edit_term'=>$tid], $base)) . '">Editar</a> ';
            if ((int) $term['assignments'] === 0) {
                echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Se eliminará este término y sus aliases. ¿Continuar?\');">';
                wp_nonce_field('seo_semantic_attributes_admin', 'seo_semantic_attributes_nonce');
                echo '<input type="hidden" name="seo_semantic_attribute_action" value="delete_term"><input type="hidden" name="term_id" value="' . esc_attr($tid) . '"><button class="button button-small seo-tags-danger">Eliminar</button></form>';
            }
            echo '</td></tr>';
        }
        if (!$terms) echo '<tr><td colspan="5">Este atributo todavía no tiene términos.</td></tr>';
        echo '</tbody></table></div>';
    }
}

if (!function_exists('seo_tags_vocabulary_admin_page')) {
    function seo_tags_vocabulary_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta herramienta.', 'seo-taxonomy'));
        }

        $domain = sanitize_key($_GET['domain'] ?? 'labels');
        if (!in_array($domain, ['labels', 'attributes'], true)) {
            $domain = 'labels';
        }

        $section = sanitize_key($_GET['section'] ?? 'vocabulary');
        if (!in_array($section, ['products', 'vocabulary', 'dictionary', 'control'], true)) {
            $section = 'vocabulary';
        }
        $attribute_section = sanitize_key($_GET['attribute_section'] ?? 'definitions');
        if (!in_array($attribute_section, ['products', 'definitions', 'terms', 'control'], true)) {
            $attribute_section = 'definitions';
        }

        $notice = $domain === 'attributes' ? seo_semantic_attributes_handle_action() : null;
        $base = admin_url('admin.php?page=seo-tags-vocabulary');

        echo '<div class="wrap seo-tags-wrap">';
        echo '<h1>Semántica <span class="seo-tags-mode">Vocabularios canónicos</span></h1>';
        echo '<p class="seo-tags-intro">Unifica los diccionarios que describen el catálogo: etiquetas semánticas de clasificación y atributos técnicos de producto. Cada familia conserva su propio modelo y controles de integridad.</p>';
        seo_tags_vocab_render_styles();
        echo '<style>.seo-semantic-domain-tabs{margin:18px 0 10px;border-bottom:1px solid #c3c4c7}.seo-semantic-domain-tabs .nav-tab{font-size:15px;padding:8px 18px}.seo-semantic-subtabs{margin:8px 0 20px}.seo-semantic-domain-title{display:flex;align-items:center;gap:10px;margin:16px 0 4px}</style>';

        echo '<nav class="nav-tab-wrapper seo-semantic-domain-tabs">';
        echo '<a class="nav-tab ' . ($domain === 'labels' ? 'nav-tab-active' : '') . '" href="' . esc_url($base . '&domain=labels&section=vocabulary') . '">Etiquetas</a>';
        echo '<a class="nav-tab ' . ($domain === 'attributes' ? 'nav-tab-active' : '') . '" href="' . esc_url($base . '&domain=attributes&attribute_section=definitions') . '">Atributos</a>';
        echo '</nav>';

        if ($domain === 'labels') {
            $summary = seo_tags_vocab_get_summary();
            echo '<div class="seo-semantic-domain-title"><h2 style="margin:0">Etiquetas semánticas</h2><span class="seo-tags-mode">Clasificación</span></div>';
            echo '<p class="seo-tags-intro">TIPO y Ámbito/ROL forman la identidad canónica del producto; APLICACIÓN, PLATAFORMA y SUBTIPO completan su clasificación semántica.</p>';
            seo_tags_vocab_render_summary_cards($summary);
            $labels_base = $base . '&domain=labels';
            echo '<nav class="nav-tab-wrapper seo-semantic-subtabs">';
            echo '<a class="nav-tab ' . ($section === 'products' ? 'nav-tab-active' : '') . '" href="' . esc_url($labels_base . '&section=products') . '">Productos</a>';
            echo '<a class="nav-tab ' . ($section === 'vocabulary' ? 'nav-tab-active' : '') . '" href="' . esc_url($labels_base . '&section=vocabulary') . '">Gestionar etiquetas</a>';
            echo '<a class="nav-tab ' . ($section === 'dictionary' ? 'nav-tab-active' : '') . '" href="' . esc_url($labels_base . '&section=dictionary') . '">Diccionario</a>';
            echo '<a class="nav-tab ' . ($section === 'control' ? 'nav-tab-active' : '') . '" href="' . esc_url($labels_base . '&section=control') . '">Control</a>';
            echo '</nav>';

            if ($section === 'vocabulary') seo_tags_vocab_render_vocabulary();
            elseif ($section === 'dictionary') seo_tags_vocab_render_dictionary();
            elseif ($section === 'control') seo_tags_vocab_render_control($summary);
            else seo_tags_vocab_render_products();
        } else {
            echo '<div class="seo-semantic-domain-title"><h2 style="margin:0">Atributos técnicos</h2><span class="seo-tags-mode">Productos</span></div>';
            echo '<p class="seo-tags-intro">Gestiona definiciones, términos controlados, aliases y asignaciones del nuevo modelo <code>wp_sql_*</code>. No se escribe en <code>wp_seo_attributes</code>.</p>';
            seo_semantic_attributes_notice($notice);
            seo_semantic_attributes_render_summary_cards(seo_semantic_attributes_get_summary());

            $attr_base = $base . '&domain=attributes';
            echo '<nav class="nav-tab-wrapper seo-semantic-subtabs">';
            echo '<a class="nav-tab ' . ($attribute_section === 'products' ? 'nav-tab-active' : '') . '" href="' . esc_url($attr_base . '&attribute_section=products') . '">Productos</a>';
            echo '<a class="nav-tab ' . ($attribute_section === 'definitions' ? 'nav-tab-active' : '') . '" href="' . esc_url($attr_base . '&attribute_section=definitions') . '">Atributos</a>';
            echo '<a class="nav-tab ' . ($attribute_section === 'terms' ? 'nav-tab-active' : '') . '" href="' . esc_url($attr_base . '&attribute_section=terms') . '">Términos y aliases</a>';
            echo '<a class="nav-tab ' . ($attribute_section === 'control' ? 'nav-tab-active' : '') . '" href="' . esc_url($attr_base . '&attribute_section=control') . '">Control</a>';
            echo '</nav>';

            if (!function_exists('seo_attributes_tables')) {
                echo '<div class="notice notice-error inline"><p>El módulo de atributos canónicos no está cargado.</p></div>';
            } elseif ($attribute_section === 'definitions') {
                seo_semantic_attributes_render_definitions();
            } elseif ($attribute_section === 'terms') {
                seo_semantic_attributes_render_terms();
            } elseif ($attribute_section === 'control') {
                search_product_attributes([
                    'render_dashboard' => true,
                    'render_definition_controls' => false,
                    'render_explorer' => false,
                ]);
            } else {
                search_product_attributes([
                    'render_dashboard' => false,
                    'render_definition_controls' => false,
                ]);
            }
        }

        echo '</div>';
    }
}


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
            .seo-tags-state.current{background:#edfaef;color:#176b2c;border:1px solid #b8dfc1}.seo-tags-state.safe{background:#e8f2ff;color:#135e96;border:1px solid #b7d7f5}.seo-tags-state.review{background:#fff7d6;color:#7a4f01;border:1px solid #e9d48a}.seo-tags-state.new{background:#f3e8ff;color:#6b21a8;border:1px solid #d8b4fe}.seo-tags-state.pending{background:#f6f7f7;color:#50575e;border:1px dashed #a7aaad}.seo-tags-state.unresolved,.seo-tags-state.empty{background:#f0f0f1;color:#646970;border:1px solid #dcdcde}
            .seo-tags-new-box{margin-top:6px;padding:7px;border:1px solid #d8b4fe;background:#faf5ff;border-radius:4px}.seo-tags-new-box input[type=text]{width:100%;font-size:11px}.seo-tags-new-button{border-color:#7e22ce!important;color:#6b21a8!important}.seo-tags-new-button:hover{border-color:#6b21a8!important;color:#581c87!important}
            .seo-classifier-job{border:1px solid #c3c4c7;border-left:4px solid #2271b1;background:#fff;padding:12px 14px;margin:12px 0}.seo-classifier-job-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;margin-top:10px}.seo-classifier-job-kpi{background:#f6f7f7;border-radius:5px;padding:8px 10px}.seo-classifier-job-kpi strong{display:block;font-size:18px}.seo-classifier-progress{height:12px;background:#e2e4e7;border-radius:999px;overflow:hidden;margin:10px 0 4px}.seo-classifier-progress>span{display:block;height:100%;background:#2271b1;width:0;transition:width .3s ease}.seo-classifier-job-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
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
            'classified_products' => 0,
            'unclassified_products' => 0,
            'wc_tagged_products' => 0,
            'wc_untagged_products' => 0,
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

            $summary['classified_products'] = (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT ov.object_id)
                 FROM {$objects} ov
                 INNER JOIN {$vocabulary} v
                   ON v.id = ov.vocabulary_id
                  AND v.active = 1
                  AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                 INNER JOIN {$wpdb->posts} p
                   ON p.ID = ov.object_id
                  AND p.post_type = 'product'
                  AND p.post_status = 'publish'
                 WHERE ov.object_type = 'product'
                   AND ov.status = 1"
            );
            $summary['unclassified_products'] = max(0, (int) $summary['products'] - (int) $summary['classified_products']);
        }

        $summary['wc_tagged_products'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT tr.object_id)
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt
               ON tt.term_taxonomy_id = tr.term_taxonomy_id
              AND tt.taxonomy = 'product_tag'
             INNER JOIN {$wpdb->posts} p
               ON p.ID = tr.object_id
              AND p.post_type = 'product'
              AND p.post_status = 'publish'"
        );
        $summary['wc_untagged_products'] = max(0, (int) $summary['products'] - (int) $summary['wc_tagged_products']);

        return $summary;
    }
}

if (!function_exists('seo_tags_vocab_render_summary_cards')) {
    function seo_tags_vocab_render_summary_cards(array $summary) {
        $cards = [
            ['Productos', $summary['products'], 'Catálogo publicado'],
            ['Sin etiquetas semánticas', (int) ($summary['unclassified_products'] ?? 0), 'sin TIPO/ROL/APLICACIÓN/PLATAFORMA/SUBTIPO'],
            ['Sin etiquetas WooCommerce', (int) ($summary['wc_untagged_products'] ?? 0), 'sin términos product_tag'],
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
        $allowed_coverage = [
            'all', 'with_any', 'without_any', 'with_wc_tags', 'without_wc_tags',
            'with_role', 'without_role', 'with_type', 'without_type',
            'with_application', 'without_application',
            'with_platform', 'without_platform',
            'with_subtype', 'without_subtype',
        ];
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
        $coverage_any = null;
        $coverage_wc_tags = null;
        if ($coverage === 'with_wc_tags' || $coverage === 'without_wc_tags') {
            $coverage_wc_tags = $coverage === 'with_wc_tags';
        } elseif ($coverage === 'with_any' || $coverage === 'without_any') {
            $coverage_any = $coverage === 'with_any';
        } elseif ($coverage === 'with_role' || $coverage === 'without_role') {
            $coverage_group = 'rol';
            $coverage_positive = $coverage === 'with_role';
        } elseif ($coverage === 'with_type' || $coverage === 'without_type') {
            $coverage_group = 'tipo';
            $coverage_positive = $coverage === 'with_type';
        } elseif ($coverage === 'with_application' || $coverage === 'without_application') {
            $coverage_group = 'aplicacion';
            $coverage_positive = $coverage === 'with_application';
        } elseif ($coverage === 'with_platform' || $coverage === 'without_platform') {
            $coverage_group = 'plataforma';
            $coverage_positive = $coverage === 'with_platform';
        } elseif ($coverage === 'with_subtype' || $coverage === 'without_subtype') {
            $coverage_group = 'subtipo';
            $coverage_positive = $coverage === 'with_subtype';
        }

        if ($coverage_wc_tags !== null) {
            $where[] = ($coverage_wc_tags ? '' : 'NOT ') . "EXISTS (
                SELECT 1 FROM {$wpdb->term_relationships} tr_tag
                JOIN {$wpdb->term_taxonomy} tt_tag
                  ON tt_tag.term_taxonomy_id = tr_tag.term_taxonomy_id
                 AND tt_tag.taxonomy = 'product_tag'
                WHERE tr_tag.object_id = p.ID
            )";
        } elseif ($coverage_any !== null) {
            $where[] = ($coverage_any ? '' : 'NOT ') . "EXISTS (
                SELECT 1 FROM {$objects} ovc
                JOIN {$vocabulary} vc ON vc.id = ovc.vocabulary_id
                WHERE ovc.object_type = 'product'
                  AND ovc.object_id = p.ID
                  AND ovc.status = 1
                  AND vc.active = 1
                  AND vc.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
            )";
        } elseif ($coverage_group !== '') {
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
        $wc_tags_map = [];
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

            $tag_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT tr.object_id AS product_id,t.name
                     FROM {$wpdb->term_relationships} tr
                     JOIN {$wpdb->term_taxonomy} tt
                       ON tt.term_taxonomy_id = tr.term_taxonomy_id
                      AND tt.taxonomy = 'product_tag'
                     JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                     WHERE tr.object_id IN ({$ph})
                     ORDER BY tr.object_id ASC,t.name ASC",
                    ...$product_ids
                ),
                ARRAY_A
            );
            foreach ((array) $tag_rows as $row) {
                $wc_tags_map[(int) $row['product_id']][] = (string) ($row['name'] ?? '');
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
        $base_url = admin_url('admin.php?page=seo-tags-vocabulary&domain=labels&section=products');

        echo '<div class="seo-tags-panel">';
        echo '<p class="seo-tags-help" style="margin-top:0"><strong>Alineación con categoría:</strong> se calcula para cada producto usando sus categorías reales y el vocabulary canónico de sus compañeros. Si seleccionas una categoría, los productos se ordenan de más atípicos a más alineados. No usa etiquetas legacy, no cuenta palabras y no mueve productos automáticamente.</p>';
        echo '<form method="get" class="seo-tags-filter">';
        echo '<input type="hidden" name="page" value="seo-tags-vocabulary">';
        echo '<input type="hidden" name="domain" value="labels">';
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
            'without_any' => 'Sin ninguna etiqueta semántica',
            'with_any' => 'Con alguna etiqueta semántica',
            'without_wc_tags' => 'Sin etiquetas WooCommerce',
            'with_wc_tags' => 'Con etiquetas WooCommerce',
            'without_type' => 'Sin TIPO',
            'with_type' => 'Con TIPO',
            'without_role' => 'Sin Ámbito / ROL',
            'with_role' => 'Con Ámbito / ROL',
            'without_application' => 'Sin aplicación',
            'with_application' => 'Con aplicación',
            'without_platform' => 'Sin plataforma',
            'with_platform' => 'Con plataforma',
            'without_subtype' => 'Sin subtipo',
            'with_subtype' => 'Con subtipo',
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
        echo '<thead><tr><th>Producto</th><th>Etiquetas WooCommerce</th><th>Ámbito / ROL</th><th>TIPO</th><th>APLICACIÓN</th><th>PLATAFORMA</th><th>SUBTIPO</th><th>Alineación</th><th>Acción</th></tr></thead><tbody>';

        if (!$products) {
            echo '<tr><td colspan="9">No hay productos que coincidan con los filtros.</td></tr>';
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

            echo '<td><div class="seo-tags-pills">';
            if (!empty($wc_tags_map[$product_id])) {
                foreach (array_slice(array_values(array_unique(array_filter($wc_tags_map[$product_id]))), 0, 8) as $wc_tag) {
                    echo '<span class="seo-tags-pill">' . esc_html($wc_tag) . '</span>';
                }
                if (count(array_unique(array_filter($wc_tags_map[$product_id]))) > 8) {
                    echo '<span class="seo-tags-muted">+' . esc_html(count(array_unique(array_filter($wc_tags_map[$product_id]))) - 8) . ' más</span>';
                }
            } else {
                echo '<span class="seo-tags-muted">Sin etiquetas</span>';
            }
            echo '</div></td>';

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
            "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type='product' AND post_status='publish'"
        );
        $products = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT pa.product_id) FROM `{$tables['values']}` pa
             INNER JOIN `{$wpdb->posts}` p ON p.ID=pa.product_id
             WHERE p.post_type='product' AND p.post_status='publish'"
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
            ['Sin atributos', max(0, $total - $with), 'productos sin asignaciones canónicas'],
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


/**
 * Inventario filtrable de cobertura de atributos canónicos por producto.
 * No propone ni modifica valores: solo permite localizar huecos antes de la
 * futura fase de clasificación asistida.
 */
if (!function_exists('seo_semantic_attributes_render_products_inventory')) {
    function seo_semantic_attributes_render_products_inventory() {
        global $wpdb;

        if (!function_exists('seo_attributes_tables')) {
            echo '<div class="notice notice-error inline"><p>El módulo de atributos canónicos no está cargado.</p></div>';
            return;
        }

        $tables = seo_attributes_tables();
        foreach (['definitions', 'terms', 'values'] as $required_key) {
            if (empty($tables[$required_key]) || !seo_tags_vocab_table_exists($tables[$required_key])) {
                echo '<div class="notice notice-error inline"><p>No está disponible la tabla canónica de atributos requerida: <code>' . esc_html((string) ($tables[$required_key] ?? $required_key)) . '</code>.</p></div>';
                return;
            }
        }

        $search = sanitize_text_field(wp_unslash($_GET['attr_s'] ?? ''));
        $category_id = absint($_GET['attr_category_id'] ?? 0);
        $coverage = sanitize_key($_GET['attr_coverage'] ?? 'without');
        if (!in_array($coverage, ['all', 'with', 'without'], true)) {
            $coverage = 'without';
        }
        $per_page = absint($_GET['attr_per_page'] ?? 50);
        if (!in_array($per_page, [25, 50, 100, 200], true)) {
            $per_page = 50;
        }
        $page_number = max(1, absint($_GET['attr_paged'] ?? 1));

        $published_total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type='product' AND p.post_status='publish'"
        );
        $with_attributes = (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             WHERE p.post_type='product' AND p.post_status='publish'
               AND EXISTS (SELECT 1 FROM `{$tables['values']}` pa WHERE pa.product_id=p.ID)"
        );
        $without_attributes = max(0, $published_total - $with_attributes);

        echo '<div class="seo-tags-panel">';
        echo '<h2 style="margin-top:0">Inventario de cobertura de atributos</h2>';
        echo '<p class="seo-tags-help">Esta vista no crea ni modifica atributos. Sirve para localizar productos que todavía no tienen datos técnicos canónicos y preparar su revisión posterior.</p>';
        echo '<div class="seo-tags-cards" style="margin-bottom:14px">';
        $inventory_cards = [
            ['Publicados', $published_total, 'productos activos'],
            ['Con atributos', $with_attributes, $published_total > 0 ? round(($with_attributes / $published_total) * 100, 1) . '% de cobertura' : '0% de cobertura'],
            ['Sin atributos', $without_attributes, 'pendientes de enriquecer'],
        ];
        foreach ($inventory_cards as $card) {
            echo '<div class="seo-tags-card"><div class="label">' . esc_html($card[0]) . '</div><div class="value">' . esc_html(number_format_i18n((int) $card[1])) . '</div><div class="meta">' . esc_html((string) $card[2]) . '</div></div>';
        }
        echo '</div>';

        $category_options = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
        if (is_wp_error($category_options)) {
            $category_options = [];
        }

        echo '<form method="get" class="seo-tags-filter">';
        echo '<input type="hidden" name="page" value="seo-tags-vocabulary">';
        echo '<input type="hidden" name="domain" value="attributes">';
        echo '<input type="hidden" name="attribute_section" value="products">';
        echo '<label>Producto / SKU / ID<input class="search-wide" type="text" name="attr_s" value="' . esc_attr($search) . '" placeholder="Buscar producto, SKU o ID"></label>';
        echo '<label>Categoría<select name="attr_category_id"><option value="0">Todas</option>';
        foreach ((array) $category_options as $category_option) {
            echo '<option value="' . esc_attr((int) $category_option->term_id) . '" ' . selected($category_id, (int) $category_option->term_id, false) . '>' . esc_html($category_option->name) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Cobertura<select name="attr_coverage">';
        foreach (['without' => 'Sin atributos', 'with' => 'Con atributos', 'all' => 'Todos'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($coverage, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Filas<select name="attr_per_page">';
        foreach ([25, 50, 100, 200] as $n) {
            echo '<option value="' . esc_attr($n) . '" ' . selected($per_page, $n, false) . '>' . esc_html($n) . '</option>';
        }
        echo '</select></label>';
        $clear_url = admin_url('admin.php?page=seo-tags-vocabulary&domain=attributes&attribute_section=products');
        echo '<div><button class="button button-primary" type="submit">Filtrar</button> <a class="button" href="' . esc_url($clear_url) . '">Limpiar</a></div>';
        echo '</form></div>';

        $where = ["p.post_type='product'", "p.post_status='publish'"];
        $args = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $parts = ['p.post_title LIKE %s'];
            $local_args = [$like];
            if (ctype_digit($search)) {
                $parts[] = 'p.ID=%d';
                $local_args[] = (int) $search;
            }
            $parts[] = "EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm
                WHERE pm.post_id=p.ID AND pm.meta_key='_sku' AND pm.meta_value LIKE %s
            )";
            $local_args[] = $like;
            $where[] = '(' . implode(' OR ', $parts) . ')';
            $args = array_merge($args, $local_args);
        }
        if ($category_id > 0) {
            $where[] = "EXISTS (
                SELECT 1 FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
                WHERE tr.object_id=p.ID AND tt.term_id=%d
            )";
            $args[] = $category_id;
        }
        if ($coverage === 'with') {
            $where[] = "EXISTS (SELECT 1 FROM `{$tables['values']}` pa_cov WHERE pa_cov.product_id=p.ID)";
        } elseif ($coverage === 'without') {
            $where[] = "NOT EXISTS (SELECT 1 FROM `{$tables['values']}` pa_cov WHERE pa_cov.product_id=p.ID)";
        }

        $where_sql = implode(' AND ', $where);
        $count_sql = seo_tags_vocab_prepare_sql("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where_sql}", $args);
        $total = (int) $wpdb->get_var($count_sql);
        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($page_number > $total_pages) {
            $page_number = $total_pages;
        }
        $offset = ($page_number - 1) * $per_page;
        $query_args = $args;
        $query_args[] = $per_page;
        $query_args[] = $offset;
        $products_sql = seo_tags_vocab_prepare_sql(
            "SELECT p.ID,p.post_title,p.post_modified,
                    (SELECT MAX(pm.meta_value) FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_sku') AS sku,
                    (SELECT COUNT(*) FROM `{$tables['values']}` pa_count WHERE pa_count.product_id=p.ID) AS attribute_count
             FROM {$wpdb->posts} p
             WHERE {$where_sql}
             ORDER BY p.post_modified DESC,p.ID DESC
             LIMIT %d OFFSET %d",
            $query_args
        );
        $products = (array) $wpdb->get_results($products_sql, ARRAY_A);
        $product_ids = array_map('intval', array_column($products, 'ID'));

        $category_map = [];
        $attribute_map = [];
        if ($product_ids) {
            $ph = seo_tags_vocab_placeholders($product_ids, '%d');
            $category_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT tr.object_id AS product_id,t.name
                     FROM {$wpdb->term_relationships} tr
                     JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
                     JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
                     WHERE tr.object_id IN ({$ph}) ORDER BY tr.object_id,t.name",
                    ...$product_ids
                ),
                ARRAY_A
            );
            foreach ((array) $category_rows as $row) {
                $category_map[(int) $row['product_id']][] = (string) $row['name'];
            }

            $attribute_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pa.product_id,a.nombre AS attribute_name,a.slug AS attribute_slug,
                            t.nombre AS term_name,pa.valor_texto,pa.valor_numero,pa.valor_numero_max,pa.unidad,pa.valor_original
                     FROM `{$tables['values']}` pa
                     JOIN `{$tables['definitions']}` a ON a.id=pa.atributo_id
                     LEFT JOIN `{$tables['terms']}` t ON t.id=pa.termino_id
                     WHERE pa.product_id IN ({$ph})
                     ORDER BY pa.product_id,a.orden,a.nombre,pa.orden,pa.id",
                    ...$product_ids
                ),
                ARRAY_A
            );
            foreach ((array) $attribute_rows as $row) {
                $pid = (int) $row['product_id'];
                if (count($attribute_map[$pid] ?? []) >= 8) {
                    continue;
                }
                $value = trim((string) ($row['term_name'] ?? ''));
                if ($value === '' && $row['valor_numero'] !== null && $row['valor_numero'] !== '') {
                    $value = (string) $row['valor_numero'];
                    if ($row['valor_numero_max'] !== null && $row['valor_numero_max'] !== '') {
                        $value .= '–' . (string) $row['valor_numero_max'];
                    }
                    if (!empty($row['unidad'])) {
                        $value .= ' ' . (string) $row['unidad'];
                    }
                }
                if ($value === '') {
                    $value = trim((string) ($row['valor_texto'] ?? $row['valor_original'] ?? ''));
                }
                $attribute_map[$pid][] = trim((string) ($row['attribute_name'] ?? $row['attribute_slug'])) . ($value !== '' ? ': ' . $value : '');
            }
        }

        echo '<div class="seo-tags-count">Mostrando ' . esc_html(number_format_i18n(count($products))) . ' de ' . esc_html(number_format_i18n($total)) . ' productos.</div>';
        echo '<div style="overflow:auto"><table class="widefat striped seo-tags-table">';
        echo '<thead><tr><th>Producto</th><th>Categorías</th><th>Atributos canónicos</th><th>Cobertura</th><th>Modificado</th><th>Acción</th></tr></thead><tbody>';
        if (!$products) {
            echo '<tr><td colspan="6">No hay productos que coincidan con los filtros.</td></tr>';
        }
        foreach ($products as $product) {
            $pid = (int) $product['ID'];
            $count = (int) ($product['attribute_count'] ?? 0);
            echo '<tr><td class="seo-tags-product"><strong>#' . esc_html($pid) . ' · ' . esc_html((string) $product['post_title']) . '</strong>';
            if (!empty($product['sku'])) {
                echo '<br><span class="seo-tags-vocab-source">SKU: ' . esc_html((string) $product['sku']) . '</span>';
            }
            echo '</td><td>';
            if (!empty($category_map[$pid])) {
                echo esc_html(implode(' · ', array_unique($category_map[$pid])));
            } else {
                echo '<span class="seo-tags-muted">Sin categoría</span>';
            }
            echo '</td><td><div class="seo-tags-pills">';
            if (!empty($attribute_map[$pid])) {
                foreach ($attribute_map[$pid] as $attribute_label) {
                    echo '<span class="seo-tags-pill">' . esc_html($attribute_label) . '</span>';
                }
                if ($count > count($attribute_map[$pid])) {
                    echo '<span class="seo-tags-muted">+' . esc_html($count - count($attribute_map[$pid])) . ' más</span>';
                }
            } else {
                echo '<span class="seo-tags-muted">Sin atributos guardados</span>';
            }
            echo '</div></td><td>';
            if ($count > 0) {
                echo '<span class="seo-tags-state active">' . esc_html(number_format_i18n($count)) . ' asignaciones</span>';
            } else {
                echo '<span class="seo-tags-state inactive" style="color:#b32d2e">Sin atributos</span>';
            }
            echo '</td><td>' . esc_html(mysql2date('d/m/Y H:i', (string) $product['post_modified'])) . '</td><td>';
            $edit_url = get_edit_post_link($pid, '');
            if ($edit_url) {
                echo '<a class="button button-small" href="' . esc_url($edit_url) . '">Editar producto</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';

        if ($total_pages > 1) {
            $base_args = [
                'page' => 'seo-tags-vocabulary',
                'domain' => 'attributes',
                'attribute_section' => 'products',
                'attr_s' => $search,
                'attr_category_id' => $category_id,
                'attr_coverage' => $coverage,
                'attr_per_page' => $per_page,
            ];
            echo '<div class="seo-tags-pagination">';
            $window_start = max(1, $page_number - 3);
            $window_end = min($total_pages, $page_number + 3);
            if ($page_number > 1) {
                echo '<a href="' . esc_url(add_query_arg(array_merge($base_args, ['attr_paged' => $page_number - 1]), admin_url('admin.php'))) . '">‹</a>';
            }
            for ($i = $window_start; $i <= $window_end; $i++) {
                if ($i === $page_number) {
                    echo '<span class="current">' . esc_html($i) . '</span>';
                } else {
                    echo '<a href="' . esc_url(add_query_arg(array_merge($base_args, ['attr_paged' => $i]), admin_url('admin.php'))) . '">' . esc_html($i) . '</a>';
                }
            }
            if ($page_number < $total_pages) {
                echo '<a href="' . esc_url(add_query_arg(array_merge($base_args, ['attr_paged' => $page_number + 1]), admin_url('admin.php'))) . '">›</a>';
            }
            echo '<span>Página ' . esc_html($page_number) . ' / ' . esc_html($total_pages) . '</span></div>';
        }
    }
}


/**
 * ============================================================================
 * ASIGNACIÓN ASISTIDA
 * Mesa operativa separada de los maestros de Etiquetas y Atributos.
 * Las propuestas reutilizan exclusivamente vocabulario canónico activo. La inteligencia
 * reside en includes/clasificador; esta pantalla solo inventaría, revisa y confirma.
 * ============================================================================
 */
if (!function_exists('seo_assignment_sections')) {
    function seo_assignment_sections() {
        return [
            'product_labels'     => 'Etiquetas de productos',
            'product_attributes' => 'Atributos de productos',
            'category_labels'    => 'Etiquetas de categorías',
            'format_examples'    => 'Formato / ejemplos',
        ];
    }
}

if (!function_exists('seo_assignment_allowed_groups')) {
    function seo_assignment_allowed_groups() {
        return ['rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo'];
    }
}

if (!function_exists('seo_assignment_normalize')) {
    function seo_assignment_normalize($text) {
        $text = html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');
        $text = wp_strip_all_tags($text);
        $text = mb_strtolower(remove_accents($text), 'UTF-8');
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }
}

if (!function_exists('seo_assignment_tokens')) {
    function seo_assignment_tokens($text) {
        $stop = ['de','del','la','las','el','los','para','por','con','sin','y','o','en','un','una','unos','unas','a'];
        $tokens = preg_split('/\s+/u', seo_assignment_normalize($text), -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ((array) $tokens as $token) {
            if (mb_strlen($token, 'UTF-8') < 3 || in_array($token, $stop, true)) continue;
            $out[$token] = true;
        }
        return array_keys($out);
    }
}

if (!function_exists('seo_assignment_vocab_index')) {
    /** Compatibilidad de UI: el vocabulario lo suministra Clasificador. */
    function seo_assignment_vocab_index() {
        return function_exists('seo_classifier_vocabulary_index') ? seo_classifier_vocabulary_index() : [];
    }
}

if (!function_exists('seo_assignment_semantic_current')) {
    /** Compatibilidad de UI: lectura canónica delegada al Clasificador. */
    function seo_assignment_semantic_current($object_type, $object_id) {
        if (!function_exists('seo_classifier_current_object_labels')) {
            return array_fill_keys(seo_assignment_allowed_groups(), []);
        }
        return seo_classifier_current_object_labels($object_type, $object_id);
    }
}

if (!function_exists('seo_assignment_semantic_label_map')) {
    function seo_assignment_semantic_label_map(array $rows) {
        if (function_exists('seo_classifier_label_map')) return seo_classifier_label_map($rows);
        $out = [];
        foreach (seo_assignment_allowed_groups() as $group) {
            $labels = [];
            foreach ((array) ($rows[$group] ?? []) as $row) {
                $label = is_array($row) ? trim((string) ($row['label'] ?? '')) : trim((string) $row);
                if ($label !== '') $labels[] = $label;
            }
            if ($labels) $out[$group] = array_values(array_unique($labels));
        }
        return $out;
    }
}

if (!function_exists('seo_assignment_product_text')) {
    /** Adapter histórico para consumidores de la pantalla. */
    function seo_assignment_product_text($product_id) {
        if (!function_exists('seo_classifier_build_product_context')) return ['title'=>'','full'=>'','categories'=>'','tags'=>''];
        $context = seo_classifier_build_product_context($product_id);
        return [
            'title'=>(string)($context['title'] ?? ''),
            'full'=>(string)($context['full'] ?? ''),
            'categories'=>(string)($context['categories'] ?? ''),
            'tags'=>(string)($context['tags'] ?? ''),
            'identity'=>(string)($context['identity'] ?? ''),
            'raw_title'=>(string)($context['raw_title'] ?? ''),
        ];
    }
}

if (!function_exists('seo_assignment_term_score')) {
    /** Wrapper usado todavía por la propuesta de categorías. */
    function seo_assignment_term_score(array $term, $title, $full) {
        if (!function_exists('seo_classifier_label_metric')) return 0.0;
        $group = sanitize_key((string) ($term['semantic_group'] ?? 'tipo')) ?: 'tipo';
        if (empty($term['concepts']) && function_exists('seo_classifier_concept_sequence')) {
            $term['concepts'] = seo_classifier_concept_sequence((string) ($term['label'] ?? ''));
        }
        $metric = seo_classifier_label_metric($term, [
            'title'=>(string)$title,
            'identity'=>(string)$title,
            'full'=>(string)$full,
            'categories'=>'',
            'tags'=>'',
        ], $group);
        return (float) ($metric['score'] ?? 0.0);
    }
}

if (!function_exists('seo_assignment_role_from_type')) {
    function seo_assignment_role_from_type($type_id) {
        return function_exists('seo_classifier_role_from_type') ? seo_classifier_role_from_type($type_id) : null;
    }
}

if (!function_exists('seo_assignment_propose_product_labels')) {
    /**
     * Build 045: Asignación nunca ejecuta el Clasificador durante el render.
     * Solo consume propuestas persistidas por los jobs en segundo plano.
     */
    function seo_assignment_propose_product_labels($product_id, array $current = [], array $prefetched = []) {
        if (function_exists('seo_classifier_cached_product_label_proposal')) {
            return seo_classifier_cached_product_label_proposal($product_id, $current, $prefetched);
        }
        return [
            'values'=>[], 'review'=>[], 'new_terms'=>[], 'confidence'=>[], 'evidence'=>[],
            'pending_groups'=>['tipo','rol','aplicacion','plataforma','subtipo'],
            'unresolved_groups'=>[], 'engine'=>'jobs_unavailable', 'viable'=>false,
        ];
    }
}

if (!function_exists('seo_assignment_category_proposal')) {
    function seo_assignment_category_proposal($category_id, array $current = []) {
        $category_id = absint($category_id);
        $term = get_term($category_id, 'product_cat');
        if ($category_id < 1 || !$term || is_wp_error($term)) return ['values'=>[],'confidence'=>[],'viable'=>false];
        $index = seo_assignment_vocab_index();
        $title = seo_assignment_normalize((string) $term->name);
        $full = seo_assignment_normalize((string) $term->name . ' ' . (string) $term->description);
        $proposal = [];
        $confidence = [];
        $proposed_ids = [];
        $profiles = function_exists('seo_tags_vocab_get_category_profiles') ? seo_tags_vocab_get_category_profiles([$category_id]) : [];
        $profile = $profiles[$category_id] ?? [];
        foreach (['rol','tipo','aplicacion','plataforma','subtipo'] as $group) {
            if (!empty($current[$group])) continue;
            $scores = [];
            foreach ((array) ($index[$group] ?? []) as $vrow) {
                $score = seo_assignment_term_score($vrow, $title, $full);
                if ($score > 0) $scores[] = ['score'=>$score,'term'=>$vrow];
            }
            usort($scores, static function($a,$b){ return $a['score'] === $b['score'] ? 0 : ($a['score'] > $b['score'] ? -1 : 1); });
            $selected = [];
            if ($scores && $scores[0]['score'] >= 0.89) {
                if (in_array($group, ['rol','tipo'], true)) $selected = [$scores[0]];
                else {
                    foreach ($scores as $candidate) {
                        if ($candidate['score'] < 0.89) break;
                        $selected[] = $candidate;
                        if (count($selected) >= 3) break;
                    }
                }
            }
            if (!$selected && !empty($profile['groups'][$group]['term_share'])) {
                $shares = (array) $profile['groups'][$group]['term_share'];
                arsort($shares, SORT_NUMERIC);
                foreach ($shares as $vid => $share) {
                    if ((float) $share < 0.65) break;
                    foreach ((array) ($index[$group] ?? []) as $vrow) {
                        if ((int) $vrow['id'] === (int) $vid) {
                            $selected[] = ['score' => min(0.93, 0.70 + ((float) $share * 0.20)), 'term' => $vrow];
                            break;
                        }
                    }
                    if (in_array($group, ['rol','tipo'], true) || count($selected) >= 3) break;
                }
            }
            if ($selected) {
                $proposal[$group] = array_values(array_unique(array_map(static fn($r) => (string) $r['term']['label'], $selected)));
                $confidence[$group] = round((float) $selected[0]['score'], 2);
                $proposed_ids[$group] = array_map(static fn($r) => (int) $r['term']['id'], $selected);
            }
        }
        if (empty($current['rol']) && empty($proposal['rol'])) {
            $type_id = 0;
            if (!empty($current['tipo'][0]['id'])) $type_id = (int) $current['tipo'][0]['id'];
            elseif (!empty($proposed_ids['tipo'][0])) $type_id = (int) $proposed_ids['tipo'][0];
            $role = seo_assignment_role_from_type($type_id);
            if ($role && trim((string) ($role['label'] ?? '')) !== '') {
                $proposal['rol'] = [(string) $role['label']];
                $confidence['rol'] = 1.0;
            }
        }
        return ['values'=>$proposal,'confidence'=>$confidence,'viable'=>!empty($proposal)];
    }
}

if (!function_exists('seo_assignment_merge_semantic')) {
    function seo_assignment_merge_semantic(array $current, array $proposal) {
        $base = seo_assignment_semantic_label_map($current);
        foreach ((array) $proposal as $group => $values) {
            $existing = (array) ($base[$group] ?? []);
            $base[$group] = array_values(array_unique(array_filter(array_merge($existing, (array) $values), 'strlen')));
        }
        return $base;
    }
}

if (!function_exists('seo_assignment_attribute_current')) {
    function seo_assignment_attribute_current($product_id) {
        $out = [];
        if (!function_exists('seo_attributes_get_product_rows')) return $out;
        foreach ((array) seo_attributes_get_product_rows(absint($product_id)) as $row) {
            $slug = sanitize_key((string) ($row->attribute_type ?? ''));
            $value = function_exists('seo_attributes_display_value') ? seo_attributes_display_value($row) : (string) ($row->attribute_value ?? '');
            $value = trim((string) $value);
            if ($slug !== '' && $value !== '') $out[$slug][] = $value;
        }
        foreach ($out as $slug => $values) $out[$slug] = array_values(array_unique($values));
        return $out;
    }
}

if (!function_exists('seo_assignment_attribute_proposal')) {
    /** La propuesta de atributos se resuelve exclusivamente en Clasificador. */
    function seo_assignment_attribute_proposal($product_id, array $current = []) {
        if (!function_exists('seo_classifier_product_attribute_proposal')) {
            return ['values'=>[],'review'=>[],'confidence'=>0.0,'evidence'=>[],'engine'=>'unavailable','viable'=>false];
        }
        return seo_classifier_product_attribute_proposal($product_id, $current);
    }
}

if (!function_exists('seo_assignment_merge_attributes')) {
    function seo_assignment_merge_attributes(array $current, array $proposal) {
        foreach ($proposal as $slug => $values) {
            $current[$slug] = array_values(array_unique(array_merge((array) ($current[$slug] ?? []), (array) $values)));
        }
        ksort($current);
        return $current;
    }
}

if (!function_exists('seo_assignment_json')) {
    function seo_assignment_json($data) {
        return (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('seo_assignment_parse_json')) {
    function seo_assignment_parse_json($raw) {
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) throw new InvalidArgumentException('El JSON de asignación no es válido.');
        return $decoded;
    }
}

if (!function_exists('seo_assignment_apply_product_labels_json')) {
    function seo_assignment_apply_product_labels_json($product_id, $raw) {
        $decoded = seo_assignment_parse_json($raw);
        $groups = [];
        foreach (['tipo','aplicacion','plataforma','subtipo'] as $group) {
            if (!array_key_exists($group, $decoded)) continue;
            $values = is_array($decoded[$group]) ? $decoded[$group] : [$decoded[$group]];
            $ids = [];
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value === '') continue;
                if (!function_exists('seo_catalog_find_active_vocabulary_term')) throw new RuntimeException('No está disponible el resolver canónico de etiquetas.');
                $term = seo_catalog_find_active_vocabulary_term($group, $value);
                if (!$term) throw new InvalidArgumentException('«' . $value . '» no existe como etiqueta activa de ' . strtoupper($group) . '.');
                $ids[] = (int) $term['id'];
            }
            if ($group === 'tipo' && count(array_unique($ids)) !== 1) throw new InvalidArgumentException('TIPO debe contener exactamente un valor existente.');
            $groups[$group] = array_values(array_unique($ids));
        }
        if (!$groups) throw new InvalidArgumentException('No hay grupos de etiquetas válidos para actualizar.');
        if (!function_exists('seo_catalog_apply_product_vocabulary_changes')) throw new RuntimeException('No está disponible la escritura canónica de etiquetas de producto.');
        $result = seo_catalog_apply_product_vocabulary_changes(absint($product_id), $groups, 'assignment_admin');
        if (empty($result['ok'])) throw new RuntimeException((string) ($result['message'] ?? 'No se pudo actualizar la clasificación.'));
        return true;
    }
}


if (!function_exists('seo_assignment_create_and_assign_new_product_label')) {
    /**
     * Convierte una propuesta NUEVA del Clasificador en vocabulario canónico y
     * la asigna al producto. Nunca se llama de forma masiva: exige un POST
     * explícito del administrador para cada término.
     */
    function seo_assignment_create_and_assign_new_product_label($product_id, $group, $label, $role_id = 0) {
        global $wpdb;
        $product_id = absint($product_id);
        $group = sanitize_key((string) $group);
        $label = sanitize_text_field((string) $label);
        $role_id = absint($role_id);
        if ($product_id < 1) throw new InvalidArgumentException('Producto no válido.');
        if (!in_array($group, ['tipo','aplicacion','plataforma','subtipo'], true)) throw new InvalidArgumentException('Grupo semántico no válido.');
        if ($label === '') throw new InvalidArgumentException('La nueva etiqueta está vacía.');
        $slug = sanitize_title($label);
        if ($slug === '') throw new InvalidArgumentException('No se puede generar un slug válido para la nueva etiqueta.');

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        if (!seo_tags_vocab_table_exists($vocabulary)) throw new RuntimeException('No está disponible el inventario canónico de vocabulario.');

        // Evita duplicados: si ya existe un término activo equivalente, se reutiliza.
        $term_id = 0;
        $created = false;
        if (function_exists('seo_catalog_find_active_vocabulary_term')) {
            $active = seo_catalog_find_active_vocabulary_term($group, $label);
            if ($active) $term_id = absint($active['id'] ?? 0);
        }
        if ($term_id < 1) {
            $same_slug = $wpdb->get_row($wpdb->prepare(
                "SELECT id,active,label FROM {$vocabulary} WHERE semantic_group=%s AND slug=%s LIMIT 1",
                $group,
                $slug
            ), ARRAY_A);
            if (is_array($same_slug) && !empty($same_slug['id'])) {
                if ((int)($same_slug['active'] ?? 0) !== 1) {
                    throw new InvalidArgumentException('Ya existe una etiqueta inactiva con este concepto. Revísala en Gestionar etiquetas antes de reutilizarla.');
                }
                $term_id = absint($same_slug['id']);
            }
        }

        if ($group === 'tipo' && $term_id < 1) {
            if ($role_id < 1) throw new InvalidArgumentException('Un TIPO nuevo necesita seleccionar el ROL canónico que debe derivar.');
            $valid_role = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$vocabulary} WHERE id=%d AND semantic_group='rol' AND active=1",
                $role_id
            ));
            if ($valid_role !== 1) throw new InvalidArgumentException('El ROL seleccionado no es válido o está inactivo.');
        }

        if ($term_id < 1) {
            $ok = $wpdb->insert(
                $vocabulary,
                [
                    'semantic_group'=>$group,
                    'slug'=>$slug,
                    'label'=>$label,
                    'source'=>'classifier_accepted',
                    'active'=>1,
                ],
                ['%s','%s','%s','%s','%d']
            );
            if (!$ok) throw new RuntimeException('No se pudo crear la nueva etiqueta en el inventario.');
            $term_id = (int) $wpdb->insert_id;
            $created = true;
        }

        if ($group === 'tipo' && $created) {
            if (!function_exists('seo_tags_vocab_set_type_role') || !seo_tags_vocab_set_type_role($term_id, $role_id)) {
                if ($created) $wpdb->update($vocabulary, ['active'=>0], ['id'=>$term_id], ['%d'], ['%d']);
                throw new RuntimeException('No se pudo establecer la relación TIPO → ROL para la nueva etiqueta.');
            }
        }

        $current = seo_assignment_semantic_current('product', $product_id);
        if ($group === 'tipo') {
            $ids = [$term_id];
        } else {
            $ids = [];
            foreach ((array)($current[$group] ?? []) as $row) {
                if (is_array($row) && !empty($row['id'])) $ids[] = (int)$row['id'];
            }
            $ids[] = $term_id;
            $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        }

        if (!function_exists('seo_catalog_apply_product_vocabulary_changes')) throw new RuntimeException('No está disponible la escritura canónica de etiquetas de producto.');
        $result = seo_catalog_apply_product_vocabulary_changes($product_id, [$group=>$ids], 'classifier_new_label');
        if (empty($result['ok'])) {
            if ($created) {
                $wpdb->update($vocabulary, ['active'=>0], ['id'=>$term_id], ['%d'], ['%d']);
                if ($group === 'tipo') {
                    $map = $wpdb->prefix . 'seo_type_role_map';
                    if (seo_tags_vocab_table_exists($map)) $wpdb->update($map, ['active'=>0], ['type_vocabulary_id'=>$term_id], ['%d'], ['%d']);
                }
            }
            throw new RuntimeException((string)($result['message'] ?? 'No se pudo asignar la nueva etiqueta al producto.'));
        }

        return ['term_id'=>$term_id,'created'=>$created,'group'=>$group,'label'=>$label];
    }
}

if (!function_exists('seo_assignment_apply_attributes_json')) {
    function seo_assignment_apply_attributes_json($product_id, $raw) {
        $decoded = seo_assignment_parse_json($raw);
        $rows = [];
        foreach ($decoded as $slug => $values) {
            $slug = sanitize_key((string) $slug);
            if ($slug === '') continue;
            $values = is_array($values) ? $values : [$values];
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value === '') continue;
                $rows[] = ['attribute_type' => $slug, 'attribute_value' => $value];
            }
        }
        if (!function_exists('seo_attributes_append_product')) throw new RuntimeException('No está disponible la escritura canónica de atributos.');
        // La mesa de Asignación añade/reutiliza valores sin borrar los existentes.
        // Así no degrada atributos numéricos/rangos ya estructurados al confirmar
        // una propuesta parcial. Las sustituciones completas siguen perteneciendo
        // al editor específico de atributos.
        seo_attributes_append_product(absint($product_id), $rows, 'assignment_admin');
        return true;
    }
}

if (!function_exists('seo_assignment_apply_category_labels_json')) {
    function seo_assignment_apply_category_labels_json($category_id, $raw) {
        $decoded = seo_assignment_parse_json($raw);
        $groups = array_fill_keys(seo_assignment_allowed_groups(), []);
        foreach (seo_assignment_allowed_groups() as $group) {
            $values = isset($decoded[$group]) ? (is_array($decoded[$group]) ? $decoded[$group] : [$decoded[$group]]) : [];
            foreach ($values as $value) {
                $value = trim((string) $value);
                if ($value === '') continue;
                if (!function_exists('seo_category_vocabulary_find_active_term')) throw new RuntimeException('No está disponible el resolver canónico de categorías.');
                $term = seo_category_vocabulary_find_active_term($group, $value);
                if (!$term) throw new InvalidArgumentException('«' . $value . '» no existe como etiqueta activa de ' . strtoupper($group) . '.');
                $groups[$group][] = (int) $term['id'];
            }
            $groups[$group] = array_values(array_unique($groups[$group]));
        }
        if (!function_exists('seo_category_vocabulary_replace')) throw new RuntimeException('No está disponible la escritura canónica de categorías.');
        $result = seo_category_vocabulary_replace(absint($category_id), $groups, 'assignment_admin');
        if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
        return true;
    }
}

if (!function_exists('seo_assignment_query_products')) {
    function seo_assignment_query_products($kind, array $filters, $limit = 50, $offset = 0, &$total = 0) {
        global $wpdb;
        $search = trim((string) ($filters['search'] ?? ''));
        $category_id = absint($filters['category_id'] ?? 0);
        $coverage = sanitize_key((string) ($filters['coverage'] ?? 'all'));
        $priority = sanitize_key((string) ($filters['priority'] ?? 'all'));
        $where = ["p.post_type='product'", "p.post_status='publish'"];
        $args = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(p.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_sku' AND pm.meta_value LIKE %s)" . (ctype_digit($search) ? ' OR p.ID=%d' : '') . ')';
            $args[] = $like; $args[] = $like; if (ctype_digit($search)) $args[] = (int) $search;
        }
        if ($category_id > 0) {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat' WHERE tr.object_id=p.ID AND tt.term_id=%d)";
            $args[] = $category_id;
        }
        if ($kind === 'product_attributes' && function_exists('seo_attributes_tables')) {
            $tables = seo_attributes_tables();
            if ($coverage === 'without') $where[] = "NOT EXISTS (SELECT 1 FROM `{$tables['values']}` pa WHERE pa.product_id=p.ID)";
            elseif ($coverage === 'with') $where[] = "EXISTS (SELECT 1 FROM `{$tables['values']}` pa WHERE pa.product_id=p.ID)";
        } elseif ($kind === 'product_labels') {
            $v = $wpdb->prefix . 'seo_vocabulary';
            $o = $wpdb->prefix . 'seo_object_vocabulary';
            $group_map = ['without_type'=>'tipo','without_role'=>'rol','without_application'=>'aplicacion','without_platform'=>'plataforma','without_subtype'=>'subtipo'];
            $exists = [];
            foreach (['tipo','rol','aplicacion','plataforma','subtipo'] as $group) {
                $exists[$group] = "EXISTS (SELECT 1 FROM {$o} ov_cov JOIN {$v} vv_cov ON vv_cov.id=ov_cov.vocabulary_id AND vv_cov.active=1 WHERE ov_cov.object_type='product' AND ov_cov.object_id=p.ID AND ov_cov.status=1 AND vv_cov.semantic_group='" . $group . "')";
            }
            $all_complete = '(' . implode(' AND ', array_values($exists)) . ')';
            $any_present = '(' . implode(' OR ', array_values($exists)) . ')';
            if ($coverage === 'without_any') {
                $where[] = 'NOT ' . $any_present;
            } elseif ($coverage === 'missing_any') {
                $where[] = 'NOT ' . $all_complete;
            } elseif ($coverage === 'complete') {
                $where[] = $all_complete;
            } elseif (isset($group_map[$coverage])) {
                $where[] = 'NOT (' . $exists[$group_map[$coverage]] . ')';
            }

            // Cola de prioridad disjunta: cada producto aparece en el primer hueco relevante.
            if ($priority === 'p1') {
                $where[] = 'NOT (' . $exists['tipo'] . ')';
            } elseif ($priority === 'p2') {
                $where[] = $exists['tipo'] . ' AND NOT (' . $exists['rol'] . ')';
            } elseif ($priority === 'p3') {
                $where[] = $exists['tipo'] . ' AND ' . $exists['rol'] . ' AND NOT (' . $exists['aplicacion'] . ')';
            } elseif ($priority === 'p4') {
                $where[] = $exists['tipo'] . ' AND ' . $exists['rol'] . ' AND ' . $exists['aplicacion'] . ' AND NOT (' . $exists['subtipo'] . ')';
            } elseif ($priority === 'p5') {
                $where[] = $exists['tipo'] . ' AND ' . $exists['rol'] . ' AND ' . $exists['aplicacion'] . ' AND ' . $exists['subtipo'] . ' AND NOT (' . $exists['plataforma'] . ')';
            } elseif ($priority === 'complete') {
                $where[] = $all_complete;
            }
        }
        $where_sql = implode(' AND ', $where);
        $count_sql = seo_tags_vocab_prepare_sql("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where_sql}", $args);
        $total = (int) $wpdb->get_var($count_sql);
        $query_args = $args; $query_args[] = max(1,(int)$limit); $query_args[] = max(0,(int)$offset);
        $sql = seo_tags_vocab_prepare_sql(
            "SELECT p.ID,p.post_title,p.post_modified,(SELECT MAX(pm.meta_value) FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_sku') AS sku
             FROM {$wpdb->posts} p WHERE {$where_sql} ORDER BY p.post_modified DESC,p.ID DESC LIMIT %d OFFSET %d",
            $query_args
        );
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }
}

if (!function_exists('seo_assignment_query_categories')) {
    function seo_assignment_query_categories(array $filters, $limit = 50, $offset = 0, &$total = 0) {
        global $wpdb;
        $search = trim((string) ($filters['search'] ?? ''));
        $coverage = sanitize_key((string) ($filters['coverage'] ?? 'all'));
        $v = $wpdb->prefix . 'seo_vocabulary';
        $o = $wpdb->prefix . 'seo_object_vocabulary';
        $where = ["tt.taxonomy='product_cat'"];
        $args = [];
        if ($search !== '') { $where[] = '(t.name LIKE %s OR t.slug LIKE %s)'; $like='%'.$wpdb->esc_like($search).'%'; $args[]=$like; $args[]=$like; }
        $group_map = ['without_type'=>'tipo','without_role'=>'rol','without_application'=>'aplicacion','without_platform'=>'plataforma','without_subtype'=>'subtipo'];
        if ($coverage === 'without_any') {
            $where[] = "NOT EXISTS (SELECT 1 FROM {$o} ov JOIN {$v} vv ON vv.id=ov.vocabulary_id AND vv.active=1 WHERE ov.object_type='product_cat' AND ov.object_id=t.term_id AND ov.status=1 AND vv.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo'))";
        } elseif ($coverage === 'missing_any') {
            $where[] = "(NOT EXISTS (SELECT 1 FROM {$o} ov JOIN {$v} vv ON vv.id=ov.vocabulary_id AND vv.active=1 WHERE ov.object_type='product_cat' AND ov.object_id=t.term_id AND ov.status=1 AND vv.semantic_group='tipo') OR NOT EXISTS (SELECT 1 FROM {$o} ov2 JOIN {$v} vv2 ON vv2.id=ov2.vocabulary_id AND vv2.active=1 WHERE ov2.object_type='product_cat' AND ov2.object_id=t.term_id AND ov2.status=1 AND vv2.semantic_group='rol'))";
        } elseif (isset($group_map[$coverage])) {
            $where[] = "NOT EXISTS (SELECT 1 FROM {$o} ov JOIN {$v} vv ON vv.id=ov.vocabulary_id AND vv.active=1 WHERE ov.object_type='product_cat' AND ov.object_id=t.term_id AND ov.status=1 AND vv.semantic_group=%s)";
            $args[] = $group_map[$coverage];
        }
        $where_sql = implode(' AND ', $where);
        $total = (int) $wpdb->get_var(seo_tags_vocab_prepare_sql("SELECT COUNT(*) FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE {$where_sql}", $args));
        $query_args=$args; $query_args[]=max(1,(int)$limit); $query_args[]=max(0,(int)$offset);
        return (array) $wpdb->get_results(seo_tags_vocab_prepare_sql(
            "SELECT t.term_id,t.name,t.slug,tt.count FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE {$where_sql} ORDER BY t.name LIMIT %d OFFSET %d",
            $query_args
        ), ARRAY_A);
    }
}

if (!function_exists('seo_assignment_summary')) {
    function seo_assignment_summary() {
        $cached = get_transient('seo_assignment_summary_v045');
        if (is_array($cached)) return $cached;
        global $wpdb;
        $labels = seo_tags_vocab_get_summary();
        $attrs = function_exists('seo_semantic_attributes_get_summary') ? seo_semantic_attributes_get_summary() : [];
        $total_cats = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_cat'");
        $v = $wpdb->prefix . 'seo_vocabulary'; $o = $wpdb->prefix . 'seo_object_vocabulary';
        $cats_with = (seo_tags_vocab_table_exists($v) && seo_tags_vocab_table_exists($o)) ? (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT ov.object_id) FROM {$o} ov JOIN {$v} vv ON vv.id=ov.vocabulary_id AND vv.active=1
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=ov.object_id AND tt.taxonomy='product_cat'
             WHERE ov.object_type='product_cat' AND ov.status=1 AND vv.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')"
        ) : 0;
        $products_total = (int) ($labels['products'] ?? 0);
        $products_complete = 0;
        if (seo_tags_vocab_table_exists($v) && seo_tags_vocab_table_exists($o)) {
            $products_complete = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM (
                    SELECT ov.object_id
                    FROM {$o} ov
                    JOIN {$v} vv ON vv.id=ov.vocabulary_id AND vv.active=1
                    JOIN {$wpdb->posts} p ON p.ID=ov.object_id AND p.post_type='product' AND p.post_status='publish'
                    WHERE ov.object_type='product' AND ov.status=1
                      AND vv.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                    GROUP BY ov.object_id
                    HAVING COUNT(DISTINCT vv.semantic_group)=5
                ) seo_complete_products"
            );
        }
        $core_ok = min((int) ($labels['groups']['tipo']['products'] ?? 0), (int) ($labels['groups']['rol']['products'] ?? 0));
        $summary = [
            'products_total' => $products_total,
            'products_labels_ok' => $core_ok,
            'products_labels_missing' => max(0, $products_total - $core_ok),
            'products_labels_complete' => $products_complete,
            'products_labels_incomplete' => max(0, $products_total - $products_complete),
            'products_attributes_with' => (int) ($attrs['products'] ?? 0),
            'products_attributes_without' => max(0, (int) ($attrs['products_total'] ?? 0) - (int) ($attrs['products'] ?? 0)),
            'categories_total' => $total_cats,
            'categories_with' => $cats_with,
            'categories_without' => max(0, $total_cats - $cats_with),
        ];
        set_transient('seo_assignment_summary_v045', $summary, 2 * MINUTE_IN_SECONDS);
        return $summary;
    }
}

if (!function_exists('seo_assignment_invalidate_summary')) {
    function seo_assignment_invalidate_summary() {
        delete_transient('seo_assignment_summary_v045');
    }
}

if (!function_exists('seo_assignment_render_summary')) {
    function seo_assignment_render_summary() {
        $s = seo_assignment_summary();
        $cards = [
            ['Productos', $s['products_total'], 'publicados'],
            ['Cobertura etiquetas 5/5', $s['products_labels_complete'], 'TIPO + ROL + APLICACIÓN + PLATAFORMA + SUBTIPO'],
            ['Cobertura incompleta', $s['products_labels_incomplete'], 'falta al menos una dimensión'],
            ['Productos sin atributos', $s['products_attributes_without'], 'sin asignaciones canónicas'],
            ['Categorías etiquetadas', $s['categories_with'], $s['categories_total'] . ' categorías totales'],
            ['Categorías sin etiquetas', $s['categories_without'], 'pendientes de clasificación'],
        ];
        echo '<div class="seo-tags-cards">';
        foreach ($cards as $card) echo '<div class="seo-tags-card"><div class="label">'.esc_html($card[0]).'</div><div class="value">'.esc_html(number_format_i18n((int)$card[1])).'</div><div class="meta">'.esc_html($card[2]).'</div></div>';
        echo '</div>';
    }
}

if (!function_exists('seo_assignment_current_filters')) {
    function seo_assignment_current_filters($section) {
        $coverage = sanitize_key($_GET['assignment_coverage'] ?? ($section === 'product_attributes' ? 'without' : 'missing_any'));
        $priority = sanitize_key($_GET['assignment_priority'] ?? 'all');
        if ($section === 'product_labels') {
            $allowed_coverage = ['missing_any','without_any','without_type','without_role','without_application','without_subtype','without_platform'];
            if (!in_array($coverage,$allowed_coverage,true)) $coverage='missing_any';
            if (!in_array($priority,['all','p1','p2','p3','p4','p5'],true)) $priority='all';
        }
        return [
            'search' => sanitize_text_field(wp_unslash($_GET['assignment_s'] ?? '')),
            'category_id' => absint($_GET['assignment_category_id'] ?? 0),
            'coverage' => $coverage,
            'priority' => $priority,
            'per_page' => in_array(absint($_GET['assignment_per_page'] ?? 25), [25,50,100], true) ? absint($_GET['assignment_per_page'] ?? 25) : 25,
            'page' => max(1,absint($_GET['assignment_paged'] ?? 1)),
            // No se consulta ningún inventario hasta que el usuario pulsa Filtrar.
            'submitted' => !empty($_GET['assignment_filter']),
        ];
    }
}

if (!function_exists('seo_assignment_redirect')) {
    function seo_assignment_redirect($section, $notice, $detail = '') {
        $section = array_key_exists($section, seo_assignment_sections()) ? $section : 'product_labels';
        wp_safe_redirect(add_query_arg([
            'page'=>'seo-tags-vocabulary','domain'=>'assignment','assignment_section'=>$section,
            'assignment_notice'=>sanitize_key($notice),'assignment_detail'=>rawurlencode((string)$detail),
        ], admin_url('admin.php')));
        exit;
    }
}

if (!function_exists('seo_assignment_filters_from_request')) {
    function seo_assignment_filters_from_request($source = null) {
        $source = is_array($source) ? $source : $_POST;
        return [
            'search'=>sanitize_text_field(wp_unslash($source['assignment_s'] ?? '')),
            'category_id'=>absint($source['assignment_category_id'] ?? 0),
            'coverage'=>sanitize_key(wp_unslash($source['assignment_coverage'] ?? 'missing_any')),
            'priority'=>sanitize_key(wp_unslash($source['assignment_priority'] ?? 'all')),
            'per_page'=>in_array(absint($source['assignment_per_page'] ?? 25), [25,50,100], true) ? absint($source['assignment_per_page'] ?? 25) : 25,
            'page'=>1,
            'submitted'=>true,
        ];
    }
}

if (!function_exists('seo_assignment_job_redirect')) {
    function seo_assignment_job_redirect($section, array $filters, $job_id, $notice = 'job_started', $detail = '') {
        $args = [
            'page'=>'seo-tags-vocabulary','domain'=>'assignment','assignment_section'=>$section,
            'assignment_filter'=>1,
            'assignment_s'=>(string)($filters['search'] ?? ''),
            'assignment_category_id'=>absint($filters['category_id'] ?? 0),
            'assignment_coverage'=>(string)($filters['coverage'] ?? 'missing_any'),
            'assignment_priority'=>(string)($filters['priority'] ?? 'all'),
            'assignment_per_page'=>absint($filters['per_page'] ?? 25),
            'classifier_job'=>absint($job_id),
            'assignment_notice'=>sanitize_key($notice),
            'assignment_detail'=>rawurlencode((string)$detail),
        ];
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}

if (!function_exists('seo_assignment_notice')) {
    function seo_assignment_notice() {
        $code = sanitize_key($_GET['assignment_notice'] ?? '');
        if ($code === '') return;
        $detail = rawurldecode((string) ($_GET['assignment_detail'] ?? ''));
        $map = [
            'saved'=>['success','Asignación confirmada y actualizada en el inventario oficial.'],
            'new_label_created'=>['success','Nueva etiqueta creada en el inventario y asignada al producto.'],
            'new_label_reused'=>['success','La etiqueta ya existía en el inventario y se ha reutilizado para el producto.'],
            'mass_saved'=>['success','Propuestas viables aplicadas.'],
            'job_started'=>['success','Trabajo del Clasificador enviado a la cola.'],
            'apply_job_started'=>['success','Aplicación de propuestas enviada a la cola.'],
            'nothing'=>['warning','No había propuestas viables para aplicar.'],
            'error'=>['error','No se pudo completar la asignación.'],
        ];
        $row = $map[$code] ?? $map['error'];
        echo '<div class="notice notice-'.esc_attr($row[0]).' inline is-dismissible"><p>'.esc_html($row[1]).($detail!==''?' '.esc_html($detail):'').'</p></div>';
    }
}

if (!function_exists('seo_assignment_mass_apply')) {
    function seo_assignment_mass_apply($section) {
        $filters = [
            'search' => sanitize_text_field(wp_unslash($_POST['assignment_s'] ?? '')),
            'category_id' => absint($_POST['assignment_category_id'] ?? 0),
            'coverage' => sanitize_key($_POST['assignment_coverage'] ?? ($section === 'product_attributes' ? 'without' : 'missing_any')),
            'priority' => sanitize_key($_POST['assignment_priority'] ?? 'all'),
        ];
        $max = 500;
        $saved = 0; $errors = 0; $total = 0;
        if ($section === 'category_labels') {
            $rows = seo_assignment_query_categories($filters, $max, 0, $total);
            foreach ($rows as $row) {
                $id=(int)$row['term_id']; $current=seo_assignment_semantic_current('product_cat',$id); $p=seo_assignment_category_proposal($id,$current);
                if (empty($p['viable'])) continue;
                try { seo_assignment_apply_category_labels_json($id, seo_assignment_json(seo_assignment_merge_semantic($current,$p['values']))); $saved++; } catch (Throwable $e) { $errors++; }
            }
        } else {
            $rows = seo_assignment_query_products($section,$filters,$max,0,$total);
            foreach ($rows as $row) {
                $id=(int)$row['ID'];
                try {
                    if ($section === 'product_attributes') {
                        $current=seo_assignment_attribute_current($id); $p=seo_assignment_attribute_proposal($id,$current); if (empty($p['viable'])) continue;
                        seo_assignment_apply_attributes_json($id,seo_assignment_json(seo_assignment_merge_attributes($current,$p['values'])));
                    } else {
                        $current=seo_assignment_semantic_current('product',$id); $p=seo_assignment_propose_product_labels($id,$current,(array)($proposal_map[$id]??[])); if (empty($p['viable'])) continue;
                        seo_assignment_apply_product_labels_json($id,seo_assignment_json(seo_assignment_merge_semantic($current,$p['values'])));
                    }
                    $saved++;
                } catch (Throwable $e) { $errors++; }
            }
        }
        return ['saved'=>$saved,'errors'=>$errors,'total'=>$total,'limited'=>$total>$max,'max'=>$max];
    }
}

if (!function_exists('seo_assignment_export_payload')) {
    function seo_assignment_export_payload() {
        $payload = [
            'schema'=>'seo-taxonomy-assignment-inventory','schema_version'=>1,'generated_at'=>current_time('mysql'),
            'policy'=>'Las propuestas existentes reutilizan vocabulario canónico. Los huecos nuevos se exportan como new_terms y solo se crean mediante aceptación manual explícita.',
            'summary'=>seo_assignment_summary(),
            'label_dictionary'=>function_exists('seo_tags_vocab_get_dictionary_rows') ? seo_tags_vocab_get_dictionary_rows() : [],
            'attribute_dictionary'=>function_exists('seo_attributes_get_catalog') ? seo_attributes_get_catalog(true) : [],
            'product_labels'=>[], 'product_attributes'=>[], 'category_labels'=>[], 'limits'=>['per_section'=>1000],
        ];
        $limit=1000; $total=0;
        $rows=seo_assignment_query_products('product_labels',['coverage'=>'missing_any','priority'=>'all','search'=>'','category_id'=>0],$limit,0,$total);
        foreach($rows as $row){$id=(int)$row['ID'];$c=seo_assignment_semantic_current('product',$id);$p=seo_assignment_propose_product_labels($id,$c);$payload['product_labels'][]=['id'=>$id,'title'=>$row['post_title'],'current'=>seo_assignment_semantic_label_map($c),'proposal'=>$p['values'],'new_terms'=>(array)($p['new_terms']??[]),'target'=>seo_assignment_merge_semantic($c,$p['values']),'viable'=>$p['viable']];}
        $payload['product_labels_total']=$total;$payload['product_labels_truncated']=$total>$limit;
        $rows=seo_assignment_query_products('product_attributes',['coverage'=>'without','search'=>'','category_id'=>0],$limit,0,$total);
        foreach($rows as $row){$id=(int)$row['ID'];$c=seo_assignment_attribute_current($id);$p=seo_assignment_attribute_proposal($id,$c);$payload['product_attributes'][]=['id'=>$id,'title'=>$row['post_title'],'current'=>$c,'proposal'=>$p['values'],'target'=>seo_assignment_merge_attributes($c,$p['values']),'viable'=>$p['viable']];}
        $payload['product_attributes_total']=$total;$payload['product_attributes_truncated']=$total>$limit;
        $rows=seo_assignment_query_categories(['coverage'=>'missing_any','search'=>''], $limit,0,$total);
        foreach($rows as $row){$id=(int)$row['term_id'];$c=seo_assignment_semantic_current('product_cat',$id);$p=seo_assignment_category_proposal($id,$c);$payload['category_labels'][]=['id'=>$id,'name'=>$row['name'],'current'=>seo_assignment_semantic_label_map($c),'proposal'=>$p['values'],'target'=>seo_assignment_merge_semantic($c,$p['values']),'viable'=>$p['viable']];}
        $payload['category_labels_total']=$total;$payload['category_labels_truncated']=$total>$limit;
        return $payload;
    }
}


if (!function_exists('seo_assignment_parse_group_json')) {
    /** Cada celda semántica usa un JSON independiente con una lista de valores. */
    function seo_assignment_parse_group_json($raw, $group) {
        $raw = trim((string) $raw);
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded && array_keys($decoded) !== range(0, count($decoded) - 1))) {
            throw new InvalidArgumentException(strtoupper((string) $group) . ': introduce un JSON de lista, por ejemplo ["Valor"].');
        }
        $values = [];
        foreach ($decoded as $value) {
            if (is_array($value) || is_object($value)) {
                throw new InvalidArgumentException(strtoupper((string) $group) . ': cada elemento debe ser texto.');
            }
            $value = trim((string) $value);
            if ($value !== '') $values[] = $value;
        }
        return array_values(array_unique($values));
    }
}

if (!function_exists('seo_assignment_apply_product_label_matrix')) {
    function seo_assignment_apply_product_label_matrix($product_id) {
        $payload = [];
        foreach (['tipo','aplicacion','plataforma','subtipo'] as $group) {
            $field = 'assignment_group_' . $group;
            if (!array_key_exists($field, $_POST)) continue;
            $payload[$group] = seo_assignment_parse_group_json(wp_unslash($_POST[$field]), $group);
        }
        if (!$payload) throw new InvalidArgumentException('No hay celdas de etiquetas para confirmar.');
        return seo_assignment_apply_product_labels_json(absint($product_id), seo_assignment_json($payload));
    }
}

if (!function_exists('seo_assignment_product_label_priority')) {
    function seo_assignment_product_label_priority(array $current) {
        if (empty($current['tipo'])) return ['code'=>'p1','label'=>'P1 · falta TIPO'];
        if (empty($current['rol'])) return ['code'=>'p2','label'=>'P2 · falta ROL'];
        if (empty($current['aplicacion'])) return ['code'=>'p3','label'=>'P3 · falta APLICACIÓN'];
        if (empty($current['subtipo'])) return ['code'=>'p4','label'=>'P4 · falta SUBTIPO'];
        if (empty($current['plataforma'])) return ['code'=>'p5','label'=>'P5 · falta PLATAFORMA'];
        return ['code'=>'complete','label'=>'Completa 5/5'];
    }
}

if (!function_exists('seo_assignment_json_inline')) {
    function seo_assignment_json_inline(array $values) {
        return (string) wp_json_encode(array_values($values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('seo_assignment_group_cell_state')) {
    function seo_assignment_group_cell_state($group, array $current_labels, array $proposal) {
        $current = array_values((array) ($current_labels[$group] ?? []));
        $safe = array_values((array) (($proposal['values'][$group] ?? [])));
        $review = array_values((array) (($proposal['review'][$group] ?? [])));
        $new_rows = array_values((array) (($proposal['new_terms'][$group] ?? [])));
        if ($current) return ['state'=>'current','values'=>$current,'label'=>'Actual · ya asignado','new'=>[]];
        if ($safe) return ['state'=>'safe','values'=>$safe,'label'=>'Propuesta segura · pendiente','new'=>[]];
        if ($review) return ['state'=>'review','values'=>$review,'label'=>'Revisar · pendiente','new'=>$new_rows];
        if ($new_rows) return ['state'=>'new','values'=>[],'label'=>'Nueva etiqueta · pendiente','new'=>$new_rows];
        if (in_array($group, (array)($proposal['unresolved_groups'] ?? []), true)) return ['state'=>'unresolved','values'=>[],'label'=>'Sin propuesta','new'=>[]];
        if (in_array($group, (array)($proposal['pending_groups'] ?? []), true)) return ['state'=>'pending','values'=>[],'label'=>'Sin analizar','new'=>[]];
        return ['state'=>'empty','values'=>[],'label'=>'Vacío','new'=>[]];
    }
}

if (!function_exists('seo_assignment_render_new_label_action')) {
    function seo_assignment_render_new_label_action($product_id, $group, array $candidate) {
        $label = trim((string)($candidate['label'] ?? ''));
        if ($label === '') return;
        $score = max(0.0,min(1.0,(float)($candidate['score'] ?? 0.0)));
        $nearest = (array)($candidate['nearest_existing'] ?? []);
        echo '<div class="seo-tags-new-box">';
        echo '<form method="post">';
        wp_nonce_field('seo_assignment_admin','seo_assignment_nonce');
        echo '<input type="hidden" name="seo_assignment_action" value="accept_new_label">';
        echo '<input type="hidden" name="assignment_section" value="product_labels">';
        echo '<input type="hidden" name="object_id" value="'.esc_attr((int)$product_id).'">';
        echo '<input type="hidden" name="semantic_group" value="'.esc_attr($group).'">';
        echo '<input type="text" name="proposed_label" value="'.esc_attr($label).'" aria-label="Nueva etiqueta propuesta">';
        echo '<small class="seo-tags-muted" style="display:block;margin-top:4px">Confianza de descubrimiento: '.esc_html(number_format_i18n($score*100,0)).'%.</small>';
        if (!empty($nearest['label'])) {
            echo '<small class="seo-tags-muted" style="display:block">Más cercana: '.esc_html((string)$nearest['label']).' · '.esc_html(number_format_i18n(((float)($nearest['similarity']??0))*100,0)).'%.</small>';
        }
        if ($group === 'tipo') {
            $roles = function_exists('seo_tags_vocab_get_active_roles') ? seo_tags_vocab_get_active_roles() : [];
            echo '<label style="display:block;margin-top:5px;font-size:11px">ROL del nuevo TIPO<select name="new_role_id" required style="width:100%;margin-top:2px"><option value="">Seleccionar…</option>';
            foreach ((array)$roles as $role) echo '<option value="'.esc_attr((int)($role['id']??0)).'">'.esc_html((string)($role['label']??$role['slug']??'')).'</option>';
            echo '</select></label>';
        }
        echo '<button class="button button-small seo-tags-new-button" style="margin-top:6px">Crear y asignar</button>';
        echo '</form>';
        echo '<small class="seo-tags-muted" style="display:block;margin-top:4px">Al aceptar se incorpora al inventario canónico y queda disponible para futuros productos.</small>';
        echo '</div>';
    }
}

if (!function_exists('seo_assignment_render_product_group_cell')) {
    function seo_assignment_render_product_group_cell($form_id, $group, array $current_labels, array $proposal, $product_id = 0) {
        $cell = seo_assignment_group_cell_state($group, $current_labels, $proposal);
        $state = (string) $cell['state'];
        $values = (array) $cell['values'];
        $class = in_array($state, ['current','safe','review','new','pending','unresolved','empty'], true) ? $state : 'empty';
        echo '<div style="min-width:150px;max-width:220px">';
        echo '<span class="seo-tags-state '.esc_attr($class).'">'.esc_html($cell['label']).'</span>';
        if ($group === 'rol') {
            echo '<textarea rows="2" readonly style="width:100%;margin-top:5px;font-family:monospace;font-size:11px;resize:vertical;background:#f6f7f7">'.esc_textarea(seo_assignment_json_inline($values)).'</textarea>';
            echo '<small class="seo-tags-muted">ROL se sincroniza desde TIPO.</small>';
        } elseif ($state === 'new') {
            $candidate = (array)(($cell['new'][0] ?? []));
            seo_assignment_render_new_label_action(absint($product_id), $group, $candidate);
        } else {
            echo '<textarea form="'.esc_attr($form_id).'" name="assignment_group_'.esc_attr($group).'" rows="2" style="width:100%;margin-top:5px;font-family:monospace;font-size:11px;resize:vertical">'.esc_textarea(seo_assignment_json_inline($values)).'</textarea>';
            if (!empty($cell['new'][0])) {
                echo '<small style="display:block;margin-top:5px;color:#6b21a8;font-weight:700">Alternativa: vocabulario nuevo</small>';
                seo_assignment_render_new_label_action(absint($product_id), $group, (array)$cell['new'][0]);
            }
        }
        echo '</div>';
    }
}

if (!function_exists('seo_assignment_handle_request')) {
    function seo_assignment_handle_request() {
        if (!current_user_can('manage_options')) return;
        if (!empty($_GET['seo_assignment_export'])) {
            check_admin_referer('seo_assignment_export');
            $payload=seo_assignment_export_payload();
            nocache_headers(); header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="seo-asignacion-'.esc_attr(current_time('Ymd-His')).'.json"');
            echo wp_json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['seo_assignment_action'])) return;
        check_admin_referer('seo_assignment_admin','seo_assignment_nonce');
        $action=sanitize_key(wp_unslash($_POST['seo_assignment_action']));
        $section=sanitize_key(wp_unslash($_POST['assignment_section'] ?? 'product_labels'));
        if (!array_key_exists($section,seo_assignment_sections())) $section='product_labels';
        try {
            if ($action==='start_classifier_job') {
                if ($section !== 'product_labels') throw new InvalidArgumentException('Los jobs adaptativos están disponibles en Etiquetas de productos.');
                if (!function_exists('seo_classifier_job_create')) throw new RuntimeException('No está disponible la cola del Clasificador.');
                $filters = seo_assignment_filters_from_request($_POST);
                $mode = sanitize_key(wp_unslash($_POST['classifier_mode'] ?? 'fast'));
                $job_id = seo_classifier_job_create([
                    'job_type'=>'classify',
                    'mode'=>$mode === 'deep' ? 'deep' : 'fast',
                    'filters'=>$filters,
                    'force_refresh'=>!empty($_POST['force_refresh']),
                    'created_by'=>get_current_user_id(),
                ]);
                if (is_wp_error($job_id)) throw new RuntimeException($job_id->get_error_message());
                seo_assignment_job_redirect($section,$filters,$job_id,'job_started','Job #'.absint($job_id).'.');
            }
            if ($action==='start_apply_job') {
                if ($section !== 'product_labels') throw new InvalidArgumentException('La aplicación en cola está disponible en Etiquetas de productos.');
                if (!function_exists('seo_classifier_job_create')) throw new RuntimeException('No está disponible la cola del Clasificador.');
                $filters = seo_assignment_filters_from_request($_POST);
                $job_id = seo_classifier_job_create([
                    'job_type'=>'apply','mode'=>'fast','filters'=>$filters,'created_by'=>get_current_user_id(),
                ]);
                if (is_wp_error($job_id)) throw new RuntimeException($job_id->get_error_message());
                seo_assignment_job_redirect($section,$filters,$job_id,'apply_job_started','Job #'.absint($job_id).'.');
            }
            if ($action==='accept_new_label') {
                if ($section !== 'product_labels') throw new InvalidArgumentException('Las nuevas etiquetas de Clasificador solo se aceptan desde Etiquetas de productos.');
                $object_id=absint($_POST['object_id'] ?? 0);
                $group=sanitize_key(wp_unslash($_POST['semantic_group'] ?? ''));
                $label=sanitize_text_field(wp_unslash($_POST['proposed_label'] ?? ''));
                $role_id=absint($_POST['new_role_id'] ?? 0);
                $result=seo_assignment_create_and_assign_new_product_label($object_id,$group,$label,$role_id);
                if (function_exists('seo_classifier_bump_profiles_generation')) seo_classifier_bump_profiles_generation();
                if (function_exists('seo_classifier_proposal_invalidate_product')) seo_classifier_proposal_invalidate_product($object_id, [$group]);
                if (function_exists('seo_assignment_invalidate_summary')) seo_assignment_invalidate_summary();
                seo_assignment_redirect($section,!empty($result['created'])?'new_label_created':'new_label_reused',strtoupper($group).': '.$label);
            }
            if ($action==='confirm') {
                $object_id=absint($_POST['object_id'] ?? 0);
                if ($section==='product_labels' && !empty($_POST['assignment_group_matrix'])) {
                    seo_assignment_apply_product_label_matrix($object_id);
                } else {
                    $raw=wp_unslash($_POST['assignment_json'] ?? '');
                    if ($section==='product_labels') seo_assignment_apply_product_labels_json($object_id,$raw);
                    elseif ($section==='product_attributes') seo_assignment_apply_attributes_json($object_id,$raw);
                    else seo_assignment_apply_category_labels_json($object_id,$raw);
                }
                if (function_exists('seo_classifier_bump_profiles_generation')) seo_classifier_bump_profiles_generation();
                if ($section === 'product_labels' && function_exists('seo_classifier_proposal_invalidate_product')) seo_classifier_proposal_invalidate_product($object_id);
                if (function_exists('seo_assignment_invalidate_summary')) seo_assignment_invalidate_summary();
                seo_assignment_redirect($section,'saved');
            }
            if ($action==='mass_accept') {
                // Compatibilidad con formularios antiguos: en productos nunca hacemos ya
                // una escritura masiva síncrona; la convertimos en job adaptativo.
                if ($section === 'product_labels' && function_exists('seo_classifier_job_create')) {
                    $filters = seo_assignment_filters_from_request($_POST);
                    $job_id = seo_classifier_job_create(['job_type'=>'apply','mode'=>'fast','filters'=>$filters,'created_by'=>get_current_user_id()]);
                    if (is_wp_error($job_id)) throw new RuntimeException($job_id->get_error_message());
                    seo_assignment_job_redirect($section,$filters,$job_id,'apply_job_started','Job #'.absint($job_id).'.');
                }
                $result=seo_assignment_mass_apply($section);
                if ((int)$result['saved']<1) seo_assignment_redirect($section,'nothing',$result['errors']?'Errores: '.$result['errors'].'.':'');
                if (function_exists('seo_classifier_bump_profiles_generation')) seo_classifier_bump_profiles_generation();
                if (function_exists('seo_assignment_invalidate_summary')) seo_assignment_invalidate_summary();
                $detail='Aplicadas: '.$result['saved'].'.'; if($result['errors'])$detail.=' Errores: '.$result['errors'].'.'; if($result['limited'])$detail.=' Se procesaron como máximo '.$result['max'].' registros; repite la acción para continuar.';
                seo_assignment_redirect($section,'mass_saved',$detail);
            }
        } catch (Throwable $e) { seo_assignment_redirect($section,'error',$e->getMessage()); }
    }
    add_action('admin_init','seo_assignment_handle_request',20);
}

if (!function_exists('seo_assignment_render_filters')) {
    function seo_assignment_render_filters($section,array $filters) {
        $base=admin_url('admin.php?page=seo-tags-vocabulary&domain=assignment&assignment_section='.$section);
        echo '<div class="seo-tags-panel"><form method="get" class="seo-tags-filter">';
        echo '<input type="hidden" name="page" value="seo-tags-vocabulary"><input type="hidden" name="domain" value="assignment"><input type="hidden" name="assignment_section" value="'.esc_attr($section).'"><input type="hidden" name="assignment_filter" value="1">';
        echo '<label>Buscar<input class="search-wide" type="text" name="assignment_s" value="'.esc_attr($filters['search']).'" placeholder="Producto, SKU, ID o categoría"></label>';
        if ($section!=='category_labels') {
            $cats=get_terms(['taxonomy'=>'product_cat','hide_empty'=>true,'orderby'=>'name','order'=>'ASC']); if(is_wp_error($cats))$cats=[];
            echo '<label>Categoría<select name="assignment_category_id"><option value="0">Todas</option>';
            foreach((array)$cats as $cat)echo '<option value="'.esc_attr((int)$cat->term_id).'" '.selected((int)$filters['category_id'],(int)$cat->term_id,false).'>'.esc_html($cat->name).'</option>';
            echo '</select></label>';
        }
        echo '<label>Cobertura<select name="assignment_coverage">';
        if($section==='product_attributes') {
            $options=['without'=>'Sin atributos','with'=>'Con atributos','all'=>'Todos'];
        } elseif ($section==='product_labels') {
            $options=[
                'missing_any'=>'Cobertura incompleta (falta alguna)',
                'without_any'=>'Sin ninguna etiqueta',
                'without_type'=>'Sin TIPO',
                'without_role'=>'Sin ROL',
                'without_application'=>'Sin APLICACIÓN',
                'without_subtype'=>'Sin SUBTIPO',
                'without_platform'=>'Sin PLATAFORMA',
            ];
        } else {
            $options=['missing_any'=>'Falta TIPO o ROL','without_any'=>'Sin etiquetas','without_type'=>'Sin TIPO','without_role'=>'Sin ROL','without_application'=>'Sin APLICACIÓN','without_platform'=>'Sin PLATAFORMA','without_subtype'=>'Sin SUBTIPO','all'=>'Todos'];
        }
        foreach($options as $v=>$l)echo '<option value="'.esc_attr($v).'" '.selected($filters['coverage'],$v,false).'>'.esc_html($l).'</option>';
        echo '</select></label>';
        if ($section === 'product_labels') {
            $priority_options=[
                'all'=>'Todas las anomalías',
                'p1'=>'P1 · falta TIPO',
                'p2'=>'P2 · falta ROL',
                'p3'=>'P3 · falta APLICACIÓN',
                'p4'=>'P4 · falta SUBTIPO',
                'p5'=>'P5 · falta PLATAFORMA',
            ];
            echo '<label>Prioridad<select name="assignment_priority">';
            foreach($priority_options as $v=>$l)echo '<option value="'.esc_attr($v).'" '.selected((string)($filters['priority']??'all'),$v,false).'>'.esc_html($l).'</option>';
            echo '</select></label>';

        }
        echo '<label>Filas<select name="assignment_per_page">'; foreach([25,50,100] as $n)echo '<option value="'.$n.'" '.selected((int)$filters['per_page'],$n,false).'>'.$n.'</option>'; echo '</select></label>';
        echo '<div><button class="button button-primary">Filtrar</button> <a class="button" href="'.esc_url($base).'">Limpiar</a></div></form></div>';
    }
}

if (!function_exists('seo_assignment_render_job_hidden_filters')) {
    function seo_assignment_render_job_hidden_filters($section, array $filters) {
        echo '<input type="hidden" name="assignment_section" value="'.esc_attr($section).'">';
        echo '<input type="hidden" name="assignment_s" value="'.esc_attr((string)($filters['search']??'')).'">';
        echo '<input type="hidden" name="assignment_category_id" value="'.esc_attr((int)($filters['category_id']??0)).'">';
        echo '<input type="hidden" name="assignment_coverage" value="'.esc_attr((string)($filters['coverage']??'missing_any')).'">';
        echo '<input type="hidden" name="assignment_priority" value="'.esc_attr((string)($filters['priority']??'all')).'">';
        echo '<input type="hidden" name="assignment_per_page" value="'.esc_attr((int)($filters['per_page']??25)).'">';
    }
}

if (!function_exists('seo_assignment_render_mass_actions')) {
    function seo_assignment_render_mass_actions($section,array $filters) {
        echo '<div class="seo-tags-panel" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
        if ($section === 'product_labels') {
            echo '<form method="post">';
            wp_nonce_field('seo_assignment_admin','seo_assignment_nonce');
            echo '<input type="hidden" name="seo_assignment_action" value="start_classifier_job">';
            seo_assignment_render_job_hidden_filters($section,$filters);
            echo '<input type="hidden" name="classifier_mode" value="fast">';
            echo '<button class="button button-primary">1. Analizar filtro · rápido</button></form>';

            echo '<form method="post">';
            wp_nonce_field('seo_assignment_admin','seo_assignment_nonce');
            echo '<input type="hidden" name="seo_assignment_action" value="start_classifier_job">';
            seo_assignment_render_job_hidden_filters($section,$filters);
            echo '<input type="hidden" name="classifier_mode" value="deep">';
            echo '<input type="hidden" name="force_refresh" value="1">';
            echo '<button class="button">1. Analizar filtro · profundo</button></form>';

            echo '<form method="post" onsubmit="return confirm(\'Se aplicarán en segundo plano únicamente propuestas seguras de vocabulario existente. ¿Continuar?\');">';
            wp_nonce_field('seo_assignment_admin','seo_assignment_nonce');
            echo '<input type="hidden" name="seo_assignment_action" value="start_apply_job">';
            seo_assignment_render_job_hidden_filters($section,$filters);
            echo '<button class="button">2. Aplicar propuestas seguras</button></form>';
            echo '<span class="seo-tags-help">Rápido puede reutilizar caché válida. Profundo siempre recalcula y refresca contexto externo conocido. Action Scheduler y WP-Cron trabajan en paralelo; con esta pantalla abierta, un watchdog recupera automáticamente un worker dormido.</span>';
        } else {
            echo '<form method="post" onsubmit="return confirm(\'Se aplicarán únicamente propuestas que reutilizan vocabulario existente. ¿Continuar?\');">';
            wp_nonce_field('seo_assignment_admin','seo_assignment_nonce');
            echo '<input type="hidden" name="seo_assignment_action" value="mass_accept"><input type="hidden" name="assignment_section" value="'.esc_attr($section).'">';
            echo '<input type="hidden" name="assignment_s" value="'.esc_attr($filters['search']).'"><input type="hidden" name="assignment_category_id" value="'.esc_attr((int)$filters['category_id']).'"><input type="hidden" name="assignment_coverage" value="'.esc_attr($filters['coverage']).'"><input type="hidden" name="assignment_priority" value="'.esc_attr((string)($filters['priority']??'all')).'">';
            echo '<button class="button button-primary">Aceptar todas las propuestas viables</button></form>';
            echo '<span class="seo-tags-help">Esta pantalla es correctiva; el inventario completo se obtiene desde Importar / Exportar.</span>';
        }
        echo '</div>';
    }
}

if (!function_exists('seo_assignment_render_semantic_compact')) {
    function seo_assignment_render_semantic_compact(array $values) {
        if(!$values){echo '<span class="seo-tags-muted">Sin asignaciones</span>';return;}
        echo '<details><summary>'.esc_html(count($values)).' grupos con datos</summary><pre style="max-width:430px;white-space:pre-wrap;margin:8px 0 0">'.esc_html(seo_assignment_json($values)).'</pre></details>';
    }
}

if (!function_exists('seo_assignment_render_json_editor')) {
    function seo_assignment_render_json_editor($section,$object_id,$target,$has_proposal) {
        echo '<form method="post" style="min-width:300px">'; wp_nonce_field('seo_assignment_admin','seo_assignment_nonce');
        echo '<input type="hidden" name="seo_assignment_action" value="confirm"><input type="hidden" name="assignment_section" value="'.esc_attr($section).'"><input type="hidden" name="object_id" value="'.esc_attr((int)$object_id).'">';
        echo '<details '.($has_proposal?'':'').'><summary>'.($has_proposal?'Ver / editar JSON propuesto':'Introducir / editar JSON').'</summary>';
        echo '<textarea name="assignment_json" rows="9" style="width:100%;min-width:300px;font-family:monospace;font-size:12px;margin-top:7px">'.esc_textarea(seo_assignment_json($target)).'</textarea></details>';
        echo '<p style="margin:8px 0 0"><button class="button button-primary button-small">Confirmar datos</button></p></form>';
    }
}

if (!function_exists('seo_assignment_render_proposal_diagnostics')) {
    function seo_assignment_render_proposal_diagnostics(array $proposal) {
        $evidence = (array)($proposal['evidence'] ?? []);
        if (!$evidence) return;
        echo '<details style="margin-top:7px"><summary style="cursor:pointer"><small>Diagnóstico del Clasificador</small></summary>';
        echo '<div style="font-size:11px;line-height:1.45;margin-top:5px;max-width:330px">';
        foreach ($evidence as $group=>$info) {
            $top=(array)($info['top']??[]);
            if (!$top) continue;
            $first=(array)$top[0];
            $state=(string)($info['state']??'');
            echo '<div style="margin-bottom:5px"><strong>'.esc_html(strtoupper((string)$group)).'</strong>: '.esc_html((string)($first['label']??''));
            echo ' · '.esc_html(number_format_i18n(((float)($first['score']??0))*100,0)).'%';
            echo $state==='safe'?' · <span class="seo-tags-good">segura</span>':($state==='review'?' · <span class="seo-tags-warn">revisar</span>':($state==='current'?' · <span class="seo-tags-muted">comparación (ya asignado)</span>':' · <span class="seo-tags-muted">descartada</span>'));
            $reasons=(array)($first['reasons']??[]); if($reasons) echo '<br><span class="seo-tags-muted">'.esc_html(implode(' · ',$reasons)).'</span>';
            if(isset($top[1])) echo '<br><span class="seo-tags-muted">2ª: '.esc_html((string)($top[1]['label']??'')).' · '.esc_html(number_format_i18n(((float)($top[1]['score']??0))*100,0)).'%</span>';
            echo '</div>';
        }
        $new_terms=(array)($proposal['new_terms']??[]);
        foreach($new_terms as $group=>$rows){
            foreach((array)$rows as $candidate){
                echo '<div style="margin-bottom:5px;padding:5px 6px;background:#faf5ff;border-left:3px solid #7e22ce"><strong>'.esc_html(strtoupper((string)$group)).'</strong>: '.esc_html((string)($candidate['label']??''));
                echo ' · '.esc_html(number_format_i18n(((float)($candidate['score']??0))*100,0)).'% · <span style="color:#6b21a8;font-weight:700">vocabulario nuevo</span>';
                $reasons=(array)($candidate['reasons']??[]); if($reasons) echo '<br><span class="seo-tags-muted">'.esc_html(implode(' · ',$reasons)).'</span>';
                echo '</div>';
            }
        }
        $sources=(array)($proposal['sources']??[]);
        if($sources){
            $external=(array)($sources['external']??[]);
            $parts=[];
            if(!empty($sources['supplier']))$parts[]='catálogo de proveedor';
            if(!empty($sources['learned_profiles']))$parts[]='perfiles aprendidos '.(string)$sources['learned_profiles'];
            $external_status=(string)($external['status']??'');
            if($external_status!=='')$parts[]='fuente externa: '.$external_status;
            if($parts)echo '<div style="border-top:1px solid #ddd;padding-top:5px;margin-top:4px"><span class="seo-tags-muted">Fuentes: '.esc_html(implode(' · ',$parts)).'</span></div>';
        }
        echo '</div></details>';
    }
}

if (!function_exists('seo_assignment_render_classifier_job')) {
    function seo_assignment_render_classifier_job() {
        if (!function_exists('seo_classifier_job_status_payload')) return;
        $job_id = absint($_GET['classifier_job'] ?? 0);
        $job = $job_id > 0 && function_exists('seo_classifier_job_get') ? seo_classifier_job_get($job_id) : null;
        if (!$job && function_exists('seo_classifier_job_latest')) {
            $latest = seo_classifier_job_latest(get_current_user_id());
            if ($latest && in_array((string)($latest['status'] ?? ''), ['pending','running','paused'], true)) {
                $job = $latest;
                $job_id = (int)$latest['id'];
            }
        }
        if (!$job || $job_id < 1) return;
        $st = seo_classifier_job_status_payload($job_id);
        if (!$st) return;

        $percent = max(0,min(100,(float)($st['progress']??0)));
        $job_type_label = (string)($st['job_type'] ?? '') === 'apply' ? 'APLICACIÓN' : 'ANÁLISIS';
        $mode_label = (string)($st['mode'] ?? '') === 'deep' ? 'PROFUNDO · RECÁLCULO' : 'RÁPIDO';
        $worker_labels = [
            'esperando_arranque'=>'ESPERANDO ARRANQUE',
            'activo'=>'ACTIVO',
            'espera_programada'=>'ESPERA PROGRAMADA',
            'heartbeat_obsoleto'=>'RECUPERANDO',
            'paused'=>'PAUSADO',
            'completed'=>'COMPLETADO',
            'cancelled'=>'CANCELADO',
            'failed'=>'FALLIDO',
        ];
        $worker_state = (string)($st['worker_state'] ?? '');
        $worker_label = $worker_labels[$worker_state] ?? strtoupper($worker_state ?: 'n/d');
        if (!empty($st['worker_source'])) $worker_label .= ' · ' . strtoupper((string)$st['worker_source']);
        $worker_age = isset($st['worker_age_seconds']) && $st['worker_age_seconds'] !== null
            ? absint($st['worker_age_seconds']).' s'
            : 'sin heartbeat';
        $scheduler = (array)($st['scheduler'] ?? []);
        $scheduler_parts = [];
        if (!empty($scheduler['action_scheduler_available'])) $scheduler_parts[] = !empty($scheduler['action_scheduler_pending']) ? 'AS preparado' : 'AS disponible';
        else $scheduler_parts[] = 'AS no disponible';
        if (!empty($scheduler['wp_cron_disabled'])) $scheduler_parts[] = 'WP-Cron desactivado';
        else $scheduler_parts[] = !empty($scheduler['wp_cron_next']) ? 'WP-Cron preparado' : 'WP-Cron sin evento';
        $scheduler_label = implode(' · ', $scheduler_parts);

        echo '<div class="seo-classifier-job" data-seo-classifier-job="'.esc_attr($job_id).'">';
        echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap"><div><strong>Clasificador · Job #'.esc_html($job_id).'</strong><br><span class="seo-tags-muted" data-job-status-label>'.esc_html(strtoupper((string)$st['status']).' · '.$job_type_label.' / '.$mode_label).'</span></div><strong data-job-percent>'.esc_html(number_format_i18n($percent,1)).'%</strong></div>';
        echo '<div class="seo-classifier-progress"><span data-job-progress style="width:'.esc_attr($percent).'%"></span></div>';
        echo '<div class="seo-classifier-job-grid">';
        $kpis=[
            'processed'=>['Procesados',$st['processed'].' / '.$st['total']],
            'safe'=>['Seguras',$st['safe']],
            'review'=>['Revisar',$st['review']],
            'new'=>['Nuevas',$st['new']],
            'unresolved'=>['Sin propuesta',$st['unresolved']],
            'applied'=>['Aplicadas',$st['applied']],
            'cache_hits'=>['Caché',$st['cache_hits']],
            'errors'=>['Errores',$st['errors']],
            'worker'=>['Worker',$worker_label],
            'heartbeat'=>['Última actividad',$worker_age],
            'scheduler'=>['Planificador',$scheduler_label],
            'cpu'=>['CPU est.',isset($st['cpu_percent']) && $st['cpu_percent'] !== null ? number_format_i18n((float)$st['cpu_percent'],1).' %' : 'n/d'],
            'batch'=>['Último lote',$st['last_batch_rows'].' · '.number_format_i18n((float)$st['last_batch_duration'],1).' s'],
            'next'=>['Siguiente',$st['next_batch_rows'].' · pausa '.$st['next_delay'].' s'],
            'pressure'=>['Presión',strtoupper((string)$st['pressure'])],
        ];
        foreach($kpis as $key=>$row) echo '<div class="seo-classifier-job-kpi"><small>'.esc_html($row[0]).'</small><strong data-job-kpi="'.esc_attr($key).'">'.esc_html((string)$row[1]).'</strong></div>';
        echo '</div>';
        if (!empty($st['last_error'])) {
            echo '<div class="notice notice-error inline" style="margin:10px 0 0"><p><strong>Último error:</strong> '.esc_html((string)$st['last_error']).'</p></div>';
        }
        if ((string)($st['mode'] ?? '') === 'deep') {
            echo '<div class="notice notice-info inline" style="margin:10px 0 0"><p><strong>Profundo:</strong> recálculo forzado; no reutiliza propuestas rápidas y refresca las fuentes externas conocidas antes de clasificar. El contador <strong>Caché</strong> debe permanecer en 0.</p></div>';
        }
        echo '<div class="seo-classifier-job-actions">';
        if (in_array((string)$st['status'],['pending','running'],true)) echo '<button type="button" class="button" data-job-action="pause">Pausar</button>';
        if ((string)$st['status']==='paused') echo '<button type="button" class="button button-primary" data-job-action="resume">Reanudar</button>';
        if (in_array((string)$st['status'],['pending','running','paused'],true)) echo '<button type="button" class="button" data-job-action="cancel">Cancelar</button>';
        echo '<span class="seo-tags-help">El servidor continúa en segundo plano. Si Action Scheduler o WP-Cron se duermen, esta pantalla actúa como watchdog y ejecuta un lote de recuperación sin duplicar trabajo.</span></div>';
        echo '</div>';

        $ajax = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('seo_classifier_jobs');
        echo '<script>(function(){
            const root=document.querySelector("[data-seo-classifier-job=\"'.esc_js((string)$job_id).'\"]");
            if(!root||root.dataset.bound)return;
            root.dataset.bound="1";
            const jobId='.wp_json_encode($job_id).',ajax='.wp_json_encode($ajax).',nonce='.wp_json_encode($nonce).';
            let timer=null,stopped=false;
            const jobTypeLabel=v=>v==="apply"?"APLICACIÓN":"ANÁLISIS";
            const modeLabel=v=>v==="deep"?"PROFUNDO · RECÁLCULO":"RÁPIDO";
            const workerLabels={esperando_arranque:"ESPERANDO ARRANQUE",activo:"ACTIVO",espera_programada:"ESPERA PROGRAMADA",heartbeat_obsoleto:"RECUPERANDO",paused:"PAUSADO",completed:"COMPLETADO",cancelled:"CANCELADO",failed:"FALLIDO"};
            function setText(sel,v){const el=root.querySelector(sel);if(el)el.textContent=v;}
            function schedulerText(s){s=s||{};const a=[];a.push(s.action_scheduler_available?(s.action_scheduler_pending?"AS preparado":"AS disponible"):"AS no disponible");a.push(s.wp_cron_disabled?"WP-Cron desactivado":(s.wp_cron_next?"WP-Cron preparado":"WP-Cron sin evento"));return a.join(" · ");}
            function render(d){
                setText("[data-job-status-label]",String(d.status||"").toUpperCase()+" · "+jobTypeLabel(d.job_type)+" / "+modeLabel(d.mode));
                setText("[data-job-percent]",Number(d.progress||0).toLocaleString(undefined,{maximumFractionDigits:1})+"%");
                const bar=root.querySelector("[data-job-progress]");if(bar)bar.style.width=Math.max(0,Math.min(100,Number(d.progress||0)))+"%";
                const age=(d.worker_age_seconds===null||typeof d.worker_age_seconds==="undefined")?"sin heartbeat":String(d.worker_age_seconds)+" s";
                const values={processed:d.processed+" / "+d.total,safe:d.safe,review:d.review,new:d.new,unresolved:d.unresolved,applied:d.applied,cache_hits:d.cache_hits,errors:d.errors,worker:(workerLabels[d.worker_state]||String(d.worker_state||"n/d").toUpperCase())+(d.worker_source?" · "+String(d.worker_source).toUpperCase():""),heartbeat:age,scheduler:schedulerText(d.scheduler),cpu:(d.cpu_percent===null||typeof d.cpu_percent==="undefined")?"n/d":Number(d.cpu_percent).toLocaleString(undefined,{maximumFractionDigits:1})+" %",batch:d.last_batch_rows+" · "+Number(d.last_batch_duration||0).toLocaleString(undefined,{maximumFractionDigits:1})+" s",next:d.next_batch_rows+" · pausa "+d.next_delay+" s",pressure:String(d.pressure||"").toUpperCase()};
                Object.keys(values).forEach(k=>setText("[data-job-kpi=\""+k+"\"]",values[k]));
                if(["completed","cancelled","failed"].includes(d.status)){stopped=true;if(timer)clearTimeout(timer);if(d.status==="completed")setTimeout(()=>window.location.reload(),900);}
            }
            async function post(action,extra){const body=new URLSearchParams(Object.assign({action,nonce,job_id:jobId},extra||{}));const r=await fetch(ajax,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()});return r.json();}
            async function poll(){if(stopped)return;try{const p=await post("seo_classifier_job_status");if(p&&p.success)render(p.data);}catch(e){}finally{if(!stopped)timer=setTimeout(poll,5000);}}
            root.addEventListener("click",async function(e){const b=e.target.closest("[data-job-action]");if(!b)return;b.disabled=true;try{const p=await post("seo_classifier_job_control",{job_action:b.dataset.jobAction});if(p&&p.success){render(p.data);window.location.reload();}}finally{b.disabled=false;}});
            if('.wp_json_encode(in_array((string)$st['status'],['pending','running'],true)).')timer=setTimeout(poll,1200);
        })();</script>';
    }
}

if (!function_exists('seo_assignment_render_product_labels')) {
    function seo_assignment_render_product_labels(array $filters) {
        $total=0;$offset=($filters['page']-1)*$filters['per_page'];$rows=seo_assignment_query_products('product_labels',$filters,$filters['per_page'],$offset,$total);
        $proposal_map = function_exists('seo_classifier_proposals_for_products') ? seo_classifier_proposals_for_products(array_map(static function($r){return (int)$r['ID'];}, (array)$rows), true) : [];
        echo '<div class="seo-tags-count">'.esc_html(number_format_i18n($total)).' anomalías coinciden con el filtro.</div>';
        echo '<div class="notice notice-info inline" style="margin:8px 0 12px"><p><strong>Matriz correctiva:</strong> aquí solo se muestran anomalías filtradas. <strong>Actual</strong> ya estaba guardado; <strong>Propuesta segura/Revisar</strong> procede de un job y aún está pendiente; <strong>Nueva etiqueta</strong> es vocabulario nuevo. <strong>Sin analizar</strong> significa que todavía no existe resultado persistido del Clasificador.</p></div>';
        echo '<div style="overflow:auto"><table class="widefat striped seo-tags-table" style="min-width:1420px"><thead><tr><th style="min-width:260px">Producto</th><th style="min-width:160px">Cobertura / prioridad</th><th>TIPO</th><th>ROL</th><th>APLICACIÓN</th><th>PLATAFORMA</th><th>SUBTIPO</th><th style="min-width:125px">Confirmar</th></tr></thead><tbody>';
        foreach($rows as $row){
            $id=(int)$row['ID'];
            $current=seo_assignment_semantic_current('product',$id);
            $current_labels=seo_assignment_semantic_label_map($current);
            $p=seo_assignment_propose_product_labels($id,$current);
            $priority=seo_assignment_product_label_priority($current);
            $current_count=0; foreach(seo_assignment_allowed_groups() as $g) if(!empty($current[$g])) $current_count++;
            $safe_target=seo_assignment_merge_semantic($current,$p['values']);
            $safe_count=0; foreach(seo_assignment_allowed_groups() as $g) if(!empty($safe_target[$g])) $safe_count++;
            $form_id='seo-assignment-product-labels-'.$id;
            echo '<tr>';
            echo '<td class="seo-tags-product"><strong>#'.$id.' · '.esc_html($row['post_title']).'</strong>'.(!empty($row['sku'])?'<br><small>SKU: '.esc_html($row['sku']).'</small>':'');
            seo_assignment_render_proposal_diagnostics($p);
            echo '</td>';
            echo '<td><strong>'.esc_html($current_count).'/5</strong>' . ($safe_count>$current_count?' <span class="seo-tags-good">→ '.$safe_count.'/5 segura</span>':'') . '<br><span class="seo-tags-state '.($priority['code']==='complete'?'active':'inactive').'">'.esc_html($priority['label']).'</span></td>';
            foreach(['tipo','rol','aplicacion','plataforma','subtipo'] as $group){
                echo '<td>'; seo_assignment_render_product_group_cell($form_id,$group,$current_labels,$p,$id); echo '</td>';
            }
            echo '<td><form id="'.esc_attr($form_id).'" method="post">';
            wp_nonce_field('seo_assignment_admin','seo_assignment_nonce');
            echo '<input type="hidden" name="seo_assignment_action" value="confirm"><input type="hidden" name="assignment_section" value="product_labels"><input type="hidden" name="assignment_group_matrix" value="1"><input type="hidden" name="object_id" value="'.esc_attr($id).'">';
            echo '<button class="button button-primary button-small">Confirmar fila</button>';
            echo '<p class="seo-tags-help" style="margin:6px 0 0">Solo admite valores existentes. No crea vocabulario.</p></form></td>';
            echo '</tr>';
        }
        if(!$rows)echo '<tr><td colspan="8">No hay productos que coincidan con los filtros.</td></tr>';
        echo '</tbody></table></div>';
        seo_assignment_render_pagination('product_labels',$filters,$total);
    }
}

if (!function_exists('seo_assignment_render_product_attributes')) {
    function seo_assignment_render_product_attributes(array $filters) {
        $total=0;$offset=($filters['page']-1)*$filters['per_page'];$rows=seo_assignment_query_products('product_attributes',$filters,$filters['per_page'],$offset,$total);
        echo '<div class="seo-tags-count">'.esc_html(number_format_i18n($total)).' productos coinciden con el filtro.</div><div style="overflow:auto"><table class="widefat striped seo-tags-table"><thead><tr><th>Producto</th><th>Atributos actuales</th><th>Propuesta</th><th>Estado</th><th>JSON / confirmar</th></tr></thead><tbody>';
        foreach($rows as $row){$id=(int)$row['ID'];$current=seo_assignment_attribute_current($id);$p=seo_assignment_attribute_proposal($id,$current);$target=seo_assignment_merge_attributes($current,$p['values']);
            echo '<tr><td class="seo-tags-product"><strong>#'.$id.' · '.esc_html($row['post_title']).'</strong>'.(!empty($row['sku'])?'<br><small>SKU: '.esc_html($row['sku']).'</small>':'').'</td><td>';seo_assignment_render_semantic_compact($current);echo '</td><td>';if($p['viable'])seo_assignment_render_semantic_compact($p['values']);else echo '<span class="seo-tags-muted">Sin propuesta canónica segura</span>';echo '</td><td>'.($current?'<span class="seo-tags-state active">'.count($current).' tipos</span>':'<span class="seo-tags-state inactive">Sin atributos</span>').($p['viable']?'<br><small>Clasificador · propuesta segura</small>':'<br><small>Clasificador · sin propuesta segura</small>').'</td><td>';seo_assignment_render_json_editor('product_attributes',$id,$target,$p['viable']);echo '</td></tr>';}
        if(!$rows)echo '<tr><td colspan="5">No hay productos que coincidan con los filtros.</td></tr>'; echo '</tbody></table></div>'; seo_assignment_render_pagination('product_attributes',$filters,$total);
    }
}

if (!function_exists('seo_assignment_render_category_labels')) {
    function seo_assignment_render_category_labels(array $filters) {
        $total=0;$offset=($filters['page']-1)*$filters['per_page'];$rows=seo_assignment_query_categories($filters,$filters['per_page'],$offset,$total);
        echo '<div class="seo-tags-count">'.esc_html(number_format_i18n($total)).' categorías coinciden con el filtro.</div><div style="overflow:auto"><table class="widefat striped seo-tags-table"><thead><tr><th>Categoría</th><th>Actual</th><th>Propuesta</th><th>Estado</th><th>JSON / confirmar</th></tr></thead><tbody>';
        foreach($rows as $row){$id=(int)$row['term_id'];$current=seo_assignment_semantic_current('product_cat',$id);$current_labels=seo_assignment_semantic_label_map($current);$p=seo_assignment_category_proposal($id,$current);$target=seo_assignment_merge_semantic($current,$p['values']);$missing=[];foreach(['tipo'=>'TIPO','rol'=>'ROL','aplicacion'=>'APLICACIÓN','plataforma'=>'PLATAFORMA','subtipo'=>'SUBTIPO'] as $g=>$l)if(empty($current[$g]))$missing[]=$l;
            echo '<tr><td class="seo-tags-product"><strong>#'.$id.' · '.esc_html($row['name']).'</strong><br><small>'.esc_html((int)$row['count']).' productos directos</small></td><td>';seo_assignment_render_semantic_compact($current_labels);echo '</td><td>';if($p['viable'])seo_assignment_render_semantic_compact($p['values']);else echo '<span class="seo-tags-muted">Sin propuesta existente</span>';echo '</td><td>'.($missing?'<span class="seo-tags-state inactive">Falta '.esc_html(implode(', ',$missing)).'</span>':'<span class="seo-tags-state active">Cobertura completa</span>').($p['viable']?'<br><small>Propuesta reutilizable</small>':'<br><small>Puede requerir vocabulario nuevo</small>').'</td><td>';seo_assignment_render_json_editor('category_labels',$id,$target,$p['viable']);echo '</td></tr>';}
        if(!$rows)echo '<tr><td colspan="5">No hay categorías que coincidan con los filtros.</td></tr>'; echo '</tbody></table></div>'; seo_assignment_render_pagination('category_labels',$filters,$total);
    }
}


if (!function_exists('seo_assignment_example_vocab_label')) {
    /** Devuelve el primer valor activo de un grupo, priorizando etiquetas conocidas. */
    function seo_assignment_example_vocab_label($group, array $preferred = []) {
        $index = seo_assignment_vocab_index();
        $rows = (array) ($index[$group] ?? []);
        foreach ($preferred as $wanted) {
            $wanted_norm = seo_assignment_normalize($wanted);
            foreach ($rows as $row) {
                if (seo_assignment_normalize((string) ($row['label'] ?? '')) === $wanted_norm) {
                    return (string) $row['label'];
                }
            }
        }
        if ($preferred) return '';
        return !empty($rows[0]['label']) ? (string) $rows[0]['label'] : '';
    }
}

if (!function_exists('seo_assignment_example_attribute_payload')) {
    /**
     * Construye un ejemplo con atributos que existen realmente en el maestro.
     * Solo se utiliza como documentación visual; no escribe datos.
     */
    function seo_assignment_example_attribute_payload() {
        global $wpdb;
        $out = [];
        if (!function_exists('seo_attributes_tables')) return $out;
        $tables = seo_attributes_tables();
        if (empty($tables['definitions']) || !seo_tags_vocab_table_exists($tables['definitions'])) return $out;

        $preferred = ['diametro', 'materiales_compatibles', 'material', 'longitud', 'numero_piezas'];
        $defs = $wpdb->get_results(
            "SELECT id,slug,nombre,tipo,unidad_base FROM `{$tables['definitions']}` WHERE activo=1 ORDER BY orden,nombre LIMIT 100",
            ARRAY_A
        );
        $by_slug = [];
        foreach ((array) $defs as $def) $by_slug[(string) ($def['slug'] ?? '')] = $def;
        $ordered = [];
        foreach ($preferred as $slug) if (isset($by_slug[$slug])) $ordered[] = $by_slug[$slug];
        foreach ((array) $defs as $def) {
            if (count($ordered) >= 3) break;
            $slug = (string) ($def['slug'] ?? '');
            if ($slug !== '' && !in_array($slug, array_column($ordered, 'slug'), true)) $ordered[] = $def;
        }

        foreach (array_slice($ordered, 0, 3) as $def) {
            $slug = (string) ($def['slug'] ?? '');
            if ($slug === '') continue;
            $value = '';
            if ((string) ($def['tipo'] ?? '') === 'termino' && !empty($tables['terms']) && seo_tags_vocab_table_exists($tables['terms'])) {
                $value = (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT nombre FROM `{$tables['terms']}` WHERE atributo_id=%d AND activo=1 ORDER BY orden,nombre LIMIT 1",
                    (int) $def['id']
                ));
            } elseif ((string) ($def['tipo'] ?? '') === 'numero' || (string) ($def['tipo'] ?? '') === 'rango') {
                $unit = trim((string) ($def['unidad_base'] ?? ''));
                $value = '8' . ($unit !== '' ? ' ' . $unit : '');
            } elseif ((string) ($def['tipo'] ?? '') === 'boolean') {
                $value = '1';
            } else {
                $value = 'valor de ejemplo';
            }
            if ($value !== '') $out[$slug] = [$value];
        }
        return $out;
    }
}

if (!function_exists('seo_assignment_render_json_sample')) {
    function seo_assignment_render_json_sample($title, $description, array $payload) {
        echo '<div class="seo-tags-panel" style="margin:0">';
        echo '<h3 style="margin-top:0">' . esc_html($title) . '</h3>';
        echo '<p class="seo-tags-help">' . wp_kses_post($description) . '</p>';
        echo '<pre style="margin:12px 0 0;max-height:360px;overflow:auto;padding:14px;background:#1d2327;color:#f6f7f7;border-radius:6px;white-space:pre-wrap">' . esc_html(wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        echo '</div>';
    }
}

if (!function_exists('seo_assignment_render_format_examples')) {
    /** Referencia viva del formato admitido por la mesa de Asignación. */
    function seo_assignment_render_format_examples() {
        $obd_type = seo_assignment_example_vocab_label('tipo', ['HUD/medidor OBD para vehículo', 'Escáner OBD y diagnosis']);
        $obd_app = seo_assignment_example_vocab_label('aplicacion', ['Diagnóstico y electrónica de vehículos', 'Accesorios y funcionalidad del vehículo']);
        $door_type = seo_assignment_example_vocab_label('tipo', ['Herrajes para puertas correderas', 'Puertas y herrajes de acceso', 'Ruedas y sistemas de rodadura']);
        $door_app = seo_assignment_example_vocab_label('aplicacion', ['Cerrajería, herrajes y control de acceso', 'Carpintería']);

        $product_payload = [];
        if ($obd_type !== '') $product_payload['tipo'] = [$obd_type];
        if ($obd_app !== '') $product_payload['aplicacion'] = [$obd_app];

        $door_payload = [];
        if ($door_type !== '') $door_payload['tipo'] = [$door_type];
        if ($door_app !== '') $door_payload['aplicacion'] = [$door_app];

        $attribute_payload = seo_assignment_example_attribute_payload();
        if (!$attribute_payload) $attribute_payload = ['atributo_slug' => ['valor existente o normalizable']];

        // Para categorías mostramos el esquema completo. TIPO y ROL deben ser coherentes.
        $category_payload = [
            'rol' => [],
            'tipo' => [],
            'aplicacion' => [],
            'plataforma' => [],
            'subtipo' => [],
        ];
        $index = seo_assignment_vocab_index();
        foreach ((array) ($index['tipo'] ?? []) as $type_row) {
            $role = seo_assignment_role_from_type((int) ($type_row['id'] ?? 0));
            if ($role && !empty($type_row['label']) && !empty($role['label'])) {
                $category_payload['tipo'] = [(string) $type_row['label']];
                $category_payload['rol'] = [(string) $role['label']];
                break;
            }
        }
        $cat_app = seo_assignment_example_vocab_label('aplicacion', ['Cerrajería, herrajes y control de acceso', 'Corte, perforación y demolición']);
        if ($cat_app !== '') $category_payload['aplicacion'] = [$cat_app];

        echo '<div class="seo-tags-panel">';
        echo '<h2 style="margin-top:0">Formato ideal de asignación</h2>';
        echo '<p>Esta pestaña es una <strong>referencia</strong>: no modifica productos ni categorías. En Etiquetas de productos la mesa usa ahora <strong>un JSON independiente por dimensión</strong>; cada celda contiene una lista como <code>[&quot;Valor&quot;]</code>.</p>';
        echo '<div class="notice notice-info inline"><p><strong>Regla principal:</strong> utiliza nombres visibles del vocabulario, no IDs ni slugs. Si un valor no existe, la confirmación debe rechazarlo; primero habrá que resolverlo en el maestro correspondiente.</p></div>';
        echo '</div>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(390px,1fr));gap:14px;margin:14px 0">';
        seo_assignment_render_json_sample(
            'Etiquetas de producto · ejemplo OBD',
            'Referencia de valores para la matriz. En la pantalla real cada clave se muestra en su propia columna y el contenido editable de la celda es solo su lista, por ejemplo <code>[&quot;HUD/medidor OBD para vehículo&quot;]</code>. ROL se deriva del TIPO.',
            $product_payload
        );
        seo_assignment_render_json_sample(
            'Etiquetas de producto · ejemplo herraje',
            'Ejemplo orientativo para un carro/rueda de puerta corredera. Solo se muestran valores que estén disponibles actualmente en el vocabulario.',
            $door_payload
        );
        seo_assignment_render_json_sample(
            'Atributos de producto',
            'Las claves son <strong>slugs de atributos existentes</strong>. Cada valor puede ser una cadena o una lista. Para atributos de tipo término, el valor debe existir como término o alias; números y unidades se normalizan mediante el servicio canónico.',
            $attribute_payload
        );
        seo_assignment_render_json_sample(
            'Etiquetas de categoría',
            'La clasificación de categorías trabaja como una sustitución completa. Por seguridad, incluye los cinco grupos y conserva explícitamente los valores que quieras mantener. Un grupo no aplicable puede quedar como lista vacía.',
            $category_payload
        );
        echo '</div>';

        echo '<div class="seo-tags-panel"><h3 style="margin-top:0">Reglas rápidas</h3>';
        echo '<table class="widefat striped seo-tags-table"><thead><tr><th>Sección</th><th>Claves</th><th>Regla de escritura</th><th>Qué ocurre con un valor desconocido</th></tr></thead><tbody>';
        echo '<tr><td><strong>Etiquetas de productos</strong></td><td>Una columna por <code>TIPO</code>, <code>ROL</code>, <code>APLICACIÓN</code>, <code>PLATAFORMA</code> y <code>SUBTIPO</code></td><td>Cada celda editable contiene una lista JSON. TIPO = 1 valor. ROL es de solo lectura y se obtiene desde TIPO.</td><td>Se rechaza; no crea vocabulario.</td></tr>';
        echo '<tr><td><strong>Atributos de productos</strong></td><td>Slug del atributo, por ejemplo <code>diametro</code></td><td>Añade/reutiliza valores y no borra los existentes desde esta mesa.</td><td>El servicio canónico lo rechaza si no existe la definición/término requerido.</td></tr>';
        echo '<tr><td><strong>Etiquetas de categorías</strong></td><td><code>rol</code>, <code>tipo</code>, <code>aplicacion</code>, <code>plataforma</code>, <code>subtipo</code></td><td>Reemplaza la clasificación completa de la categoría.</td><td>Se rechaza; no crea vocabulario.</td></tr>';
        echo '</tbody></table>';
        $labels_url = admin_url('admin.php?page=seo-tags-vocabulary&domain=labels&section=dictionary');
        $attributes_url = admin_url('admin.php?page=seo-tags-vocabulary&domain=attributes&attribute_section=definitions');
        echo '<p style="margin-bottom:0"><a class="button" href="' . esc_url($labels_url) . '">Abrir diccionario de etiquetas</a> <a class="button" href="' . esc_url($attributes_url) . '">Abrir maestro de atributos</a></p>';
        echo '</div>';
    }
}

if (!function_exists('seo_assignment_render_pagination')) {
    function seo_assignment_render_pagination($section,array $filters,$total) {
        $pages=max(1,(int)ceil($total/max(1,$filters['per_page']))); if($pages<=1)return; $page=min($pages,max(1,$filters['page']));
        $base=['page'=>'seo-tags-vocabulary','domain'=>'assignment','assignment_section'=>$section,'assignment_filter'=>1,'assignment_s'=>$filters['search'],'assignment_category_id'=>$filters['category_id'],'assignment_coverage'=>$filters['coverage'],'assignment_priority'=>(string)($filters['priority']??'all'),'assignment_per_page'=>$filters['per_page']];
        echo '<div class="seo-tags-pagination">'; if($page>1)echo '<a href="'.esc_url(add_query_arg(array_merge($base,['assignment_paged'=>$page-1]),admin_url('admin.php'))).'">‹</a>';
        for($i=max(1,$page-2);$i<=min($pages,$page+2);$i++){if($i===$page)echo '<span class="current">'.$i.'</span>';else echo '<a href="'.esc_url(add_query_arg(array_merge($base,['assignment_paged'=>$i]),admin_url('admin.php'))).'">'.$i.'</a>';}
        if($page<$pages)echo '<a href="'.esc_url(add_query_arg(array_merge($base,['assignment_paged'=>$page+1]),admin_url('admin.php'))).'">›</a>'; echo '<span>Página '.$page.' / '.$pages.'</span></div>';
    }
}

if (!function_exists('seo_assignment_render')) {
    function seo_assignment_render() {
        $section=sanitize_key($_GET['assignment_section'] ?? 'product_labels'); if(!array_key_exists($section,seo_assignment_sections()))$section='product_labels';
        echo '<div class="seo-semantic-domain-title"><h2 style="margin:0">Asignación asistida</h2><span class="seo-tags-mode">Revisión manual</span></div>';
        echo '<p class="seo-tags-intro">Inventaría huecos de clasificación, propone valores ya existentes y permite confirmar cada fila. Los maestros siguen gestionándose exclusivamente en Etiquetas y Atributos.</p>';
        seo_assignment_notice(); seo_assignment_render_summary();
        $base=admin_url('admin.php?page=seo-tags-vocabulary&domain=assignment'); echo '<nav class="nav-tab-wrapper seo-semantic-subtabs">';
        foreach(seo_assignment_sections() as $key=>$label)echo '<a class="nav-tab '.($section===$key?'nav-tab-active':'').'" href="'.esc_url($base.'&assignment_section='.$key).'">'.esc_html($label).'</a>'; echo '</nav>';
        if ($section === 'format_examples') {
            seo_assignment_render_format_examples();
            return;
        }
        $filters=seo_assignment_current_filters($section);
        seo_assignment_render_filters($section,$filters);
        if ($section === 'product_labels') seo_assignment_render_classifier_job();
        if (empty($filters['submitted'])) {
            echo '<div class="seo-tags-panel"><strong>Sin precarga de productos.</strong><p style="margin-bottom:0">Esta pantalla es correctiva. Elige una anomalía en Cobertura/Prioridad y pulsa <strong>Filtrar</strong>. Los productos completos no se listan aquí; el inventario completo sigue disponible desde Importar / Exportar.</p></div>';
            return;
        }
        seo_assignment_render_mass_actions($section,$filters);
        if($section==='product_attributes')seo_assignment_render_product_attributes($filters); elseif($section==='category_labels')seo_assignment_render_category_labels($filters); else seo_assignment_render_product_labels($filters);
    }
}

if (!function_exists('seo_tags_vocabulary_admin_page')) {
    function seo_tags_vocabulary_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta herramienta.', 'seo-taxonomy'));
        }

        $domain = sanitize_key($_GET['domain'] ?? 'labels');
        if (!in_array($domain, ['labels', 'attributes', 'assignment'], true)) {
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
        echo '<a class="nav-tab ' . ($domain === 'assignment' ? 'nav-tab-active' : '') . '" href="' . esc_url($base . '&domain=assignment&assignment_section=product_labels') . '">Asignación</a>';
        echo '</nav>';

        if ($domain === 'assignment') {
            seo_assignment_render();
        } elseif ($domain === 'labels') {
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
            echo '<p class="seo-tags-intro">Gestiona definiciones, términos controlados, aliases y asignaciones del modelo canónico <code>wp_sql_*</code>.</p>';
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
                seo_semantic_attributes_render_products_inventory();
                echo '<details class="seo-tags-panel" style="margin-top:18px" ' . ((isset($_GET['search_attributes']) || isset($_GET['propose_attributes']) || isset($_POST['save_attributes'])) ? 'open' : '') . '>';
                echo '<summary style="cursor:pointer;font-weight:700">Explorador manual de atributos existente</summary>';
                echo '<p class="seo-tags-help">Se conserva la herramienta anterior de exploración/propuesta para no perder funcionalidad. El inventario superior es la nueva vista de cobertura.</p>';
                search_product_attributes([
                    'render_dashboard' => false,
                    'render_definition_controls' => false,
                ]);
                echo '</details>';
            }
        }

        echo '</div>';
    }
}


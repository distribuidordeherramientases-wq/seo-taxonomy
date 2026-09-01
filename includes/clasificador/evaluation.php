<?php
/**
 * Evaluación reproducible del Clasificador.
 *
 * Oculta una etiqueta canónica ya conocida, vuelve a clasificar el producto y
 * compara la propuesta con el valor real. No persiste cambios.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_evaluation_groups')) {
    function seo_classifier_evaluation_groups() {
        return ['tipo','rol','aplicacion','plataforma','subtipo'];
    }
}

if (!function_exists('seo_classifier_normalized_values')) {
    function seo_classifier_normalized_values(array $values) {
        $out = [];
        foreach ($values as $value) {
            $value = seo_classifier_normalize((string)$value);
            if ($value !== '') $out[$value] = true;
        }
        return array_keys($out);
    }
}

if (!function_exists('seo_classifier_values_intersect')) {
    function seo_classifier_values_intersect(array $left, array $right) {
        return (bool) array_intersect(
            seo_classifier_normalized_values($left),
            seo_classifier_normalized_values($right)
        );
    }
}

if (!function_exists('seo_classifier_evaluation_product_ids')) {
    function seo_classifier_evaluation_product_ids($group, array $args, &$total = 0) {
        global $wpdb;
        $group = sanitize_key((string)$group);
        $limit = max(1, min(500, absint($args['limit'] ?? 50)));
        $offset = max(0, absint($args['offset'] ?? 0));
        $category_id = absint($args['category_id'] ?? 0);
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        if (!seo_classifier_table_exists($vocabulary) || !seo_classifier_table_exists($objects)) {
            $total = 0;
            return [];
        }

        $where = [
            "p.post_type='product'",
            "p.post_status='publish'",
            "ov.object_type='product'",
            'ov.status=1',
            'v.active=1',
            'v.semantic_group=%s',
        ];
        $query_args = [$group];
        if ($category_id > 0) {
            $where[] = "EXISTS (
                SELECT 1
                FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->term_taxonomy} tt
                  ON tt.term_taxonomy_id=tr.term_taxonomy_id
                 AND tt.taxonomy='product_cat'
                WHERE tr.object_id=p.ID AND tt.term_id=%d
            )";
            $query_args[] = $category_id;
        }
        $where_sql = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            JOIN {$objects} ov ON ov.object_id=p.ID
            JOIN {$vocabulary} v ON v.id=ov.vocabulary_id
            WHERE {$where_sql}";
        $total = (int)$wpdb->get_var($wpdb->prepare($count_sql, ...$query_args));

        $data_args = $query_args;
        $data_args[] = $limit;
        $data_args[] = $offset;
        $sql = "SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            JOIN {$objects} ov ON ov.object_id=p.ID
            JOIN {$vocabulary} v ON v.id=ov.vocabulary_id
            WHERE {$where_sql}
            ORDER BY p.ID DESC
            LIMIT %d OFFSET %d";
        return array_values(array_map('absint', (array)$wpdb->get_col($wpdb->prepare($sql, ...$data_args))));
    }
}

if (!function_exists('seo_classifier_evaluate_product_group')) {
    /**
     * @param string $group TIPO/ROL/APLICACIÓN/PLATAFORMA/SUBTIPO.
     * @param array  $args  limit, offset, category_id, examples_limit.
     */
    function seo_classifier_evaluate_product_group($group, array $args = []) {
        $group = sanitize_key((string)$group);
        if (!in_array($group, seo_classifier_evaluation_groups(), true)) {
            return new WP_Error('seo_classifier_invalid_group', 'Grupo de evaluación no válido.');
        }

        $total = 0;
        $ids = seo_classifier_evaluation_product_ids($group, $args, $total);
        $examples_limit = max(0, min(100, absint($args['examples_limit'] ?? 25)));
        $metrics = [
            'evaluated'=>0,
            'safe_proposed'=>0,
            'safe_correct'=>0,
            'review_proposed'=>0,
            'review_correct'=>0,
            'top1_proposed'=>0,
            'top1_correct'=>0,
            'unresolved'=>0,
        ];
        $examples = [];

        foreach ($ids as $product_id) {
            $current = seo_classifier_current_object_labels('product', $product_id);
            $actual_map = seo_classifier_label_map([$group=>(array)($current[$group] ?? [])]);
            $actual = (array)($actual_map[$group] ?? []);
            if (!$actual) continue;

            $masked = $current;
            $masked[$group] = [];
            if ($group === 'tipo') {
                // Evita que el ROL materializado revele indirectamente la identidad.
                $masked['rol'] = [];
            }
            $context = seo_classifier_build_product_context($product_id, ['queue_external'=>false]);
            $result = seo_classifier_classify_product_labels($product_id, $masked, [
                'context'=>$context,
                'queue_external'=>false,
                'exclude_product_id'=>$product_id,
                'evaluation'=>true,
            ]);

            $safe = (array)($result['values'][$group] ?? []);
            $review = (array)($result['review'][$group] ?? []);
            $candidates = (array)($result['groups'][$group]['candidates'] ?? []);
            $top = $safe ?: ($review ?: (!empty($candidates[0]['label']) ? [(string)$candidates[0]['label']] : []));
            $safe_correct = $safe && seo_classifier_values_intersect($safe, $actual);
            $review_correct = $review && seo_classifier_values_intersect($review, $actual);
            $top_correct = $top && seo_classifier_values_intersect($top, $actual);

            $metrics['evaluated']++;
            if ($safe) {
                $metrics['safe_proposed']++;
                if ($safe_correct) $metrics['safe_correct']++;
            }
            if ($review) {
                $metrics['review_proposed']++;
                if ($review_correct) $metrics['review_correct']++;
            }
            if ($top) {
                $metrics['top1_proposed']++;
                if ($top_correct) $metrics['top1_correct']++;
            } else {
                $metrics['unresolved']++;
            }

            if (count($examples) < $examples_limit && (!$safe_correct || !$safe)) {
                $post = get_post($product_id);
                $examples[] = [
                    'product_id'=>$product_id,
                    'title'=>$post ? (string)$post->post_title : '',
                    'actual'=>$actual,
                    'safe'=>$safe,
                    'review'=>$review,
                    'top'=>$top,
                    'top_correct'=>(bool)$top_correct,
                    'candidates'=>array_slice($candidates, 0, 3),
                ];
            }
        }

        $evaluated = (int)$metrics['evaluated'];
        $safe_proposed = (int)$metrics['safe_proposed'];
        $review_proposed = (int)$metrics['review_proposed'];
        $report = [
            'schema'=>'seo-taxonomy-classifier-evaluation',
            'schema_version'=>1,
            'classifier_version'=>function_exists('seo_classifier_version') ? seo_classifier_version() : '',
            'profiles_version'=>function_exists('seo_classifier_profiles_version') ? seo_classifier_profiles_version() : '',
            'group'=>$group,
            'catalog_total'=>$total,
            'sample'=>[
                'limit'=>max(1, min(500, absint($args['limit'] ?? 50))),
                'offset'=>max(0, absint($args['offset'] ?? 0)),
                'category_id'=>absint($args['category_id'] ?? 0),
            ],
            'metrics'=>$metrics + [
                'safe_precision'=>$safe_proposed > 0 ? round($metrics['safe_correct'] / $safe_proposed, 4) : null,
                'safe_coverage'=>$evaluated > 0 ? round($metrics['safe_proposed'] / $evaluated, 4) : null,
                'review_precision'=>$review_proposed > 0 ? round($metrics['review_correct'] / $review_proposed, 4) : null,
                'top1_accuracy'=>$evaluated > 0 ? round($metrics['top1_correct'] / $evaluated, 4) : null,
                'proposal_coverage'=>$evaluated > 0 ? round($metrics['top1_proposed'] / $evaluated, 4) : null,
            ],
            'examples'=>$examples,
            'notes'=>[
                'No modifica productos ni vocabulario.',
                'La etiqueta evaluada se oculta antes de clasificar.',
                'El producto se excluye de los perfiles aprendidos usados en su propia evaluación.',
                'No realiza lecturas externas durante la evaluación; solo usa caché ya disponible.',
            ],
        ];
        return apply_filters('seo_classifier_evaluation_report', $report, $group, $args);
    }
}

if (!function_exists('seo_classifier_cli_evaluate')) {
    function seo_classifier_cli_evaluate($args, $assoc_args) {
        $group = sanitize_key((string)($assoc_args['group'] ?? ($args[0] ?? 'aplicacion')));
        $report = seo_classifier_evaluate_product_group($group, [
            'limit'=>absint($assoc_args['limit'] ?? 50),
            'offset'=>absint($assoc_args['offset'] ?? 0),
            'category_id'=>absint($assoc_args['category'] ?? 0),
            'examples_limit'=>absint($assoc_args['examples'] ?? 25),
        ]);
        if (is_wp_error($report)) WP_CLI::error($report->get_error_message());
        WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('seo_classifier_cli_classify')) {
    function seo_classifier_cli_classify($args, $assoc_args) {
        $product_id = absint($args[0] ?? 0);
        if ($product_id < 1) WP_CLI::error('Indica un ID de producto válido.');
        $result = seo_classifier_classify_product($product_id, [
            'queue_external'=>empty($assoc_args['no-external']),
            'refresh_external'=>!empty($assoc_args['refresh-source']),
        ]);
        WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('seo_classifier_cli_refresh_source')) {
    function seo_classifier_cli_refresh_source($args, $assoc_args) {
        $product_id = absint($args[0] ?? 0);
        if ($product_id < 1) WP_CLI::error('Indica un ID de producto válido.');
        $result = seo_classifier_refresh_external_context($product_id);
        WP_CLI::line(wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    WP_CLI::add_command('seo-classifier evaluate', 'seo_classifier_cli_evaluate');
    WP_CLI::add_command('seo-classifier classify', 'seo_classifier_cli_classify');
    WP_CLI::add_command('seo-classifier refresh-source', 'seo_classifier_cli_refresh_source');
}

<?php
/**
 * Aprendizaje estadístico de las asignaciones canónicas existentes.
 *
 * Convierte el catálogo ya revisado en señales de clasificación: distribución
 * TIPO -> APLICACIÓN/SUBTIPO/PLATAFORMA y distribución de cada categoría.
 * Solo lee datos y cachea agregados; nunca escribe asignaciones.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_profiles_version')) {
    function seo_classifier_profiles_version() {
        return '2.0.0';
    }
}

if (!function_exists('seo_classifier_profile_ttl')) {
    function seo_classifier_profile_ttl() {
        return max(15 * MINUTE_IN_SECONDS, (int) apply_filters('seo_classifier_profile_ttl', HOUR_IN_SECONDS));
    }
}

if (!function_exists('seo_classifier_profiles_generation')) {
    function seo_classifier_profiles_generation() {
        return max(1, absint(get_option('seo_classifier_profiles_generation', 1)));
    }
}

if (!function_exists('seo_classifier_bump_profiles_generation')) {
    function seo_classifier_bump_profiles_generation() {
        $generation = seo_classifier_profiles_generation() + 1;
        update_option('seo_classifier_profiles_generation', $generation, false);
        do_action('seo_classifier_profiles_invalidated', $generation);
        return $generation;
    }
}

if (!function_exists('seo_classifier_sql_placeholders')) {
    function seo_classifier_sql_placeholders(array $values, $placeholder = '%d') {
        return implode(',', array_fill(0, count($values), $placeholder));
    }
}

if (!function_exists('seo_classifier_profile_cache_key')) {
    function seo_classifier_profile_cache_key($kind, array $ids, $group, $exclude_product_id = 0) {
        sort($ids, SORT_NUMERIC);
        return 'seo_cl_prof_' . md5(implode('|', [
            seo_classifier_profiles_version(),
            seo_classifier_profiles_generation(),
            sanitize_key((string) $kind),
            implode(',', $ids),
            sanitize_key((string) $group),
            absint($exclude_product_id),
        ]));
    }
}

if (!function_exists('seo_classifier_vocabulary_term_by_id')) {
    function seo_classifier_vocabulary_term_by_id($group, $term_id) {
        static $cache = [];
        $group = sanitize_key((string) $group);
        $term_id = absint($term_id);
        $key = $group . ':' . $term_id;
        if (array_key_exists($key, $cache)) return $cache[$key];
        foreach ((array) (seo_classifier_vocabulary_index()[$group] ?? []) as $row) {
            if ((int) ($row['id'] ?? 0) === $term_id) return $cache[$key] = $row;
        }
        return $cache[$key] = null;
    }
}

if (!function_exists('seo_classifier_profile_score')) {
    /**
     * Puntúa dominancia, cobertura y soporte. Un 100% basado en un solo ejemplo
     * no puede considerarse seguro; una relación repetida sí.
     */
    function seo_classifier_profile_score($share, $coverage, $hits, $avg_confidence = 1.0) {
        $share = max(0.0, min(1.0, (float) $share));
        $coverage = max(0.0, min(1.0, (float) $coverage));
        $hits = max(0, (int) $hits);
        $avg_confidence = max(0.0, min(1.0, (float) $avg_confidence));
        $support = min(1.0, log(1 + $hits) / log(26));
        $score = (0.48 * $share) + (0.22 * $coverage) + (0.22 * $support) + (0.08 * $avg_confidence);
        return round(max(0.0, min(1.0, $score)), 4);
    }
}

if (!function_exists('seo_classifier_profile_mark_dominance')) {
    function seo_classifier_profile_mark_dominance(array $rows, $source) {
        usort($rows, static function($a, $b) {
            $as = (float) ($a['score'] ?? 0);
            $bs = (float) ($b['score'] ?? 0);
            if ($as !== $bs) return $as > $bs ? -1 : 1;
            return (int) ($b['hits'] ?? 0) <=> (int) ($a['hits'] ?? 0);
        });
        $second = (float) ($rows[1]['score'] ?? 0.0);
        foreach ($rows as $index => &$row) {
            $margin = max(0.0, (float) ($row['score'] ?? 0) - ($index === 0 ? $second : 0.0));
            $row['margin'] = round($margin, 4);
            $row['source'] = $source;
            $row['profile_safe'] = $index === 0
                && (int) ($row['hits'] ?? 0) >= 5
                && (float) ($row['share'] ?? 0) >= 0.80
                && (float) ($row['coverage'] ?? 0) >= 0.35
                && $margin >= 0.08;
            $row['profile_review'] = $index === 0
                && (int) ($row['hits'] ?? 0) >= 3
                && (float) ($row['share'] ?? 0) >= 0.55;
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('seo_classifier_type_profile_candidates')) {
    function seo_classifier_type_profile_candidates(array $type_ids, $target_group, $exclude_product_id = 0) {
        static $request_cache = [];
        $type_ids = array_values(array_unique(array_filter(array_map('absint', $type_ids))));
        $target_group = sanitize_key((string) $target_group);
        $exclude_product_id = absint($exclude_product_id);
        if (!$type_ids || !in_array($target_group, seo_classifier_allowed_label_groups(), true) || $target_group === 'tipo') return [];

        $request_key = implode(',', $type_ids) . '|' . $target_group . '|' . $exclude_product_id;
        if (isset($request_cache[$request_key])) return $request_cache[$request_key];
        $transient_key = seo_classifier_profile_cache_key('type', $type_ids, $target_group, $exclude_product_id);
        if ($exclude_product_id < 1) {
            $cached = get_transient($transient_key);
            if (is_array($cached)) return $request_cache[$request_key] = $cached;
        }

        global $wpdb;
        $v = $wpdb->prefix . 'seo_vocabulary';
        $o = $wpdb->prefix . 'seo_object_vocabulary';
        if (!seo_classifier_table_exists($v) || !seo_classifier_table_exists($o)) return [];
        $ph = seo_classifier_sql_placeholders($type_ids);
        $exclude_sql = $exclude_product_id > 0 ? ' AND os.object_id<>%d' : '';
        $base_args = $type_ids;
        if ($exclude_product_id > 0) $base_args[] = $exclude_product_id;

        $source_sql = "SELECT COUNT(DISTINCT os.object_id)
            FROM {$o} os
            JOIN {$v} vs ON vs.id=os.vocabulary_id AND vs.active=1 AND vs.semantic_group='tipo'
            JOIN {$wpdb->posts} p ON p.ID=os.object_id AND p.post_type='product' AND p.post_status='publish'
            WHERE os.object_type='product' AND os.status=1 AND vs.id IN ({$ph}){$exclude_sql}";
        $source_total = (int) $wpdb->get_var($wpdb->prepare($source_sql, ...$base_args));
        if ($source_total < 1) return [];

        $labelled_sql = "SELECT COUNT(DISTINCT os.object_id)
            FROM {$o} os
            JOIN {$v} vs ON vs.id=os.vocabulary_id AND vs.active=1 AND vs.semantic_group='tipo'
            JOIN {$wpdb->posts} p ON p.ID=os.object_id AND p.post_type='product' AND p.post_status='publish'
            JOIN {$o} ot ON ot.object_type='product' AND ot.object_id=os.object_id AND ot.status=1
            JOIN {$v} vt ON vt.id=ot.vocabulary_id AND vt.active=1 AND vt.semantic_group=%s
            WHERE os.object_type='product' AND os.status=1 AND vs.id IN ({$ph}){$exclude_sql}";
        $labelled_args = array_merge([$target_group], $type_ids);
        if ($exclude_product_id > 0) $labelled_args[] = $exclude_product_id;
        $labelled_total = (int) $wpdb->get_var($wpdb->prepare($labelled_sql, ...$labelled_args));
        if ($labelled_total < 1) return [];

        $hits_sql = "SELECT vt.id,vt.slug,vt.label,COUNT(DISTINCT os.object_id) AS hits,AVG(ot.confidence) AS avg_confidence
            FROM {$o} os
            JOIN {$v} vs ON vs.id=os.vocabulary_id AND vs.active=1 AND vs.semantic_group='tipo'
            JOIN {$wpdb->posts} p ON p.ID=os.object_id AND p.post_type='product' AND p.post_status='publish'
            JOIN {$o} ot ON ot.object_type='product' AND ot.object_id=os.object_id AND ot.status=1
            JOIN {$v} vt ON vt.id=ot.vocabulary_id AND vt.active=1 AND vt.semantic_group=%s
            WHERE os.object_type='product' AND os.status=1 AND vs.id IN ({$ph}){$exclude_sql}
            GROUP BY vt.id,vt.slug,vt.label";
        $hits_args = array_merge([$target_group], $type_ids);
        if ($exclude_product_id > 0) $hits_args[] = $exclude_product_id;
        $db_rows = $wpdb->get_results($wpdb->prepare($hits_sql, ...$hits_args), ARRAY_A);

        $coverage = $labelled_total / max(1, $source_total);
        $rows = [];
        foreach ((array) $db_rows as $row) {
            $hits = (int) ($row['hits'] ?? 0);
            $share = $hits / max(1, $labelled_total);
            $avg_confidence = (float) ($row['avg_confidence'] ?? 1.0);
            $term = seo_classifier_vocabulary_term_by_id($target_group, (int) ($row['id'] ?? 0));
            if (!$term) continue;
            $rows[] = [
                'term'=>$term,
                'score'=>seo_classifier_profile_score($share, $coverage, $hits, $avg_confidence),
                'share'=>round($share, 4),
                'coverage'=>round($coverage, 4),
                'hits'=>$hits,
                'labelled_total'=>$labelled_total,
                'source_total'=>$source_total,
                'avg_confidence'=>round($avg_confidence, 4),
                'matched'=>0,
                'title_matched'=>0,
                'run'=>0,
                'reasons'=>[
                    sprintf('patrón del TIPO: %d%% (%d/%d; cobertura %d%%)', round($share * 100), $hits, $labelled_total, round($coverage * 100)),
                ],
            ];
        }
        $rows = seo_classifier_profile_mark_dominance($rows, 'type_profile');
        if ($exclude_product_id < 1) set_transient($transient_key, $rows, seo_classifier_profile_ttl());
        return $request_cache[$request_key] = $rows;
    }
}

if (!function_exists('seo_classifier_category_profile_candidates')) {
    function seo_classifier_category_profile_candidates(array $category_ids, $target_group, $exclude_product_id = 0) {
        static $request_cache = [];
        $category_ids = array_values(array_unique(array_filter(array_map('absint', $category_ids))));
        $target_group = sanitize_key((string) $target_group);
        $exclude_product_id = absint($exclude_product_id);
        if (!$category_ids || !in_array($target_group, seo_classifier_allowed_label_groups(), true)) return [];

        $request_key = implode(',', $category_ids) . '|' . $target_group . '|' . $exclude_product_id;
        if (isset($request_cache[$request_key])) return $request_cache[$request_key];
        $transient_key = seo_classifier_profile_cache_key('category', $category_ids, $target_group, $exclude_product_id);
        if ($exclude_product_id < 1) {
            $cached = get_transient($transient_key);
            if (is_array($cached)) return $request_cache[$request_key] = $cached;
        }

        global $wpdb;
        $v = $wpdb->prefix . 'seo_vocabulary';
        $o = $wpdb->prefix . 'seo_object_vocabulary';
        if (!seo_classifier_table_exists($v) || !seo_classifier_table_exists($o)) return [];
        $ph = seo_classifier_sql_placeholders($category_ids);
        $exclude_sql = $exclude_product_id > 0 ? ' AND p.ID<>%d' : '';

        $count_args = $category_ids;
        if ($exclude_product_id > 0) $count_args[] = $exclude_product_id;
        $source_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.term_id AS category_id,COUNT(DISTINCT p.ID) AS source_total
             FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
             JOIN {$wpdb->posts} p ON p.ID=tr.object_id AND p.post_type='product' AND p.post_status='publish'
             WHERE tt.term_id IN ({$ph}){$exclude_sql}
             GROUP BY tt.term_id",
            ...$count_args
        ), ARRAY_A);
        $source_totals = [];
        foreach ((array) $source_rows as $row) $source_totals[(int)$row['category_id']] = (int)$row['source_total'];

        $label_args = array_merge([$target_group], $category_ids);
        if ($exclude_product_id > 0) $label_args[] = $exclude_product_id;
        $label_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.term_id AS category_id,COUNT(DISTINCT p.ID) AS labelled_total
             FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
             JOIN {$wpdb->posts} p ON p.ID=tr.object_id AND p.post_type='product' AND p.post_status='publish'
             JOIN {$o} ot ON ot.object_type='product' AND ot.object_id=p.ID AND ot.status=1
             JOIN {$v} vt ON vt.id=ot.vocabulary_id AND vt.active=1 AND vt.semantic_group=%s
             WHERE tt.term_id IN ({$ph}){$exclude_sql}
             GROUP BY tt.term_id",
            ...$label_args
        ), ARRAY_A);
        $labelled_totals = [];
        foreach ((array) $label_rows as $row) $labelled_totals[(int)$row['category_id']] = (int)$row['labelled_total'];

        $hits_args = array_merge([$target_group], $category_ids);
        if ($exclude_product_id > 0) $hits_args[] = $exclude_product_id;
        $hit_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.term_id AS category_id,vt.id,vt.slug,vt.label,COUNT(DISTINCT p.ID) AS hits,AVG(ot.confidence) AS avg_confidence
             FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
             JOIN {$wpdb->posts} p ON p.ID=tr.object_id AND p.post_type='product' AND p.post_status='publish'
             JOIN {$o} ot ON ot.object_type='product' AND ot.object_id=p.ID AND ot.status=1
             JOIN {$v} vt ON vt.id=ot.vocabulary_id AND vt.active=1 AND vt.semantic_group=%s
             WHERE tt.term_id IN ({$ph}){$exclude_sql}
             GROUP BY tt.term_id,vt.id,vt.slug,vt.label",
            ...$hits_args
        ), ARRAY_A);

        $by_category = [];
        foreach ((array) $hit_rows as $row) {
            $category_id = (int) ($row['category_id'] ?? 0);
            $source_total = (int) ($source_totals[$category_id] ?? 0);
            $labelled_total = (int) ($labelled_totals[$category_id] ?? 0);
            if ($source_total < 1 || $labelled_total < 1) continue;
            $hits = (int) ($row['hits'] ?? 0);
            $share = $hits / max(1, $labelled_total);
            $coverage = $labelled_total / max(1, $source_total);
            $avg_confidence = (float) ($row['avg_confidence'] ?? 1.0);
            $term = seo_classifier_vocabulary_term_by_id($target_group, (int) ($row['id'] ?? 0));
            if (!$term) continue;
            $term_obj = get_term($category_id, 'product_cat');
            $category_name = ($term_obj && !is_wp_error($term_obj)) ? (string) $term_obj->name : ('#' . $category_id);
            $by_category[$category_id][] = [
                'term'=>$term,
                'score'=>seo_classifier_profile_score($share, $coverage, $hits, $avg_confidence),
                'share'=>round($share, 4),
                'coverage'=>round($coverage, 4),
                'hits'=>$hits,
                'labelled_total'=>$labelled_total,
                'source_total'=>$source_total,
                'avg_confidence'=>round($avg_confidence, 4),
                'category_id'=>$category_id,
                'category_name'=>$category_name,
                'matched'=>0,
                'title_matched'=>0,
                'run'=>0,
                'reasons'=>[
                    sprintf('patrón de categoría «%s»: %d%% (%d/%d; cobertura %d%%)', $category_name, round($share * 100), $hits, $labelled_total, round($coverage * 100)),
                ],
            ];
        }

        $aggregate = [];
        foreach ($by_category as $category_id => $rows) {
            $rows = seo_classifier_profile_mark_dominance($rows, 'category_profile');
            foreach ($rows as $row) {
                $term_id = (int) ($row['term']['id'] ?? 0);
                if ($term_id < 1) continue;
                if (!isset($aggregate[$term_id])) {
                    $aggregate[$term_id] = $row + ['category_signals'=>[], 'weight_sum'=>0.0, 'weighted_score'=>0.0];
                    $aggregate[$term_id]['reasons'] = [];
                    $aggregate[$term_id]['profile_safe'] = false;
                    $aggregate[$term_id]['profile_review'] = false;
                }
                $weight = min(3.0, 0.5 + log(1 + (int)($row['labelled_total'] ?? 0)));
                $aggregate[$term_id]['weight_sum'] += $weight;
                $aggregate[$term_id]['weighted_score'] += $weight * (float)($row['score'] ?? 0);
                $aggregate[$term_id]['score'] = max((float)$aggregate[$term_id]['score'], (float)($row['score'] ?? 0));
                $aggregate[$term_id]['hits'] = max((int)$aggregate[$term_id]['hits'], (int)($row['hits'] ?? 0));
                $aggregate[$term_id]['profile_safe'] = !empty($aggregate[$term_id]['profile_safe']) || !empty($row['profile_safe']);
                $aggregate[$term_id]['profile_review'] = !empty($aggregate[$term_id]['profile_review']) || !empty($row['profile_review']);
                $aggregate[$term_id]['category_signals'][] = $row;
                $aggregate[$term_id]['reasons'] = array_merge((array)$aggregate[$term_id]['reasons'], (array)$row['reasons']);
            }
        }

        $rows = [];
        foreach ($aggregate as $row) {
            $average = $row['weight_sum'] > 0 ? $row['weighted_score'] / $row['weight_sum'] : 0.0;
            $agreement = count((array)$row['category_signals']) > 1 ? min(0.06, 0.02 * (count($row['category_signals']) - 1)) : 0.0;
            $row['score'] = round(min(1.0, max((float)$row['score'], $average + $agreement)), 4);
            $row['source'] = 'category_profile';
            $row['matched'] = 0;
            $row['title_matched'] = 0;
            $row['run'] = 0;
            $row['reasons'] = array_values(array_unique(array_slice((array)$row['reasons'], 0, 4)));
            unset($row['weight_sum'], $row['weighted_score']);
            $rows[] = $row;
        }
        usort($rows, static function($a, $b) {
            return (float)($b['score'] ?? 0) <=> (float)($a['score'] ?? 0);
        });
        if ($rows) {
            $second = (float)($rows[1]['score'] ?? 0);
            $rows[0]['margin'] = round(max(0, (float)$rows[0]['score'] - $second), 4);
            if ((float)$rows[0]['margin'] < 0.06) $rows[0]['profile_safe'] = false;
        }
        if ($exclude_product_id < 1) set_transient($transient_key, $rows, seo_classifier_profile_ttl());
        return $request_cache[$request_key] = $rows;
    }
}

if (!function_exists('seo_classifier_profile_candidates')) {
    function seo_classifier_profile_candidates($group, array $context, array $current, array $effective_type_ids = [], array $options = []) {
        $group = sanitize_key((string) $group);
        $exclude_product_id = absint($options['exclude_product_id'] ?? 0);
        $category_ids = array_values(array_unique(array_filter(array_map('absint', (array)($context['category_ids'] ?? [])))));
        $rows = [];
        if ($group === 'tipo') {
            $rows = seo_classifier_category_profile_candidates($category_ids, 'tipo', $exclude_product_id);
        } else {
            if (!$effective_type_ids) {
                foreach ((array)($current['tipo'] ?? []) as $row) {
                    $id = is_array($row) ? absint($row['id'] ?? 0) : 0;
                    if ($id > 0) $effective_type_ids[] = $id;
                }
            }
            if ($effective_type_ids) $rows = array_merge($rows, seo_classifier_type_profile_candidates($effective_type_ids, $group, $exclude_product_id));
            if ($category_ids) $rows = array_merge($rows, seo_classifier_category_profile_candidates($category_ids, $group, $exclude_product_id));
        }
        return $rows;
    }
}

if (!function_exists('seo_classifier_fuse_label_candidates')) {
    /**
     * Fusiona texto, relaciones por TIPO y consenso de categoría.
     */
    function seo_classifier_fuse_label_candidates($group, array $lexical_rows, array $profile_rows, $limit = 5) {
        $map = [];
        foreach ($lexical_rows as $row) {
            $term_id = (int) ($row['term']['id'] ?? 0);
            if ($term_id < 1) continue;
            $map[$term_id] = $row;
            $map[$term_id]['source_scores'] = ['lexical'=>(float)($row['score'] ?? 0)];
            $map[$term_id]['signal_count'] = 1;
            $map[$term_id]['profile_safe'] = false;
            $map[$term_id]['profile_review'] = false;
        }
        foreach ($profile_rows as $row) {
            $term_id = (int) ($row['term']['id'] ?? 0);
            if ($term_id < 1) continue;
            $source = (string) ($row['source'] ?? 'profile');
            if (!isset($map[$term_id])) {
                $map[$term_id] = [
                    'term'=>$row['term'], 'score'=>0.0, 'matched'=>0, 'title_matched'=>0, 'run'=>0,
                    'reasons'=>[], 'source_scores'=>[], 'signal_count'=>0,
                    'profile_safe'=>false, 'profile_review'=>false,
                ];
            }
            $map[$term_id]['source_scores'][$source] = max(
                (float)($map[$term_id]['source_scores'][$source] ?? 0),
                (float)($row['score'] ?? 0)
            );
            $map[$term_id]['signal_count'] = count(array_filter($map[$term_id]['source_scores'], static function($v){ return (float)$v > 0; }));
            $map[$term_id]['profile_safe'] = !empty($map[$term_id]['profile_safe']) || !empty($row['profile_safe']);
            $map[$term_id]['profile_review'] = !empty($map[$term_id]['profile_review']) || !empty($row['profile_review']);
            $map[$term_id]['reasons'] = array_merge((array)$map[$term_id]['reasons'], (array)($row['reasons'] ?? []));
            foreach (['share','coverage','hits','labelled_total','source_total','margin'] as $field) {
                if (isset($row[$field])) $map[$term_id][$source . '_' . $field] = $row[$field];
            }
        }

        $weights = [
            'tipo'=>['lexical'=>0.72,'category_profile'=>0.28,'type_profile'=>0.0],
            'aplicacion'=>['lexical'=>0.30,'type_profile'=>0.47,'category_profile'=>0.23],
            'subtipo'=>['lexical'=>0.52,'type_profile'=>0.28,'category_profile'=>0.20],
            'plataforma'=>['lexical'=>0.68,'type_profile'=>0.17,'category_profile'=>0.15],
        ];
        $group_weights = $weights[$group] ?? ['lexical'=>0.60,'type_profile'=>0.25,'category_profile'=>0.15];

        $rows = [];
        foreach ($map as $row) {
            $scores = (array)($row['source_scores'] ?? []);
            $weighted = 0.0;
            $denom = 0.0;
            foreach ($scores as $source=>$score) {
                $weight = (float)($group_weights[$source] ?? 0.12);
                if ($score <= 0 || $weight <= 0) continue;
                $weighted += $weight * $score;
                $denom += $weight;
            }
            $average = $denom > 0 ? $weighted / $denom : 0.0;
            $max_signal = $scores ? max(array_map('floatval', $scores)) : 0.0;
            $agreement = count(array_filter($scores, static function($v){ return (float)$v >= 0.55; })) >= 2 ? 0.055 : 0.0;
            if (count(array_filter($scores, static function($v){ return (float)$v >= 0.70; })) >= 3) $agreement += 0.035;
            $row['score'] = round(min(1.0, max($max_signal * 0.98, $average + $agreement)), 4);
            $row['matched'] = max((int)($row['matched'] ?? 0), !empty($row['profile_safe']) ? 2 : (int)($row['signal_count'] ?? 0));
            $row['reasons'] = array_values(array_unique(array_slice((array)$row['reasons'], 0, 7)));
            $rows[] = $row;
        }
        usort($rows, static function($a, $b) {
            $as = (float)($a['score'] ?? 0);
            $bs = (float)($b['score'] ?? 0);
            if ($as !== $bs) return $as > $bs ? -1 : 1;
            return (int)($b['signal_count'] ?? 0) <=> (int)($a['signal_count'] ?? 0);
        });
        return array_slice($rows, 0, max(1, (int)$limit));
    }
}

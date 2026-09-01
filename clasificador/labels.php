<?php
/**
 * Clasificación semántica de productos contra vocabulario canónico existente.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_candidate_public_row')) {
    function seo_classifier_candidate_public_row(array $row) {
        $public = [
            'id'=>(int)($row['term']['id'] ?? 0),
            'slug'=>(string)($row['term']['slug'] ?? ''),
            'label'=>(string)($row['term']['label'] ?? ''),
            'score'=>round((float)($row['score'] ?? 0), 2),
            'matched'=>(int)($row['matched'] ?? 0),
            'signal_count'=>(int)($row['signal_count'] ?? 0),
            'profile_safe'=>!empty($row['profile_safe']),
            'reasons'=>(array)($row['reasons'] ?? []),
        ];
        if (!empty($row['source_scores'])) $public['source_scores'] = $row['source_scores'];
        foreach (['type_profile_share','type_profile_hits','type_profile_coverage','category_profile_share','category_profile_hits','category_profile_coverage'] as $field) {
            if (isset($row[$field])) $public[$field] = $row[$field];
        }
        return $public;
    }
}

if (!function_exists('seo_classifier_candidate_labels')) {
    function seo_classifier_candidate_labels(array $rows) {
        $labels = [];
        foreach ($rows as $row) {
            $label = trim((string)($row['term']['label'] ?? ''));
            if ($label !== '') $labels[] = $label;
        }
        return array_values(array_unique($labels));
    }
}

if (!function_exists('seo_classifier_candidate_ids')) {
    function seo_classifier_candidate_ids(array $rows) {
        $ids = [];
        foreach ($rows as $row) {
            $id = absint($row['term']['id'] ?? 0);
            if ($id > 0) $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('seo_classifier_classify_product_labels')) {
    function seo_classifier_classify_product_labels($product_id, $current = null, array $options = []) {
        $product_id = absint($product_id);
        $current = is_array($current) ? $current : seo_classifier_current_object_labels('product', $product_id);
        $context = !empty($options['context']) && is_array($options['context'])
            ? $options['context']
            : seo_classifier_build_product_context($product_id, $options);

        $proposal = [];
        $review = [];
        $confidence = [];
        $proposed_ids = [];
        $evidence = [];
        $groups = [];

        // La identidad se resuelve primero y alimenta los perfiles posteriores.
        $ordered_groups = ['tipo','aplicacion','subtipo','plataforma'];
        $effective_type_ids = [];
        foreach ((array)($current['tipo'] ?? []) as $row) {
            $id = is_array($row) ? absint($row['id'] ?? 0) : 0;
            if ($id > 0) $effective_type_ids[] = $id;
        }

        foreach ($ordered_groups as $group) {
            $current_labels = seo_classifier_label_map([$group => (array)($current[$group] ?? [])]);
            $ranked = seo_classifier_rank_product_label_group($group, $context, $current, $effective_type_ids, $options, 7);
            $selection = function_exists('seo_classifier_select_label_candidates')
                ? seo_classifier_select_label_candidates($group, $ranked)
                : ['state'=>'unresolved','safe'=>[],'review'=>[],'candidate'=>null,'margin'=>0.0];
            $top = $selection['candidate'];
            $state = !empty($current[$group]) ? 'current' : (string)$selection['state'];
            $safe_rows = !empty($current[$group]) ? [] : (array)$selection['safe'];
            $review_rows = !empty($current[$group]) ? [] : (array)$selection['review'];
            $safe_labels = seo_classifier_candidate_labels($safe_rows);
            $review_labels = seo_classifier_candidate_labels($review_rows);
            $candidate_rows = array_map('seo_classifier_candidate_public_row', $ranked);

            $evidence[$group] = [
                'state'=>$state,
                'margin'=>(float)$selection['margin'],
                'top'=>$candidate_rows,
                'selected_safe'=>seo_classifier_candidate_ids($safe_rows),
                'selected_review'=>seo_classifier_candidate_ids($review_rows),
            ];
            $groups[$group] = [
                'status'=>$state,
                'current'=>(array)($current_labels[$group] ?? []),
                'proposal'=>$safe_labels ?: $review_labels,
                'safe'=>$safe_labels,
                'review'=>$review_labels,
                'confidence'=>$top ? round((float)($top['score'] ?? 0), 2) : 0.0,
                'candidates'=>$candidate_rows,
            ];

            if ($safe_labels) {
                $proposal[$group] = $safe_labels;
                $confidence[$group] = round((float)($safe_rows[0]['score'] ?? 0), 2);
                $proposed_ids[$group] = seo_classifier_candidate_ids($safe_rows);
                if ($group === 'tipo') $effective_type_ids = [(int)$proposed_ids[$group][0]];
            }
            if ($review_labels) $review[$group] = $review_labels;
        }

        // ROL se deriva exclusivamente del TIPO canónico actual o seguro.
        $current_role_labels = seo_classifier_label_map(['rol'=>(array)($current['rol'] ?? [])]);
        $groups['rol'] = [
            'status'=>!empty($current['rol']) ? 'current' : 'unresolved',
            'current'=>(array)($current_role_labels['rol'] ?? []),
            'proposal'=>[],
            'safe'=>[],
            'review'=>[],
            'confidence'=>0.0,
            'candidates'=>[],
        ];
        if (empty($current['rol'])) {
            $type_id = 0;
            if (!empty($current['tipo'][0]['id'])) $type_id = (int)$current['tipo'][0]['id'];
            elseif (!empty($proposed_ids['tipo'][0])) $type_id = (int)$proposed_ids['tipo'][0];
            $role = seo_classifier_role_from_type($type_id);
            if ($role && trim((string)($role['label'] ?? '')) !== '') {
                $proposal['rol'] = [(string)$role['label']];
                $confidence['rol'] = 1.0;
                $role_candidate = [
                    'id'=>(int)$role['id'],
                    'slug'=>(string)$role['slug'],
                    'label'=>(string)$role['label'],
                    'score'=>1.0,
                    'matched'=>1,
                    'signal_count'=>1,
                    'profile_safe'=>true,
                    'reasons'=>['derivado del TIPO'],
                ];
                $groups['rol'] = [
                    'status'=>'derived',
                    'current'=>[],
                    'proposal'=>[(string)$role['label']],
                    'safe'=>[(string)$role['label']],
                    'review'=>[],
                    'confidence'=>1.0,
                    'candidates'=>[$role_candidate],
                ];
                $evidence['rol'] = ['state'=>'safe','margin'=>1.0,'top'=>[$role_candidate],'selected_safe'=>[(int)$role['id']],'selected_review'=>[]];
            }
        }

        $external = (array)($context['external'] ?? []);
        $result = [
            'object_type'=>'product',
            'object_id'=>$product_id,
            'engine'=>'classifier_labels_v2',
            'values'=>$proposal,
            'review'=>$review,
            'confidence'=>$confidence,
            'evidence'=>$evidence,
            'groups'=>$groups,
            'sources'=>[
                'local'=>true,
                'supplier'=>trim((string)($context['supplier'] ?? '')) !== '',
                'external'=>[
                    'status'=>(string)($external['status'] ?? 'unavailable'),
                    'ready'=>!empty($external['ready']),
                    'queued'=>!empty($external['queued']),
                    'relevance'=>round((float)($external['relevance'] ?? 0.0), 4),
                    'urls'=>array_values(array_map(static function($row){ return (string)($row['url'] ?? ''); }, (array)($external['sources'] ?? []))),
                ],
                'learned_profiles'=>function_exists('seo_classifier_profiles_version') ? seo_classifier_profiles_version() : '',
            ],
            'viable'=>!empty($proposal),
        ];
        return apply_filters('seo_classifier_product_labels_result', $result, $product_id, $current, $context, $options);
    }
}

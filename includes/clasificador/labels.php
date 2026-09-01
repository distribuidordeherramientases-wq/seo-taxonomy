<?php
/**
 * Clasificación semántica de productos contra vocabulario canónico existente.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_classify_product_labels')) {
    function seo_classifier_classify_product_labels($product_id, $current = null) {
        $product_id = absint($product_id);
        $current = is_array($current) ? $current : seo_classifier_current_object_labels('product', $product_id);
        $context = seo_classifier_build_product_context($product_id);

        $proposal = [];
        $review = [];
        $confidence = [];
        $proposed_ids = [];
        $evidence = [];
        $groups = [];

        foreach (['tipo','aplicacion','plataforma','subtipo'] as $group) {
            $current_labels = seo_classifier_label_map([$group => (array) ($current[$group] ?? [])]);
            $ranked = seo_classifier_rank_label_group($group, $context, 5);
            $decision = seo_classifier_candidate_state($group, $ranked);
            $top = $decision['candidate'];
            $state = !empty($current[$group]) ? 'current' : $decision['state'];

            $candidate_rows = array_map(static function($row) {
                return [
                    'id'=>(int)($row['term']['id'] ?? 0),
                    'slug'=>(string)($row['term']['slug'] ?? ''),
                    'label'=>(string)($row['term']['label'] ?? ''),
                    'score'=>round((float)($row['score'] ?? 0), 2),
                    'matched'=>(int)($row['matched'] ?? 0),
                    'reasons'=>(array)($row['reasons'] ?? []),
                ];
            }, $ranked);

            $evidence[$group] = [
                'state'=>$state,
                'margin'=>$decision['margin'],
                'top'=>$candidate_rows,
            ];
            $groups[$group] = [
                'status'=>$state,
                'current'=>(array)($current_labels[$group] ?? []),
                'proposal'=>[],
                'confidence'=>$top ? round((float)($top['score'] ?? 0), 2) : 0.0,
                'candidates'=>$candidate_rows,
            ];

            if (!empty($current[$group]) || !$top || $decision['state'] === 'unresolved') continue;
            $label = trim((string) ($top['term']['label'] ?? ''));
            if ($label === '') continue;

            if ($decision['state'] === 'safe') {
                $proposal[$group] = [$label];
                $confidence[$group] = round((float) ($top['score'] ?? 0), 2);
                $proposed_ids[$group] = [(int) ($top['term']['id'] ?? 0)];
                $groups[$group]['proposal'] = [$label];
            } else {
                $review[$group] = [$label];
                $groups[$group]['proposal'] = [$label];
            }
        }

        // ROL no se infiere lingüísticamente: se deriva del TIPO canónico.
        $current_role_labels = seo_classifier_label_map(['rol'=>(array)($current['rol'] ?? [])]);
        $groups['rol'] = [
            'status'=>!empty($current['rol']) ? 'current' : 'unresolved',
            'current'=>(array)($current_role_labels['rol'] ?? []),
            'proposal'=>[],
            'confidence'=>0.0,
            'candidates'=>[],
        ];
        if (empty($current['rol'])) {
            $type_id = 0;
            if (!empty($current['tipo'][0]['id'])) $type_id = (int) $current['tipo'][0]['id'];
            elseif (!empty($proposed_ids['tipo'][0])) $type_id = (int) $proposed_ids['tipo'][0];
            $role = seo_classifier_role_from_type($type_id);
            if ($role && trim((string) ($role['label'] ?? '')) !== '') {
                $proposal['rol'] = [(string) $role['label']];
                $confidence['rol'] = 1.0;
                $role_candidate = [
                    'id'=>(int)$role['id'],
                    'slug'=>(string)$role['slug'],
                    'label'=>(string)$role['label'],
                    'score'=>1.0,
                    'matched'=>1,
                    'reasons'=>['derivado del TIPO'],
                ];
                $groups['rol'] = [
                    'status'=>'derived',
                    'current'=>[],
                    'proposal'=>[(string)$role['label']],
                    'confidence'=>1.0,
                    'candidates'=>[$role_candidate],
                ];
                $evidence['rol'] = ['state'=>'safe','margin'=>1.0,'top'=>$groups['rol']['candidates']];
            }
        }

        $result = [
            'object_type'=>'product',
            'object_id'=>$product_id,
            'engine'=>'classifier_labels_v1',
            'values'=>$proposal,
            'review'=>$review,
            'confidence'=>$confidence,
            'evidence'=>$evidence,
            'groups'=>$groups,
            'viable'=>!empty($proposal),
        ];
        return apply_filters('seo_classifier_product_labels_result', $result, $product_id, $current, $context);
    }
}

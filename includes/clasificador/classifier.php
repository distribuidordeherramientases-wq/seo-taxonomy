<?php
/**
 * API pública del Clasificador.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_version')) {
    function seo_classifier_version() {
        return '1.0.0';
    }
}

if (!function_exists('seo_classifier_classify_product')) {
    /**
     * Clasifica un producto sin persistir nada.
     *
     * @return array Resultado estructurado listo para revisión/asignación.
     */
    function seo_classifier_classify_product($product_id) {
        $product_id = absint($product_id);
        $current_labels = seo_classifier_current_object_labels('product', $product_id);
        $current_attributes = seo_classifier_current_product_attributes($product_id);
        $context = seo_classifier_build_product_context($product_id);
        $labels = seo_classifier_classify_product_labels($product_id, $current_labels);
        $attributes = seo_classifier_classify_product_attributes($product_id, $current_attributes);

        $coverage_before = 0;
        foreach (seo_classifier_allowed_label_groups() as $group) {
            if (!empty($current_labels[$group])) $coverage_before++;
        }
        $target_labels = seo_classifier_label_map($current_labels);
        foreach ((array)($labels['values'] ?? []) as $group=>$values) {
            $target_labels[$group] = array_values(array_unique(array_merge((array)($target_labels[$group] ?? []), (array)$values)));
        }
        $coverage_safe = 0;
        foreach (seo_classifier_allowed_label_groups() as $group) {
            if (!empty($target_labels[$group])) $coverage_safe++;
        }

        $result = [
            'schema'=>'seo-taxonomy-classifier-result',
            'schema_version'=>1,
            'classifier_version'=>seo_classifier_version(),
            'object_type'=>'product',
            'object_id'=>$product_id,
            'generated_at'=>current_time('mysql'),
            'policy'=>'Solo propone. No crea vocabulario ni escribe asignaciones.',
            'context'=>[
                'title'=>(string)($context['raw_title'] ?? ''),
                'sku'=>(string)($context['sku'] ?? ''),
                'categories'=>(array)($context['category_names'] ?? []),
                'product_tags'=>(array)($context['tag_names'] ?? []),
            ],
            'coverage'=>[
                'labels_before'=>$coverage_before,
                'labels_after_safe'=>$coverage_safe,
                'labels_total'=>count(seo_classifier_allowed_label_groups()),
                'attributes_before'=>count($current_attributes),
                'attributes_safe_proposed'=>count((array)($attributes['values'] ?? [])),
            ],
            'labels'=>$labels,
            'attributes'=>$attributes,
        ];
        return apply_filters('seo_classifier_product_result', $result, $product_id);
    }
}

if (!function_exists('seo_classifier_product_label_proposal')) {
    /** Adapter compacto para la pantalla Asignación. */
    function seo_classifier_product_label_proposal($product_id, array $current = []) {
        return seo_classifier_classify_product_labels($product_id, $current);
    }
}

if (!function_exists('seo_classifier_product_attribute_proposal')) {
    /** Adapter compacto para la pantalla Asignación. */
    function seo_classifier_product_attribute_proposal($product_id, array $current = []) {
        return seo_classifier_classify_product_attributes($product_id, $current);
    }
}

<?php
/**
 * API pública del Clasificador.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_version')) {
    function seo_classifier_version() {
        return '2.2.0';
    }
}

if (!function_exists('seo_classifier_classify_product')) {
    /**
     * Clasifica un producto sin persistir nada.
     *
     * Opciones relevantes:
     * - queue_external: encola la lectura de URLs conocidas si no hay caché.
     * - refresh_external: fuerza una lectura externa síncrona antes de clasificar.
     * - exclude_product_id: excluye un producto de los perfiles aprendidos.
     * - current_labels/current_attributes: permiten evaluar contra un estado enmascarado.
     *
     * @return array Resultado estructurado listo para revisión/asignación.
     */
    function seo_classifier_classify_product($product_id, array $options = []) {
        $product_id = absint($product_id);
        $current_labels = isset($options['current_labels']) && is_array($options['current_labels'])
            ? $options['current_labels']
            : seo_classifier_current_object_labels('product', $product_id);
        $current_attributes = isset($options['current_attributes']) && is_array($options['current_attributes'])
            ? $options['current_attributes']
            : seo_classifier_current_product_attributes($product_id);

        if (!empty($options['refresh_external']) && function_exists('seo_classifier_refresh_external_context')) {
            seo_classifier_refresh_external_context($product_id);
        }

        $context = isset($options['context']) && is_array($options['context'])
            ? $options['context']
            : seo_classifier_build_product_context($product_id, $options);
        $child_options = $options;
        $child_options['context'] = $context;

        $labels = seo_classifier_classify_product_labels($product_id, $current_labels, $child_options);
        $attributes = seo_classifier_classify_product_attributes($product_id, $current_attributes, $child_options);

        $coverage_before = 0;
        foreach (seo_classifier_allowed_label_groups() as $group) {
            if (!empty($current_labels[$group])) $coverage_before++;
        }
        $target_labels = seo_classifier_label_map($current_labels);
        foreach ((array)($labels['values'] ?? []) as $group => $values) {
            $target_labels[$group] = array_values(array_unique(array_merge(
                (array)($target_labels[$group] ?? []),
                (array)$values
            )));
        }
        $coverage_safe = 0;
        foreach (seo_classifier_allowed_label_groups() as $group) {
            if (!empty($target_labels[$group])) $coverage_safe++;
        }

        $external = (array)($context['external'] ?? []);
        $source_data = (array)($context['source_data'] ?? []);
        $result = [
            'schema'=>'seo-taxonomy-classifier-result',
            'schema_version'=>3,
            'classifier_version'=>seo_classifier_version(),
            'profiles_version'=>function_exists('seo_classifier_profiles_version') ? seo_classifier_profiles_version() : '',
            'object_type'=>'product',
            'object_id'=>$product_id,
            'generated_at'=>current_time('mysql'),
            'policy'=>'Propone valores existentes y puede detectar huecos de vocabulario. Nunca crea ni asigna términos sin aceptación explícita.',
            'context'=>[
                'title'=>(string)($context['raw_title'] ?? ''),
                'sku'=>(string)($context['sku'] ?? ''),
                'categories'=>(array)($context['category_names'] ?? []),
                'product_tags'=>(array)($context['tag_names'] ?? []),
                'brands'=>(array)($context['brand_names'] ?? []),
                'manufacturer'=>(string)($source_data['manufacturer'] ?? ''),
                'provider'=>(string)($source_data['provider'] ?? ''),
                'supplier_record'=>trim((string)($context['supplier'] ?? '')) !== '',
                'external'=>[
                    'status'=>(string)($external['status'] ?? 'unavailable'),
                    'ready'=>!empty($external['ready']),
                    'queued'=>!empty($external['queued']),
                    'sources'=>array_values(array_map(static function($row) {
                        return [
                            'url'=>(string)($row['url'] ?? ''),
                            'kind'=>(string)($row['kind'] ?? ''),
                        ];
                    }, (array)($external['sources'] ?? []))),
                ],
            ],
            'coverage'=>[
                'labels_before'=>$coverage_before,
                'labels_after_safe'=>$coverage_safe,
                'labels_total'=>count(seo_classifier_allowed_label_groups()),
                'attributes_before'=>count($current_attributes),
                'attributes_safe_proposed'=>count((array)($attributes['values'] ?? [])),
                'attributes_review_proposed'=>count((array)($attributes['review'] ?? [])),
            ],
            'labels'=>$labels,
            'attributes'=>$attributes,
        ];
        return apply_filters('seo_classifier_product_result', $result, $product_id, $options, $context);
    }
}


if (!function_exists('seo_classifier_classify_product_groups')) {
    /**
     * Clasifica únicamente los grupos semánticos solicitados.
     * Pensado para workers: evita recalcular dimensiones ya resueltas.
     */
    function seo_classifier_classify_product_groups($product_id, array $groups, array $options = []) {
        $product_id = absint($product_id);
        $options['groups'] = array_values(array_unique(array_filter(array_map('sanitize_key', $groups))));
        $current = isset($options['current_labels']) && is_array($options['current_labels'])
            ? $options['current_labels']
            : seo_classifier_current_object_labels('product', $product_id);
        return seo_classifier_classify_product_labels($product_id, $current, $options);
    }
}

if (!function_exists('seo_classifier_product_label_proposal')) {
    /** Adapter compacto para la pantalla Asignación. */
    function seo_classifier_product_label_proposal($product_id, array $current = [], array $options = []) {
        return seo_classifier_classify_product_labels($product_id, $current, $options);
    }
}

if (!function_exists('seo_classifier_product_attribute_proposal')) {
    /** Adapter compacto para la pantalla Asignación. */
    function seo_classifier_product_attribute_proposal($product_id, array $current = [], array $options = []) {
        return seo_classifier_classify_product_attributes($product_id, $current, $options);
    }
}

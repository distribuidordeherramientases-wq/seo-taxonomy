<?php
/**
 * Contexto normalizado para el Clasificador de SEO Taxonomy.
 *
 * Este archivo no escribe datos. Solo reúne y normaliza señales del catálogo.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_allowed_label_groups')) {
    function seo_classifier_allowed_label_groups() {
        return ['rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo'];
    }
}

if (!function_exists('seo_classifier_normalize')) {
    function seo_classifier_normalize($text) {
        $text = html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');
        $text = wp_strip_all_tags($text);
        $text = mb_strtolower(remove_accents($text), 'UTF-8');
        $text = preg_replace('/[^a-z0-9º°+\/.]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }
}

if (!function_exists('seo_classifier_identity_segment')) {
    /**
     * Conserva la parte inicial del título, que suele describir qué es el producto.
     * Evita cortar guiones técnicos como HSS-G: solo separa delimitadores rodeados
     * por espacios, raya larga, dos puntos o barra vertical.
     */
    function seo_classifier_identity_segment($title) {
        $title = trim((string) $title);
        if ($title === '') return '';
        $parts = preg_split('/\s+[\x{2013}\x{2014}|-]\s+|:\s+/u', $title, 2);
        return trim((string) ($parts[0] ?? $title));
    }
}

if (!function_exists('seo_classifier_stopwords')) {
    function seo_classifier_stopwords() {
        return [
            'de','del','la','las','el','los','para','por','con','sin','y','o','en','un','una','unos','unas',
            'a','al','se','su','sus','e','hasta','desde','sobre','como','tipo','modelo','premium','universal',
        ];
    }
}

if (!function_exists('seo_classifier_concept_aliases')) {
    /**
     * Equivalencias léxicas conservadoras. Son conceptos, no valores canónicos.
     * Se usan únicamente para comparar texto con el vocabulario existente.
     */
    function seo_classifier_concept_aliases() {
        $aliases = [
            'coche'=>'vehiculo','coches'=>'vehiculo','vehiculo'=>'vehiculo','vehiculos'=>'vehiculo',
            'automovil'=>'vehiculo','automoviles'=>'vehiculo','auto'=>'vehiculo','carro'=>'vehiculo',
            'pantalla'=>'pantalla','pantallas'=>'pantalla','display'=>'pantalla','displays'=>'pantalla',
            'monitor'=>'pantalla','monitores'=>'pantalla','hud'=>'pantalla',
            'diagnostico'=>'diagnostico','diagnosticos'=>'diagnostico','diagnostica'=>'diagnostico','diagnosis'=>'diagnostico',
            'obd'=>'obd','obd2'=>'obd','obdii'=>'obd',
            'puerta'=>'puerta','puertas'=>'puerta','corredera'=>'corredera','correderas'=>'corredera',
            'rueda'=>'rueda','ruedas'=>'rueda','herraje'=>'herraje','herrajes'=>'herraje',
            'broca'=>'broca','brocas'=>'broca','taladrado'=>'perforacion','perforar'=>'perforacion',
            'cristal'=>'vidrio','vidrio'=>'vidrio','glass'=>'vidrio',
        ];
        return apply_filters('seo_classifier_concept_aliases', $aliases);
    }
}

if (!function_exists('seo_classifier_concept_token')) {
    function seo_classifier_concept_token($token) {
        $token = seo_classifier_normalize((string) $token);
        if ($token === '' || strpos($token, ' ') !== false) return '';
        $aliases = seo_classifier_concept_aliases();
        if (isset($aliases[$token])) return $aliases[$token];
        if (preg_match('/^obd(?:2|ii)$/', $token)) return 'obd';

        // Singularización mínima y conservadora para mejorar coincidencias.
        if (mb_strlen($token, 'UTF-8') > 5 && substr($token, -2) === 'es') {
            $candidate = substr($token, 0, -2);
            if (mb_strlen($candidate, 'UTF-8') >= 3) $token = $candidate;
        } elseif (mb_strlen($token, 'UTF-8') > 4 && substr($token, -1) === 's') {
            $candidate = substr($token, 0, -1);
            if (mb_strlen($candidate, 'UTF-8') >= 3) $token = $candidate;
        }
        return $aliases[$token] ?? $token;
    }
}

if (!function_exists('seo_classifier_concept_sequence')) {
    function seo_classifier_concept_sequence($text) {
        $stop = array_fill_keys(seo_classifier_stopwords(), true);
        $normalized = seo_classifier_normalize($text);
        // Para conceptos, los separadores técnicos unen alternativas (HUD/medidor,
        // audio/vídeo, etc.) y deben convertirse en tokens independientes.
        $normalized = str_replace(['/', '+', '.'], ' ', $normalized);
        $raw = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ((array) $raw as $token) {
            if (mb_strlen($token, 'UTF-8') < 2 || isset($stop[$token])) continue;
            $concept = seo_classifier_concept_token($token);
            if ($concept !== '') $out[] = $concept;
        }
        return $out;
    }
}

if (!function_exists('seo_classifier_current_object_labels')) {
    function seo_classifier_current_object_labels($object_type, $object_id) {
        global $wpdb;
        $object_type = $object_type === 'product_cat' ? 'product_cat' : 'product';
        $object_id = absint($object_id);
        $out = array_fill_keys(seo_classifier_allowed_label_groups(), []);
        if ($object_id < 1) return $out;

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        if (!seo_classifier_table_exists($vocabulary) || !seo_classifier_table_exists($objects)) return $out;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT v.id,v.semantic_group,v.slug,v.label,ov.source,ov.confidence
             FROM {$objects} ov
             INNER JOIN {$vocabulary} v ON v.id=ov.vocabulary_id AND v.active=1
             WHERE ov.object_type=%s AND ov.object_id=%d AND ov.status=1
               AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
             ORDER BY v.semantic_group,v.label",
            $object_type,
            $object_id
        ), ARRAY_A);

        foreach ((array) $rows as $row) {
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            if (isset($out[$group])) $out[$group][] = $row;
        }
        return $out;
    }
}

if (!function_exists('seo_classifier_label_map')) {
    function seo_classifier_label_map(array $rows) {
        $out = [];
        foreach (seo_classifier_allowed_label_groups() as $group) {
            $labels = [];
            foreach ((array) ($rows[$group] ?? []) as $row) {
                if (is_array($row)) $label = trim((string) ($row['label'] ?? ''));
                else $label = trim((string) $row);
                if ($label !== '') $labels[] = $label;
            }
            if ($labels) $out[$group] = array_values(array_unique($labels));
        }
        return $out;
    }
}

if (!function_exists('seo_classifier_current_product_attributes')) {
    function seo_classifier_current_product_attributes($product_id) {
        $out = [];
        if (!function_exists('seo_attributes_get_product_rows')) return $out;
        foreach ((array) seo_attributes_get_product_rows(absint($product_id)) as $row) {
            $slug = sanitize_key((string) ($row->attribute_type ?? ''));
            $value = function_exists('seo_attributes_display_value')
                ? seo_attributes_display_value($row)
                : (string) ($row->attribute_value ?? '');
            $value = trim((string) $value);
            if ($slug !== '' && $value !== '') $out[$slug][] = $value;
        }
        foreach ($out as $slug => $values) $out[$slug] = array_values(array_unique($values));
        return $out;
    }
}

if (!function_exists('seo_classifier_build_product_context')) {
    function seo_classifier_build_product_context($product_id) {
        $product_id = absint($product_id);
        $post = get_post($product_id);
        if (!$post || $post->post_type !== 'product') {
            return [
                'product_id'=>$product_id,'valid'=>false,'raw_title'=>'','title'=>'','identity'=>'','full'=>'',
                'categories'=>'','tags'=>'','category_names'=>[],'tag_names'=>[],'sku'=>'',
            ];
        }

        $category_names = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']);
        $tag_names = taxonomy_exists('product_tag') ? wp_get_post_terms($product_id, 'product_tag', ['fields' => 'names']) : [];
        if (is_wp_error($category_names)) $category_names = [];
        if (is_wp_error($tag_names)) $tag_names = [];

        $sku = (string) get_post_meta($product_id, '_sku', true);
        $raw_title = (string) $post->post_title;
        $identity_raw = seo_classifier_identity_segment($raw_title);
        $raw_full = implode(' ', [
            $raw_title,
            (string) $post->post_name,
            (string) $post->post_excerpt,
            (string) $post->post_content,
            implode(' ', (array) $category_names),
            implode(' ', (array) $tag_names),
            $sku,
        ]);

        $context = [
            'product_id'     => $product_id,
            'valid'          => true,
            'sku'            => $sku,
            'raw_title'      => $raw_title,
            'raw_identity'   => $identity_raw,
            'raw_full'       => $raw_full,
            'title'          => seo_classifier_normalize($raw_title),
            'identity'       => seo_classifier_normalize($identity_raw),
            'full'           => seo_classifier_normalize($raw_full),
            'categories'     => seo_classifier_normalize(implode(' ', (array) $category_names)),
            'tags'           => seo_classifier_normalize(implode(' ', (array) $tag_names)),
            'category_names' => array_values((array) $category_names),
            'tag_names'      => array_values((array) $tag_names),
        ];

        return apply_filters('seo_classifier_product_context', $context, $product_id, $post);
    }
}

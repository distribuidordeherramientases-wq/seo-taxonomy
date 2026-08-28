<?php
if (!defined('ABSPATH')) exit;

/**
 * Helpers compartidos que siguen siendo necesarios tras retirar la
 * clasificación legacy de productos.
 *
 * - El producto usa exclusivamente el vocabulary canónico.
 * - Las categorías usan exclusivamente Vocabulary canónico para su semántica.
 * - seo_nodes se conserva aquí solo para excerpt/description editoriales de categoría.
 * - seo_cls_score() se mantiene por compatibilidad con el informe de
 *   reclasificación y obtiene semántica de producto y categoría desde Vocabulary.
 */

if (!function_exists('seo_pc_normalize_text')) {
    function seo_pc_normalize_text($text) {
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = remove_accents(mb_strtolower($text, 'UTF-8'));
        $text = preg_replace('/\\b\\d+(?:[.,]\\d+)?\\s*(?:mm|cm|m|kg|g|w|kw|v|a|ah|bar|psi|rpm|hz|l|ml)\\b/iu', ' ', $text);
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim(preg_replace('/\\s+/u', ' ', $text));
    }
}

if (!function_exists('seo_pc_default_stopwords')) {
    function seo_pc_default_stopwords() {
        return [
            'para','como','desde','hasta','entre','sobre','bajo','tras','ante','contra','durante','mediante','segun','sin','con','por','del','las','los','una','uno','unos','unas','que','sus','este','esta','estos','estas','ese','esa','muy','mas','menos','tambien','puede','permite','ofrece','ayuda','facilita','mejora','evita','usar','utilizar','trabajo','trabajos','producto','productos','herramienta','herramientas','equipo','equipos','solucion','profesional','profesionales','calidad','rendimiento','eficiente','eficaz','practico','practica','ideal','versatil','imprescindible','principal','disenado','disenada','adecuado','adecuada','uso','tipo','aplicacion','aplicaciones','caracteristica','caracteristicas','ventaja','ventajas'
        ];
    }
}

if (!function_exists('seo_pc_stopwords')) {
    function seo_pc_stopwords() {
        static $cache = null;
        if (null !== $cache) return $cache;
        global $wpdb;
        $all = seo_pc_default_stopwords();
        $table = $wpdb->prefix . 'seo_dictionari';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $db_words = $wpdb->get_col("SELECT palabra FROM {$table} WHERE palabra IS NOT NULL AND palabra <> ''");
            foreach ((array) $db_words as $word) $all[] = $word;
        }
        $cache = [];
        foreach ($all as $word) {
            $key = seo_pc_normalize_text($word);
            if ('' !== $key) $cache[$key] = true;
        }
        return $cache;
    }
}

if (!function_exists('seo_pc_words')) {
    function seo_pc_words($text, $keep_stopwords = false) {
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');
        $parts = preg_split('/[^\\p{L}\\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        $stop = seo_pc_stopwords();
        foreach ((array) $parts as $word) {
            $key = seo_pc_normalize_text($word);
            if ('' === $key || mb_strlen($key, 'UTF-8') < 3) continue;
            if (!$keep_stopwords && isset($stop[$key])) continue;
            $out[] = sanitize_text_field($word);
        }
        return $out;
    }
}

if (!function_exists('seo_pc_get_product_data')) {
    function seo_pc_get_product_data($product_id) {
        static $cache = [];
        $product_id = absint($product_id);
        if (isset($cache[$product_id])) return $cache[$product_id];
        global $wpdb;
        $post = get_post($product_id);
        if (!$post || 'product' !== $post->post_type) return [];
        $labels = '';
        if (function_exists('seo_catalog_get_product_public_semantic_labels')) {
            $semantic_rows = seo_catalog_get_product_public_semantic_labels($product_id, 30);
            $labels = implode(', ', array_values(array_filter(array_map(
                static function ($row) { return trim((string) ($row['label'] ?? '')); },
                (array) $semantic_rows
            ))));
        }
        $attrs = $wpdb->get_results($wpdb->prepare(
            "SELECT attribute_type, attribute_value FROM {$wpdb->prefix}seo_attributes WHERE product_id=%d ORDER BY attribute_type, id",
            $product_id
        ));
        $cats = wp_get_post_terms($product_id, 'product_cat', ['fields'=>'all']);
        if (is_wp_error($cats)) $cats = [];
        $wc_tags = wp_get_post_terms($product_id, 'product_tag', ['fields'=>'names']);
        if (is_wp_error($wc_tags)) $wc_tags = [];
        return $cache[$product_id] = [
            'post'=>$post,
            'labels'=>$labels,
            'attrs'=>(array) $attrs,
            'cats'=>(array) $cats,
            'wc_tags'=>(array) $wc_tags,
        ];
    }
}
if (!function_exists('seo_pc_profile')) {
    function seo_pc_profile($text) {
        $tokens = [];
        foreach (seo_pc_words($text, false) as $word) {
            $key = seo_pc_normalize_text($word);
            if ('' === $key) continue;
            $tokens[$key] = 1 + min(0.6, max(0, strlen($key)-5) * 0.08);
        }
        return $tokens;
    }
}

if (!function_exists('seo_pc_category_vocabulary_text')) {
    /**
     * Devuelve la semántica canónica activa de una categoría WooCommerce.
     *
     * No usa seo_nodes/category/category: la fuente única es
     * seo_object_vocabulary -> seo_vocabulary con object_type=product_cat.
     */
    function seo_pc_category_vocabulary_text($term_id) {
        static $cache = [];
        $term_id = absint($term_id);
        if ($term_id < 1) return '';
        if (array_key_exists($term_id, $cache)) return $cache[$term_id];

        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT v.label\n"
            . "FROM {$wpdb->prefix}seo_object_vocabulary ov\n"
            . "JOIN {$wpdb->prefix}seo_vocabulary v ON v.id = ov.vocabulary_id\n"
            . "WHERE ov.object_type = 'product_cat'\n"
            . "  AND ov.object_id = %d\n"
            . "  AND ov.status = 1\n"
            . "  AND v.active = 1\n"
            . "  AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')\n"
            . "ORDER BY FIELD(v.semantic_group,'rol','tipo','aplicacion','plataforma','subtipo'), v.label ASC",
            $term_id
        ));

        $labels = array_values(array_unique(array_filter(array_map(
            static function ($label) { return trim((string) $label); },
            (array) $rows
        ))));

        return $cache[$term_id] = implode(', ', $labels);
    }
}

if (!function_exists('seo_pc_category_context')) {
    function seo_pc_category_context($term, $hs_obj, $hp_obj, $cluster_obj) {
        static $cache = [];
        if (!$term || is_wp_error($term)) return [];
        $key = $term->term_id . ':' . absint($hs_obj->ID ?? 0) . ':' . absint($hp_obj->ID ?? 0) . ':' . absint($cluster_obj->ID ?? 0);
        if (isset($cache[$key])) return $cache[$key];
        global $wpdb;
        $context = [];
        $merge = function($text, $multiplier) use (&$context) {
            foreach (seo_pc_profile($text) as $token=>$weight) {
                $weighted = $weight * $multiplier;
                if (!isset($context[$token]) || $weighted > $context[$token]) $context[$token] = $weighted;
            }
        };
        $merge($term->name . ' ' . $term->slug, 3.0);
        $merge(seo_pc_category_vocabulary_text($term->term_id), 2.6);
        $merge((string) $wpdb->get_var($wpdb->prepare(
            "SELECT keywords FROM {$wpdb->prefix}seo_nodes WHERE object_type='category' AND object_id=%d AND seo_role='excerpt' AND status=1 ORDER BY updated_at DESC,id DESC LIMIT 1",
            $term->term_id
        )), 1.8);
        $merge($term->description . ' ' . (string) $wpdb->get_var($wpdb->prepare(
            "SELECT keywords FROM {$wpdb->prefix}seo_nodes WHERE object_type='category' AND object_id=%d AND seo_role='description' AND status=1 ORDER BY updated_at DESC,id DESC LIMIT 1",
            $term->term_id
        )), 1.0);
        if ($hs_obj) $merge($hs_obj->post_title . ' ' . $hs_obj->post_excerpt, 0.8);
        if ($hp_obj) $merge($hp_obj->post_title . ' ' . $hp_obj->post_excerpt, 0.45);
        if ($cluster_obj) $merge($cluster_obj->post_title . ' ' . $cluster_obj->post_excerpt, 0.25);
        return $cache[$key] = $context;
    }
}

if (!function_exists('seo_pc_coverage')) {
    function seo_pc_coverage($text, $context) {
        $profile = seo_pc_profile($text);
        if (!$profile || !$context) return 0.0;
        $total=0.0; $match=0.0;
        foreach ($profile as $token=>$weight) {
            $total += $weight;
            if (isset($context[$token])) $match += $weight * min(1.0, $context[$token] / 2.5);
        }
        return $total > 0 ? $match / $total : 0.0;
    }
}

if (!function_exists('seo_pc_classification_score')) {
    function seo_pc_classification_score($product_id, $term, $hs_obj, $hp_obj, $cluster_obj) {
        $data = seo_pc_get_product_data($product_id);
        if (empty($data)) return ['score'=>0,'title'=>0,'label'=>'Sin datos','reasons'=>[]];
        $p = $data['post'];
        $ctx = seo_pc_category_context($term,$hs_obj,$hp_obj,$cluster_obj);
        $attr_text=''; foreach ($data['attrs'] as $a) $attr_text .= ' ' . $a->attribute_type . ' ' . $a->attribute_value;
        $sources = [
            'title'=>[$p->post_title,40],
            'attributes'=>[$attr_text,25],
            'labels'=>[$data['labels'],15],
            'excerpt'=>[$p->post_excerpt,10],
            'wc_tags'=>[implode(' ',$data['wc_tags']),5],
            'description'=>[$p->post_content,5],
        ];
        $score=0.0; $details=[];
        foreach ($sources as $name=>$row) {
            $coverage=seo_pc_coverage($row[0],$ctx);
            $score += $coverage*$row[1];
            $details[$name]=(int)round($coverage*100);
        }
        $score=(int)round($score);
        $title_cov=$details['title'];
        $reasons=[];
        if ($title_cov < 20) $reasons[]='El título comparte pocas señales con la categoría.';
        if ($details['attributes'] < 15 && trim($attr_text)!=='') $reasons[]='Los atributos aportan poca evidencia para esta categoría.';
        if ($score >= 70) $label='Alta'; elseif ($score >= 50) $label='Media'; else $label='Baja';
        return ['score'=>$score,'title'=>$title_cov,'label'=>$label,'reasons'=>$reasons,'sources'=>$details];
    }
}
/*
 * Los helpers seo_semantic_labels_* de categorías se retiraron aquí.
 * La edición y limpieza semántica de product_cat vive ya en la capa canónica
 * de Vocabulary definida por category-classification.php.
 */

/* Compatibilidad con el informe de reclasificación. */
if (!function_exists('seo_cls_norm')) {
    function seo_cls_norm($text) {
        return seo_pc_normalize_text($text);
    }
}

if (!function_exists('seo_cls_tokens')) {
    function seo_cls_tokens($text) {
        return array_keys(seo_pc_profile($text));
    }
}

if (!function_exists('seo_cls_score')) {
    function seo_cls_score($product_id, $p, $term, $hs_obj, $hp_obj, $cluster_obj) {
        $result = seo_pc_classification_score($product_id, $term, $hs_obj, $hp_obj, $cluster_obj);
        return $result['score'];
    }
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * Helpers compartidos que siguen siendo necesarios tras retirar la
 * clasificación legacy de productos.
 *
 * - El producto usa exclusivamente el vocabulary canónico.
 * - Los helpers seo_semantic_labels_* se conservan temporalmente para
 *   etiquetas legacy de categorías mientras termina su migración.
 * - seo_cls_score() se mantiene por compatibilidad con el informe de
 *   reclasificación y ya obtiene las etiquetas de producto desde vocabulary.
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
        $merge((string) $wpdb->get_var($wpdb->prepare(
            "SELECT keywords FROM {$wpdb->prefix}seo_nodes WHERE object_type='category' AND object_id=%d AND seo_role='category' AND status=1 ORDER BY updated_at DESC,id DESC LIMIT 1",
            $term->term_id
        )), 2.6);
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
if (!function_exists('seo_semantic_labels_normalize')) {
    function seo_semantic_labels_normalize($label) {
        $label = html_entity_decode(wp_strip_all_tags((string) $label), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = preg_replace('/\\s+/u', ' ', trim($label));
        if ($label === '') return '';
        return trim(mb_strtolower($label, 'UTF-8'));
    }
}

if (!function_exists('seo_semantic_labels_parse')) {
    function seo_semantic_labels_parse($keywords) {
        $out = [];
        foreach (explode(',', (string) $keywords) as $item) {
            $item = sanitize_text_field(trim($item));
            if ($item !== '') $out[] = $item;
        }
        return $out;
    }
}

if (!function_exists('seo_semantic_labels_unique')) {
    function seo_semantic_labels_unique($keywords) {
        $unique = [];
        foreach (seo_semantic_labels_parse($keywords) as $label) {
            $key = seo_semantic_labels_normalize($label);
            if ($key !== '' && !isset($unique[$key])) $unique[$key] = $label;
        }
        return array_values($unique);
    }
}

if (!function_exists('seo_semantic_labels_signature')) {
    function seo_semantic_labels_signature($keywords) {
        $keys = [];
        foreach (seo_semantic_labels_unique($keywords) as $label) {
            $key = seo_semantic_labels_normalize($label);
            if ($key !== '') $keys[$key] = true;
        }
        $keys = array_keys($keys);
        sort($keys, SORT_STRING);
        return implode('|', $keys);
    }
}

if (!function_exists('seo_semantic_labels_assert_data_layer')) {
    function seo_semantic_labels_assert_data_layer() {
        if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
            throw new RuntimeException('El Data Layer no esta disponible. No se ha modificado ninguna etiqueta.');
        }
        $config = SEO_Data_Layer::table('nodes');
        if (empty($config['table'])) {
            throw new RuntimeException('La tabla seo_nodes no esta registrada en el Data Layer.');
        }
        return $config;
    }
}

if (!function_exists('seo_semantic_labels_object_exists')) {
    function seo_semantic_labels_object_exists($object_type, $object_id) {
        global $wpdb;
        $object_type = sanitize_key($object_type);
        $object_id = absint($object_id);
        if ($object_type !== 'category' || $object_id < 1) return false;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='product_cat' LIMIT 1",
            $object_id
        ));
    }
}

if (!function_exists('seo_semantic_labels_save_object')) {
    function seo_semantic_labels_save_object($object_type, $object_id, $seo_role, $keywords_value, $source_module = 'semantic_labels') {
        global $wpdb;

        $object_type = sanitize_key($object_type);
        $seo_role = sanitize_key($seo_role);
        $object_id = absint($object_id);

        if ($object_type !== 'category' || $seo_role !== 'category' || $object_id < 1) {
            throw new InvalidArgumentException('Este helper queda reservado a etiquetas legacy de categorias.');
        }

        if (!seo_semantic_labels_object_exists($object_type, $object_id)) {
            throw new RuntimeException('La categoria ya no existe. No se han guardado etiquetas.');
        }

        $keywords_value = implode(', ', seo_semantic_labels_unique($keywords_value));
        $config = seo_semantic_labels_assert_data_layer();
        $table = (string) $config['table'];

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, object_type, object_id, seo_role, keywords, status
             FROM `{$table}`
             WHERE object_type=%s AND object_id=%d AND seo_role=%s
             ORDER BY status DESC, updated_at DESC, id DESC
             LIMIT 1",
            $object_type,
            $object_id,
            $seo_role
        ), ARRAY_A);

        if (is_array($existing)
            && (string) ($existing['keywords'] ?? '') === $keywords_value
            && (int) ($existing['status'] ?? 0) === 1) {
            return [
                'changed' => false,
                'operation_id' => 0,
                'operation_uuid' => '',
                'node_id' => (int) ($existing['id'] ?? 0),
            ];
        }

        $operation = SEO_Data_Layer::operation([
            'type'          => 'save_semantic_labels_' . $object_type,
            'label'         => 'Guardar etiquetas de categoria #' . $object_id,
            'source_module' => sanitize_key($source_module),
            'rollbackable'  => true,
            'risk_level'    => 'medium',
            'audit_level'   => 'full',
            'metadata'      => [
                'object_type' => $object_type,
                'object_id'   => $object_id,
                'seo_role'    => $seo_role,
            ],
        ]);
        $operation->mark_validated(['validated_rows' => 1]);
        $operation->mark_previewed(1, ['new_keywords' => $keywords_value]);

        $result = $operation->execute(
            static function (SEO_Data_Operation $op) use ($existing, $table, $object_type, $object_id, $seo_role, $keywords_value) {
                if (!seo_semantic_labels_object_exists($object_type, $object_id)) {
                    throw new RuntimeException('El objeto desaparecio antes de guardar. Operacion cancelada.');
                }

                $now = current_time('mysql');

                if (is_array($existing) && !empty($existing['id'])) {
                    $node_id = (int) $existing['id'];
                    $current = SEO_Data_Layer::fetch_row($table, ['id' => $node_id], true);
                    if ($current === null
                        || (string) ($current['object_type'] ?? '') !== $object_type
                        || (int) ($current['object_id'] ?? 0) !== $object_id
                        || (string) ($current['seo_role'] ?? '') !== $seo_role
                        || (string) ($current['keywords'] ?? '') !== (string) ($existing['keywords'] ?? '')
                        || (int) ($current['status'] ?? 0) !== (int) ($existing['status'] ?? 0)) {
                        throw new RuntimeException('Las etiquetas cambiaron durante el guardado. Operacion cancelada para evitar sobrescribir cambios.');
                    }

                    $after = $op->update(
                        'nodes',
                        ['id' => $node_id],
                        [
                            'keywords'   => $keywords_value,
                            'status'     => 1,
                            'updated_at' => $now,
                        ],
                        [
                            'related_object_type' => $object_type,
                            'related_object_id'   => $object_id,
                            'seo_role'            => $seo_role,
                        ]
                    );
                    return (int) ($after['id'] ?? $node_id);
                }

                $after = $op->insert(
                    'nodes',
                    [
                        'object_type' => $object_type,
                        'object_id'   => $object_id,
                        'seo_role'    => $seo_role,
                        'keywords'    => $keywords_value,
                        'status'      => 1,
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ],
                    [
                        'related_object_type' => $object_type,
                        'related_object_id'   => $object_id,
                        'seo_role'            => $seo_role,
                    ]
                );
                return (int) ($after['id'] ?? 0);
            }
        );

        return [
            'changed'        => true,
            'operation_id'   => $operation->id(),
            'operation_uuid' => $operation->uuid(),
            'node_id'        => (int) $result,
        ];
    }
}

if (!function_exists('seo_semantic_labels_clear_object')) {
    function seo_semantic_labels_clear_object($object_type, $object_id, $seo_role, $source_module = 'semantic_labels') {
        global $wpdb;
        $object_type = sanitize_key($object_type);
        $seo_role = sanitize_key($seo_role);
        $object_id = absint($object_id);
        if ($object_type !== 'category' || $seo_role !== 'category' || $object_id < 1) {
            throw new InvalidArgumentException('Este helper queda reservado a etiquetas legacy de categorias.');
        }

        $config = seo_semantic_labels_assert_data_layer();
        $table = (string) $config['table'];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, object_type, object_id, seo_role, keywords, status
             FROM `{$table}`
             WHERE object_type=%s AND object_id=%d AND seo_role=%s
             ORDER BY id ASC",
            $object_type,
            $object_id,
            $seo_role
        ), ARRAY_A);
        if (!is_array($rows)) throw new RuntimeException('No se pudieron inventariar las etiquetas.');
        if (!$rows) return ['deleted' => 0, 'operation_id' => 0, 'operation_uuid' => ''];

        $operation = SEO_Data_Layer::operation([
            'type'          => 'clear_semantic_labels_' . $object_type,
            'label'         => 'Borrar etiquetas de categoria #' . $object_id,
            'source_module' => sanitize_key($source_module),
            'rollbackable'  => true,
            'risk_level'    => 'medium',
            'audit_level'   => 'full',
            'metadata'      => ['object_type' => $object_type, 'object_id' => $object_id, 'seo_role' => $seo_role],
        ]);
        $operation->mark_validated(['validated_rows' => count($rows)]);
        $operation->mark_previewed(count($rows));

        $deleted = $operation->execute(
            static function (SEO_Data_Operation $op) use ($rows, $table, $object_type, $object_id, $seo_role) {
                $count = 0;
                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    $current = SEO_Data_Layer::fetch_row($table, ['id' => $id], true);
                    if ($current === null
                        || (string) ($current['object_type'] ?? '') !== $object_type
                        || (int) ($current['object_id'] ?? 0) !== $object_id
                        || (string) ($current['seo_role'] ?? '') !== $seo_role
                        || (string) ($current['keywords'] ?? '') !== (string) ($row['keywords'] ?? '')
                        || (int) ($current['status'] ?? 0) !== (int) ($row['status'] ?? 0)) {
                        throw new RuntimeException('Las etiquetas cambiaron antes del borrado. Operacion cancelada.');
                    }
                    $op->delete('nodes', ['id' => $id], [
                        'related_object_type' => $object_type,
                        'related_object_id'   => $object_id,
                        'seo_role'            => $seo_role,
                    ]);
                    $count++;
                }
                return $count;
            }
        );

        return ['deleted' => (int) $deleted, 'operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid()];
    }
}

if (!function_exists('seo_semantic_labels_delete_global')) {
    function seo_semantic_labels_delete_global($object_type, $seo_role, $label, $source_module = 'semantic_labels_dashboard') {
        global $wpdb;
        $object_type = sanitize_key($object_type);
        $seo_role = sanitize_key($seo_role);
        $label = sanitize_text_field(trim((string) $label));
        if ($object_type !== 'category' || $seo_role !== 'category') {
            throw new InvalidArgumentException('Este helper queda reservado a etiquetas legacy de categorias.');
        }
        $target_key = seo_semantic_labels_normalize($label);
        if ($target_key === '') throw new InvalidArgumentException('La etiqueta no puede estar vacia.');

        $config = seo_semantic_labels_assert_data_layer();
        $table = (string) $config['table'];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, object_id, keywords, status
             FROM `{$table}`
             WHERE object_type=%s AND seo_role=%s AND status=1
               AND keywords IS NOT NULL AND keywords<>''
             ORDER BY id ASC",
            $object_type,
            $seo_role
        ), ARRAY_A);
        if (!is_array($rows)) throw new RuntimeException('No se pudieron inventariar las etiquetas.');

        $targets = [];
        $object_ids = [];
        foreach ($rows as $row) {
            $filtered = [];
            $found = false;
            foreach (seo_semantic_labels_parse($row['keywords'] ?? '') as $item) {
                if (seo_semantic_labels_normalize($item) === $target_key) {
                    $found = true;
                    continue;
                }
                $filtered[] = $item;
            }
            if (!$found) continue;
            $targets[] = [
                'id'       => (int) ($row['id'] ?? 0),
                'object_id'=> (int) ($row['object_id'] ?? 0),
                'before'   => (string) ($row['keywords'] ?? ''),
                'after'    => implode(', ', $filtered),
                'status'   => (int) ($row['status'] ?? 0),
            ];
            $object_ids[(int) ($row['object_id'] ?? 0)] = true;
        }

        if (!$targets) return ['updated' => 0, 'objects' => 0, 'operation_id' => 0, 'operation_uuid' => ''];

        $operation = SEO_Data_Layer::operation([
            'type'          => 'delete_semantic_label_global_' . $object_type,
            'label'         => 'Eliminar etiqueta global ' . $object_type . ': ' . $label,
            'source_module' => sanitize_key($source_module),
            'rollbackable'  => true,
            'risk_level'    => 'high',
            'audit_level'   => 'full',
            'metadata'      => [
                'object_type' => $object_type,
                'seo_role'    => $seo_role,
                'label'       => $label,
                'rows'        => count($targets),
                'objects'     => count($object_ids),
            ],
        ]);
        $operation->mark_validated(['validated_rows' => count($targets)]);
        $operation->mark_previewed(count($targets), ['affected_objects' => count($object_ids)]);

        $updated = $operation->execute(
            static function (SEO_Data_Operation $op) use ($targets, $table, $object_type, $seo_role, $target_key) {
                $count = 0;
                foreach ($targets as $target) {
                    $current = SEO_Data_Layer::fetch_row($table, ['id' => (int) $target['id']], true);
                    if ($current === null
                        || (string) ($current['object_type'] ?? '') !== $object_type
                        || (string) ($current['seo_role'] ?? '') !== $seo_role
                        || (string) ($current['keywords'] ?? '') !== (string) $target['before']
                        || (int) ($current['status'] ?? 0) !== (int) $target['status']) {
                        throw new RuntimeException('Una fila de etiquetas cambio durante el borrado global. Operacion cancelada.');
                    }

                    $still_present = false;
                    foreach (seo_semantic_labels_parse($current['keywords'] ?? '') as $item) {
                        if (seo_semantic_labels_normalize($item) === $target_key) {
                            $still_present = true;
                            break;
                        }
                    }
                    if (!$still_present) throw new RuntimeException('La etiqueta ya no esta en una de las filas inventariadas. Operacion cancelada.');

                    $op->update('nodes', ['id' => (int) $target['id']], [
                        'keywords'   => (string) $target['after'],
                        'updated_at' => current_time('mysql'),
                    ], [
                        'related_object_type' => $object_type,
                        'related_object_id'   => (int) $target['object_id'],
                        'seo_role'            => $seo_role,
                        'removed_label'       => $target_key,
                    ]);
                    $count++;
                }
                return $count;
            }
        );

        return [
            'updated'        => (int) $updated,
            'objects'        => count($object_ids),
            'operation_id'   => $operation->id(),
            'operation_uuid' => $operation->uuid(),
        ];
    }
}

if (!function_exists('seo_semantic_labels_orphan_rows')) {
    function seo_semantic_labels_orphan_rows($object_type, $seo_role) {
        global $wpdb;
        $nodes = $wpdb->prefix . 'seo_nodes';
        if (sanitize_key($object_type) !== 'category' || sanitize_key($seo_role) !== 'category') {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT n.id, n.object_id, n.keywords, n.status
             FROM `{$nodes}` n
             LEFT JOIN {$wpdb->term_taxonomy} tt
               ON tt.term_id=n.object_id AND tt.taxonomy='product_cat'
             WHERE n.object_type=%s AND n.seo_role=%s AND n.status=1 AND tt.term_taxonomy_id IS NULL
             ORDER BY n.id ASC",
            $object_type,
            $seo_role
        ), ARRAY_A);
    }
}

if (!function_exists('seo_semantic_labels_cleanup_orphans')) {
    function seo_semantic_labels_cleanup_orphans($object_type, $seo_role, $source_module = 'semantic_labels_dashboard') {
        $config = seo_semantic_labels_assert_data_layer();
        $table = (string) $config['table'];
        $rows = seo_semantic_labels_orphan_rows($object_type, $seo_role);
        if (!is_array($rows)) throw new RuntimeException('No se pudieron detectar residuos de etiquetas.');
        if (!$rows) return ['deleted' => 0, 'operation_id' => 0, 'operation_uuid' => ''];

        $operation = SEO_Data_Layer::operation([
            'type'          => 'cleanup_semantic_label_orphans_' . $object_type,
            'label'         => 'Limpiar etiquetas huerfanas de categorias',
            'source_module' => sanitize_key($source_module),
            'rollbackable'  => true,
            'risk_level'    => 'medium',
            'audit_level'   => 'full',
            'metadata'      => ['object_type' => $object_type, 'seo_role' => $seo_role, 'rows' => count($rows)],
        ]);
        $operation->mark_validated(['validated_rows' => count($rows)]);
        $operation->mark_previewed(count($rows));

        $deleted = $operation->execute(
            static function (SEO_Data_Operation $op) use ($rows, $table, $object_type, $seo_role) {
                $count = 0;
                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    $object_id = (int) ($row['object_id'] ?? 0);
                    if (seo_semantic_labels_object_exists($object_type, $object_id)) {
                        throw new RuntimeException('Un objeto huerfano ha reaparecido durante la limpieza. Operacion cancelada.');
                    }
                    $current = SEO_Data_Layer::fetch_row($table, ['id' => $id], true);
                    if ($current === null
                        || (string) ($current['object_type'] ?? '') !== $object_type
                        || (string) ($current['seo_role'] ?? '') !== $seo_role
                        || (int) ($current['object_id'] ?? 0) !== $object_id
                        || (string) ($current['keywords'] ?? '') !== (string) ($row['keywords'] ?? '')
                        || (int) ($current['status'] ?? 0) !== 1) {
                        throw new RuntimeException('Una fila huerfana cambio antes de borrarse. Operacion cancelada.');
                    }
                    $op->delete('nodes', ['id' => $id], [
                        'related_object_type' => $object_type,
                        'related_object_id'   => $object_id,
                        'seo_role'            => $seo_role,
                        'reason'              => 'orphan_label_node',
                    ]);
                    $count++;
                }
                return $count;
            }
        );

        return ['deleted' => (int) $deleted, 'operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid()];
    }
}

if (!function_exists('seo_semantic_labels_cleanup_empty_nodes')) {
    function seo_semantic_labels_cleanup_empty_nodes($object_type, $seo_role, $source_module = 'semantic_labels_dashboard') {
        global $wpdb;
        $config = seo_semantic_labels_assert_data_layer();
        $table = (string) $config['table'];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, object_id, keywords, status
             FROM `{$table}`
             WHERE object_type=%s AND seo_role=%s AND status=1
               AND TRIM(COALESCE(keywords,''))=''
             ORDER BY id ASC",
            $object_type,
            $seo_role
        ), ARRAY_A);
        if (!is_array($rows)) throw new RuntimeException('No se pudieron detectar nodos vacios.');
        if (!$rows) return ['deleted' => 0, 'operation_id' => 0, 'operation_uuid' => ''];

        $operation = SEO_Data_Layer::operation([
            'type'          => 'cleanup_empty_semantic_label_nodes_' . $object_type,
            'label'         => 'Eliminar nodos de etiquetas vacios de categorias',
            'source_module' => sanitize_key($source_module),
            'rollbackable'  => true,
            'risk_level'    => 'low',
            'audit_level'   => 'full',
            'metadata'      => ['object_type' => $object_type, 'seo_role' => $seo_role, 'rows' => count($rows)],
        ]);
        $operation->mark_validated(['validated_rows' => count($rows)]);
        $operation->mark_previewed(count($rows));

        $deleted = $operation->execute(
            static function (SEO_Data_Operation $op) use ($rows, $table, $object_type, $seo_role) {
                $count = 0;
                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    $current = SEO_Data_Layer::fetch_row($table, ['id' => $id], true);
                    if ($current === null
                        || (string) ($current['object_type'] ?? '') !== $object_type
                        || (string) ($current['seo_role'] ?? '') !== $seo_role
                        || trim((string) ($current['keywords'] ?? '')) !== ''
                        || (int) ($current['status'] ?? 0) !== 1) {
                        throw new RuntimeException('Un nodo vacio cambio antes del borrado. Operacion cancelada.');
                    }
                    $op->delete('nodes', ['id' => $id], [
                        'related_object_type' => $object_type,
                        'related_object_id'   => (int) ($row['object_id'] ?? 0),
                        'seo_role'            => $seo_role,
                        'reason'              => 'empty_label_node',
                    ]);
                    $count++;
                }
                return $count;
            }
        );
        return ['deleted' => (int) $deleted, 'operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid()];
    }
}

if (!function_exists('seo_semantic_labels_cleanup_internal_duplicates')) {
    function seo_semantic_labels_cleanup_internal_duplicates($object_type, $seo_role, $source_module = 'semantic_labels_dashboard') {
        global $wpdb;
        $config = seo_semantic_labels_assert_data_layer();
        $table = (string) $config['table'];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, object_id, keywords, status
             FROM `{$table}`
             WHERE object_type=%s AND seo_role=%s AND status=1
               AND keywords IS NOT NULL AND keywords<>''
             ORDER BY id ASC",
            $object_type,
            $seo_role
        ), ARRAY_A);
        if (!is_array($rows)) throw new RuntimeException('No se pudieron inventariar las etiquetas.');

        $targets = [];
        $removed = 0;
        foreach ($rows as $row) {
            $items = seo_semantic_labels_parse($row['keywords'] ?? '');
            $unique = seo_semantic_labels_unique($row['keywords'] ?? '');
            if (count($items) <= count($unique)) continue;
            $targets[] = [
                'id' => (int) ($row['id'] ?? 0),
                'object_id' => (int) ($row['object_id'] ?? 0),
                'before' => (string) ($row['keywords'] ?? ''),
                'after' => implode(', ', $unique),
            ];
            $removed += count($items) - count($unique);
        }
        if (!$targets) return ['updated' => 0, 'removed' => 0, 'operation_id' => 0, 'operation_uuid' => ''];

        $operation = SEO_Data_Layer::operation([
            'type'          => 'cleanup_internal_duplicate_labels_' . $object_type,
            'label'         => 'Limpiar etiquetas repetidas en categorias',
            'source_module' => sanitize_key($source_module),
            'rollbackable'  => true,
            'risk_level'    => 'low',
            'audit_level'   => 'full',
            'metadata'      => ['object_type' => $object_type, 'seo_role' => $seo_role, 'rows' => count($targets), 'duplicates' => $removed],
        ]);
        $operation->mark_validated(['validated_rows' => count($targets)]);
        $operation->mark_previewed(count($targets), ['duplicate_labels_to_remove' => $removed]);

        $updated = $operation->execute(
            static function (SEO_Data_Operation $op) use ($targets, $table, $object_type, $seo_role) {
                $count = 0;
                foreach ($targets as $target) {
                    $current = SEO_Data_Layer::fetch_row($table, ['id' => (int) $target['id']], true);
                    if ($current === null
                        || (string) ($current['object_type'] ?? '') !== $object_type
                        || (string) ($current['seo_role'] ?? '') !== $seo_role
                        || (string) ($current['keywords'] ?? '') !== (string) $target['before']
                        || (int) ($current['status'] ?? 0) !== 1) {
                        throw new RuntimeException('Una fila cambio antes de limpiar duplicados. Operacion cancelada.');
                    }
                    $op->update('nodes', ['id' => (int) $target['id']], [
                        'keywords' => (string) $target['after'],
                        'updated_at' => current_time('mysql'),
                    ], [
                        'related_object_type' => $object_type,
                        'related_object_id' => (int) $target['object_id'],
                        'seo_role' => $seo_role,
                        'reason' => 'internal_duplicate_labels',
                    ]);
                    $count++;
                }
                return $count;
            }
        );
        return ['updated' => (int) $updated, 'removed' => $removed, 'operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid()];
    }
}

if (!function_exists('seo_semantic_labels_cleanup_exact_duplicate_nodes')) {
    function seo_semantic_labels_cleanup_exact_duplicate_nodes($object_type, $seo_role, $source_module = 'semantic_labels_dashboard') {
        global $wpdb;
        $config = seo_semantic_labels_assert_data_layer();
        $table = (string) $config['table'];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, object_id, keywords, status, updated_at
             FROM `{$table}`
             WHERE object_type=%s AND seo_role=%s AND status=1
             ORDER BY object_id ASC, updated_at DESC, id DESC",
            $object_type,
            $seo_role
        ), ARRAY_A);
        if (!is_array($rows)) throw new RuntimeException('No se pudieron inventariar los nodos duplicados.');

        $seen = [];
        $targets = [];
        foreach ($rows as $row) {
            $object_id = (int) ($row['object_id'] ?? 0);
            $signature = seo_semantic_labels_signature($row['keywords'] ?? '');
            $key = $object_id . '|' . $signature;
            if (!isset($seen[$key])) {
                $seen[$key] = (int) ($row['id'] ?? 0);
                continue;
            }
            $targets[] = [
                'id' => (int) ($row['id'] ?? 0),
                'object_id' => $object_id,
                'keywords' => (string) ($row['keywords'] ?? ''),
                'keep_id' => (int) $seen[$key],
                'signature' => $signature,
            ];
        }
        if (!$targets) return ['deleted' => 0, 'operation_id' => 0, 'operation_uuid' => ''];

        $operation = SEO_Data_Layer::operation([
            'type'          => 'cleanup_exact_duplicate_label_nodes_' . $object_type,
            'label'         => 'Eliminar nodos duplicados exactos de categorias',
            'source_module' => sanitize_key($source_module),
            'rollbackable'  => true,
            'risk_level'    => 'low',
            'audit_level'   => 'full',
            'metadata'      => ['object_type' => $object_type, 'seo_role' => $seo_role, 'rows' => count($targets)],
        ]);
        $operation->mark_validated(['validated_rows' => count($targets)]);
        $operation->mark_previewed(count($targets));

        $deleted = $operation->execute(
            static function (SEO_Data_Operation $op) use ($targets, $table, $object_type, $seo_role) {
                $count = 0;
                foreach ($targets as $target) {
                    $keep = SEO_Data_Layer::fetch_row($table, ['id' => (int) $target['keep_id']], true);
                    $current = SEO_Data_Layer::fetch_row($table, ['id' => (int) $target['id']], true);
                    if ($keep === null || $current === null
                        || (string) ($keep['object_type'] ?? '') !== $object_type
                        || (string) ($keep['seo_role'] ?? '') !== $seo_role
                        || (int) ($keep['status'] ?? 0) !== 1
                        || (int) ($keep['object_id'] ?? 0) !== (int) $target['object_id']
                        || seo_semantic_labels_signature($keep['keywords'] ?? '') !== (string) $target['signature']
                        || (string) ($current['object_type'] ?? '') !== $object_type
                        || (string) ($current['seo_role'] ?? '') !== $seo_role
                        || (int) ($current['status'] ?? 0) !== 1
                        || (int) ($current['object_id'] ?? 0) !== (int) $target['object_id']
                        || seo_semantic_labels_signature($current['keywords'] ?? '') !== (string) $target['signature']) {
                        throw new RuntimeException('Un nodo duplicado cambio antes de la limpieza. Operacion cancelada.');
                    }
                    $op->delete('nodes', ['id' => (int) $target['id']], [
                        'related_object_type' => $object_type,
                        'related_object_id' => (int) $target['object_id'],
                        'seo_role' => $seo_role,
                        'reason' => 'exact_duplicate_label_node',
                        'kept_node_id' => (int) $target['keep_id'],
                    ]);
                    $count++;
                }
                return $count;
            }
        );
        return ['deleted' => (int) $deleted, 'operation_id' => $operation->id(), 'operation_uuid' => $operation->uuid()];
    }
}

if (!function_exists('seo_semantic_labels_collect_stats')) {
    function seo_semantic_labels_collect_stats($object_type, $seo_role) {
        global $wpdb;
        $nodes = $wpdb->prefix . 'seo_nodes';
        if (sanitize_key($object_type) !== 'category' || sanitize_key($seo_role) !== 'category') {
            return [
                'total_objects'=>0,'labeled_objects'=>0,'unlabeled_objects'=>0,'unique_labels'=>0,
                'assignments'=>0,'usage'=>[],'rare'=>[],'blocked'=>[],'orphan_rows'=>0,
                'orphan_only_labels'=>0,'orphan_only_sample'=>[],'empty_nodes'=>0,
                'internal_duplicate_rows'=>0,'internal_duplicate_extras'=>0,
                'exact_duplicate_nodes'=>0,'conflicting_duplicate_objects'=>0,
            ];
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, object_id, keywords, status, updated_at
             FROM `{$nodes}`
             WHERE object_type=%s AND seo_role=%s AND status=1
             ORDER BY object_id ASC, updated_at DESC, id DESC",
            $object_type,
            $seo_role
        ), ARRAY_A);
        if (!is_array($rows)) $rows = [];

        $total_objects = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_cat'");

        $orphan_rows = seo_semantic_labels_orphan_rows($object_type, $seo_role);
        if (!is_array($orphan_rows)) $orphan_rows = [];
        $orphan_ids = [];
        $orphan_label_keys = [];
        foreach ($orphan_rows as $row) {
            $orphan_ids[(int) ($row['id'] ?? 0)] = true;
            foreach (seo_semantic_labels_unique($row['keywords'] ?? '') as $label) {
                $key = seo_semantic_labels_normalize($label);
                if ($key !== '') $orphan_label_keys[$key] = true;
            }
        }

        $current = [];
        $rows_by_object = [];
        foreach ($rows as $row) {
            $object_id = (int) ($row['object_id'] ?? 0);
            if (!isset($rows_by_object[$object_id])) $rows_by_object[$object_id] = [];
            $rows_by_object[$object_id][] = $row;
            if (!isset($current[$object_id])) $current[$object_id] = $row;
        }

        $usage = [];
        $variants = [];
        $assignments = 0;
        $labeled_objects = 0;
        $internal_duplicate_rows = 0;
        $internal_duplicate_extras = 0;
        $empty_nodes = 0;
        $conflicting_duplicate_objects = 0;
        $exact_duplicate_nodes = 0;

        foreach ($rows_by_object as $object_id => $object_rows) {
            if (count($object_rows) > 1) {
                $signatures = [];
                foreach ($object_rows as $row) {
                    $sig = seo_semantic_labels_signature($row['keywords'] ?? '');
                    if (isset($signatures[$sig])) $exact_duplicate_nodes++;
                    else $signatures[$sig] = true;
                }
                if (count($signatures) > 1) $conflicting_duplicate_objects++;
            }
        }

        foreach ($rows as $row) {
            $items = seo_semantic_labels_parse($row['keywords'] ?? '');
            $unique = seo_semantic_labels_unique($row['keywords'] ?? '');
            if (!$items) $empty_nodes++;
            if (count($items) > count($unique)) {
                $internal_duplicate_rows++;
                $internal_duplicate_extras += count($items) - count($unique);
            }
        }

        foreach ($current as $row) {
            if (isset($orphan_ids[(int) ($row['id'] ?? 0)])) continue;
            $labels = seo_semantic_labels_unique($row['keywords'] ?? '');
            if (!$labels) continue;
            $labeled_objects++;
            $seen_for_object = [];
            foreach ($labels as $label) {
                $key = seo_semantic_labels_normalize($label);
                if ($key === '' || isset($seen_for_object[$key])) continue;
                $seen_for_object[$key] = true;
                if (!isset($usage[$key])) $usage[$key] = ['label' => $label, 'objects' => 0];
                $usage[$key]['objects']++;
                if (!isset($variants[$key])) $variants[$key] = [];
                $variants[$key][$label] = true;
                $assignments++;
            }
        }

        foreach ($usage as $key => &$item) {
            $item['variants'] = isset($variants[$key]) ? count($variants[$key]) : 1;
            $item['coverage'] = $total_objects > 0 ? round(($item['objects'] / $total_objects) * 100, 2) : 0;
        }
        unset($item);
        uasort($usage, static function ($a, $b) {
            if ((int) $a['objects'] === (int) $b['objects']) return strcasecmp((string) $a['label'], (string) $b['label']);
            return (int) $b['objects'] <=> (int) $a['objects'];
        });

        $rare = array_filter($usage, static function ($item) { return (int) $item['objects'] === 1; });
        uasort($rare, static function ($a, $b) { return strcasecmp((string) $a['label'], (string) $b['label']); });

        $blocked = [];
        $dict = $wpdb->prefix . 'seo_dictionari';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $dict)) === $dict) {
            $words = $wpdb->get_col("SELECT palabra FROM `{$dict}` WHERE palabra IS NOT NULL AND palabra<>''");
            $block_keys = [];
            foreach ((array) $words as $word) {
                $key = seo_semantic_labels_normalize($word);
                if ($key !== '') $block_keys[$key] = true;
            }
            foreach ($usage as $key => $item) {
                if (isset($block_keys[$key])) $blocked[$key] = $item;
            }
        }

        $orphan_only = array_diff_key($orphan_label_keys, $usage);

        return [
            'total_objects' => $total_objects,
            'labeled_objects' => $labeled_objects,
            'unlabeled_objects' => max(0, $total_objects - $labeled_objects),
            'unique_labels' => count($usage),
            'assignments' => $assignments,
            'usage' => $usage,
            'rare' => $rare,
            'blocked' => $blocked,
            'orphan_rows' => count($orphan_rows),
            'orphan_only_labels' => count($orphan_only),
            'orphan_only_sample' => array_slice(array_keys($orphan_only), 0, 20),
            'empty_nodes' => $empty_nodes,
            'internal_duplicate_rows' => $internal_duplicate_rows,
            'internal_duplicate_extras' => $internal_duplicate_extras,
            'exact_duplicate_nodes' => $exact_duplicate_nodes,
            'conflicting_duplicate_objects' => $conflicting_duplicate_objects,
        ];
    }
}

if (!function_exists('seo_semantic_labels_handle_dashboard_action')) {
    function seo_semantic_labels_handle_dashboard_action($object_type, $seo_role) {
        if (sanitize_key($object_type) !== 'category' || sanitize_key($seo_role) !== 'category') return null;
        if (empty($_POST['seo_label_dashboard_action'])) return null;
        $scope = sanitize_key(wp_unslash($_POST['seo_label_dashboard_scope'] ?? ''));
        if ($scope !== $object_type) return null;
        check_admin_referer('seo_semantic_labels_dashboard_' . $object_type, 'seo_semantic_labels_dashboard_nonce');

        $action = sanitize_key(wp_unslash($_POST['seo_label_dashboard_action']));
        try {
            switch ($action) {
                case 'delete_global':
                    $label = sanitize_text_field(wp_unslash($_POST['seo_label'] ?? ''));
                    $result = seo_semantic_labels_delete_global($object_type, $seo_role, $label);
                    return ['type' => 'success', 'text' => sprintf(
                        'Etiqueta "%s" eliminada de %d filas (%d objetos). Operacion Data Layer #%d; rollback disponible.',
                        $label,
                        (int) ($result['updated'] ?? 0),
                        (int) ($result['objects'] ?? 0),
                        (int) ($result['operation_id'] ?? 0)
                    )];

                case 'cleanup_orphans':
                    $result = seo_semantic_labels_cleanup_orphans($object_type, $seo_role);
                    return ['type' => 'success', 'text' => sprintf(
                        'Residuos eliminados: %d filas. Operacion Data Layer #%d.',
                        (int) ($result['deleted'] ?? 0),
                        (int) ($result['operation_id'] ?? 0)
                    )];

                case 'cleanup_empty':
                    $result = seo_semantic_labels_cleanup_empty_nodes($object_type, $seo_role);
                    return ['type' => 'success', 'text' => sprintf(
                        'Nodos de etiquetas vacios eliminados: %d. Operacion Data Layer #%d.',
                        (int) ($result['deleted'] ?? 0),
                        (int) ($result['operation_id'] ?? 0)
                    )];

                case 'cleanup_internal_duplicates':
                    $result = seo_semantic_labels_cleanup_internal_duplicates($object_type, $seo_role);
                    return ['type' => 'success', 'text' => sprintf(
                        'Filas normalizadas: %d; repeticiones eliminadas: %d. Operacion Data Layer #%d.',
                        (int) ($result['updated'] ?? 0),
                        (int) ($result['removed'] ?? 0),
                        (int) ($result['operation_id'] ?? 0)
                    )];

                case 'cleanup_exact_nodes':
                    $result = seo_semantic_labels_cleanup_exact_duplicate_nodes($object_type, $seo_role);
                    return ['type' => 'success', 'text' => sprintf(
                        'Nodos duplicados exactos eliminados: %d. Operacion Data Layer #%d.',
                        (int) ($result['deleted'] ?? 0),
                        (int) ($result['operation_id'] ?? 0)
                    )];
            }
            return ['type' => 'warning', 'text' => 'Accion de etiquetas no reconocida.'];
        } catch (Throwable $exception) {
            return ['type' => 'error', 'text' => 'No se ha modificado ninguna etiqueta: ' . $exception->getMessage()];
        }
    }
}

if (!function_exists('seo_semantic_labels_render_action_form')) {
    function seo_semantic_labels_render_action_form($object_type, $action, $label = '', $button = '', $confirm = '', $class = 'button') {
        $page = sanitize_key($_GET['page'] ?? 'seo-taxonomy');
        $tab = sanitize_key($_GET['tab'] ?? 'semantic');
        $semantic_tab = sanitize_key($_GET['semantic_tab'] ?? 'category_labels');
        echo '<form method="post" style="display:inline-block;margin:0;">';
        wp_nonce_field('seo_semantic_labels_dashboard_' . $object_type, 'seo_semantic_labels_dashboard_nonce');
        echo '<input type="hidden" name="page" value="' . esc_attr($page) . '">';
        echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
        echo '<input type="hidden" name="semantic_tab" value="' . esc_attr($semantic_tab) . '">';
        echo '<input type="hidden" name="seo_label_dashboard_scope" value="' . esc_attr($object_type) . '">';
        echo '<input type="hidden" name="seo_label_dashboard_action" value="' . esc_attr($action) . '">';
        if ($label !== '') echo '<input type="hidden" name="seo_label" value="' . esc_attr($label) . '">';
        $onclick = $confirm !== '' ? ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : '';
        echo '<button type="submit" class="' . esc_attr($class) . '"' . $onclick . '>' . esc_html($button) . '</button>';
        echo '</form>';
    }
}

if (!function_exists('seo_semantic_labels_render_dashboard')) {
    function seo_semantic_labels_render_dashboard($object_type, $seo_role, $title) {
        if (sanitize_key($object_type) !== 'category' || sanitize_key($seo_role) !== 'category') return;
        $stats = seo_semantic_labels_collect_stats($object_type, $seo_role);
        $usage = $stats['usage'];
        $top = array_slice($usage, 0, 30, true);
        $rare = array_slice($stats['rare'], 0, 25, true);
        $max_usage = 1;
        foreach ($top as $item) $max_usage = max($max_usage, (int) $item['objects']);

        $search = sanitize_text_field(wp_unslash($_GET['semantic_label_q'] ?? ''));
        $inventory = $usage;
        if ($search !== '') {
            $needle = seo_semantic_labels_normalize($search);
            $inventory = array_filter($usage, static function ($item, $key) use ($needle) {
                return $needle === '' || strpos((string) $key, $needle) !== false || strpos(seo_semantic_labels_normalize($item['label']), $needle) !== false;
            }, ARRAY_FILTER_USE_BOTH);
        }
        $inventory = array_slice($inventory, 0, $search !== '' ? 100 : 50, true);

        echo '<style>
            .seo-semantic-label-dashboard{margin:16px 0 24px;padding:18px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px}
            .seo-semantic-label-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:14px 0}
            .seo-semantic-label-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px}
            .seo-semantic-label-card strong{display:block;font-size:23px;line-height:1.2;margin-bottom:4px}
            .seo-semantic-label-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;margin-top:14px}
            .seo-semantic-label-panel{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px;overflow:auto}
            .seo-semantic-label-bar{height:7px;background:#dcdcde;border-radius:6px;overflow:hidden;min-width:80px}
            .seo-semantic-label-bar span{display:block;height:100%;background:#2271b1}
            .seo-semantic-label-table{width:100%;border-collapse:collapse}
            .seo-semantic-label-table th,.seo-semantic-label-table td{padding:7px 8px;border-bottom:1px solid #eee;text-align:left;vertical-align:middle}
            .seo-semantic-label-table th{font-size:12px;color:#50575e}
            .seo-semantic-label-danger{border-left:4px solid #d63638}
            .seo-semantic-label-warning{border-left:4px solid #dba617}
            .seo-semantic-label-ok{border-left:4px solid #00a32a}
        </style>';

        echo '<div class="seo-semantic-label-dashboard">';
        echo '<h2 style="margin-top:0;">Panel semantico: ' . esc_html($title) . '</h2>';
        echo '<p>Inventario real de <code>seo_nodes.keywords</code>. Las senales de limpieza no borran por frecuencia: una etiqueta usada una sola vez puede ser valida. Las acciones destructivas pasan por Data Layer y quedan disponibles para rollback.</p>';

        echo '<div class="seo-semantic-label-cards">';
        $cards = [
            ['value' => $stats['labeled_objects'], 'label' => 'Con etiquetas'],
            ['value' => $stats['unlabeled_objects'], 'label' => 'Sin etiquetas'],
            ['value' => $stats['unique_labels'], 'label' => 'Etiquetas unicas'],
            ['value' => $stats['assignments'], 'label' => 'Asignaciones'],
            ['value' => count($stats['rare']), 'label' => 'Uso unico'],
            ['value' => count($stats['blocked']), 'label' => 'Bloqueadas en uso'],
        ];
        foreach ($cards as $card) {
            echo '<div class="seo-semantic-label-card"><strong>' . esc_html(number_format_i18n((int) $card['value'])) . '</strong><span>' . esc_html($card['label']) . '</span></div>';
        }
        echo '</div>';

        echo '<div class="seo-semantic-label-grid">';
        echo '<div class="seo-semantic-label-panel ' . ($stats['orphan_rows'] ? 'seo-semantic-label-danger' : 'seo-semantic-label-ok') . '">';
        echo '<h3>Residuos de objetos eliminados</h3><p><strong>' . esc_html(number_format_i18n($stats['orphan_rows'])) . '</strong> filas activas pertenecen a objetos que ya no existen o estan en papelera. Contienen ' . esc_html(number_format_i18n($stats['orphan_only_labels'])) . ' etiquetas que no tienen uso real en ningun objeto valido.</p>';
        seo_semantic_labels_render_action_form($object_type, 'cleanup_orphans', '', 'Limpiar residuos', 'Se eliminaran solamente filas de etiquetas cuya categoria siga sin existir. La operacion sera reversible mediante Data Layer.', 'button button-secondary');
        echo '</div>';

        echo '<div class="seo-semantic-label-panel ' . ($stats['internal_duplicate_extras'] ? 'seo-semantic-label-warning' : 'seo-semantic-label-ok') . '">';
        echo '<h3>Etiquetas repetidas dentro del mismo objeto</h3><p><strong>' . esc_html(number_format_i18n($stats['internal_duplicate_extras'])) . '</strong> repeticiones sobrantes en ' . esc_html(number_format_i18n($stats['internal_duplicate_rows'])) . ' filas. Se conserva la primera forma encontrada.</p>';
        seo_semantic_labels_render_action_form($object_type, 'cleanup_internal_duplicates', '', 'Eliminar repeticiones', 'Se quitaran solo repeticiones de la misma etiqueta dentro de una misma fila. No se borraran etiquetas distintas.', 'button button-secondary');
        echo '</div>';

        echo '<div class="seo-semantic-label-panel ' . ($stats['empty_nodes'] ? 'seo-semantic-label-warning' : 'seo-semantic-label-ok') . '">';
        echo '<h3>Nodos vacios</h3><p><strong>' . esc_html(number_format_i18n($stats['empty_nodes'])) . '</strong> filas activas no contienen ninguna etiqueta. No aportan informacion semantica.</p>';
        seo_semantic_labels_render_action_form($object_type, 'cleanup_empty', '', 'Eliminar nodos vacios', 'Se eliminaran exclusivamente nodos activos cuyo campo keywords siga vacio. Rollback disponible.', 'button button-secondary');
        echo '</div>';

        echo '<div class="seo-semantic-label-panel ' . ($stats['exact_duplicate_nodes'] ? 'seo-semantic-label-warning' : 'seo-semantic-label-ok') . '">';
        echo '<h3>Nodos duplicados</h3><p><strong>' . esc_html(number_format_i18n($stats['exact_duplicate_nodes'])) . '</strong> copias activas semanticamente identicas. Objetos con copias diferentes entre si: <strong>' . esc_html(number_format_i18n($stats['conflicting_duplicate_objects'])) . '</strong> (esas no se borran automaticamente).</p>';
        seo_semantic_labels_render_action_form($object_type, 'cleanup_exact_nodes', '', 'Eliminar duplicados exactos', 'Se conservara la fila activa mas reciente y se borraran solo copias con el mismo conjunto de etiquetas.', 'button button-secondary');
        echo '</div>';
        echo '</div>';

        echo '<div class="seo-semantic-label-grid">';
        echo '<div class="seo-semantic-label-panel">';
        echo '<h3>Etiquetas mas utilizadas</h3>';
        if (!$top) {
            echo '<p>No hay etiquetas activas.</p>';
        } else {
            echo '<table class="seo-semantic-label-table"><thead><tr><th>Etiqueta</th><th>Objetos</th><th>Cobertura</th><th>Variantes</th><th></th></tr></thead><tbody>';
            foreach ($top as $key => $item) {
                $width = max(2, (int) round(((int) $item['objects'] / $max_usage) * 100));
                echo '<tr><td><strong>' . esc_html($item['label']) . '</strong><div class="seo-semantic-label-bar"><span style="width:' . esc_attr($width) . '%"></span></div></td>';
                echo '<td>' . esc_html(number_format_i18n((int) $item['objects'])) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $item['coverage'], 2)) . '%</td>';
                echo '<td>' . esc_html(number_format_i18n((int) $item['variants'])) . '</td><td>';
                seo_semantic_labels_render_action_form($object_type, 'delete_global', $item['label'], 'Eliminar globalmente', 'Se eliminara la etiqueta "' . $item['label'] . '" de todos los ' . (int) $item['objects'] . ' objetos donde aparezca. Es una accion de alto impacto pero reversible mediante Data Layer.', 'button button-small');
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        echo '<div class="seo-semantic-label-panel">';
        echo '<h3>Etiquetas de uso unico</h3><p>Son candidatas a revision, no a borrado automatico. Una marca, modelo o aplicacion especifica puede ser perfectamente valida aunque solo aparezca una vez.</p>';
        if (!$rare) echo '<p>No hay etiquetas de uso unico.</p>';
        else {
            echo '<table class="seo-semantic-label-table"><thead><tr><th>Etiqueta</th><th>Variantes</th><th></th></tr></thead><tbody>';
            foreach ($rare as $item) {
                echo '<tr><td>' . esc_html($item['label']) . '</td><td>' . esc_html(number_format_i18n((int) $item['variants'])) . '</td><td>';
                seo_semantic_labels_render_action_form($object_type, 'delete_global', $item['label'], 'Eliminar', 'Esta etiqueta se usa en un unico objeto. Eliminarla globalmente quitara esa asignacion. Rollback disponible.', 'button button-small');
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        echo '</div>';

        if (!empty($stats['blocked'])) {
            echo '<div class="seo-semantic-label-panel seo-semantic-label-warning" style="margin-top:14px;">';
            echo '<h3>Palabras bloqueadas que siguen como etiquetas</h3><p>Estas etiquetas coinciden con palabras presentes en <code>seo_dictionari</code>. Se muestran como senal de revision; no se eliminan automaticamente.</p>';
            echo '<table class="seo-semantic-label-table"><thead><tr><th>Etiqueta</th><th>Objetos</th><th></th></tr></thead><tbody>';
            foreach (array_slice($stats['blocked'], 0, 30, true) as $item) {
                echo '<tr><td>' . esc_html($item['label']) . '</td><td>' . esc_html(number_format_i18n((int) $item['objects'])) . '</td><td>';
                seo_semantic_labels_render_action_form($object_type, 'delete_global', $item['label'], 'Eliminar globalmente', 'La etiqueta coincide con una palabra bloqueada y se eliminara de todos los objetos donde aparezca. Rollback disponible.', 'button button-small');
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        $page = sanitize_key($_GET['page'] ?? 'seo-taxonomy');
        $tab = sanitize_key($_GET['tab'] ?? 'semantic');
        $semantic_tab = sanitize_key($_GET['semantic_tab'] ?? 'category_labels');
        echo '<div class="seo-semantic-label-panel" style="margin-top:14px;">';
        echo '<h3>Inventario de etiquetas</h3>';
        echo '<form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '"><input type="hidden" name="semantic_tab" value="' . esc_attr($semantic_tab) . '">';
        echo '<input type="search" name="semantic_label_q" value="' . esc_attr($search) . '" placeholder="Buscar etiqueta" style="min-width:280px;">';
        echo '<button class="button">Buscar</button>';
        if ($search !== '') echo '<a class="button" href="' . esc_url(add_query_arg(['page'=>$page,'tab'=>$tab,'semantic_tab'=>$semantic_tab], admin_url('admin.php'))) . '">Limpiar busqueda</a>';
        echo '</form>';
        echo '<table class="seo-semantic-label-table"><thead><tr><th>Etiqueta</th><th>Objetos</th><th>Cobertura</th><th>Variantes</th><th></th></tr></thead><tbody>';
        foreach ($inventory as $item) {
            echo '<tr><td>' . esc_html($item['label']) . '</td><td>' . esc_html(number_format_i18n((int) $item['objects'])) . '</td><td>' . esc_html(number_format_i18n((float) $item['coverage'], 2)) . '%</td><td>' . esc_html(number_format_i18n((int) $item['variants'])) . '</td><td>';
            seo_semantic_labels_render_action_form($object_type, 'delete_global', $item['label'], 'Eliminar globalmente', 'Se eliminara esta etiqueta de todos los objetos donde aparezca. La operacion queda auditada y es reversible.', 'button button-small');
            echo '</td></tr>';
        }
        if (!$inventory) echo '<tr><td colspan="5">No se encontraron etiquetas.</td></tr>';
        echo '</tbody></table></div>';

        echo '<p style="margin:12px 0 0;color:#646970;"><strong>Importante:</strong> no existe un diccionario maestro operativo de etiquetas equivalente al de atributos. Por eso "sin uso" se mide como residuos de objetos eliminados o nodos vacios; las etiquetas de baja frecuencia solo se senalan para revision.</p>';
        echo '</div>';
    }
}


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

<?php
/**
 * Analista 3.8.1 - capa de validacion antes de priorizar.
 *
 * Reglas:
 * - destino y entidad antes que accion editorial;
 * - prioridad y confianza son dimensiones independientes;
 * - muestras pequenas nunca convierten por si solas una posicion en oportunidad;
 * - Analista diagnostica y deriva, no ejecuta cambios editoriales.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_ANALISTA_TASK_HISTORY_OPTION')) {
    define('SEO_ANALISTA_TASK_HISTORY_OPTION', 'seo_analista_task_history_381');
}

if (!function_exists('seo_analista_validation_settings')) {
    function seo_analista_validation_settings() {
        $settings = function_exists('seo_analista_get_settings') ? (array) seo_analista_get_settings() : array();
        $raw = (array) ($settings['validation'] ?? array());
        return array(
            'small_sample_max' => max(1, min(20, absint($raw['small_sample_max'] ?? 4))),
            'reliable_impressions' => max(5, min(100, absint($raw['reliable_impressions'] ?? 20))),
            'high_confidence_impressions' => max(10, min(500, absint($raw['high_confidence_impressions'] ?? 50))),
            'minimum_previous_base' => max(1, min(50, absint($raw['minimum_previous_base'] ?? 5))),
            'partial_match_min' => max(40, min(90, absint($raw['partial_match_min'] ?? 58))),
            'exact_match_min' => max(60, min(99, absint($raw['exact_match_min'] ?? 82))),
        );
    }
}

if (!function_exists('seo_analista_primary_query')) {
    function seo_analista_primary_query(array $row) {
        foreach (array('query','query_text') as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') return function_exists('seo_analista_clean_query') ? seo_analista_clean_query($value) : sanitize_text_field($value);
        }
        foreach (array('keywords','evidence','related_topics') as $key) {
            foreach ((array) ($row[$key] ?? array()) as $value) {
                $value = trim((string) $value);
                if ($value !== '') return function_exists('seo_analista_clean_query') ? seo_analista_clean_query($value) : sanitize_text_field($value);
            }
        }
        return function_exists('seo_analista_clean_query')
            ? seo_analista_clean_query((string) ($row['topic'] ?? ''))
            : sanitize_text_field((string) ($row['topic'] ?? ''));
    }
}

if (!function_exists('seo_analista_model_tokens')) {
    function seo_analista_model_tokens($text) {
        $text = remove_accents(strtolower(wp_strip_all_tags((string) $text)));
        $tokens = array();
        if (preg_match_all('/\b[a-z0-9]+(?:[-\/]?[a-z0-9]+)*\b/u', $text, $matches)) {
            foreach ((array) ($matches[0] ?? array()) as $token) {
                $token = trim((string) $token);
                if ($token === '' || !preg_match('/\d/', $token)) continue;
                if (preg_match('/^(\d+|\d+(mm|cm|m|kg|g|w|v|a|ah|nm|l|ml))$/', $token)) continue;
                if (strlen($token) < 4) continue;
                $tokens[] = $token;
            }
        }
        return array_values(array_unique($tokens));
    }
}

if (!function_exists('seo_analista_identity_words')) {
    function seo_analista_identity_words($text) {
        $text = function_exists('seo_analista_normalize_text')
            ? seo_analista_normalize_text($text)
            : sanitize_text_field($text);
        $stop = array(
            'profesional','professional','producto','productos','herramienta','herramientas',
            'maquina','maquinas','equipo','equipos','para','con','sin','kit','juego','set',
            'bateria','cargador','electrico','electrica','inalambrico','inalambrica'
        );
        $out = array();
        foreach (preg_split('/\s+/', $text) as $token) {
            if (strlen($token) < 3 || in_array($token, $stop, true) || ctype_digit($token)) continue;
            $out[] = $token;
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('seo_analista_resolve_target_url')) {
    function seo_analista_resolve_target_url(array $row) {
        $entity = (array) ($row['entity'] ?? array());
        $target = (array) ($row['target'] ?? array());
        $url = trim((string) ($entity['url'] ?? $target['url'] ?? ''));
        $type = sanitize_key((string) ($entity['type'] ?? ''));
        $id = absint($entity['id'] ?? 0);

        if ($url === '' && $id > 0) {
            if ($type === 'category') {
                $term_url = get_term_link($id, 'product_cat');
                if (!is_wp_error($term_url)) $url = (string) $term_url;
            } elseif (in_array($type, array('post','page','product','cluster','hub_primary','hub_secondary'), true)) {
                $candidate = get_permalink($id);
                if ($candidate) $url = (string) $candidate;
            }
        }

        if ($url === '') {
            return array('resolved'=>false,'url'=>'','canonical'=>false,'reason'=>'Destino sin resolver.');
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $own = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        $own = preg_replace('/^www\./', '', $own);
        if ($host === '' || $own === '' || $host !== $own) {
            return array('resolved'=>false,'url'=>esc_url_raw($url),'canonical'=>false,'reason'=>'La URL atribuida no pertenece al sitio canónico.');
        }

        return array('resolved'=>true,'url'=>esc_url_raw($url),'canonical'=>true,'reason'=>'URL canónica local resuelta.');
    }
}

if (!function_exists('seo_analista_entity_identity_text')) {
    function seo_analista_entity_identity_text(array $row) {
        $entity = (array) ($row['entity'] ?? array());
        $parts = array((string) ($entity['title'] ?? $row['topic'] ?? ''));
        $type = sanitize_key((string) ($entity['type'] ?? ''));
        $id = absint($entity['id'] ?? 0);

        if ($type === 'product' && $id > 0 && function_exists('wc_get_product')) {
            $product = wc_get_product($id);
            if ($product && is_a($product, 'WC_Product')) {
                $parts[] = (string) $product->get_name();
                $parts[] = (string) $product->get_sku();
                foreach (array('_mpn','mpn','_gtin','_ean','ean','_supplier_sku') as $meta_key) {
                    $parts[] = (string) get_post_meta($id, $meta_key, true);
                }
                foreach (array('pa_marca','pa_brand','marca','brand') as $taxonomy) {
                    if (!taxonomy_exists($taxonomy)) continue;
                    $terms = wp_get_post_terms($id, $taxonomy, array('fields'=>'names'));
                    if (!is_wp_error($terms)) $parts = array_merge($parts, (array) $terms);
                }
            }
        }

        return trim(implode(' ', array_filter(array_map('strval', $parts))));
    }
}

if (!function_exists('seo_analista_validate_query_entity')) {
    function seo_analista_validate_query_entity($query, array $row) {
        $query = trim((string) $query);
        $entity = (array) ($row['entity'] ?? array());
        $type = sanitize_key((string) ($entity['type'] ?? ''));
        $title = trim((string) ($entity['title'] ?? $row['topic'] ?? ''));
        $target = seo_analista_resolve_target_url($row);

        if ($query === '') {
            return array(
                'match_type'=>'unproven','match_confidence'=>20,'intent_fit'=>'unknown',
                'model_conflict'=>false,'target'=>$target,'reason'=>'No hay una consulta concreta para validar la atribución.'
            );
        }

        if (!$target['resolved'] || $title === '') {
            return array(
                'match_type'=>'unproven','match_confidence'=>15,'intent_fit'=>'unknown',
                'model_conflict'=>false,'target'=>$target,'reason'=>'La consulta existe, pero el destino no está resuelto de forma verificable.'
            );
        }

        $identity = seo_analista_entity_identity_text($row);
        $query_models = seo_analista_model_tokens($query);
        $entity_models = seo_analista_model_tokens($identity);
        $query_words = seo_analista_identity_words($query);
        $entity_words = seo_analista_identity_words($identity);
        $shared_words = array_intersect($query_words, $entity_words);

        $model_conflict = false;
        if ($query_models && $entity_models && !array_intersect($query_models, $entity_models) && $shared_words) {
            $model_conflict = true;
        }

        if ($model_conflict) {
            return array(
                'match_type'=>'conflict','match_confidence'=>5,'intent_fit'=>'low',
                'model_conflict'=>true,'target'=>$target,
                'reason'=>'Conflicto de modelo o variante: la consulta contiene identificadores distintos de los de la entidad atribuida.',
                'query_models'=>$query_models,'entity_models'=>$entity_models
            );
        }

        $similarity = function_exists('seo_analista_text_similarity')
            ? (float) seo_analista_text_similarity($query, $identity)
            : 0.0;

        if ($type === 'product' && $query_models && $entity_models && array_intersect($query_models, $entity_models)) {
            return array(
                'match_type'=>'exact','match_confidence'=>96,'intent_fit'=>'high',
                'model_conflict'=>false,'target'=>$target,'reason'=>'Modelo/identificador compatible con la entidad.',
                'similarity'=>$similarity,'query_models'=>$query_models,'entity_models'=>$entity_models
            );
        }

        // Una consulta específica de modelo no se transfiere a una categoría/hub
        // genéricos solo por compartir taxonomía.
        if (in_array($type, array('category','cluster','hub_primary','hub_secondary'), true) && $query_models && $similarity < 0.58) {
            return array(
                'match_type'=>'unproven','match_confidence'=>28,'intent_fit'=>'low',
                'model_conflict'=>false,'target'=>$target,
                'reason'=>'La consulta es específica de modelo/variante y la entidad es demasiado amplia para asumir que sea su destino SEO.',
                'similarity'=>$similarity,'query_models'=>$query_models
            );
        }

        $settings = seo_analista_validation_settings();
        $exact_min = $settings['exact_match_min'] / 100;
        $partial_min = $settings['partial_match_min'] / 100;

        if ($similarity >= $exact_min) {
            $type_label = 'exact';
            $confidence = min(94, max(82, (int) round($similarity * 100)));
            $intent_fit = 'high';
        } elseif ($similarity >= $partial_min) {
            $type_label = 'partial';
            $confidence = min(81, max(58, (int) round($similarity * 100)));
            $intent_fit = 'medium';
        } else {
            $type_label = 'unproven';
            $confidence = min(50, max(18, (int) round($similarity * 100)));
            $intent_fit = 'low';
        }

        return array(
            'match_type'=>$type_label,
            'match_confidence'=>$confidence,
            'intent_fit'=>$intent_fit,
            'model_conflict'=>false,
            'target'=>$target,
            'reason'=>$type_label === 'unproven'
                ? 'La afinidad semántica/taxonómica no basta para demostrar que esta entidad sea el destino correcto.'
                : 'La consulta y la entidad muestran una correspondencia ' . ($type_label === 'exact' ? 'fuerte' : 'parcial') . '.',
            'similarity'=>$similarity,
            'query_models'=>$query_models,
            'entity_models'=>$entity_models
        );
    }
}

if (!function_exists('seo_analista_evidence_profile')) {
    function seo_analista_evidence_profile(array $row) {
        $settings = seo_analista_validation_settings();
        $metrics = (array) ($row['metrics'] ?? array());
        $impressions = max(0, (int) round((float) ($metrics['impressions'] ?? 0)));
        $clicks = max(0, (int) round((float) ($metrics['clicks'] ?? 0)));
        $queries = max(0, (int) ($metrics['queries'] ?? count((array) ($row['keywords'] ?? array()))));
        $previous = max(0, (int) round((float) ($metrics['previous_impressions'] ?? 0)));
        $delta = isset($metrics['impressions_delta']) ? (float) $metrics['impressions_delta'] : ($impressions - $previous);
        $sources = array_values(array_unique(array_filter((array) ($row['sources'] ?? array()))));
        $independent = max(1, count($sources));
        if ($queries > 0) $independent += min(3, $queries);

        $small = $impressions > 0 && $impressions <= $settings['small_sample_max'];
        $growth_unstable = $previous < $settings['minimum_previous_base'] && abs($delta) > 0;
        $penalty = 0;
        if ($small) $penalty += 24;
        elseif ($impressions > 0 && $impressions < $settings['reliable_impressions']) $penalty += 10;
        if ($growth_unstable) $penalty += 12;

        if ($impressions >= $settings['high_confidence_impressions'] || ($clicks >= 5 && $queries >= 3)) $level = 'high';
        elseif ($impressions >= $settings['reliable_impressions'] || ($clicks >= 2 && $queries >= 2)) $level = 'medium';
        else $level = 'low';

        return array(
            'level'=>$level,
            'impressions'=>$impressions,
            'clicks'=>$clicks,
            'queries'=>$queries,
            'previous_impressions'=>$previous,
            'absolute_delta'=>$delta,
            'evidence_count'=>$independent,
            'small_sample'=>$small,
            'growth_unstable'=>$growth_unstable,
            'priority_penalty'=>$penalty,
            'settings'=>$settings
        );
    }
}

if (!function_exists('seo_analista_commercial_readiness')) {
    function seo_analista_commercial_readiness(array $row) {
        $entity = (array) ($row['entity'] ?? array());
        $type = sanitize_key((string) ($entity['type'] ?? ''));
        $id = absint($entity['id'] ?? 0);
        $out = array(
            'status'=>'unknown',
            'label'=>'Comercial no verificado',
            'stock'=>null,
            'price'=>null,
            'supplier'=>null,
            'margin_or_commission'=>null,
            'available'=>null
        );

        if ($type === 'product' && $id > 0 && function_exists('wc_get_product')) {
            $product = wc_get_product($id);
            if ($product && is_a($product, 'WC_Product')) {
                $out['stock'] = $product->is_in_stock();
                $out['available'] = $product->is_purchasable();
                $out['price'] = (float) $product->get_price();
                foreach (array('_seo_proveedor','_supplier','supplier','proveedor') as $key) {
                    $value = trim((string) get_post_meta($id, $key, true));
                    if ($value !== '') { $out['supplier'] = $value; break; }
                }
                foreach (array('_cost_price','_purchase_price','_supplier_cost','cost_price') as $key) {
                    $cost = get_post_meta($id, $key, true);
                    if ($cost !== '' && is_numeric($cost) && (float) $cost > 0 && $out['price'] > 0) {
                        $out['margin_or_commission'] = round((($out['price'] - (float) $cost) / $out['price']) * 100, 2);
                        break;
                    }
                }
                if (!$out['stock'] || !$out['available'] || $out['price'] <= 0) {
                    $out['status'] = 'not_ready';
                    $out['label'] = 'Oferta no preparada';
                } elseif ($out['supplier'] !== null && $out['margin_or_commission'] !== null) {
                    $out['status'] = 'verified';
                    $out['label'] = 'Comercial verificado';
                } else {
                    $out['status'] = 'partial';
                    $out['label'] = 'Comercial parcialmente verificado';
                }
            }
        } elseif ($type === 'category') {
            $products = isset($row['catalog']['products']) ? (int) $row['catalog']['products'] : null;
            if ($products !== null && $products > 0) {
                $out['available'] = true;
                $out['status'] = 'partial';
                $out['label'] = 'Surtido existente; margen/proveedor por validar';
            }
        }

        return $out;
    }
}

if (!function_exists('seo_analista_editorial_coverage')) {
    function seo_analista_editorial_coverage(array $row) {
        global $wpdb;
        static $cache = array();

        $entity = (array) ($row['entity'] ?? array());
        $type = sanitize_key((string) ($entity['type'] ?? ''));
        $id = absint($entity['id'] ?? 0);
        $category_ids = array();

        if ($type === 'category' && $id > 0) {
            $category_ids[] = $id;
        } elseif ($type === 'product' && $id > 0) {
            $terms = wp_get_post_terms($id, 'product_cat', array('fields'=>'ids'));
            if (!is_wp_error($terms)) $category_ids = array_map('absint', (array) $terms);
        }
        if (!empty($row['catalog']['term_id'])) $category_ids[] = absint($row['catalog']['term_id']);
        $category_ids = array_values(array_unique(array_filter($category_ids)));
        sort($category_ids);
        if (!$category_ids) return array('available'=>false,'posts'=>array(),'roles'=>array());

        $key = implode('-', $category_ids);
        if (isset($cache[$key])) return $cache[$key];

        $relations = $wpdb->prefix . 'seo_relations';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($relations)));
        if ($exists !== $relations) return $cache[$key] = array('available'=>false,'posts'=>array(),'roles'=>array());

        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $sql = "SELECT DISTINCT r.source_id
                FROM {$relations} r
                INNER JOIN {$wpdb->posts} p ON p.ID = r.source_id
                WHERE r.source_type = 'post'
                  AND r.target_type = 'product_cat'
                  AND r.relation_type = 'post_to_category'
                  AND r.target_id IN ({$placeholders})
                  AND p.post_type = 'post'
                  AND p.post_status = 'publish'
                LIMIT 20";
        $post_ids = $wpdb->get_col($wpdb->prepare($sql, $category_ids)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $roles = array();
        $posts = array();
        foreach ((array) $post_ids as $post_id) {
            $post_id = absint($post_id);
            if (!$post_id) continue;
            $role = sanitize_key((string) get_post_meta($post_id, '_seo_solucionador_content_role', true));
            if (!in_array($role, array('dependiente_qa_basic','ingeniero_qa_specialized','comparison'), true)) continue;
            $roles[] = $role;
            $posts[] = array('id'=>$post_id,'title'=>(string) get_the_title($post_id),'role'=>$role,'url'=>(string) get_permalink($post_id));
        }

        return $cache[$key] = array(
            'available'=>(bool) $posts,
            'posts'=>$posts,
            'roles'=>array_values(array_unique($roles))
        );
    }
}

if (!function_exists('seo_analista_atomic_actions')) {
    function seo_analista_atomic_actions(array $row, array $validation, array $evidence, array $commercial, array $coverage) {
        $actions = array();
        $issues = array_map('strval', (array) ($row['issues'] ?? array()));
        $issue_text = function_exists('seo_analista_normalize_text') ? seo_analista_normalize_text(implode(' ', $issues)) : strtolower(implode(' ', $issues));

        if (!empty($validation['model_conflict'])) {
            $actions[] = array('type'=>'CORREGIR_ASOCIACION','detail'=>'Revisar la atribución consulta-entidad y separar el modelo/variante ajeno antes de modificar contenido.','owner'=>'SEO/taxonomía');
            return $actions;
        }
        if (empty($validation['target']['resolved']) || ($validation['match_type'] ?? '') === 'unproven') {
            $actions[] = array('type'=>'INVESTIGAR_COBERTURA','detail'=>'Resolver primero qué URL existente debe atender esta intención y comprobar que hay surtido/contenido suficiente.','owner'=>'SEO/taxonomía');
            return $actions;
        }
        if (!empty($evidence['small_sample'])) {
            $actions[] = array('type'=>'ESPERAR_DATOS','detail'=>'La muestra es demasiado pequeña para convertir posición o crecimiento en una instrucción de ejecución.','owner'=>'SEO/taxonomía');
        }

        if (strpos($issue_text, 'seo title') !== false || strpos($issue_text, 'meta description') !== false || strpos($issue_text, 'ctr bajo') !== false) {
            $actions[] = array('type'=>'REVISAR_META','detail'=>'Comprobar primero title/meta efectivos y ajustar snippet solo si el problema está confirmado.','owner'=>'Editora');
        }
        if (strpos($issue_text, 'enlazado interno') !== false) {
            $actions[] = array('type'=>'REVISAR_ENLAZADO','detail'=>'Añadir o corregir enlaces internos únicamente desde entidades semánticamente relacionadas.','owner'=>'SEO/taxonomía');
        }
        if (strpos($issue_text, 'cobertura textual') !== false || strpos($issue_text, 'excerpt') !== false || strpos($issue_text, 'vocabulary') !== false) {
            $actions[] = array('type'=>'MEJORAR_COBERTURA','detail'=>'Cubrir el hueco concreto demostrado por consultas/intención sin ampliar la URL a temas ajenos.','owner'=>'Editora');
        }
        if (strpos($issue_text, 'descripcion corta') !== false || sanitize_key((string) ($row['entity']['type'] ?? '')) === 'product') {
            if ($issues) $actions[] = array('type'=>'MEJORAR_FICHA','detail'=>'Corregir únicamente los defectos de ficha demostrados por la evidencia y la intención atribuida.','owner'=>'Editora');
        }

        if (in_array((string) ($commercial['status'] ?? 'unknown'), array('unknown','partial','not_ready'), true)
            && (string) ($row['objective']['primary'] ?? '') === 'sales') {
            $actions[] = array('type'=>'REVISAR_OFERTA_PRECIO','detail'=>'Validar stock, proveedor, precio final y margen/comisión antes de tratar el trabajo como oportunidad de ventas.','owner'=>'Catálogo/proveedores');
        }

        $legacy_action = (string) ($row['action'] ?? '');
        if (in_array($legacy_action, array('AMPLIAR_PRODUCTOS','MEJORAR_CATEGORIA_SURTIDO','REVISAR_CATEGORIA_SURTIDO','INVESTIGAR_CATALOGO'), true)) {
            $actions[] = array('type'=>'INVESTIGAR_SURTIDO','detail'=>'Comprobar productos reales, variantes y proveedor antes de ampliar catálogo.','owner'=>'Catálogo/proveedores');
        }

        if (strpos($legacy_action, 'CREAR_') === 0) {
            if (!empty($coverage['available'])) {
                $actions[] = array('type'=>'ACTUALIZAR_CONTENIDO','detail'=>'Ya existe contenido relacionado de Dependiente/Ingeniero/Comparador: revisar actualización o enlazado antes de abrir otra URL.','owner'=>'Editora');
            } else {
                $actions[] = array('type'=>'CREAR_CONTENIDO','detail'=>'Crear contenido solo tras confirmar destino, ausencia de cobertura equivalente y revisión editorial.','owner'=>'Editora');
            }
        }

        if (!$actions) {
            $actions[] = array('type'=>'VIGILAR','detail'=>'No hay un cambio atómico suficientemente demostrado; conservar la señal y revisar cuando aumente la evidencia.','owner'=>'SEO/taxonomía');
        }

        $seen = array();
        $unique = array();
        foreach ($actions as $action) {
            $key = (string) ($action['type'] ?? '');
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $action;
        }
        return $unique;
    }
}

if (!function_exists('seo_analista_has_independent_critical_issue')) {
    function seo_analista_has_independent_critical_issue(array $row) {
        $text = function_exists('seo_analista_normalize_text')
            ? seo_analista_normalize_text(implode(' ', (array) ($row['issues'] ?? array())))
            : strtolower(implode(' ', (array) ($row['issues'] ?? array())));
        foreach (array('producto sin categoria','nodo estructural sin descendencia','url rota','error 404','no indexable') as $needle) {
            if (strpos($text, $needle) !== false) return true;
        }
        return false;
    }
}

if (!function_exists('seo_analista_hydrate_entity_from_target')) {
    function seo_analista_hydrate_entity_from_target(array $row) {
        $entity = (array) ($row['entity'] ?? array());
        if (!empty($entity['type']) && (!empty($entity['id']) || !empty($entity['url']))) return $row;

        $term_id = absint($row['catalog']['term_id'] ?? 0);
        if ($term_id > 0) {
            $term = get_term($term_id, 'product_cat');
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                $url = get_term_link($term);
                if (!is_wp_error($url)) {
                    $row['entity'] = array(
                        'type'=>'category',
                        'type_label'=>'Categoría',
                        'id'=>$term_id,
                        'title'=>(string) $term->name,
                        'url'=>(string) $url,
                        'edit_url'=>admin_url('term.php?taxonomy=product_cat&tag_ID=' . $term_id . '&post_type=product'),
                    );
                    $row['target'] = array(
                        'title'=>(string) $term->name,
                        'url'=>(string) $url,
                    );
                    return $row;
                }
            }
        }

        $target_url = trim((string) ($row['target']['url'] ?? ''));
        if ($target_url !== '') {
            $post_id = url_to_postid($target_url);
            if ($post_id > 0) {
                $post_type = get_post_type($post_id);
                $type = $post_type === 'product' ? 'product' : ($post_type === 'page' ? 'page' : ($post_type === 'post' ? 'post' : ''));
                if ($type !== '') {
                    $row['entity'] = array(
                        'type'=>$type,
                        'type_label'=>ucfirst($type),
                        'id'=>$post_id,
                        'title'=>(string) get_the_title($post_id),
                        'url'=>(string) get_permalink($post_id),
                        'edit_url'=>(string) get_edit_post_link($post_id, ''),
                    );
                }
            }
        }
        return $row;
    }
}

if (!function_exists('seo_analista_validate_and_finalize_task')) {
    function seo_analista_validate_and_finalize_task(array $row, $days = 28) {
        $row = seo_analista_hydrate_entity_from_target($row);
        $query = seo_analista_primary_query($row);
        $validation = seo_analista_validate_query_entity($query, $row);
        $evidence = seo_analista_evidence_profile($row);
        $commercial = seo_analista_commercial_readiness($row);
        $coverage = seo_analista_editorial_coverage($row);

        $priority = max(0, min(99, (int) ($row['priority'] ?? 0)));
        $penalties = array();
        if (!empty($evidence['priority_penalty'])) $penalties['evidence'] = -absint($evidence['priority_penalty']);
        if (($validation['match_type'] ?? '') === 'partial') $penalties['partial_match'] = -6;
        elseif (($validation['match_type'] ?? '') === 'unproven') $penalties['unproven_match'] = -20;
        elseif (($validation['match_type'] ?? '') === 'conflict') $penalties['model_conflict'] = -35;
        if ((string) ($row['objective']['primary'] ?? '') === 'sales' && ($commercial['status'] ?? 'unknown') === 'unknown') $penalties['commercial_unknown'] = -8;
        if ((string) ($row['objective']['primary'] ?? '') === 'sales' && ($commercial['status'] ?? '') === 'not_ready') $penalties['commercial_not_ready'] = -16;

        $validated_priority = max(0, min(99, $priority + array_sum($penalties)));
        $confidence = max(0, min(100, (int) ($row['confidence'] ?? 0)));
        $confidence -= absint($evidence['priority_penalty'] ?? 0);
        if (($validation['match_type'] ?? '') === 'partial') $confidence -= 8;
        elseif (($validation['match_type'] ?? '') === 'unproven') $confidence -= 25;
        elseif (($validation['match_type'] ?? '') === 'conflict') $confidence = min($confidence, 15);
        $confidence = max(0, min(100, $confidence));
        $confidence_level = $confidence >= 75 ? 'high' : ($confidence >= 50 ? 'medium' : 'low');

        $pre_validation_bucket = (string) ($row['work_bucket'] ?? 'VIGILAR');
        $bucket = $pre_validation_bucket;
        if (($validation['match_type'] ?? '') === 'conflict') {
            $bucket = 'INVESTIGAR';
        } elseif (empty($validation['target']['resolved']) || ($validation['match_type'] ?? '') === 'unproven') {
            $bucket = 'INVESTIGAR';
        } elseif (!empty($evidence['small_sample'])) {
            $bucket = seo_analista_has_independent_critical_issue($row) ? 'VIGILAR' : 'ESPERAR_DATOS';
        } elseif ($confidence_level === 'low' && $bucket === 'HACER_AHORA') {
            $bucket = 'HACER_DESPUES';
        }

        if ((string) ($row['objective']['primary'] ?? '') === 'sales'
            && in_array((string) ($commercial['status'] ?? 'unknown'), array('unknown','not_ready'), true)
            && $bucket === 'HACER_AHORA') {
            $bucket = 'INVESTIGAR';
        }

        $atomic = seo_analista_atomic_actions($row, $validation, $evidence, $commercial, $coverage);
        $action_type = (string) ($atomic[0]['type'] ?? 'VIGILAR');
        if ($action_type === 'ESPERAR_DATOS') $bucket = 'ESPERAR_DATOS';
        if ($action_type === 'CORREGIR_ASOCIACION' || $action_type === 'INVESTIGAR_COBERTURA') $bucket = 'INVESTIGAR';

        $owner = (string) ($atomic[0]['owner'] ?? 'SEO/taxonomía');
        $dependencies = array();
        if (empty($validation['target']['resolved'])) $dependencies[] = 'Resolver URL objetivo';
        if (($validation['match_type'] ?? '') !== 'exact') $dependencies[] = 'Validar correspondencia consulta-entidad';
        if (($commercial['status'] ?? 'unknown') !== 'verified' && (string) ($row['objective']['primary'] ?? '') === 'sales') $dependencies[] = 'Validar oferta comercial';
        if (!empty($coverage['available'])) $dependencies[] = 'Revisar cobertura editorial existente';

        $entity = (array) ($row['entity'] ?? array());
        $task_seed = implode('|', array(
            sanitize_key((string) ($entity['type'] ?? 'unknown')),
            absint($entity['id'] ?? 0),
            (string) ($validation['target']['url'] ?? ''),
            $query,
            $action_type,
        ));
        $task_id = 'ana381_' . substr(hash('sha256', $task_seed), 0, 20);

        $period = array(
            'days'=>absint($days),
            'label'=>absint($days) . ' días',
        );
        if (!empty($row['period']) && is_array($row['period'])) $period = array_merge($period, $row['period']);

        $baseline = array(
            'captured_at'=>current_time('mysql'),
            'impressions'=>(float) ($row['metrics']['impressions'] ?? 0),
            'clicks'=>(float) ($row['metrics']['clicks'] ?? 0),
            'ctr'=>(float) ($row['metrics']['ctr'] ?? 0),
            'position'=>(float) ($row['metrics']['position'] ?? 0),
            'previous_impressions'=>(float) ($evidence['previous_impressions'] ?? 0),
            'absolute_delta'=>(float) ($evidence['absolute_delta'] ?? 0),
        );

        $row['priority'] = $validated_priority;
        $row['priority_score'] = $validated_priority;
        $row['work_bucket'] = $bucket;
        $row['pre_validation_bucket'] = $pre_validation_bucket;
        $row['confidence'] = $confidence;
        $row['confidence_level'] = $confidence_level;
        $row['query'] = $query;
        $row['match_type'] = (string) ($validation['match_type'] ?? 'unproven');
        $row['match_confidence'] = absint($validation['match_confidence'] ?? 0);
        $row['intent_fit'] = (string) ($validation['intent_fit'] ?? 'unknown');
        $row['target_url'] = (string) ($validation['target']['url'] ?? '');
        $row['target_resolved'] = !empty($validation['target']['resolved']);
        $row['match_reason'] = (string) ($validation['reason'] ?? '');
        $row['evidence_volume'] = $evidence;
        $row['commercial_readiness'] = $commercial;
        $row['editorial_coverage'] = $coverage;
        $row['action_type'] = $action_type;
        $row['action_details'] = $atomic;
        $row['recommended_owner'] = $owner;
        $row['owner'] = $owner;
        $row['dependencies'] = array_values(array_unique($dependencies));
        $row['status'] = 'proposed';
        $row['task_id'] = $task_id;
        $row['period'] = $period;
        $row['baseline'] = $baseline;
        $row['verification_date'] = gmdate('Y-m-d', strtotime('+28 days'));
        $row['review_dates'] = array(
            '28d'=>gmdate('Y-m-d', strtotime('+28 days')),
            '60d'=>gmdate('Y-m-d', strtotime('+60 days')),
            '90d'=>gmdate('Y-m-d', strtotime('+90 days')),
        );
        $strategy_breakdown = (array) ($row['priority_breakdown'] ?? array());
        $strategy_breakdown['pre_validation_score'] = $priority;
        $strategy_breakdown['validation_penalties'] = $penalties;
        $strategy_breakdown['final_score'] = $validated_priority;
        $strategy_breakdown['rule'] = '86% impacto objetivo + 14% confianza + ajustes de posición/familia/catálogo/señal; después penalizaciones explícitas de evidencia, matching y preparación comercial.';
        $row['priority_breakdown'] = $strategy_breakdown;

        // La accion visible debe reflejar la accion atomica segura, no una
        // recomendacion legacy mas agresiva.
        $row['legacy_action'] = (string) ($row['action'] ?? '');
        $row['action'] = $action_type;
        if (function_exists('seo_analista_action_meta')) {
            $meta = seo_analista_action_meta($action_type);
            $row['action_label'] = (string) ($meta['label'] ?? $action_type);
            $row['channel'] = (string) ($meta['channel'] ?? ($row['channel'] ?? 'seo'));
        }
        $row['recommended_changes'] = array_values(array_unique(array_map(
            static function($action){ return (string) ($action['detail'] ?? ''); },
            $atomic
        )));

        return $row;
    }
}

if (!function_exists('seo_analista_task_contract')) {
    function seo_analista_task_contract(array $row) {
        $entity = (array) ($row['entity'] ?? array());
        return array(
            'task_id'=>(string) ($row['task_id'] ?? ''),
            'entity_type'=>(string) ($entity['type'] ?? ''),
            'entity_id'=>absint($entity['id'] ?? 0),
            'target_url'=>(string) ($row['target_url'] ?? ''),
            'query'=>(string) ($row['query'] ?? ''),
            'match_type'=>(string) ($row['match_type'] ?? 'unproven'),
            'match_confidence'=>absint($row['match_confidence'] ?? 0),
            'intent_fit'=>(string) ($row['intent_fit'] ?? 'unknown'),
            'evidence_count'=>absint($row['evidence_volume']['evidence_count'] ?? 0),
            'impressions'=>(float) ($row['metrics']['impressions'] ?? 0),
            'clicks'=>(float) ($row['metrics']['clicks'] ?? 0),
            'ctr'=>(float) ($row['metrics']['ctr'] ?? 0),
            'position'=>(float) ($row['metrics']['position'] ?? 0),
            'period'=>(array) ($row['period'] ?? array()),
            'priority_score'=>absint($row['priority_score'] ?? $row['priority'] ?? 0),
            'priority_breakdown'=>(array) ($row['priority_breakdown'] ?? array()),
            'pre_validation_bucket'=>(string) ($row['pre_validation_bucket'] ?? ''),
            'final_bucket'=>(string) ($row['work_bucket'] ?? ''),
            'confidence_level'=>(string) ($row['confidence_level'] ?? 'low'),
            'confidence'=>absint($row['confidence'] ?? 0),
            'objective'=>(array) ($row['objective'] ?? array()),
            'commercial_readiness'=>(array) ($row['commercial_readiness'] ?? array()),
            'action_type'=>(string) ($row['action_type'] ?? $row['action'] ?? ''),
            'action_details'=>(array) ($row['action_details'] ?? array()),
            'dependencies'=>(array) ($row['dependencies'] ?? array()),
            'owner'=>(string) ($row['recommended_owner'] ?? $row['owner'] ?? ''),
            'status'=>(string) ($row['status'] ?? 'proposed'),
            'baseline'=>(array) ($row['baseline'] ?? array()),
            'verification_date'=>(string) ($row['verification_date'] ?? ''),
            'review_dates'=>(array) ($row['review_dates'] ?? array()),
        );
    }
}

if (!function_exists('seo_analista_persist_task_baselines')) {
    function seo_analista_persist_task_baselines(array $plan) {
        $history = get_option(SEO_ANALISTA_TASK_HISTORY_OPTION, array());
        $history = is_array($history) ? $history : array();
        $now = current_time('mysql');

        foreach ($plan as $row) {
            $task = seo_analista_task_contract((array) $row);
            $id = (string) ($task['task_id'] ?? '');
            if ($id === '') continue;
            $existing = (array) ($history[$id] ?? array());
            if (empty($existing['first_seen'])) {
                $existing['first_seen'] = $now;
                $existing['baseline'] = (array) ($task['baseline'] ?? array());
                $existing['status'] = 'proposed';
                $existing['action_executed'] = '';
            }

            /*
             * Analista no administra tareas: Auditor u otra capa de workflow
             * puede aportar estado/accion ejecutada mediante este filtro.
             */
            $tracking = apply_filters(
                'seo_analista_task_tracking_state',
                array(
                    'status'=>(string) ($existing['status'] ?? 'proposed'),
                    'action_executed'=>(string) ($existing['action_executed'] ?? ''),
                ),
                $task,
                $existing
            );
            if (is_array($tracking)) {
                $candidate_status = sanitize_key((string) ($tracking['status'] ?? 'proposed'));
                if (in_array($candidate_status, array('proposed','queued','in_progress','executed','dismissed'), true)) {
                    $existing['status'] = $candidate_status;
                }
                $existing['action_executed'] = sanitize_text_field((string) ($tracking['action_executed'] ?? ''));
            }

            $existing['last_seen'] = $now;
            $existing['last_task'] = $task;
            $existing['review_dates'] = (array) ($task['review_dates'] ?? array());
            $history[$id] = $existing;
        }

        if (count($history) > 500) {
            uasort($history, static function($a,$b){
                return strcmp((string) ($b['last_seen'] ?? ''), (string) ($a['last_seen'] ?? ''));
            });
            $history = array_slice($history, 0, 500, true);
        }
        update_option(SEO_ANALISTA_TASK_HISTORY_OPTION, $history, false);
        return $history;
    }
}

if (!function_exists('seo_analista_task_history')) {
    function seo_analista_task_history($task_id = '') {
        $history = get_option(SEO_ANALISTA_TASK_HISTORY_OPTION, array());
        $history = is_array($history) ? $history : array();
        if ($task_id === '') return $history;
        return (array) ($history[sanitize_key($task_id)] ?? array());
    }
}

if (!function_exists('seo_analista_action_scheduler_health')) {
    function seo_analista_action_scheduler_health() {
        $out = array('available'=>false,'overdue'=>0,'state'=>'unknown','detail'=>'Action Scheduler no disponible.');
        if (!class_exists('ActionScheduler') || !class_exists('ActionScheduler_Store')) return $out;
        try {
            $store = ActionScheduler::store();
            if (!$store || !method_exists($store, 'query_actions')) return $out;
            $ids = $store->query_actions(array(
                'status'=>ActionScheduler_Store::STATUS_PENDING,
                'per_page'=>50,
                'orderby'=>'date',
                'order'=>'ASC',
            ));
            $now = time();
            $overdue = 0;
            foreach ((array) $ids as $id) {
                $action = $store->fetch_action($id);
                if (!$action || !method_exists($action, 'get_schedule')) continue;
                $schedule = $action->get_schedule();
                if (!$schedule || !method_exists($schedule, 'get_date')) continue;
                $date = $schedule->get_date();
                if ($date && method_exists($date, 'getTimestamp') && $date->getTimestamp() < $now) $overdue++;
            }
            $out['available'] = true;
            $out['overdue'] = $overdue;
            $out['state'] = $overdue > 0 ? 'warning' : 'ok';
            $out['detail'] = $overdue > 0
                ? $overdue . ' acciones pendientes aparecen vencidas; revisar si afectan a la frescura de fuentes.'
                : 'No se observan acciones pendientes vencidas en la muestra consultada.';
        } catch (Throwable $e) {
            $out['detail'] = 'No se pudo comprobar Action Scheduler: ' . $e->getMessage();
        }
        return $out;
    }
}

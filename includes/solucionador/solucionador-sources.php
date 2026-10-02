<?php
/**
 * Solucionador - adaptadores de fuentes locales.
 *
 * v0.4.2 conserva la demanda real del log de Dependiente V3 y añade, como
 * fuente editorial separada, el conocimiento aprendido por Academia. Solucionador
 * no modifica Dependiente: consume preguntas cuyo ultimo run esta validado pass_*,
 * resuelve product_cat y construye dossiers category-first.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Sources {
    private static function table_exists($table) {
        return SEO_Solucionador_DB::table_exists($table);
    }

    private static function decode($value) {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static $dependiente_academia_cache = null;

    private static function origin_flag($value = true) {
        return $value ? 'origin' : 'reinforcement';
    }

    private static function valid_product_cat_ids(array $ids) {
        $out = array();
        foreach (array_unique(array_filter(array_map('absint', $ids))) as $term_id) {
            $term = get_term($term_id, 'product_cat');
            if ($term && !is_wp_error($term)) $out[] = $term_id;
        }
        return array_values(array_unique($out));
    }

    private static function product_category_ids($product_id) {
        $product_id = absint($product_id);
        if (!$product_id || get_post_type($product_id) !== 'product') return array();
        $ids = wp_get_post_terms($product_id, 'product_cat', array('fields'=>'ids'));
        if (is_wp_error($ids)) return array();
        return self::valid_product_cat_ids((array) $ids);
    }

    /**
     * Resuelve una pregunta de Academia a product_cat sin inferencias semanticas.
     * Solo acepta relaciones demostrables por expected/source/FAQ/producto.
     */
    private static function academy_category_ids(array $row, array $expected) {
        global $wpdb;

        $ids = array();
        $kind = sanitize_key((string) ($expected['kind'] ?? ''));
        $source_type = sanitize_key((string) ($row['source_type'] ?? ''));
        $source_id = absint($row['source_id'] ?? 0);

        if (!empty($expected['category_id'])) $ids[] = absint($expected['category_id']);

        if ($kind === 'category') {
            $ids[] = absint($expected['category_id'] ?? 0);
        } elseif ($kind === 'product') {
            $ids = array_merge($ids, self::product_category_ids(absint($expected['product_id'] ?? 0)));
        } elseif ($kind === 'features') {
            $ids = array_merge($ids, self::product_category_ids(absint($expected['source_product_id'] ?? 0)));
        } elseif ($kind === 'faq') {
            $owner_type = absint($expected['owner_type'] ?? 0);
            $owner_id = absint($expected['owner_id'] ?? 0);
            if ($owner_type === 2) {
                $ids[] = $owner_id;
            } elseif ($owner_type === 3) {
                $ids = array_merge($ids, self::product_category_ids($owner_id));
            }
        }

        if (!empty($expected['source_product_id'])) {
            $ids = array_merge($ids, self::product_category_ids(absint($expected['source_product_id'])));
        }

        if ($source_type === 'category' && $source_id) {
            $ids[] = $source_id;
        } elseif (in_array($source_type, array('product','features'), true) && $source_id) {
            $ids = array_merge($ids, self::product_category_ids($source_id));
        } elseif ($source_type === 'faq' && $source_id && $kind !== 'faq') {
            $faq_table = $wpdb->prefix . 'seo_faq';
            if (self::table_exists($faq_table)) {
                $faq = $wpdb->get_row($wpdb->prepare(
                    "SELECT object_type,object_id FROM {$faq_table} WHERE id=%d LIMIT 1",
                    $source_id
                ), ARRAY_A);
                if ($faq) {
                    $owner_type = absint($faq['object_type'] ?? 0);
                    $owner_id = absint($faq['object_id'] ?? 0);
                    if ($owner_type === 2) $ids[] = $owner_id;
                    elseif ($owner_type === 3) $ids = array_merge($ids, self::product_category_ids($owner_id));
                }
            }
        }

        return self::valid_product_cat_ids($ids);
    }

    private static function dependiente_academia_data() {
        if (self::$dependiente_academia_cache !== null) {
            return self::$dependiente_academia_cache;
        }

        global $wpdb;
        $questions = $wpdb->prefix . 'seo_dependiente_trainer_questions';
        $runs = $wpdb->prefix . 'seo_dependiente_trainer_runs';

        $empty = array(
            'stats'=>array(
                'available'=>false,
                'questions_total'=>0,
                'learned'=>0,
                'not_learned'=>0,
                'learned_with_category'=>0,
                'learned_without_category'=>0,
                'categories_with_knowledge'=>0,
                'categories_total'=>0,
                'categories_without_knowledge'=>0,
                'avg_questions_per_category'=>0,
                'last_run_at'=>'',
            ),
            'dossiers'=>array(),
        );

        if (!self::table_exists($questions) || !self::table_exists($runs)) {
            self::$dependiente_academia_cache = $empty;
            return $empty;
        }

        $where = "q.enabled=1 AND q.lesson_key<>'' AND q.lesson_key NOT LIKE 'lab\\_%'";

        $total = absint($wpdb->get_var(
            "SELECT COUNT(*) FROM {$questions} q WHERE {$where}"
        ));

        $aggregate = $wpdb->get_row(
            "SELECT
                COUNT(r.id) evaluated,
                SUM(CASE WHEN r.status='answered' AND LEFT(COALESCE(r.evaluation_status,''),5)='pass_' THEN 1 ELSE 0 END) learned,
                MAX(r.created_at) last_run_at
             FROM {$questions} q
             LEFT JOIN (
                SELECT question_id,MAX(id) latest_run_id
                FROM {$runs}
                WHERE question_id IS NOT NULL
                GROUP BY question_id
             ) latest ON latest.question_id=q.id
             LEFT JOIN {$runs} r ON r.id=latest.latest_run_id
             WHERE {$where}",
            ARRAY_A
        );
        $learned_total = absint($aggregate['learned'] ?? 0);

        $rows = (array) $wpdb->get_results(
            "SELECT
                q.id question_id,
                q.lesson_key,
                q.lesson_order,
                q.module_no,
                q.source_type,
                q.source_id,
                q.source_key,
                q.question_type,
                q.mode,
                q.question,
                q.expected_json,
                r.id run_id,
                r.status run_status,
                r.search_strategy,
                r.evaluation_status,
                r.evaluation_score,
                r.evaluation_json,
                r.top_results,
                r.response_meta,
                r.created_at run_created_at
             FROM {$questions} q
             INNER JOIN (
                SELECT question_id,MAX(id) latest_run_id
                FROM {$runs}
                WHERE question_id IS NOT NULL
                GROUP BY question_id
             ) latest ON latest.question_id=q.id
             INNER JOIN {$runs} r ON r.id=latest.latest_run_id
             WHERE {$where}
               AND r.status='answered'
               AND LEFT(COALESCE(r.evaluation_status,''),5)='pass_'
             ORDER BY q.lesson_order ASC,q.id ASC",
            ARRAY_A
        );

        $by_category = array();
        $learned_with_category = 0;
        $learned_without_category = 0;
        $assignments = 0;

        foreach ($rows as $row) {
            $expected = self::decode($row['expected_json'] ?? '');
            $category_ids = self::academy_category_ids($row, $expected);
            if (!$category_ids) {
                $learned_without_category++;
                continue;
            }
            $learned_with_category++;

            $detail = array(
                'question_id'=>absint($row['question_id'] ?? 0),
                'run_id'=>absint($row['run_id'] ?? 0),
                'question'=>sanitize_text_field((string) ($row['question'] ?? '')),
                'question_type'=>sanitize_key((string) ($row['question_type'] ?? '')),
                'lesson_key'=>sanitize_key((string) ($row['lesson_key'] ?? '')),
                'module_no'=>absint($row['module_no'] ?? 0),
                'source_type'=>sanitize_key((string) ($row['source_type'] ?? '')),
                'source_id'=>absint($row['source_id'] ?? 0) ?: null,
                'source_key'=>sanitize_text_field((string) ($row['source_key'] ?? '')),
                'expected'=>$expected,
                'evaluation_status'=>sanitize_key((string) ($row['evaluation_status'] ?? '')),
                'evaluation_score'=>max(0,min(1,(float) ($row['evaluation_score'] ?? 0))),
                'evaluation'=>self::decode($row['evaluation_json'] ?? ''),
                'top_results'=>array_values(array_slice(self::decode($row['top_results'] ?? ''),0,12)),
                'response_meta'=>self::decode($row['response_meta'] ?? ''),
                'search_strategy'=>sanitize_key((string) ($row['search_strategy'] ?? '')),
                'observed_at'=>sanitize_text_field((string) ($row['run_created_at'] ?? '')),
            );

            foreach ($category_ids as $term_id) {
                if (!isset($by_category[$term_id])) {
                    $term = get_term($term_id,'product_cat');
                    $by_category[$term_id] = array(
                        'category_id'=>$term_id,
                        'category_name'=>$term && !is_wp_error($term) ? (string) $term->name : ('Categoría #' . $term_id),
                        'questions'=>array(),
                        'last_run_at'=>'',
                        'score_total'=>0.0,
                    );
                }
                $by_category[$term_id]['questions'][$detail['question_id']] = $detail;
                if ($detail['observed_at'] > $by_category[$term_id]['last_run_at']) {
                    $by_category[$term_id]['last_run_at'] = $detail['observed_at'];
                }
                $by_category[$term_id]['score_total'] += (float) $detail['evaluation_score'];
                $assignments++;
            }
        }

        $dossiers = array();
        foreach ($by_category as $term_id=>$dossier) {
            $items = array_values($dossier['questions']);
            $count = count($items);
            if (!$count) continue;
            $avg_score = $count > 0 ? ((float) $dossier['score_total'] / $count) : 0.0;
            $dossiers[] = array(
                'category_id'=>absint($term_id),
                'category_name'=>(string) $dossier['category_name'],
                'question_count'=>$count,
                'questions'=>$items,
                'confidence'=>max(0.60,min(1.0,$avg_score > 0 ? $avg_score : 0.90)),
                'last_run_at'=>(string) $dossier['last_run_at'],
            );
        }
        usort($dossiers,static function($a,$b){
            $cmp = absint($b['question_count'] ?? 0) <=> absint($a['question_count'] ?? 0);
            if ($cmp !== 0) return $cmp;
            return strcasecmp((string)($a['category_name'] ?? ''),(string)($b['category_name'] ?? ''));
        });

        $categories_total = wp_count_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
        $categories_total = is_wp_error($categories_total) ? 0 : absint($categories_total);
        $categories_with = count($dossiers);

        self::$dependiente_academia_cache = array(
            'stats'=>array(
                'available'=>true,
                'questions_total'=>$total,
                'learned'=>$learned_total,
                'not_learned'=>max(0,$total-$learned_total),
                'learned_with_category'=>$learned_with_category,
                'learned_without_category'=>$learned_without_category,
                'categories_with_knowledge'=>$categories_with,
                'categories_total'=>$categories_total,
                'categories_without_knowledge'=>max(0,$categories_total-$categories_with),
                'avg_questions_per_category'=>$categories_with ? round($assignments/$categories_with,2) : 0,
                'last_run_at'=>(string) ($aggregate['last_run_at'] ?? ''),
            ),
            'dossiers'=>$dossiers,
        );
        return self::$dependiente_academia_cache;
    }

    public static function dependiente_academia_snapshot() {
        $data = self::dependiente_academia_data();
        return (array) ($data['stats'] ?? array());
    }

    private static function dependiente_academia_dossiers() {
        $data = self::dependiente_academia_data();
        $out = array();
        foreach ((array) ($data['dossiers'] ?? array()) as $dossier) {
            $term_id = absint($dossier['category_id'] ?? 0);
            $name = trim((string) ($dossier['category_name'] ?? ''));
            $count = absint($dossier['question_count'] ?? 0);
            if (!$term_id || $name === '' || !$count) continue;

            $out[] = array(
                'source_type'=>'dependiente',
                'proposal_role'=>'origin',
                'source_id'=>'academy-category:' . $term_id,
                'signal_type'=>'learned_category_dossier',
                'entity_type'=>'product_cat',
                'entity_id'=>$term_id,
                'category_id'=>$term_id,
                'category_name'=>$name,
                'source_text'=>'Preguntas habituales sobre ' . $name . ': conocimiento aprendido por Dependiente para elección, uso y compatibilidad.',
                'hints'=>array(
                    'intent'=>'dependiente_qa_basic',
                    'action'=>'resolver',
                    'object'=>$name,
                    'category_id'=>$term_id,
                ),
                'occurrences'=>$count,
                'confidence'=>(float) ($dossier['confidence'] ?? 0.90),
                'evidence_score'=>1.00,
                'observed_at'=>(string) ($dossier['last_run_at'] ?? current_time('mysql')),
                'source_meta'=>array(
                    'proposal_role'=>'origin',
                    'dependiente_channel'=>'academy_learned_dossier',
                    'editorial_family'=>'dependiente_qa_basic',
                    'category_id'=>$term_id,
                    'category_name'=>$name,
                    'question_count'=>$count,
                    'academy_questions'=>array_values((array) ($dossier['questions'] ?? array())),
                    'last_validated_at'=>(string) ($dossier['last_run_at'] ?? ''),
                    'confidence'=>(float) ($dossier['confidence'] ?? 0.90),
                ),
            );
        }
        return $out;
    }

    public static function dependiente($days = 180, $limit = 1600) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_dependiente_search_log';

        $days = min(365, max(7, absint($days)));
        $limit = min(3000, max(50, absint($limit)));
        // Academia aporta lo que Dependiente ya sabe; search_log conserva por
        // separado lo que los visitantes preguntan. Ambos son señales distintas.
        $out = self::dependiente_academia_dossiers();
        $seen = array();
        $out_index = array();

        // Demanda real: log canonico del Dependiente. Desde v0.2.4 V3
        // registra aqui cada consulta publica sin activar aprendizaje legacy.
        if (self::table_exists($table)) {
            $sql = "SELECT
                        s.id source_id,
                        s.query_original source_text,
                        s.query_normalized normalized_text,
                        s.detected_intent,
                        s.detected_object,
                        s.detected_context,
                        s.detected_state,
                        s.clicked_product_id,
                        s.top_results,
                        s.semantic_analysis,
                        s.strategy_detail,
                        s.candidate_count,
                        s.result_count,
                        s.created_at observed_at,
                        a.occurrences,
                        a.zero_results,
                        a.negative_feedback
                    FROM {$table} s
                    INNER JOIN (
                        SELECT
                            MAX(id) last_id,
                            COUNT(*) occurrences,
                            SUM(CASE WHEN candidate_count=0 THEN 1 ELSE 0 END) zero_results,
                            SUM(CASE WHEN feedback<0 THEN 1 ELSE 0 END) negative_feedback
                        FROM {$table}
                        WHERE request_kind='search'
                          AND created_at >= DATE_SUB(%s, INTERVAL %d DAY)
                        GROUP BY COALESCE(NULLIF(semantic_signature,''),query_hash),
                                 COALESCE(detected_intent,''),COALESCE(detected_object,''),
                                 COALESCE(detected_context,''),COALESCE(detected_state,'')
                    ) a ON a.last_id=s.id
                    ORDER BY a.occurrences DESC,a.zero_results DESC,a.negative_feedback DESC,s.id DESC
                    LIMIT %d";

            $rows = (array) $wpdb->get_results(
                $wpdb->prepare($sql, current_time('mysql'), $days, $limit),
                ARRAY_A
            );

            foreach ($rows as $row) {
                $text = (string) ($row['source_text'] ?? '');
                if (!SEO_Solucionador_Normalizer::is_solution_signal(
                    $text,
                    (string) ($row['detected_intent'] ?? ''),
                    (string) ($row['detected_state'] ?? '')
                )) continue;

                $semantic = self::decode($row['semantic_analysis'] ?? '');
                $strategy_detail = self::decode($row['strategy_detail'] ?? '');
                $matches = array_values(array_slice((array) ($semantic['matches'] ?? array()), 0, 24));
                $top_results = array_values(array_slice(self::decode($row['top_results'] ?? ''), 0, 12));
                $normalized = (string) ($row['normalized_text'] ?? SEO_Solucionador_Normalizer::normalize($text));
                $seen[$normalized] = true;

                $out[] = array(
                    'source_type' => 'dependiente',
                    'proposal_role' => self::origin_flag(true),
                    'source_id' => 'search:' . md5(implode('|', array(
                        $normalized,
                        (string) ($row['detected_intent'] ?? ''),
                        (string) ($row['detected_object'] ?? ''),
                        (string) ($row['detected_context'] ?? ''),
                        (string) ($row['detected_state'] ?? ''),
                    ))),
                    'source_text' => $text,
                    'hints' => array(
                        'intent' => (string) ($row['detected_intent'] ?? ''),
                        'object' => (string) ($row['detected_object'] ?? ''),
                        'context' => (string) ($row['detected_context'] ?? ''),
                        'state' => (string) ($row['detected_state'] ?? ''),
                    ),
                    'occurrences' => max(1, absint($row['occurrences'] ?? 1)),
                    'evidence_score' => 1.00,
                    'observed_at' => (string) ($row['observed_at'] ?? ''),
                    'source_meta' => array(
                        'proposal_role' => 'origin',
                        'dependiente_channel' => sanitize_key((string) ($strategy_detail['runtime'] ?? 'structured_search_log')) ?: 'structured_search_log',
                        'last_log_id' => absint($row['source_id'] ?? 0),
                        'zero_results' => absint($row['zero_results'] ?? 0),
                        'negative_feedback' => absint($row['negative_feedback'] ?? 0),
                        'candidate_count' => absint($row['candidate_count'] ?? 0),
                        'shown_results' => absint($row['result_count'] ?? 0),
                        'clicked_product_id' => absint($row['clicked_product_id'] ?? 0),
                        'top_results' => $top_results,
                        'semantic_matches' => $matches,
                        'actions' => array_values(array_slice((array) ($semantic['actions'] ?? array()), 0, 12)),
                        'decision' => is_array($strategy_detail['decision'] ?? null) ? $strategy_detail['decision'] : array(),
                    ),
                );
                $out_index[$normalized] = count($out) - 1;
            }
        }

        // Historico complementario: Analista conserva busquedas internas previas
        // a la instrumentacion de V3. Se suman siempre (no solo como fallback de
        // tabla inexistente) y se deduplican frente al log canonico.
        if (function_exists('seo_analista_internal_search_snapshot')) {
            $snapshot = seo_analista_internal_search_snapshot($days, min(250, $limit));
            foreach ((array) ($snapshot['top'] ?? array()) as $row) {
                $text = (string) ($row['search_term'] ?? '');
                $normalized = (string) ($row['normalized_term'] ?? SEO_Solucionador_Normalizer::normalize($text));
                if ($text === '') continue;
                if (isset($seen[$normalized])) {
                    $idx = isset($out_index[$normalized]) ? absint($out_index[$normalized]) : -1;
                    if ($idx >= 0 && isset($out[$idx])) {
                        $out[$idx]['occurrences'] = max(1, absint($out[$idx]['occurrences'] ?? 1)) + max(1, absint($row['searches'] ?? 1));
                        $out[$idx]['source_meta']['zero_results'] = absint($out[$idx]['source_meta']['zero_results'] ?? 0) + absint($row['zero_count'] ?? 0);
                        $out[$idx]['source_meta']['historical_searches'] = absint($row['searches'] ?? 0);
                    }
                    continue;
                }
                if (!SEO_Solucionador_Normalizer::is_solution_signal($text)) continue;
                $seen[$normalized] = true;
                $out[] = array(
                    'source_type' => 'dependiente',
                    'proposal_role' => 'origin',
                    'source_id' => 'historic-search:' . md5($normalized),
                    'source_text' => $text,
                    'hints' => array(),
                    'occurrences' => max(1, absint($row['searches'] ?? 1)),
                    'evidence_score' => 0.90,
                    'observed_at' => (string) ($row['last_search'] ?? ''),
                    'source_meta' => array(
                        'proposal_role' => 'origin',
                        'dependiente_channel' => 'analista_internal_search_history',
                        'zero_results' => absint($row['zero_count'] ?? 0),
                        'negative_feedback' => 0,
                        'avg_results' => (float) ($row['avg_results'] ?? 0),
                    ),
                );
                $out_index[$normalized] = count($out) - 1;
            }
        }

        return $out;
    }

    public static function comentarista($limit = 1200) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_comentarista';
        if (!self::table_exists($table)) return array();

        $limit = min(2500, max(50, absint($limit)));
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT c.id,c.product_id,c.content_type,c.source_name,c.source_title,c.source_content,c.editorial_summary,
                    c.source_published_at,c.captured_at,p.post_title product_title
             FROM {$table} c
             LEFT JOIN {$wpdb->posts} p ON p.ID=c.product_id AND p.post_type='product'
             WHERE c.status='published'
               AND c.content_type IN ('comment','article','social_post')
               AND (c.editorial_summary IS NOT NULL OR c.source_content IS NOT NULL)
             ORDER BY c.id DESC LIMIT %d",
            $limit
        ), ARRAY_A);

        $out = array();
        foreach ($rows as $row) {
            $blob = trim((string) ($row['editorial_summary'] ?? '') . ' ' . (string) ($row['source_content'] ?? ''));
            foreach (SEO_Solucionador_Normalizer::extract_comentarista_signals($blob, 4) as $index => $signal) {
                $sentence = (string) ($signal['text'] ?? '');
                $role = sanitize_key((string) ($signal['proposal_role'] ?? 'reinforcement')) ?: 'reinforcement';
                $kind = sanitize_key((string) ($signal['kind'] ?? 'problem_statement'));
                if ($sentence === '') continue;
                $out[] = array(
                    'source_type' => 'comentarista',
                    'proposal_role' => $role,
                    'source_id' => (string) absint($row['id'] ?? 0) . ':' . $kind . ':' . ($index + 1),
                    'source_text' => $sentence,
                    'hints' => array(),
                    'occurrences' => 1,
                    'evidence_score' => $role === 'origin' ? 0.70 : 0.45,
                    'observed_at' => (string) (($row['source_published_at'] ?? '') ?: ($row['captured_at'] ?? '')),
                    'source_meta' => array(
                        'proposal_role' => $role,
                        'comentarista_signal_kind' => $kind,
                        'product_id' => absint($row['product_id'] ?? 0),
                        'product_title' => (string) ($row['product_title'] ?? ''),
                        'source_name' => (string) ($row['source_name'] ?? ''),
                        'source_title' => (string) ($row['source_title'] ?? ''),
                    ),
                );
            }
        }
        return $out;
    }

    private static function analyst_action_can_originate($action, $channel, array $entity) {
        $action = strtoupper(sanitize_text_field((string) $action));
        $channel = sanitize_key((string) $channel);
        $type = sanitize_key((string) ($entity['type'] ?? ''));

        // Nunca convertir una instruccion de mejorar/impulsar una entidad concreta
        // del catalogo en una pregunta para clientes.
        if (in_array($type, array('product','product_cat','category','cluster','hub_primary','hub_secondary'), true)) {
            if (preg_match('/^(MEJORAR|IMPULSAR)_/', $action)) return false;
        }
        if (preg_match('/(PRODUCTO|CATEGORIA|ESTRUCTURA|CLUSTER|HUB)/', $action) && !preg_match('/CREAR_(POST|CONTENIDO|GUIA)/', $action)) {
            return false;
        }

        // Solo las directrices explicitamente orientadas a crear cobertura editorial
        // pueden originar una propuesta sin una pregunta previa del cliente.
        if (preg_match('/CREAR_(POST|CONTENIDO|GUIA)|NUEVO_(POST|CONTENIDO)|COBERTURA_(NUEVA|EDITORIAL)|CONTENT_GAP|EDITORIAL_GAP/', $action)) return true;
        if ($channel === 'contenido' && $type === '' && preg_match('/CREAR|COBERTURA|NUEV/', $action)) return true;
        return false;
    }

    public static function analista($days = 90, $limit = 160) {
        $out = array();
        $days = min(365, max(7, absint($days)));
        $limit = min(250, max(20, absint($limit)));

        // Las busquedas internas se consumen desde dependiente(), incluso cuando
        // proceden del snapshot de Analista como fallback. Asi no se duplican ni
        // se contabilizan como demanda de mercado.

        if (function_exists('seo_analista_decision_plan')) {
            $plan = seo_analista_decision_plan($days, min(80, $limit));
            foreach ((array) $plan as $row) {
                $entity = is_array($row['entity'] ?? null) ? $row['entity'] : array();
                $origin = self::analyst_action_can_originate(
                    (string) ($row['action'] ?? ''),
                    (string) ($row['channel'] ?? ''),
                    $entity
                );

                $texts = array_filter(array_merge(
                    array((string) ($row['topic'] ?? '')),
                    array_slice((array) ($row['keywords'] ?? array()), 0, 5)
                ));
                foreach ($texts as $text) {
                    if (!SEO_Solucionador_Normalizer::is_solution_signal($text)) continue;
                    $out[] = array(
                        'source_type' => 'analista',
                        'proposal_role' => self::origin_flag($origin),
                        'source_id' => 'plan:' . md5(SEO_Solucionador_Normalizer::normalize((string) ($row['topic'] ?? '')) . '|' . SEO_Solucionador_Normalizer::normalize((string) $text)),
                        'source_text' => (string) $text,
                        'hints' => array(),
                        'occurrences' => 1,
                        'evidence_score' => min(1.0, max(0.45, ((float) ($row['priority'] ?? 50)) / 100)),
                        'observed_at' => current_time('mysql'),
                        'source_meta' => array(
                            'proposal_role' => self::origin_flag($origin),
                            'priority' => (int) ($row['priority'] ?? 0),
                            'action' => (string) ($row['action'] ?? ''),
                            'channel' => (string) ($row['channel'] ?? ''),
                            'analista_channel' => 'decision_plan',
                            'entity' => $entity,
                            'catalog' => is_array($row['catalog'] ?? null) ? $row['catalog'] : array(),
                            'target' => is_array($row['target'] ?? null) ? $row['target'] : array(),
                            'keywords' => array_values(array_slice((array) ($row['keywords'] ?? array()), 0, 8)),
                        ),
                    );
                }
            }
        }
        return $out;
    }

    private static function auditor_editorial_code($code) {
        $code = sanitize_key((string) $code);
        if ($code === '') return false;
        return (bool) preg_match('/(^|_)(content_gap|editorial_gap|search_gap|query_gap|intent_gap|missing_content|missing_faq|faq_coverage|unanswered_query|uncovered_intent)(_|$)/', $code);
    }

    public static function auditor($limit = 250) {
        if (!class_exists('SEO_Auditor') || !method_exists('SEO_Auditor', 'last_catalog_report')) return array();
        $report = SEO_Auditor::last_catalog_report();
        if (!is_array($report) || !$report) return array();

        $out = array();

        // Las pruebas de comportamiento que contienen una consulta de cliente si
        // pueden originar una necesidad, siempre que el resultado no sea correcto.
        foreach (array_slice((array) ($report['behavior_audit']['samples'] ?? array()), 0, 80) as $sample) {
            $query = (string) ($sample['query'] ?? '');
            $status = sanitize_key((string) ($sample['status'] ?? ''));
            if ($query === '' || $status === 'ok' || !SEO_Solucionador_Normalizer::is_solution_signal($query)) continue;
            $out[] = array(
                'source_type' => 'auditor',
                'proposal_role' => self::origin_flag(true),
                'source_id' => 'behavior:' . md5(SEO_Solucionador_Normalizer::normalize($query)),
                'source_text' => $query,
                'hints' => array(),
                'occurrences' => 1,
                'evidence_score' => 0.50,
                'observed_at' => (string) ($report['generated_at'] ?? current_time('mysql')),
                'source_meta' => array(
                    'proposal_role' => 'origin',
                    'auditor_channel' => 'behavior_probe',
                    'status' => $status,
                    'kind' => (string) ($sample['kind'] ?? ''),
                    'diagnostics' => array_values((array) ($sample['diagnostics'] ?? array())),
                ),
            );
        }

        // Findings generales del Auditor son tecnicos por defecto. Solo una
        // allowlist de gaps editoriales puede alimentar Solucionador.
        foreach (array_slice((array) ($report['findings'] ?? array()), 0, $limit) as $finding) {
            $code = sanitize_key((string) ($finding['code'] ?? ''));
            $entity_type = sanitize_key((string) ($finding['entity_type'] ?? ''));
            if (!self::auditor_editorial_code($code) || $entity_type === 'system') continue;

            $evidence = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : array();
            $candidate_texts = array_filter(array(
                (string) ($evidence['query'] ?? ''),
                (string) ($evidence['search_term'] ?? ''),
            ));
            $text = '';
            foreach ($candidate_texts as $candidate) {
                if (SEO_Solucionador_Normalizer::is_solution_signal($candidate)) {
                    $text = $candidate;
                    break;
                }
            }
            if ($text === '') continue;

            $out[] = array(
                'source_type' => 'auditor',
                'proposal_role' => self::origin_flag(true),
                'source_id' => 'finding:' . md5($code . '|' . SEO_Solucionador_Normalizer::normalize($text)),
                'source_text' => $text,
                'hints' => array(),
                'occurrences' => 1,
                'evidence_score' => 0.45,
                'observed_at' => (string) ($report['generated_at'] ?? current_time('mysql')),
                'source_meta' => array(
                    'proposal_role' => 'origin',
                    'auditor_channel' => 'editorial_finding',
                    'code' => $code,
                    'severity' => (string) ($finding['severity'] ?? ''),
                    'entity_type' => $entity_type,
                    'entity_id' => $finding['entity_id'] ?? '',
                ),
            );
        }
        return $out;
    }

    public static function ojeador($limit = 180) {
        if (!class_exists('SEO_Ojeador_Analysis') || !method_exists('SEO_Ojeador_Analysis','dashboard')) return array();
        $dashboard = SEO_Ojeador_Analysis::dashboard(max(20, min(300, absint($limit))));
        $out = array();
        foreach ((array) ($dashboard['recommendations'] ?? array()) as $row) {
            $term_id = absint($row['term_id'] ?? 0);
            $category = trim((string) ($row['category_name'] ?? ''));
            if (!$term_id || $category === '') continue;
            $query = trim((string) ($row['query_text'] ?? ''));
            $text = $query !== '' ? $query : ('elegir ' . $category);
            $out[] = array(
                'source_type'=>'ojeador',
                'proposal_role'=>'reinforcement',
                'source_id'=>'market:' . $term_id . ':' . sanitize_key((string) ($row['code'] ?? 'signal')),
                'source_text'=>$text,
                'hints'=>array(
                    'intent'=>'eleccion',
                    'action'=>'elegir',
                    'object'=>$category,
                ),
                'occurrences'=>1,
                'evidence_score'=>min(1.0, max(0.25, ((float) ($row['priority'] ?? 50)) / 100)),
                'observed_at'=>current_time('mysql'),
                'source_meta'=>array(
                    'proposal_role'=>'reinforcement',
                    'term_id'=>$term_id,
                    'category'=>$category,
                    'code'=>(string) ($row['code'] ?? ''),
                    'signal'=>(string) ($row['signal'] ?? ''),
                    'action'=>(string) ($row['action'] ?? ''),
                    'reason'=>(string) ($row['reason'] ?? ''),
                    'opportunity_index'=>(float) ($row['opportunity_index'] ?? 0),
                    'competition_index'=>(float) ($row['competition_index'] ?? 0),
                    'catalog_gap_index'=>(float) ($row['catalog_gap_index'] ?? 0),
                    'visibility_index'=>(float) ($row['visibility_index'] ?? 0),
                ),
            );
        }
        return array_slice($out, 0, max(1, min(300, absint($limit))));
    }

    public static function ingeniero($limit = 260) {
        if (!class_exists('SEO_Ingeniero') || !class_exists('SEO_Ingeniero_DB')) return array();
        $stats = SEO_Ingeniero_DB::category_stats_map();
        $out = array();
        foreach ($stats as $term_id=>$stat) {
            if (absint($stat['active'] ?? 0) < 1) continue;
            $term = get_term(absint($term_id), 'product_cat');
            if (!$term || is_wp_error($term)) continue;
            $category = (string) $term->name;
            foreach (array_slice((array) SEO_Ingeniero::active_knowledge(absint($term_id)), 0, 3) as $row) {
                $summary = trim((string) (($row['summary'] ?? '') ?: ($row['concept'] ?? '')));
                if ($summary === '') continue;
                $out[] = array(
                    'source_type'=>'ingeniero',
                    'proposal_role'=>'reinforcement',
                    'source_id'=>'knowledge:' . absint($row['id'] ?? 0) . ':' . absint($term_id),
                    'source_text'=>$summary,
                    'hints'=>array('object'=>$category),
                    'occurrences'=>1,
                    'evidence_score'=>min(1.0, max(0.30, (float) ($row['confidence'] ?? 0.6))),
                    'observed_at'=>(string) ($row['updated_at'] ?? current_time('mysql')),
                    'source_meta'=>array(
                        'proposal_role'=>'reinforcement',
                        'term_id'=>absint($term_id),
                        'category'=>$category,
                        'knowledge_type'=>(string) ($row['knowledge_type'] ?? ''),
                        'concept'=>(string) ($row['concept'] ?? ''),
                        'confidence'=>(float) ($row['confidence'] ?? 0),
                    ),
                );
                if (count($out) >= max(20, absint($limit))) break 2;
            }
        }
        return $out;
    }

    public static function clasificador($limit = 260) {
        if (!function_exists('seo_classifier_engineer_vocab_bulk_reports') || !function_exists('seo_classifier_engineer_vocab_flat_rows')) return array();
        $reports = seo_classifier_engineer_vocab_bulk_reports();
        if (is_wp_error($reports)) return array();
        $rows = seo_classifier_engineer_vocab_flat_rows((array) $reports);
        $out = array();
        foreach ($rows as $row) {
            if (!in_array((string) ($row['status'] ?? ''), array('new','possible'), true)) continue;
            $term_id = absint($row['term_id'] ?? 0);
            $category = trim((string) ($row['category'] ?? ''));
            $value = trim((string) ($row['value'] ?? ''));
            if (!$term_id || $category === '' || $value === '') continue;
            $out[] = array(
                'source_type'=>'clasificador',
                'proposal_role'=>'reinforcement',
                'source_id'=>'vocab:' . $term_id . ':' . sanitize_key((string) ($row['kind'] ?? '')) . ':' . md5((string) ($row['key'] ?? $value)),
                'source_text'=>'elegir ' . $category . ' ' . $value,
                'hints'=>array(
                    'intent'=>'eleccion',
                    'action'=>'elegir',
                    'object'=>$category,
                    'context'=>$value,
                ),
                'occurrences'=>1,
                'evidence_score'=>min(1.0, max(0.30, (float) ($row['confidence'] ?? 0.6))),
                'observed_at'=>current_time('mysql'),
                'source_meta'=>array(
                    'proposal_role'=>'reinforcement',
                    'term_id'=>$term_id,
                    'category'=>$category,
                    'kind'=>(string) ($row['kind'] ?? ''),
                    'concept'=>$value,
                    'master_status'=>(string) ($row['status'] ?? ''),
                    'equivalent'=>(string) ($row['existing'] ?? ''),
                    'provisional'=>!empty($row['provisional']),
                ),
            );
            if (count($out) >= max(20, absint($limit))) break;
        }
        return $out;
    }


    /**
     * Comparador aun puede evolucionar como servicio independiente. Solucionador
     * consume solo un contrato normalizado publicado por Comparador; no vuelve a
     * calcular comparativas de mercado.
     */
    public static function comparador($limit = 240) {
        $signals = apply_filters('seo_solucionador_comparador_signals', array(), max(20,min(500,absint($limit))));
        $out = array();
        foreach (array_slice((array) $signals,0,max(20,min(500,absint($limit)))) as $row) {
            if (!is_array($row)) continue;
            $term_id = absint($row['category_id'] ?? $row['term_id'] ?? 0);
            $text = trim((string) ($row['source_text'] ?? $row['topic'] ?? $row['summary'] ?? ''));
            if ($text === '') continue;
            $proposal_role = sanitize_key((string) ($row['proposal_role'] ?? 'origin'));
            if (!in_array($proposal_role, array('origin','reinforcement'), true)) $proposal_role = 'origin';
            $out[] = array(
                'source_type'=>'comparador',
                'proposal_role'=>$proposal_role,
                'source_id'=>sanitize_text_field((string) ($row['source_id'] ?? ('comparison:' . md5($text . '|' . $term_id)))),
                'source_text'=>$text,
                'signal_type'=>sanitize_key((string) ($row['signal_type'] ?? 'market_comparison')),
                'category_id'=>$term_id,
                'entity_type'=>$term_id ? 'product_cat' : sanitize_key((string) ($row['entity_type'] ?? '')),
                'entity_id'=>$term_id ?: absint($row['entity_id'] ?? 0),
                'hints'=>array(
                    'intent'=>(string) ($row['intent'] ?? 'decision'),
                    'object'=>(string) ($row['object'] ?? ''),
                    'context'=>(string) ($row['context'] ?? ''),
                    'category_id'=>$term_id,
                ),
                'occurrences'=>max(1,absint($row['occurrences'] ?? 1)),
                'confidence'=>max(0,min(1,(float) ($row['confidence'] ?? 0.7))),
                'evidence_score'=>max(0.25,min(1.0,(float) ($row['evidence_score'] ?? 0.70))),
                'observed_at'=>sanitize_text_field((string) ($row['observed_at'] ?? current_time('mysql'))),
                'source_meta'=>array_merge((array) ($row['metadata'] ?? array()),array(
                    'proposal_role'=>$proposal_role,
                    'term_id'=>$term_id,
                    'types'=>(array) ($row['types'] ?? array()),
                    'differentiators'=>(array) ($row['differentiators'] ?? array()),
                    'decisive_features'=>(array) ($row['decisive_features'] ?? array()),
                    'advantages'=>(array) ($row['advantages'] ?? array()),
                    'limitations'=>(array) ($row['limitations'] ?? array()),
                    'representative_refs'=>(array) ($row['representative_refs'] ?? array()),
                )),
            );
        }
        return $out;
    }

    /**
     * Marketing puede reforzar prioridad, nunca originar por si solo una URL.
     * Se deja un contrato desacoplado para campañas/estacionalidad/interes.
     */
    public static function marketing($limit = 120) {
        $signals = apply_filters('seo_solucionador_marketing_priorities', array(), max(10,min(300,absint($limit))));
        $out = array();
        foreach (array_slice((array) $signals,0,max(10,min(300,absint($limit)))) as $row) {
            if (!is_array($row)) continue;
            $term_id = absint($row['category_id'] ?? $row['term_id'] ?? 0);
            $text = trim((string) ($row['topic'] ?? $row['source_text'] ?? ''));
            if ($text === '') continue;
            $out[] = array(
                'source_type'=>'marketing',
                'proposal_role'=>'reinforcement',
                'source_id'=>sanitize_text_field((string) ($row['source_id'] ?? ('marketing:' . md5($text . '|' . $term_id)))),
                'source_text'=>$text,
                'signal_type'=>sanitize_key((string) ($row['signal_type'] ?? 'commercial_priority')),
                'category_id'=>$term_id,
                'entity_type'=>$term_id ? 'product_cat' : '',
                'entity_id'=>$term_id,
                'hints'=>array('category_id'=>$term_id,'object'=>(string) ($row['category_name'] ?? '')),
                'occurrences'=>1,
                'confidence'=>max(0,min(1,(float) ($row['confidence'] ?? 0.6))),
                'evidence_score'=>max(0.1,min(0.8,(float) ($row['evidence_score'] ?? 0.45))),
                'observed_at'=>sanitize_text_field((string) ($row['observed_at'] ?? current_time('mysql'))),
                'source_meta'=>array(
                    'proposal_role'=>'reinforcement',
                    'term_id'=>$term_id,
                    'category_priority'=>(float) ($row['category_priority'] ?? 0),
                    'campaign'=>(string) ($row['campaign'] ?? ''),
                    'seasonality'=>(string) ($row['seasonality'] ?? ''),
                    'commercial_interest'=>(float) ($row['commercial_interest'] ?? 0),
                ),
            );
        }
        return $out;
    }

    private static function normalize_rows(array $rows) {
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $meta = is_array($row['source_meta'] ?? null) ? $row['source_meta'] : array();
            $hints = is_array($row['hints'] ?? null) ? $row['hints'] : array();

            $category_id = absint($row['category_id'] ?? $meta['category_id'] ?? $meta['term_id'] ?? $hints['category_id'] ?? 0);
            $entity_type = sanitize_key((string) ($row['entity_type'] ?? $meta['entity_type'] ?? ''));
            $entity_id = absint($row['entity_id'] ?? $meta['entity_id'] ?? 0);

            if (!$entity_id && !empty($meta['clicked_product_id'])) {
                $entity_type = 'product';
                $entity_id = absint($meta['clicked_product_id']);
            }
            if (!$entity_id && !empty($meta['product_id'])) {
                $entity_type = 'product';
                $entity_id = absint($meta['product_id']);
            }
            if (!$category_id && $entity_type === 'product' && $entity_id) {
                $ids = wp_get_post_terms($entity_id,'product_cat',array('fields'=>'ids'));
                if (!is_wp_error($ids) && $ids) $category_id = absint(reset($ids));
            }
            if (!$category_id && !empty($meta['top_results']) && is_array($meta['top_results'])) {
                foreach ($meta['top_results'] as $result) {
                    if (!is_array($result)) continue;
                    $product_id = absint($result['product_id'] ?? $result['id'] ?? $result['post_id'] ?? 0);
                    if (!$product_id) continue;
                    $ids = wp_get_post_terms($product_id,'product_cat',array('fields'=>'ids'));
                    if (!is_wp_error($ids) && $ids) {
                        $category_id = absint(reset($ids));
                        break;
                    }
                }
            }
            if (!$category_id && !empty($meta['semantic_matches']) && is_array($meta['semantic_matches'])) {
                foreach ($meta['semantic_matches'] as $match) {
                    if (!is_array($match)) continue;
                    if (in_array(sanitize_key((string) ($match['type'] ?? $match['object_type'] ?? '')),array('category','product_cat'),true)) {
                        $category_id = absint($match['id'] ?? $match['term_id'] ?? $match['object_id'] ?? 0);
                        if ($category_id) break;
                    }
                }
            }
            if ($category_id && $entity_type === '') {
                $entity_type = 'product_cat';
                $entity_id = $category_id;
            }

            $row['category_id'] = $category_id;
            $row['entity_type'] = $entity_type;
            $row['entity_id'] = $entity_id;
            $row['signal_type'] = sanitize_key((string) ($row['signal_type'] ?? $meta['signal_type'] ?? $meta['kind'] ?? $meta['code'] ?? $row['source_type'] ?? 'signal'));
            $row['confidence'] = max(0,min(1,(float) ($row['confidence'] ?? $meta['confidence'] ?? $row['evidence_score'] ?? 0.6)));
            $row['hints'] = $hints;
            if ($category_id && empty($row['hints']['category_id'])) $row['hints']['category_id'] = $category_id;
            $out[] = $row;
        }
        return $out;
    }

    public static function all($days = 180) {
        // Orden intencional: primero las preguntas reales y los gaps/probes que
        // pueden originar temas. Comentarista aporta preguntas o refuerzos y
        // Analista queda al final para reforzar cualquier origen del ciclo.
        return self::normalize_rows(array_merge(
            self::dependiente($days, 1600),
            self::auditor(300),
            self::comentarista(1200),
            self::analista(min(180, $days), 160),
            self::ojeador(180),
            self::comparador(240),
            self::ingeniero(260),
            self::clasificador(260),
            self::marketing(120)
        ));
    }
}

<?php
/**
 * Solucionador - fuente editorial reducida.
 *
 * Arquitectura v0.5.0:
 * Academia/Entrenador -> dossier por product_cat -> cobertura -> brief.
 *
 * Dependiente no se modifica. Solucionador lee preguntas activas cuyo ultimo
 * run esta answered + pass_*. El search_log se conserva solo como refuerzo
 * ligero de prioridad para categorias que ya disponen de dossier.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Sources {
    const SNAPSHOT_OPTION = 'seo_solucionador_academia_snapshot';

    private static function table_exists($table) {
        return SEO_Solucionador_DB::table_exists($table);
    }

    private static function decode($value) {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
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
     * Resuelve product_cat solo por relaciones demostrables.
     * Nunca usa parecido textual.
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
            if ($owner_type === 2) $ids[] = $owner_id;
            elseif ($owner_type === 3) $ids = array_merge($ids, self::product_category_ids($owner_id));
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

    private static function academy_tables() {
        global $wpdb;
        return array(
            'questions'=>$wpdb->prefix . 'seo_dependiente_trainer_questions',
            'runs'=>$wpdb->prefix . 'seo_dependiente_trainer_runs',
        );
    }

    private static function academy_where() {
        return "q.enabled=1 AND q.lesson_key<>'' AND q.lesson_key NOT LIKE 'lab\\_%'";
    }

    /**
     * Estadisticas baratas. Las metricas que exigen resolver categoria se
     * actualizan durante el scan y se guardan en SNAPSHOT_OPTION.
     */
    private static function academy_base_stats() {
        global $wpdb;
        $tables = self::academy_tables();
        $empty = array(
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
        );
        if (!self::table_exists($tables['questions']) || !self::table_exists($tables['runs'])) return $empty;

        $where = self::academy_where();
        $row = $wpdb->get_row(
            "SELECT
                COUNT(q.id) questions_total,
                SUM(CASE WHEN r.status='answered' AND LEFT(COALESCE(r.evaluation_status,''),5)='pass_' THEN 1 ELSE 0 END) learned,
                MAX(r.created_at) last_run_at
             FROM {$tables['questions']} q
             LEFT JOIN (
                SELECT question_id,MAX(id) latest_run_id
                FROM {$tables['runs']}
                WHERE question_id IS NOT NULL
                GROUP BY question_id
             ) latest ON latest.question_id=q.id
             LEFT JOIN {$tables['runs']} r ON r.id=latest.latest_run_id
             WHERE {$where}",
            ARRAY_A
        );
        $total = absint($row['questions_total'] ?? 0);
        $learned = absint($row['learned'] ?? 0);
        $categories_total = wp_count_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
        $categories_total = is_wp_error($categories_total) ? 0 : absint($categories_total);

        return array(
            'available'=>true,
            'questions_total'=>$total,
            'learned'=>$learned,
            'not_learned'=>max(0,$total-$learned),
            'learned_with_category'=>0,
            'learned_without_category'=>0,
            'categories_with_knowledge'=>0,
            'categories_total'=>$categories_total,
            'categories_without_knowledge'=>$categories_total,
            'avg_questions_per_category'=>0,
            'last_run_at'=>(string) ($row['last_run_at'] ?? ''),
        );
    }

    public static function dependiente_academia_snapshot() {
        $base = self::academy_base_stats();
        $saved = get_option(self::SNAPSHOT_OPTION, array());
        if (!is_array($saved)) $saved = array();
        foreach (array(
            'learned_with_category','learned_without_category','categories_with_knowledge',
            'categories_without_knowledge','avg_questions_per_category','last_run_at'
        ) as $key) {
            if (array_key_exists($key,$saved)) $base[$key] = $saved[$key];
        }
        return $base;
    }

    /**
     * Lote ligero de conocimiento aprendido.
     *
     * No carga evaluation_json/top_results/response_meta. Esos datos se consultan
     * bajo demanda cuando Editora abre o exporta el brief.
     */
    public static function academia_batch($cursor = 0, $limit = 200) {
        global $wpdb;
        $tables = self::academy_tables();
        $cursor = absint($cursor);
        $limit = max(25,min(500,absint($limit)));

        if (!self::table_exists($tables['questions']) || !self::table_exists($tables['runs'])) {
            return array('sources'=>array(),'next_cursor'=>$cursor,'complete'=>true,'seen'=>0,'with_category'=>0,'without_category'=>0);
        }

        $where = self::academy_where();
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT
                q.id question_id,q.lesson_key,q.question_type,q.source_type,q.source_id,
                q.source_key,q.question,q.expected_json,
                r.id run_id,r.evaluation_status,r.evaluation_score,r.created_at run_created_at
             FROM {$tables['questions']} q
             INNER JOIN (
                SELECT question_id,MAX(id) latest_run_id
                FROM {$tables['runs']}
                WHERE question_id IS NOT NULL
                GROUP BY question_id
             ) latest ON latest.question_id=q.id
             INNER JOIN {$tables['runs']} r ON r.id=latest.latest_run_id
             WHERE {$where}
               AND q.id>%d
               AND r.status='answered'
               AND LEFT(COALESCE(r.evaluation_status,''),5)='pass_'
             ORDER BY q.id ASC
             LIMIT %d",
            $cursor,
            $limit
        ), ARRAY_A);

        $sources = array();
        $with_category = 0;
        $without_category = 0;
        $next_cursor = $cursor;

        foreach ($rows as $row) {
            $question_id = absint($row['question_id'] ?? 0);
            if (!$question_id) continue;
            $next_cursor = max($next_cursor,$question_id);

            $expected = self::decode($row['expected_json'] ?? '');
            $category_ids = self::academy_category_ids($row,$expected);
            if (!$category_ids) {
                $without_category++;
                continue;
            }
            $with_category++;

            foreach ($category_ids as $term_id) {
                $term = get_term($term_id,'product_cat');
                if (!$term || is_wp_error($term)) continue;
                $category_name = (string) $term->name;
                $score = max(0,min(1,(float) ($row['evaluation_score'] ?? 0)));

                $sources[] = array(
                    'source_type'=>'dependiente',
                    'proposal_role'=>'origin',
                    'source_id'=>'academy-question:' . $question_id . ':category:' . $term_id,
                    'signal_type'=>'learned_question',
                    'entity_type'=>'product_cat',
                    'entity_id'=>$term_id,
                    'category_id'=>$term_id,
                    'category_name'=>$category_name,
                    'source_text'=>sanitize_text_field((string) ($row['question'] ?? '')),
                    'hints'=>array(
                        'intent'=>'dependiente_qa_basic',
                        'action'=>'resolver',
                        'object'=>$category_name,
                        'category_id'=>$term_id,
                    ),
                    'occurrences'=>1,
                    'confidence'=>$score > 0 ? $score : 0.90,
                    'evidence_score'=>1.00,
                    'observed_at'=>sanitize_text_field((string) ($row['run_created_at'] ?? '')),
                    'source_meta'=>array(
                        'proposal_role'=>'origin',
                        'dependiente_channel'=>'academy_learned',
                        'editorial_family'=>'dependiente_qa_basic',
                        'question_id'=>$question_id,
                        'run_id'=>absint($row['run_id'] ?? 0),
                        'question_type'=>sanitize_key((string) ($row['question_type'] ?? '')),
                        'lesson_key'=>sanitize_key((string) ($row['lesson_key'] ?? '')),
                        'source_type'=>sanitize_key((string) ($row['source_type'] ?? '')),
                        'source_id'=>absint($row['source_id'] ?? 0) ?: null,
                        'source_key'=>sanitize_text_field((string) ($row['source_key'] ?? '')),
                        'expected_kind'=>sanitize_key((string) ($expected['kind'] ?? '')),
                        'evaluation_status'=>sanitize_key((string) ($row['evaluation_status'] ?? '')),
                        'evaluation_score'=>$score,
                        'category_id'=>$term_id,
                        'category_name'=>$category_name,
                    ),
                );
            }
        }

        return array(
            'sources'=>$sources,
            'next_cursor'=>$next_cursor,
            'complete'=>count($rows) < $limit,
            'seen'=>count($rows),
            'with_category'=>$with_category,
            'without_category'=>$without_category,
        );
    }

    /**
     * Recupera resultados internos completos solo cuando el brief los necesita.
     */
    public static function academia_question_details(array $question_ids) {
        global $wpdb;
        $tables = self::academy_tables();
        $ids = array_values(array_unique(array_filter(array_map('absint',$question_ids))));
        if (!$ids || !self::table_exists($tables['questions']) || !self::table_exists($tables['runs'])) return array();
        $ids = array_slice($ids,0,250);

        $placeholders = implode(',',array_fill(0,count($ids),'%d'));
        $sql = "SELECT
                    q.id question_id,q.lesson_key,q.question_type,q.source_type,q.source_id,q.source_key,
                    q.question,q.expected_json,
                    r.id run_id,r.status run_status,r.search_strategy,r.evaluation_status,r.evaluation_score,
                    r.evaluation_json,r.top_results,r.response_meta,r.created_at run_created_at
                FROM {$tables['questions']} q
                INNER JOIN (
                    SELECT question_id,MAX(id) latest_run_id
                    FROM {$tables['runs']}
                    WHERE question_id IS NOT NULL
                    GROUP BY question_id
                ) latest ON latest.question_id=q.id
                INNER JOIN {$tables['runs']} r ON r.id=latest.latest_run_id
                WHERE q.id IN ({$placeholders})
                  AND r.status='answered'
                  AND LEFT(COALESCE(r.evaluation_status,''),5)='pass_'
                ORDER BY q.id ASC";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql,$ids),ARRAY_A);

        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'question_id'=>absint($row['question_id'] ?? 0),
                'run_id'=>absint($row['run_id'] ?? 0),
                'question'=>sanitize_text_field((string) ($row['question'] ?? '')),
                'question_type'=>sanitize_key((string) ($row['question_type'] ?? '')),
                'lesson_key'=>sanitize_key((string) ($row['lesson_key'] ?? '')),
                'source_type'=>sanitize_key((string) ($row['source_type'] ?? '')),
                'source_id'=>absint($row['source_id'] ?? 0) ?: null,
                'source_key'=>sanitize_text_field((string) ($row['source_key'] ?? '')),
                'expected'=>self::decode($row['expected_json'] ?? ''),
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
        if (class_exists('SEO_Solucionador_Dossiers')) {
            return SEO_Solucionador_Dossiers::snapshot();
        }
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
        // Desde v0.5.0 el único origen editorial es el dossier persistido de
        // Academia. El search_log deja de originar temas independientes.
        if (class_exists('SEO_Solucionador_Dossiers')) {
            return SEO_Solucionador_Dossiers::signals(min(500,max(1,absint($limit))),0);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'seo_dependiente_search_log';
        if (!self::table_exists($table)) return array();

        $allowed = array_fill_keys(array_values(array_unique(array_filter(array_map('absint',$category_ids)))),true);
        if (!$allowed) return array();

        $days = max(7,min(365,absint($days)));
        $limit = max(25,min(1000,absint($limit)));

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT clicked_product_id,
                    COUNT(*) occurrences,
                    SUM(CASE WHEN candidate_count=0 THEN 1 ELSE 0 END) zero_results,
                    SUM(CASE WHEN feedback<0 THEN 1 ELSE 0 END) negative_feedback,
                    MAX(created_at) observed_at
             FROM {$table}
             WHERE request_kind='search'
               AND clicked_product_id>0
               AND created_at>=DATE_SUB(%s,INTERVAL %d DAY)
             GROUP BY clicked_product_id
             ORDER BY occurrences DESC
             LIMIT %d",
            current_time('mysql'),
            $days,
            $limit
        ),ARRAY_A);

        $by_category = array();
        foreach ($rows as $row) {
            foreach (self::product_category_ids(absint($row['clicked_product_id'] ?? 0)) as $term_id) {
                if (empty($allowed[$term_id])) continue;
                if (!isset($by_category[$term_id])) {
                    $by_category[$term_id] = array('occurrences'=>0,'zero_results'=>0,'negative_feedback'=>0,'observed_at'=>'');
                }
                $by_category[$term_id]['occurrences'] += absint($row['occurrences'] ?? 0);
                $by_category[$term_id]['zero_results'] += absint($row['zero_results'] ?? 0);
                $by_category[$term_id]['negative_feedback'] += absint($row['negative_feedback'] ?? 0);
                if ((string) ($row['observed_at'] ?? '') > $by_category[$term_id]['observed_at']) {
                    $by_category[$term_id]['observed_at'] = (string) $row['observed_at'];
                }
            }
        }

        $out = array();
        foreach ($by_category as $term_id=>$stats) {
            $term = get_term($term_id,'product_cat');
            if (!$term || is_wp_error($term)) continue;
            $out[] = array(
                'source_type'=>'dependiente',
                'proposal_role'=>'reinforcement',
                'source_id'=>'search-demand-category:' . absint($term_id),
                'signal_type'=>'real_demand_reinforcement',
                'entity_type'=>'product_cat',
                'entity_id'=>absint($term_id),
                'category_id'=>absint($term_id),
                'category_name'=>(string) $term->name,
                'source_text'=>'Demanda real de clientes relacionada con ' . (string) $term->name,
                'hints'=>array(
                    'intent'=>'dependiente_qa_basic',
                    'action'=>'resolver',
                    'object'=>(string) $term->name,
                    'category_id'=>absint($term_id),
                ),
                'occurrences'=>max(1,absint($stats['occurrences'] ?? 0)),
                'confidence'=>0.75,
                'evidence_score'=>0.35,
                'observed_at'=>(string) ($stats['observed_at'] ?? ''),
                'source_meta'=>array(
                    'proposal_role'=>'reinforcement',
                    'dependiente_channel'=>'search_log_demand',
                    'category_id'=>absint($term_id),
                    'zero_results'=>absint($stats['zero_results'] ?? 0),
                    'negative_feedback'=>absint($stats['negative_feedback'] ?? 0),
                ),
            );
        }
        return $out;
    }

    public static function save_academia_snapshot(array $stats) {
        $base = self::academy_base_stats();
        $snapshot = array_merge($base,array(
            'learned_with_category'=>absint($stats['learned_with_category'] ?? 0),
            'learned_without_category'=>absint($stats['learned_without_category'] ?? 0),
            'categories_with_knowledge'=>absint($stats['categories_with_knowledge'] ?? 0),
            'categories_without_knowledge'=>max(0,absint($base['categories_total'] ?? 0)-absint($stats['categories_with_knowledge'] ?? 0)),
            'avg_questions_per_category'=>(float) ($stats['avg_questions_per_category'] ?? 0),
            'last_run_at'=>(string) (($stats['last_run_at'] ?? '') ?: ($base['last_run_at'] ?? '')),
        ));
        update_option(self::SNAPSHOT_OPTION,$snapshot,false);
        return $snapshot;
    }

    /**
     * Compatibilidad. Ya no mezcla servicios externos y devuelve solo un lote
     * de Academia. El motor v0.5.0 usa academia_batch() de forma paginada.
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

    public static function editorial_source_contract() {
        return array('dependiente_academia');
    }

    public static function all($days = 180, $limit = 100, $after_dossier_id = 0) {
        // Arquitectura separada v0.5.0:
        // Academia/Entrenador es el único origen editorial de Solucionador.
        // Ingeniero, Ojeador, Comparador, Clasificador, Marketing, Comentarista,
        // Auditor y Analista mantienen sus procesos editoriales independientes.
        // Los detalles pesados de las preguntas se cargan sólo al abrir el brief.
        if (!class_exists('SEO_Solucionador_Dossiers')) return array();
        return self::normalize_rows(
            SEO_Solucionador_Dossiers::signals(
                min(500,max(1,absint($limit))),
                absint($after_dossier_id)
            )
        );
    }
}

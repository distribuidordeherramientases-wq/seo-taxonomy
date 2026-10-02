<?php
/**
 * Solucionador - dossiers category-first de Academia/Entrenador.
 *
 * Mantiene una huella ligera por product_cat. Los payloads pesados de cada
 * pregunta se recuperan bajo demanda al abrir/exportar el brief.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Dossiers {
    const STATE_OPTION = 'seo_solucionador_academia_scan_state';
    const DEFAULT_BATCH = 150;

    private static function questions_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_trainer_questions';
    }

    private static function runs_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_trainer_runs';
    }

    private static function curriculum_where() {
        return "q.enabled=1 AND q.lesson_key<>'' AND q.lesson_key NOT LIKE 'lab\\_%'";
    }

    private static function decode($value) {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function valid_category_ids(array $ids) {
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
        return is_wp_error($ids) ? array() : self::valid_category_ids((array) $ids);
    }

    private static function category_ids(array $row) {
        global $wpdb;
        $expected = self::decode($row['expected_json'] ?? '');
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
            if (SEO_Solucionador_DB::table_exists($faq_table)) {
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

        return self::valid_category_ids($ids);
    }

    private static function fresh_state() {
        return array(
            'token'=>wp_generate_uuid4(),
            'cursor'=>0,
            'processed'=>0,
            'learned'=>0,
            'learned_with_category'=>0,
            'learned_without_category'=>0,
            'errors'=>0,
            'last_run_at'=>'',
            'complete'=>false,
            'started_at'=>current_time('mysql'),
            'updated_at'=>current_time('mysql'),
            'completed_at'=>'',
        );
    }

    public static function state() {
        $state = get_option(self::STATE_OPTION, array());
        return is_array($state) ? $state : array();
    }

    public static function reset_scan() {
        $state = self::fresh_state();
        update_option(self::STATE_OPTION, $state, false);
        return $state;
    }

    private static function upsert_batch_dossier($term_id, array $question_ids, $score_sum, $last_validated_at, $token) {
        global $wpdb;
        $table = SEO_Solucionador_DB::dossiers_table();
        $term_id = absint($term_id);
        if (!$term_id || !$question_ids) return false;

        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) return false;

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE category_id=%d LIMIT 1",
            $term_id
        ), ARRAY_A);

        $question_ids = array_values(array_unique(array_filter(array_map('absint', $question_ids))));
        $previous_ids = array();
        $previous_count = 0;
        $previous_score_sum = 0.0;

        if ($existing && (string) ($existing['scan_token'] ?? '') === (string) $token) {
            $previous_ids = SEO_Solucionador_DB::decode_json($existing['question_ids'] ?? '[]', array());
            $previous_ids = array_values(array_unique(array_filter(array_map('absint', (array) $previous_ids))));
            $previous_count = count($previous_ids);
            $previous_score_sum = (float) ($existing['score_avg'] ?? 0) * $previous_count;
        }

        $merged_ids = array_values(array_unique(array_merge($previous_ids, $question_ids)));
        sort($merged_ids, SORT_NUMERIC);
        $new_only_count = max(0, count($merged_ids) - $previous_count);
        $combined_score_sum = $previous_score_sum + (float) $score_sum;
        $count = count($merged_ids);
        $avg = $count > 0 ? min(1, max(0, $combined_score_sum / max(1, $previous_count + $new_only_count))) : 0;
        $last = (string) $last_validated_at;
        if ($existing && (string) ($existing['scan_token'] ?? '') === (string) $token) {
            $old_last = (string) ($existing['last_validated_at'] ?? '');
            if ($old_last > $last) $last = $old_last;
        }

        $hash = hash('sha256', wp_json_encode(array(
            'category_id'=>$term_id,
            'question_ids'=>$merged_ids,
            'score_avg'=>round($avg,4),
            'last_validated_at'=>$last,
        )));
        $now = current_time('mysql');
        $data = array(
            'category_name'=>sanitize_text_field((string) $term->name),
            'question_count'=>$count,
            'question_ids'=>wp_json_encode($merged_ids),
            'score_avg'=>round($avg,4),
            'last_validated_at'=>$last !== '' ? $last : null,
            'source_hash'=>$hash,
            'scan_token'=>(string) $token,
            'updated_at'=>$now,
        );

        if ($existing) {
            return $wpdb->update($table, $data, array('category_id'=>$term_id)) !== false;
        }

        $data['category_id'] = $term_id;
        $data['created_at'] = $now;
        return $wpdb->insert($table, $data) !== false;
    }

    public static function scan_batch($limit = self::DEFAULT_BATCH, $reset = false) {
        global $wpdb;
        SEO_Solucionador_DB::maybe_install();

        $questions = self::questions_table();
        $runs = self::runs_table();
        if (!SEO_Solucionador_DB::table_exists($questions) || !SEO_Solucionador_DB::table_exists($runs)) {
            return new WP_Error('solucionador_academia_missing', 'No están disponibles las tablas de Academia/Entrenador.');
        }

        $limit = max(25, min(500, absint($limit)));
        $state = self::state();
        if ($reset || !$state || empty($state['token'])) {
            $state = self::reset_scan();
        }

        $cursor = absint($state['cursor'] ?? 0);
        $where = self::curriculum_where();

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT
                q.id question_id,q.lesson_key,q.lesson_order,q.module_no,
                q.source_type,q.source_id,q.source_key,q.question_type,q.mode,
                q.question,q.expected_json,
                r.id run_id,r.status run_status,r.evaluation_status,
                r.evaluation_score,r.created_at run_created_at
             FROM {$questions} q
             LEFT JOIN (
                SELECT question_id,MAX(id) latest_run_id
                FROM {$runs}
                WHERE question_id IS NOT NULL
                GROUP BY question_id
             ) latest ON latest.question_id=q.id
             LEFT JOIN {$runs} r ON r.id=latest.latest_run_id
             WHERE {$where} AND q.id>%d
             ORDER BY q.id ASC
             LIMIT %d",
            $cursor,
            $limit
        ), ARRAY_A);

        $batch_by_category = array();
        $last_cursor = $cursor;

        foreach ($rows as $row) {
            $question_id = absint($row['question_id'] ?? 0);
            if ($question_id > $last_cursor) $last_cursor = $question_id;
            $state['processed'] = absint($state['processed'] ?? 0) + 1;

            $run_at = sanitize_text_field((string)($row['run_created_at'] ?? ''));
            if ($run_at !== '' && $run_at > (string)($state['last_run_at'] ?? '')) {
                $state['last_run_at'] = $run_at;
            }

            $learned = (string) ($row['run_status'] ?? '') === 'answered'
                && strpos((string) ($row['evaluation_status'] ?? ''), 'pass_') === 0;
            if (!$learned) continue;

            $state['learned'] = absint($state['learned'] ?? 0) + 1;

            try {
                $category_ids = self::category_ids($row);
            } catch (Throwable $e) {
                $state['errors'] = absint($state['errors'] ?? 0) + 1;
                continue;
            }

            if (!$category_ids) {
                $state['learned_without_category'] = absint($state['learned_without_category'] ?? 0) + 1;
                continue;
            }

            $state['learned_with_category'] = absint($state['learned_with_category'] ?? 0) + 1;
            foreach ($category_ids as $term_id) {
                if (!isset($batch_by_category[$term_id])) {
                    $batch_by_category[$term_id] = array('ids'=>array(),'score_sum'=>0.0,'last'=>'');
                }
                $batch_by_category[$term_id]['ids'][] = $question_id;
                $batch_by_category[$term_id]['score_sum'] += max(0,min(1,(float) ($row['evaluation_score'] ?? 0)));
                $observed = sanitize_text_field((string) ($row['run_created_at'] ?? ''));
                if ($observed > $batch_by_category[$term_id]['last']) {
                    $batch_by_category[$term_id]['last'] = $observed;
                }
            }
        }

        foreach ($batch_by_category as $term_id=>$batch) {
            try {
                self::upsert_batch_dossier(
                    $term_id,
                    (array) $batch['ids'],
                    (float) $batch['score_sum'],
                    (string) $batch['last'],
                    (string) $state['token']
                );
            } catch (Throwable $e) {
                $state['errors'] = absint($state['errors'] ?? 0) + 1;
            }
        }

        $state['cursor'] = $last_cursor;
        $state['updated_at'] = current_time('mysql');

        if (count($rows) < $limit) {
            $state['complete'] = true;
            $state['completed_at'] = current_time('mysql');
            $table = SEO_Solucionador_DB::dossiers_table();
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE scan_token<>%s",
                (string) $state['token']
            ));
        }

        update_option(self::STATE_OPTION, $state, false);
        return self::snapshot();
    }

    public static function snapshot() {
        global $wpdb;
        $state = self::state();
        $table = SEO_Solucionador_DB::dossiers_table();

        $categories_with = SEO_Solucionador_DB::table_exists($table)
            ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE question_count>0"))
            : 0;
        $questions_in_dossiers = SEO_Solucionador_DB::table_exists($table)
            ? absint($wpdb->get_var("SELECT COALESCE(SUM(question_count),0) FROM {$table}"))
            : 0;
        $categories_total = wp_count_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
        $categories_total = is_wp_error($categories_total) ? 0 : absint($categories_total);

        $questions_total = 0;
        if (SEO_Solucionador_DB::table_exists(self::questions_table())) {
            $questions_table = self::questions_table();
            $questions_total = absint($wpdb->get_var(
                "SELECT COUNT(*) FROM {$questions_table} q WHERE " . self::curriculum_where()
            ));
        }

        return array(
            'available'=>SEO_Solucionador_DB::table_exists(self::questions_table()) && SEO_Solucionador_DB::table_exists(self::runs_table()),
            'questions_total'=>$questions_total,
            'scan_token'=>(string) ($state['token'] ?? ''),
            'cursor'=>absint($state['cursor'] ?? 0),
            'scan_complete'=>!empty($state['complete']),
            'processed'=>absint($state['processed'] ?? 0),
            'learned'=>absint($state['learned'] ?? 0),
            'not_learned'=>max(0,absint($state['processed'] ?? 0)-absint($state['learned'] ?? 0)),
            'learned_with_category'=>absint($state['learned_with_category'] ?? 0),
            'learned_without_category'=>absint($state['learned_without_category'] ?? 0),
            'categories_with_knowledge'=>$categories_with,
            'categories_total'=>$categories_total,
            'categories_without_knowledge'=>max(0,$categories_total-$categories_with),
            'questions_in_dossiers'=>$questions_in_dossiers,
            'avg_questions_per_category'=>$categories_with ? round($questions_in_dossiers/$categories_with,2) : 0,
            'errors'=>absint($state['errors'] ?? 0),
            'last_run_at'=>(string) ($state['last_run_at'] ?? ''),
            'started_at'=>(string) ($state['started_at'] ?? ''),
            'updated_at'=>(string) ($state['updated_at'] ?? ''),
            'completed_at'=>(string) ($state['completed_at'] ?? ''),
        );
    }

    public static function rows($limit = 100, $after_id = 0) {
        global $wpdb;
        $table = SEO_Solucionador_DB::dossiers_table();
        if (!SEO_Solucionador_DB::table_exists($table)) return array();
        $limit = max(1,min(500,absint($limit)));
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE id>%d AND question_count>0
             ORDER BY id ASC LIMIT %d",
            absint($after_id),
            $limit
        ), ARRAY_A);
    }

    public static function get_by_category($category_id) {
        global $wpdb;
        $table = SEO_Solucionador_DB::dossiers_table();
        $category_id = absint($category_id);
        if (!$category_id || !SEO_Solucionador_DB::table_exists($table)) return array();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE category_id=%d LIMIT 1",
            $category_id
        ), ARRAY_A);
        return is_array($row) ? $row : array();
    }

    public static function question_details($category_id) {
        global $wpdb;
        $dossier = self::get_by_category($category_id);
        if (!$dossier) return array();

        $ids = SEO_Solucionador_DB::decode_json($dossier['question_ids'] ?? '[]', array());
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
        if (!$ids) return array();
        $ids = array_slice($ids,0,500);

        $questions = self::questions_table();
        $runs = self::runs_table();
        $placeholders = implode(',', array_fill(0,count($ids),'%d'));

        $sql = "SELECT
                    q.id question_id,q.question,q.question_type,q.lesson_key,
                    q.source_type,q.source_id,q.source_key,q.expected_json,
                    r.id run_id,r.status run_status,r.search_strategy,
                    r.evaluation_status,r.evaluation_score,r.evaluation_json,
                    r.top_results,r.response_meta,r.created_at observed_at
                FROM {$questions} q
                INNER JOIN (
                    SELECT question_id,MAX(id) latest_run_id
                    FROM {$runs}
                    WHERE question_id IS NOT NULL
                    GROUP BY question_id
                ) latest ON latest.question_id=q.id
                INNER JOIN {$runs} r ON r.id=latest.latest_run_id
                WHERE q.id IN ({$placeholders})
                  AND r.status='answered'
                  AND LEFT(COALESCE(r.evaluation_status,''),5)='pass_'
                ORDER BY q.lesson_order ASC,q.id ASC";

        $rows = (array) $wpdb->get_results($wpdb->prepare($sql,$ids),ARRAY_A);
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'question_id'=>absint($row['question_id'] ?? 0),
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
                'observed_at'=>sanitize_text_field((string) ($row['observed_at'] ?? '')),
            );
        }
        return $out;
    }

    /**
     * Convierte la salida estructurada del ultimo entrenamiento en una respuesta
     * legible sin inventar contenido. La fuente sigue siendo top_results /
     * response_meta / evaluation guardados por Academia.
     */
    public static function answer_text(array $detail) {
        $parts = array();

        foreach (array_slice((array) ($detail['top_results'] ?? array()), 0, 3) as $result) {
            if (!is_array($result)) continue;
            $title = sanitize_text_field((string) ($result['title'] ?? $result['name'] ?? $result['label'] ?? ''));
            if ($title === '') continue;
            $reasons = array_values(array_filter(array_map('sanitize_text_field', (array) ($result['reasons'] ?? array()))));
            $parts[] = $title . ($reasons ? ' — ' . implode('; ', array_slice($reasons,0,3)) : '');
        }
        if ($parts) {
            return 'Dependiente devolvió como respuesta: ' . implode(' | ', $parts) . '.';
        }

        $meta = is_array($detail['response_meta'] ?? null) ? $detail['response_meta'] : array();
        $related = array();
        foreach (array_slice((array) ($meta['related_results'] ?? $meta['related_all'] ?? array()), 0, 3) as $item) {
            if (!is_array($item)) continue;
            $title = sanitize_text_field((string) ($item['title'] ?? ''));
            if ($title !== '') $related[] = $title;
        }
        if ($related) {
            return 'Dependiente devolvió contenido relacionado: ' . implode(' | ', $related) . '.';
        }

        $evaluation = is_array($detail['evaluation'] ?? null) ? $detail['evaluation'] : array();
        $reason = sanitize_text_field((string) ($evaluation['reason'] ?? ''));
        if ($reason !== '') {
            return $reason;
        }

        $expected = is_array($detail['expected'] ?? null) ? $detail['expected'] : array();
        $kind = sanitize_key((string) ($expected['kind'] ?? ''));
        if ($kind === 'category') {
            $name = sanitize_text_field((string) ($expected['category_name'] ?? ''));
            if ($name === '' && !empty($expected['category_id'])) {
                $term = get_term(absint($expected['category_id']), 'product_cat');
                if ($term && !is_wp_error($term)) $name = (string) $term->name;
            }
            if ($name !== '') return 'Dependiente validó la respuesta dentro de la categoría ' . $name . '.';
        }
        if (in_array($kind, array('product','features'), true)) {
            $product_id = absint($expected['product_id'] ?? $expected['source_product_id'] ?? 0);
            if ($product_id) {
                $title = get_the_title($product_id);
                if ($title) return 'Dependiente validó como referencia: ' . sanitize_text_field((string) $title) . '.';
            }
        }

        return 'Respuesta validada por Dependiente; este entrenamiento no guardó una respuesta textual adicional.';
    }

    public static function signals($limit = 100, $after_id = 0) {
        $out = array();
        foreach (self::rows($limit,$after_id) as $row) {
            $term_id = absint($row['category_id'] ?? 0);
            $name = trim((string) ($row['category_name'] ?? ''));
            $count = absint($row['question_count'] ?? 0);
            if (!$term_id || $name === '' || !$count) continue;
            $ids = SEO_Solucionador_DB::decode_json($row['question_ids'] ?? '[]',array());

            $out[] = array(
                'dossier_id'=>absint($row['id'] ?? 0),
                'source_type'=>'dependiente',
                'proposal_role'=>'origin',
                'source_id'=>'academy-category:' . $term_id,
                'signal_type'=>'learned_category_dossier',
                'entity_type'=>'product_cat',
                'entity_id'=>$term_id,
                'category_id'=>$term_id,
                'category_name'=>$name,
                'source_text'=>'Preguntas habituales sobre ' . $name . ': conocimiento aprendido por Dependiente.',
                'hints'=>array(
                    'intent'=>'dependiente_qa_basic',
                    'action'=>'resolver',
                    'object'=>$name,
                    'category_id'=>$term_id,
                ),
                'occurrences'=>$count,
                'confidence'=>max(0.60,min(1.0,(float) ($row['score_avg'] ?? 0.90))),
                'evidence_score'=>1.00,
                'observed_at'=>(string) ($row['last_validated_at'] ?? current_time('mysql')),
                'source_meta'=>array(
                    'proposal_role'=>'origin',
                    'dependiente_channel'=>'academy_learned_dossier',
                    'editorial_family'=>'dependiente_qa_basic',
                    'dossier_id'=>absint($row['id'] ?? 0),
                    'category_id'=>$term_id,
                    'category_name'=>$name,
                    'question_count'=>$count,
                    'question_ids'=>array_values(array_filter(array_map('absint',(array) $ids))),
                    'source_hash'=>(string) ($row['source_hash'] ?? ''),
                    'last_validated_at'=>(string) ($row['last_validated_at'] ?? ''),
                ),
            );
        }
        return $out;
    }
}

<?php
/**
 * Lectura editorial de conocimiento consolidado de Dependiente.
 *
 * No publica ni modifica conocimiento. Expone únicamente:
 * - preguntas de Entrenador cuyo último run está answered + pass_*;
 * - reglas semánticas activas consolidadas.
 *
 * Excluye seed, academy_stage, learned_candidate, learned_rejected y search logs.
 */

defined('ABSPATH') || exit;

final class SEO_Dependiente_Editorial_Knowledge {
    const VERSION = '1.0.0';

    private static function questions_table() {
        global $wpdb;
        return class_exists('SEO_Dependiente_Entrenador')
            ? SEO_Dependiente_Entrenador::questions_table()
            : $wpdb->prefix . 'seo_dependiente_trainer_questions';
    }

    private static function runs_table() {
        global $wpdb;
        return class_exists('SEO_Dependiente_Entrenador')
            ? SEO_Dependiente_Entrenador::runs_table()
            : $wpdb->prefix . 'seo_dependiente_trainer_runs';
    }

    private static function semantics_table() {
        global $wpdb;
        return class_exists('SEO_Dependiente_Semantics')
            ? SEO_Dependiente_Semantics::table()
            : $wpdb->prefix . 'seo_dependiente_semantics';
    }

    private static function table_exists($table) {
        global $wpdb;
        $table = (string)$table;
        if ($table === '') return false;
        return (string)$wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table))
        ) === $table;
    }

    private static function decode($value) {
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value,true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function curriculum_where() {
        return "q.enabled=1 AND q.lesson_key<>'' AND q.lesson_key NOT LIKE 'lab\\_%'";
    }

    private static function consolidated_semantics_where() {
        return "active=1 AND (source IS NULL OR source NOT IN ('seed','academy_stage','learned_candidate','learned_rejected'))";
    }

    private static function valid_category_ids(array $ids) {
        $out = array();
        foreach (array_values(array_unique(array_filter(array_map('absint',$ids)))) as $term_id) {
            $term = get_term($term_id,'product_cat');
            if ($term && !is_wp_error($term)) $out[] = $term_id;
        }
        sort($out,SORT_NUMERIC);
        return array_values(array_unique($out));
    }

    private static function product_category_ids($product_id) {
        $product_id = absint($product_id);
        if (!$product_id || get_post_type($product_id) !== 'product') return array();
        $ids = wp_get_post_terms($product_id,'product_cat',array('fields'=>'ids'));
        return is_wp_error($ids) ? array() : self::valid_category_ids((array)$ids);
    }

    private static function trainer_category_ids(array $row) {
        global $wpdb;
        $expected = self::decode($row['expected_json'] ?? '');
        $ids = array();
        $kind = sanitize_key((string)($expected['kind'] ?? ''));
        $source_type = sanitize_key((string)($row['source_type'] ?? ''));
        $source_id = absint($row['source_id'] ?? 0);

        if (!empty($expected['category_id'])) $ids[] = absint($expected['category_id']);
        if ($kind === 'category') {
            $ids[] = absint($expected['category_id'] ?? 0);
        } elseif ($kind === 'product') {
            $ids = array_merge($ids,self::product_category_ids(absint($expected['product_id'] ?? 0)));
        } elseif ($kind === 'features') {
            $ids = array_merge($ids,self::product_category_ids(absint($expected['source_product_id'] ?? 0)));
        } elseif ($kind === 'faq') {
            $owner_type = absint($expected['owner_type'] ?? 0);
            $owner_id = absint($expected['owner_id'] ?? 0);
            if ($owner_type === 2) $ids[] = $owner_id;
            elseif ($owner_type === 3) $ids = array_merge($ids,self::product_category_ids($owner_id));
        }

        if (!empty($expected['source_product_id'])) {
            $ids = array_merge($ids,self::product_category_ids(absint($expected['source_product_id'])));
        }

        if ($source_type === 'category' && $source_id) {
            $ids[] = $source_id;
        } elseif (in_array($source_type,array('product','features'),true) && $source_id) {
            $ids = array_merge($ids,self::product_category_ids($source_id));
        } elseif ($source_type === 'faq' && $source_id && $kind !== 'faq') {
            $faq_table = $wpdb->prefix . 'seo_faq';
            if (self::table_exists($faq_table)) {
                $faq = $wpdb->get_row($wpdb->prepare(
                    "SELECT object_type,object_id FROM {$faq_table} WHERE id=%d LIMIT 1",
                    $source_id
                ),ARRAY_A);
                if ($faq) {
                    $owner_type = absint($faq['object_type'] ?? 0);
                    $owner_id = absint($faq['object_id'] ?? 0);
                    if ($owner_type === 2) $ids[] = $owner_id;
                    elseif ($owner_type === 3) $ids = array_merge($ids,self::product_category_ids($owner_id));
                }
            }
        }
        return self::valid_category_ids($ids);
    }

    private static function metadata_ids(array $metadata,&$category_ids,&$product_ids) {
        foreach ($metadata as $key=>$value) {
            $key = sanitize_key((string)$key);
            if (in_array($key,array('category_id','product_cat_id'),true)) {
                $category_ids[] = absint($value);
            } elseif (in_array($key,array('category_ids','product_cat_ids'),true) && is_array($value)) {
                $category_ids = array_merge($category_ids,array_map('absint',$value));
            } elseif (in_array($key,array('product_id','source_product_id'),true)) {
                $product_ids[] = absint($value);
            } elseif ($key === 'product_ids' && is_array($value)) {
                $product_ids = array_merge($product_ids,array_map('absint',$value));
            } elseif (is_array($value)) {
                self::metadata_ids($value,$category_ids,$product_ids);
            }
        }
    }

    private static function categories_for_vocabulary(array $vocabulary_ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint',$vocabulary_ids))));
        if (!$ids) return array();

        $table = $wpdb->prefix . 'seo_object_vocabulary';
        if (!self::table_exists($table)) return array();

        $placeholders = implode(',',array_fill(0,count($ids),'%d'));
        $sql = "SELECT DISTINCT object_id FROM {$table}
                WHERE object_type='product_cat' AND status=1
                  AND vocabulary_id IN ({$placeholders})";
        $prepared = $wpdb->prepare($sql,$ids);
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- placeholders internos.
        return self::valid_category_ids((array)$wpdb->get_col($prepared));
    }

    private static function semantic_category_ids(array $row) {
        $category_ids = array();
        $product_ids = array();
        $metadata = self::decode($row['metadata'] ?? '');
        self::metadata_ids($metadata,$category_ids,$product_ids);

        foreach (array_values(array_unique(array_filter(array_map('absint',$product_ids)))) as $product_id) {
            $category_ids = array_merge($category_ids,self::product_category_ids($product_id));
        }

        $category_ids = array_merge($category_ids,self::categories_for_vocabulary(array(
            absint($row['source_vocabulary_id'] ?? 0),
            absint($row['context_vocabulary_id'] ?? 0),
            absint($row['target_vocabulary_id'] ?? 0),
        )));
        return self::valid_category_ids($category_ids);
    }

    private static function normalized_question($text) {
        $text = strtolower(remove_accents(wp_strip_all_tags((string)$text)));
        $text = preg_replace('/[^a-z0-9]+/u',' ',$text);
        return trim((string)preg_replace('/\s+/u',' ',(string)$text));
    }

    private static function editorial_value(array $row) {
        $question = self::normalized_question($row['question'] ?? '');
        $type = sanitize_key((string)($row['question_type'] ?? ''));
        if ($question === '') return array('eligible'=>false,'reason'=>'empty_question');

        if (in_array($type,array(
            'category_identity','category_identity_fallback','category_inventory',
            'category_listing','category_membership','product_identity',
            'product_identity_fallback','product_listing'
        ),true)) {
            return array('eligible'=>false,'reason'=>'training_identity_or_catalog');
        }

        foreach (array(
            '/^que es (esta |esa |la |una |un )?categoria\b/',
            '/^que define (esta |esa |la )?categoria\b/',
            '/^como se define (esta |esa |la )?categoria\b/',
            '/^que productos( del catalogo)? (pertenecen|estan asignados|forman parte|hay|incluye|incluyen|contiene|contienen)\b.*\bcategoria\b/',
            '/^que productos (incluye|contiene|hay en|forman parte de)\b/',
            '/^cuales son los productos( del catalogo)? (de|en|pertenecientes a)\b.*\bcategoria\b/',
            '/^que articulos( del catalogo)? (pertenecen|hay|incluye|contiene)\b.*\bcategoria\b/',
            '/^que contiene (esta |esa |la )?categoria\b/',
            '/^lista(r)? (los )?(productos|articulos)\b.*\bcategoria\b/'
        ) as $pattern) {
            if (preg_match($pattern,$question)) {
                return array('eligible'=>false,'reason'=>'catalog_or_definition_question');
            }
        }
        return array('eligible'=>true,'reason'=>'practical_customer_value');
    }

    private static function trainer_answer(array $row) {
        $parts = array();
        foreach (array_slice(self::decode($row['top_results'] ?? ''),0,3) as $result) {
            if (!is_array($result)) continue;
            $title = sanitize_text_field((string)($result['title'] ?? $result['name'] ?? $result['label'] ?? ''));
            if ($title === '') continue;
            $reasons = array_values(array_filter(array_map('sanitize_text_field',(array)($result['reasons'] ?? array()))));
            $parts[] = $title . ($reasons ? ' — ' . implode('; ',array_slice($reasons,0,3)) : '');
        }
        if ($parts) return implode(' | ',$parts) . '.';

        $meta = self::decode($row['response_meta'] ?? '');
        $related = array();
        foreach (array_slice((array)($meta['related_results'] ?? $meta['related_all'] ?? array()),0,3) as $item) {
            if (!is_array($item)) continue;
            $title = sanitize_text_field((string)($item['title'] ?? ''));
            if ($title !== '') $related[] = $title;
        }
        if ($related) return 'Dependiente devolvió contenido relacionado: ' . implode(' | ',$related) . '.';

        $evaluation = self::decode($row['evaluation_json'] ?? '');
        $reason = sanitize_text_field((string)($evaluation['reason'] ?? ''));
        return $reason !== '' ? $reason : 'Respuesta validada por Dependiente.';
    }

    private static function trainer_item(array $row) {
        $question_id = absint($row['question_id'] ?? $row['id'] ?? 0);
        $run_id = absint($row['run_id'] ?? 0);
        $validation = sanitize_key((string)($row['evaluation_status'] ?? ''));
        if (!$question_id || !$run_id || (string)($row['run_status'] ?? $row['status'] ?? '') !== 'answered'
            || strpos($validation,'pass_') !== 0) {
            return array();
        }

        $editorial = self::editorial_value($row);
        $categories = self::trainer_category_ids($row);
        $expected = self::decode($row['expected_json'] ?? '');
        $product_id = absint($expected['product_id'] ?? $expected['source_product_id'] ?? 0);
        $score = max(0,min(1,(float)($row['evaluation_score'] ?? 0)));
        $answer = self::trainer_answer($row);
        $item = array(
            'item_id'=>'dependiente:trainer:' . $question_id,
            'origin'=>'dependiente',
            'dependiente_source'=>'trainer',
            'source_id'=>$question_id,
            'category_ids'=>$categories,
            'product_id'=>$product_id ?: null,
            'question'=>sanitize_text_field((string)($row['question'] ?? '')),
            'answer'=>$answer,
            'validation'=>$validation,
            'question_type'=>sanitize_key((string)($row['question_type'] ?? '')),
            'lesson_key'=>sanitize_key((string)($row['lesson_key'] ?? '')),
            'run_id'=>$run_id,
            'confidence'=>$score,
            'first_seen_at'=>sanitize_text_field((string)($row['run_created_at'] ?? $row['created_at'] ?? '')),
            'last_seen_at'=>sanitize_text_field((string)($row['run_created_at'] ?? $row['created_at'] ?? '')),
            'editorial_candidate'=>!empty($editorial['eligible']),
            'discard_reason'=>empty($editorial['eligible']) ? (string)($editorial['reason'] ?? 'training_noise') : '',
            'expected'=>$expected,
            'evaluation'=>self::decode($row['evaluation_json'] ?? ''),
            'top_results'=>self::decode($row['top_results'] ?? ''),
            'response_meta'=>self::decode($row['response_meta'] ?? ''),
            'search_strategy'=>sanitize_key((string)($row['search_strategy'] ?? '')),
        );
        $item['source_hash'] = hash('sha256',wp_json_encode(array(
            'item_id'=>$item['item_id'],
            'category_ids'=>$categories,
            'question'=>$item['question'],
            'answer'=>$item['answer'],
            'validation'=>$validation,
            'confidence'=>round($score,4),
            'run_id'=>$run_id,
            'editorial_candidate'=>$item['editorial_candidate'],
            'discard_reason'=>$item['discard_reason'],
        ),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $item;
    }

    private static function semantic_question(array $row,array $metadata) {
        foreach ((array)($metadata['learning']['examples'] ?? array()) as $example) {
            $example = sanitize_text_field((string)$example);
            if ($example !== '') return $example;
        }
        foreach ((array)($metadata['examples'] ?? array()) as $example) {
            $example = sanitize_text_field((string)$example);
            if ($example !== '') return $example;
        }
        $expression = sanitize_text_field((string)($row['expression'] ?? $row['normalized_expression'] ?? ''));
        return $expression !== '' ? '¿Cómo interpreta Dependiente «' . $expression . '»?' : '';
    }

    private static function semantic_answer(array $row) {
        $expression = sanitize_text_field((string)($row['expression'] ?? $row['normalized_expression'] ?? ''));
        $canonical = sanitize_text_field((string)($row['canonical_expression'] ?? ''));
        $target = sanitize_text_field((string)($row['target_slug'] ?? ''));
        $relation = sanitize_key((string)($row['relation_type'] ?? 'related')) ?: 'related';
        if ($canonical !== '') {
            return 'Dependiente relaciona «' . $expression . '» con «' . $canonical . '» mediante ' . $relation . '.';
        }
        if ($target !== '') {
            return 'Dependiente relaciona «' . $expression . '» con «' . $target . '» mediante ' . $relation . '.';
        }
        return '';
    }

    private static function semantic_item(array $row) {
        $id = absint($row['id'] ?? 0);
        if (!$id || empty($row['active'])) return array();

        $source = sanitize_key((string)($row['source'] ?? 'manual')) ?: 'manual';
        if (in_array($source,array('seed','academy_stage','learned_candidate','learned_rejected'),true)) return array();

        $metadata = self::decode($row['metadata'] ?? '');
        $question = self::semantic_question($row,$metadata);
        $answer = self::semantic_answer($row);
        $categories = self::semantic_category_ids($row);
        $confidence = isset($row['confidence']) && $row['confidence'] !== null
            ? max(0,min(1,(float)$row['confidence']))
            : 0.75;

        $item = array(
            'item_id'=>'dependiente:semantic:' . $id,
            'origin'=>'dependiente',
            'dependiente_source'=>$source,
            'source_id'=>$id,
            'category_ids'=>$categories,
            'product_id'=>null,
            'question'=>$question,
            'answer'=>$answer,
            'validation'=>'approved_semantic_rule',
            'question_type'=>'semantic_rule',
            'lesson_key'=>sanitize_key((string)($metadata['academy']['lesson_key'] ?? $metadata['lesson_key'] ?? '')),
            'run_id'=>null,
            'confidence'=>$confidence,
            'first_seen_at'=>sanitize_text_field((string)($row['created_at'] ?? '')),
            'last_seen_at'=>sanitize_text_field((string)($row['updated_at'] ?? $row['created_at'] ?? '')),
            'editorial_candidate'=>$question !== '' && $answer !== '',
            'discard_reason'=>($question !== '' && $answer !== '') ? '' : 'semantic_rule_without_editorial_text',
            'semantic_rule'=>array(
                'rule_key'=>sanitize_key((string)($row['rule_key'] ?? '')),
                'rule_type'=>sanitize_key((string)($row['rule_type'] ?? '')),
                'semantic_role'=>sanitize_key((string)($row['semantic_role'] ?? '')),
                'relation_type'=>$relation = sanitize_key((string)($row['relation_type'] ?? '')),
            ),
        );
        $item['source_hash'] = hash('sha256',wp_json_encode(array(
            'item_id'=>$item['item_id'],
            'source'=>$source,
            'category_ids'=>$categories,
            'question'=>$question,
            'answer'=>$answer,
            'confidence'=>round($confidence,4),
            'updated_at'=>$item['last_seen_at'],
        ),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $item;
    }

    public static function available() {
        return (self::table_exists(self::questions_table()) && self::table_exists(self::runs_table()))
            || self::table_exists(self::semantics_table());
    }

    public static function inventory() {
        global $wpdb;
        $out = array(
            'trainer_questions'=>0,
            'semantic_rules'=>0,
            'total'=>0,
        );

        $questions = self::questions_table();
        if (self::table_exists($questions)) {
            $where = self::curriculum_where();
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna, filtro fijo.
            $out['trainer_questions'] = absint($wpdb->get_var(
                "SELECT COUNT(*) FROM {$questions} q WHERE {$where}"
            ));
        }

        $semantics = self::semantics_table();
        if (self::table_exists($semantics)) {
            $where = self::consolidated_semantics_where();
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna, filtro fijo.
            $out['semantic_rules'] = absint($wpdb->get_var(
                "SELECT COUNT(*) FROM {$semantics} WHERE {$where}"
            ));
        }

        $out['total'] = $out['trainer_questions'] + $out['semantic_rules'];
        return $out;
    }

    public static function signature() {
        global $wpdb;
        $parts = array('provider'=>self::VERSION);

        $questions = self::questions_table();
        $runs = self::runs_table();
        if (self::table_exists($questions) && self::table_exists($runs)) {
            $where = self::curriculum_where();
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tablas internas, filtro fijo.
            $q = (array)$wpdb->get_row(
                "SELECT COUNT(q.id) total,COALESCE(MAX(q.id),0) max_id FROM {$questions} q WHERE {$where}",
                ARRAY_A
            );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna.
            $max_run = absint($wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM {$runs} WHERE question_id IS NOT NULL"));
            $parts['trainer'] = array(absint($q['total'] ?? 0),absint($q['max_id'] ?? 0),$max_run);
        }

        $semantics = self::semantics_table();
        if (self::table_exists($semantics)) {
            $where = self::consolidated_semantics_where();
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna, filtro fijo.
            $row = (array)$wpdb->get_row(
                "SELECT COUNT(*) total,COALESCE(MAX(id),0) max_id,COALESCE(MAX(updated_at),'') max_updated
                 FROM {$semantics} WHERE {$where}",
                ARRAY_A
            );
            $parts['semantics'] = array(
                absint($row['total'] ?? 0),
                absint($row['max_id'] ?? 0),
                (string)($row['max_updated'] ?? ''),
            );
        }
        return hash('sha256',wp_json_encode($parts));
    }

    public static function batch(array $cursor = array(),$limit = 150) {
        global $wpdb;
        $limit = max(25,min(500,absint($limit)));
        $q_cursor = absint($cursor['trainer_question'] ?? 0);
        $run_cursor = absint($cursor['trainer_run'] ?? 0);
        $semantic_cursor = absint($cursor['semantic'] ?? 0);

        $items = array();
        $stats = array(
            'processed'=>0,'learned'=>0,'editorial_eligible'=>0,'editorial_discarded'=>0,
            'with_category'=>0,'without_category'=>0,
        );

        $questions = self::questions_table();
        $runs = self::runs_table();
        $q_rows = array();
        $runs_by_question = array();

        if (self::table_exists($questions) && self::table_exists($runs)) {
            $where = self::curriculum_where();
            $q_sql = $wpdb->prepare(
                "SELECT q.id question_id,q.lesson_key,q.source_type,q.source_id,q.source_key,
                        q.question_type,q.question,q.expected_json
                 FROM {$questions} q
                 WHERE {$where} AND q.id>%d
                 ORDER BY q.id ASC LIMIT %d",
                $q_cursor,$limit
            );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- SQL preparado.
            $q_rows = (array)$wpdb->get_results($q_sql,ARRAY_A);
            $q_ids = array_values(array_filter(array_map(static function($row){
                return absint($row['question_id'] ?? 0);
            },$q_rows)));

            if ($q_ids) {
                $ph = implode(',',array_fill(0,count($q_ids),'%d'));
                $sql = "SELECT r.id run_id,r.question_id,r.status run_status,r.search_strategy,
                               r.evaluation_status,r.evaluation_score,r.evaluation_json,
                               r.top_results,r.response_meta,r.created_at run_created_at
                        FROM {$runs} r
                        INNER JOIN (
                          SELECT question_id,MAX(id) latest_run_id FROM {$runs}
                          WHERE question_id IN ({$ph}) GROUP BY question_id
                        ) latest ON latest.latest_run_id=r.id";
                $prepared = $wpdb->prepare($sql,$q_ids);
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- IDs preparados.
                foreach ((array)$wpdb->get_results($prepared,ARRAY_A) as $run) {
                    $runs_by_question[absint($run['question_id'] ?? 0)] = $run;
                    $run_cursor = max($run_cursor,absint($run['run_id'] ?? 0));
                }
            }

            $question_complete = count($q_rows)<$limit;
            $delta_count = 0;
            if ($question_complete) {
                $delta_sql = $wpdb->prepare(
                    "SELECT q.id question_id,q.lesson_key,q.source_type,q.source_id,q.source_key,
                            q.question_type,q.question,q.expected_json,
                            r.id run_id,r.status run_status,r.search_strategy,
                            r.evaluation_status,r.evaluation_score,r.evaluation_json,
                            r.top_results,r.response_meta,r.created_at run_created_at
                     FROM {$questions} q
                     INNER JOIN (
                       SELECT question_id,MAX(id) latest_run_id FROM {$runs}
                       WHERE question_id IS NOT NULL GROUP BY question_id
                     ) latest ON latest.question_id=q.id
                     INNER JOIN {$runs} r ON r.id=latest.latest_run_id
                     WHERE {$where} AND r.id>%d
                     ORDER BY r.id ASC LIMIT %d",
                    $run_cursor,$limit
                );
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- SQL preparado.
                $delta_rows = (array)$wpdb->get_results($delta_sql,ARRAY_A);
                $delta_count = count($delta_rows);
                foreach ($delta_rows as $delta) {
                    $qid = absint($delta['question_id'] ?? 0);
                    if (!$qid) continue;
                    $runs_by_question[$qid] = $delta;
                    $q_rows[] = $delta;
                    $run_cursor = max($run_cursor,absint($delta['run_id'] ?? 0));
                }
            }

            $seen_questions = array();
            foreach ($q_rows as $row) {
                $qid = absint($row['question_id'] ?? 0);
                if (!$qid || isset($seen_questions[$qid])) continue;
                $seen_questions[$qid] = true;
                $q_cursor = max($q_cursor,$qid);
                $stats['processed']++;

                $combined = array_merge($row,(array)($runs_by_question[$qid] ?? array()));
                $item = self::trainer_item($combined);
                if (!$item) continue;
                $stats['learned']++;
                if (empty($item['editorial_candidate'])) {
                    $stats['editorial_discarded']++;
                    continue;
                }
                $stats['editorial_eligible']++;
                if (!empty($item['category_ids'])) $stats['with_category']++;
                else $stats['without_category']++;
                $items[$item['item_id']] = $item;
            }
            $trainer_complete = $question_complete && $delta_count<$limit;
        } else {
            $trainer_complete = true;
        }

        $semantics = self::semantics_table();
        $semantic_rows = array();
        if (self::table_exists($semantics)) {
            $where = self::consolidated_semantics_where();
            $semantic_sql = $wpdb->prepare(
                "SELECT id,rule_key,rule_type,expression,normalized_expression,canonical_expression,
                        semantic_role,source_vocabulary_id,context_vocabulary_id,target_vocabulary_id,
                        target_group,target_slug,relation_type,confidence,source,metadata,active,created_at,updated_at
                 FROM {$semantics}
                 WHERE {$where} AND id>%d
                 ORDER BY id ASC LIMIT %d",
                $semantic_cursor,$limit
            );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- SQL preparado.
            $semantic_rows = (array)$wpdb->get_results($semantic_sql,ARRAY_A);
            foreach ($semantic_rows as $row) {
                $semantic_cursor = max($semantic_cursor,absint($row['id'] ?? 0));
                $stats['processed']++;
                $stats['learned']++;
                $item = self::semantic_item($row);
                if (!$item) continue;
                if (empty($item['editorial_candidate'])) {
                    $stats['editorial_discarded']++;
                    continue;
                }
                $stats['editorial_eligible']++;
                if (!empty($item['category_ids'])) $stats['with_category']++;
                else $stats['without_category']++;
                $items[$item['item_id']] = $item;
            }
            $semantic_complete = count($semantic_rows)<$limit;
        } else {
            $semantic_complete = true;
        }

        return array(
            'items'=>array_values($items),
            'cursor'=>array(
                'trainer_question'=>$q_cursor,
                'trainer_run'=>$run_cursor,
                'semantic'=>$semantic_cursor,
            ),
            'trainer_complete'=>$trainer_complete,
            'semantic_complete'=>$semantic_complete,
            'complete'=>$trainer_complete && $semantic_complete,
            'stats'=>$stats,
        );
    }

    public static function details(array $item_keys) {
        global $wpdb;
        $trainer_ids = array();
        $semantic_ids = array();
        foreach ($item_keys as $key) {
            $key = sanitize_text_field((string)$key);
            if (preg_match('/^dependiente:trainer:([0-9]+)$/',$key,$m)) $trainer_ids[] = absint($m[1]);
            elseif (preg_match('/^dependiente:semantic:([0-9]+)$/',$key,$m)) $semantic_ids[] = absint($m[1]);
            elseif (preg_match('/^dependiente:([0-9]+)$/',$key,$m)) $trainer_ids[] = absint($m[1]);
        }

        $out = array();
        $trainer_ids = array_values(array_unique(array_filter($trainer_ids)));
        if ($trainer_ids && self::table_exists(self::questions_table()) && self::table_exists(self::runs_table())) {
            $q = self::questions_table();
            $r = self::runs_table();
            $ph = implode(',',array_fill(0,count($trainer_ids),'%d'));
            $sql = "SELECT q.id question_id,q.lesson_key,q.source_type,q.source_id,q.source_key,
                           q.question_type,q.question,q.expected_json,
                           rr.id run_id,rr.status run_status,rr.search_strategy,
                           rr.evaluation_status,rr.evaluation_score,rr.evaluation_json,
                           rr.top_results,rr.response_meta,rr.created_at run_created_at
                    FROM {$q} q
                    INNER JOIN (
                      SELECT question_id,MAX(id) latest_run_id FROM {$r}
                      WHERE question_id IN ({$ph}) GROUP BY question_id
                    ) latest ON latest.question_id=q.id
                    INNER JOIN {$r} rr ON rr.id=latest.latest_run_id
                    WHERE q.id IN ({$ph})";
            $args = array_merge($trainer_ids,$trainer_ids);
            $prepared = $wpdb->prepare($sql,$args);
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- IDs preparados.
            foreach ((array)$wpdb->get_results($prepared,ARRAY_A) as $row) {
                $item = self::trainer_item($row);
                if ($item) $out[$item['item_id']] = $item;
            }
        }

        $semantic_ids = array_values(array_unique(array_filter($semantic_ids)));
        $semantics = self::semantics_table();
        if ($semantic_ids && self::table_exists($semantics)) {
            $ph = implode(',',array_fill(0,count($semantic_ids),'%d'));
            $sql = "SELECT id,rule_key,rule_type,expression,normalized_expression,canonical_expression,
                           semantic_role,source_vocabulary_id,context_vocabulary_id,target_vocabulary_id,
                           target_group,target_slug,relation_type,confidence,source,metadata,active,created_at,updated_at
                    FROM {$semantics} WHERE id IN ({$ph})";
            $prepared = $wpdb->prepare($sql,$semantic_ids);
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- IDs preparados.
            foreach ((array)$wpdb->get_results($prepared,ARRAY_A) as $row) {
                $item = self::semantic_item($row);
                if ($item) $out[$item['item_id']] = $item;
            }
        }
        return $out;
    }
}

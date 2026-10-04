<?php
/**
 * Solucionador - dossiers category-first de FAQ + Academia/Entrenador.
 *
 * Mantiene una única huella editorial por product_cat con dos orígenes
 * independientes: FAQs activas y preguntas realmente aprendidas por Dependiente.
 * Los payloads completos se recuperan bajo demanda al abrir/exportar el brief.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Dossiers {
    const STATE_OPTION = 'seo_solucionador_academia_scan_state';
    const DEFAULT_BATCH = 150;
    const EDITORIAL_POLICY_VERSION = 'v3-faq-plus-dependiente';

    private static function questions_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_trainer_questions';
    }

    private static function runs_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_trainer_runs';
    }

    private static function faq_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_faq';
    }

    private static function faq_source_signature() {
        global $wpdb;
        $table = self::faq_table();
        if (!SEO_Solucionador_DB::table_exists($table)) return '';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna sin entrada de usuario.
        $row = (array)$wpdb->get_row(
            "SELECT COUNT(*) total,COALESCE(MAX(id),0) max_id,COALESCE(MAX(updated_at),'') max_updated
             FROM {$table} WHERE active=1",
            ARRAY_A
        );
        return hash('sha256',wp_json_encode(array(
            absint($row['total'] ?? 0),
            absint($row['max_id'] ?? 0),
            (string)($row['max_updated'] ?? ''),
        )));
    }

    private static function academy_source_signature() {
        global $wpdb;
        $table = self::runs_table();
        if (!SEO_Solucionador_DB::table_exists($table)) return '';
        // Los runs son append-only en Academia. Se observa cualquier run nuevo,
        // no sólo pass_*, porque una reevaluación posterior también puede retirar
        // conocimiento que antes formaba parte del dossier.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna sin entrada de usuario.
        $row = (array)$wpdb->get_row(
            "SELECT COUNT(*) total,COALESCE(MAX(id),0) max_id FROM {$table}",
            ARRAY_A
        );
        return hash('sha256',wp_json_encode(array(
            absint($row['total'] ?? 0),
            absint($row['max_id'] ?? 0),
        )));
    }

    private static function curriculum_where() {
        return "q.enabled=1 AND q.lesson_key<>'' AND q.lesson_key NOT LIKE 'lab\\_%'";
    }

    private static function decode($value) {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function normalized_question($text) {
        $text = strtolower(remove_accents(wp_strip_all_tags((string) $text)));
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

    /**
     * Separa entrenamiento útil para Dependiente de contenido publicable.
     * Las preguntas triviales siguen existiendo en Academia; simplemente no
     * cuentan para la masa editorial ni entran en los posts de Solucionador.
     */
    public static function editorial_question_value(array $row) {
        $question = self::normalized_question($row['question'] ?? '');
        $type = sanitize_key((string) ($row['question_type'] ?? ''));

        if ($question === '') {
            return array('eligible'=>false,'reason'=>'empty_question');
        }

        $trivial_types = array(
            'category_identity',
            'category_identity_fallback',
            'category_inventory',
            'category_listing',
            'category_membership',
            'product_identity',
            'product_identity_fallback',
            'product_listing',
        );
        if (in_array($type, $trivial_types, true)) {
            return array('eligible'=>false,'reason'=>'training_identity_or_catalog');
        }

        $patterns = array(
            '/^que es (esta |esa |la |una |un )?categoria\b/',
            '/^que define (esta |esa |la )?categoria\b/',
            '/^como se define (esta |esa |la )?categoria\b/',
            '/^que productos( del catalogo)? (pertenecen|estan asignados|forman parte|hay|incluye|incluyen|contiene|contienen)\b.*\bcategoria\b/',
            '/^que productos (incluye|contiene|hay en|forman parte de)\b/',
            '/^cuales son los productos( del catalogo)? (de|en|pertenecientes a)\b.*\bcategoria\b/',
            '/^que articulos( del catalogo)? (pertenecen|hay|incluye|contiene)\b.*\bcategoria\b/',
            '/^que contiene (esta |esa |la )?categoria\b/',
            '/^lista(r)? (los )?(productos|articulos)\b.*\bcategoria\b/',
        );
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $question)) {
                return array('eligible'=>false,'reason'=>'catalog_or_definition_question');
            }
        }

        return array('eligible'=>true,'reason'=>'practical_customer_value');
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

    private static function faq_category_ids(array $row) {
        $object_type = absint($row['object_type'] ?? 0);
        $object_id = absint($row['object_id'] ?? 0);
        if (!$object_type || !$object_id) return array();

        if ($object_type === 2) {
            return self::valid_category_ids(array($object_id));
        }
        if ($object_type === 3) {
            return self::product_category_ids($object_id);
        }

        // Las FAQs de hubs/páginas no se expanden por parecido textual.
        // Sólo entran cuando existe una product_cat demostrable.
        return array();
    }

    private static function faq_item_hash(array $row, $category_id) {
        return hash('sha256', wp_json_encode(array(
            'origin'=>'faq',
            'source_id'=>absint($row['id'] ?? $row['faq_id'] ?? 0),
            'object_type'=>absint($row['object_type'] ?? 0),
            'object_id'=>absint($row['object_id'] ?? 0),
            'category_id'=>absint($category_id),
            'question'=>sanitize_text_field((string)($row['question'] ?? '')),
            'answer'=>trim(wp_strip_all_tags((string)($row['answer'] ?? ''))),
            'updated_at'=>sanitize_text_field((string)($row['updated_at'] ?? '')),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function fresh_state() {
        return array(
            'token'=>wp_generate_uuid4(),
            'editorial_policy'=>self::EDITORIAL_POLICY_VERSION,
            'cursor'=>0,
            'processed'=>0,
            'learned'=>0,
            'editorial_eligible'=>0,
            'editorial_discarded'=>0,
            'learned_with_category'=>0,
            'learned_without_category'=>0,
            'academy_complete'=>false,
            'faq_cursor'=>0,
            'faq_processed'=>0,
            'faq_with_category'=>0,
            'faq_without_category'=>0,
            'faq_complete'=>false,
            'faq_source_signature'=>'',
            'academy_source_signature'=>'',
            'errors'=>0,
            'last_run_at'=>'',
            'last_faq_at'=>'',
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
        global $wpdb;
        $state = self::fresh_state();
        update_option(self::STATE_OPTION, $state, false);

        // Al cambiar de política editorial no se mezclan IDs antiguos con el
        // nuevo contrato. Se conserva el inventario de categorías, pero se vacía
        // temporalmente el material hasta reconstruirlo desde FAQ + Academia.
        $table = SEO_Solucionador_DB::dossiers_table();
        if (SEO_Solucionador_DB::table_exists($table)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table es una tabla interna validada; valores variables usan placeholders compatibles con WP 5.8.
            $reset_sql = $wpdb->prepare(
                "UPDATE {$table}
                 SET question_count=0,
                     dependiente_count=0,
                     faq_count=0,
                     question_ids='[]',
                     faq_ids='[]',
                     item_hashes='{}',
                     score_avg=0,
                     last_validated_at=NULL,
                     source_hash='',
                     scan_token=%s,
                     updated_at=%s",
                (string) $state['token'],
                current_time('mysql')
            );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- $reset_sql es el resultado de $wpdb->prepare().
            $wpdb->query($reset_sql);
        }
        return $state;
    }

    /**
     * Garantiza un dossier ligero para TODAS las product_cat de WooCommerce.
     * FAQ y Academia enriquecen esos dossiers, pero nunca deciden
     * si una categoría existe o no dentro de Solucionador.
     */
    public static function ensure_all_categories($token = '') {
        global $wpdb;

        SEO_Solucionador_DB::maybe_install();
        $table = SEO_Solucionador_DB::dossiers_table();
        if (!SEO_Solucionador_DB::table_exists($table)) return 0;

        $token = sanitize_text_field((string) $token);
        if ($token === '') {
            $state = self::state();
            if (!$state || empty($state['token'])) {
                $state = self::reset_scan();
            }
            $token = sanitize_text_field((string) ($state['token'] ?? ''));
        }

        $terms = get_terms(array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ));
        if (is_wp_error($terms)) return 0;

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- $table es una tabla interna validada; consulta fija sin datos externos.
        $existing_rows = (array) $wpdb->get_results(
            "SELECT category_id,category_name FROM {$table}",
            ARRAY_A
        );
        $existing = array();
        foreach ($existing_rows as $row) {
            $existing[absint($row['category_id'] ?? 0)] = (string) ($row['category_name'] ?? '');
        }

        $now = current_time('mysql');
        $live_ids = array();

        foreach ((array) $terms as $term) {
            if (!$term instanceof WP_Term) continue;
            $term_id = absint($term->term_id);
            if (!$term_id) continue;

            $live_ids[$term_id] = true;
            $name = sanitize_text_field((string) $term->name);

            if (isset($existing[$term_id])) {
                if ($existing[$term_id] !== $name) {
                    $wpdb->update(
                        $table,
                        array('category_name'=>$name,'updated_at'=>$now),
                        array('category_id'=>$term_id)
                    );
                }
                continue;
            }

            $source_hash = hash('sha256', wp_json_encode(array(
                'category_id'=>$term_id,
                'question_ids'=>array(),
                'faq_ids'=>array(),
                'item_hashes'=>array(),
                'score_avg'=>0,
                'last_validated_at'=>'',
            )));

            $wpdb->insert($table, array(
                'category_id'=>$term_id,
                'category_name'=>$name,
                'question_count'=>0,
                'dependiente_count'=>0,
                'faq_count'=>0,
                'question_ids'=>'[]',
                'faq_ids'=>'[]',
                'score_avg'=>0,
                'last_validated_at'=>null,
                'source_hash'=>$source_hash,
                'rejected_source_hash'=>'',
                'rejected_at'=>null,
                'scan_token'=>$token,
                'demand_occurrences'=>0,
                'created_at'=>$now,
                'updated_at'=>$now,
            ));
        }

        // El inventario debe reflejar exactamente las product_cat actuales.
        foreach ($existing as $category_id=>$name) {
            if ($category_id && empty($live_ids[$category_id])) {
                $wpdb->delete($table, array('category_id'=>$category_id));
            }
        }

        return count($live_ids);
    }

    private static function upsert_batch_dossier($term_id, array $dependiente_ids, array $faq_ids, array $item_hashes, $score_sum, $last_validated_at, $token) {
        global $wpdb;
        $table = SEO_Solucionador_DB::dossiers_table();
        $term_id = absint($term_id);
        if (!$term_id || (!$dependiente_ids && !$faq_ids)) return false;

        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) return false;

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE category_id=%d LIMIT 1",
            $term_id
        ), ARRAY_A);

        $dependiente_ids = array_values(array_unique(array_filter(array_map('absint', $dependiente_ids))));
        $faq_ids = array_values(array_unique(array_filter(array_map('absint', $faq_ids))));
        $previous_dependiente = array();
        $previous_faq = array();
        $previous_item_hashes = array();
        $previous_dep_score_sum = 0.0;

        if ($existing && (string)($existing['scan_token'] ?? '') === (string)$token) {
            $previous_dependiente = array_values(array_unique(array_filter(array_map(
                'absint',
                (array) SEO_Solucionador_DB::decode_json($existing['question_ids'] ?? '[]', array())
            ))));
            $previous_faq = array_values(array_unique(array_filter(array_map(
                'absint',
                (array) SEO_Solucionador_DB::decode_json($existing['faq_ids'] ?? '[]', array())
            ))));
            $previous_item_hashes = SEO_Solucionador_DB::decode_json($existing['item_hashes'] ?? '{}', array());
            $previous_dep_score_sum = (float)($existing['score_avg'] ?? 0) * count($previous_dependiente);
        }

        $merged_dependiente = array_values(array_unique(array_merge($previous_dependiente, $dependiente_ids)));
        $merged_faq = array_values(array_unique(array_merge($previous_faq, $faq_ids)));
        sort($merged_dependiente, SORT_NUMERIC);
        sort($merged_faq, SORT_NUMERIC);

        $normalized_item_hashes = array();
        foreach ($item_hashes as $item_key=>$item_hash) {
            $item_key = sanitize_text_field((string)$item_key);
            $item_hash = sanitize_text_field((string)$item_hash);
            if (preg_match('/^(dependiente|faq):[0-9]+$/', $item_key) && $item_hash !== '') {
                $normalized_item_hashes[$item_key] = $item_hash;
            }
        }
        $merged_item_hashes = array_merge((array)$previous_item_hashes, $normalized_item_hashes);
        ksort($merged_item_hashes, SORT_STRING);

        $new_dependiente_count = max(0, count($merged_dependiente) - count($previous_dependiente));
        $dep_count = count($merged_dependiente);
        $faq_count = count($merged_faq);
        $total_count = $dep_count + $faq_count;
        $combined_score_sum = $previous_dep_score_sum + (float)$score_sum;
        $avg = $dep_count > 0 ? min(1, max(0, $combined_score_sum / max(1, count($previous_dependiente) + $new_dependiente_count))) : 0;

        $last = (string)$last_validated_at;
        if ($existing && (string)($existing['scan_token'] ?? '') === (string)$token) {
            $old_last = (string)($existing['last_validated_at'] ?? '');
            if ($old_last > $last) $last = $old_last;
        }

        $hash = hash('sha256', wp_json_encode(array(
            'category_id'=>$term_id,
            'dependiente_ids'=>$merged_dependiente,
            'faq_ids'=>$merged_faq,
            'item_hashes'=>$merged_item_hashes,
            'score_avg'=>round($avg,4),
            'last_validated_at'=>$last,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $now = current_time('mysql');
        $data = array(
            'category_name'=>sanitize_text_field((string)$term->name),
            'question_count'=>$total_count,
            'dependiente_count'=>$dep_count,
            'faq_count'=>$faq_count,
            'question_ids'=>wp_json_encode($merged_dependiente),
            'faq_ids'=>wp_json_encode($merged_faq),
            'item_hashes'=>wp_json_encode($merged_item_hashes),
            'score_avg'=>round($avg,4),
            'last_validated_at'=>$last !== '' ? $last : null,
            'source_hash'=>$hash,
            'editorial_status'=>($existing && !empty($existing['reviewed_hash']) && (string)$existing['reviewed_hash'] !== $hash)
                ? SEO_Editorial_Service_Contract::NEEDS_UPDATE
                : (($existing && !empty($existing['editorial_status']))
                    ? SEO_Editorial_Service_Contract::normalize($existing['editorial_status'])
                    : SEO_Editorial_Service_Contract::READY_FOR_REVIEW),
            'scan_token'=>(string)$token,
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
        $faq_table = self::faq_table();
        $academy_available = SEO_Solucionador_DB::table_exists($questions) && SEO_Solucionador_DB::table_exists($runs);
        $faq_available = SEO_Solucionador_DB::table_exists($faq_table);

        if (!$academy_available && !$faq_available) {
            return new WP_Error('solucionador_sources_missing', 'No están disponibles ni Academia/Entrenador ni la tabla de FAQs.');
        }

        $limit = max(25, min(500, absint($limit)));
        $state = self::state();
        if (
            $reset
            || !$state
            || empty($state['token'])
            || (string)($state['editorial_policy'] ?? '') !== self::EDITORIAL_POLICY_VERSION
        ) {
            $state = self::reset_scan();
        }

        $batch_by_category = array();

        // Fuente 1: Dependiente/Entrenador. Sólo conocimiento realmente aprendido pass_*.
        $cursor = absint($state['cursor'] ?? 0);
        $rows = array();
        if ($academy_available && empty($state['academy_complete'])) {
            $where = self::curriculum_where();
            $scan_sql = $wpdb->prepare(
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
            );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- SQL preparado; tablas/filtro internos.
            $rows = (array)$wpdb->get_results($scan_sql, ARRAY_A);
        }

        $last_cursor = $cursor;
        foreach ($rows as $row) {
            $question_id = absint($row['question_id'] ?? 0);
            if ($question_id > $last_cursor) $last_cursor = $question_id;
            $state['processed'] = absint($state['processed'] ?? 0) + 1;

            $run_at = sanitize_text_field((string)($row['run_created_at'] ?? ''));
            if ($run_at !== '' && $run_at > (string)($state['last_run_at'] ?? '')) {
                $state['last_run_at'] = $run_at;
            }

            $learned = (string)($row['run_status'] ?? '') === 'answered'
                && strpos((string)($row['evaluation_status'] ?? ''), 'pass_') === 0;
            if (!$learned) continue;
            $state['learned'] = absint($state['learned'] ?? 0) + 1;

            $editorial_value = self::editorial_question_value($row);
            if (empty($editorial_value['eligible'])) {
                $state['editorial_discarded'] = absint($state['editorial_discarded'] ?? 0) + 1;
                continue;
            }
            $state['editorial_eligible'] = absint($state['editorial_eligible'] ?? 0) + 1;

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
                    $batch_by_category[$term_id] = array(
                        'dependiente_ids'=>array(),'faq_ids'=>array(),'item_hashes'=>array(),
                        'score_sum'=>0.0,'last'=>''
                    );
                }
                $batch_by_category[$term_id]['dependiente_ids'][] = $question_id;
                $batch_by_category[$term_id]['item_hashes']['dependiente:' . $question_id] = hash(
                    'sha256',
                    wp_json_encode(array(
                        'origin'=>'dependiente',
                        'question'=>sanitize_text_field((string)($row['question'] ?? '')),
                        'question_type'=>sanitize_key((string)($row['question_type'] ?? '')),
                        'source_type'=>sanitize_key((string)($row['source_type'] ?? '')),
                        'source_id'=>absint($row['source_id'] ?? 0),
                        'source_key'=>sanitize_text_field((string)($row['source_key'] ?? '')),
                        'expected_json'=>(string)($row['expected_json'] ?? ''),
                        'run_id'=>absint($row['run_id'] ?? 0),
                        'evaluation_status'=>sanitize_key((string)($row['evaluation_status'] ?? '')),
                        'evaluation_score'=>round((float)($row['evaluation_score'] ?? 0),4),
                        'category_id'=>absint($term_id),
                    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
                $batch_by_category[$term_id]['score_sum'] += max(0,min(1,(float)($row['evaluation_score'] ?? 0)));
                if ($run_at > $batch_by_category[$term_id]['last']) $batch_by_category[$term_id]['last'] = $run_at;
            }
        }
        $state['cursor'] = $last_cursor;
        if (!$academy_available || count($rows) < $limit) $state['academy_complete'] = true;

        // Fuente 2: FAQ manual activa. Es editorialmente válida para Solucionador
        // aunque Dependiente nunca la haya aprendido.
        $faq_cursor = absint($state['faq_cursor'] ?? 0);
        $faq_rows = array();
        if ($faq_available && empty($state['faq_complete'])) {
            $faq_sql = $wpdb->prepare(
                "SELECT id,object_type,object_id,question,answer,created_at,updated_at
                 FROM {$faq_table}
                 WHERE active=1 AND id>%d
                 ORDER BY id ASC
                 LIMIT %d",
                $faq_cursor,
                $limit
            );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- SQL preparado; tabla interna.
            $faq_rows = (array)$wpdb->get_results($faq_sql, ARRAY_A);
        }

        $last_faq_cursor = $faq_cursor;
        foreach ($faq_rows as $faq) {
            $faq_id = absint($faq['id'] ?? 0);
            if (!$faq_id) continue;
            if ($faq_id > $last_faq_cursor) $last_faq_cursor = $faq_id;
            $state['faq_processed'] = absint($state['faq_processed'] ?? 0) + 1;

            $question = trim(wp_strip_all_tags((string)($faq['question'] ?? '')));
            $answer = trim(wp_strip_all_tags((string)($faq['answer'] ?? '')));
            if ($question === '' || $answer === '') continue;

            try {
                $category_ids = self::faq_category_ids($faq);
            } catch (Throwable $e) {
                $state['errors'] = absint($state['errors'] ?? 0) + 1;
                continue;
            }
            if (!$category_ids) {
                $state['faq_without_category'] = absint($state['faq_without_category'] ?? 0) + 1;
                continue;
            }

            $state['faq_with_category'] = absint($state['faq_with_category'] ?? 0) + 1;
            $faq_at = sanitize_text_field((string)($faq['updated_at'] ?? $faq['created_at'] ?? ''));
            if ($faq_at > (string)($state['last_faq_at'] ?? '')) $state['last_faq_at'] = $faq_at;

            foreach ($category_ids as $term_id) {
                if (!isset($batch_by_category[$term_id])) {
                    $batch_by_category[$term_id] = array(
                        'dependiente_ids'=>array(),'faq_ids'=>array(),'item_hashes'=>array(),
                        'score_sum'=>0.0,'last'=>''
                    );
                }
                $batch_by_category[$term_id]['faq_ids'][] = $faq_id;
                $batch_by_category[$term_id]['item_hashes']['faq:' . $faq_id] = self::faq_item_hash($faq,$term_id);
                if ($faq_at > $batch_by_category[$term_id]['last']) $batch_by_category[$term_id]['last'] = $faq_at;
            }
        }
        $state['faq_cursor'] = $last_faq_cursor;
        if (!$faq_available || count($faq_rows) < $limit) $state['faq_complete'] = true;

        foreach ($batch_by_category as $term_id=>$batch) {
            try {
                $saved = self::upsert_batch_dossier(
                    $term_id,
                    (array)$batch['dependiente_ids'],
                    (array)$batch['faq_ids'],
                    (array)$batch['item_hashes'],
                    (float)$batch['score_sum'],
                    (string)$batch['last'],
                    (string)$state['token']
                );
                if ($saved && class_exists('SEO_Solucionador_Posts') && method_exists('SEO_Solucionador_Posts','sync_category_post')) {
                    SEO_Solucionador_Posts::sync_category_post($term_id);
                }
            } catch (Throwable $e) {
                $state['errors'] = absint($state['errors'] ?? 0) + 1;
            }
        }

        $state['updated_at'] = current_time('mysql');
        $state['complete'] = !empty($state['academy_complete']) && !empty($state['faq_complete']);
        if (!empty($state['complete'])) {
            $state['completed_at'] = current_time('mysql');
            $state['faq_source_signature'] = self::faq_source_signature();
            $state['academy_source_signature'] = self::academy_source_signature();
        }

        update_option(self::STATE_OPTION,$state,false);
        return self::snapshot();
    }

    public static function snapshot() {
        global $wpdb;
        $state = self::state();
        $table = SEO_Solucionador_DB::dossiers_table();
        $current_faq_signature = self::faq_source_signature();
        $current_academy_signature = self::academy_source_signature();
        $source_changed = !empty($state['complete']) && (
            ((string)($state['faq_source_signature'] ?? '') !== '' && (string)$state['faq_source_signature'] !== $current_faq_signature)
            || ((string)($state['academy_source_signature'] ?? '') !== '' && (string)$state['academy_source_signature'] !== $current_academy_signature)
        );

        // Solucionador inventaría TODAS las product_cat. FAQ y Academia enriquecen
        // el mismo dossier, pero no deciden qué categorías existen.
        self::ensure_all_categories((string)($state['token'] ?? ''));

        $categories_with = SEO_Solucionador_DB::table_exists($table)
            ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE question_count>0"))
            : 0;
        $items_in_dossiers = SEO_Solucionador_DB::table_exists($table)
            ? absint($wpdb->get_var("SELECT COALESCE(SUM(question_count),0) FROM {$table}"))
            : 0;
        $dependiente_in_dossiers = SEO_Solucionador_DB::table_exists($table)
            ? absint($wpdb->get_var("SELECT COALESCE(SUM(dependiente_count),0) FROM {$table}"))
            : 0;
        $faq_in_dossiers = SEO_Solucionador_DB::table_exists($table)
            ? absint($wpdb->get_var("SELECT COALESCE(SUM(faq_count),0) FROM {$table}"))
            : 0;

        $categories_total = wp_count_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
        $categories_total = is_wp_error($categories_total) ? 0 : absint($categories_total);

        $questions_total = 0;
        if (SEO_Solucionador_DB::table_exists(self::questions_table())) {
            $questions_table = self::questions_table();
            $curriculum_where = self::curriculum_where();
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla y filtro internos.
            $questions_total = absint($wpdb->get_var("SELECT COUNT(*) FROM {$questions_table} q WHERE {$curriculum_where}"));
        }

        $faqs_total = SEO_Solucionador_DB::table_exists(self::faq_table())
            ? absint($wpdb->get_var("SELECT COUNT(*) FROM " . self::faq_table() . " WHERE active=1"))
            : 0;

        return array(
            'available'=>SEO_Solucionador_DB::table_exists(self::faq_table())
                || (SEO_Solucionador_DB::table_exists(self::questions_table()) && SEO_Solucionador_DB::table_exists(self::runs_table())),
            'questions_total'=>$questions_total,
            'faqs_total'=>$faqs_total,
            'scan_token'=>(string)($state['token'] ?? ''),
            'cursor'=>absint($state['cursor'] ?? 0),
            'faq_cursor'=>absint($state['faq_cursor'] ?? 0),
            'scan_complete'=>!empty($state['complete']),
            'source_changed'=>$source_changed,
            'faq_source_signature'=>$current_faq_signature,
            'academy_source_signature'=>$current_academy_signature,
            'academy_complete'=>!empty($state['academy_complete']),
            'faq_complete'=>!empty($state['faq_complete']),
            'processed'=>absint($state['processed'] ?? 0),
            'learned'=>absint($state['learned'] ?? 0),
            'not_learned'=>max(0,absint($state['processed'] ?? 0)-absint($state['learned'] ?? 0)),
            'faq_processed'=>absint($state['faq_processed'] ?? 0),
            'editorial_policy'=>(string)($state['editorial_policy'] ?? ''),
            'editorial_eligible'=>absint($state['editorial_eligible'] ?? 0),
            'editorial_discarded'=>absint($state['editorial_discarded'] ?? 0),
            'learned_with_category'=>absint($state['learned_with_category'] ?? 0),
            'learned_without_category'=>absint($state['learned_without_category'] ?? 0),
            'faq_with_category'=>absint($state['faq_with_category'] ?? 0),
            'faq_without_category'=>absint($state['faq_without_category'] ?? 0),
            'categories_with_knowledge'=>$categories_with,
            'categories_total'=>$categories_total,
            'categories_without_knowledge'=>max(0,$categories_total-$categories_with),
            'questions_in_dossiers'=>$items_in_dossiers,
            'dependiente_in_dossiers'=>$dependiente_in_dossiers,
            'faq_in_dossiers'=>$faq_in_dossiers,
            'avg_questions_per_category'=>$categories_with ? round($items_in_dossiers/$categories_with,2) : 0,
            'errors'=>absint($state['errors'] ?? 0),
            'last_run_at'=>(string)($state['last_run_at'] ?? ''),
            'last_faq_at'=>(string)($state['last_faq_at'] ?? ''),
            'started_at'=>(string)($state['started_at'] ?? ''),
            'updated_at'=>(string)($state['updated_at'] ?? ''),
            'completed_at'=>(string)($state['completed_at'] ?? ''),
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

    public static function proposal_available(array $dossier) {
        $source_hash = (string) ($dossier['source_hash'] ?? '');
        $blocked_hash = (string) ($dossier['rejected_source_hash'] ?? '');
        return $source_hash !== '' && ($blocked_hash === '' || $blocked_hash !== $source_hash);
    }

    public static function mark_rejected($category_id) {
        global $wpdb;
        $category_id = absint($category_id);
        $dossier = self::get_by_category($category_id);
        if (!$dossier) return false;

        $source_hash = (string) ($dossier['source_hash'] ?? '');
        if ($source_hash === '') return false;

        return false !== $wpdb->update(
            SEO_Solucionador_DB::dossiers_table(),
            array(
                'rejected_source_hash'=>$source_hash,
                'editorial_status'=>SEO_Editorial_Service_Contract::REJECTED,
                'rejected_at'=>current_time('mysql'),
                'updated_at'=>current_time('mysql'),
            ),
            array('category_id'=>$category_id)
        );
    }

    public static function changed_item_keys($category_id) {
        $dossier = self::get_by_category($category_id);
        if (!$dossier) return array();

        $current = SEO_Solucionador_DB::decode_json($dossier['item_hashes'] ?? '{}', array());
        $reviewed = SEO_Solucionador_DB::decode_json($dossier['reviewed_item_hashes'] ?? '{}', array());
        $changed = array();

        foreach ((array)$current as $item_key=>$item_hash) {
            $item_key = sanitize_text_field((string)$item_key);
            if (!preg_match('/^(dependiente|faq):[0-9]+$/', $item_key)) continue;
            if (!isset($reviewed[$item_key]) || (string)$reviewed[$item_key] !== (string)$item_hash) {
                $changed[] = $item_key;
            }
        }
        sort($changed, SORT_STRING);
        return array_values(array_unique($changed));
    }

    public static function changed_item_ids($category_id) {
        $ids = array();
        foreach (self::changed_item_keys($category_id) as $key) {
            if (strpos($key,'dependiente:') === 0) $ids[] = absint(substr($key,12));
        }
        return array_values(array_unique(array_filter($ids)));
    }

    public static function review_item_keys($category_id, array $item_keys) {
        $category_id = absint($category_id);
        $dossier = self::get_by_category($category_id);
        if (!$dossier) return false;

        $current = SEO_Solucionador_DB::decode_json($dossier['item_hashes'] ?? '{}', array());
        $reviewed = SEO_Solucionador_DB::decode_json($dossier['reviewed_item_hashes'] ?? '{}', array());

        foreach ($item_keys as $item_key) {
            $item_key = sanitize_text_field((string)$item_key);
            if (!preg_match('/^(dependiente|faq):[0-9]+$/', $item_key)) continue;
            if (isset($current[$item_key])) $reviewed[$item_key] = (string)$current[$item_key];
        }
        ksort($reviewed, SORT_STRING);

        $remaining = array();
        foreach ((array)$current as $item_key=>$item_hash) {
            $item_key = sanitize_text_field((string)$item_key);
            if (!preg_match('/^(dependiente|faq):[0-9]+$/', $item_key)) continue;
            if (!isset($reviewed[$item_key]) || (string)$reviewed[$item_key] !== (string)$item_hash) {
                $remaining[] = $item_key;
            }
        }

        $post_id = class_exists('SEO_Solucionador_Posts')
            ? SEO_Solucionador_Posts::managed_post_id_by_category_public($category_id)
            : 0;
        $post_status = $post_id ? get_post_status($post_id) : '';
        $editorial_status = $remaining
            ? SEO_Editorial_Service_Contract::NEEDS_UPDATE
            : ($post_status === 'publish'
                ? SEO_Editorial_Service_Contract::PUBLISHED
                : ($post_id ? SEO_Editorial_Service_Contract::DRAFT : SEO_Editorial_Service_Contract::READY_FOR_REVIEW));

        return SEO_Solucionador_DB::update_dossier_editorial($category_id, array(
            'reviewed_hash'=>$remaining ? (string)($dossier['reviewed_hash'] ?? '') : (string)($dossier['source_hash'] ?? ''),
            'reviewed_item_hashes'=>wp_json_encode($reviewed),
            'editorial_status'=>$editorial_status,
            'reviewed_at'=>current_time('mysql'),
        ));
    }

    public static function review_item_ids($category_id, array $question_ids) {
        $keys = array();
        foreach (array_values(array_unique(array_filter(array_map('absint',$question_ids)))) as $question_id) {
            $keys[] = 'dependiente:' . $question_id;
        }
        return self::review_item_keys($category_id,$keys);
    }

    public static function clear_rejection($category_id) {
        $category_id = absint($category_id);
        if (!$category_id) return false;
        return SEO_Solucionador_DB::update_dossier_editorial($category_id, array(
            'rejected_source_hash'=>'',
            'rejected_at'=>null,
            'editorial_status'=>SEO_Editorial_Service_Contract::READY_FOR_REVIEW,
        ));
    }

    public static function mark_reviewed($category_id) {
        $category_id = absint($category_id);
        $dossier = self::get_by_category($category_id);
        if (!$dossier || empty($dossier['source_hash'])) return false;

        $post_id = class_exists('SEO_Solucionador_Posts')
            ? SEO_Solucionador_Posts::managed_post_id_by_category_public($category_id)
            : 0;
        $status = $post_id ? get_post_status($post_id) : '';
        $editorial_status = $status === 'publish'
            ? SEO_Editorial_Service_Contract::PUBLISHED
            : ($post_id ? SEO_Editorial_Service_Contract::DRAFT : SEO_Editorial_Service_Contract::READY_FOR_REVIEW);

        return SEO_Solucionador_DB::update_dossier_editorial($category_id, array(
            'reviewed_hash'=>(string)$dossier['source_hash'],
            'reviewed_item_hashes'=>(string)($dossier['item_hashes'] ?? '{}'),
            'editorial_status'=>$editorial_status,
            'reviewed_at'=>current_time('mysql'),
        ));
    }

    public static function question_details($category_id) {
        global $wpdb;
        $dossier = self::get_by_category($category_id);
        if (!$dossier) return array();

        $out = array();

        $ids = array_values(array_unique(array_filter(array_map(
            'absint',
            (array)SEO_Solucionador_DB::decode_json($dossier['question_ids'] ?? '[]', array())
        ))));
        if ($ids && SEO_Solucionador_DB::table_exists(self::questions_table()) && SEO_Solucionador_DB::table_exists(self::runs_table())) {
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
            $prepared_sql = $wpdb->prepare($sql,$ids);
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- SQL preparado con IDs enteros.
            $rows = (array)$wpdb->get_results($prepared_sql,ARRAY_A);

            foreach ($rows as $row) {
                $editorial_value = self::editorial_question_value($row);
                if (empty($editorial_value['eligible'])) continue;
                $question_id = absint($row['question_id'] ?? 0);
                $out[] = array(
                    'origin'=>'dependiente',
                    'item_key'=>'dependiente:' . $question_id,
                    'question_id'=>$question_id,
                    'faq_id'=>0,
                    'question'=>sanitize_text_field((string)($row['question'] ?? '')),
                    'answer'=>'',
                    'question_type'=>sanitize_key((string)($row['question_type'] ?? '')),
                    'editorial_value'=>(string)($editorial_value['reason'] ?? 'practical_customer_value'),
                    'lesson_key'=>sanitize_key((string)($row['lesson_key'] ?? '')),
                    'source_type'=>sanitize_key((string)($row['source_type'] ?? '')),
                    'source_id'=>absint($row['source_id'] ?? 0) ?: null,
                    'source_key'=>sanitize_text_field((string)($row['source_key'] ?? '')),
                    'expected'=>self::decode($row['expected_json'] ?? ''),
                    'evaluation_status'=>sanitize_key((string)($row['evaluation_status'] ?? '')),
                    'evaluation_score'=>max(0,min(1,(float)($row['evaluation_score'] ?? 0))),
                    'evaluation'=>self::decode($row['evaluation_json'] ?? ''),
                    'top_results'=>array_values(array_slice(self::decode($row['top_results'] ?? ''),0,12)),
                    'response_meta'=>self::decode($row['response_meta'] ?? ''),
                    'search_strategy'=>sanitize_key((string)($row['search_strategy'] ?? '')),
                    'observed_at'=>sanitize_text_field((string)($row['observed_at'] ?? '')),
                    'category_id'=>absint($category_id),
                );
            }
        }

        $faq_ids = array_values(array_unique(array_filter(array_map(
            'absint',
            (array)SEO_Solucionador_DB::decode_json($dossier['faq_ids'] ?? '[]', array())
        ))));
        if ($faq_ids && SEO_Solucionador_DB::table_exists(self::faq_table())) {
            $faq_ids = array_slice($faq_ids,0,500);
            $faq_table = self::faq_table();
            $placeholders = implode(',',array_fill(0,count($faq_ids),'%d'));
            $sql = "SELECT id,object_type,object_id,question,answer,created_at,updated_at
                    FROM {$faq_table}
                    WHERE active=1 AND id IN ({$placeholders})
                    ORDER BY sort_order ASC,id ASC";
            $prepared_sql = $wpdb->prepare($sql,$faq_ids);
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- SQL preparado con IDs enteros.
            $faq_rows = (array)$wpdb->get_results($prepared_sql,ARRAY_A);

            foreach ($faq_rows as $row) {
                $faq_id = absint($row['id'] ?? 0);
                $category_ids = self::faq_category_ids($row);
                if (!$faq_id || !in_array(absint($category_id),$category_ids,true)) continue;

                $source_hash = self::faq_item_hash($row,$category_id);
                $out[] = array(
                    'origin'=>'faq',
                    'item_key'=>'faq:' . $faq_id,
                    'question_id'=>0,
                    'faq_id'=>$faq_id,
                    'question'=>sanitize_text_field((string)($row['question'] ?? '')),
                    'answer'=>trim(wp_strip_all_tags((string)($row['answer'] ?? ''))),
                    'question_type'=>'faq_manual',
                    'editorial_value'=>'manual_editorial_knowledge',
                    'lesson_key'=>'',
                    'source_type'=>'faq',
                    'source_id'=>$faq_id,
                    'source_key'=>'faq:' . $faq_id,
                    'source_hash'=>$source_hash,
                    'object_type'=>absint($row['object_type'] ?? 0),
                    'object_id'=>absint($row['object_id'] ?? 0),
                    'category_id'=>absint($category_id),
                    'evaluation_status'=>'manual_source',
                    'evaluation_score'=>1.0,
                    'evaluation'=>array(),
                    'top_results'=>array(),
                    'response_meta'=>array(),
                    'search_strategy'=>'',
                    'observed_at'=>sanitize_text_field((string)($row['updated_at'] ?? $row['created_at'] ?? '')),
                );
            }
        }

        usort($out,static function($a,$b){
            $oa=(string)($a['origin'] ?? '');
            $ob=(string)($b['origin'] ?? '');
            if ($oa !== $ob) return $oa === 'faq' ? -1 : 1;
            return strcasecmp((string)($a['question'] ?? ''),(string)($b['question'] ?? ''));
        });
        return $out;
    }

    /**
     * Convierte la salida estructurada del ultimo entrenamiento en una respuesta
     * legible sin inventar contenido. La fuente sigue siendo top_results /
     * response_meta / evaluation guardados por Academia.
     */
    public static function answer_text(array $detail) {
        if (sanitize_key((string)($detail['origin'] ?? '')) === 'faq') {
            return trim(wp_strip_all_tags((string)($detail['answer'] ?? '')));
        }

        $parts = array();

        foreach (array_slice((array) ($detail['top_results'] ?? array()), 0, 3) as $result) {
            if (!is_array($result)) continue;
            $title = sanitize_text_field((string) ($result['title'] ?? $result['name'] ?? $result['label'] ?? ''));
            if ($title === '') continue;
            $reasons = array_values(array_filter(array_map('sanitize_text_field', (array) ($result['reasons'] ?? array()))));
            $parts[] = $title . ($reasons ? ' — ' . implode('; ', array_slice($reasons,0,3)) : '');
        }
        if ($parts) {
            return implode(' | ', $parts) . '.';
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
            $name = trim((string)($row['category_name'] ?? ''));
            $count = absint($row['question_count'] ?? 0);
            if (!$term_id || $name === '' || !$count) continue;

            $dependiente_ids = SEO_Solucionador_DB::decode_json($row['question_ids'] ?? '[]',array());
            $faq_ids = SEO_Solucionador_DB::decode_json($row['faq_ids'] ?? '[]',array());
            $dependiente_count = absint($row['dependiente_count'] ?? count((array)$dependiente_ids));
            $faq_count = absint($row['faq_count'] ?? count((array)$faq_ids));

            $out[] = array(
                'dossier_id'=>absint($row['id'] ?? 0),
                'source_type'=>'dependiente',
                'proposal_role'=>'origin',
                'source_id'=>'mixed-category:' . $term_id,
                'signal_type'=>'faq_dependiente_category_dossier',
                'entity_type'=>'product_cat',
                'entity_id'=>$term_id,
                'category_id'=>$term_id,
                'category_name'=>$name,
                'source_text'=>'Dossier editorial sobre ' . $name . ': FAQs manuales y conocimiento realmente aprendido por Dependiente.',
                'hints'=>array(
                    'intent'=>'dependiente_qa_basic',
                    'action'=>'resolver',
                    'object'=>$name,
                    'category_id'=>$term_id,
                ),
                'occurrences'=>$count,
                'confidence'=>$dependiente_count > 0
                    ? max(0.60,min(1.0,(float)($row['score_avg'] ?? 0.90)))
                    : 0.90,
                'evidence_score'=>1.00,
                'observed_at'=>(string)($row['last_validated_at'] ?? current_time('mysql')),
                'source_meta'=>array(
                    'proposal_role'=>'origin',
                    'dependiente_channel'=>'faq_dependiente_dossier',
                    'editorial_family'=>'dependiente_qa_basic',
                    'dossier_id'=>absint($row['id'] ?? 0),
                    'category_id'=>$term_id,
                    'category_name'=>$name,
                    'question_count'=>$count,
                    'dependiente_count'=>$dependiente_count,
                    'faq_count'=>$faq_count,
                    'question_ids'=>array_values(array_filter(array_map('absint',(array)$dependiente_ids))),
                    'faq_ids'=>array_values(array_filter(array_map('absint',(array)$faq_ids))),
                    'origins'=>array('dependiente'=>$dependiente_count,'faq'=>$faq_count),
                    'source_hash'=>(string)($row['source_hash'] ?? ''),
                    'last_validated_at'=>(string)($row['last_validated_at'] ?? ''),
                ),
            );
        }
        return $out;
    }
}

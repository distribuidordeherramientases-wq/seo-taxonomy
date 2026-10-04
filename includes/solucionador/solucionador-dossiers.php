<?php
/**
 * Solucionador - dossiers category-first de FAQ + conocimiento consolidado de Dependiente.
 *
 * Mantiene una única huella editorial por product_cat con dos orígenes
 * independientes: FAQs activas y conocimiento que Dependiente considera aprendido/aprobado.
 * Los payloads completos se recuperan bajo demanda al abrir/exportar el brief.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Dossiers {
    const STATE_OPTION = 'seo_solucionador_academia_scan_state';
    const DEFAULT_BATCH = 150;
    const EDITORIAL_POLICY_VERSION = 'v6-faq-plus-dependiente-provider';

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

    private static function dependiente_source_signature() {
        if (class_exists('SEO_Dependiente_Editorial_Knowledge')) {
            return (string)SEO_Dependiente_Editorial_Knowledge::signature();
        }

        // Fallback defensivo para instalaciones que aún no hayan cargado el
        // proveedor. No se usa como contrato editorial principal.
        global $wpdb;
        $questions = self::questions_table();
        $runs = self::runs_table();
        if (!SEO_Solucionador_DB::table_exists($questions) || !SEO_Solucionador_DB::table_exists($runs)) return '';
        $where = self::curriculum_where();
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tablas internas, filtro fijo.
        $q = (array)$wpdb->get_row(
            "SELECT COUNT(q.id) total,COALESCE(MAX(q.id),0) max_id FROM {$questions} q WHERE {$where}",
            ARRAY_A
        );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna.
        $max_run = absint($wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM {$runs} WHERE question_id IS NOT NULL"));
        return hash('sha256',wp_json_encode(array(
            absint($q['total'] ?? 0),absint($q['max_id'] ?? 0),$max_run
        )));
    }

    private static function academy_source_signature() {
        // Alias de compatibilidad 0.7. El origen editorial se llama Dependiente.
        return self::dependiente_source_signature();
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
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function dependiente_item_hash(array $question,array $run,$category_id) {
        return hash('sha256',wp_json_encode(array(
            'origin'=>'dependiente',
            'source_id'=>absint($question['id'] ?? $question['question_id'] ?? 0),
            'category_id'=>absint($category_id),
            'question'=>sanitize_text_field((string)($question['question'] ?? '')),
            'question_type'=>sanitize_key((string)($question['question_type'] ?? '')),
            'source_type'=>sanitize_key((string)($question['source_type'] ?? '')),
            'source_id_raw'=>absint($question['source_id'] ?? 0),
            'expected_json'=>(string)($question['expected_json'] ?? ''),
            'run_id'=>absint($run['id'] ?? $run['run_id'] ?? 0),
            'evaluation_status'=>sanitize_key((string)($run['evaluation_status'] ?? '')),
            'evaluation_score'=>round((float)($run['evaluation_score'] ?? 0),4),
            'run_created_at'=>sanitize_text_field((string)($run['created_at'] ?? $run['run_created_at'] ?? '')),
        ),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private static function fresh_state() {
        return array(
            'token'=>wp_generate_uuid4(),
            'editorial_policy'=>self::EDITORIAL_POLICY_VERSION,
            'dependiente_cursor'=>0,
            'dependiente_run_cursor'=>0,
            'dependiente_semantic_cursor'=>0,
            'dependiente_processed'=>0,
            'dependiente_learned'=>0,
            'dependiente_editorial_eligible'=>0,
            'dependiente_editorial_discarded'=>0,
            'dependiente_with_category'=>0,
            'dependiente_without_category'=>0,
            'dependiente_complete'=>false,
            'dependiente_source_signature'=>'',
            'dependiente_scan_signature'=>'',
            'faq_cursor'=>0,
            'faq_processed'=>0,
            'faq_with_category'=>0,
            'faq_without_category'=>0,
            'faq_complete'=>false,
            'faq_source_signature'=>'',
            'faq_scan_signature'=>'',
            // aliases históricos
            'cursor'=>0,
            'processed'=>0,
            'learned'=>0,
            'editorial_eligible'=>0,
            'editorial_discarded'=>0,
            'learned_with_category'=>0,
            'learned_without_category'=>0,
            'academy_complete'=>false,
            'academy_source_signature'=>'',
            'errors'=>0,
            'last_dependiente_at'=>'',
            'last_run_at'=>'',
            'last_faq_at'=>'',
            'complete'=>false,
            'started_at'=>current_time('mysql'),
            'updated_at'=>current_time('mysql'),
            'completed_at'=>'',
        );
    }

    public static function state() {
        $state = get_option(self::STATE_OPTION,array());
        return is_array($state) ? $state : array();
    }

    /**
     * Migra estados 0.6/0.7 sin reiniciar el cursor largo de Entrenador.
     */
    public static function migrate_state(array $state = array()) {
        if (!$state) $state = self::state();
        if (!$state || empty($state['token'])) return self::reset_scan();

        $changed = false;
        $old_cursor = $state['dependiente_cursor'] ?? $state['cursor'] ?? 0;
        $trainer_cursor = is_array($old_cursor)
            ? absint($old_cursor['trainer'] ?? 0)
            : absint($old_cursor);
        if (!isset($state['dependiente_cursor']) || is_array($state['dependiente_cursor'])) {
            $state['dependiente_cursor'] = $trainer_cursor;
            $changed = true;
        }
        if (!array_key_exists('dependiente_run_cursor',$state)) {
            $state['dependiente_run_cursor'] = is_array($old_cursor)
                ? absint($old_cursor['trainer_run'] ?? 0)
                : 0;
            $changed = true;
        }
        if (!array_key_exists('dependiente_semantic_cursor',$state)) {
            $state['dependiente_semantic_cursor'] = is_array($old_cursor)
                ? absint($old_cursor['semantic'] ?? 0)
                : 0;
            $changed = true;
        }

        $aliases = array(
            'dependiente_processed'=>'processed',
            'dependiente_learned'=>'learned',
            'dependiente_editorial_eligible'=>'editorial_eligible',
            'dependiente_editorial_discarded'=>'editorial_discarded',
            'dependiente_with_category'=>'learned_with_category',
            'dependiente_without_category'=>'learned_without_category',
        );
        foreach ($aliases as $new=>$old) {
            if (!array_key_exists($new,$state)) {
                $state[$new] = absint($state[$old] ?? 0);
                $changed = true;
            }
        }

        foreach (array(
            'dependiente_complete'=>false,'dependiente_source_signature'=>'','dependiente_scan_signature'=>'',
            'faq_cursor'=>0,'faq_processed'=>0,'faq_with_category'=>0,'faq_without_category'=>0,
            'faq_complete'=>false,'faq_source_signature'=>'','faq_scan_signature'=>'',
            'academy_source_signature'=>'','last_dependiente_at'=>'','last_faq_at'=>'','errors'=>0
        ) as $key=>$default) {
            if (!array_key_exists($key,$state)) {
                $state[$key] = $default;
                $changed = true;
            }
        }

        if ((string)($state['editorial_policy'] ?? '') !== self::EDITORIAL_POLICY_VERSION) {
            $state['editorial_policy'] = self::EDITORIAL_POLICY_VERSION;
            $state['complete'] = false;
            $state['completed_at'] = '';
            $changed = true;
        }

        $state['cursor'] = $trainer_cursor;
        $state['processed'] = absint($state['dependiente_processed']);
        $state['learned'] = absint($state['dependiente_learned']);
        $state['editorial_eligible'] = absint($state['dependiente_editorial_eligible']);
        $state['editorial_discarded'] = absint($state['dependiente_editorial_discarded']);
        $state['learned_with_category'] = absint($state['dependiente_with_category']);
        $state['learned_without_category'] = absint($state['dependiente_without_category']);
        $state['academy_complete'] = !empty($state['dependiente_complete']);

        if ($changed) {
            $state['updated_at'] = current_time('mysql');
            update_option(self::STATE_OPTION,$state,false);
        }
        return $state;
    }

    public static function reset_scan() {
        global $wpdb;
        $state = self::fresh_state();
        update_option(self::STATE_OPTION,$state,false);

        $table = SEO_Solucionador_DB::dossiers_table();
        if (SEO_Solucionador_DB::table_exists($table)) {
            $reset_sql = $wpdb->prepare(
                "UPDATE {$table}
                 SET question_count=0,
                     dependiente_count=0,
                     faq_count=0,
                     question_ids='[]',
                     dependiente_keys='[]',
                     faq_ids='[]',
                     item_hashes='{}',
                     score_avg=0,
                     last_validated_at=NULL,
                     source_hash='',
                     scan_token=%s,
                     updated_at=%s",
                (string)$state['token'],
                current_time('mysql')
            );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- SQL preparado.
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
                'dependiente_keys'=>array(),
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
                'dependiente_keys'=>'[]',
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

    private static function canonical_item_key($item_key) {
        $item_key = sanitize_text_field((string)$item_key);
        if (preg_match('/^faq:([0-9]+)$/',$item_key,$m)) {
            return 'faq:' . absint($m[1]);
        }
        if (preg_match('/^dependiente:(trainer|semantic):([0-9]+)$/',$item_key,$m)) {
            return 'dependiente:' . sanitize_key($m[1]) . ':' . absint($m[2]);
        }
        // Compatibilidad 0.7.0/0.7.1 inicial: dependiente:123 = trainer.
        if (preg_match('/^dependiente:([0-9]+)$/',$item_key,$m)) {
            return 'dependiente:trainer:' . absint($m[1]);
        }
        return '';
    }

    private static function valid_item_key($item_key) {
        return self::canonical_item_key($item_key) !== '';
    }

    private static function normalize_item_hashes(array $hashes) {
        $normalized = array();
        foreach ($hashes as $key=>$hash) {
            $key = self::canonical_item_key($key);
            $hash = sanitize_text_field((string)$hash);
            if ($key !== '' && $hash !== '') $normalized[$key] = $hash;
        }
        ksort($normalized,SORT_STRING);
        return $normalized;
    }

    public static function source_hash_for_test($category_id,array $item_hashes) {
        $normalized = self::normalize_item_hashes($item_hashes);
        return hash('sha256',wp_json_encode(array(
            'category_id'=>absint($category_id),
            'item_hashes'=>$normalized,
        ),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    public static function compare_item_hashes_for_test(array $current,array $reviewed) {
        $current = self::normalize_item_hashes($current);
        $reviewed = self::normalize_item_hashes($reviewed);
        $changes = array('new'=>array(),'modified'=>array(),'retired'=>array(),'status'=>array());

        foreach ($current as $key=>$hash) {
            if (!array_key_exists($key,$reviewed)) {
                $changes['new'][] = $key;
                $changes['status'][$key] = 'new';
            } elseif ((string)$reviewed[$key] !== (string)$hash) {
                $changes['modified'][] = $key;
                $changes['status'][$key] = 'modified';
            }
        }
        foreach ($reviewed as $key=>$hash) {
            if (!array_key_exists($key,$current)) {
                $changes['retired'][] = $key;
                $changes['status'][$key] = 'retired';
            }
        }
        foreach (array('new','modified','retired') as $type) sort($changes[$type],SORT_STRING);
        ksort($changes['status'],SORT_STRING);
        return $changes;
    }

    private static function upsert_batch_dossier(
        $term_id,
        array $dependiente_keys,
        array $legacy_question_ids,
        array $faq_ids,
        array $item_hashes,
        $score_sum,
        $score_count,
        $last_validated_at,
        $token
    ) {
        global $wpdb;
        $table = SEO_Solucionador_DB::dossiers_table();
        $term_id = absint($term_id);
        if (!$term_id || (!$dependiente_keys && !$faq_ids)) return false;

        $term = get_term($term_id,'product_cat');
        if (!$term || is_wp_error($term)) return false;

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE category_id=%d LIMIT 1",$term_id
        ),ARRAY_A);

        $dependiente_keys = array_values(array_unique(array_filter(array_map(
            array(__CLASS__,'canonical_item_key'),
            $dependiente_keys
        ))));
        $legacy_question_ids = array_values(array_unique(array_filter(array_map('absint',$legacy_question_ids))));
        $faq_ids = array_values(array_unique(array_filter(array_map('absint',$faq_ids))));

        $previous_keys = array();
        $previous_questions = array();
        $previous_faq = array();
        $previous_item_hashes = array();
        $previous_score_sum = 0.0;

        if ($existing && (string)($existing['scan_token'] ?? '') === (string)$token) {
            $previous_keys = array_values(array_unique(array_filter(array_map(
                array(__CLASS__,'canonical_item_key'),
                (array)SEO_Solucionador_DB::decode_json($existing['dependiente_keys'] ?? '[]',array())
            ))));
            $previous_questions = array_values(array_unique(array_filter(array_map(
                'absint',(array)SEO_Solucionador_DB::decode_json($existing['question_ids'] ?? '[]',array())
            ))));
            // Migración transparente 0.7: deriva keys trainer de question_ids.
            if (!$previous_keys && $previous_questions) {
                foreach ($previous_questions as $question_id) $previous_keys[] = 'dependiente:trainer:' . $question_id;
            }
            $previous_faq = array_values(array_unique(array_filter(array_map(
                'absint',(array)SEO_Solucionador_DB::decode_json($existing['faq_ids'] ?? '[]',array())
            ))));
            $previous_item_hashes = self::normalize_item_hashes(
                (array)SEO_Solucionador_DB::decode_json($existing['item_hashes'] ?? '{}',array())
            );
            $previous_score_sum = (float)($existing['score_avg'] ?? 0) * count($previous_keys);
        }

        $merged_keys = array_values(array_unique(array_merge($previous_keys,$dependiente_keys)));
        $merged_questions = array_values(array_unique(array_merge($previous_questions,$legacy_question_ids)));
        $merged_faq = array_values(array_unique(array_merge($previous_faq,$faq_ids)));
        sort($merged_keys,SORT_STRING);
        sort($merged_questions,SORT_NUMERIC);
        sort($merged_faq,SORT_NUMERIC);

        $normalized_hashes = self::normalize_item_hashes($item_hashes);
        $merged_item_hashes = array_merge((array)$previous_item_hashes,$normalized_hashes);
        ksort($merged_item_hashes,SORT_STRING);

        $dep_count = count($merged_keys);
        $faq_count = count($merged_faq);
        $total_count = $dep_count + $faq_count;
        $new_count = max(0,$dep_count-count($previous_keys));
        $combined_score_sum = $previous_score_sum + (float)$score_sum;
        $denominator = max(1,count($previous_keys)+min($new_count,absint($score_count)));
        $avg = $dep_count > 0 ? min(1,max(0,$combined_score_sum/$denominator)) : 0;

        $last = sanitize_text_field((string)$last_validated_at);
        if ($existing && (string)($existing['scan_token'] ?? '') === (string)$token) {
            $old_last = (string)($existing['last_validated_at'] ?? '');
            if ($old_last > $last) $last = $old_last;
        }

        // Estable: dos ejecuciones sin cambios producen exactamente el mismo hash.
        $hash = self::source_hash_for_test($term_id,$merged_item_hashes);

        $now = current_time('mysql');
        $data = array(
            'category_name'=>sanitize_text_field((string)$term->name),
            'question_count'=>$total_count,
            'dependiente_count'=>$dep_count,
            'faq_count'=>$faq_count,
            'question_ids'=>wp_json_encode($merged_questions),
            'dependiente_keys'=>wp_json_encode($merged_keys),
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

        if ($existing) return $wpdb->update($table,$data,array('category_id'=>$term_id)) !== false;
        $data['category_id'] = $term_id;
        $data['created_at'] = $now;
        return $wpdb->insert($table,$data) !== false;
    }

    private static function clear_source_lane($origin,$token) {
        global $wpdb;
        $origin = sanitize_key((string)$origin);
        if (!in_array($origin,array('faq','dependiente'),true)) return 0;
        $table = SEO_Solucionador_DB::dossiers_table();
        if (!SEO_Solucionador_DB::table_exists($table)) return 0;

        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- tabla interna fija.
        $rows = (array)$wpdb->get_results("SELECT * FROM {$table}",ARRAY_A);
        $updated = 0;
        foreach ($rows as $row) {
            $category_id = absint($row['category_id'] ?? 0);
            if (!$category_id) continue;
            $hashes = self::normalize_item_hashes(
                (array)SEO_Solucionador_DB::decode_json($row['item_hashes'] ?? '{}',array())
            );
            foreach (array_keys((array)$hashes) as $key) {
                if ($origin === 'faq' && strpos((string)$key,'faq:') === 0) unset($hashes[$key]);
                if ($origin === 'dependiente' && strpos((string)$key,'dependiente:') === 0) unset($hashes[$key]);
            }
            ksort($hashes,SORT_STRING);

            $dep_keys = $origin === 'dependiente'
                ? array()
                : array_values(array_unique(array_filter(array_map(
                    array(__CLASS__,'canonical_item_key'),
                    (array)SEO_Solucionador_DB::decode_json($row['dependiente_keys'] ?? '[]',array())
                ))));
            $question_ids = $origin === 'dependiente'
                ? array()
                : (array)SEO_Solucionador_DB::decode_json($row['question_ids'] ?? '[]',array());
            $faq_ids = $origin === 'faq'
                ? array()
                : (array)SEO_Solucionador_DB::decode_json($row['faq_ids'] ?? '[]',array());

            $dep_count = count(array_values(array_filter($dep_keys,array(__CLASS__,'valid_item_key'))));
            $faq_count = count(array_values(array_filter(array_map('absint',$faq_ids))));
            $source_hash = self::source_hash_for_test($category_id,$hashes);
            $reviewed_hash = (string)($row['reviewed_hash'] ?? '');
            $status = $reviewed_hash !== '' && $reviewed_hash !== $source_hash
                ? SEO_Editorial_Service_Contract::NEEDS_UPDATE
                : SEO_Editorial_Service_Contract::normalize((string)($row['editorial_status'] ?? SEO_Editorial_Service_Contract::READY_FOR_REVIEW));

            $ok = $wpdb->update($table,array(
                'question_count'=>$dep_count+$faq_count,
                'dependiente_count'=>$dep_count,
                'faq_count'=>$faq_count,
                'question_ids'=>wp_json_encode(array_values(array_filter(array_map('absint',$question_ids)))),
                'dependiente_keys'=>wp_json_encode(array_values($dep_keys)),
                'faq_ids'=>wp_json_encode(array_values(array_filter(array_map('absint',$faq_ids)))),
                'item_hashes'=>wp_json_encode($hashes),
                'score_avg'=>$origin === 'dependiente' ? 0 : (float)($row['score_avg'] ?? 0),
                'source_hash'=>$source_hash,
                'editorial_status'=>$status,
                'scan_token'=>(string)$token,
                'updated_at'=>current_time('mysql'),
            ),array('category_id'=>$category_id));
            if ($ok !== false) {
                $updated++;
                if (class_exists('SEO_Solucionador_Posts') && method_exists('SEO_Solucionador_Posts','sync_category_post')) {
                    SEO_Solucionador_Posts::sync_category_post($category_id);
                }
            }
        }
        return $updated;
    }

    public static function scan_batch($limit = self::DEFAULT_BATCH,$reset = false) {
        global $wpdb;
        SEO_Solucionador_DB::maybe_install();

        $faq_table = self::faq_table();
        $dependiente_available = class_exists('SEO_Dependiente_Editorial_Knowledge')
            && SEO_Dependiente_Editorial_Knowledge::available();
        $faq_available = SEO_Solucionador_DB::table_exists($faq_table);
        if (!$dependiente_available && !$faq_available) {
            return new WP_Error('solucionador_sources_missing','No están disponibles ni Dependiente ni la tabla de FAQs.');
        }

        $limit = max(25,min(500,absint($limit)));
        $state = $reset ? self::reset_scan() : self::migrate_state(self::state());

        $faq_signature = self::faq_source_signature();
        $dep_signature = self::dependiente_source_signature();

        if (empty($state['faq_complete']) && (string)($state['faq_scan_signature'] ?? '') === '') {
            $state['faq_scan_signature'] = $faq_signature;
        }
        if (empty($state['dependiente_complete']) && (string)($state['dependiente_scan_signature'] ?? '') === '') {
            $state['dependiente_scan_signature'] = $dep_signature;
        }

        // Cada fuente se invalida de forma independiente. Nunca se reinicia el
        // carril largo de Dependiente porque cambie una FAQ.
        if (!empty($state['faq_complete'])
            && (string)($state['faq_source_signature'] ?? '') !== ''
            && (string)$state['faq_source_signature'] !== $faq_signature) {
            self::clear_source_lane('faq',(string)$state['token']);
            $state['faq_cursor'] = 0;
            $state['faq_processed'] = 0;
            $state['faq_with_category'] = 0;
            $state['faq_without_category'] = 0;
            $state['faq_complete'] = false;
            $state['faq_source_signature'] = '';
            $state['faq_scan_signature'] = $faq_signature;
            $state['complete'] = false;
        }

        if (!empty($state['dependiente_complete'])
            && (string)($state['dependiente_source_signature'] ?? '') !== ''
            && (string)$state['dependiente_source_signature'] !== $dep_signature) {
            // Para detectar también modificaciones/desactivaciones de reglas
            // existentes se reconstruye únicamente el carril Dependiente.
            self::clear_source_lane('dependiente',(string)$state['token']);
            $state['dependiente_cursor'] = 0;
            $state['dependiente_run_cursor'] = 0;
            $state['dependiente_semantic_cursor'] = 0;
            $state['dependiente_processed'] = 0;
            $state['dependiente_learned'] = 0;
            $state['dependiente_editorial_eligible'] = 0;
            $state['dependiente_editorial_discarded'] = 0;
            $state['dependiente_with_category'] = 0;
            $state['dependiente_without_category'] = 0;
            $state['dependiente_complete'] = false;
            $state['dependiente_source_signature'] = '';
            $state['dependiente_scan_signature'] = $dep_signature;
            $state['complete'] = false;
        }

        $batch_by_category = array();
        $changed_category_ids = array();

        /**
         * FUENTE DEPENDIENTE
         *
         * Solucionador no conoce las tablas internas: consume el proveedor de
         * conocimiento consolidado de Dependiente. Puede incluir Entrenador y
         * reglas semánticas activas/aprobadas.
         */
        if ($dependiente_available && empty($state['dependiente_complete'])) {
            try {
                $dep_batch = SEO_Dependiente_Editorial_Knowledge::batch(array(
                    'trainer_question'=>absint($state['dependiente_cursor'] ?? 0),
                    'trainer_run'=>absint($state['dependiente_run_cursor'] ?? 0),
                    'semantic'=>absint($state['dependiente_semantic_cursor'] ?? 0),
                ),$limit);

                $dep_stats = (array)($dep_batch['stats'] ?? array());
                $state['dependiente_processed'] += absint($dep_stats['processed'] ?? 0);
                $state['dependiente_learned'] += absint($dep_stats['learned'] ?? 0);
                $state['dependiente_editorial_eligible'] += absint($dep_stats['editorial_eligible'] ?? 0);
                $state['dependiente_editorial_discarded'] += absint($dep_stats['editorial_discarded'] ?? 0);
                $state['dependiente_with_category'] += absint($dep_stats['with_category'] ?? 0);
                $state['dependiente_without_category'] += absint($dep_stats['without_category'] ?? 0);

                $dep_cursor = (array)($dep_batch['cursor'] ?? array());
                $state['dependiente_cursor'] = absint($dep_cursor['trainer_question'] ?? $state['dependiente_cursor']);
                $state['dependiente_run_cursor'] = absint($dep_cursor['trainer_run'] ?? $state['dependiente_run_cursor']);
                $state['dependiente_semantic_cursor'] = absint($dep_cursor['semantic'] ?? $state['dependiente_semantic_cursor']);

                foreach ((array)($dep_batch['items'] ?? array()) as $item) {
                    if (!is_array($item) || empty($item['editorial_candidate'])) continue;
                    $item_key = self::canonical_item_key($item['item_id'] ?? '');
                    $provider_hash = sanitize_text_field((string)($item['source_hash'] ?? ''));
                    if ($item_key === '' || $provider_hash === '') continue;

                    $observed = sanitize_text_field((string)($item['last_seen_at'] ?? ''));
                    if ($observed > (string)($state['last_dependiente_at'] ?? '')) {
                        $state['last_dependiente_at'] = $observed;
                    }
                    $score = max(0,min(1,(float)($item['confidence'] ?? 0)));

                    foreach ((array)($item['category_ids'] ?? array()) as $term_id) {
                        $term_id = absint($term_id);
                        if (!$term_id) continue;
                        if (!isset($batch_by_category[$term_id])) {
                            $batch_by_category[$term_id] = array(
                                'dependiente_keys'=>array(),'question_ids'=>array(),'faq_ids'=>array(),
                                'item_hashes'=>array(),'score_sum'=>0.0,'score_count'=>0,'last'=>''
                            );
                        }

                        $batch_by_category[$term_id]['dependiente_keys'][] = $item_key;
                        $item_hash = $provider_hash;
                        if ((string)($item['dependiente_source'] ?? '') === 'trainer') {
                            $question_id = absint($item['source_id'] ?? 0);
                            $batch_by_category[$term_id]['question_ids'][] = $question_id;

                            // Mantener la misma huella que 0.7.0/0.7.1 para no
                            // marcar como MODIFIED todo el conocimiento trainer
                            // únicamente por introducir el proveedor.
                            $item_hash = self::dependiente_item_hash(array(
                                'id'=>$question_id,
                                'question'=>(string)($item['question'] ?? ''),
                                'question_type'=>(string)($item['question_type'] ?? ''),
                                'source_type'=>(string)($item['source_type_raw'] ?? ''),
                                'source_id'=>absint($item['source_id_raw'] ?? 0),
                                'expected_json'=>(string)($item['expected_json_raw'] ?? ''),
                            ),array(
                                'id'=>absint($item['run_id'] ?? 0),
                                'evaluation_status'=>(string)($item['validation'] ?? ''),
                                'evaluation_score'=>(float)($item['confidence'] ?? 0),
                                'created_at'=>$observed,
                            ),$term_id);
                        }
                        $batch_by_category[$term_id]['item_hashes'][$item_key] = $item_hash;
                        $batch_by_category[$term_id]['score_sum'] += $score;
                        $batch_by_category[$term_id]['score_count']++;
                        if ($observed > $batch_by_category[$term_id]['last']) {
                            $batch_by_category[$term_id]['last'] = $observed;
                        }
                    }
                }

                if (!empty($dep_batch['complete'])) {
                    $end_signature = self::dependiente_source_signature();
                    if ((string)($state['dependiente_scan_signature'] ?? '') !== ''
                        && (string)$state['dependiente_scan_signature'] !== $end_signature) {
                        // La fuente cambió durante el propio recorrido. Repetimos
                        // sólo Dependiente para no perder MODIFIED/RETIRED.
                        self::clear_source_lane('dependiente',(string)$state['token']);
                        $state['dependiente_cursor'] = 0;
                        $state['dependiente_run_cursor'] = 0;
                        $state['dependiente_semantic_cursor'] = 0;
                        $state['dependiente_processed'] = 0;
                        $state['dependiente_learned'] = 0;
                        $state['dependiente_editorial_eligible'] = 0;
                        $state['dependiente_editorial_discarded'] = 0;
                        $state['dependiente_with_category'] = 0;
                        $state['dependiente_without_category'] = 0;
                        $state['dependiente_complete'] = false;
                        $state['dependiente_source_signature'] = '';
                        $state['dependiente_scan_signature'] = $end_signature;
                    } else {
                        $state['dependiente_complete'] = true;
                        $state['dependiente_source_signature'] = $end_signature;
                        $state['dependiente_scan_signature'] = $end_signature;
                    }
                } else {
                    $state['dependiente_complete'] = false;
                }
            } catch (Throwable $e) {
                $state['errors']++;
            }
        } elseif (!$dependiente_available) {
            $state['dependiente_complete'] = true;
        }

        /**
         * FUENTE FAQ
         * Lectura directa de pregunta + respuesta humana activa.
         */
        $faq_cursor = absint($state['faq_cursor'] ?? 0);
        $faq_rows = array();
        if ($faq_available && empty($state['faq_complete'])) {
            $faq_sql = $wpdb->prepare(
                "SELECT id,object_type,object_id,question,answer,created_at,updated_at
                 FROM {$faq_table}
                 WHERE active=1 AND id>%d
                 ORDER BY id ASC
                 LIMIT %d",
                $faq_cursor,$limit
            );
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- SQL preparado.
            $faq_rows = (array)$wpdb->get_results($faq_sql,ARRAY_A);
        }

        $last_faq_cursor = $faq_cursor;
        foreach ($faq_rows as $faq) {
            $faq_id = absint($faq['id'] ?? 0);
            if (!$faq_id) continue;
            $last_faq_cursor = max($last_faq_cursor,$faq_id);
            $state['faq_processed']++;

            $question = trim(wp_strip_all_tags((string)($faq['question'] ?? '')));
            $answer = trim(wp_strip_all_tags((string)($faq['answer'] ?? '')));
            if ($question === '' || $answer === '') continue;

            $category_ids = self::faq_category_ids($faq);
            if (!$category_ids) {
                $state['faq_without_category']++;
                continue;
            }
            $state['faq_with_category']++;
            $faq_at = sanitize_text_field((string)($faq['updated_at'] ?? $faq['created_at'] ?? ''));
            if ($faq_at > (string)($state['last_faq_at'] ?? '')) $state['last_faq_at'] = $faq_at;

            foreach ($category_ids as $term_id) {
                $term_id = absint($term_id);
                if (!isset($batch_by_category[$term_id])) {
                    $batch_by_category[$term_id] = array(
                        'dependiente_keys'=>array(),'question_ids'=>array(),'faq_ids'=>array(),
                        'item_hashes'=>array(),'score_sum'=>0.0,'score_count'=>0,'last'=>''
                    );
                }
                $batch_by_category[$term_id]['faq_ids'][] = $faq_id;
                $batch_by_category[$term_id]['item_hashes']['faq:' . $faq_id] = self::faq_item_hash($faq,$term_id);
                if ($faq_at > $batch_by_category[$term_id]['last']) $batch_by_category[$term_id]['last'] = $faq_at;
            }
        }
        $state['faq_cursor'] = $last_faq_cursor;

        if (!$faq_available || count($faq_rows)<$limit) {
            $state['faq_complete'] = true;
            $end_signature = self::faq_source_signature();
            if ((string)($state['faq_scan_signature'] ?? '') !== ''
                && (string)$state['faq_scan_signature'] !== $end_signature) {
                self::clear_source_lane('faq',(string)$state['token']);
                $state['faq_cursor'] = 0;
                $state['faq_processed'] = 0;
                $state['faq_with_category'] = 0;
                $state['faq_without_category'] = 0;
                $state['faq_complete'] = false;
                $state['faq_source_signature'] = '';
                $state['faq_scan_signature'] = $end_signature;
            } else {
                $state['faq_source_signature'] = $end_signature;
                $state['faq_scan_signature'] = $end_signature;
            }
        }

        foreach ($batch_by_category as $term_id=>$batch) {
            try {
                $saved = self::upsert_batch_dossier(
                    $term_id,
                    (array)$batch['dependiente_keys'],
                    (array)$batch['question_ids'],
                    (array)$batch['faq_ids'],
                    (array)$batch['item_hashes'],
                    (float)$batch['score_sum'],
                    absint($batch['score_count']),
                    (string)$batch['last'],
                    (string)$state['token']
                );
                if ($saved) {
                    $changed_category_ids[] = absint($term_id);
                    if (class_exists('SEO_Solucionador_Posts') && method_exists('SEO_Solucionador_Posts','sync_category_post')) {
                        SEO_Solucionador_Posts::sync_category_post($term_id);
                    }
                }
            } catch (Throwable $e) {
                $state['errors']++;
            }
        }

        // Aliases históricos.
        $state['cursor'] = absint($state['dependiente_cursor'] ?? 0);
        $state['processed'] = absint($state['dependiente_processed'] ?? 0);
        $state['learned'] = absint($state['dependiente_learned'] ?? 0);
        $state['editorial_eligible'] = absint($state['dependiente_editorial_eligible'] ?? 0);
        $state['editorial_discarded'] = absint($state['dependiente_editorial_discarded'] ?? 0);
        $state['learned_with_category'] = absint($state['dependiente_with_category'] ?? 0);
        $state['learned_without_category'] = absint($state['dependiente_without_category'] ?? 0);
        $state['academy_complete'] = !empty($state['dependiente_complete']);
        $state['academy_source_signature'] = (string)($state['dependiente_source_signature'] ?? '');
        $state['last_run_at'] = (string)($state['last_dependiente_at'] ?? '');

        $state['updated_at'] = current_time('mysql');
        $state['complete'] = !empty($state['dependiente_complete']) && !empty($state['faq_complete']);
        if (!empty($state['complete'])) $state['completed_at'] = current_time('mysql');

        update_option(self::STATE_OPTION,$state,false);
        $snapshot = self::snapshot();
        $snapshot['changed_category_ids'] = array_values(array_unique(array_filter(array_map('absint',$changed_category_ids))));
        return $snapshot;
    }

    public static function snapshot() {
        global $wpdb;
        $state = self::migrate_state(self::state());
        $table = SEO_Solucionador_DB::dossiers_table();
        $current_faq_signature = self::faq_source_signature();
        $current_dep_signature = self::dependiente_source_signature();
        $faq_source_changed = !empty($state['faq_complete'])
            && (string)($state['faq_source_signature'] ?? '') !== ''
            && (string)$state['faq_source_signature'] !== $current_faq_signature;
        $dependiente_source_changed = !empty($state['dependiente_complete'])
            && (string)($state['dependiente_source_signature'] ?? '') !== ''
            && (string)$state['dependiente_source_signature'] !== $current_dep_signature;

        self::ensure_all_categories((string)($state['token'] ?? ''));

        $categories_total = wp_count_terms(array('taxonomy'=>'product_cat','hide_empty'=>false));
        $categories_total = is_wp_error($categories_total) ? 0 : absint($categories_total);
        $categories_with = SEO_Solucionador_DB::table_exists($table)
            ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE question_count>0")) : 0;
        $items_in_dossiers = SEO_Solucionador_DB::table_exists($table)
            ? absint($wpdb->get_var("SELECT COALESCE(SUM(question_count),0) FROM {$table}")) : 0;
        $dependiente_in_dossiers = SEO_Solucionador_DB::table_exists($table)
            ? absint($wpdb->get_var("SELECT COALESCE(SUM(dependiente_count),0) FROM {$table}")) : 0;
        $faq_in_dossiers = SEO_Solucionador_DB::table_exists($table)
            ? absint($wpdb->get_var("SELECT COALESCE(SUM(faq_count),0) FROM {$table}")) : 0;

        $only_faq = SEO_Solucionador_DB::table_exists($table)
            ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE faq_count>0 AND dependiente_count=0")) : 0;
        $only_dependiente = SEO_Solucionador_DB::table_exists($table)
            ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE dependiente_count>0 AND faq_count=0")) : 0;
        $both = SEO_Solucionador_DB::table_exists($table)
            ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE dependiente_count>0 AND faq_count>0")) : 0;

        $dependiente_inventory = class_exists('SEO_Dependiente_Editorial_Knowledge')
            ? (array)SEO_Dependiente_Editorial_Knowledge::inventory()
            : array('trainer_questions'=>0,'semantic_rules'=>0,'total'=>0);
        $questions_total = absint($dependiente_inventory['trainer_questions'] ?? 0);
        $semantic_rules_total = absint($dependiente_inventory['semantic_rules'] ?? 0);
        $dependiente_inventory_total = absint($dependiente_inventory['total'] ?? 0);

        $faq_table = self::faq_table();
        if (SEO_Solucionador_DB::table_exists($faq_table)) {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Tabla interna validada; consulta fija sin entrada de usuario.
            $faqs_all_total = absint($wpdb->get_var("SELECT COUNT(*) FROM {$faq_table}"));
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Tabla interna validada; consulta fija sin entrada de usuario.
            $faqs_total = absint($wpdb->get_var("SELECT COUNT(*) FROM {$faq_table} WHERE active=1"));
        } else {
            $faqs_all_total = 0;
            $faqs_total = 0;
        }

        return array(
            'available'=>SEO_Solucionador_DB::table_exists(self::faq_table())
                || (class_exists('SEO_Dependiente_Editorial_Knowledge') && SEO_Dependiente_Editorial_Knowledge::available()),
            'questions_total'=>$questions_total,
            'dependiente_trainer_questions_total'=>$questions_total,
            'dependiente_semantic_rules_total'=>$semantic_rules_total,
            'dependiente_inventory_total'=>$dependiente_inventory_total,
            'faqs_all_total'=>$faqs_all_total,
            'faqs_total'=>$faqs_total,
            'scan_token'=>(string)($state['token'] ?? ''),
            'dependiente_cursor'=>absint($state['dependiente_cursor'] ?? 0),
            'dependiente_run_cursor'=>absint($state['dependiente_run_cursor'] ?? 0),
            'dependiente_semantic_cursor'=>absint($state['dependiente_semantic_cursor'] ?? 0),
            'cursor'=>absint($state['cursor'] ?? 0),
            'faq_cursor'=>absint($state['faq_cursor'] ?? 0),
            'scan_complete'=>!empty($state['complete']),
            'source_changed'=>$faq_source_changed || $dependiente_source_changed,
            'faq_source_changed'=>$faq_source_changed,
            'dependiente_source_changed'=>$dependiente_source_changed,
            'faq_source_signature'=>$current_faq_signature,
            'dependiente_source_signature'=>$current_dep_signature,
            'academy_source_signature'=>$current_dep_signature,
            'dependiente_complete'=>!empty($state['dependiente_complete']),
            'academy_complete'=>!empty($state['dependiente_complete']),
            'faq_complete'=>!empty($state['faq_complete']),
            'dependiente_processed'=>absint($state['dependiente_processed'] ?? 0),
            'dependiente_learned'=>absint($state['dependiente_learned'] ?? 0),
            'dependiente_editorial_eligible'=>absint($state['dependiente_editorial_eligible'] ?? 0),
            'dependiente_editorial_discarded'=>absint($state['dependiente_editorial_discarded'] ?? 0),
            'dependiente_with_category'=>absint($state['dependiente_with_category'] ?? 0),
            'dependiente_without_category'=>absint($state['dependiente_without_category'] ?? 0),
            // aliases históricos
            'processed'=>absint($state['processed'] ?? 0),
            'learned'=>absint($state['learned'] ?? 0),
            'not_learned'=>max(0,absint($state['processed'] ?? 0)-absint($state['learned'] ?? 0)),
            'editorial_eligible'=>absint($state['editorial_eligible'] ?? 0),
            'editorial_discarded'=>absint($state['editorial_discarded'] ?? 0),
            'learned_with_category'=>absint($state['learned_with_category'] ?? 0),
            'learned_without_category'=>absint($state['learned_without_category'] ?? 0),
            'faq_processed'=>absint($state['faq_processed'] ?? 0),
            'faq_with_category'=>absint($state['faq_with_category'] ?? 0),
            'faq_without_category'=>absint($state['faq_without_category'] ?? 0),
            'editorial_policy'=>(string)($state['editorial_policy'] ?? ''),
            'categories_with_knowledge'=>$categories_with,
            'categories_total'=>$categories_total,
            'categories_without_knowledge'=>max(0,$categories_total-$categories_with),
            'categories_only_faq'=>$only_faq,
            'categories_only_dependiente'=>$only_dependiente,
            'categories_faq_dependiente'=>$both,
            'categories_without_information'=>max(0,$categories_total-$categories_with),
            'questions_in_dossiers'=>$items_in_dossiers,
            'dependiente_in_dossiers'=>$dependiente_in_dossiers,
            'faq_in_dossiers'=>$faq_in_dossiers,
            'avg_questions_per_category'=>$categories_with ? round($items_in_dossiers/$categories_with,2) : 0,
            'errors'=>absint($state['errors'] ?? 0),
            'last_run_at'=>(string)($state['last_dependiente_at'] ?? ''),
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

    public static function item_changes($category_id) {
        $dossier = self::get_by_category($category_id);
        if (!$dossier) return array('new'=>array(),'modified'=>array(),'retired'=>array(),'status'=>array());

        $current = SEO_Solucionador_DB::decode_json($dossier['item_hashes'] ?? '{}',array());
        $reviewed = SEO_Solucionador_DB::decode_json($dossier['reviewed_item_hashes'] ?? '{}',array());
        return self::compare_item_hashes_for_test((array)$current,(array)$reviewed);
    }

    public static function changed_item_keys($category_id) {
        $changes = self::item_changes($category_id);
        $keys = array_merge($changes['new'],$changes['modified'],$changes['retired']);
        sort($keys,SORT_STRING);
        return array_values(array_unique($keys));
    }

    public static function changed_item_ids($category_id) {
        $ids = array();
        foreach (self::changed_item_keys($category_id) as $key) {
            if (preg_match('/^dependiente:(?:trainer:)?([0-9]+)$/',$key,$m)) $ids[] = absint($m[1]);
        }
        return array_values(array_unique(array_filter($ids)));
    }

    public static function review_item_keys($category_id,array $item_keys) {
        $category_id = absint($category_id);
        $dossier = self::get_by_category($category_id);
        if (!$dossier) return false;

        $current = self::normalize_item_hashes(
            (array)SEO_Solucionador_DB::decode_json($dossier['item_hashes'] ?? '{}',array())
        );
        $reviewed = self::normalize_item_hashes(
            (array)SEO_Solucionador_DB::decode_json($dossier['reviewed_item_hashes'] ?? '{}',array())
        );

        foreach ($item_keys as $item_key) {
            $item_key = self::canonical_item_key($item_key);
            if ($item_key === '') continue;
            if (isset($current[$item_key])) $reviewed[$item_key] = (string)$current[$item_key];
            else unset($reviewed[$item_key]); // retirada revisada.
        }
        ksort($reviewed,SORT_STRING);

        $remaining = array();
        foreach ((array)$current as $key=>$hash) {
            if (!self::valid_item_key($key)) continue;
            if (!isset($reviewed[$key]) || (string)$reviewed[$key] !== (string)$hash) $remaining[] = $key;
        }
        foreach ((array)$reviewed as $key=>$hash) {
            if (self::valid_item_key($key) && !isset($current[$key])) $remaining[] = $key;
        }
        $remaining = array_values(array_unique($remaining));

        $post_id = class_exists('SEO_Solucionador_Posts')
            ? SEO_Solucionador_Posts::managed_post_id_by_category_public($category_id) : 0;
        $post_status = $post_id ? get_post_status($post_id) : '';
        $editorial_status = $remaining
            ? SEO_Editorial_Service_Contract::NEEDS_UPDATE
            : ($post_status === 'publish'
                ? SEO_Editorial_Service_Contract::PUBLISHED
                : ($post_id ? SEO_Editorial_Service_Contract::DRAFT : SEO_Editorial_Service_Contract::READY_FOR_REVIEW));

        return SEO_Solucionador_DB::update_dossier_editorial($category_id,array(
            'reviewed_hash'=>$remaining ? (string)($dossier['reviewed_hash'] ?? '') : (string)($dossier['source_hash'] ?? ''),
            'reviewed_item_hashes'=>wp_json_encode($reviewed),
            'editorial_status'=>$editorial_status,
            'reviewed_at'=>current_time('mysql'),
        ));
    }

    public static function review_item_ids($category_id,array $question_ids) {
        $keys = array();
        foreach (array_values(array_unique(array_filter(array_map('absint',$question_ids)))) as $question_id) {
            $keys[] = 'dependiente:trainer:' . $question_id;
        }
        return self::review_item_keys($category_id,$keys);
    }

    public static function item_editorial_states($category_id) {
        $dossier = self::get_by_category($category_id);
        if (!$dossier) return array();
        $states = SEO_Solucionador_DB::decode_json($dossier['editorial_item_states'] ?? '{}',array());
        $out = array();
        foreach ((array)$states as $key=>$state) {
            $key = self::canonical_item_key($key);
            $state = sanitize_key((string)$state);
            if ($key !== '' && in_array($state,array('pending','use','discard'),true)) {
                $out[$key] = $state;
            }
        }
        ksort($out,SORT_STRING);
        return $out;
    }

    public static function save_item_editorial_states($category_id,array $states) {
        $category_id = absint($category_id);
        $dossier = self::get_by_category($category_id);
        if (!$dossier) return false;

        $saved = self::item_editorial_states($category_id);
        foreach ($states as $key=>$state) {
            $key = self::canonical_item_key($key);
            $state = sanitize_key((string)$state);
            if ($key === '') continue;
            if (!in_array($state,array('pending','use','discard'),true)) $state = 'pending';
            $saved[$key] = $state;
        }
        ksort($saved,SORT_STRING);

        return SEO_Solucionador_DB::update_dossier_editorial($category_id,array(
            'editorial_item_states'=>wp_json_encode($saved),
            'updated_at'=>current_time('mysql'),
        ));
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

        $snapshot = array();
        foreach (self::question_details($category_id) as $item) {
            $key = self::canonical_item_key($item['item_key'] ?? '');
            if ($key === '') continue;
            $snapshot[$key] = array(
                'item_key'=>$key,
                'origin'=>sanitize_key((string)($item['origin'] ?? '')),
                'source_id'=>$item['source_id'] ?? null,
                'faq_id'=>absint($item['faq_id'] ?? 0) ?: null,
                'question_id'=>absint($item['question_id'] ?? 0) ?: null,
                'question'=>sanitize_text_field((string)($item['question'] ?? '')),
                'answer'=>trim(wp_strip_all_tags((string)($item['answer'] ?? ''))),
                'dependiente_source'=>sanitize_key((string)($item['dependiente_source'] ?? '')),
                'question_type'=>sanitize_key((string)($item['question_type'] ?? '')),
                'lesson_key'=>sanitize_key((string)($item['lesson_key'] ?? '')),
                'evaluation_status'=>sanitize_key((string)($item['evaluation_status'] ?? '')),
                'evaluation_score'=>(float)($item['evaluation_score'] ?? 0),
                'product_id'=>absint($item['product_id'] ?? 0) ?: null,
                'observed_at'=>sanitize_text_field((string)($item['observed_at'] ?? '')),
                'source_hash'=>sanitize_text_field((string)($item['source_hash'] ?? '')),
                'editorial_choice'=>sanitize_key((string)($item['editorial_choice'] ?? 'pending')),
            );
        }
        ksort($snapshot,SORT_STRING);

        return SEO_Solucionador_DB::update_dossier_editorial($category_id, array(
            'reviewed_hash'=>(string)$dossier['source_hash'],
            'reviewed_item_hashes'=>wp_json_encode(self::normalize_item_hashes(
                (array)SEO_Solucionador_DB::decode_json($dossier['item_hashes'] ?? '{}',array())
            )),
            'reviewed_items_snapshot'=>wp_json_encode($snapshot),
            'editorial_status'=>$editorial_status,
            'reviewed_at'=>current_time('mysql'),
        ));
    }

    public static function retired_item_details($category_id) {
        $category_id = absint($category_id);
        $dossier = self::get_by_category($category_id);
        if (!$dossier) return array();

        $changes = self::item_changes($category_id);
        $retired = array_fill_keys((array)($changes['retired'] ?? array()),true);
        if (!$retired) return array();

        $snapshot = SEO_Solucionador_DB::decode_json($dossier['reviewed_items_snapshot'] ?? '{}',array());
        $out = array();
        foreach ($retired as $key=>$unused) {
            unset($unused);
            $key = self::canonical_item_key($key);
            if ($key === '') continue;
            $row = isset($snapshot[$key]) && is_array($snapshot[$key])
                ? $snapshot[$key]
                : array('item_key'=>$key,'question'=>'Elemento retirado de la fuente','answer'=>'');
            $row['item_key'] = $key;
            $row['origin'] = sanitize_key((string)($row['origin'] ?? (strpos($key,'faq:')===0 ? 'faq' : 'dependiente')));
            $row['editorial_state'] = 'retired';
            $out[] = $row;
        }
        return $out;
    }

    public static function question_details($category_id) {
        global $wpdb;
        $category_id = absint($category_id);
        $dossier = self::get_by_category($category_id);
        if (!$dossier) return array();

        $out = array();

        // Dependiente: contenido real/aprobado expuesto por su proveedor.
        $dependiente_keys = array();
        foreach ((array)SEO_Solucionador_DB::decode_json($dossier['dependiente_keys'] ?? '[]',array()) as $key) {
            $key = self::canonical_item_key($key);
            if ($key !== '' && strpos($key,'dependiente:') === 0) $dependiente_keys[] = $key;
        }
        if (!$dependiente_keys) {
            // Compatibilidad con dossiers previos a dependiente_keys.
            foreach ((array)SEO_Solucionador_DB::decode_json($dossier['question_ids'] ?? '[]',array()) as $question_id) {
                $question_id = absint($question_id);
                if ($question_id) $dependiente_keys[] = 'dependiente:trainer:' . $question_id;
            }
        }
        $dependiente_keys = array_values(array_unique($dependiente_keys));

        if ($dependiente_keys && class_exists('SEO_Dependiente_Editorial_Knowledge')) {
            foreach (SEO_Dependiente_Editorial_Knowledge::details($dependiente_keys) as $item) {
                if (!is_array($item) || empty($item['editorial_candidate'])) continue;
                $category_ids = array_map('absint',(array)($item['category_ids'] ?? array()));
                if (!in_array($category_id,$category_ids,true)) continue;

                $item_key = self::canonical_item_key($item['item_id'] ?? '');
                if ($item_key === '') continue;
                $dep_source = sanitize_key((string)($item['dependiente_source'] ?? ''));
                $source_id = absint($item['source_id'] ?? 0);

                $out[] = array(
                    'origin'=>'dependiente',
                    'item_key'=>$item_key,
                    'question_id'=>$dep_source === 'trainer' ? $source_id : 0,
                    'faq_id'=>0,
                    'question'=>sanitize_text_field((string)($item['question'] ?? '')),
                    'answer'=>trim(wp_strip_all_tags((string)($item['answer'] ?? ''))),
                    'question_type'=>sanitize_key((string)($item['question_type'] ?? '')),
                    'editorial_value'=>'dependiente_consolidated_knowledge',
                    'lesson_key'=>sanitize_key((string)($item['lesson_key'] ?? '')),
                    'source_type'=>'dependiente',
                    'dependiente_source'=>$dep_source,
                    'source_id'=>$source_id ?: null,
                    'source_key'=>$item_key,
                    'source_hash'=>sanitize_text_field((string)($item['source_hash'] ?? '')),
                    'product_id'=>absint($item['product_id'] ?? 0) ?: null,
                    'expected'=>(array)($item['expected'] ?? array()),
                    'evaluation_status'=>sanitize_key((string)($item['validation'] ?? '')),
                    'evaluation_score'=>max(0,min(1,(float)($item['confidence'] ?? 0))),
                    'evaluation'=>(array)($item['evaluation'] ?? array()),
                    'top_results'=>array_values(array_slice((array)($item['top_results'] ?? array()),0,12)),
                    'response_meta'=>(array)($item['response_meta'] ?? array()),
                    'search_strategy'=>sanitize_key((string)($item['search_strategy'] ?? '')),
                    'run_id'=>absint($item['run_id'] ?? 0) ?: null,
                    'first_seen_at'=>sanitize_text_field((string)($item['first_seen_at'] ?? '')),
                    'observed_at'=>sanitize_text_field((string)($item['last_seen_at'] ?? '')),
                    'category_id'=>$category_id,
                );
            }
        }

        // FAQ: pregunta + respuesta humana original.
        $faq_ids = array_values(array_unique(array_filter(array_map(
            'absint',(array)SEO_Solucionador_DB::decode_json($dossier['faq_ids'] ?? '[]',array())
        ))));
        if ($faq_ids && SEO_Solucionador_DB::table_exists(self::faq_table())) {
            $faq_ids = array_slice($faq_ids,0,500);
            $faq_table = self::faq_table();
            $placeholders = implode(',',array_fill(0,count($faq_ids),'%d'));
            $sql = "SELECT id,object_type,object_id,question,answer,created_at,updated_at
                    FROM {$faq_table}
                    WHERE active=1 AND id IN ({$placeholders})
                    ORDER BY sort_order ASC,id ASC";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Tabla interna y lista de placeholders %d generada localmente; IDs enlazados mediante $wpdb->prepare().
            $prepared_sql = $wpdb->prepare($sql,$faq_ids);
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- $prepared_sql es el resultado de $wpdb->prepare().
            $faq_rows = (array)$wpdb->get_results($prepared_sql,ARRAY_A);
            foreach ($faq_rows as $row) {
                $faq_id = absint($row['id'] ?? 0);
                $category_ids = self::faq_category_ids($row);
                if (!$faq_id || !in_array($category_id,$category_ids,true)) continue;
                $source_hash = self::faq_item_hash($row,$category_id);
                $object_type = absint($row['object_type'] ?? 0);
                $object_id = absint($row['object_id'] ?? 0);
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
                    'dependiente_source'=>'',
                    'source_id'=>$faq_id,
                    'source_key'=>'faq:' . $faq_id,
                    'source_hash'=>$source_hash,
                    'object_type'=>$object_type,
                    'object_id'=>$object_id,
                    'product_id'=>$object_type === 3 ? $object_id : null,
                    'category_id'=>$category_id,
                    'evaluation_status'=>'manual_source',
                    'evaluation_score'=>1.0,
                    'evaluation'=>array(),'top_results'=>array(),'response_meta'=>array(),'search_strategy'=>'',
                    'first_seen_at'=>sanitize_text_field((string)($row['created_at'] ?? '')),
                    'observed_at'=>sanitize_text_field((string)($row['updated_at'] ?? $row['created_at'] ?? '')),
                );
            }
        }

        $changes = self::item_changes($category_id);
        $choices = self::item_editorial_states($category_id);
        foreach ($out as &$item) {
            $key = self::canonical_item_key($item['item_key'] ?? '');
            if ($key !== '') $item['item_key'] = $key;
            $item['editorial_state'] = (string)($changes['status'][$key] ?? 'unchanged');
            $item['editorial_choice'] = (string)($choices[$key] ?? 'pending');
        }
        unset($item);

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
        $normalized_answer = trim(wp_strip_all_tags((string)($detail['answer'] ?? '')));
        if ($normalized_answer !== '') return $normalized_answer;

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

    private static function signals_from_dossier(array $row) {
        $out = array();
        $term_id = absint($row['category_id'] ?? 0);
        $name = trim((string)($row['category_name'] ?? ''));
        if (!$term_id || $name === '') return $out;

        $dependiente_count = absint($row['dependiente_count'] ?? 0);
        $faq_count = absint($row['faq_count'] ?? 0);
        $base_meta = array(
            'proposal_role'=>'origin',
            'editorial_family'=>'dependiente_qa_basic',
            'dossier_id'=>absint($row['id'] ?? 0),
            'category_id'=>$term_id,
            'category_name'=>$name,
            'source_hash'=>(string)($row['source_hash'] ?? ''),
            'last_validated_at'=>(string)($row['last_validated_at'] ?? ''),
        );

        if ($faq_count > 0) {
            $out[] = array(
                'dossier_id'=>absint($row['id'] ?? 0),
                'source_type'=>'faq','proposal_role'=>'origin',
                'source_id'=>'category:' . $term_id,
                'signal_type'=>'category_editorial_source',
                'entity_type'=>'product_cat','entity_id'=>$term_id,
                'category_id'=>$term_id,'category_name'=>$name,
                'source_text'=>'FAQs humanas disponibles para ' . $name . '.',
                'hints'=>array('intent'=>'dependiente_qa_basic','action'=>'resolver','object'=>$name,'category_id'=>$term_id),
                'occurrences'=>$faq_count,'confidence'=>1.0,'evidence_score'=>1.0,
                'observed_at'=>(string)($row['last_validated_at'] ?? current_time('mysql')),
                'source_meta'=>array_merge($base_meta,array('origin'=>'faq','item_count'=>$faq_count)),
            );
        }

        if ($dependiente_count > 0) {
            $out[] = array(
                'dossier_id'=>absint($row['id'] ?? 0),
                'source_type'=>'dependiente','proposal_role'=>'origin',
                'source_id'=>'category:' . $term_id,
                'signal_type'=>'category_editorial_source',
                'entity_type'=>'product_cat','entity_id'=>$term_id,
                'category_id'=>$term_id,'category_name'=>$name,
                'source_text'=>'Conocimiento consolidado de Dependiente disponible para ' . $name . '.',
                'hints'=>array('intent'=>'dependiente_qa_basic','action'=>'resolver','object'=>$name,'category_id'=>$term_id),
                'occurrences'=>$dependiente_count,
                'confidence'=>max(0.50,min(1.0,(float)($row['score_avg'] ?? 0.80))),
                'evidence_score'=>1.0,
                'observed_at'=>(string)($row['last_validated_at'] ?? current_time('mysql')),
                'source_meta'=>array_merge($base_meta,array('origin'=>'dependiente','item_count'=>$dependiente_count)),
            );
        }
        return $out;
    }

    public static function signals_for_category($category_id) {
        $row = self::get_by_category(absint($category_id));
        return $row ? self::signals_from_dossier((array)$row) : array();
    }

    public static function signals($limit = 100,$after_id = 0) {
        $out = array();
        foreach (self::rows($limit,$after_id) as $row) {
            $out = array_merge($out,self::signals_from_dossier((array)$row));
        }
        return $out;
    }

}

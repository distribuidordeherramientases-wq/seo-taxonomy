<?php

defined('ABSPATH') || exit;

/**
 * Lingüista: formación del Intérprete.
 *
 * Se inicia siempre de forma manual. Después, el Gestor de procesos le entrega
 * ventanas de trabajo mediante el worker/supervisor ya existente. No consulta
 * servicios externos durante una búsqueda de cliente: el aprendizaje se deja
 * preparado en la memoria local del Intérprete.
 */
final class SEO_Dependiente_Linguista {
    const VERSION = '0.2.0';
    const STATE_OPTION = 'seo_dependiente_linguista_state';
    const GRAMMAR_OPTION = 'seo_dependiente_interprete_grammar';
    const MORPHOLOGY_OPTION = 'seo_dependiente_interprete_morphology';

    private static $booted = false;
    private static $curriculum = null;
    private static $grammar = null;

    public static function init() {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_filter('seo_process_supervisor_has_pending_work', array(__CLASS__, 'supervisor_has_pending_work'), 20, 1);
        add_filter('seo_process_supervisor_manager_targets', array(__CLASS__, 'supervisor_manager_targets'), 20, 3);
        add_filter('seo_processes_monitor_items', array(__CLASS__, 'processes_monitor_items'), 20, 1);
    }

    public static function lessons() {
        if (is_array(self::$curriculum)) {
            return self::$curriculum;
        }
        $file = __DIR__ . '/data/curriculum-v1.php';
        $rows = is_readable($file) ? require $file : array();
        self::$curriculum = is_array($rows) ? array_values($rows) : array();
        return self::$curriculum;
    }

    public static function grammar() {
        if (is_array(self::$grammar)) {
            return self::$grammar;
        }
        $file = __DIR__ . '/data/es-grammar-v2.php';
        $data = is_readable($file) ? require $file : array();
        self::$grammar = is_array($data) ? $data : array();
        return self::$grammar;
    }

    public static function default_state() {
        $lessons = self::lessons();
        $first = isset($lessons[0]) ? $lessons[0] : array();
        return array(
            'version'            => self::VERSION,
            'enabled'            => 0,
            'status'             => 'stopped',
            'run_id'             => '',
            'lesson_index'       => 0,
            'current_lesson'     => sanitize_key((string) ($first['key'] ?? '')),
            'lesson_phase'       => '',
            'cursor'             => 0,
            'lesson_processed'   => 0,
            'lesson_learned'     => 0,
            'lesson_rejected'    => 0,
            'lesson_total'       => 0,
            'processed_total'    => 0,
            'learned_total'      => 0,
            'rejected_total'     => 0,
            'exam_pass'          => 0,
            'exam_fail'          => 0,
            'batch_size'         => 60,
            'started_at'         => 0,
            'heartbeat_at'       => 0,
            'last_activity_at'   => 0,
            'last_duration'      => 0.0,
            'last_processed'     => 0,
            'next_delay'         => 1,
            'not_before'         => 0,
            'last_message'       => '',
            'last_error'         => '',
            'lesson_results'     => array(),
        );
    }

    public static function state() {
        $stored = get_option(self::STATE_OPTION, array());
        $state = wp_parse_args(is_array($stored) ? $stored : array(), self::default_state());
        $state['lesson_results'] = isset($state['lesson_results']) && is_array($state['lesson_results']) ? $state['lesson_results'] : array();
        return $state;
    }

    private static function save_state($changes) {
        $state = self::state();
        foreach ((array) $changes as $key => $value) {
            $state[$key] = $value;
        }
        update_option(self::STATE_OPTION, $state, false);
        return $state;
    }

    public static function is_pending() {
        $state = self::state();
        return !empty($state['enabled'])
            && 'running' === sanitize_key((string) ($state['status'] ?? ''))
            && absint($state['lesson_index'] ?? 0) < count(self::lessons());
    }

    public static function process_control_start() {
        if (!class_exists('SEO_Dependiente_Interprete_DB')) {
            return new WP_Error('linguista_db_missing', 'La memoria del Intérprete no está disponible.');
        }
        SEO_Dependiente_Interprete_DB::install();

        $current = self::state();
        if (self::is_pending()) {
            return array('started' => true, 'message' => 'Lingüista ya está en ejecución.');
        }

        $lessons = self::lessons();
        if (!$lessons) {
            return new WP_Error('linguista_curriculum_missing', 'No se ha podido cargar el plan de formación de Lingüista.');
        }

        $run_id = gmdate('YmdHis') . '-' . wp_generate_password(6, false, false);
        $first = $lessons[0];
        $state = self::default_state();
        $state['enabled'] = 1;
        $state['status'] = 'running';
        $state['run_id'] = $run_id;
        $state['lesson_index'] = 0;
        $state['current_lesson'] = sanitize_key((string) $first['key']);
        $state['lesson_total'] = self::lesson_total($state['current_lesson']);
        $state['batch_size'] = self::control_config()['initial_batch'];
        $state['started_at'] = time();
        $state['heartbeat_at'] = time();
        $state['last_activity_at'] = time();
        $state['last_message'] = 'Formación Lingüista iniciada manualmente. Lección 1 preparada.';
        update_option(self::STATE_OPTION, $state, false);

        if (function_exists('seo_process_supervisor_managed_update')) {
            seo_process_supervisor_managed_update('linguista', array(
                'name' => 'Lingüista',
                'pending' => 1,
                'healthy' => 1,
                'last_checked' => time(),
                'last_result' => 'started',
                'last_error' => '',
                'detail' => 'Formación del Intérprete iniciada manualmente; el gestor continuará por lotes.',
            ));
        }
        if (function_exists('seo_process_supervisor_nudge')) {
            seo_process_supervisor_nudge(0, 'linguista');
        }

        return array('started' => true, 'message' => 'Lingüista iniciado. El Gestor de procesos continuará la formación del Intérprete.');
    }

    public static function process_monitor_payload() {
        $state = self::state();
        $lessons = self::lessons();
        $index = absint($state['lesson_index'] ?? 0);
        $lesson = isset($lessons[$index]) ? $lessons[$index] : array();
        $stats = class_exists('SEO_Dependiente_Interprete_DB') ? SEO_Dependiente_Interprete_DB::stats() : array();
        return array(
            'version' => self::VERSION,
            'running' => self::is_pending(),
            'state'   => $state,
            'current' => $lesson,
            'lessons' => $lessons,
            'stats'   => $stats,
        );
    }

    public static function process_manager_slice($max_runtime = 20, $source = 'process_manager') {
        if (!self::is_pending()) {
            return false;
        }
        $state = self::state();
        $not_before = absint($state['not_before'] ?? 0);
        if ($not_before && $not_before > time()) {
            return false;
        }

        $max_runtime = max(5, min(50, absint($max_runtime)));
        $started = microtime(true);
        $did_work = false;
        $processed_window = 0;
        $learned_window = 0;
        $rejected_window = 0;

        try {
            while ((microtime(true) - $started) < max(2, $max_runtime - 1)) {
                $state = self::state();
                if (!self::is_pending()) {
                    break;
                }

                $batch_started = microtime(true);
                $result = self::run_current_lesson_batch($state);
                $duration = max(0.001, microtime(true) - $batch_started);
                $did_work = $did_work || !empty($result['processed']) || !empty($result['done']);
                $processed = absint($result['processed'] ?? 0);
                $learned = absint($result['learned'] ?? 0);
                $rejected = absint($result['rejected'] ?? 0);
                $processed_window += $processed;
                $learned_window += $learned;
                $rejected_window += $rejected;

                $state = self::save_state(array(
                    'heartbeat_at'     => time(),
                    'last_activity_at' => time(),
                    'last_duration'    => $duration,
                    'last_processed'   => $processed,
                    'lesson_processed' => absint($state['lesson_processed'] ?? 0) + $processed,
                    'lesson_learned'   => absint($state['lesson_learned'] ?? 0) + $learned,
                    'lesson_rejected'  => absint($state['lesson_rejected'] ?? 0) + $rejected,
                    'processed_total'  => absint($state['processed_total'] ?? 0) + $processed,
                    'learned_total'    => absint($state['learned_total'] ?? 0) + $learned,
                    'rejected_total'   => absint($state['rejected_total'] ?? 0) + $rejected,
                    'last_error'       => '',
                    'last_message'     => sanitize_text_field((string) ($result['message'] ?? 'Lote Lingüista completado.')),
                ));

                if (!empty($result['state_changes']) && is_array($result['state_changes'])) {
                    $state = self::save_state($result['state_changes']);
                }

                if (!empty($result['done'])) {
                    self::finish_current_lesson($state, $result);
                    continue;
                }

                self::adapt_speed($duration);
                break;
            }
        } catch (Throwable $e) {
            self::save_state(array(
                'status' => 'error',
                'enabled' => 0,
                'heartbeat_at' => time(),
                'last_activity_at' => time(),
                'last_error' => sanitize_text_field($e->getMessage()),
                'last_message' => 'Lingüista se ha detenido por un error.',
            ));
            if (function_exists('seo_process_supervisor_managed_update')) {
                seo_process_supervisor_managed_update('linguista', array(
                    'name' => 'Lingüista', 'pending' => 0, 'healthy' => 0, 'last_checked' => time(),
                    'last_result' => 'failed', 'last_error' => sanitize_text_field($e->getMessage()),
                    'detail' => 'La formación del Intérprete se ha detenido por un error.',
                ));
            }
            return false;
        }

        $state = self::state();
        if (self::is_pending()) {
            $delay = max(1, absint($state['next_delay'] ?? 1));
            self::save_state(array('not_before' => time() + $delay));
            if (function_exists('seo_process_supervisor_nudge')) {
                seo_process_supervisor_nudge($delay, 'linguista');
            }
        }

        if (function_exists('seo_process_supervisor_managed_update')) {
            seo_process_supervisor_managed_update('linguista', array(
                'name' => 'Lingüista',
                'pending' => self::is_pending() ? 1 : 0,
                'healthy' => 1,
                'last_checked' => time(),
                'last_attempt_at' => time(),
                'last_result' => $did_work ? 'processed' : 'waiting',
                'last_error' => '',
                'detail' => self::is_pending()
                    ? 'Ventana Lingüista completada; el worker continuará la formación.'
                    : 'Formación Lingüista terminada.',
            ));
        }

        return $did_work || $processed_window > 0 || $learned_window > 0 || $rejected_window > 0;
    }

    private static function run_current_lesson_batch($state) {
        $key = sanitize_key((string) ($state['current_lesson'] ?? ''));
        switch ($key) {
            case 'ling_l1_catalog_language':
                return self::lesson_catalog_language($state);
            case 'ling_l2_actions':
                return self::lesson_actions($state);
            case 'ling_l3_synonyms':
                return self::lesson_synonyms($state);
            case 'ling_l4_context':
                return self::lesson_context($state);
            case 'ling_l5_ambiguity':
                return self::lesson_validate_ambiguity($state);
            case 'ling_l6_natural_language':
                return self::lesson_natural_language($state);
            case 'ling_l7_intent':
                return self::lesson_intent($state);
            case 'ling_l8_exam':
                return self::lesson_exam($state);
        }
        return array('done' => true, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Lección sin trabajo pendiente.');
    }

    /** L1: nombres canónicos procedentes de Vocabulary, categorías y etiquetas. */
    private static function lesson_catalog_language($state) {
        global $wpdb;
        $batch = self::batch_size($state);
        $phase = sanitize_key((string) ($state['lesson_phase'] ?? '')) ?: 'vocabulary';
        $cursor = absint($state['cursor'] ?? 0);
        $rows = array();

        if ('vocabulary' === $phase) {
            $table = $wpdb->prefix . 'seo_vocabulary';
            if (!self::table_exists($table)) {
                return array('done' => false, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Vocabulary no disponible; se continúa con categorías.', 'state_changes' => array('lesson_phase' => 'categories', 'cursor' => 0));
            }
            $rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT id,semantic_group,slug,label FROM {$table} WHERE active=1 AND id>%d AND semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo') ORDER BY id ASC LIMIT %d",
                $cursor,
                $batch
            ), ARRAY_A);
            if (!$rows) {
                return array('done' => false, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Vocabulary leído. Pasando a categorías.', 'state_changes' => array('lesson_phase' => 'categories', 'cursor' => 0));
            }
            $learned = 0;
            foreach ($rows as $row) {
                $label = trim((string) ($row['label'] ?? ''));
                $slug = trim((string) ($row['slug'] ?? ''));
                if ('' === $label) {
                    $label = str_replace(array('-', '_'), ' ', $slug);
                }
                if ('' === $label) {
                    continue;
                }
                $id = SEO_Dependiente_Interprete_DB::upsert_row(array(
                    'expression' => $label,
                    'canonical_term' => $label,
                    'target_search' => $label,
                    'relation_type' => 'noun',
                    'semantic_group' => sanitize_key((string) ($row['semantic_group'] ?? '')),
                    'vocabulary_id' => absint($row['id']),
                    'confidence' => 1.0,
                    'priority' => 1,
                    'source' => 'linguista_catalog',
                    'lesson_key' => 'ling_l1_catalog_language',
                    'validated' => 1,
                    'active' => 1,
                ));
                if ($id) {
                    SEO_Dependiente_Interprete_DB::add_evidence($id, 'vocabulary', absint($row['id']), hash('sha256', wp_json_encode($row)), 'ling_l1_catalog_language', 1.0);
                    $learned++;
                }
                $slug_expression = trim(str_replace(array('-', '_'), ' ', $slug));
                if ($slug_expression && SEO_Dependiente_Interprete_DB::normalize($slug_expression) !== SEO_Dependiente_Interprete_DB::normalize($label)) {
                    $alias_id = SEO_Dependiente_Interprete_DB::upsert_row(array(
                        'expression' => $slug_expression,
                        'canonical_term' => $label,
                        'target_search' => $label,
                        'relation_type' => 'catalog_variant',
                        'semantic_group' => sanitize_key((string) ($row['semantic_group'] ?? '')),
                        'vocabulary_id' => absint($row['id']),
                        'confidence' => 0.99,
                        'priority' => 2,
                        'source' => 'linguista_catalog',
                        'lesson_key' => 'ling_l1_catalog_language',
                        'validated' => 1,
                        'active' => 1,
                    ));
                    if ($alias_id) {
                        SEO_Dependiente_Interprete_DB::add_evidence($alias_id, 'vocabulary_slug', absint($row['id']), hash('sha256', $slug), 'ling_l1_catalog_language', 0.99);
                        $learned++;
                    }
                }
            }
            $last = end($rows);
            return array('done' => false, 'processed' => count($rows), 'learned' => $learned, 'rejected' => 0, 'message' => 'Vocabulary procesado.', 'state_changes' => array('cursor' => absint($last['id'] ?? $cursor)));
        }

        if ('categories' === $phase) {
            $rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT tt.term_taxonomy_id AS id,t.name,t.slug FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE tt.taxonomy='product_cat' AND tt.count>0 AND tt.term_taxonomy_id>%d ORDER BY tt.term_taxonomy_id ASC LIMIT %d",
                $cursor,
                $batch
            ), ARRAY_A);
            if (!$rows) {
                return array('done' => false, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Categorías leídas. Pasando a etiquetas.', 'state_changes' => array('lesson_phase' => 'tags', 'cursor' => 0));
            }
            $learned = 0;
            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ('' === $name) {
                    continue;
                }
                $id = SEO_Dependiente_Interprete_DB::upsert_row(array(
                    'expression' => $name,
                    'canonical_term' => $name,
                    'target_search' => $name,
                    'relation_type' => 'category_name',
                    'semantic_group' => 'category',
                    'confidence' => 1.0,
                    'priority' => 1,
                    'source' => 'linguista_catalog',
                    'lesson_key' => 'ling_l1_catalog_language',
                    'validated' => 1,
                    'active' => 1,
                ));
                if ($id) {
                    SEO_Dependiente_Interprete_DB::add_evidence($id, 'product_cat', absint($row['id']), hash('sha256', wp_json_encode($row)), 'ling_l1_catalog_language', 1.0);
                    $learned++;
                }
            }
            $last = end($rows);
            return array('done' => false, 'processed' => count($rows), 'learned' => $learned, 'rejected' => 0, 'message' => 'Categorías procesadas.', 'state_changes' => array('cursor' => absint($last['id'] ?? $cursor)));
        }

        if ('tags' === $phase) {
            $rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT tt.term_taxonomy_id AS id,t.name,t.slug FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE tt.taxonomy='product_tag' AND tt.count>0 AND tt.term_taxonomy_id>%d ORDER BY tt.term_taxonomy_id ASC LIMIT %d",
                $cursor,
                $batch
            ), ARRAY_A);
            if (!$rows) {
                return array('done' => true, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Lenguaje canónico del catálogo aprendido.');
            }
            $learned = 0;
            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                if ('' === $name) {
                    continue;
                }
                $id = SEO_Dependiente_Interprete_DB::upsert_row(array(
                    'expression' => $name,
                    'canonical_term' => $name,
                    'target_search' => $name,
                    'relation_type' => 'tag_name',
                    'semantic_group' => 'tag',
                    'confidence' => 0.98,
                    'priority' => 2,
                    'source' => 'linguista_catalog',
                    'lesson_key' => 'ling_l1_catalog_language',
                    'validated' => 1,
                    'active' => 1,
                ));
                if ($id) {
                    SEO_Dependiente_Interprete_DB::add_evidence($id, 'product_tag', absint($row['id']), hash('sha256', wp_json_encode($row)), 'ling_l1_catalog_language', 0.98);
                    $learned++;
                }
            }
            $last = end($rows);
            return array('done' => false, 'processed' => count($rows), 'learned' => $learned, 'rejected' => 0, 'message' => 'Etiquetas procesadas.', 'state_changes' => array('cursor' => absint($last['id'] ?? $cursor)));
        }

        return array('done' => true, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Lección de catálogo completada.');
    }

    /** L2: extrae infinitivos de texto real y los relaciona con el tipo asignado. */
    private static function lesson_actions($state) {
        global $wpdb;
        $batch = self::batch_size($state);
        $cursor = absint($state['cursor'] ?? 0);
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ID,post_title,post_excerpt,post_content FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND ID>%d ORDER BY ID ASC LIMIT %d",
            $cursor,
            $batch
        ), ARRAY_A);
        if (!$rows) {
            return array('done' => true, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Acciones del catálogo extraídas.');
        }

        $ids = array_values(array_map('absint', wp_list_pluck($rows, 'ID')));
        $targets = self::product_targets($ids);
        $grammar = self::grammar();
        $stop_verbs = array_map(array('SEO_Dependiente_Interprete_DB', 'normalize'), (array) ($grammar['stop_verbs'] ?? array()));
        $learned = 0;
        $rejected = 0;

        foreach ($rows as $row) {
            $product_id = absint($row['ID'] ?? 0);
            if (!$product_id || empty($targets[$product_id]['target'])) {
                $rejected++;
                continue;
            }
            $target = (string) $targets[$product_id]['target'];
            $semantic_group = sanitize_key((string) ($targets[$product_id]['semantic_group'] ?? ''));
            $vocabulary_id = absint($targets[$product_id]['vocabulary_id'] ?? 0);
            $contexts = (array) ($targets[$product_id]['contexts'] ?? array());
            $text = (string) ($row['post_title'] ?? '') . ' ' . (string) ($row['post_excerpt'] ?? '') . ' ' . wp_strip_all_tags((string) ($row['post_content'] ?? ''));
            if (strlen($text) > 18000) {
                $text = substr($text, 0, 18000);
            }
            $verbs = self::extract_infinitive_verbs($text, $stop_verbs);
            foreach ($verbs as $verb) {
                $lexicon_id = SEO_Dependiente_Interprete_DB::upsert_row(array(
                    'expression' => $verb,
                    'canonical_term' => $target,
                    'target_search' => $target,
                    'relation_type' => 'verb_to_tool',
                    'semantic_group' => $semantic_group,
                    'vocabulary_id' => $vocabulary_id,
                    'context_terms' => $contexts,
                    'context_required' => 0,
                    'confidence' => 0.76,
                    'priority' => 4,
                    'source' => 'linguista_catalog_actions',
                    'lesson_key' => 'ling_l2_actions',
                    'validated' => 0,
                    'active' => 0,
                ));
                if ($lexicon_id) {
                    SEO_Dependiente_Interprete_DB::add_evidence(
                        $lexicon_id,
                        'product',
                        $product_id,
                        hash('sha256', $verb . '|' . $target . '|' . strip_tags((string) ($row['post_excerpt'] ?? ''))),
                        'ling_l2_actions',
                        0.76,
                        $contexts
                    );
                    $learned++;
                }
            }
        }
        $last = end($rows);
        return array(
            'done' => false,
            'processed' => count($rows),
            'learned' => $learned,
            'rejected' => $rejected,
            'message' => 'Acciones extraídas de productos y relacionadas con Vocabulary.',
            'state_changes' => array('cursor' => absint($last['ID'] ?? $cursor)),
        );
    }

    /** L3: solo equivalencias explícitas; no deduce sinónimos por proximidad. */
    private static function lesson_synonyms($state) {
        global $wpdb;
        $batch = self::batch_size($state);
        $phase = sanitize_key((string) ($state['lesson_phase'] ?? '')) ?: 'search_synonyms';
        $cursor = absint($state['cursor'] ?? 0);

        if ('search_synonyms' === $phase) {
            $pairs = self::existing_synonym_pairs();
            if ($cursor >= count($pairs)) {
                return array('done' => false, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Sinónimos configurados importados. Pasando a variantes explícitas de Vocabulary.', 'state_changes' => array('lesson_phase' => 'vocabulary_aliases', 'cursor' => 0));
            }
            $slice = array_slice($pairs, $cursor, $batch);
            $learned = 0;
            $rejected = 0;
            foreach ($slice as $index => $pair) {
                $target_row = SEO_Dependiente_Interprete_DB::find_target_for_expression($pair['canonical']);
                if (!$target_row) {
                    $target_row = SEO_Dependiente_Interprete_DB::find_target_for_expression($pair['expression']);
                }
                if (!$target_row) {
                    $rejected++;
                    continue;
                }
                $target = (string) ($target_row['target_search'] ?? $pair['canonical']);
                $canonical = (string) ($target_row['canonical_term'] ?? $target);
                $lexicon_id = SEO_Dependiente_Interprete_DB::upsert_row(array(
                    'expression' => $pair['expression'],
                    'canonical_term' => $canonical,
                    'target_search' => $target,
                    'relation_type' => 'synonym',
                    'semantic_group' => sanitize_key((string) ($target_row['semantic_group'] ?? '')),
                    'vocabulary_id' => absint($target_row['vocabulary_id'] ?? 0),
                    'confidence' => 0.95,
                    'priority' => 3,
                    'source' => 'linguista_explicit_synonym',
                    'lesson_key' => 'ling_l3_synonyms',
                    'validated' => 0,
                    'active' => 0,
                ));
                if ($lexicon_id) {
                    SEO_Dependiente_Interprete_DB::add_evidence($lexicon_id, 'search_synonym', $cursor + $index + 1, hash('sha256', wp_json_encode($pair)), 'ling_l3_synonyms', 0.95);
                    $learned++;
                }
            }
            return array('done' => false, 'processed' => count($slice), 'learned' => $learned, 'rejected' => $rejected, 'message' => 'Sinónimos explícitos procesados.', 'state_changes' => array('cursor' => $cursor + count($slice)));
        }

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        if (!self::table_exists($vocabulary)) {
            return array('done' => true, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Lección de sinónimos completada.');
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id,semantic_group,slug,label FROM {$vocabulary} WHERE active=1 AND id>%d ORDER BY id ASC LIMIT %d",
            $cursor,
            $batch
        ), ARRAY_A);
        if (!$rows) {
            return array('done' => true, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Sinónimos y variantes explícitas aprendidos.');
        }
        $learned = 0;
        foreach ($rows as $row) {
            $aliases = self::explicit_label_aliases((string) ($row['label'] ?? ''));
            if (count($aliases) < 2) {
                continue;
            }
            $canonical = array_shift($aliases);
            foreach ($aliases as $alias) {
                $lexicon_id = SEO_Dependiente_Interprete_DB::upsert_row(array(
                    'expression' => $alias,
                    'canonical_term' => $canonical,
                    'target_search' => $canonical,
                    'relation_type' => 'synonym',
                    'semantic_group' => sanitize_key((string) ($row['semantic_group'] ?? '')),
                    'vocabulary_id' => absint($row['id']),
                    'confidence' => 0.88,
                    'priority' => 3,
                    'source' => 'linguista_explicit_synonym',
                    'lesson_key' => 'ling_l3_synonyms',
                    'validated' => 0,
                    'active' => 0,
                ));
                if ($lexicon_id) {
                    SEO_Dependiente_Interprete_DB::add_evidence($lexicon_id, 'vocabulary_alias', absint($row['id']), hash('sha256', (string) $row['label']), 'ling_l3_synonyms', 0.88);
                    $learned++;
                }
            }
        }
        $last = end($rows);
        return array('done' => false, 'processed' => count($rows), 'learned' => $learned, 'rejected' => 0, 'message' => 'Variantes explícitas de Vocabulary procesadas.', 'state_changes' => array('cursor' => absint($last['id'] ?? $cursor)));
    }

    /** L4: reúne contexto real de los productos que aportaron evidencia. */
    private static function lesson_context($state) {
        global $wpdb;
        $batch = min(80, self::batch_size($state));
        $cursor = absint($state['cursor'] ?? 0);
        $table = SEO_Dependiente_Interprete_DB::table();
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id>%d AND lesson_key IN ('ling_l2_actions','ling_l3_synonyms') ORDER BY id ASC LIMIT %d",
            $cursor,
            $batch
        ), ARRAY_A);
        if (!$rows) {
            return array('done' => true, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Contexto añadido a las expresiones aprendidas.');
        }

        $learned = 0;
        foreach ($rows as $row) {
            $id = absint($row['id'] ?? 0);
            $contexts = self::contexts_for_lexicon($id);
            $existing = json_decode((string) ($row['context_terms'] ?? ''), true);
            $contexts = array_values(array_unique(array_merge(is_array($existing) ? $existing : array(), $contexts)));
            $ambiguous = SEO_Dependiente_Interprete_DB::canonical_count_for_expression((string) ($row['normalized_expression'] ?? '')) > 1;
            if (SEO_Dependiente_Interprete_DB::update_row($id, array(
                'context_terms' => $contexts,
                'context_required' => $ambiguous ? 1 : !empty($row['context_required']),
            ))) {
                $learned++;
            }
        }
        $last = end($rows);
        return array('done' => false, 'processed' => count($rows), 'learned' => $learned, 'rejected' => 0, 'message' => 'Contexto de catálogo consolidado.', 'state_changes' => array('cursor' => absint($last['id'] ?? $cursor)));
    }

    /** L5: activa únicamente candidatos con evidencia suficiente. */
    private static function lesson_validate_ambiguity($state) {
        global $wpdb;
        $batch = min(100, self::batch_size($state));
        $cursor = absint($state['cursor'] ?? 0);
        $table = SEO_Dependiente_Interprete_DB::table();
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id>%d AND lesson_key IN ('ling_l2_actions','ling_l3_synonyms') ORDER BY id ASC LIMIT %d",
            $cursor,
            $batch
        ), ARRAY_A);
        if (!$rows) {
            SEO_Dependiente_Interprete_DB::resolve_vocabulary_ids();
            return array('done' => true, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Candidatos validados; solo quedan activas las relaciones con evidencia suficiente.');
        }

        $learned = 0;
        $rejected = 0;
        foreach ($rows as $row) {
            $evidence = max(1, absint($row['evidence_count'] ?? 1));
            $relation = sanitize_key((string) ($row['relation_type'] ?? ''));
            $source = sanitize_key((string) ($row['source'] ?? ''));
            $threshold = 'verb_to_tool' === $relation ? 3 : 1;
            if ('linguista_explicit_synonym' !== $source && 'synonym' === $relation) {
                $threshold = 2;
            }
            $ambiguous = SEO_Dependiente_Interprete_DB::canonical_count_for_expression((string) ($row['normalized_expression'] ?? '')) > 1;
            $contexts = json_decode((string) ($row['context_terms'] ?? ''), true);
            $has_context = is_array($contexts) && !empty($contexts);
            $eligible = $evidence >= $threshold && (!$ambiguous || $has_context);

            if ($eligible) {
                $confidence = min(0.98, max((float) ($row['confidence'] ?? 0.7), 0.72 + (min(10, $evidence) * 0.025)));
                SEO_Dependiente_Interprete_DB::update_row(absint($row['id']), array(
                    'active' => 1,
                    'validated' => 1,
                    'context_required' => $ambiguous ? 1 : !empty($row['context_required']),
                    'confidence' => $confidence,
                ));
                $learned++;
            } else {
                // No se desactiva una regla que ya estuviera activa de antes.
                if (empty($row['active'])) {
                    $rejected++;
                }
            }
        }
        $last = end($rows);
        return array('done' => false, 'processed' => count($rows), 'learned' => $learned, 'rejected' => $rejected, 'message' => 'Evidencias y ambigüedades validadas.', 'state_changes' => array('cursor' => absint($last['id'] ?? $cursor)));
    }

    /**
     * L6: instala gramática castellana y compila un diccionario morfológico
     * específico a partir de los verbos que el propio catálogo ya ha enseñado.
     */
    private static function lesson_natural_language($state) {
        global $wpdb;
        $phase = sanitize_key((string) ($state['lesson_phase'] ?? '')) ?: 'grammar';
        $cursor = absint($state['cursor'] ?? 0);
        $grammar = self::grammar();

        if ('grammar' === $phase) {
            $stored = get_option(self::GRAMMAR_OPTION, array());
            $stored = is_array($stored) ? $stored : array();
            $stored['version'] = '2';
            $stored['natural_language'] = 1;
            $stored['fuzzy_distance'] = 2;
            $stored['fuzzy_min_length'] = 5;
            $stored['filler_phrases'] = array_values(array_unique((array) ($grammar['filler_phrases'] ?? array())));
            $stored['stop_verbs'] = array_values(array_unique((array) ($grammar['stop_verbs'] ?? array())));
            $stored['stopword_groups'] = isset($grammar['stopword_groups']) && is_array($grammar['stopword_groups']) ? $grammar['stopword_groups'] : array();
            $stored['semantic_stopwords'] = array_values(array_unique((array) ($grammar['semantic_stopwords'] ?? array())));
            $stored['irregular_forms'] = isset($grammar['irregular_forms']) && is_array($grammar['irregular_forms']) ? $grammar['irregular_forms'] : array();
            update_option(self::GRAMMAR_OPTION, $stored, false);

            // El diccionario morfológico arranca también con los verbos
            // funcionales de la conversación (necesitar, buscar, querer...).
            // Así pueden identificarse y filtrarse aunque no sean acciones de
            // una herramienta concreta aprendida desde el catálogo.
            $base_forms = array();
            $base_lemmas = array();
            $base_ambiguous = array();
            foreach ((array) ($grammar['stop_verbs'] ?? array()) as $stop_lemma) {
                $stop_lemma = SEO_Dependiente_Interprete_DB::normalize($stop_lemma);
                if (!preg_match('/(?:ar|er|ir)$/', $stop_lemma)) {
                    continue;
                }
                $base_lemmas[$stop_lemma] = 1;
                foreach (self::regular_verb_forms($stop_lemma) as $form) {
                    if (isset($base_ambiguous[$form])) {
                        continue;
                    }
                    if (!isset($base_forms[$form])) {
                        $base_forms[$form] = $stop_lemma;
                    } elseif ($base_forms[$form] !== $stop_lemma) {
                        unset($base_forms[$form]);
                        $base_ambiguous[$form] = 1;
                    }
                }
            }
            foreach ((array) ($grammar['irregular_forms'] ?? array()) as $surface => $lemma) {
                $surface = SEO_Dependiente_Interprete_DB::normalize($surface);
                $lemma = SEO_Dependiente_Interprete_DB::normalize($lemma);
                if ('' === $surface || '' === $lemma || !isset($base_lemmas[$lemma]) || isset($base_ambiguous[$surface])) {
                    continue;
                }
                if (!isset($base_forms[$surface]) || $base_forms[$surface] === $lemma) {
                    $base_forms[$surface] = $lemma;
                } else {
                    unset($base_forms[$surface]);
                    $base_ambiguous[$surface] = 1;
                }
            }

            update_option(self::MORPHOLOGY_OPTION, array(
                'version' => '2',
                'built_at' => 0,
                'forms' => $base_forms,
                'lemmas' => $base_lemmas,
                'ambiguous' => $base_ambiguous,
                'form_count' => count($base_forms),
                'lemma_count' => count($base_lemmas),
            ), false);

            return array(
                'done' => false,
                'processed' => 1,
                'learned' => 1,
                'rejected' => 0,
                'message' => 'Gramática castellana instalada. Compilando formas verbales del catálogo.',
                'state_changes' => array('lesson_phase' => 'morphology', 'cursor' => 0),
            );
        }

        if ('morphology' === $phase) {
            $table = SEO_Dependiente_Interprete_DB::table();
            if (!self::table_exists($table)) {
                return array('done' => true, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Gramática instalada; no hay léxico para compilar morfología.');
            }
            $batch = min(150, self::batch_size($state));
            $rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT id,normalized_expression FROM {$table} WHERE active=1 AND relation_type='verb_to_tool' AND id>%d ORDER BY id ASC LIMIT %d",
                $cursor,
                $batch
            ), ARRAY_A);
            if (!$rows) {
                return array(
                    'done' => false,
                    'processed' => 0,
                    'learned' => 0,
                    'rejected' => 0,
                    'message' => 'Formas regulares compiladas. Añadiendo irregulares conocidas.',
                    'state_changes' => array('lesson_phase' => 'irregulars', 'cursor' => 0),
                );
            }

            $compiled = get_option(self::MORPHOLOGY_OPTION, array());
            $compiled = is_array($compiled) ? $compiled : array();
            $forms = isset($compiled['forms']) && is_array($compiled['forms']) ? $compiled['forms'] : array();
            $lemmas = isset($compiled['lemmas']) && is_array($compiled['lemmas']) ? $compiled['lemmas'] : array();
            $ambiguous = isset($compiled['ambiguous']) && is_array($compiled['ambiguous']) ? $compiled['ambiguous'] : array();
            $learned = 0;
            $rejected = 0;

            foreach ($rows as $row) {
                $lemma = SEO_Dependiente_Interprete_DB::normalize((string) ($row['normalized_expression'] ?? ''));
                if (!preg_match('/(?:ar|er|ir)$/', $lemma)) {
                    $rejected++;
                    continue;
                }
                $lemmas[$lemma] = 1;
                foreach (self::regular_verb_forms($lemma) as $form) {
                    if (isset($ambiguous[$form])) {
                        continue;
                    }
                    if (!isset($forms[$form])) {
                        $forms[$form] = $lemma;
                        $learned++;
                    } elseif ($forms[$form] !== $lemma) {
                        unset($forms[$form]);
                        $ambiguous[$form] = 1;
                        $rejected++;
                    }
                }
            }

            $compiled['forms'] = $forms;
            $compiled['lemmas'] = $lemmas;
            $compiled['ambiguous'] = $ambiguous;
            $compiled['form_count'] = count($forms);
            $compiled['lemma_count'] = count($lemmas);
            update_option(self::MORPHOLOGY_OPTION, $compiled, false);
            $last = end($rows);
            return array(
                'done' => false,
                'processed' => count($rows),
                'learned' => $learned,
                'rejected' => $rejected,
                'message' => 'Familias verbales regulares compiladas desde las acciones aprendidas.',
                'state_changes' => array('cursor' => absint($last['id'] ?? $cursor)),
            );
        }

        $compiled = get_option(self::MORPHOLOGY_OPTION, array());
        $compiled = is_array($compiled) ? $compiled : array();
        $forms = isset($compiled['forms']) && is_array($compiled['forms']) ? $compiled['forms'] : array();
        $lemmas = isset($compiled['lemmas']) && is_array($compiled['lemmas']) ? $compiled['lemmas'] : array();
        $ambiguous = isset($compiled['ambiguous']) && is_array($compiled['ambiguous']) ? $compiled['ambiguous'] : array();
        $learned = 0;
        $rejected = 0;
        foreach ((array) ($grammar['irregular_forms'] ?? array()) as $surface => $lemma) {
            $surface = SEO_Dependiente_Interprete_DB::normalize($surface);
            $lemma = SEO_Dependiente_Interprete_DB::normalize($lemma);
            if ('' === $surface || '' === $lemma || !isset($lemmas[$lemma]) || isset($ambiguous[$surface])) {
                continue;
            }
            if (!isset($forms[$surface]) || $forms[$surface] === $lemma) {
                if (!isset($forms[$surface])) {
                    $learned++;
                }
                $forms[$surface] = $lemma;
            } else {
                unset($forms[$surface]);
                $ambiguous[$surface] = 1;
                $rejected++;
            }
        }
        $compiled['forms'] = $forms;
        $compiled['lemmas'] = $lemmas;
        $compiled['ambiguous'] = $ambiguous;
        $compiled['built_at'] = time();
        $compiled['form_count'] = count($forms);
        $compiled['lemma_count'] = count($lemmas);
        update_option(self::MORPHOLOGY_OPTION, $compiled, false);

        return array(
            'done' => true,
            'processed' => max(1, count((array) ($grammar['irregular_forms'] ?? array()))),
            'learned' => $learned,
            'rejected' => $rejected,
            'message' => 'Gramática, formas verbales regulares/irregulares y tolerancia ortográfica preparadas.',
        );
    }

    /** L7: activa clasificación ligera de intención. */
    private static function lesson_intent($state) {
        $stored = get_option(self::GRAMMAR_OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $grammar = self::grammar();
        $stored['version'] = '1';
        $stored['intent_detection'] = 1;
        $stored['intents'] = isset($grammar['intents']) && is_array($grammar['intents']) ? $grammar['intents'] : array();
        update_option(self::GRAMMAR_OPTION, $stored, false);
        return array('done' => true, 'processed' => 1, 'learned' => 1, 'rejected' => 0, 'message' => 'Detección de intención activada.');
    }

    /** L8: examen cerrado sobre reglas activas, sin crear nuevo conocimiento. */
    private static function lesson_exam($state) {
        global $wpdb;
        $batch = min(100, self::batch_size($state));
        $cursor = absint($state['cursor'] ?? 0);
        $table = SEO_Dependiente_Interprete_DB::table();
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id,expression,target_search,relation_type FROM {$table} WHERE active=1 AND language='es' AND id>%d ORDER BY id ASC LIMIT %d",
            $cursor,
            $batch
        ), ARRAY_A);
        if (!$rows) {
            $pass = absint($state['exam_pass'] ?? 0);
            $fail = absint($state['exam_fail'] ?? 0);
            $total = $pass + $fail;
            $rate = $total > 0 ? round(($pass / $total) * 100, 2) : 0;
            return array('done' => true, 'processed' => 0, 'learned' => 0, 'rejected' => 0, 'message' => 'Examen Lingüista terminado: ' . number_format_i18n($rate, 2) . '% de aciertos.', 'state_changes' => array('exam_rate' => $rate));
        }

        $pass = 0;
        $fail = 0;
        foreach ($rows as $row) {
            $expression = trim((string) ($row['expression'] ?? ''));
            $target = trim((string) ($row['target_search'] ?? ''));
            if ('' === $expression || '' === $target || !class_exists('SEO_Dependiente_Interprete')) {
                $fail++;
                continue;
            }
            $relation = sanitize_key((string) ($row['relation_type'] ?? ''));
            $question = in_array($relation, array('verb_to_tool','phrase_to_tool'), true)
                ? 'Necesito una herramienta para ' . $expression
                : 'Estoy buscando ' . $expression;
            $interpretation = SEO_Dependiente_Interprete::interpret($question);
            $actual = SEO_Dependiente_Interprete_DB::normalize((string) ($interpretation['search_query'] ?? ''));
            $expected = SEO_Dependiente_Interprete_DB::normalize($target);
            if ('' !== $expected && (false !== strpos(' ' . $actual . ' ', ' ' . $expected . ' ') || false !== strpos($actual, $expected))) {
                $pass++;
            } else {
                $fail++;
            }
        }
        $last = end($rows);
        return array(
            'done' => false,
            'processed' => count($rows),
            'learned' => 0,
            'rejected' => $fail,
            'message' => 'Examen conversacional en curso.',
            'state_changes' => array(
                'cursor' => absint($last['id'] ?? $cursor),
                'exam_pass' => absint($state['exam_pass'] ?? 0) + $pass,
                'exam_fail' => absint($state['exam_fail'] ?? 0) + $fail,
            ),
        );
    }

    private static function finish_current_lesson($state, $result) {
        $lessons = self::lessons();
        $index = absint($state['lesson_index'] ?? 0);
        $lesson = isset($lessons[$index]) ? $lessons[$index] : array();
        $key = sanitize_key((string) ($lesson['key'] ?? $state['current_lesson'] ?? ''));
        $results = isset($state['lesson_results']) && is_array($state['lesson_results']) ? $state['lesson_results'] : array();
        // process_manager_slice() ya incorporó el último lote al estado antes
        // de cerrar la lección; no se vuelve a sumar aquí.
        $results[$key] = array(
            'completed_at' => time(),
            'processed' => absint($state['lesson_processed'] ?? 0),
            'learned' => absint($state['lesson_learned'] ?? 0),
            'rejected' => absint($state['lesson_rejected'] ?? 0),
            'message' => sanitize_text_field((string) ($result['message'] ?? 'Lección completada.')),
        );
        if ('ling_l8_exam' === $key) {
            $fresh = self::state();
            $results[$key]['pass'] = absint($fresh['exam_pass'] ?? 0);
            $results[$key]['fail'] = absint($fresh['exam_fail'] ?? 0);
            $results[$key]['rate'] = (float) ($fresh['exam_rate'] ?? 0);
        }

        $next_index = $index + 1;
        if ($next_index >= count($lessons)) {
            self::save_state(array(
                'enabled' => 0,
                'status' => 'completed',
                'lesson_index' => count($lessons),
                'current_lesson' => '',
                'lesson_phase' => '',
                'cursor' => 0,
                'lesson_processed' => 0,
                'lesson_learned' => 0,
                'lesson_rejected' => 0,
                'lesson_total' => 0,
                'lesson_results' => $results,
                'heartbeat_at' => time(),
                'last_activity_at' => time(),
                'not_before' => 0,
                'last_message' => sanitize_text_field((string) ($result['message'] ?? 'Formación Lingüista completada.')),
            ));
            return;
        }

        $next = $lessons[$next_index];
        self::save_state(array(
            'lesson_index' => $next_index,
            'current_lesson' => sanitize_key((string) $next['key']),
            'lesson_phase' => '',
            'cursor' => 0,
            'lesson_processed' => 0,
            'lesson_learned' => 0,
            'lesson_rejected' => 0,
            'lesson_total' => self::lesson_total((string) $next['key']),
            'lesson_results' => $results,
            'heartbeat_at' => time(),
            'last_activity_at' => time(),
            'last_message' => 'Lección ' . absint($next['order'] ?? ($next_index + 1)) . ' preparada: ' . sanitize_text_field((string) ($next['title'] ?? '')) . '.',
        ));
    }

    private static function lesson_total($key) {
        global $wpdb;
        $key = sanitize_key((string) $key);
        if ('ling_l1_catalog_language' === $key) {
            $total = 0;
            $vocabulary = $wpdb->prefix . 'seo_vocabulary';
            if (self::table_exists($vocabulary)) {
                $total += absint($wpdb->get_var("SELECT COUNT(*) FROM {$vocabulary} WHERE active=1 AND semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')"));
            }
            $total += absint($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_cat' AND count>0"));
            $total += absint($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_tag' AND count>0"));
            return $total;
        }
        if ('ling_l2_actions' === $key) {
            return absint($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'"));
        }
        if ('ling_l3_synonyms' === $key) {
            $vocabulary = $wpdb->prefix . 'seo_vocabulary';
            return count(self::existing_synonym_pairs()) + (self::table_exists($vocabulary) ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$vocabulary} WHERE active=1")) : 0);
        }
        if (in_array($key, array('ling_l4_context','ling_l5_ambiguity'), true)) {
            $table = SEO_Dependiente_Interprete_DB::table();
            return self::table_exists($table) ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE lesson_key IN ('ling_l2_actions','ling_l3_synonyms')")) : 0;
        }
        if ('ling_l6_natural_language' === $key) {
            $table = SEO_Dependiente_Interprete_DB::table();
            $verbs = self::table_exists($table)
                ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active=1 AND relation_type='verb_to_tool'"))
                : 0;
            return max(2, $verbs + 2);
        }
        if ('ling_l7_intent' === $key) {
            return 1;
        }
        if ('ling_l8_exam' === $key) {
            $table = SEO_Dependiente_Interprete_DB::table();
            return self::table_exists($table) ? absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active=1 AND language='es'")) : 0;
        }
        return 0;
    }

    private static function product_targets($ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
        if (!$ids) {
            return array();
        }
        $in = implode(',', $ids);
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        $out = array();

        if (self::table_exists($vocabulary) && self::table_exists($objects)) {
            $rows = (array) $wpdb->get_results(
                "SELECT ov.object_id,v.id vocabulary_id,v.semantic_group,v.label,v.slug
                 FROM {$objects} ov
                 JOIN {$vocabulary} v ON v.id=ov.vocabulary_id AND v.active=1
                 WHERE ov.object_type='product' AND ov.status=1 AND ov.object_id IN ({$in})
                   AND v.semantic_group IN ('tipo','subtipo','aplicacion','rol')
                 ORDER BY ov.object_id ASC, FIELD(v.semantic_group,'tipo','subtipo','aplicacion','rol'), v.id ASC",
                ARRAY_A
            );
            foreach ($rows as $row) {
                $product_id = absint($row['object_id'] ?? 0);
                $label = trim((string) ($row['label'] ?? ''));
                if (!$product_id || '' === $label) {
                    continue;
                }
                if (!isset($out[$product_id])) {
                    $out[$product_id] = array(
                        'target' => $label,
                        'semantic_group' => sanitize_key((string) ($row['semantic_group'] ?? '')),
                        'vocabulary_id' => absint($row['vocabulary_id'] ?? 0),
                        'contexts' => array(),
                    );
                }
                $out[$product_id]['contexts'][] = $label;
            }
        }

        $missing = array_values(array_diff($ids, array_keys($out)));
        if ($missing) {
            $missing_in = implode(',', array_map('absint', $missing));
            $rows = (array) $wpdb->get_results(
                "SELECT tr.object_id,t.name
                 FROM {$wpdb->term_relationships} tr
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
                 JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
                 WHERE tr.object_id IN ({$missing_in})
                 ORDER BY tr.object_id ASC,tt.parent DESC,tt.term_taxonomy_id ASC",
                ARRAY_A
            );
            foreach ($rows as $row) {
                $product_id = absint($row['object_id'] ?? 0);
                $name = trim((string) ($row['name'] ?? ''));
                if (!$product_id || '' === $name) {
                    continue;
                }
                if (!isset($out[$product_id])) {
                    $out[$product_id] = array('target' => $name, 'semantic_group' => 'category', 'vocabulary_id' => 0, 'contexts' => array());
                }
                $out[$product_id]['contexts'][] = $name;
            }
        }

        foreach ($out as $product_id => $data) {
            $contexts = array();
            foreach ((array) ($data['contexts'] ?? array()) as $context) {
                $normalized = SEO_Dependiente_Interprete_DB::normalize($context);
                if ($normalized) {
                    $contexts[] = $normalized;
                    foreach (explode(' ', $normalized) as $token) {
                        if (strlen($token) >= 4) {
                            $contexts[] = $token;
                        }
                    }
                }
            }
            $out[$product_id]['contexts'] = array_slice(array_values(array_unique($contexts)), 0, 16);
        }
        return $out;
    }

    private static function extract_infinitive_verbs($text, $stop_verbs) {
        $text = function_exists('remove_accents') ? remove_accents((string) $text) : (string) $text;
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        preg_match_all('/\b[a-z]{4,18}(?:ar|er|ir)\b/u', $text, $matches);
        $verbs = array_values(array_unique(array_map('sanitize_key', (array) ($matches[0] ?? array()))));
        $stop = array_flip((array) $stop_verbs);
        $out = array();
        foreach ($verbs as $verb) {
            if (strlen($verb) < 5 || isset($stop[$verb])) {
                continue;
            }
            $out[] = $verb;
        }
        return array_slice($out, 0, 20);
    }

    /**
     * Genera las formas regulares más habituales que puede escribir un cliente.
     * Las tildes se normalizan igual que en el buscador, por lo que una forma
     * escrita con o sin tilde termina en la misma clave.
     */
    private static function regular_verb_forms($lemma) {
        $lemma = SEO_Dependiente_Interprete_DB::normalize($lemma);
        if (!preg_match('/^(.*)(ar|er|ir)$/', $lemma, $m)) {
            return array();
        }
        $stem = (string) $m[1];
        $ending = (string) $m[2];
        if (strlen($stem) < 2) {
            return array($lemma);
        }

        $forms = array($lemma);
        if ('ar' === $ending) {
            $suffixes = array(
                'o','as','a','amos','ais','an',
                'e','aste','o','asteis','aron',
                'aba','abas','abamos','abais','aban',
                'e','es','emos','eis','en',
                'ando','ado',
            );
        } elseif ('er' === $ending) {
            $suffixes = array(
                'o','es','e','emos','eis','en',
                'i','iste','io','imos','isteis','ieron',
                'ia','ias','iamos','iais','ian',
                'a','as','amos','ais','an',
                'iendo','ido',
            );
        } else {
            $suffixes = array(
                'o','es','e','imos','is','en',
                'i','iste','io','imos','isteis','ieron',
                'ia','ias','iamos','iais','ian',
                'a','as','amos','ais','an',
                'iendo','ido',
            );
        }
        foreach ($suffixes as $suffix) {
            $forms[] = $stem . $suffix;
        }
        foreach (array('e','as','a','emos','eis','an','ia','ias','iamos','iais','ian') as $suffix) {
            $forms[] = $lemma . $suffix;
        }
        return array_values(array_unique(array_filter(array_map(array('SEO_Dependiente_Interprete_DB', 'normalize'), $forms))));
    }

    private static function existing_synonym_pairs() {
        $raw = '';
        if (function_exists('seo_search_get_option')) {
            $raw = (string) seo_search_get_option('synonyms', '');
        } else {
            $options = get_option('seo_search_options', array());
            $raw = is_array($options) ? (string) ($options['synonyms'] ?? '') : '';
        }
        $pairs = array();
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        foreach ((array) $lines as $line) {
            if (false === strpos($line, '=')) {
                continue;
            }
            list($left, $right) = array_map('trim', explode('=', $line, 2));
            if ('' === $left || '' === $right) {
                continue;
            }
            $alts = array_values(array_unique(array_filter(array_map('trim', explode(',', $right)))));
            foreach ($alts as $alt) {
                if ('' !== $alt && SEO_Dependiente_Interprete_DB::normalize($alt) !== SEO_Dependiente_Interprete_DB::normalize($left)) {
                    $pairs[] = array('canonical' => $left, 'expression' => $alt);
                }
            }
        }
        return $pairs;
    }

    private static function explicit_label_aliases($label) {
        $label = trim(wp_strip_all_tags((string) $label));
        if ('' === $label) {
            return array();
        }
        $parts = preg_split('/\s*(?:\/|\||;|,|\s+o\s+)\s*/iu', $label);
        $parts = array_values(array_unique(array_filter(array_map('trim', (array) $parts))));
        if (count($parts) < 2) {
            if (preg_match('/^(.+?)\s*\(([^\)]+)\)\s*$/u', $label, $m)) {
                $parts = array_values(array_unique(array_filter(array(trim($m[1]), trim($m[2])))));
            }
        }
        return array_values(array_filter($parts, static function ($value) {
            $value = (string) $value;
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            return $length >= 3 && $length <= 80;
        }));
    }

    private static function contexts_for_lexicon($lexicon_id) {
        global $wpdb;
        $product_ids = SEO_Dependiente_Interprete_DB::evidence_product_ids($lexicon_id, 180);
        if (!$product_ids) {
            return array();
        }
        $in = implode(',', array_map('absint', $product_ids));
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        $contexts = array();
        if (self::table_exists($vocabulary) && self::table_exists($objects)) {
            $rows = (array) $wpdb->get_results(
                "SELECT v.label,COUNT(*) evidence_count
                 FROM {$objects} ov JOIN {$vocabulary} v ON v.id=ov.vocabulary_id AND v.active=1
                 WHERE ov.object_type='product' AND ov.status=1 AND ov.object_id IN ({$in})
                   AND v.semantic_group IN ('tipo','subtipo','aplicacion','plataforma','rol')
                 GROUP BY v.id,v.label ORDER BY evidence_count DESC,v.id ASC LIMIT 16",
                ARRAY_A
            );
            foreach ($rows as $row) {
                $label = SEO_Dependiente_Interprete_DB::normalize((string) ($row['label'] ?? ''));
                if ($label) {
                    $contexts[] = $label;
                    foreach (explode(' ', $label) as $token) {
                        if (strlen($token) >= 4) {
                            $contexts[] = $token;
                        }
                    }
                }
            }
        }
        return array_slice(array_values(array_unique($contexts)), 0, 20);
    }

    private static function table_exists($table) {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    private static function control_config() {
        $defaults = array(
            'min_batch' => 20,
            'initial_batch' => 60,
            'max_batch' => 180,
            'fast_seconds' => 1.5,
            'slow_seconds' => 5.0,
            'very_slow_seconds' => 10.0,
            'growth_factor' => 1.35,
            'slowdown_factor' => 0.55,
            'normal_delay_seconds' => 1,
            'slow_delay_seconds' => 2,
            'critical_delay_seconds' => 5,
        );
        if (function_exists('seo_processes_control_for')) {
            $stored = seo_processes_control_for('linguista');
            if (is_array($stored) && $stored) {
                return wp_parse_args($stored, $defaults);
            }
        }
        return $defaults;
    }

    private static function batch_size($state) {
        $control = self::control_config();
        return max(absint($control['min_batch']), min(absint($control['max_batch']), absint($state['batch_size'] ?? $control['initial_batch'])));
    }

    private static function adapt_speed($duration) {
        $state = self::state();
        $control = self::control_config();
        $current = self::batch_size($state);
        $next = $current;
        $delay = absint($control['normal_delay_seconds']);
        if ($duration >= (float) $control['very_slow_seconds']) {
            $next = max(absint($control['min_batch']), (int) floor($current * (float) $control['slowdown_factor']));
            $delay = absint($control['critical_delay_seconds']);
        } elseif ($duration >= (float) $control['slow_seconds']) {
            $next = max(absint($control['min_batch']), (int) floor($current * (float) $control['slowdown_factor']));
            $delay = absint($control['slow_delay_seconds']);
        } elseif ($duration <= (float) $control['fast_seconds']) {
            $next = min(absint($control['max_batch']), max($current + 1, (int) ceil($current * (float) $control['growth_factor'])));
        }
        self::save_state(array('batch_size' => $next, 'next_delay' => max(1, $delay)));
    }

    public static function supervisor_has_pending_work($pending) {
        if ($pending) {
            return true;
        }
        $settings = function_exists('seo_process_supervisor_settings') ? seo_process_supervisor_settings() : array('linguista' => 1);
        return !empty($settings['linguista']) && self::is_pending();
    }

    public static function supervisor_manager_targets($targets, $settings, $source) {
        $targets = is_array($targets) ? $targets : array();
        if (empty($settings['linguista']) || !self::is_pending()) {
            return $targets;
        }
        $state = self::state();
        $due = absint($state['not_before'] ?? 0);
        if ($due && $due > time()) {
            if (function_exists('seo_process_supervisor_managed_update')) {
                seo_process_supervisor_managed_update('linguista', array(
                    'name' => 'Lingüista', 'pending' => 1, 'healthy' => 1, 'last_checked' => time(),
                    'last_result' => 'waiting', 'last_error' => '',
                    'detail' => 'En pausa adaptativa; el gestor retomará la formación del Intérprete.',
                ));
            }
            return $targets;
        }
        $targets[] = array(
            'type' => 'linguista',
            'data' => array(),
            'callback' => array(__CLASS__, 'supervisor_target_callback'),
        );
        return $targets;
    }

    public static function supervisor_target_callback($budget, $source, $target) {
        if (function_exists('seo_process_supervisor_managed_update')) {
            seo_process_supervisor_managed_update('linguista', array(
                'name' => 'Lingüista', 'pending' => 1, 'healthy' => 1, 'last_checked' => time(),
                'last_attempt_at' => time(), 'last_result' => 'running', 'last_error' => '',
                'detail' => 'El gestor está ejecutando una ventana de formación Lingüista.',
            ));
        }
        if (function_exists('seo_process_supervisor_log')) {
            seo_process_supervisor_log('info', 'process_window_started', 'Lingüista entra en una ventana del gestor.', 'Lingüista', array('seconds' => absint($budget)));
        }
        return self::process_manager_slice($budget, $source);
    }

    public static function processes_monitor_items($items) {
        if (!function_exists('seo_processes_state')) {
            return $items;
        }
        $payload = self::process_monitor_payload();
        $state = $payload['state'];
        $running = !empty($payload['running']);
        $raw = sanitize_key((string) ($state['status'] ?? 'stopped'));
        if ('error' === $raw) {
            $view_state = seo_processes_state('error', 'Error', 'error');
        } elseif ($running && absint($state['not_before'] ?? 0) > time()) {
            $view_state = seo_processes_state('waiting', 'En espera controlada', 'waiting');
        } elseif ($running) {
            $view_state = seo_processes_state('running', 'En formación', 'running');
        } elseif ('completed' === $raw) {
            $view_state = seo_processes_state('completed', 'Parado · completado', 'completed');
        } else {
            $view_state = seo_processes_state('stopped', 'Parado', 'stopped');
        }

        $duration = max(0.0, (float) ($state['last_duration'] ?? 0));
        $processed = absint($state['last_processed'] ?? 0);
        $rate = ($duration > 0 && $processed > 0) ? (($processed / $duration) * 60) : 0;
        $lesson_index = absint($state['lesson_index'] ?? 0);
        $lesson_count = max(1, count(self::lessons()));
        $lesson_total = absint($state['lesson_total'] ?? 0);
        $lesson_processed = absint($state['lesson_processed'] ?? 0);
        $fraction = $lesson_total > 0 ? min(1, $lesson_processed / $lesson_total) : 0;
        $progress = min(100, (int) round((($lesson_index + $fraction) / $lesson_count) * 100));
        if ('completed' === $raw) {
            $progress = 100;
        }
        $lesson = isset($payload['current']) && is_array($payload['current']) ? $payload['current'] : array();
        $detail = sanitize_text_field((string) ($state['last_error'] ?? ''));
        if (!$detail) {
            $detail = sanitize_text_field((string) ($state['last_message'] ?? ''));
        }
        if ($lesson) {
            $detail .= ($detail ? ' ' : '') . 'Lección ' . absint($lesson['order'] ?? ($lesson_index + 1)) . ' · ' . sanitize_text_field((string) ($lesson['title'] ?? '')) . '.';
        }
        $age = function_exists('seo_processes_age_seconds') ? seo_processes_age_seconds(absint($state['last_activity_at'] ?? 0)) : null;
        $speed = function_exists('seo_processes_format_rate') ? seo_processes_format_rate($rate, 'elementos') : number_format_i18n($rate, 1) . ' elementos/min';
        $stats = isset($payload['stats']) && is_array($payload['stats']) ? $payload['stats'] : array();
        $load = 'lote ' . number_format_i18n(absint($state['batch_size'] ?? 0))
            . ' · léxico activo ' . number_format_i18n(absint($stats['active'] ?? 0))
            . ' · evidencias ' . number_format_i18n(absint($stats['evidence'] ?? 0));

        $items[] = array(
            'id' => 'linguista',
            'name' => 'Lingüista',
            'kind' => 'Formación del Intérprete · Gestor de workers',
            'state' => $view_state,
            'speed' => $running ? $speed : '0 elementos/min',
            'response' => $duration > 0 ? number_format_i18n($duration, 2) . ' s el último lote' : 'Sin lote medido',
            'load' => $load,
            'activity' => function_exists('seo_processes_format_age') ? seo_processes_format_age($age) : '—',
            'activity_age' => $age,
            'progress' => $progress,
            'progress_text' => number_format_i18n($progress) . '%',
            'detail' => $detail ?: 'Lingüista está preparado para enseñar al Intérprete a entender al cliente.',
            'url' => add_query_arg(array('page' => 'seo-dependiente', 'tab' => 'interpreter'), admin_url('admin.php')),
            'can_start' => !$running,
            'start_label' => 'completed' === $raw ? 'Reentrenar' : 'Arrancar / reanudar',
        );
        return $items;
    }
}

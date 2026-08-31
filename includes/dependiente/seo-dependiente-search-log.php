<?php

defined('ABSPATH') || exit;

/**
 * Registro de busquedas y aprendizaje supervisado de Dependiente.
 *
 * No guarda IP, correo, nombre ni ningun identificador personal. El navegador
 * puede enviar un ID aleatorio de sesion; se almacena solo su hash para poder
 * detectar reformulaciones consecutivas y proponer mejoras semanticas.
 *
 * Flujo de aprendizaje:
 * consulta -> interpretacion -> resultados -> interaccion -> candidato -> revision -> regla semantica.
 */
final class SEO_Dependiente_Search_Log {
    const LOG_VERSION = '2026-08-31.1';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_search_log';
    }

    public static function install() {
        global $wpdb;

        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            search_uuid CHAR(36) NOT NULL,
            session_hash CHAR(64) NULL,
            query_hash CHAR(64) NOT NULL,
            query_original VARCHAR(500) NOT NULL,
            query_normalized VARCHAR(500) NOT NULL,
            request_kind VARCHAR(20) NOT NULL DEFAULT 'search',
            mode VARCHAR(20) NOT NULL DEFAULT 'need',
            semantic_signature VARCHAR(255) NULL,
            detected_intent VARCHAR(191) NULL,
            detected_object VARCHAR(191) NULL,
            detected_context VARCHAR(191) NULL,
            detected_state VARCHAR(191) NULL,
            ignored_terms LONGTEXT NULL,
            operators LONGTEXT NULL,
            semantic_analysis LONGTEXT NULL,
            unresolved_terms LONGTEXT NULL,
            search_strategy VARCHAR(40) NOT NULL DEFAULT 'strict',
            strategy_detail LONGTEXT NULL,
            candidate_count INT UNSIGNED NOT NULL DEFAULT 0,
            result_count INT UNSIGNED NOT NULL DEFAULT 0,
            top_results LONGTEXT NULL,
            execution_ms DECIMAL(10,3) NULL,
            feedback TINYINT NOT NULL DEFAULT 0,
            feedback_reason VARCHAR(255) NULL,
            clicked_product_id BIGINT UNSIGNED NULL,
            clicked_position SMALLINT UNSIGNED NULL,
            clicked_at DATETIME NULL,
            learning_status VARCHAR(20) NOT NULL DEFAULT 'new',
            learning_candidate LONGTEXT NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            promoted_rule_keys LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY search_uuid (search_uuid),
            KEY idx_session_created (session_hash, created_at),
            KEY idx_query_hash (query_hash),
            KEY idx_semantic_signature (semantic_signature(160)),
            KEY idx_intent_object (detected_intent(80), detected_object(80)),
            KEY idx_feedback_created (feedback, created_at),
            KEY idx_learning_created (learning_status, created_at),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('seo_dependiente_search_log_version', self::LOG_VERSION, false);
    }

    public static function table_exists() {
        if (class_exists('SEO_Dependiente_Index')) {
            return SEO_Dependiente_Index::table_exists(self::table());
        }
        global $wpdb;
        $table = self::table();
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    /**
     * Registra una respuesta completa de Dependiente.
     * Devuelve el UUID publico del log o cadena vacia si no se pudo guardar.
     */
    public static function record_search($data) {
        global $wpdb;

        if (!self::table_exists()) {
            self::install();
        }
        if (!self::table_exists()) {
            return '';
        }

        $query = sanitize_text_field((string) ($data['query'] ?? ''));
        $semantic = is_array($data['semantic'] ?? null) ? $data['semantic'] : array();
        $normalized = (string) ($semantic['normalized'] ?? '');
        if (!$normalized && class_exists('SEO_Dependiente_Semantics')) {
            $normalized = SEO_Dependiente_Semantics::normalize($query);
        }
        if (!$normalized && class_exists('SEO_Dependiente_Index')) {
            $normalized = SEO_Dependiente_Index::normalize($query);
        }

        $public_semantic = class_exists('SEO_Dependiente_Semantics')
            ? SEO_Dependiente_Semantics::public_analysis($semantic)
            : $semantic;
        // El log conserva tambien la regla exacta que produjo cada concepto.
        $public_semantic['matches'] = array_values(array_slice((array) ($semantic['matches'] ?? array()), 0, 50));

        $concepts = is_array($semantic['concepts'] ?? null) ? $semantic['concepts'] : array();
        $intent = self::first_concept($concepts, 'intent');
        $object = self::first_concept($concepts, 'object');
        $context = self::first_concept($concepts, 'context');
        $state = self::first_concept($concepts, 'state');
        $unresolved = self::unresolved_terms($semantic);
        $uuid = wp_generate_uuid4();
        $session_hash = self::session_hash((string) ($data['session_id'] ?? ''));
        $strategy = sanitize_key((string) ($data['search_strategy'] ?? 'strict')) ?: 'strict';
        $strategy_detail = is_array($data['strategy_detail'] ?? null) ? $data['strategy_detail'] : array();
        $top_results = self::compact_results((array) ($data['results'] ?? array()));
        $request_kind = sanitize_key((string) ($data['request_kind'] ?? 'search')) ?: 'search';
        if (!in_array($request_kind, array('search', 'refine', 'paginate'), true)) {
            $request_kind = 'search';
        }

        $row = array(
            'search_uuid'        => $uuid,
            'session_hash'       => $session_hash ?: null,
            'query_hash'         => hash('sha256', $normalized),
            'query_original'     => $query,
            'query_normalized'   => $normalized,
            'request_kind'       => $request_kind,
            'mode'               => sanitize_key((string) ($data['mode'] ?? 'need')) ?: 'need',
            'semantic_signature' => self::semantic_signature($concepts) ?: null,
            'detected_intent'    => $intent ?: null,
            'detected_object'    => $object ?: null,
            'detected_context'   => $context ?: null,
            'detected_state'     => $state ?: null,
            'ignored_terms'      => self::json((array) ($semantic['ignored'] ?? array())),
            'operators'          => self::json((array) ($semantic['operators'] ?? array())),
            'semantic_analysis'  => self::json($public_semantic),
            'unresolved_terms'   => self::json($unresolved),
            'search_strategy'    => $strategy,
            'strategy_detail'    => self::json($strategy_detail),
            'candidate_count'    => max(0, absint($data['candidate_count'] ?? 0)),
            'result_count'       => max(0, absint($data['result_count'] ?? 0)),
            'top_results'        => self::json($top_results),
            'execution_ms'       => isset($data['execution_ms']) ? round(max(0, (float) $data['execution_ms']), 3) : null,
            'learning_status'    => $unresolved ? 'new' : 'reviewed',
            'created_at'         => current_time('mysql'),
            'updated_at'         => current_time('mysql'),
        );

        if (false === $wpdb->insert(self::table(), $row)) {
            return '';
        }

        $log_id = absint($wpdb->insert_id);
        if ('search' === $request_kind && $session_hash) {
            self::infer_from_reformulation($log_id);
        }

        return $uuid;
    }

    /**
     * Registra click o valoracion asociada a una busqueda ya guardada.
     * feedback: 1 util, -1 no util, 0 sin valoracion.
     */
    public static function record_feedback($search_uuid, $event_type, $data = array()) {
        global $wpdb;

        $search_uuid = sanitize_text_field((string) $search_uuid);
        $event_type = sanitize_key((string) $event_type);
        if (!$search_uuid || !self::table_exists()) {
            return false;
        }

        $updates = array('updated_at' => current_time('mysql'));
        if ('click' === $event_type) {
            $updates['clicked_product_id'] = absint($data['product_id'] ?? 0) ?: null;
            $updates['clicked_position'] = absint($data['position'] ?? 0) ?: null;
            $updates['clicked_at'] = current_time('mysql');
        } elseif ('helpful' === $event_type) {
            $value = (int) ($data['value'] ?? 0);
            $updates['feedback'] = $value > 0 ? 1 : ($value < 0 ? -1 : 0);
            $reason = sanitize_text_field((string) ($data['reason'] ?? ''));
            $updates['feedback_reason'] = $reason ? self::substr($reason, 255) : null;
        } else {
            return false;
        }

        return false !== $wpdb->update(self::table(), $updates, array('search_uuid' => $search_uuid));
    }

    /**
     * Lee el log para construir paneles de diagnostico o revisiones manuales.
     */
    public static function get_searches($args = array()) {
        global $wpdb;

        if (!self::table_exists()) {
            return array();
        }
        $args = wp_parse_args($args, array(
            'days'            => 30,
            'limit'           => 100,
            'offset'          => 0,
            'feedback'        => null,
            'learning_status' => '',
            'intent'          => '',
            'object'          => '',
            'query'           => '',
        ));

        $where = array('created_at >= DATE_SUB(%s, INTERVAL %d DAY)');
        $params = array(current_time('mysql'), min(365, max(1, absint($args['days']))));
        if (null !== $args['feedback']) {
            $where[] = 'feedback = %d';
            $params[] = (int) $args['feedback'];
        }
        if ($args['learning_status']) {
            $where[] = 'learning_status = %s';
            $params[] = sanitize_key((string) $args['learning_status']);
        }
        if ($args['intent']) {
            $where[] = 'detected_intent = %s';
            $params[] = sanitize_text_field((string) $args['intent']);
        }
        if ($args['object']) {
            $where[] = 'detected_object = %s';
            $params[] = sanitize_text_field((string) $args['object']);
        }
        if ($args['query']) {
            $where[] = 'query_normalized LIKE %s';
            $params[] = '%' . $wpdb->esc_like(class_exists('SEO_Dependiente_Semantics')
                ? SEO_Dependiente_Semantics::normalize((string) $args['query'])
                : sanitize_text_field((string) $args['query'])) . '%';
        }

        $limit = min(500, max(1, absint($args['limit'])));
        $offset = max(0, absint($args['offset']));
        $params[] = $limit;
        $params[] = $offset;
        $sql = 'SELECT * FROM ' . self::table()
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY id DESC LIMIT %d OFFSET %d';

        return (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    public static function get_search($id_or_uuid) {
        global $wpdb;
        if (!self::table_exists()) {
            return null;
        }
        if (is_numeric($id_or_uuid)) {
            return $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', absint($id_or_uuid)),
                ARRAY_A
            );
        }
        return $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE search_uuid = %s LIMIT 1', sanitize_text_field((string) $id_or_uuid)),
            ARRAY_A
        );
    }

    /**
     * Guarda una propuesta revisable dentro del propio log. No la activa aun.
     */
    public static function set_learning_candidate($log_id, $expression, $canonical, $role, $relation = 'synonym', $confidence = 1.0) {
        global $wpdb;
        $candidate = self::candidate(
            $expression,
            $canonical,
            $role,
            $relation,
            $confidence,
            'manual_review',
            '',
            ''
        );
        if (!$candidate) {
            return new WP_Error('seo_dependiente_candidate_invalid', 'No se pudo construir el candidato semantico.');
        }
        $ok = $wpdb->update(
            self::table(),
            array(
                'learning_status'    => 'candidate',
                'learning_candidate' => self::json(array($candidate)),
                'updated_at'         => current_time('mysql'),
            ),
            array('id' => absint($log_id))
        );
        return false === $ok ? new WP_Error('seo_dependiente_candidate_save_failed', 'No se pudo guardar el candidato.') : $candidate;
    }

    /**
     * Devuelve candidatos de mejora obtenidos de reformulaciones y terminos no
     * interpretados. No modifica el vocabulario.
     */
    public static function learning_candidates($args = array()) {
        global $wpdb;

        if (!self::table_exists()) {
            return array();
        }
        $args = wp_parse_args($args, array(
            'days'            => 90,
            'limit'           => 2000,
            'min_occurrences' => 2,
        ));
        $days = min(365, max(1, absint($args['days'])));
        $limit = min(10000, max(50, absint($args['limit'])));
        $minimum = max(1, absint($args['min_occurrences']));

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, query_original, semantic_signature, detected_intent, detected_object,
                        unresolved_terms, learning_status, learning_candidate, feedback, result_count, created_at
                 FROM " . self::table() . "
                 WHERE request_kind = 'search'
                   AND created_at >= DATE_SUB(%s, INTERVAL %d DAY)
                   AND learning_status IN ('new','candidate','reviewed')
                 ORDER BY id DESC
                 LIMIT %d",
                current_time('mysql'),
                $days,
                $limit
            ),
            ARRAY_A
        );

        $aggregated = array();
        $explicit = array();
        foreach ($rows as $row) {
            $candidate_data = self::decode_json($row['learning_candidate'] ?? '');
            foreach (self::as_candidate_list($candidate_data) as $candidate) {
                $key = self::candidate_key($candidate);
                if (!$key) {
                    continue;
                }
                if (!isset($explicit[$key])) {
                    $explicit[$key] = array_merge($candidate, array(
                        'occurrences' => 0,
                        'log_ids'     => array(),
                        'examples'    => array(),
                    ));
                }
                $explicit[$key]['occurrences']++;
                $explicit[$key]['log_ids'][] = absint($row['id']);
                if (count($explicit[$key]['examples']) < 5) {
                    $explicit[$key]['examples'][] = (string) $row['query_original'];
                }
            }

            $unresolved = self::decode_json($row['unresolved_terms'] ?? '');
            foreach ((array) $unresolved as $term) {
                $term = class_exists('SEO_Dependiente_Semantics')
                    ? SEO_Dependiente_Semantics::normalize((string) $term)
                    : sanitize_title((string) $term);
                if (!$term || strlen($term) < 3) {
                    continue;
                }
                if (!isset($aggregated[$term])) {
                    $aggregated[$term] = array(
                        'expression'        => $term,
                        'occurrences'       => 0,
                        'zero_results'      => 0,
                        'negative_feedback' => 0,
                        'contexts'          => array(),
                        'examples'          => array(),
                    );
                }
                $aggregated[$term]['occurrences']++;
                if (0 === absint($row['result_count'])) {
                    $aggregated[$term]['zero_results']++;
                }
                if ((int) $row['feedback'] < 0) {
                    $aggregated[$term]['negative_feedback']++;
                }
                $signature = (string) ($row['semantic_signature'] ?? '');
                if ($signature) {
                    $aggregated[$term]['contexts'][$signature] = 1 + ($aggregated[$term]['contexts'][$signature] ?? 0);
                }
                if (count($aggregated[$term]['examples']) < 5) {
                    $aggregated[$term]['examples'][] = (string) $row['query_original'];
                }
            }
        }

        foreach ($aggregated as $term => &$item) {
            arsort($item['contexts'], SORT_NUMERIC);
            $item['contexts'] = array_slice($item['contexts'], 0, 5, true);
        }
        unset($item);

        $aggregated = array_values(array_filter($aggregated, static function ($item) use ($minimum) {
            return absint($item['occurrences'] ?? 0) >= $minimum;
        }));
        usort($aggregated, static function ($a, $b) {
            $a_score = (int) $a['occurrences'] + (2 * (int) $a['zero_results']) + (3 * (int) $a['negative_feedback']);
            $b_score = (int) $b['occurrences'] + (2 * (int) $b['zero_results']) + (3 * (int) $b['negative_feedback']);
            return $b_score <=> $a_score;
        });

        usort($explicit, static function ($a, $b) {
            $confidence = ((float) ($b['confidence'] ?? 0)) <=> ((float) ($a['confidence'] ?? 0));
            return 0 !== $confidence ? $confidence : ((int) ($b['occurrences'] ?? 0) <=> (int) ($a['occurrences'] ?? 0));
        });

        return array(
            'reformulation_candidates' => array_values($explicit),
            'unresolved_terms'         => $aggregated,
        );
    }

    /**
     * Promociona una propuesta revisada a wp_seo_dependiente_semantics.
     * Esta es la funcion que realmente "alimenta" el conocimiento semantico.
     */
    public static function promote_candidate($log_id, $candidate_index = 0, $reviewer_id = 0) {
        global $wpdb;

        $log_id = absint($log_id);
        if (!$log_id || !class_exists('SEO_Dependiente_Semantics') || !SEO_Dependiente_Semantics::table_exists()) {
            return new WP_Error('seo_dependiente_learning_unavailable', 'La capa semantica no esta disponible.');
        }

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $log_id),
            ARRAY_A
        );
        if (!$row) {
            return new WP_Error('seo_dependiente_log_not_found', 'No se ha encontrado el registro de busqueda.');
        }

        $candidates = self::as_candidate_list(self::decode_json($row['learning_candidate'] ?? ''));
        $candidate = $candidates[absint($candidate_index)] ?? null;
        if (!$candidate) {
            return new WP_Error('seo_dependiente_candidate_not_found', 'Este registro no contiene un candidato promocionable.');
        }

        $expression = SEO_Dependiente_Semantics::normalize((string) ($candidate['expression'] ?? ''));
        $canonical = SEO_Dependiente_Semantics::normalize((string) ($candidate['canonical_expression'] ?? ''));
        $role = sanitize_key((string) ($candidate['semantic_role'] ?? ''));
        if (!$expression || !$canonical || !in_array($role, array('intent','object','state','context','modifier','tool','material','accessory'), true)) {
            return new WP_Error('seo_dependiente_candidate_invalid', 'El candidato no contiene expresion, concepto canonico o rol validos.');
        }

        $semantic_table = SEO_Dependiente_Semantics::table();
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, rule_key FROM {$semantic_table}
                 WHERE normalized_expression = %s
                   AND canonical_expression = %s
                   AND semantic_role = %s
                   AND active = 1
                 LIMIT 1",
                $expression,
                $canonical,
                $role
            ),
            ARRAY_A
        );

        if ($existing) {
            self::mark_promoted($log_id, (string) $existing['rule_key'], $reviewer_id);
            return array('rule_id' => absint($existing['id']), 'rule_key' => (string) $existing['rule_key'], 'existing' => true);
        }

        $relation = sanitize_key((string) ($candidate['relation_type'] ?? 'synonym')) ?: 'synonym';
        $rule_type = in_array($relation, array('synonym','variant'), true) ? 'alias' : 'implication';
        $rule_key = sanitize_key('learned-' . $role . '-' . substr(hash('sha256', $expression . '|' . $canonical . '|' . $role), 0, 24));
        $confidence = min(1, max(0, (float) ($candidate['confidence'] ?? 0.75)));
        $weight = min(1000, max(1, absint($candidate['weight'] ?? round(70 + (30 * $confidence)))));

        $insert = array(
            'rule_key'              => $rule_key,
            'rule_type'             => $rule_type,
            'expression'            => $expression,
            'normalized_expression' => $expression,
            'canonical_expression'  => $canonical,
            'match_type'            => false !== strpos($expression, ' ') ? 'phrase' : 'token',
            'semantic_role'         => $role,
            'relation_type'         => $relation,
            'weight'                => $weight,
            'priority'              => 6,
            'confidence'            => $confidence,
            'language'              => 'es',
            'source'                => 'learned',
            'metadata'              => self::json(array(
                'source_log_id' => $log_id,
                'evidence'      => (string) ($candidate['evidence'] ?? 'reviewed_log'),
                'reviewed_by'   => absint($reviewer_id),
            )),
            'active'                => 1,
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        );

        if (false === $wpdb->insert($semantic_table, $insert)) {
            return new WP_Error('seo_dependiente_candidate_insert_failed', 'No se pudo guardar la nueva regla semantica.');
        }

        self::mark_promoted($log_id, $rule_key, $reviewer_id);
        return array('rule_id' => absint($wpdb->insert_id), 'rule_key' => $rule_key, 'existing' => false);
    }

    public static function ignore_candidate($log_id, $reviewer_id = 0) {
        global $wpdb;
        return false !== $wpdb->update(
            self::table(),
            array(
                'learning_status' => 'ignored',
                'reviewed_by'     => absint($reviewer_id) ?: null,
                'reviewed_at'     => current_time('mysql'),
                'updated_at'      => current_time('mysql'),
            ),
            array('id' => absint($log_id))
        );
    }

    /**
     * Detecta reformulaciones de una misma sesion. Ejemplo:
     * 1) "apañar un grifo" (apañar no entendido)
     * 2) "reparar un grifo" (reparar entendido)
     * => propone apañar -> reparar como INTENT con confianza 0.82.
     */
    private static function infer_from_reformulation($current_id) {
        global $wpdb;

        $current = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', absint($current_id)),
            ARRAY_A
        );
        if (!$current || empty($current['session_hash'])) {
            return;
        }

        $previous = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . "
                 WHERE session_hash = %s
                   AND request_kind = 'search'
                   AND id < %d
                   AND created_at >= DATE_SUB(%s, INTERVAL 30 MINUTE)
                 ORDER BY id DESC
                 LIMIT 1",
                $current['session_hash'],
                absint($current_id),
                current_time('mysql')
            ),
            ARRAY_A
        );
        if (!$previous) {
            return;
        }

        $previous_unresolved = (array) self::decode_json($previous['unresolved_terms'] ?? '');
        if (!$previous_unresolved) {
            return;
        }

        $candidates = array();
        $same_object = self::same_nonempty((string) $previous['detected_object'], (string) $current['detected_object']);
        $same_intent = self::same_nonempty((string) $previous['detected_intent'], (string) $current['detected_intent']);

        if (!$previous['detected_intent'] && $current['detected_intent'] && $same_object) {
            foreach (array_slice($previous_unresolved, 0, 3) as $term) {
                $candidates[] = self::candidate(
                    $term,
                    $current['detected_intent'],
                    'intent',
                    'synonym',
                    0.82,
                    'reformulation',
                    $previous['query_original'],
                    $current['query_original']
                );
            }
        }

        if (!$previous['detected_object'] && $current['detected_object'] && $same_intent) {
            foreach (array_slice($previous_unresolved, 0, 3) as $term) {
                $candidates[] = self::candidate(
                    $term,
                    $current['detected_object'],
                    'object',
                    'synonym',
                    0.80,
                    'reformulation',
                    $previous['query_original'],
                    $current['query_original']
                );
            }
        }

        $candidates = array_values(array_filter($candidates));
        if (!$candidates) {
            return;
        }

        $wpdb->update(
            self::table(),
            array(
                'learning_status'    => 'candidate',
                'learning_candidate' => self::json($candidates),
                'updated_at'         => current_time('mysql'),
            ),
            array('id' => absint($previous['id']))
        );
    }

    private static function mark_promoted($log_id, $rule_key, $reviewer_id) {
        global $wpdb;
        $wpdb->update(
            self::table(),
            array(
                'learning_status'   => 'promoted',
                'reviewed_by'       => absint($reviewer_id) ?: null,
                'reviewed_at'       => current_time('mysql'),
                'promoted_rule_keys'=> self::json(array($rule_key)),
                'updated_at'        => current_time('mysql'),
            ),
            array('id' => absint($log_id))
        );
    }

    private static function unresolved_terms($semantic) {
        $terms = array();
        foreach ((array) ($semantic['groups'] ?? array()) as $group) {
            if ('term' !== sanitize_key((string) ($group['role'] ?? 'term'))) {
                continue;
            }
            $term = (string) ($group['canonical'] ?? '');
            $term = class_exists('SEO_Dependiente_Semantics')
                ? SEO_Dependiente_Semantics::normalize($term)
                : sanitize_title($term);
            if ($term && strlen($term) >= 2) {
                $terms[$term] = true;
            }
        }
        return array_keys($terms);
    }

    private static function compact_results($results) {
        $out = array();
        foreach (array_slice($results, 0, 20) as $position => $result) {
            if (!is_array($result)) {
                continue;
            }
            $out[] = array(
                'id'         => absint($result['id'] ?? 0),
                'position'   => $position + 1,
                'title'      => self::substr(sanitize_text_field((string) ($result['title'] ?? '')), 180),
                'score'      => round((float) ($result['score'] ?? 0), 4),
                'reasons'    => array_slice(array_values(array_map('sanitize_text_field', (array) ($result['reasons'] ?? array()))), 0, 6),
                'categories' => array_slice(array_values(array_map('sanitize_text_field', (array) ($result['categories'] ?? array()))), 0, 4),
            );
        }
        return $out;
    }

    private static function semantic_signature($concepts) {
        $parts = array();
        foreach (array('intent','object','state','context') as $role) {
            $values = array_values(array_unique(array_filter(array_map('strval', (array) ($concepts[$role] ?? array())))));
            sort($values, SORT_NATURAL | SORT_FLAG_CASE);
            if ($values) {
                $parts[] = $role . '=' . implode(',', $values);
            }
        }
        return self::substr(implode('|', $parts), 255);
    }

    private static function first_concept($concepts, $role) {
        $values = array_values(array_filter((array) ($concepts[$role] ?? array())));
        return $values ? self::substr(sanitize_text_field((string) $values[0]), 191) : '';
    }

    private static function session_hash($session_id) {
        $session_id = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $session_id);
        if (!$session_id) {
            return '';
        }
        return hash_hmac('sha256', self::substr($session_id, 100), wp_salt('nonce'));
    }

    private static function candidate($expression, $canonical, $role, $relation, $confidence, $evidence, $before, $after) {
        $expression = class_exists('SEO_Dependiente_Semantics')
            ? SEO_Dependiente_Semantics::normalize((string) $expression)
            : sanitize_title((string) $expression);
        $canonical = class_exists('SEO_Dependiente_Semantics')
            ? SEO_Dependiente_Semantics::normalize((string) $canonical)
            : sanitize_title((string) $canonical);
        if (!$expression || !$canonical || $expression === $canonical) {
            return null;
        }
        return array(
            'expression'           => $expression,
            'canonical_expression' => $canonical,
            'semantic_role'        => sanitize_key((string) $role),
            'relation_type'        => sanitize_key((string) $relation),
            'confidence'           => round(min(1, max(0, (float) $confidence)), 4),
            'evidence'             => sanitize_key((string) $evidence),
            'query_before'         => sanitize_text_field((string) $before),
            'query_after'          => sanitize_text_field((string) $after),
        );
    }

    private static function same_nonempty($a, $b) {
        return '' !== trim((string) $a) && '' !== trim((string) $b) && (string) $a === (string) $b;
    }

    private static function candidate_key($candidate) {
        if (!is_array($candidate)) {
            return '';
        }
        $expression = (string) ($candidate['expression'] ?? '');
        $canonical = (string) ($candidate['canonical_expression'] ?? '');
        $role = (string) ($candidate['semantic_role'] ?? '');
        return ($expression && $canonical && $role) ? $role . '|' . $expression . '|' . $canonical : '';
    }

    private static function as_candidate_list($value) {
        if (!is_array($value)) {
            return array();
        }
        if (isset($value['expression'])) {
            return array($value);
        }
        return array_values(array_filter($value, 'is_array'));
    }

    private static function decode_json($value) {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || '' === trim($value)) {
            return array();
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function json($value) {
        return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function substr($value, $length) {
        $value = (string) $value;
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}

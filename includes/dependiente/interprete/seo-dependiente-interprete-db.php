<?php

defined('ABSPATH') || exit;

/**
 * Memoria lingüística persistente del Intérprete.
 *
 * seo_vocabulary describe QUÉ existe en el catálogo.
 * seo_interprete_lexicon describe CÓMO puede expresarlo un cliente.
 * seo_interprete_lexicon_evidence conserva la procedencia que justificó cada
 * relación para que Lingüista pueda reentrenar sin inflar evidencias ni borrar
 * aprendizaje válido por defecto.
 */
final class SEO_Dependiente_Interprete_DB {
    const SCHEMA_VERSION = '0.3.0';
    const SCHEMA_OPTION  = 'seo_dependiente_interprete_schema_version';

    private static $rows_cache = null;
    private static $fuzzy_index = null;
    private static $table_exists = null;
    private static $evidence_table_exists = null;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_interprete_lexicon';
    }

    public static function evidence_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_interprete_lexicon_evidence';
    }

    public static function table_exists() {
        global $wpdb;
        if (null !== self::$table_exists) {
            return self::$table_exists;
        }
        $table = self::table();
        self::$table_exists = (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        ) === $table;
        return self::$table_exists;
    }

    public static function evidence_table_exists() {
        global $wpdb;
        if (null !== self::$evidence_table_exists) {
            return self::$evidence_table_exists;
        }
        $table = self::evidence_table();
        self::$evidence_table_exists = (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        ) === $table;
        return self::$evidence_table_exists;
    }

    /**
     * Reconcilia las tablas. Nunca inicia una formación.
     */
    public static function install() {
        global $wpdb;

        $table = self::table();
        $evidence = self::evidence_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            expression VARCHAR(191) NOT NULL,
            normalized_expression VARCHAR(191) NOT NULL,
            canonical_term VARCHAR(191) NOT NULL,
            target_search VARCHAR(255) NOT NULL,
            relation_type VARCHAR(40) NOT NULL DEFAULT 'synonym',
            semantic_group VARCHAR(100) NULL,
            vocabulary_id BIGINT UNSIGNED NULL,
            context_terms LONGTEXT NULL,
            context_required TINYINT(1) NOT NULL DEFAULT 0,
            confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
            priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
            language VARCHAR(10) NOT NULL DEFAULT 'es',
            source VARCHAR(80) NOT NULL DEFAULT 'interpreter',
            lesson_key VARCHAR(80) NOT NULL DEFAULT '',
            evidence_count INT UNSIGNED NOT NULL DEFAULT 1,
            validated TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY expression_target (normalized_expression, canonical_term, relation_type),
            KEY canonical_term (canonical_term),
            KEY vocabulary_id (vocabulary_id),
            KEY lesson_active (lesson_key, active),
            KEY language_active (language, active),
            KEY priority_active (priority, active),
            KEY evidence_active (evidence_count, active)
        ) {$charset_collate};";

        $sql_evidence = "CREATE TABLE {$evidence} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lexicon_id BIGINT UNSIGNED NOT NULL,
            source_type VARCHAR(50) NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source_hash CHAR(64) NOT NULL DEFAULT '',
            lesson_key VARCHAR(80) NOT NULL DEFAULT '',
            confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
            context_terms LONGTEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY lexicon_source (lexicon_id, source_type, source_id),
            KEY source_ref (source_type, source_id),
            KEY lesson_active (lesson_key, active),
            KEY lexicon_active (lexicon_id, active)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        dbDelta($sql_evidence);
        self::$table_exists = true;
        self::$evidence_table_exists = true;

        self::seed_i1_subject_action();
        self::resolve_vocabulary_ids();
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        self::clear_cache();
    }

    /**
     * Semilla mínima de regresión. Lingüista ampliará la memoria automáticamente.
     */
    private static function seed_i1_subject_action() {
        $rows = array(
            array('extractor', 'extractor', 'extractor', 'noun', '', array(), 0, 1.00, 1),
            array('extractores', 'extractor', 'extractor', 'noun', '', array(), 0, 1.00, 1),
            array('extraer', 'extractor', 'extractor', 'verb_to_tool', '', array('rodamiento','rodamientos','cojinete','cojinetes','polea','poleas','engranaje','engranajes','rotula','rotulas'), 1, 0.98, 2),
            array('sacar', 'extractor', 'extractor', 'verb_to_tool', '', array('rodamiento','rodamientos','cojinete','cojinetes','polea','poleas','engranaje','engranajes','rotula','rotulas'), 1, 0.96, 3),
            array('retirar', 'extractor', 'extractor', 'verb_to_tool', '', array('rodamiento','rodamientos','cojinete','cojinetes','polea','poleas','engranaje','engranajes','rotula','rotulas'), 1, 0.95, 3),

            array('taladro', 'taladro', 'taladro', 'noun', '', array(), 0, 1.00, 1),
            array('taladros', 'taladro', 'taladro', 'noun', '', array(), 0, 1.00, 1),
            array('taladrar', 'taladro', 'taladro', 'verb_to_tool', '', array(), 0, 0.99, 2),
            array('perforar', 'taladro', 'taladro', 'verb_to_tool', '', array(), 0, 0.96, 3),
            array('agujerear', 'taladro', 'taladro', 'verb_to_tool', '', array(), 0, 0.98, 2),
            array('hacer un agujero', 'taladro', 'taladro', 'phrase_to_tool', '', array(), 0, 0.97, 2),
            array('hacer agujeros', 'taladro', 'taladro', 'phrase_to_tool', '', array(), 0, 0.97, 2),

            array('remachar', 'remachadora', 'remachadora', 'verb_to_tool', '', array(), 0, 0.99, 2),
            array('soldar', 'soldadora', 'soldadora', 'verb_to_tool', '', array(), 0, 0.96, 3),
            array('lijar', 'lijadora', 'lijadora', 'verb_to_tool', '', array(), 0, 0.98, 2),
            array('grapar', 'grapadora', 'grapadora', 'verb_to_tool', '', array(), 0, 0.98, 2),
            array('desbrozar', 'desbrozadora', 'desbrozadora', 'verb_to_tool', '', array(), 0, 0.99, 2),
        );

        foreach ($rows as $row) {
            self::upsert_row(array(
                'expression'       => $row[0],
                'canonical_term'   => $row[1],
                'target_search'    => $row[2],
                'relation_type'    => $row[3],
                'semantic_group'   => $row[4],
                'context_terms'    => $row[5],
                'context_required' => $row[6],
                'confidence'       => $row[7],
                'priority'         => $row[8],
                'source'           => 'interpreter_seed',
                'lesson_key'       => 'i1_subject_action',
                'evidence_count'   => 1,
                'validated'        => 1,
                'active'           => 1,
            ));
        }
    }

    /**
     * Crea o actualiza una relación. Una repetición de formación no desactiva
     * una regla ya activa salvo que se indique force_active explícitamente.
     *
     * @return int ID del léxico o 0.
     */
    public static function upsert_row($row) {
        global $wpdb;

        if (!self::table_exists()) {
            return 0;
        }

        $expression = self::clean_text($row['expression'] ?? '');
        $normalized = self::normalize($expression);
        $canonical = self::clean_text($row['canonical_term'] ?? '');
        $target = self::clean_text($row['target_search'] ?? $canonical);
        $relation = sanitize_key((string) ($row['relation_type'] ?? 'synonym'));

        if ('' === $normalized || '' === $canonical || '' === $target || '' === $relation) {
            return 0;
        }

        $context_terms = self::normalize_terms((array) ($row['context_terms'] ?? array()));
        $table = self::table();
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE normalized_expression=%s AND canonical_term=%s AND relation_type=%s LIMIT 1",
            $normalized,
            $canonical,
            $relation
        ), ARRAY_A);

        $active = array_key_exists('active', $row) ? (empty($row['active']) ? 0 : 1) : 1;
        $validated = array_key_exists('validated', $row) ? (empty($row['validated']) ? 0 : 1) : 0;
        $evidence_count = max(1, absint($row['evidence_count'] ?? 1));
        $confidence = min(1, max(0, (float) ($row['confidence'] ?? 1)));
        $priority = min(255, max(1, absint($row['priority'] ?? 5)));

        if (is_array($existing)) {
            $existing_context = json_decode((string) ($existing['context_terms'] ?? ''), true);
            $context_terms = array_values(array_unique(array_merge(
                is_array($existing_context) ? self::normalize_terms($existing_context) : array(),
                $context_terms
            )));
            if (empty($row['force_active']) && !empty($existing['active'])) {
                $active = 1;
            }
            if (!empty($existing['validated'])) {
                $validated = 1;
            }
            $evidence_count = max(absint($existing['evidence_count'] ?? 1), $evidence_count);
            $confidence = max((float) ($existing['confidence'] ?? 0), $confidence);
            $priority = min(absint($existing['priority'] ?? 255), $priority);
        }

        $data = array(
            'expression'            => $expression,
            'normalized_expression' => $normalized,
            'canonical_term'        => $canonical,
            'target_search'         => $target,
            'relation_type'         => $relation,
            'semantic_group'        => sanitize_key((string) ($row['semantic_group'] ?? ($existing['semantic_group'] ?? ''))),
            'vocabulary_id'         => !empty($row['vocabulary_id']) ? absint($row['vocabulary_id']) : (!empty($existing['vocabulary_id']) ? absint($existing['vocabulary_id']) : null),
            'context_terms'         => wp_json_encode($context_terms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'context_required'      => !empty($row['context_required']) || !empty($existing['context_required']) ? 1 : 0,
            'confidence'            => $confidence,
            'priority'              => $priority,
            'language'              => sanitize_key((string) ($row['language'] ?? ($existing['language'] ?? 'es'))) ?: 'es',
            'source'                => sanitize_key((string) ($row['source'] ?? ($existing['source'] ?? 'interpreter'))) ?: 'interpreter',
            'lesson_key'            => sanitize_key((string) ($row['lesson_key'] ?? ($existing['lesson_key'] ?? ''))),
            'evidence_count'        => $evidence_count,
            'validated'             => $validated,
            'active'                => $active,
        );

        if (is_array($existing) && !empty($existing['id'])) {
            $id = absint($existing['id']);
            $wpdb->update($table, $data, array('id' => $id));
        } else {
            $wpdb->insert($table, $data);
            $id = absint($wpdb->insert_id);
        }

        self::clear_cache();
        return $id;
    }

    public static function add_evidence($lexicon_id, $source_type, $source_id, $source_hash, $lesson_key, $confidence = 1.0, $context_terms = array()) {
        global $wpdb;

        $lexicon_id = absint($lexicon_id);
        $source_type = sanitize_key((string) $source_type);
        $source_id = absint($source_id);
        if (!$lexicon_id || '' === $source_type || !self::evidence_table_exists()) {
            return false;
        }

        $source_hash = preg_replace('/[^a-f0-9]/', '', strtolower((string) $source_hash));
        if (64 !== strlen($source_hash)) {
            $source_hash = hash('sha256', (string) $source_hash);
        }
        $context_terms = self::normalize_terms((array) $context_terms);
        $table = self::evidence_table();
        $existing_id = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE lexicon_id=%d AND source_type=%s AND source_id=%d LIMIT 1",
            $lexicon_id,
            $source_type,
            $source_id
        )));

        $data = array(
            'lexicon_id'   => $lexicon_id,
            'source_type'  => $source_type,
            'source_id'    => $source_id,
            'source_hash'  => $source_hash,
            'lesson_key'   => sanitize_key((string) $lesson_key),
            'confidence'   => min(1, max(0, (float) $confidence)),
            'context_terms'=> wp_json_encode($context_terms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'active'       => 1,
        );

        if ($existing_id) {
            $wpdb->update($table, $data, array('id' => $existing_id));
        } else {
            $wpdb->insert($table, $data);
        }

        self::refresh_evidence_summary($lexicon_id);
        return true;
    }

    public static function refresh_evidence_summary($lexicon_id) {
        global $wpdb;
        $lexicon_id = absint($lexicon_id);
        if (!$lexicon_id || !self::evidence_table_exists() || !self::table_exists()) {
            return;
        }
        $evidence = self::evidence_table();
        $summary = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) evidence_count, MAX(confidence) max_confidence FROM {$evidence} WHERE lexicon_id=%d AND active=1",
            $lexicon_id
        ), ARRAY_A);
        $count = max(1, absint($summary['evidence_count'] ?? 0));
        $confidence = min(1, max(0, (float) ($summary['max_confidence'] ?? 0)));
        $wpdb->update(
            self::table(),
            array('evidence_count' => $count, 'confidence' => $confidence > 0 ? $confidence : 0.5),
            array('id' => $lexicon_id)
        );
        self::clear_cache();
    }

    public static function update_row($id, $changes) {
        global $wpdb;
        $id = absint($id);
        if (!$id || !self::table_exists() || !is_array($changes)) {
            return false;
        }
        $allowed = array();
        foreach ($changes as $key => $value) {
            if ('context_terms' === $key) {
                $allowed[$key] = wp_json_encode(self::normalize_terms((array) $value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (in_array($key, array('context_required','validated','active'), true)) {
                $allowed[$key] = empty($value) ? 0 : 1;
            } elseif ('confidence' === $key) {
                $allowed[$key] = min(1, max(0, (float) $value));
            } elseif ('priority' === $key) {
                $allowed[$key] = min(255, max(1, absint($value)));
            } elseif ('evidence_count' === $key) {
                $allowed[$key] = max(1, absint($value));
            } elseif (in_array($key, array('target_search','canonical_term','expression'), true)) {
                $allowed[$key] = self::clean_text($value);
            } elseif (in_array($key, array('semantic_group','source','lesson_key','relation_type'), true)) {
                $allowed[$key] = sanitize_key((string) $value);
            }
        }
        if (!$allowed) {
            return false;
        }
        $ok = false !== $wpdb->update(self::table(), $allowed, array('id' => $id));
        self::clear_cache();
        return $ok;
    }

    public static function row($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id || !self::table_exists()) {
            return array();
        }
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::table() . " WHERE id=%d", $id), ARRAY_A);
        return is_array($row) ? $row : array();
    }

    public static function canonical_count_for_expression($normalized_expression) {
        global $wpdb;
        $normalized_expression = self::normalize($normalized_expression);
        if ('' === $normalized_expression || !self::table_exists()) {
            return 0;
        }
        return absint($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT canonical_term) FROM " . self::table() . " WHERE normalized_expression=%s AND language='es'",
            $normalized_expression
        )));
    }

    public static function find_target_for_expression($expression) {
        global $wpdb;
        $normalized = self::normalize($expression);
        if ('' === $normalized || !self::table_exists()) {
            return array();
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,canonical_term,target_search,semantic_group,vocabulary_id,confidence FROM " . self::table() . " WHERE normalized_expression=%s AND active=1 ORDER BY priority ASC,confidence DESC,id ASC LIMIT 1",
            $normalized
        ), ARRAY_A);
        return is_array($row) ? $row : array();
    }

    public static function evidence_product_ids($lexicon_id, $limit = 250) {
        global $wpdb;
        $lexicon_id = absint($lexicon_id);
        $limit = max(1, min(1000, absint($limit)));
        if (!$lexicon_id || !self::evidence_table_exists()) {
            return array();
        }
        return array_values(array_unique(array_filter(array_map('absint', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT source_id FROM " . self::evidence_table() . " WHERE lexicon_id=%d AND source_type='product' AND active=1 ORDER BY source_id ASC LIMIT %d",
            $lexicon_id,
            $limit
        ))))));
    }

    public static function resolve_vocabulary_ids() {
        global $wpdb;
        if (!self::table_exists()) {
            return;
        }
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($vocabulary))) === $vocabulary;
        if (!$exists) {
            return;
        }

        $table = self::table();
        $rows = $wpdb->get_results(
            "SELECT id,canonical_term FROM {$table} WHERE active=1 AND (vocabulary_id IS NULL OR vocabulary_id=0) LIMIT 5000",
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $canonical = self::clean_text($row['canonical_term'] ?? '');
            if ('' === $canonical) {
                continue;
            }
            $slug = sanitize_title($canonical);
            $match = $wpdb->get_row($wpdb->prepare(
                "SELECT id,semantic_group FROM {$vocabulary} WHERE active=1 AND (slug=%s OR LOWER(label)=LOWER(%s)) ORDER BY id ASC LIMIT 1",
                $slug,
                $canonical
            ), ARRAY_A);
            if ($match) {
                $wpdb->update($table, array(
                    'vocabulary_id' => absint($match['id']),
                    'semantic_group' => sanitize_key((string) ($match['semantic_group'] ?? '')),
                ), array('id' => absint($row['id'])));
            }
        }
        self::clear_cache();
    }

    /**
     * Coincidencias literales activas, priorizando contexto específico.
     */
    public static function matching_rows($normalized_query) {
        $normalized_query = self::normalize($normalized_query);
        if ('' === $normalized_query || !self::table_exists()) {
            return array();
        }

        $matches = array();
        foreach (self::active_rows() as $row) {
            $expression = (string) ($row['normalized_expression'] ?? '');
            if ('' === $expression || false === strpos(' ' . $normalized_query . ' ', ' ' . $expression . ' ')) {
                continue;
            }
            $row = self::apply_context_match($row, $normalized_query);
            if (!$row) {
                continue;
            }
            $matches[] = $row;
        }
        self::sort_matches($matches);
        return $matches;
    }

    /**
     * Fallback muy acotado para pequeños errores ortográficos. Solo compara
     * palabras de al menos cinco caracteres con expresiones de una palabra.
     */
    public static function fuzzy_matching_rows($normalized_query, $max_distance = 1) {
        $normalized_query = self::normalize($normalized_query);
        if ('' === $normalized_query || !self::table_exists()) {
            return array();
        }
        $max_distance = max(1, min(2, absint($max_distance)));
        self::build_fuzzy_index();
        $tokens = array_values(array_unique(array_filter(explode(' ', $normalized_query))));
        $matches = array();
        foreach ($tokens as $token) {
            $len = strlen($token);
            if ($len < 5) {
                continue;
            }
            // Dos ediciones solo en palabras largas; en las cortas sería fácil
            // convertir una errata en otro concepto válido del catálogo.
            $token_distance = ($len >= 8) ? $max_distance : min(1, $max_distance);
            $first = substr($token, 0, 1);
            for ($candidate_len = max(5, $len - $token_distance); $candidate_len <= $len + $token_distance; $candidate_len++) {
                $bucket = self::$fuzzy_index[$first][$candidate_len] ?? array();
                foreach ($bucket as $row) {
                    $expr = (string) ($row['normalized_expression'] ?? '');
                    if (false !== strpos($expr, ' ')) {
                        continue;
                    }
                    $distance = levenshtein($token, $expr);
                    if ($distance > $token_distance) {
                        continue;
                    }
                    $checked = self::apply_context_match($row, $normalized_query);
                    if (!$checked) {
                        continue;
                    }
                    $checked['fuzzy_distance'] = $distance;
                    $matches[$checked['id']] = $checked;
                }
            }
        }
        $matches = array_values($matches);
        usort($matches, static function ($a, $b) {
            $distance = absint($a['fuzzy_distance'] ?? 99) <=> absint($b['fuzzy_distance'] ?? 99);
            if (0 !== $distance) {
                return $distance;
            }
            $context = count((array) ($b['matched_context'] ?? array())) <=> count((array) ($a['matched_context'] ?? array()));
            if (0 !== $context) {
                return $context;
            }
            $priority = absint($a['priority'] ?? 5) <=> absint($b['priority'] ?? 5);
            if (0 !== $priority) {
                return $priority;
            }
            return (float) ($b['confidence'] ?? 0) <=> (float) ($a['confidence'] ?? 0);
        });
        return $matches;
    }

    public static function active_rows() {
        global $wpdb;
        if (is_array(self::$rows_cache)) {
            return self::$rows_cache;
        }
        if (!self::table_exists()) {
            self::$rows_cache = array();
            return self::$rows_cache;
        }
        self::$rows_cache = (array) $wpdb->get_results(
            "SELECT * FROM " . self::table() . " WHERE active=1 AND language='es' ORDER BY priority ASC,id ASC",
            ARRAY_A
        );
        return self::$rows_cache;
    }

    public static function stats() {
        global $wpdb;
        $stats = array(
            'ready' => self::table_exists(),
            'evidence_ready' => self::evidence_table_exists(),
            'active' => 0,
            'staged' => 0,
            'validated' => 0,
            'evidence' => 0,
            'linked_vocabulary' => 0,
            'lesson_i1' => 0,
        );
        if (!$stats['ready']) {
            return $stats;
        }
        $table = self::table();
        $stats['active'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active=1");
        $stats['staged'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active=0 AND source LIKE 'linguista%'");
        $stats['validated'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE validated=1");
        $stats['linked_vocabulary'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active=1 AND vocabulary_id IS NOT NULL AND vocabulary_id>0");
        $stats['lesson_i1'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active=1 AND lesson_key='i1_subject_action'");
        if ($stats['evidence_ready']) {
            $stats['evidence'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::evidence_table() . " WHERE active=1");
        }
        return $stats;
    }

    public static function normalize($value) {
        if (class_exists('SEO_Dependiente_Index')) {
            return SEO_Dependiente_Index::normalize((string) $value);
        }
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private static function clean_text($value) {
        $value = sanitize_text_field((string) $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private static function normalize_terms($terms) {
        return array_values(array_unique(array_filter(array_map(array(__CLASS__, 'normalize'), (array) $terms))));
    }

    private static function apply_context_match($row, $normalized_query) {
        $context_terms = json_decode((string) ($row['context_terms'] ?? ''), true);
        $context_terms = is_array($context_terms) ? self::normalize_terms($context_terms) : array();
        $matched_context = array();
        foreach ($context_terms as $context_term) {
            if (false !== strpos(' ' . $normalized_query . ' ', ' ' . $context_term . ' ')) {
                $matched_context[] = $context_term;
            }
        }
        if (!empty($row['context_required']) && !$matched_context) {
            return array();
        }
        $row['matched_context'] = $matched_context;
        return $row;
    }

    private static function sort_matches(&$matches) {
        usort($matches, static function ($a, $b) {
            $context_cmp = count((array) ($b['matched_context'] ?? array())) <=> count((array) ($a['matched_context'] ?? array()));
            if (0 !== $context_cmp) {
                return $context_cmp;
            }
            $len_cmp = strlen((string) ($b['normalized_expression'] ?? '')) <=> strlen((string) ($a['normalized_expression'] ?? ''));
            if (0 !== $len_cmp) {
                return $len_cmp;
            }
            $priority_cmp = (int) ($a['priority'] ?? 5) <=> (int) ($b['priority'] ?? 5);
            if (0 !== $priority_cmp) {
                return $priority_cmp;
            }
            return (float) ($b['confidence'] ?? 0) <=> (float) ($a['confidence'] ?? 0);
        });
    }

    private static function build_fuzzy_index() {
        if (is_array(self::$fuzzy_index)) {
            return;
        }
        self::$fuzzy_index = array();
        foreach (self::active_rows() as $row) {
            $expr = (string) ($row['normalized_expression'] ?? '');
            if ('' === $expr || false !== strpos($expr, ' ') || strlen($expr) < 5) {
                continue;
            }
            $first = substr($expr, 0, 1);
            $len = strlen($expr);
            if (!isset(self::$fuzzy_index[$first])) {
                self::$fuzzy_index[$first] = array();
            }
            if (!isset(self::$fuzzy_index[$first][$len])) {
                self::$fuzzy_index[$first][$len] = array();
            }
            self::$fuzzy_index[$first][$len][] = $row;
        }
    }

    private static function clear_cache() {
        self::$rows_cache = null;
        self::$fuzzy_index = null;
    }
}

<?php
/**
 * Solucionador - persistencia.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_DB {
    const VERSION_OPTION = 'seo_solucionador_db_version';

    public static function topics_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_solucionador_topics';
    }

    public static function evidence_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_solucionador_evidence';
    }

    public static function post_topics_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_solucionador_post_topics';
    }

    public static function table_exists($table) {
        global $wpdb;
        $table = (string) $table;
        if ($table === '') return false;
        return (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        ) === $table;
    }

    public static function maybe_install() {
        $installed = (string) get_option(self::VERSION_OPTION, '0');
        if (
            version_compare($installed, SEO_SOLUCIONADOR_DB_VERSION, '>=')
            && self::table_exists(self::topics_table())
            && self::table_exists(self::evidence_table())
            && self::table_exists(self::post_topics_table())
        ) {
            return true;
        }
        return self::install();
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();

        $topics = self::topics_table();
        $evidence = self::evidence_table();
        $post_topics = self::post_topics_table();

        $sql_topics = "CREATE TABLE {$topics} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            canonical_key VARCHAR(191) NOT NULL,
            canonical_question VARCHAR(500) NULL,
            intent VARCHAR(40) NULL,
            action_term VARCHAR(120) NULL,
            object_term VARCHAR(191) NULL,
            condition_term VARCHAR(191) NULL,
            context_term VARCHAR(191) NULL,
            suggested_title VARCHAR(500) NULL,
            proposed_vocabulary LONGTEXT NULL,
            proposed_categories LONGTEXT NULL,
            coverage_status VARCHAR(30) NOT NULL DEFAULT 'uncovered',
            existing_post_id BIGINT UNSIGNED NULL,
            draft_post_id BIGINT UNSIGNED NULL,
            recommended_action VARCHAR(40) NOT NULL DEFAULT 'observe',
            status VARCHAR(20) NOT NULL DEFAULT 'candidate',
            confidence DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
            priority_score DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            evidence_total INT UNSIGNED NOT NULL DEFAULT 0,
            interpreter_evidence INT UNSIGNED NOT NULL DEFAULT 0,
            comentarista_evidence INT UNSIGNED NOT NULL DEFAULT 0,
            analyst_evidence INT UNSIGNED NOT NULL DEFAULT 0,
            auditor_evidence INT UNSIGNED NOT NULL DEFAULT 0,
            zero_result_evidence INT UNSIGNED NOT NULL DEFAULT 0,
            negative_feedback_evidence INT UNSIGNED NOT NULL DEFAULT 0,
            first_seen_at DATETIME NULL,
            last_seen_at DATETIME NULL,
            last_analyzed_at DATETIME NULL,
            approved_at DATETIME NULL,
            draft_created_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY canonical_key (canonical_key),
            KEY status_priority (status, priority_score),
            KEY coverage_status (coverage_status),
            KEY object_action (object_term, action_term),
            KEY existing_post_id (existing_post_id),
            KEY draft_post_id (draft_post_id)
        ) {$collate};";

        $sql_evidence = "CREATE TABLE {$evidence} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            topic_id BIGINT UNSIGNED NOT NULL,
            source_type VARCHAR(30) NOT NULL,
            source_id VARCHAR(191) NULL,
            source_text TEXT NOT NULL,
            source_meta LONGTEXT NULL,
            occurrences INT UNSIGNED NOT NULL DEFAULT 1,
            evidence_score DECIMAL(6,3) NOT NULL DEFAULT 1.000,
            evidence_hash CHAR(64) NOT NULL,
            observed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY evidence_hash (evidence_hash),
            KEY topic_source (topic_id, source_type),
            KEY observed_at (observed_at)
        ) {$collate};";

        $sql_post_topics = "CREATE TABLE {$post_topics} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            scope VARCHAR(20) NOT NULL DEFAULT 'title',
            source_text VARCHAR(500) NOT NULL,
            canonical_key VARCHAR(191) NOT NULL,
            intent VARCHAR(40) NULL,
            action_term VARCHAR(120) NULL,
            object_term VARCHAR(191) NULL,
            condition_term VARCHAR(191) NULL,
            context_term VARCHAR(191) NULL,
            confidence DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
            source_hash CHAR(64) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY post_topic (post_id, canonical_key),
            KEY canonical_key (canonical_key),
            KEY object_action (object_term, action_term),
            KEY post_id (post_id)
        ) {$collate};";

        dbDelta($sql_topics);
        dbDelta($sql_evidence);
        dbDelta($sql_post_topics);

        update_option(self::VERSION_OPTION, SEO_SOLUCIONADOR_DB_VERSION, false);
        return true;
    }

    public static function register_data_layer($tables) {
        $tables = is_array($tables) ? $tables : array();
        $tables['solucionador_topics'] = array(
            'table' => self::topics_table(),
            'primary_key' => array('id'),
            'entity_type' => 'solution_topic',
        );
        $tables['solucionador_evidence'] = array(
            'table' => self::evidence_table(),
            'primary_key' => array('id'),
            'entity_type' => 'solution_evidence',
        );
        $tables['solucionador_post_topics'] = array(
            'table' => self::post_topics_table(),
            'primary_key' => array('id'),
            'entity_type' => 'solution_post_topic',
        );
        return $tables;
    }

    public static function decode_json($value, $default = array()) {
        if (is_array($value)) return $value;
        $value = trim((string) $value);
        if ($value === '') return $default;
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }

    public static function get_topic($topic_id) {
        global $wpdb;
        $topic_id = absint($topic_id);
        if (!$topic_id) return array();
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::topics_table() . ' WHERE id=%d LIMIT 1', $topic_id),
            ARRAY_A
        );
        return is_array($row) ? $row : array();
    }

    public static function get_evidence_rows($topic_id) {
        global $wpdb;
        $topic_id = absint($topic_id);
        if (!$topic_id) return array();
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::evidence_table() . ' WHERE topic_id=%d ORDER BY evidence_score DESC,occurrences DESC,id ASC',
                $topic_id
            ),
            ARRAY_A
        );
        foreach ($rows as &$row) {
            $row['source_meta_decoded'] = self::decode_json($row['source_meta'] ?? '', array());
        }
        unset($row);
        return $rows;
    }

    public static function update_topic($topic_id, array $changes) {
        global $wpdb;
        $topic_id = absint($topic_id);
        if (!$topic_id || !$changes) return false;
        $changes['updated_at'] = current_time('mysql');
        return false !== $wpdb->update(self::topics_table(), $changes, array('id' => $topic_id));
    }

    public static function upsert_topic(array $profile, $question = '') {
        global $wpdb;
        $key = sanitize_text_field((string) ($profile['canonical_key'] ?? ''));
        if ($key === '') return 0;

        $table = self::topics_table();
        $id = absint($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE canonical_key=%s LIMIT 1", $key)));
        $now = current_time('mysql');
        $row = array(
            'intent' => sanitize_key((string) ($profile['intent'] ?? '')),
            'action_term' => sanitize_text_field((string) ($profile['action'] ?? '')),
            'object_term' => sanitize_text_field((string) ($profile['object'] ?? '')),
            'condition_term' => sanitize_text_field((string) ($profile['condition'] ?? '')),
            'context_term' => sanitize_text_field((string) ($profile['context'] ?? '')),
            'confidence' => max(0, min(1, (float) ($profile['confidence'] ?? 0))),
            'last_seen_at' => $now,
            'updated_at' => $now,
        );
        $question = sanitize_text_field((string) $question);
        if ($question !== '') $row['canonical_question'] = $question;

        if ($id) {
            $wpdb->update($table, $row, array('id' => $id));
            return $id;
        }

        $row['canonical_key'] = $key;
        $row['canonical_question'] = $question;
        $row['first_seen_at'] = $now;
        $row['created_at'] = $now;
        $row['status'] = 'candidate';
        $row['coverage_status'] = 'uncovered';
        $row['recommended_action'] = 'observe';
        if (false === $wpdb->insert($table, $row)) return 0;
        return absint($wpdb->insert_id);
    }

    public static function add_evidence($topic_id, array $row) {
        global $wpdb;
        $topic_id = absint($topic_id);
        if (!$topic_id) return false;
        $source_type = sanitize_key((string) ($row['source_type'] ?? ''));
        $source_id = sanitize_text_field((string) ($row['source_id'] ?? ''));
        $source_text = trim(wp_strip_all_tags((string) ($row['source_text'] ?? '')));
        if ($source_type === '' || $source_text === '') return false;

        // source_id debe ser estable por evidencia logica. Asi un nuevo escaneo
        // actualiza ocurrencias/metadatos y no duplica acumulados historicos.
        $identity = $source_id !== ''
            ? $source_type . '|' . $source_id
            : $source_type . '|' . SEO_Solucionador_Normalizer::normalize($source_text);
        $hash = hash('sha256', $identity);
        $table = self::evidence_table();
        $payload = array(
            'topic_id' => $topic_id,
            'source_type' => $source_type,
            'source_id' => $source_id !== '' ? $source_id : null,
            'source_text' => $source_text,
            'source_meta' => wp_json_encode(
                (array) ($row['source_meta'] ?? array()),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'occurrences' => max(1, absint($row['occurrences'] ?? 1)),
            'evidence_score' => max(0, min(5, (float) ($row['evidence_score'] ?? 1))),
            'observed_at' => !empty($row['observed_at'])
                ? sanitize_text_field((string) $row['observed_at'])
                : current_time('mysql'),
        );

        $existing = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE evidence_hash=%s LIMIT 1",
            $hash
        )));
        if ($existing) {
            return false !== $wpdb->update($table, $payload, array('id' => $existing));
        }

        $payload['created_at'] = current_time('mysql');
        $payload['evidence_hash'] = $hash;
        return false !== $wpdb->insert($table, $payload);
    }

    public static function clear_post_topics() {
        global $wpdb;
        if (!self::table_exists(self::post_topics_table())) return false;
        return false !== $wpdb->query('TRUNCATE TABLE ' . self::post_topics_table());
    }

    public static function insert_post_topic($post_id, $scope, $text, array $profile) {
        global $wpdb;
        $post_id = absint($post_id);
        $key = sanitize_text_field((string) ($profile['canonical_key'] ?? ''));
        $text = trim(wp_strip_all_tags((string) $text));
        if (!$post_id || $key === '' || $text === '') return false;

        $table = self::post_topics_table();
        $existing = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id=%d AND canonical_key=%s LIMIT 1",
            $post_id,
            $key
        )));
        $row = array(
            'scope' => sanitize_key((string) $scope) ?: 'title',
            'source_text' => function_exists('mb_substr')
                ? mb_substr($text, 0, 500, 'UTF-8')
                : substr($text, 0, 500),
            'intent' => sanitize_key((string) ($profile['intent'] ?? '')),
            'action_term' => sanitize_text_field((string) ($profile['action'] ?? '')),
            'object_term' => sanitize_text_field((string) ($profile['object'] ?? '')),
            'condition_term' => sanitize_text_field((string) ($profile['condition'] ?? '')),
            'context_term' => sanitize_text_field((string) ($profile['context'] ?? '')),
            'confidence' => max(0, min(1, (float) ($profile['confidence'] ?? 0))),
            'source_hash' => hash('sha256', $text),
            'updated_at' => current_time('mysql'),
        );
        if ($existing) {
            return false !== $wpdb->update($table, $row, array('id' => $existing));
        }
        $row['post_id'] = $post_id;
        $row['canonical_key'] = $key;
        return false !== $wpdb->insert($table, $row);
    }

    public static function topic_stats($topic_id) {
        $rows = self::get_evidence_rows($topic_id);
        $out = array(
            'total' => 0,
            'dependiente' => 0,
            'comentarista' => 0,
            'analista' => 0,
            'auditor' => 0,
            'zero_results' => 0,
            'negative_feedback' => 0,
        );
        foreach ($rows as $row) {
            $type = sanitize_key((string) ($row['source_type'] ?? ''));
            $count = max(1, absint($row['occurrences'] ?? 1));
            $out['total'] += $count;
            if (isset($out[$type])) $out[$type] += $count;
            $meta = (array) ($row['source_meta_decoded'] ?? array());
            $out['zero_results'] += absint($meta['zero_results'] ?? 0);
            $out['negative_feedback'] += absint($meta['negative_feedback'] ?? 0);
        }
        return $out;
    }

    public static function proposed_vocabulary($topic) {
        return self::decode_json(is_array($topic) ? ($topic['proposed_vocabulary'] ?? '') : '', array());
    }

    public static function proposed_categories($topic) {
        return self::decode_json(is_array($topic) ? ($topic['proposed_categories'] ?? '') : '', array());
    }
}

add_filter('seo_data_layer_tables', array('SEO_Solucionador_DB', 'register_data_layer'));

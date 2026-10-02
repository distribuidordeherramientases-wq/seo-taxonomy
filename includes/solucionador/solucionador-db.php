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

    public static function coverage_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_solucionador_coverage';
    }

    public static function workflow_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_solucionador_workflow';
    }

    public static function tracking_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_solucionador_tracking';
    }

    public static function dossiers_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_solucionador_dossiers';
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
            && self::table_exists(self::coverage_table())
            && self::table_exists(self::workflow_table())
            && self::table_exists(self::tracking_table())
            && self::table_exists(self::dossiers_table())
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
        $coverage = self::coverage_table();
        $workflow = self::workflow_table();
        $tracking = self::tracking_table();
        $dossiers = self::dossiers_table();

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
            primary_category_id BIGINT UNSIGNED NULL,
            hierarchy_json LONGTEXT NULL,
            coverage_status VARCHAR(30) NOT NULL DEFAULT 'uncovered',
            coverage_score DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
            existing_entity_type VARCHAR(30) NULL,
            existing_entity_id BIGINT UNSIGNED NULL,
            existing_post_id BIGINT UNSIGNED NULL,
            draft_post_id BIGINT UNSIGNED NULL,
            recommended_action VARCHAR(40) NOT NULL DEFAULT 'DEFER',
            decision_reason TEXT NULL,
            decision_requirements LONGTEXT NULL,
            priority_components LONGTEXT NULL,
            duplication_risk DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            cannibalization_risk DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            knowledge_status VARCHAR(30) NOT NULL DEFAULT 'unknown',
            workflow_state VARCHAR(30) NOT NULL DEFAULT 'detected',
            content_type VARCHAR(30) NOT NULL DEFAULT 'none',
            status VARCHAR(20) NOT NULL DEFAULT 'candidate',
            confidence DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
            priority_score DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            evidence_total INT UNSIGNED NOT NULL DEFAULT 0,
            dossier_question_count INT UNSIGNED NOT NULL DEFAULT 0,
            dossier_question_ids LONGTEXT NULL,
            dossier_score DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
            dossier_last_validated_at DATETIME NULL,
            dossier_hash CHAR(64) NULL,
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
            KEY primary_category_id (primary_category_id),
            KEY existing_entity (existing_entity_type, existing_entity_id),
            KEY workflow_state (workflow_state),
            KEY recommended_action (recommended_action),
            KEY existing_post_id (existing_post_id),
            KEY draft_post_id (draft_post_id)
        ) {$collate};";

        $sql_evidence = "CREATE TABLE {$evidence} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            topic_id BIGINT UNSIGNED NOT NULL,
            source_type VARCHAR(30) NOT NULL,
            source_id VARCHAR(191) NULL,
            signal_type VARCHAR(40) NULL,
            entity_type VARCHAR(30) NULL,
            entity_id BIGINT UNSIGNED NULL,
            category_id BIGINT UNSIGNED NULL,
            source_text TEXT NOT NULL,
            source_meta LONGTEXT NULL,
            occurrences INT UNSIGNED NOT NULL DEFAULT 1,
            confidence DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
            evidence_score DECIMAL(6,3) NOT NULL DEFAULT 1.000,
            evidence_hash CHAR(64) NOT NULL,
            observed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY evidence_hash (evidence_hash),
            KEY topic_source (topic_id, source_type),
            KEY category_id (category_id),
            KEY entity (entity_type, entity_id),
            KEY observed_at (observed_at)
        ) {$collate};";

        $sql_dossiers = "CREATE TABLE {$dossiers} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id BIGINT UNSIGNED NOT NULL,
            category_name VARCHAR(255) NOT NULL,
            question_count INT UNSIGNED NOT NULL DEFAULT 0,
            question_ids LONGTEXT NULL,
            score_avg DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
            last_validated_at DATETIME NULL,
            source_hash CHAR(64) NOT NULL DEFAULT '',
            scan_token VARCHAR(64) NOT NULL DEFAULT '',
            demand_occurrences INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY category_id (category_id),
            KEY scan_token (scan_token),
            KEY question_count (question_count),
            KEY last_validated_at (last_validated_at)
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

        $sql_coverage = "CREATE TABLE {$coverage} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(30) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            seo_role VARCHAR(40) NULL,
            category_id BIGINT UNSIGNED NULL,
            scope VARCHAR(30) NOT NULL DEFAULT 'title',
            title VARCHAR(500) NULL,
            url TEXT NULL,
            source_text LONGTEXT NOT NULL,
            canonical_key VARCHAR(191) NOT NULL,
            intent VARCHAR(40) NULL,
            action_term VARCHAR(120) NULL,
            object_term VARCHAR(191) NULL,
            condition_term VARCHAR(191) NULL,
            context_term VARCHAR(191) NULL,
            vocabulary_text LONGTEXT NULL,
            confidence DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
            source_hash CHAR(64) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY entity_topic (entity_type, entity_id, scope, canonical_key),
            KEY canonical_key (canonical_key),
            KEY category_id (category_id),
            KEY object_action (object_term, action_term),
            KEY entity (entity_type, entity_id)
        ) {$collate};";

        $sql_workflow = "CREATE TABLE {$workflow} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            topic_id BIGINT UNSIGNED NOT NULL,
            from_state VARCHAR(30) NULL,
            to_state VARCHAR(30) NOT NULL,
            action_code VARCHAR(40) NULL,
            reason TEXT NULL,
            user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY topic_id (topic_id),
            KEY to_state (to_state),
            KEY created_at (created_at)
        ) {$collate};";

        $sql_tracking = "CREATE TABLE {$tracking} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            topic_id BIGINT UNSIGNED NOT NULL,
            entity_type VARCHAR(30) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            source VARCHAR(30) NOT NULL DEFAULT 'analista',
            period_days INT UNSIGNED NOT NULL DEFAULT 28,
            impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
            clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
            ctr DECIMAL(10,6) NOT NULL DEFAULT 0,
            position DECIMAL(10,4) NOT NULL DEFAULT 0,
            sessions BIGINT UNSIGNED NOT NULL DEFAULT 0,
            pageviews BIGINT UNSIGNED NOT NULL DEFAULT 0,
            outcome_state VARCHAR(30) NULL,
            payload_json LONGTEXT NULL,
            snapshot_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY topic_snapshot (topic_id, snapshot_at),
            KEY entity (entity_type, entity_id)
        ) {$collate};";

        dbDelta($sql_topics);
        dbDelta($sql_evidence);
        dbDelta($sql_post_topics);
        dbDelta($sql_coverage);
        dbDelta($sql_workflow);
        dbDelta($sql_tracking);
        dbDelta($sql_dossiers);

        // Compatibilidad con decisiones previas a RF v1.0.
        $wpdb->query("UPDATE {$topics} SET recommended_action='CREATE_POST' WHERE recommended_action='create_post'");
        $wpdb->query("UPDATE {$topics} SET recommended_action='CREATE_LANDING' WHERE recommended_action='create_landing'");
        $wpdb->query("UPDATE {$topics} SET recommended_action='IMPROVE_POST' WHERE recommended_action IN ('expand_existing_post','create_section')");
        $wpdb->query("UPDATE {$topics} SET recommended_action='NO_ACTION' WHERE recommended_action='no_action'");
        $wpdb->query("UPDATE {$topics} SET recommended_action='DEFER' WHERE recommended_action='observe'");
        $wpdb->query("UPDATE {$topics} SET workflow_state='candidate' WHERE status='candidate' AND workflow_state='detected'");
        $wpdb->query("UPDATE {$topics} SET workflow_state='approved' WHERE status='approved'");
        $wpdb->query("UPDATE {$topics} SET workflow_state='in_editing' WHERE status='draft_created'");
        $wpdb->query("UPDATE {$topics} SET workflow_state='published' WHERE status='covered'");

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
        $tables['solucionador_coverage'] = array(
            'table' => self::coverage_table(),
            'primary_key' => array('id'),
            'entity_type' => 'solution_coverage',
        );
        $tables['solucionador_workflow'] = array(
            'table' => self::workflow_table(),
            'primary_key' => array('id'),
            'entity_type' => 'solution_workflow',
        );
        $tables['solucionador_tracking'] = array(
            'table' => self::tracking_table(),
            'primary_key' => array('id'),
            'entity_type' => 'solution_tracking',
        );
        $tables['solucionador_dossiers'] = array(
            'table' => self::dossiers_table(),
            'primary_key' => array('id'),
            'entity_type' => 'solution_category_dossier',
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
        $topics_table = self::topics_table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$topics_table} WHERE id=%d LIMIT 1", $topic_id),
            ARRAY_A
        );
        return is_array($row) ? $row : array();
    }


    public static function get_topic_id_by_key($canonical_key) {
        global $wpdb;
        $canonical_key = sanitize_text_field((string) $canonical_key);
        if ($canonical_key === '') return 0;
        $topics_table = self::topics_table();
        return absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$topics_table} WHERE canonical_key=%s LIMIT 1",
            $canonical_key
        )));
    }

    /**
     * Evidencias y clasificacion editorial son datos derivados. Se reconstruyen
     * en cada escaneo para que un cambio de reglas no deje propuestas obsoletas.
     */
    public static function begin_scan() {
        global $wpdb;
        $evidence_table = self::evidence_table();
        if (self::table_exists($evidence_table)) {
            $wpdb->query("TRUNCATE TABLE {$evidence_table}");
        }
        return true;
    }

    /**
     * Elimina temas automaticos que ya no tienen ninguna evidencia vigente.
     * Conserva cualquier tema que ya tenga un borrador/post asociado.
     */
    public static function prune_orphan_topics() {
        global $wpdb;
        $topics = self::topics_table();
        $evidence = self::evidence_table();
        if (!self::table_exists($topics) || !self::table_exists($evidence)) return 0;
        $sql = "DELETE t FROM {$topics} t
                LEFT JOIN {$evidence} e ON e.topic_id=t.id
                WHERE e.id IS NULL
                  AND COALESCE(t.draft_post_id,0)=0";
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Query contains only internal table identifiers and fixed predicates.
        $result = $wpdb->query($sql);
        return $result === false ? 0 : absint($result);
    }

    public static function get_evidence_rows($topic_id) {
        global $wpdb;
        $topic_id = absint($topic_id);
        if (!$topic_id) return array();
        $evidence_table = self::evidence_table();
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$evidence_table} WHERE topic_id=%d ORDER BY evidence_score DESC,occurrences DESC,id ASC",
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
        $row['workflow_state'] = 'detected';
        $row['coverage_status'] = 'uncovered';
        $row['recommended_action'] = 'DEFER';
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
        $meta = (array) ($row['source_meta'] ?? array());
        $payload = array(
            'topic_id' => $topic_id,
            'source_type' => $source_type,
            'source_id' => $source_id !== '' ? $source_id : null,
            'signal_type' => sanitize_key((string) ($row['signal_type'] ?? $meta['signal_type'] ?? '')),
            'entity_type' => sanitize_key((string) ($row['entity_type'] ?? $meta['entity_type'] ?? '')),
            'entity_id' => absint($row['entity_id'] ?? $meta['entity_id'] ?? 0) ?: null,
            'category_id' => absint($row['category_id'] ?? $meta['category_id'] ?? $meta['term_id'] ?? 0) ?: null,
            'source_text' => $source_text,
            'source_meta' => wp_json_encode(
                $meta,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'occurrences' => max(1, absint($row['occurrences'] ?? 1)),
            'confidence' => max(0, min(1, (float) ($row['confidence'] ?? $meta['confidence'] ?? 0.6))),
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
        $post_topics_table = self::post_topics_table();
        if (!self::table_exists($post_topics_table)) return false;
        return false !== $wpdb->query("TRUNCATE TABLE {$post_topics_table}");
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


    public static function clear_coverage_index() {
        global $wpdb;
        $coverage_table = self::coverage_table();
        if (!self::table_exists($coverage_table)) return false;
        return false !== $wpdb->query("TRUNCATE TABLE {$coverage_table}");
    }

    public static function insert_coverage_item(array $row) {
        global $wpdb;
        $entity_type = sanitize_key((string) ($row['entity_type'] ?? ''));
        $entity_id = absint($row['entity_id'] ?? 0);
        $scope = sanitize_key((string) ($row['scope'] ?? 'title')) ?: 'title';
        $profile = is_array($row['profile'] ?? null) ? $row['profile'] : array();
        $key = sanitize_text_field((string) ($profile['canonical_key'] ?? ''));
        $text = trim(wp_strip_all_tags((string) ($row['source_text'] ?? '')));
        if ($entity_type === '' || !$entity_id || $key === '' || $text === '') return false;

        $table = self::coverage_table();
        $existing = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE entity_type=%s AND entity_id=%d AND scope=%s AND canonical_key=%s LIMIT 1",
            $entity_type, $entity_id, $scope, $key
        )));
        $payload = array(
            'entity_type'=>$entity_type,
            'entity_id'=>$entity_id,
            'seo_role'=>sanitize_key((string) ($row['seo_role'] ?? '')),
            'category_id'=>absint($row['category_id'] ?? 0) ?: null,
            'scope'=>$scope,
            'title'=>sanitize_text_field((string) ($row['title'] ?? '')),
            'url'=>esc_url_raw((string) ($row['url'] ?? '')),
            'source_text'=>$text,
            'canonical_key'=>$key,
            'intent'=>sanitize_key((string) ($profile['intent'] ?? '')),
            'action_term'=>sanitize_text_field((string) ($profile['action'] ?? '')),
            'object_term'=>sanitize_text_field((string) ($profile['object'] ?? '')),
            'condition_term'=>sanitize_text_field((string) ($profile['condition'] ?? '')),
            'context_term'=>sanitize_text_field((string) ($profile['context'] ?? '')),
            'vocabulary_text'=>sanitize_textarea_field((string) ($row['vocabulary_text'] ?? '')),
            'confidence'=>max(0,min(1,(float) ($profile['confidence'] ?? 0))),
            'source_hash'=>hash('sha256',$entity_type . '|' . $entity_id . '|' . $scope . '|' . $text),
            'updated_at'=>current_time('mysql'),
        );
        if ($existing) return false !== $wpdb->update($table,$payload,array('id'=>$existing));
        return false !== $wpdb->insert($table,$payload);
    }

    public static function workflow_states() {
        return array('detected','validated','candidate','approved','brief_ready','in_editing','scheduled','published','monitoring','closed','rejected','deferred');
    }

    public static function record_workflow($topic_id, $to_state, $reason = '', $action_code = '', $user_id = null) {
        global $wpdb;
        $topic_id = absint($topic_id);
        $to_state = sanitize_key((string) $to_state);
        if (!$topic_id || !in_array($to_state, self::workflow_states(), true)) return false;
        $topic = self::get_topic($topic_id);
        if (!$topic) return false;
        $from_state = sanitize_key((string) ($topic['workflow_state'] ?? 'detected'));
        $user_id = null === $user_id ? get_current_user_id() : absint($user_id);
        $ok = $wpdb->insert(self::workflow_table(), array(
            'topic_id'=>$topic_id,
            'from_state'=>$from_state ?: null,
            'to_state'=>$to_state,
            'action_code'=>sanitize_text_field((string) $action_code),
            'reason'=>sanitize_textarea_field((string) $reason),
            'user_id'=>$user_id ?: null,
            'created_at'=>current_time('mysql'),
        ));
        if (false === $ok) return false;
        self::update_topic($topic_id, array('workflow_state'=>$to_state));
        return true;
    }

    public static function workflow_history($topic_id, $limit = 100) {
        global $wpdb;
        $topic_id = absint($topic_id);
        $limit = max(1,min(500,absint($limit)));
        $workflow_table = self::workflow_table();
        if (!$topic_id || !self::table_exists($workflow_table)) return array();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$workflow_table} WHERE topic_id=%d ORDER BY id DESC LIMIT %d",
            $topic_id,$limit
        ),ARRAY_A);
    }

    public static function add_tracking_snapshot($topic_id, array $row) {
        global $wpdb;
        $topic_id = absint($topic_id);
        $entity_type = sanitize_key((string) ($row['entity_type'] ?? ''));
        $entity_id = absint($row['entity_id'] ?? 0);
        if (!$topic_id || $entity_type === '' || !$entity_id) return false;

        $source = sanitize_key((string) ($row['source'] ?? 'analista')) ?: 'analista';
        $period_days = max(1,absint($row['period_days'] ?? 28));
        $snapshot_at = sanitize_text_field((string) ($row['snapshot_at'] ?? current_time('mysql')));
        if ($snapshot_at === '') $snapshot_at = current_time('mysql');

        $payload = array(
            'topic_id'=>$topic_id,
            'entity_type'=>$entity_type,
            'entity_id'=>$entity_id,
            'source'=>$source,
            'period_days'=>$period_days,
            'impressions'=>max(0,(int) ($row['impressions'] ?? 0)),
            'clicks'=>max(0,(int) ($row['clicks'] ?? 0)),
            'ctr'=>max(0,(float) ($row['ctr'] ?? 0)),
            'position'=>max(0,(float) ($row['position'] ?? 0)),
            'sessions'=>max(0,(int) ($row['sessions'] ?? 0)),
            'pageviews'=>max(0,(int) ($row['pageviews'] ?? 0)),
            'outcome_state'=>sanitize_key((string) ($row['outcome_state'] ?? '')),
            'payload_json'=>wp_json_encode((array) ($row['payload'] ?? array()),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'snapshot_at'=>$snapshot_at,
        );

        $table = self::tracking_table();
        // Una reejecucion del mismo dia actualiza el snapshot en lugar de
        // acumular filas identicas. Conservamos historial diario util.
        $existing_id = absint($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE topic_id=%d AND entity_type=%s AND entity_id=%d
               AND source=%s AND period_days=%d
               AND DATE(snapshot_at)=DATE(%s)
             ORDER BY id DESC LIMIT 1",
            $topic_id,$entity_type,$entity_id,$source,$period_days,$snapshot_at
        )));
        if ($existing_id) {
            return false !== $wpdb->update($table,$payload,array('id'=>$existing_id));
        }
        return false !== $wpdb->insert($table,$payload);
    }

    public static function tracking_history($topic_id, $limit = 24) {
        global $wpdb;
        $topic_id = absint($topic_id);
        $limit = max(1,min(200,absint($limit)));
        $tracking_table = self::tracking_table();
        if (!$topic_id || !self::table_exists($tracking_table)) return array();
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tracking_table} WHERE topic_id=%d ORDER BY snapshot_at DESC,id DESC LIMIT %d",
            $topic_id,$limit
        ),ARRAY_A);
    }

    public static function priority_components($topic) {
        return self::decode_json(is_array($topic) ? ($topic['priority_components'] ?? '') : '', array());
    }

    public static function decision_requirements($topic) {
        return self::decode_json(is_array($topic) ? ($topic['decision_requirements'] ?? '') : '', array());
    }

    public static function hierarchy($topic) {
        return self::decode_json(is_array($topic) ? ($topic['hierarchy_json'] ?? '') : '', array());
    }

    public static function topic_stats($topic_id) {
        $rows = self::get_evidence_rows($topic_id);
        $out = array(
            'total' => 0,
            'dependiente' => 0,
            'comentarista' => 0,
            'analista' => 0,
            'auditor' => 0,
            'ojeador' => 0,
            'ingeniero' => 0,
            'clasificador' => 0,
            'comparador' => 0,
            'marketing' => 0,
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

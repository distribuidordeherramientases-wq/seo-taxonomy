<?php
/**
 * Solucionador - exportacion JSON de resultados.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Export {
    const SCHEMA = 'seo-solucionador-export-v1';

    public static function init() {
        add_action('admin_post_seo_solucionador_export_json', array(__CLASS__, 'download'));
    }

    public static function download() {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para exportar Solucionador.');
        }
        check_admin_referer('seo_solucionador_export_json');
        SEO_Solucionador_DB::maybe_install();

        $payload = self::build_payload();
        $json = wp_json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($json)) {
            wp_die('No se pudo generar el JSON de Solucionador.');
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $filename = sanitize_file_name('seo-solucionador-' . wp_date('Ymd-His') . '.json');
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    public static function build_payload() {
        global $wpdb;

        $topics_table = SEO_Solucionador_DB::topics_table();
        $evidence_table = SEO_Solucionador_DB::evidence_table();
        $post_topics_table = SEO_Solucionador_DB::post_topics_table();

        $topics = SEO_Solucionador_DB::table_exists($topics_table)
            ? (array) $wpdb->get_results(
                "SELECT * FROM {$topics_table} ORDER BY priority_score DESC,evidence_total DESC,id ASC",
                ARRAY_A
            )
            : array();

        $evidence_rows = SEO_Solucionador_DB::table_exists($evidence_table)
            ? (array) $wpdb->get_results(
                "SELECT * FROM {$evidence_table} ORDER BY topic_id ASC,evidence_score DESC,occurrences DESC,id ASC",
                ARRAY_A
            )
            : array();

        $evidence_by_topic = array();
        foreach ($evidence_rows as $row) {
            $topic_id = absint($row['topic_id'] ?? 0);
            if (!$topic_id) continue;
            if (!isset($evidence_by_topic[$topic_id])) $evidence_by_topic[$topic_id] = array();
            $evidence_by_topic[$topic_id][] = self::normalize_evidence($row);
        }

        $topic_rows = array();
        foreach ($topics as $topic) {
            $topic_rows[] = self::normalize_topic(
                $topic,
                (array) ($evidence_by_topic[absint($topic['id'] ?? 0)] ?? array())
            );
        }

        $coverage_rows = array();
        if (SEO_Solucionador_DB::table_exists($post_topics_table)) {
            $coverage_raw = (array) $wpdb->get_results(
                "SELECT pt.*,p.post_title,p.post_status,p.post_modified_gmt
                 FROM {$post_topics_table} pt
                 LEFT JOIN {$wpdb->posts} p ON p.ID=pt.post_id
                 ORDER BY pt.post_id ASC,pt.scope ASC,pt.id ASC",
                ARRAY_A
            );
            foreach ($coverage_raw as $row) {
                $coverage_rows[] = self::normalize_coverage($row);
            }
        }

        return array(
            'schema' => self::SCHEMA,
            'generated_at' => current_time('mysql'),
            'generated_at_gmt' => current_time('mysql', true),
            'site' => array(
                'home_url' => home_url('/'),
                'solucionador_version' => defined('SEO_SOLUCIONADOR_VERSION') ? SEO_SOLUCIONADOR_VERSION : '',
                'db_version' => (string) get_option(SEO_Solucionador_DB::VERSION_OPTION, ''),
            ),
            'last_scan' => (array) get_option('seo_solucionador_last_scan', array()),
            'summary' => self::summary($topics_table, $evidence_table, $post_topics_table),
            'topics' => $topic_rows,
            'editorial_coverage' => $coverage_rows,
        );
    }

    private static function normalize_topic(array $row, array $evidence) {
        $id = absint($row['id'] ?? 0);
        $existing_post_id = absint($row['existing_post_id'] ?? 0);
        $draft_post_id = absint($row['draft_post_id'] ?? 0);

        return array(
            'id' => $id,
            'canonical_key' => (string) ($row['canonical_key'] ?? ''),
            'canonical_question' => (string) ($row['canonical_question'] ?? ''),
            'profile' => array(
                'intent' => (string) ($row['intent'] ?? ''),
                'action' => (string) ($row['action_term'] ?? ''),
                'object' => (string) ($row['object_term'] ?? ''),
                'condition' => (string) ($row['condition_term'] ?? ''),
                'context' => (string) ($row['context_term'] ?? ''),
                'confidence' => (float) ($row['confidence'] ?? 0),
            ),
            'proposal' => array(
                'suggested_title' => (string) ($row['suggested_title'] ?? ''),
                'recommended_action' => (string) ($row['recommended_action'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'priority_score' => (float) ($row['priority_score'] ?? 0),
                'vocabulary' => SEO_Solucionador_DB::proposed_vocabulary($row),
                'categories' => SEO_Solucionador_DB::proposed_categories($row),
            ),
            'coverage' => array(
                'status' => (string) ($row['coverage_status'] ?? ''),
                'existing_post' => self::post_info($existing_post_id),
                'draft_post' => self::post_info($draft_post_id),
            ),
            'evidence_summary' => array(
                'total' => absint($row['evidence_total'] ?? 0),
                'dependiente_interprete' => absint($row['interpreter_evidence'] ?? 0),
                'comentarista' => absint($row['comentarista_evidence'] ?? 0),
                'analista' => absint($row['analyst_evidence'] ?? 0),
                'auditor' => absint($row['auditor_evidence'] ?? 0),
                'zero_results' => absint($row['zero_result_evidence'] ?? 0),
                'negative_feedback' => absint($row['negative_feedback_evidence'] ?? 0),
            ),
            'evidence' => $evidence,
            'dates' => array(
                'first_seen_at' => self::nullable_string($row['first_seen_at'] ?? null),
                'last_seen_at' => self::nullable_string($row['last_seen_at'] ?? null),
                'last_analyzed_at' => self::nullable_string($row['last_analyzed_at'] ?? null),
                'approved_at' => self::nullable_string($row['approved_at'] ?? null),
                'draft_created_at' => self::nullable_string($row['draft_created_at'] ?? null),
                'created_at' => self::nullable_string($row['created_at'] ?? null),
                'updated_at' => self::nullable_string($row['updated_at'] ?? null),
            ),
        );
    }

    private static function normalize_evidence(array $row) {
        return array(
            'id' => absint($row['id'] ?? 0),
            'source_type' => (string) ($row['source_type'] ?? ''),
            'source_id' => self::nullable_string($row['source_id'] ?? null),
            'source_text' => (string) ($row['source_text'] ?? ''),
            'source_meta' => SEO_Solucionador_DB::decode_json($row['source_meta'] ?? '', array()),
            'occurrences' => max(1, absint($row['occurrences'] ?? 1)),
            'evidence_score' => (float) ($row['evidence_score'] ?? 0),
            'observed_at' => self::nullable_string($row['observed_at'] ?? null),
            'created_at' => self::nullable_string($row['created_at'] ?? null),
        );
    }

    private static function normalize_coverage(array $row) {
        $post_id = absint($row['post_id'] ?? 0);
        return array(
            'id' => absint($row['id'] ?? 0),
            'post' => array(
                'id' => $post_id,
                'title' => (string) ($row['post_title'] ?? ''),
                'status' => (string) ($row['post_status'] ?? ''),
                'url' => $post_id ? (string) get_permalink($post_id) : '',
                'edit_url' => $post_id ? (string) get_edit_post_link($post_id, 'raw') : '',
                'modified_gmt' => self::nullable_string($row['post_modified_gmt'] ?? null),
            ),
            'scope' => (string) ($row['scope'] ?? ''),
            'source_text' => (string) ($row['source_text'] ?? ''),
            'canonical_key' => (string) ($row['canonical_key'] ?? ''),
            'profile' => array(
                'intent' => (string) ($row['intent'] ?? ''),
                'action' => (string) ($row['action_term'] ?? ''),
                'object' => (string) ($row['object_term'] ?? ''),
                'condition' => (string) ($row['condition_term'] ?? ''),
                'context' => (string) ($row['context_term'] ?? ''),
                'confidence' => (float) ($row['confidence'] ?? 0),
            ),
            'updated_at' => self::nullable_string($row['updated_at'] ?? null),
        );
    }

    private static function post_info($post_id) {
        $post_id = absint($post_id);
        if (!$post_id) return null;
        $post = get_post($post_id);
        if (!$post) {
            return array('id' => $post_id, 'missing' => true);
        }
        return array(
            'id' => $post_id,
            'title' => (string) $post->post_title,
            'status' => (string) $post->post_status,
            'url' => (string) get_permalink($post_id),
            'edit_url' => (string) get_edit_post_link($post_id, 'raw'),
        );
    }

    private static function summary($topics_table, $evidence_table, $post_topics_table) {
        global $wpdb;
        $summary = array(
            'topics_total' => 0,
            'evidence_occurrences_total' => 0,
            'editorial_coverage_rows' => 0,
            'by_status' => array(),
            'by_coverage' => array(),
            'by_recommended_action' => array(),
            'by_source' => array(),
        );

        if (SEO_Solucionador_DB::table_exists($topics_table)) {
            $summary['topics_total'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$topics_table}"));
            $summary['by_status'] = self::grouped_counts($topics_table, 'status');
            $summary['by_coverage'] = self::grouped_counts($topics_table, 'coverage_status');
            $summary['by_recommended_action'] = self::grouped_counts($topics_table, 'recommended_action');
        }

        if (SEO_Solucionador_DB::table_exists($evidence_table)) {
            $summary['evidence_occurrences_total'] = absint($wpdb->get_var("SELECT COALESCE(SUM(occurrences),0) FROM {$evidence_table}"));
            $rows = (array) $wpdb->get_results(
                "SELECT source_type,COALESCE(SUM(occurrences),0) total FROM {$evidence_table} GROUP BY source_type ORDER BY total DESC,source_type ASC",
                ARRAY_A
            );
            foreach ($rows as $row) {
                $summary['by_source'][(string) ($row['source_type'] ?? '')] = absint($row['total'] ?? 0);
            }
        }

        if (SEO_Solucionador_DB::table_exists($post_topics_table)) {
            $summary['editorial_coverage_rows'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$post_topics_table}"));
        }
        return $summary;
    }

    private static function grouped_counts($table, $column) {
        global $wpdb;
        $allowed = array('status', 'coverage_status', 'recommended_action');
        if (!in_array($column, $allowed, true)) return array();
        $rows = (array) $wpdb->get_results(
            "SELECT {$column} value,COUNT(*) total FROM {$table} GROUP BY {$column} ORDER BY total DESC,value ASC",
            ARRAY_A
        );
        $out = array();
        foreach ($rows as $row) {
            $out[(string) ($row['value'] ?? '')] = absint($row['total'] ?? 0);
        }
        return $out;
    }

    private static function nullable_string($value) {
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}

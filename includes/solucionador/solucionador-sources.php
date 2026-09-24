<?php
/**
 * Solucionador - adaptadores de fuentes locales.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Sources {
    private static function table_exists($table) {
        return SEO_Solucionador_DB::table_exists($table);
    }

    private static function decode($value) {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }

    public static function dependiente($days = 180, $limit = 1400) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_dependiente_search_log';
        if (!self::table_exists($table)) return array();

        $days = min(365, max(7, absint($days)));
        $limit = min(3000, max(50, absint($limit)));

        $sql = "SELECT
                    s.id source_id,
                    s.query_original source_text,
                    s.query_normalized normalized_text,
                    s.detected_intent,
                    s.detected_object,
                    s.detected_context,
                    s.detected_state,
                    s.clicked_product_id,
                    s.top_results,
                    s.semantic_analysis,
                    s.created_at observed_at,
                    a.occurrences,
                    a.zero_results,
                    a.negative_feedback
                FROM {$table} s
                INNER JOIN (
                    SELECT
                        MAX(id) last_id,
                        COUNT(*) occurrences,
                        SUM(CASE WHEN result_count=0 THEN 1 ELSE 0 END) zero_results,
                        SUM(CASE WHEN feedback<0 THEN 1 ELSE 0 END) negative_feedback
                    FROM {$table}
                    WHERE request_kind='search'
                      AND created_at >= DATE_SUB(%s, INTERVAL %d DAY)
                    GROUP BY COALESCE(NULLIF(semantic_signature,''),query_hash),
                             COALESCE(detected_intent,''),COALESCE(detected_object,''),
                             COALESCE(detected_context,''),COALESCE(detected_state,'')
                ) a ON a.last_id=s.id
                ORDER BY a.occurrences DESC,a.zero_results DESC,a.negative_feedback DESC,s.id DESC
                LIMIT %d";

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare($sql, current_time('mysql'), $days, $limit),
            ARRAY_A
        );

        $out = array();
        foreach ($rows as $row) {
            $text = (string) ($row['source_text'] ?? '');
            if (!SEO_Solucionador_Normalizer::is_solution_signal(
                $text,
                (string) ($row['detected_intent'] ?? ''),
                (string) ($row['detected_state'] ?? '')
            )) {
                continue;
            }

            $semantic = self::decode($row['semantic_analysis'] ?? '');
            $matches = array_values(array_slice((array) ($semantic['matches'] ?? array()), 0, 24));
            $top_results = array_values(array_slice(self::decode($row['top_results'] ?? ''), 0, 12));

            $out[] = array(
                'source_type' => 'dependiente',
                'source_id' => 'search:' . md5(implode('|', array(
                    (string) ($row['normalized_text'] ?? $text),
                    (string) ($row['detected_intent'] ?? ''),
                    (string) ($row['detected_object'] ?? ''),
                    (string) ($row['detected_context'] ?? ''),
                    (string) ($row['detected_state'] ?? ''),
                ))),
                'source_text' => $text,
                'hints' => array(
                    'intent' => (string) ($row['detected_intent'] ?? ''),
                    'object' => (string) ($row['detected_object'] ?? ''),
                    'context' => (string) ($row['detected_context'] ?? ''),
                    'state' => (string) ($row['detected_state'] ?? ''),
                ),
                'occurrences' => max(1, absint($row['occurrences'] ?? 1)),
                'evidence_score' => 1.00,
                'observed_at' => (string) ($row['observed_at'] ?? ''),
                'source_meta' => array(
                    'last_log_id' => absint($row['source_id'] ?? 0),
                    'zero_results' => absint($row['zero_results'] ?? 0),
                    'negative_feedback' => absint($row['negative_feedback'] ?? 0),
                    'clicked_product_id' => absint($row['clicked_product_id'] ?? 0),
                    'top_results' => $top_results,
                    'semantic_matches' => $matches,
                ),
            );
        }
        return $out;
    }

    public static function comentarista($limit = 1200) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_comentarista';
        if (!self::table_exists($table)) return array();

        $limit = min(2500, max(50, absint($limit)));
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT c.id,c.product_id,c.content_type,c.source_name,c.source_title,c.source_content,c.editorial_summary,
                    c.source_published_at,c.captured_at,p.post_title product_title
             FROM {$table} c
             LEFT JOIN {$wpdb->posts} p ON p.ID=c.product_id AND p.post_type='product'
             WHERE c.status='published'
               AND c.content_type IN ('comment','article','social_post')
               AND (c.editorial_summary IS NOT NULL OR c.source_content IS NOT NULL)
             ORDER BY c.id DESC LIMIT %d",
            $limit
        ), ARRAY_A);

        $out = array();
        foreach ($rows as $row) {
            $blob = trim((string) ($row['editorial_summary'] ?? '') . ' ' . (string) ($row['source_content'] ?? ''));
            foreach (SEO_Solucionador_Normalizer::extract_problem_sentences($blob, 3) as $index => $sentence) {
                $out[] = array(
                    'source_type' => 'comentarista',
                    'source_id' => (string) absint($row['id'] ?? 0) . ':' . ($index + 1),
                    'source_text' => $sentence,
                    'hints' => array(),
                    'occurrences' => 1,
                    'evidence_score' => 0.60,
                    'observed_at' => (string) (($row['source_published_at'] ?? '') ?: ($row['captured_at'] ?? '')),
                    'source_meta' => array(
                        'product_id' => absint($row['product_id'] ?? 0),
                        'product_title' => (string) ($row['product_title'] ?? ''),
                        'source_name' => (string) ($row['source_name'] ?? ''),
                        'source_title' => (string) ($row['source_title'] ?? ''),
                    ),
                );
            }
        }
        return $out;
    }

    public static function analista($days = 90, $limit = 140) {
        $out = array();
        $days = min(365, max(7, absint($days)));
        $limit = min(250, max(20, absint($limit)));

        if (function_exists('seo_analista_internal_search_snapshot')) {
            $snapshot = seo_analista_internal_search_snapshot($days, $limit);
            foreach ((array) ($snapshot['top'] ?? array()) as $i => $row) {
                $text = (string) ($row['search_term'] ?? '');
                if (!SEO_Solucionador_Normalizer::is_solution_signal($text)) continue;
                $out[] = array(
                    'source_type' => 'analista',
                    'source_id' => 'search:' . md5((string) ($row['normalized_term'] ?? SEO_Solucionador_Normalizer::normalize($text))),
                    'source_text' => $text,
                    'hints' => array(),
                    'occurrences' => max(1, absint($row['searches'] ?? 1)),
                    'evidence_score' => 0.75,
                    'observed_at' => (string) ($row['last_search'] ?? ''),
                    'source_meta' => array(
                        'zero_results' => absint($row['zero_count'] ?? 0),
                        'negative_feedback' => 0,
                        'avg_results' => (float) ($row['avg_results'] ?? 0),
                        'analista_channel' => 'internal_search',
                    ),
                );
            }
        }

        if (function_exists('seo_analista_decision_plan')) {
            $plan = seo_analista_decision_plan($days, min(80, $limit));
            foreach ((array) $plan as $i => $row) {
                $texts = array_filter(array_merge(
                    array((string) ($row['topic'] ?? '')),
                    array_slice((array) ($row['keywords'] ?? array()), 0, 5)
                ));
                foreach ($texts as $j => $text) {
                    if (!SEO_Solucionador_Normalizer::is_solution_signal($text)) continue;
                    $out[] = array(
                        'source_type' => 'analista',
                        'source_id' => 'plan:' . md5(SEO_Solucionador_Normalizer::normalize((string) ($row['topic'] ?? '')) . '|' . SEO_Solucionador_Normalizer::normalize((string) $text)),
                        'source_text' => (string) $text,
                        'hints' => array(),
                        'occurrences' => 1,
                        'evidence_score' => min(1.0, max(0.55, ((float) ($row['priority'] ?? 50)) / 100)),
                        'observed_at' => current_time('mysql'),
                        'source_meta' => array(
                            'priority' => (int) ($row['priority'] ?? 0),
                            'action' => (string) ($row['action'] ?? ''),
                            'channel' => (string) ($row['channel'] ?? ''),
                            'analista_channel' => 'decision_plan',
                            'entity' => is_array($row['entity'] ?? null) ? $row['entity'] : array(),
                            'catalog' => is_array($row['catalog'] ?? null) ? $row['catalog'] : array(),
                            'target' => is_array($row['target'] ?? null) ? $row['target'] : array(),
                            'keywords' => array_values(array_slice((array) ($row['keywords'] ?? array()), 0, 8)),
                        ),
                    );
                }
            }
        }

        return $out;
    }

    public static function auditor($limit = 250) {
        if (!class_exists('SEO_Auditor') || !method_exists('SEO_Auditor', 'last_catalog_report')) return array();
        $report = SEO_Auditor::last_catalog_report();
        if (!is_array($report) || !$report) return array();

        $out = array();
        foreach (array_slice((array) ($report['behavior_audit']['samples'] ?? array()), 0, 80) as $i => $sample) {
            $query = (string) ($sample['query'] ?? '');
            $status = sanitize_key((string) ($sample['status'] ?? ''));
            if ($query === '' || $status === 'ok' || !SEO_Solucionador_Normalizer::is_solution_signal($query)) continue;
            $out[] = array(
                'source_type' => 'auditor',
                'source_id' => 'behavior:' . md5(SEO_Solucionador_Normalizer::normalize($query)),
                'source_text' => $query,
                'hints' => array(),
                'occurrences' => 1,
                'evidence_score' => 0.45,
                'observed_at' => (string) ($report['generated_at'] ?? current_time('mysql')),
                'source_meta' => array(
                    'auditor_channel' => 'behavior_probe',
                    'status' => $status,
                    'kind' => (string) ($sample['kind'] ?? ''),
                    'diagnostics' => array_values((array) ($sample['diagnostics'] ?? array())),
                ),
            );
        }

        foreach (array_slice((array) ($report['findings'] ?? array()), 0, $limit) as $i => $finding) {
            $code = sanitize_key((string) ($finding['code'] ?? ''));
            if (preg_match('/image|schema|canonical|duplicate|orphan|server|database|attribute|sitemap|redirect|json|inventory|supplier/', $code)) continue;

            $evidence = is_array($finding['evidence'] ?? null) ? $finding['evidence'] : array();
            $candidate_texts = array_filter(array(
                (string) ($evidence['query'] ?? ''),
                (string) ($evidence['search_term'] ?? ''),
                (string) ($finding['headline'] ?? ''),
                (string) ($finding['recommendation'] ?? ''),
            ));
            $text = '';
            foreach ($candidate_texts as $candidate) {
                if (SEO_Solucionador_Normalizer::is_solution_signal($candidate)) {
                    $text = $candidate;
                    break;
                }
            }
            if ($text === '') continue;

            $out[] = array(
                'source_type' => 'auditor',
                'source_id' => 'finding:' . md5($code . '|' . SEO_Solucionador_Normalizer::normalize($text)),
                'source_text' => $text,
                'hints' => array(),
                'occurrences' => 1,
                'evidence_score' => 0.40,
                'observed_at' => (string) ($report['generated_at'] ?? current_time('mysql')),
                'source_meta' => array(
                    'auditor_channel' => 'finding',
                    'code' => $code,
                    'severity' => (string) ($finding['severity'] ?? ''),
                    'entity_type' => (string) ($finding['entity_type'] ?? ''),
                    'entity_id' => $finding['entity_id'] ?? '',
                ),
            );
        }

        return $out;
    }

    public static function all($days = 180) {
        return array_merge(
            self::dependiente($days, 1600),
            self::comentarista(1200),
            self::analista(min(180, $days), 160),
            self::auditor(300)
        );
    }
}

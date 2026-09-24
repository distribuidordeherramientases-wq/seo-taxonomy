<?php
/**
 * Solucionador - indice editorial y comprobacion de cobertura.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Coverage {
    private static function post_vocabulary_text($post_id) {
        global $wpdb;
        $ov = $wpdb->prefix . 'seo_object_vocabulary';
        $v = $wpdb->prefix . 'seo_vocabulary';
        if (!SEO_Solucionador_DB::table_exists($ov) || !SEO_Solucionador_DB::table_exists($v)) return '';
        $labels = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT v.label
             FROM {$ov} ov
             INNER JOIN {$v} v ON v.id=ov.vocabulary_id AND v.active=1
             WHERE ov.object_type='post' AND ov.object_id=%d AND ov.status=1
             ORDER BY v.semantic_group,v.id LIMIT 40",
            absint($post_id)
        ));
        return implode(' ', array_filter(array_map('sanitize_text_field', $labels)));
    }

    private static function post_category_text($post_id) {
        global $wpdb;
        $relations = $wpdb->prefix . 'seo_relations';
        if (!SEO_Solucionador_DB::table_exists($relations)) return '';
        $labels = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT t.name
             FROM {$relations} r
             INNER JOIN {$wpdb->terms} t ON t.term_id=r.target_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id AND tt.taxonomy='product_cat'
             WHERE r.source_type='post' AND r.source_id=%d
               AND r.target_type='product_cat' AND r.relation_type='post_to_category'
             ORDER BY t.name ASC LIMIT 20",
            absint($post_id)
        ));
        return implode(' ', array_filter(array_map('sanitize_text_field', $labels)));
    }

    private static function headings($html) {
        $out = array();
        if (preg_match_all('/<h[23][^>]*>(.*?)<\/h[23]>/isu', (string) $html, $matches)) {
            foreach ((array) ($matches[1] ?? array()) as $heading) {
                $heading = trim(wp_strip_all_tags((string) $heading));
                if ($heading !== '') $out[] = $heading;
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 40);
    }

    public static function rebuild_post_index($limit = 3500) {
        global $wpdb;
        SEO_Solucionador_DB::clear_post_topics();
        $limit = min(8000, max(100, absint($limit)));
        $posts = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ID,post_title,post_excerpt,post_content
             FROM {$wpdb->posts}
             WHERE post_type='post' AND post_status IN ('publish','future')
             ORDER BY ID ASC LIMIT %d",
            $limit
        ), ARRAY_A);

        $indexed = 0;
        foreach ($posts as $post) {
            $post_id = absint($post['ID'] ?? 0);
            if (!$post_id) continue;
            $vocab = self::post_vocabulary_text($post_id);
            $categories = self::post_category_text($post_id);
            $semantic_context = trim($vocab . ' ' . $categories);
            $title = trim((string) ($post['post_title'] ?? ''));
            $profile = SEO_Solucionador_Normalizer::profile(trim($title . ' ' . $semantic_context));
            if ($profile) {
                SEO_Solucionador_DB::insert_post_topic($post_id, 'title', $title, $profile);
                $indexed++;
            }
            foreach (self::headings((string) ($post['post_content'] ?? '')) as $heading) {
                $profile = SEO_Solucionador_Normalizer::profile(trim($heading . ' ' . $semantic_context));
                if (!$profile) continue;
                SEO_Solucionador_DB::insert_post_topic($post_id, 'heading', $heading, $profile);
                $indexed++;
            }
        }
        return array('posts' => count($posts), 'topics' => $indexed);
    }

    public static function find(array $profile) {
        global $wpdb;
        $table = SEO_Solucionador_DB::post_topics_table();
        $key = (string) ($profile['canonical_key'] ?? '');
        if ($key === '') return array('status'=>'uncovered','post_id'=>0,'score'=>0,'scope'=>'');

        $exact = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE canonical_key=%s ORDER BY confidence DESC,id ASC LIMIT 1",
            $key
        ), ARRAY_A);
        if ($exact) {
            return array(
                'status' => 'covered_exact',
                'post_id' => absint($exact['post_id']),
                'score' => 1.0,
                'scope' => (string) $exact['scope'],
            );
        }

        $object = sanitize_text_field((string) ($profile['object'] ?? ''));
        $action = sanitize_text_field((string) ($profile['action'] ?? ''));
        $condition = sanitize_text_field((string) ($profile['condition'] ?? ''));
        $rows = array();
        if ($object !== '') {
            $rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE object_term=%s ORDER BY confidence DESC,id ASC LIMIT 250",
                $object
            ), ARRAY_A);
        }
        if (!$rows && $action !== '') {
            $rows = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE action_term=%s ORDER BY confidence DESC,id ASC LIMIT 250",
                $action
            ), ARRAY_A);
        }

        $best = null;
        $best_score = 0.0;
        $candidate_text = implode(' ', array_filter(array(
            $profile['action'] ?? '',
            $profile['object'] ?? '',
            str_replace('_', ' ', (string) ($profile['condition'] ?? '')),
            $profile['context'] ?? '',
        )));
        foreach ($rows as $row) {
            $score = SEO_Solucionador_Normalizer::similarity($candidate_text, (string) ($row['source_text'] ?? ''));
            if ($action !== '' && $action === (string) ($row['action_term'] ?? '')) $score += 0.20;
            if ($object !== '' && $object === (string) ($row['object_term'] ?? '')) $score += 0.30;
            if ($condition !== '' && $condition === (string) ($row['condition_term'] ?? '')) $score += 0.15;
            $score = min(1.0, $score);
            if ($score > $best_score) {
                $best_score = $score;
                $best = $row;
            }
        }

        if (!$best || $best_score < 0.48) {
            return array('status'=>'uncovered','post_id'=>0,'score'=>$best_score,'scope'=>'');
        }
        $row_condition = (string) ($best['condition_term'] ?? '');
        if ($condition !== '' && $row_condition === '') {
            return array(
                'status'=>'covered_parent',
                'post_id'=>absint($best['post_id']),
                'score'=>$best_score,
                'scope'=>(string)$best['scope'],
            );
        }
        if ($best_score >= 0.78) {
            return array(
                'status'=>'covered_partial',
                'post_id'=>absint($best['post_id']),
                'score'=>$best_score,
                'scope'=>(string)$best['scope'],
            );
        }
        return array(
            'status'=>'needs_expansion',
            'post_id'=>absint($best['post_id']),
            'score'=>$best_score,
            'scope'=>(string)$best['scope'],
        );
    }
}

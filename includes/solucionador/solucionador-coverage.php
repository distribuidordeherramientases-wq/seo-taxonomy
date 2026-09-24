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

    private static function context_supports_object($object, $article_context) {
        $object = SEO_Solucionador_Normalizer::normalize((string) $object);
        $context = SEO_Solucionador_Normalizer::normalize((string) $article_context);
        if ($object === '' || $context === '') return false;
        return false !== strpos(' ' . $context . ' ', ' ' . $object . ' ');
    }

    private static function inherited_heading_profile($heading, array $title_profile, $article_context) {
        if (!SEO_Solucionador_Normalizer::is_solution_signal($heading)) return array();
        $profile = SEO_Solucionador_Normalizer::profile($heading);
        if (!$profile) return array();

        $title_object = (string) ($title_profile['object'] ?? '');
        $heading_object = (string) ($profile['object'] ?? '');
        $supported_object = $heading_object !== '' && (
            $heading_object === $title_object ||
            self::context_supports_object($heading_object, $article_context)
        );

        // H2/H3 no son consultas independientes. Si su objeto es gramatical,
        // discursivo o no esta respaldado por titulo/Vocabulary/categorias, se
        // hereda el objeto principal del articulo. Asi evitamos fingerprints
        // como object=importa, object=tres u object=decide.
        if (SEO_Solucionador_Normalizer::is_weak_profile($profile) || !$supported_object) {
            if ($title_object === '') return array();
            $hints = array(
                'action' => (string) (($profile['action'] ?? '') ?: ($title_profile['action'] ?? '')),
                'object' => $title_object,
                'context' => (string) (($profile['context'] ?? '') ?: ($title_profile['context'] ?? '')),
                'state' => (string) (($profile['condition'] ?? '') ?: ($title_profile['condition'] ?? '')),
                'intent' => (string) (($profile['intent'] ?? '') ?: ($title_profile['intent'] ?? '')),
            );
            $profile = SEO_Solucionador_Normalizer::profile(trim($heading . ' ' . $article_context), $hints);
        } elseif (empty($profile['context']) && !empty($title_profile['context'])) {
            $profile = SEO_Solucionador_Normalizer::profile($heading, array(
                'action' => (string) ($profile['action'] ?? ''),
                'object' => (string) ($profile['object'] ?? ''),
                'context' => (string) ($title_profile['context'] ?? ''),
                'state' => (string) ($profile['condition'] ?? ''),
                'intent' => (string) ($profile['intent'] ?? ''),
            ));
        }

        if (!$profile || SEO_Solucionador_Normalizer::is_weak_profile($profile)) return array();
        $object = (string) ($profile['object'] ?? '');
        if ($object !== $title_object && !self::context_supports_object($object, $article_context)) return array();
        return $profile;
    }

    public static function rebuild_post_index($limit = 3500) {
        global $wpdb;
        SEO_Solucionador_DB::clear_post_topics();
        $limit = min(8000, max(100, absint($limit)));
        $posts = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ID,post_title,post_excerpt,post_content
             FROM {$wpdb->posts}
             WHERE post_type='post' AND post_status IN ('publish','future','draft')
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
            $article_context = trim($title . ' ' . $semantic_context);
            $editorial_type = SEO_Solucionador_Normalizer::editorial_type($title, (string) ($post['post_content'] ?? ''));

            // Noticias, piezas informativas, legales y comparativas no deben
            // convertirse artificialmente en cobertura de una solucion. Solo
            // indexamos contenidos que realmente pueden responder una necesidad.
            if (!SEO_Solucionador_Normalizer::coverage_eligible_editorial_type($editorial_type)) {
                continue;
            }

            // El titulo manda. El contexto semantico solo se usa como fallback si
            // el titulo por si solo no produce un perfil suficientemente fiable.
            $title_profile = SEO_Solucionador_Normalizer::profile($title);
            if (!$title_profile || SEO_Solucionador_Normalizer::is_weak_profile($title_profile)) {
                $title_profile = SEO_Solucionador_Normalizer::profile(trim($title . ' ' . $semantic_context));
            }
            if ($title_profile && !SEO_Solucionador_Normalizer::is_weak_profile($title_profile)) {
                SEO_Solucionador_DB::insert_post_topic($post_id, 'title', $title, $title_profile);
                $indexed++;
            }

            if (!$title_profile) continue;
            foreach (self::headings((string) ($post['post_content'] ?? '')) as $heading) {
                $profile = self::inherited_heading_profile($heading, $title_profile, $article_context);
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
            "SELECT * FROM {$table} WHERE canonical_key=%s
             ORDER BY CASE WHEN scope='title' THEN 0 ELSE 1 END,confidence DESC,id ASC LIMIT 1",
            $key
        ), ARRAY_A);
        if ($exact) {
            $post_id = absint($exact['post_id']);
            return array(
                'status' => get_post_status($post_id) === 'draft' ? 'draft_pending' : 'covered_exact',
                'post_id' => $post_id,
                'score' => 1.0,
                'scope' => (string) $exact['scope'],
            );
        }

        $object = sanitize_text_field((string) ($profile['object'] ?? ''));
        $action = sanitize_text_field((string) ($profile['action'] ?? ''));
        $condition = sanitize_text_field((string) ($profile['condition'] ?? ''));
        $context = sanitize_text_field((string) ($profile['context'] ?? ''));

        $where = array();
        $params = array();
        if ($object !== '') {
            $where[] = 'object_term=%s';
            $params[] = $object;
        }
        if ($action !== '') {
            $where[] = 'action_term=%s';
            $params[] = $action;
        }
        if (!$where) return array('status'=>'uncovered','post_id'=>0,'score'=>0,'scope'=>'');

        $sql = "SELECT * FROM {$table} WHERE (" . implode(' OR ', $where) . ")
                ORDER BY CASE WHEN scope='title' THEN 0 ELSE 1 END,confidence DESC,id ASC LIMIT 400";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $best = null;
        $best_score = 0.0;
        $candidate_text = implode(' ', array_filter(array(
            $action,
            $object,
            str_replace('_', ' ', $condition),
            $context,
        )));

        foreach ($rows as $row) {
            $row_action = (string) ($row['action_term'] ?? '');
            $row_object = (string) ($row['object_term'] ?? '');
            $row_condition = (string) ($row['condition_term'] ?? '');
            $row_context = (string) ($row['context_term'] ?? '');

            // Una coincidencia de accion sin objeto comun no basta para decir que
            // un post cubre la necesidad.
            if ($object !== '' && $row_object !== '' && $object !== $row_object) {
                $lexical = SEO_Solucionador_Normalizer::similarity($object, $row_object);
                if ($lexical < 0.50) continue;
            }

            $score = SEO_Solucionador_Normalizer::similarity($candidate_text, (string) ($row['source_text'] ?? ''));
            if ($action !== '' && $action === $row_action) $score += 0.25;
            if ($object !== '' && $object === $row_object) $score += 0.35;
            if ($condition !== '' && $condition === $row_condition) $score += 0.20;
            if ($context !== '' && $context === $row_context) $score += 0.18;
            elseif ($context !== '' && $row_context !== '' && $context !== $row_context) $score -= 0.12;
            if ((string) ($row['scope'] ?? '') === 'title') $score += 0.12;
            $score = min(1.0, max(0.0, $score));
            if ($score > $best_score) {
                $best_score = $score;
                $best = $row;
            }
        }

        if (!$best || $best_score < 0.58) {
            return array('status'=>'uncovered','post_id'=>0,'score'=>$best_score,'scope'=>'');
        }
        $best_post_id = absint($best['post_id']);
        if ($best_post_id && get_post_status($best_post_id) === 'draft') {
            return array(
                'status'=>'draft_pending',
                'post_id'=>$best_post_id,
                'score'=>$best_score,
                'scope'=>(string)$best['scope'],
            );
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
        if ($best_score >= 0.82) {
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

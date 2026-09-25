<?php
/**
 * Solucionador - propuesta de categorias y Vocabulary.
 *
 * Las etiquetas propuestas siempre se resuelven contra wp_seo_vocabulary.
 * Nunca crea terminos nuevos. Las categorias siempre son product_cat reales.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Catalog {
    private static function allowed_groups() {
        return array('rol','tipo','aplicacion','plataforma','subtipo');
    }

    private static function add_score(array &$scores, $id, $score) {
        $id = absint($id);
        $score = (float) $score;
        if (!$id || $score <= 0) return;
        $scores[$id] = ($scores[$id] ?? 0) + $score;
    }

    private static function source_weight(array $evidence) {
        $base = max(0.1, (float) ($evidence['evidence_score'] ?? 1));
        $occ = max(1, absint($evidence['occurrences'] ?? 1));
        return $base * (1 + min(1.5, log(1 + $occ) / 2));
    }

    private static function product_categories(array $product_scores, array &$category_scores) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('absint', array_keys($product_scores))));
        if (!$ids) return;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT tr.object_id product_id,t.term_id,t.name,t.slug
                FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt
                   ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
                INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
                WHERE tr.object_id IN ({$placeholders})";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $ids), ARRAY_A);
        foreach ($rows as $row) {
            $pid = absint($row['product_id'] ?? 0);
            $cid = absint($row['term_id'] ?? 0);
            if (!$pid || !$cid) continue;
            self::add_score($category_scores, $cid, (float) ($product_scores[$pid] ?? 0));
        }
    }

    private static function resolve_category_names(array $name_scores, array &$category_scores) {
        foreach ($name_scores as $name => $score) {
            $name = trim((string) $name);
            if ($name === '') continue;
            $term = get_term_by('slug', sanitize_title($name), 'product_cat');
            if (!$term || is_wp_error($term)) $term = get_term_by('name', $name, 'product_cat');
            if ($term && !is_wp_error($term)) {
                self::add_score($category_scores, absint($term->term_id), (float) $score);
            }
        }
    }

    private static function direct_profile_categories(array $profile, array &$category_scores) {
        global $wpdb;
        $phrases = array_values(array_unique(array_filter(array(
            (string) ($profile['object'] ?? ''),
            (string) ($profile['context'] ?? ''),
        ))));
        if (!$phrases) return;

        $where = array();
        $params = array();
        foreach (array_slice($phrases, 0, 4) as $phrase) {
            $n = SEO_Solucionador_Normalizer::normalize($phrase);
            if (strlen($n) < 3) continue;
            $where[] = '(LOWER(t.name) LIKE %s OR t.slug LIKE %s)';
            $like = '%' . $wpdb->esc_like($n) . '%';
            $params[] = $like;
            $params[] = '%' . $wpdb->esc_like(sanitize_title($n)) . '%';
        }
        if (!$where) return;

        $sql = "SELECT t.term_id,t.name,t.slug
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id AND tt.taxonomy='product_cat'
                WHERE " . implode(' OR ', $where) . "
                ORDER BY tt.count DESC,t.name ASC LIMIT 30";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $needle = implode(' ', $phrases);
        foreach ($rows as $row) {
            $score = SEO_Solucionador_Normalizer::similarity($needle, (string) ($row['name'] ?? ''));
            if ($score >= 0.30) self::add_score($category_scores, absint($row['term_id'] ?? 0), 0.75 + (2 * $score));
        }
    }

    private static function collect_evidence_candidates($topic_id, array &$product_scores, array &$category_scores, array &$category_name_scores, array &$vocab_slug_hints) {
        $rows = SEO_Solucionador_DB::get_evidence_rows($topic_id);
        foreach ($rows as $evidence) {
            $meta = (array) ($evidence['source_meta_decoded'] ?? array());
            $weight = self::source_weight($evidence);
            $source_type = sanitize_key((string) ($evidence['source_type'] ?? ''));

            $clicked = absint($meta['clicked_product_id'] ?? 0);
            if ($clicked) self::add_score($product_scores, $clicked, 5.0 * $weight);

            $comment_product = absint($meta['product_id'] ?? 0);
            if ($comment_product) self::add_score($product_scores, $comment_product, 1.25 * $weight);

            foreach (array_slice((array) ($meta['top_results'] ?? array()), 0, 10) as $result) {
                if (!is_array($result)) continue;
                $pid = absint($result['id'] ?? 0);
                $position = max(1, absint($result['position'] ?? 1));
                if ($pid) self::add_score($product_scores, $pid, $weight * max(0.45, 3.2 / $position));
                foreach ((array) ($result['categories'] ?? array()) as $name) {
                    $name = sanitize_text_field((string) $name);
                    if ($name === '') continue;
                    $category_name_scores[$name] = ($category_name_scores[$name] ?? 0) + ($weight * max(0.35, 1.8 / $position));
                }
            }

            $entity = is_array($meta['entity'] ?? null) ? $meta['entity'] : array();
            $entity_type = sanitize_key((string) ($entity['type'] ?? ($meta['entity_type'] ?? '')));
            $entity_id = absint($entity['id'] ?? ($meta['entity_id'] ?? 0));
            if ($entity_type === 'product' && $entity_id) self::add_score($product_scores, $entity_id, 2.0 * $weight);
            if (in_array($entity_type, array('product_cat','category'), true) && $entity_id) self::add_score($category_scores, $entity_id, 3.5 * $weight);

            $catalog = is_array($meta['catalog'] ?? null) ? $meta['catalog'] : array();
            if (!empty($catalog['category_id'])) self::add_score($category_scores, absint($catalog['category_id']), 3.0 * $weight);
            if (!empty($catalog['category'])) {
                $name = sanitize_text_field((string) $catalog['category']);
                $category_name_scores[$name] = ($category_name_scores[$name] ?? 0) + (2.0 * $weight);
            }

            foreach ((array) ($meta['semantic_matches'] ?? array()) as $match) {
                if (!is_array($match)) continue;
                $group = sanitize_key((string) ($match['source_group'] ?? $match['semantic_group'] ?? $match['group'] ?? ''));
                $slug = sanitize_title((string) ($match['source_slug'] ?? $match['slug'] ?? $match['canonical'] ?? ''));
                if ($slug === '' || !in_array($group, self::allowed_groups(), true)) continue;
                $key = $group . '|' . $slug;
                $vocab_slug_hints[$key] = ($vocab_slug_hints[$key] ?? 0) + (2.5 * $weight);
            }

            if ($source_type === 'auditor' && $entity_type === 'product' && $entity_id) {
                self::add_score($product_scores, $entity_id, 0.75 * $weight);
            }
        }
    }

    private static function load_object_vocabulary($object_type, array $object_scores, array &$vocab_scores) {
        global $wpdb;
        $ids = array_values(array_filter(array_map('absint', array_keys($object_scores))));
        if (!$ids) return;
        $ov = $wpdb->prefix . 'seo_object_vocabulary';
        $v = $wpdb->prefix . 'seo_vocabulary';
        if (!SEO_Solucionador_DB::table_exists($ov) || !SEO_Solucionador_DB::table_exists($v)) return;

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $groups = "'rol','tipo','aplicacion','plataforma','subtipo'";
        $sql = "SELECT ov.object_id,v.id,v.semantic_group,v.slug,v.label
                FROM {$ov} ov
                INNER JOIN {$v} v ON v.id=ov.vocabulary_id AND v.active=1
                WHERE ov.object_type=%s AND ov.status=1
                  AND v.semantic_group IN ({$groups})
                  AND ov.object_id IN ({$placeholders})";
        $params = array_merge(array($object_type), $ids);
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        foreach ($rows as $row) {
            $oid = absint($row['object_id'] ?? 0);
            $vid = absint($row['id'] ?? 0);
            if (!$oid || !$vid) continue;
            $base = (float) ($object_scores[$oid] ?? 0);
            if ($object_type === 'product_cat') $base *= 1.35;
            self::add_score($vocab_scores, $vid, $base);
        }
    }

    private static function resolve_vocab_hints(array $hints, array &$vocab_scores) {
        global $wpdb;
        if (!$hints) return;
        $v = $wpdb->prefix . 'seo_vocabulary';
        if (!SEO_Solucionador_DB::table_exists($v)) return;
        foreach ($hints as $key => $score) {
            list($group, $slug) = array_pad(explode('|', (string) $key, 2), 2, '');
            if (!in_array($group, self::allowed_groups(), true) || $slug === '') continue;
            $id = absint($wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$v} WHERE active=1 AND semantic_group=%s AND slug=%s LIMIT 1",
                $group,
                $slug
            )));
            if ($id) self::add_score($vocab_scores, $id, (float) $score);
        }
    }

    private static function direct_profile_vocabulary(array $profile, array &$vocab_scores) {
        global $wpdb;
        $v = $wpdb->prefix . 'seo_vocabulary';
        if (!SEO_Solucionador_DB::table_exists($v)) return;
        $phrases = array_values(array_unique(array_filter(array(
            (string) ($profile['action'] ?? ''),
            (string) ($profile['object'] ?? ''),
            (string) ($profile['context'] ?? ''),
            str_replace('_', ' ', (string) ($profile['condition'] ?? '')),
        ))));
        if (!$phrases) return;

        $where = array();
        $params = array();
        foreach (array_slice($phrases, 0, 4) as $phrase) {
            $n = SEO_Solucionador_Normalizer::normalize($phrase);
            if (strlen($n) < 3) continue;
            $where[] = '(LOWER(v.label) LIKE %s OR v.slug LIKE %s)';
            $params[] = '%' . $wpdb->esc_like($n) . '%';
            $params[] = '%' . $wpdb->esc_like(sanitize_title($n)) . '%';
        }
        if (!$where) return;

        $sql = "SELECT v.id,v.semantic_group,v.slug,v.label
                FROM {$v} v
                WHERE v.active=1
                  AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                  AND (" . implode(' OR ', $where) . ")
                ORDER BY v.semantic_group,v.label LIMIT 120";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $needle = implode(' ', $phrases);
        foreach ($rows as $row) {
            $score = SEO_Solucionador_Normalizer::similarity($needle, (string) ($row['label'] ?? $row['slug'] ?? ''));
            if ($score >= 0.30) self::add_score($vocab_scores, absint($row['id'] ?? 0), 0.65 + (1.5 * $score));
        }
    }

    /**
     * Si la consulta no trae productos/categorias suficientes, usa el propio
     * Vocabulary canonico como puente hacia categorias reales del catalogo.
     * Evita mapas hardcodeados problema->categoria.
     */
    private static function categories_from_vocabulary(array $vocab_scores, array &$category_scores) {
        global $wpdb;
        if (!$vocab_scores) return;
        $ov = $wpdb->prefix . 'seo_object_vocabulary';
        $v = $wpdb->prefix . 'seo_vocabulary';
        if (!SEO_Solucionador_DB::table_exists($ov) || !SEO_Solucionador_DB::table_exists($v)) return;

        arsort($vocab_scores, SORT_NUMERIC);
        $candidate_ids = array();
        $top_vocab_score = $vocab_scores ? (float) reset($vocab_scores) : 0.0;
        foreach (array_slice($vocab_scores, 0, 18, true) as $vid => $score) {
            if ($top_vocab_score > 0 && (float) $score < ($top_vocab_score * 0.55)) continue;
            $candidate_ids[] = absint($vid);
        }
        $candidate_ids = array_values(array_unique(array_filter($candidate_ids)));
        if (!$candidate_ids) return;

        $placeholders = implode(',', array_fill(0, count($candidate_ids), '%d'));
        $allowed = "'tipo','aplicacion','plataforma','subtipo'";

        // Primero, categorias que ya tienen esos conceptos asignados de forma canonica.
        $sql = "SELECT ov.object_id category_id,ov.vocabulary_id
                FROM {$ov} ov
                INNER JOIN {$v} v ON v.id=ov.vocabulary_id AND v.active=1
                WHERE ov.object_type='product_cat' AND ov.status=1
                  AND v.semantic_group IN ({$allowed})
                  AND ov.vocabulary_id IN ({$placeholders})";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $candidate_ids), ARRAY_A);
        foreach ($rows as $row) {
            $cid = absint($row['category_id'] ?? 0);
            $vid = absint($row['vocabulary_id'] ?? 0);
            if (!$cid || !$vid) continue;
            self::add_score($category_scores, $cid, 1.6 * (float) ($vocab_scores[$vid] ?? 0));
        }

        // Segundo, categorias en las que existen productos con esos conceptos.
        // Se limita a conceptos ya seleccionados y agrega por categoria para no
        // convertir un termino generico en miles de candidatos.
        $sql = "SELECT ov.vocabulary_id,tt.term_id category_id,COUNT(DISTINCT ov.object_id) matched_products
                FROM {$ov} ov
                INNER JOIN {$v} v ON v.id=ov.vocabulary_id AND v.active=1
                INNER JOIN {$wpdb->posts} p ON p.ID=ov.object_id AND p.post_type='product' AND p.post_status='publish'
                INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=ov.object_id
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
                WHERE ov.object_type='product' AND ov.status=1
                  AND v.semantic_group IN ({$allowed})
                  AND ov.vocabulary_id IN ({$placeholders})
                GROUP BY ov.vocabulary_id,tt.term_id
                ORDER BY matched_products DESC
                LIMIT 180";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $candidate_ids), ARRAY_A);
        foreach ($rows as $row) {
            $cid = absint($row['category_id'] ?? 0);
            $vid = absint($row['vocabulary_id'] ?? 0);
            $matches = max(1, absint($row['matched_products'] ?? 1));
            if (!$cid || !$vid) continue;
            $support = min(2.2, 0.70 + (log(1 + $matches) / 2));
            self::add_score($category_scores, $cid, $support * (float) ($vocab_scores[$vid] ?? 0));
        }
    }

    private static function category_rows(array $scores, $limit = 4) {
        if (!$scores) return array();
        arsort($scores, SORT_NUMERIC);
        $out = array();
        $top = $scores ? (float) reset($scores) : 0.0;
        foreach ($scores as $id => $score) {
            if ($top > 0 && (float) $score < ($top * 0.45)) continue;
            $term = get_term(absint($id), 'product_cat');
            if (!$term || is_wp_error($term)) continue;
            $out[] = array(
                'id' => absint($term->term_id),
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'score' => round((float) $score, 3),
            );
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private static function vocabulary_rows(array $scores) {
        global $wpdb;
        $out = array();
        foreach (self::allowed_groups() as $group) $out[$group] = array();
        if (!$scores) return $out;

        $v = $wpdb->prefix . 'seo_vocabulary';
        $ids = array_values(array_filter(array_map('absint', array_keys($scores))));
        if (!$ids || !SEO_Solucionador_DB::table_exists($v)) return $out;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id,semantic_group,slug,label FROM {$v}
             WHERE active=1 AND id IN ({$placeholders})",
            $ids
        ), ARRAY_A);

        $by_group = array();
        foreach ($rows as $row) {
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            if (!isset($out[$group])) continue;
            $row['score'] = (float) ($scores[absint($row['id'] ?? 0)] ?? 0);
            $by_group[$group][] = $row;
        }
        $limits = array('rol'=>2,'tipo'=>4,'aplicacion'=>4,'plataforma'=>3,'subtipo'=>4);
        foreach ($by_group as $group => $items) {
            usort($items, static function($a, $b) { return ($b['score'] <=> $a['score']); });
            $top = isset($items[0]['score']) ? (float) $items[0]['score'] : 0;
            foreach ($items as $row) {
                if ($top > 0 && (float) $row['score'] < ($top * 0.50)) continue;
                $out[$group][] = array(
                    'id' => absint($row['id'] ?? 0),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'label' => (string) ($row['label'] ?? ''),
                    'score' => round((float) $row['score'], 3),
                );
                if (count($out[$group]) >= ($limits[$group] ?? 3)) break;
            }
        }
        return $out;
    }

    public static function build_proposal($topic_id, array $profile) {
        $product_scores = array();
        $category_scores = array();
        $category_name_scores = array();
        $vocab_slug_hints = array();
        $vocab_scores = array();

        self::collect_evidence_candidates(
            $topic_id,
            $product_scores,
            $category_scores,
            $category_name_scores,
            $vocab_slug_hints
        );

        arsort($product_scores, SORT_NUMERIC);
        $product_scores = array_slice($product_scores, 0, 30, true);
        self::product_categories($product_scores, $category_scores);
        self::resolve_category_names($category_name_scores, $category_scores);
        if (!$category_scores) self::direct_profile_categories($profile, $category_scores);

        arsort($category_scores, SORT_NUMERIC);
        $category_scores = array_slice($category_scores, 0, 12, true);

        self::load_object_vocabulary('product', $product_scores, $vocab_scores);
        self::load_object_vocabulary('product_cat', $category_scores, $vocab_scores);
        self::resolve_vocab_hints($vocab_slug_hints, $vocab_scores);
        self::direct_profile_vocabulary($profile, $vocab_scores);

        // Vocabulary actua tambien como puente semantico hacia product_cat cuando
        // la consulta original no tenia resultados directos en Dependiente.
        self::categories_from_vocabulary($vocab_scores, $category_scores);
        arsort($category_scores, SORT_NUMERIC);
        $category_scores = array_slice($category_scores, 0, 12, true);
        // Las nuevas categorias pueden aportar Vocabulary canonico adicional.
        self::load_object_vocabulary('product_cat', $category_scores, $vocab_scores);

        return array(
            'categories' => self::category_rows($category_scores, 4),
            'vocabulary' => self::vocabulary_rows($vocab_scores),
        );
    }

    public static function proposal_ready(array $proposal) {
        $categories = (array) ($proposal['categories'] ?? array());
        $vocabulary = (array) ($proposal['vocabulary'] ?? array());
        $vocab_count = 0;
        foreach ($vocabulary as $rows) $vocab_count += count((array) $rows);
        return count($categories) > 0 && $vocab_count > 0;
    }
}
